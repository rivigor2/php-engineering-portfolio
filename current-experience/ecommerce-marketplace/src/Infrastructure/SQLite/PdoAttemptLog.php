<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Infrastructure\SQLite;

use DateTimeImmutable;
use PDO;
use Portfolio\Commerce\Application\AttemptLog;
use Portfolio\Commerce\Application\DeliveryDecision;

final readonly class PdoAttemptLog implements AttemptLog
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(
        string $messageId,
        int $cycleAttempt,
        DeliveryDecision $decision,
        DateTimeImmutable $createdAt,
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO delivery_attempts (
                message_id, cycle_attempt, action, failure_kind, error_code, created_at
            ) VALUES (
                :message_id, :cycle_attempt, :action, :failure_kind, :error_code, :created_at
            )
            SQL);
        $statement->execute([
            'message_id' => $messageId,
            'cycle_attempt' => $cycleAttempt,
            'action' => $decision->action->value,
            'failure_kind' => $decision->failureKind,
            'error_code' => $decision->errorCode,
            'created_at' => $createdAt->format(DATE_ATOM),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function history(string $messageId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT cycle_attempt, action, failure_kind, error_code, created_at
            FROM delivery_attempts
            WHERE message_id = :message_id
            ORDER BY id
            SQL);
        $statement->execute(['message_id' => $messageId]);
        $rows = $statement->fetchAll();

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }
}
