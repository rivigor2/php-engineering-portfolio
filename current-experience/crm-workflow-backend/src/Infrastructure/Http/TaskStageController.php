<?php

declare(strict_types=1);

namespace Portfolio\CrmWorkflow\Infrastructure\Http;

use DomainException;
use InvalidArgumentException;
use Portfolio\CrmWorkflow\Application\ChangeTaskParticipantStage;
use Portfolio\CrmWorkflow\Application\TaskParticipantStages;
use Portfolio\CrmWorkflow\Application\TaskStageGateway;
use Portfolio\CrmWorkflow\Application\TaskStageSnapshot;

/** Framework-neutral boundary; the hosting application supplies verified session identity and CSRF. */
final readonly class TaskStageController
{
    public function __construct(
        private TaskStageGateway $tasks,
        private ChangeTaskParticipantStage $changes,
        private TaskParticipantStages $policy,
    ) {}

    /** @return array{status: int, body: array<string, mixed>} */
    public function show(string $taskId, string $authenticatedActorId): array
    {
        try {
            $snapshot = $this->tasks->loadForActor($taskId, $authenticatedActorId);
            if (!$snapshot->actorCanEdit) {
                throw new DomainException('Task edit permission is required.', 403);
            }
            return $this->response($snapshot);
        } catch (DomainException|InvalidArgumentException $error) {
            return $this->failure($error);
        }
    }

    /** @param array<string, mixed> $input @return array{status: int, body: array<string, mixed>} */
    public function change(string $taskId, string $authenticatedActorId, bool $csrfVerified, array $input): array
    {
        if (trim($authenticatedActorId) === '') {
            return ['status' => 401, 'body' => ['error' => 'Authentication required.']];
        }
        if (!$csrfVerified) {
            return ['status' => 403, 'body' => ['error' => 'CSRF verification failed.']];
        }
        // Rights, actor, participants and board are never accepted from request JSON.
        if (array_diff(array_keys($input), ['expected_version', 'participant_id', 'stage']) !== []
            || !isset($input['expected_version']) || !is_int($input['expected_version']) || $input['expected_version'] < 1
            || !isset($input['participant_id'], $input['stage'])
            || !is_string($input['participant_id']) || trim($input['participant_id']) === ''
            || !is_string($input['stage']) || trim($input['stage']) === '') {
            return ['status' => 422, 'body' => ['error' => 'Version, participant and stage are required.']];
        }
        try {
            return $this->response($this->changes->handle(
                $taskId, $authenticatedActorId, $input['expected_version'],
                $input['participant_id'], $input['stage'],
            ));
        } catch (DomainException|InvalidArgumentException $error) {
            return $this->failure($error);
        }
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function response(TaskStageSnapshot $snapshot): array
    {
        return ['status' => 200, 'body' => [
            'task_id' => $snapshot->taskId,
            'board_id' => $snapshot->boardId,
            'version' => $snapshot->version,
            'allowed_stage_ids' => $snapshot->allowedStageIds,
            'rows' => $this->policy->visible($snapshot->participantIds, $snapshot->savedStages),
        ]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function failure(DomainException|InvalidArgumentException $error): array
    {
        $code = $error->getCode();
        return [
            'status' => in_array($code, [401, 403, 404, 409, 422], true) ? $code : 422,
            'body' => ['error' => $error->getMessage()],
        ];
    }
}
