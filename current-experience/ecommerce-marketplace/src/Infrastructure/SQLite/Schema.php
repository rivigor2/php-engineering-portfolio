<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Infrastructure\SQLite;

use PDO;

final readonly class Schema
{
    public function __construct(private PDO $pdo)
    {
    }

    public function ensure(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS catalog_items (
                external_id TEXT PRIMARY KEY,
                version INTEGER NOT NULL CHECK (version >= 1),
                name TEXT NOT NULL,
                price_minor INTEGER NOT NULL CHECK (price_minor >= 0),
                currency TEXT NOT NULL CHECK (currency = 'RUB'),
                stock INTEGER NOT NULL CHECK (stock >= 0),
                updated_at TEXT NOT NULL
            )
            SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS import_operations (
                operation_id TEXT PRIMARY KEY,
                request_hash TEXT NOT NULL,
                result_json TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
            SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS outbox_messages (
                message_id TEXT PRIMARY KEY,
                idempotency_key TEXT NOT NULL UNIQUE,
                target_url TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                state TEXT NOT NULL,
                attempts INTEGER NOT NULL CHECK (attempts >= 0),
                available_at TEXT NOT NULL,
                last_failure_kind TEXT NULL,
                last_error_code TEXT NULL,
                version INTEGER NOT NULL CHECK (version >= 1),
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS delivery_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message_id TEXT NOT NULL,
                cycle_attempt INTEGER NOT NULL CHECK (cycle_attempt >= 1),
                action TEXT NOT NULL,
                failure_kind TEXT NOT NULL,
                error_code TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (message_id) REFERENCES outbox_messages(message_id)
            )
            SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_outbox_ready
            ON outbox_messages (state, available_at)
            SQL);
    }
}

