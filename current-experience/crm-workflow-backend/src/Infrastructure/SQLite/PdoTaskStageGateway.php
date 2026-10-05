<?php
declare(strict_types=1);
namespace Portfolio\CrmWorkflow\Infrastructure\SQLite;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;
use Portfolio\CrmWorkflow\Application\TaskParticipantStages;
use Portfolio\CrmWorkflow\Application\TaskStageGateway;
use Portfolio\CrmWorkflow\Application\TaskStageConflict;
use Portfolio\CrmWorkflow\Application\TaskStageSnapshot;

/** Public SQLite adapter; this schema is not the schema of the corporate portal. */
final readonly class PdoTaskStageGateway implements TaskStageGateway
{
    public function __construct(private PDO $pdo, private TaskParticipantStages $policy)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS task_stage_context (
            task_id TEXT PRIMARY KEY,
            board_id TEXT NOT NULL,
            version INTEGER NOT NULL CHECK (version >= 1),
            participants_json TEXT NOT NULL,
            board_stages_json TEXT NOT NULL,
            editors_json TEXT NOT NULL,
            saved_stages_json TEXT NOT NULL
        )');
    }

    public function loadForActor(string $taskId, string $authenticatedActorId): TaskStageSnapshot
    {
        if (trim($authenticatedActorId) === '') {
            throw new DomainException('Authenticated actor is required.', 401);
        }
        $query = $this->pdo->prepare('SELECT * FROM task_stage_context WHERE task_id = :task');
        $query->execute(['task' => $taskId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new DomainException('Task does not exist.', 404);
        }
        $decode = static function (string $json): array {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($value) || !array_is_list($value)) {
                throw new RuntimeException('Invalid authoritative task context.');
            }
            return $value;
        };
        return new TaskStageSnapshot(
            $row['task_id'], $row['board_id'], (int) $row['version'], $authenticatedActorId,
            in_array($authenticatedActorId, $decode($row['editors_json']), true),
            $decode($row['participants_json']), $decode($row['board_stages_json']),
            $decode($row['saved_stages_json']),
        );
    }

    public function changeIfUnchanged(
        TaskStageSnapshot $expected,
        string $participantId,
        string $targetStage,
    ): TaskStageSnapshot {
        // SQLite single-writer lock acquired BEFORE reloading authority and context.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $current = $this->loadForActor($expected->taskId, $expected->actorId);
            if (!$current->actorCanEdit) {
                throw new DomainException('Task edit permission was revoked.', 403);
            }
            if ($current->version !== $expected->version || $current->boardId !== $expected->boardId
                || $current->participantIds !== $expected->participantIds
                || $current->allowedStageIds !== $expected->allowedStageIds
                || $current->savedStages !== $expected->savedStages) {
                throw new TaskStageConflict('Task context changed; reload the card.');
            }
            $rows = $this->policy->change(
                $current->participantIds, $current->savedStages, $participantId,
                $targetStage, $current->allowedStageIds, $current->actorCanEdit,
            );
            if ($rows !== $current->savedStages) {
                $update = $this->pdo->prepare('UPDATE task_stage_context
                    SET saved_stages_json = :rows, version = version + 1
                    WHERE task_id = :task AND version = :version');
                $update->execute([
                    'rows' => json_encode($rows, JSON_THROW_ON_ERROR),
                    'task' => $current->taskId,
                    'version' => $current->version,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new TaskStageConflict('Concurrent update; reload the card.');
                }
            }
            $result = $this->loadForActor($current->taskId, $current->actorId);
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            $this->pdo->exec('ROLLBACK');
            throw $error;
        }
    }
}
