<?php
declare(strict_types=1);
namespace Portfolio\CrmWorkflow\Application;

/** Loads authoritative server state; actor identity comes from an authenticated session. */
interface TaskStageGateway
{
    public function loadForActor(string $taskId, string $authenticatedActorId): TaskStageSnapshot;

    /** Rechecks rights, participants, board and version within the storage transaction. */
    public function changeIfUnchanged(
        TaskStageSnapshot $expected,
        string $participantId,
        string $targetStage,
    ): TaskStageSnapshot;
}
