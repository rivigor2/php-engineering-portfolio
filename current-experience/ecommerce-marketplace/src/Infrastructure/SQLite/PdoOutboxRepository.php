<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Infrastructure\SQLite;

use DateTimeImmutable;
use DomainException;
use JsonException;
use PDO;
use PDOException;
use Portfolio\Commerce\Application\OutboxRepository;
use Portfolio\Commerce\Domain\DeliveryState;
use Portfolio\Commerce\Domain\OutboundMessage;
use RuntimeException;

final readonly class PdoOutboxRepository implements OutboxRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function add(OutboundMessage $message): void
    {
        $existing = $this->get($message->messageId);
        if ($existing !== null) {
            if ($this->sameRequest($existing, $message)) {
                return;
            }

            throw new DomainException('Message ID is already used for different content.');
        }

        $now = (new DateTimeImmutable('now'))->format(DATE_ATOM);
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO outbox_messages (
                message_id, idempotency_key, target_url, payload_json, state, attempts,
                available_at, last_failure_kind, last_error_code, version, created_at, updated_at
            ) VALUES (
                :message_id, :idempotency_key, :target_url, :payload_json, :state, :attempts,
                :available_at, :last_failure_kind, :last_error_code, :version, :created_at, :updated_at
            )
            SQL);

        try {
            $statement->execute($this->parameters($message, $now));
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'UNIQUE constraint failed')) {
                throw new DomainException('Message or idempotency key is already used.', 0, $exception);
            }

            throw $exception;
        }
    }

    public function get(string $messageId): ?OutboundMessage
    {
        $statement = $this->pdo->prepare('SELECT * FROM outbox_messages WHERE message_id = :message_id');
        $statement->execute(['message_id' => $messageId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Unexpected outbox row.');
        }

        return $this->hydrate($row);
    }

    public function save(OutboundMessage $message, int $expectedVersion): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE outbox_messages SET
                state = :state,
                attempts = :attempts,
                available_at = :available_at,
                last_failure_kind = :last_failure_kind,
                last_error_code = :last_error_code,
                version = :version,
                updated_at = :updated_at
            WHERE message_id = :message_id AND version = :expected_version
            SQL);
        $statement->execute([
            'state' => $message->state->value,
            'attempts' => $message->attempts,
            'available_at' => $message->availableAt->format(DATE_ATOM),
            'last_failure_kind' => $message->lastFailureKind,
            'last_error_code' => $message->lastErrorCode,
            'version' => $message->version,
            'updated_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'message_id' => $message->messageId,
            'expected_version' => $expectedVersion,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new DomainException('Outbound message changed concurrently.');
        }
    }

    public function findByState(?DeliveryState $state): array
    {
        if ($state === null) {
            $statement = $this->pdo->query(
                'SELECT * FROM outbox_messages ORDER BY updated_at DESC, message_id ASC LIMIT 100'
            );
        } else {
            $statement = $this->pdo->prepare(
                'SELECT * FROM outbox_messages WHERE state = :state '
                . 'ORDER BY updated_at DESC, message_id ASC LIMIT 100'
            );
            $statement->execute(['state' => $state->value]);
        }
        if ($statement === false) {
            throw new RuntimeException('Cannot list outbox messages.');
        }

        $rows = $statement->fetchAll();
        $messages = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Unexpected outbox row.');
            }
            $messages[] = $this->hydrate($row);
        }

        return $messages;
    }

    /** @return array<string, scalar|null> */
    private function parameters(OutboundMessage $message, string $now): array
    {
        return [
            'message_id' => $message->messageId,
            'idempotency_key' => $message->idempotencyKey,
            'target_url' => $message->targetUrl,
            'payload_json' => json_encode($message->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'state' => $message->state->value,
            'attempts' => $message->attempts,
            'available_at' => $message->availableAt->format(DATE_ATOM),
            'last_failure_kind' => $message->lastFailureKind,
            'last_error_code' => $message->lastErrorCode,
            'version' => $message->version,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): OutboundMessage
    {
        try {
            $payload = json_decode((string) $row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored outbox payload is invalid.', 0, $exception);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('Stored outbox payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        return new OutboundMessage(
            (string) $row['message_id'],
            (string) $row['idempotency_key'],
            (string) $row['target_url'],
            $payload,
            DeliveryState::from((string) $row['state']),
            (int) $row['attempts'],
            new DateTimeImmutable((string) $row['available_at']),
            $row['last_failure_kind'] === null ? null : (string) $row['last_failure_kind'],
            $row['last_error_code'] === null ? null : (string) $row['last_error_code'],
            (int) $row['version'],
        );
    }

    private function sameRequest(OutboundMessage $left, OutboundMessage $right): bool
    {
        return $left->idempotencyKey === $right->idempotencyKey
            && $left->targetUrl === $right->targetUrl
            && $this->canonicalPayload($left->payload) === $this->canonicalPayload($right->payload);
    }

    /** @param array<mixed> $payload */
    private function canonicalPayload(array $payload): string
    {
        // Object key order is insignificant; array order and numeric types are preserved.
        $this->sortObjectKeys($payload);
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param array<mixed> $value */
    private function sortObjectKeys(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortObjectKeys($child);
            }
        }
        unset($child);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
    }
}
