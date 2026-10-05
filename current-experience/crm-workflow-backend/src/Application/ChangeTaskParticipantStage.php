<?php
declare(strict_types=1);
namespace Portfolio\CrmWorkflow\Application;

use DomainException;

final readonly class ChangeTaskParticipantStage
{
    public function __construct(
        private TaskStageGateway $tasks,
        private TaskParticipantStages $policy,
    ) {}

    public function handle(
        string $taskId,
        string $authenticatedActorId,
        int $expectedVersion,
        string $participantId,
        string $targetStage,
    ): TaskStageSnapshot {
        $snapshot = $this->tasks->loadForActor($taskId, $authenticatedActorId);
        if (!$snapshot->actorCanEdit) {
            throw new DomainException('Task edit permission is required.', 403);
        }
        if ($snapshot->version !== $expectedVersion) {
            throw new TaskStageConflict('Task changed; reload the card before editing.');
        }
        $this->policy->change(
            $snapshot->participantIds, $snapshot->savedStages, $participantId,
            $targetStage, $snapshot->allowedStageIds, $snapshot->actorCanEdit,
        );
        // The adapter repeats validation under its write lock; the read above is not a lock.
        return $this->tasks->changeIfUnchanged($snapshot, $participantId, $targetStage);
    }
}
