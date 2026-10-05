#!/usr/bin/env python3
"""Public clean-room excerpt of the Portal routing layer.

The production router also handles attachments, security gates and project
bootstrap. This example keeps the decisions that matter for an engineering
review: explicit mention, project boundary, runtime selection, work mode and
TTL-based repeat detection for one poller. It is not a distributed lock.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import time
from dataclasses import asdict, dataclass
from enum import StrEnum
from pathlib import Path
from typing import Any


class Provider(StrEnum):
    CODEX = "codex"
    CURSOR = "cursor"


class WorkMode(StrEnum):
    GENERAL = "general"
    GREENFIELD = "greenfield"
    FIX = "fix"
    INSPECT = "inspect"


@dataclass(frozen=True)
class PortalMessage:
    message_id: str
    task_id: str
    text: str
    mentioned_bot: bool


@dataclass(frozen=True)
class ProjectRoute:
    alias: str
    workspace: str
    allowed: bool = True


@dataclass(frozen=True)
class RouteDecision:
    accepted: bool
    reason: str
    provider: Provider | None = None
    mode: WorkMode | None = None
    project: str | None = None
    workspace: str | None = None
    clean_request: str | None = None
    fingerprint: str | None = None


class FingerprintStore:
    """Public single-poller store. Remember only after issue creation succeeds."""

    def __init__(self, path: Path, ttl_seconds: int = 7 * 24 * 60 * 60) -> None:
        self.path = path
        self.ttl_seconds = ttl_seconds

    def contains(self, fingerprint: str, now: float) -> bool:
        item = self._read().get(fingerprint)
        if not isinstance(item, dict):
            return False
        created_at = float(item.get("created_at", 0))
        return created_at > 0 and 0 <= now - created_at <= self.ttl_seconds

    def remember(
        self,
        fingerprint: str,
        *,
        message_id: str,
        issue_identifier: str,
        now: float,
    ) -> None:
        values = self._read()
        values[fingerprint] = {
            "message_id": message_id,
            "issue_identifier": issue_identifier,
            "created_at": now,
        }
        self.path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.path.with_suffix(self.path.suffix + '.tmp')
        temporary.write_text(
            json.dumps(values, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        temporary.replace(self.path)

    def _read(self) -> dict[str, Any]:
        if not self.path.exists():
            return {}
        decoded = json.loads(self.path.read_text(encoding="utf-8"))
        if not isinstance(decoded, dict):
            raise ValueError("Invalid fingerprint store; refusing to lose dedup state")
        return decoded


class PortalRouter:
    DEFAULT_PROVIDER = Provider.CODEX

    _CURSOR_DIRECTIVE = re.compile(
        r"\b(?:используй|через|use)\s+(?:курсор|cursor)\b",
        re.IGNORECASE,
    )
    _CODEX_DIRECTIVE = re.compile(
        r"\b(?:используй|через|use)\s+(?:кодекс|codex)\b",
        re.IGNORECASE,
    )
    _FIX = re.compile(
        r"\b(?:handoff|plan_patch|исправь|фикс|patch)\b",
        re.IGNORECASE,
    )
    _GREENFIELD = re.compile(
        r"\b(?:greenfield|manufacture|с\s+нуля|создай\s+проект)\b",
        re.IGNORECASE,
    )
    _INSPECT = re.compile(
        r"\b(?:проверь\s+архив|inspect|вложение|что\s+в\s+файле)\b",
        re.IGNORECASE,
    )

    def __init__(
        self,
        routes: dict[str, ProjectRoute],
        fingerprints: FingerprintStore,
    ) -> None:
        self.routes = routes
        self.fingerprints = fingerprints

    def decide(self, message: PortalMessage, now: float | None = None) -> RouteDecision:
        timestamp = time.time() if now is None else now

        if not message.mentioned_bot:
            return RouteDecision(False, "message_has_no_agent_mention")

        route = self.routes.get(message.task_id)
        if route is None:
            return RouteDecision(False, "task_is_not_mapped")
        if not route.allowed:
            return RouteDecision(False, "project_is_not_allowed")

        provider, conflict = self._provider(message.text)
        if conflict:
            return RouteDecision(False, "conflicting_runtime_directives")

        clean_request = self._strip_provider_directive(message.text)
        if not clean_request:
            return RouteDecision(False, "empty_request_after_routing_directives")

        fingerprint = self._fingerprint(message.task_id, clean_request)
        if self.fingerprints.contains(fingerprint, timestamp):
            return RouteDecision(
                False,
                "duplicate_request",
                provider=provider,
                mode=self._mode(clean_request),
                project=route.alias,
                workspace=route.workspace,
                clean_request=clean_request,
                fingerprint=fingerprint,
            )

        return RouteDecision(
            True,
            "ready_for_issue",
            provider=provider,
            mode=self._mode(clean_request),
            project=route.alias,
            workspace=route.workspace,
            clean_request=clean_request,
            fingerprint=fingerprint,
        )

    @classmethod
    def _provider(cls, text: str) -> tuple[Provider, bool]:
        # Public simplification: ambiguous directives require clarification.
        wants_cursor = bool(cls._CURSOR_DIRECTIVE.search(text))
        wants_codex = bool(cls._CODEX_DIRECTIVE.search(text))
        if wants_cursor and wants_codex:
            return cls.DEFAULT_PROVIDER, True
        if wants_cursor:
            return Provider.CURSOR, False
        if wants_codex:
            return Provider.CODEX, False
        return cls.DEFAULT_PROVIDER, False

    @classmethod
    def _strip_provider_directive(cls, text: str) -> str:
        clean = cls._CURSOR_DIRECTIVE.sub(" ", text)
        clean = cls._CODEX_DIRECTIVE.sub(" ", clean)
        clean = re.sub(r"^\s*@\S+\s*", " ", clean, count=1)
        return re.sub(r"\s+", " ", clean).strip(" \t\r\n.,:;—-")

    @classmethod
    def _mode(cls, text: str) -> WorkMode:
        if cls._FIX.search(text):
            return WorkMode.FIX
        if cls._GREENFIELD.search(text):
            return WorkMode.GREENFIELD
        if cls._INSPECT.search(text):
            return WorkMode.INSPECT
        return WorkMode.GENERAL

    @staticmethod
    def _fingerprint(task_id: str, request: str) -> str:
        normalized = re.sub(r"\s+", " ", request.lower()).strip()
        digest = hashlib.sha256(f"{task_id}:{normalized}".encode()).hexdigest()
        return digest[:24]


def load_message(path: Path) -> PortalMessage:
    raw = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(raw.get("mentioned_bot"), bool):
        raise ValueError("mentioned_bot must be a boolean supplied by the Portal adapter")
    return PortalMessage(
        message_id=str(raw["message_id"]),
        task_id=str(raw["task_id"]),
        text=str(raw["text"]),
        mentioned_bot=bool(raw["mentioned_bot"]),
    )


def load_routes(path: Path) -> dict[str, ProjectRoute]:
    raw = json.loads(path.read_text(encoding="utf-8"))
    return {
        task_id: ProjectRoute(
            alias=str(value["alias"]),
            workspace=str(value["workspace"]),
            allowed=bool(value.get("allowed", True)),
        )
        for task_id, value in raw.items()
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Preview routing only; does not create an issue or remember a fingerprint")
    parser.add_argument("message", type=Path)
    parser.add_argument("project_map", type=Path)
    parser.add_argument(
        "--state",
        type=Path,
        default=Path(".router-state.json"),
    )
    args = parser.parse_args()

    router = PortalRouter(
        load_routes(args.project_map),
        FingerprintStore(args.state),
    )
    decision = router.decide(load_message(args.message))
    print(json.dumps(asdict(decision), ensure_ascii=False, indent=2))
    return 0 if decision.accepted else 2


if __name__ == "__main__":
    raise SystemExit(main())

