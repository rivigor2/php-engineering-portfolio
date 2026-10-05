<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Infrastructure\SQLite;

use JsonException;
use PDO;
use Portfolio\Commerce\Application\ImportReport;
use Portfolio\Commerce\Application\OperationRepository;
use Portfolio\Commerce\Application\StoredOperation;
use RuntimeException;

final readonly class PdoOperationRepository implements OperationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function find(string $operationId): ?StoredOperation
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT request_hash, result_json
            FROM import_operations
            WHERE operation_id = :operation_id
            SQL);
        $statement->execute(['operation_id' => $operationId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        try {
            $data = json_decode((string) $row['result_json'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Stored operation result is invalid.', 0, $error);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Stored operation result must be an object.');
        }

        /** @var array{operation_id: string, status: string, repeated: bool, accepted: int, rejected: int, items: list<array{external_id: ?string, status: string, code: string, errors?: list<string>}>} $data */
        return new StoredOperation(
            (string) $row['request_hash'],
            ImportReport::fromArray($data),
        );
    }

    public function save(string $operationId, string $requestHash, ImportReport $report): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO import_operations (operation_id, request_hash, result_json, created_at)
            VALUES (:operation_id, :request_hash, :result_json, :created_at)
            SQL);
        $statement->execute([
            'operation_id' => $operationId,
            'request_hash' => $requestHash,
            'result_json' => json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => gmdate('c'),
        ]);
    }
}

