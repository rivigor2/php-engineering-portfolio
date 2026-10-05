#!/usr/bin/env python3
"""Public clean-room boundary between the Portal router and Paperclip.

The code intentionally does not contain production URLs, credentials or Portal
SDK calls. Transport and Portal output are ports, so orchestration decisions
can be reviewed separately from closed infrastructure.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Any, Callable, Protocol
from urllib.parse import quote


class JsonTransport(Protocol):
    def request(
        self,
        method: str,
        path: str,
        *,
        body: dict[str, Any] | None = None,
    ) -> dict[str, Any]: ...


class PortalOutput(Protocol):
    def post(self, destination: str, text: str) -> None: ...


@dataclass(frozen=True)
class IssueCommand:
    title: str
    description: str
    project_id: str
    assignee_agent_id: str
    reply_destination: str
    fingerprint: str
    priority: str = "medium"


@dataclass(frozen=True)
class IssueRef:
    id: str
    identifier: str
    status: str
    assignee_agent_id: str
    reply_destination: str


@dataclass(frozen=True)
class DispatchResult:
    issue: IssueRef
    wakeup_requested: bool


class IssueCreatedButNotRecorded(RuntimeError):
    """Reconcile this issue before retrying; blindly creating again can duplicate it."""

    def __init__(self, issue: IssueRef) -> None:
        super().__init__("Issue exists; persist its reference before continuing")
        self.issue = issue


class PaperclipBridge:
    """Create/feed/wake an issue without leaking board details into the router."""

    _SECRET = re.compile(
        r"(?:Bearer\s+|pcp_)[A-Za-z0-9._-]+",
        re.IGNORECASE,
    )

    def __init__(
        self,
        transport: JsonTransport,
        portal: PortalOutput,
        company_id: str,
    ) -> None:
        self.transport = transport
        self.portal = portal
        self.company_id = company_id

    def dispatch(
        self,
        command: IssueCommand,
        remember: Callable[[IssueRef], None],
    ) -> DispatchResult:
        """One create attempt; record identity before wakeup or notifications.

        The single poller checks the router fingerprint before calling this.
        `remember` persists that fingerprint, issue reference and reply route.
        A timeout on create needs reconciliation, not automatic POST retry.
        This is not distributed exactly-once delivery.
        """
        created = self.transport.request(
            "POST",
            f"/api/companies/{quote(self.company_id, safe='')}/issues",
            body={
                "title": command.title[:120],
                "description": command.description,
                "status": "todo",
                "priority": command.priority,
                "projectId": command.project_id,
                "assigneeAgentId": command.assignee_agent_id,
            },
        )
        issue = self._issue_ref(created, command)
        try:
            remember(issue)
        except Exception:
            raise IssueCreatedButNotRecorded(issue) from None
        # Receipt delivery is separate: a chat error must not erase the result
        # of create. The caller can retry wakeup using this same IssueRef.
        return DispatchResult(issue, self.wake_existing(issue))

    def wake_existing(self, issue: IssueRef) -> bool:
        try:
            self.transport.request(
                "POST",
                f"/api/agents/{quote(issue.assignee_agent_id, safe='')}/wakeup",
                body={},
            )
            return True
        except (OSError, RuntimeError):
            return False

    def feed_human_reply(self, issue: IssueRef, text: str) -> bool:
        """Add the reply once; if wake fails, retry wake_existing, not the feed."""
        clean = text.strip()
        if not clean:
            raise ValueError("empty Portal reply")

        self.transport.request(
            "POST",
            f"/api/issues/{quote(issue.id, safe='')}/comments",
            body={"body": clean},
        )
        return self.wake_existing(issue)

    def publish_agent_report(
        self,
        issue: IssueRef,
        report: str,
        disposition: str,
    ) -> None:
        """Called by the Portal poller with the human report, never raw logs."""
        if disposition not in {"done", "in_review", "blocked"}:
            raise ValueError(f"unsupported disposition: {disposition}")

        # blocked is a statement from the agent. The human still verifies
        # whether the blocker is real before accepting it as an external fact.
        prefix = {
            "done": "🏁",
            "in_review": "👀",
            "blocked": "🔐",
        }[disposition]
        safe_report = self.redact(report).strip()
        self.portal.post(
            issue.reply_destination,
            f"{prefix} {safe_report}",
        )

    @classmethod
    def redact(cls, text: str) -> str:
        # A narrow last filter, not a general confidential-data detector.
        text = cls._SECRET.sub("[secret]", text)
        text = re.sub(
            r"(?:https?://)?(?:\d{1,3}\.){3}\d{1,3}(?::\d+)?",
            "[internal-host]",
            text,
        )
        text = re.sub(
            r"/(?:var|srv)/www/[^\s]+",
            "[project-path]",
            text,
        )
        return text

    @staticmethod
    def _issue_ref(
        raw: dict[str, Any],
        command: IssueCommand,
    ) -> IssueRef:
        issue_id = str(raw.get("id") or "").strip()
        identifier = str(raw.get("identifier") or "").strip()
        if not issue_id or not identifier:
            raise RuntimeError("Paperclip returned an issue without identity")
        return IssueRef(
            id=issue_id,
            identifier=identifier,
            status=str(raw.get("status") or "todo"),
            assignee_agent_id=command.assignee_agent_id,
            reply_destination=command.reply_destination,
        )
