<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Infrastructure\SQLite;

use PDO;
use Portfolio\Commerce\Application\EventReceiptRepository;
use Portfolio\Commerce\Application\StoredEventReceipt;
use Portfolio\Commerce\Domain\OrderState;
use Portfolio\Commerce\Domain\OrderTransition;

final readonly class PdoEventReceiptRepository implements EventReceiptRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS marketplace_event_receipts (
            event_id TEXT PRIMARY KEY,
            payload_hash TEXT NOT NULL,
            previous_state TEXT NOT NULL,
            current_state TEXT NOT NULL,
            result TEXT NOT NULL,
            reason TEXT NOT NULL
        )');
    }

    public function find(string $eventId): ?StoredEventReceipt
    {
        $statement = $this->pdo->prepare('SELECT * FROM marketplace_event_receipts WHERE event_id = :id');
        $statement->execute(['id' => $eventId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return new StoredEventReceipt(
            $eventId,
            (string) $row['payload_hash'],
            new OrderTransition(
                $eventId,
                OrderState::from((string) $row['previous_state']),
                OrderState::from((string) $row['current_state']),
                (string) $row['result'],
                (string) $row['reason'],
            ),
        );
    }

    public function save(StoredEventReceipt $receipt): void
    {
        $statement = $this->pdo->prepare('INSERT INTO marketplace_event_receipts
            (event_id, payload_hash, previous_state, current_state, result, reason)
            VALUES (:id, :hash, :previous, :current, :result, :reason)');
        $statement->execute([
            'id' => $receipt->eventId,
            'hash' => $receipt->payloadHash,
            'previous' => $receipt->transition->previous->value,
            'current' => $receipt->transition->current->value,
            'result' => $receipt->transition->result,
            'reason' => $receipt->transition->reason,
        ]);
    }
}
