<?php
declare(strict_types=1);
namespace Portfolio\CrmWorkflow\Application;

final readonly class TaskStageSnapshot
{
    /**
     * @param list<string> $participantIds
     * @param list<string> $allowedStageIds
     * @param list<array{participant_id: string, stage: string}> $savedStages
     */
    public function __construct(
        public string $taskId,
        public string $boardId,
        public int $version,
        public string $actorId,
        public bool $actorCanEdit,
        public array $participantIds,
        public array $allowedStageIds,
        public array $savedStages,
    ) {}
}
