<?php

declare(strict_types=1);

namespace Portfolio\DocumentFlow\Infrastructure\SQLite;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;
use Portfolio\DocumentFlow\Application\DocumentFlowStore;

/** Independent storage for a local CRM projection and received-delivery ledger. */
final readonly class PdoDocumentFlowStore implements DocumentFlowStore
{
    public function __construct(private PDO $pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS crm_documents (
            organization_id TEXT NOT NULL,
            entity_id TEXT NOT NULL,
            crm_record_id TEXT NOT NULL,
            status TEXT NOT NULL,
            version INTEGER NOT NULL CHECK (version >= 1),
            fields_json TEXT NOT NULL,
            PRIMARY KEY (organization_id, entity_id)
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS document_receipts (
            organization_id TEXT NOT NULL,
            delivery_id TEXT NOT NULL,
            request_hash TEXT NOT NULL,
            outcome_json TEXT NOT NULL,
            PRIMARY KEY (organization_id, delivery_id)
        )');
    }

    public function transaction(callable $work): mixed
    {
        // Fixture authority provider is pure. Network verification must not hold this local lock.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            $this->pdo->exec('ROLLBACK');
            throw $error;
        }
    }

    public function document(string $organizationId, string $entityId): ?array
    {
        $query = $this->pdo->prepare('SELECT crm_record_id, status, version, fields_json
            FROM crm_documents WHERE organization_id = :org AND entity_id = :entity');
        $query->execute(['org' => $organizationId, 'entity' => $entityId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'crm_record_id' => $row['crm_record_id'],
            'status' => $row['status'],
            'version' => (int) $row['version'],
            'fields' => $this->decode($row['fields_json']),
        ];
    }

    public function receipt(string $organizationId, string $deliveryId): ?array
    {
        $query = $this->pdo->prepare('SELECT request_hash, outcome_json FROM document_receipts
            WHERE organization_id = :org AND delivery_id = :delivery');
        $query->execute(['org' => $organizationId, 'delivery' => $deliveryId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['request_hash' => $row['request_hash'], 'outcome' => $this->decode($row['outcome_json'])];
    }

    public function saveDocument(
        string $organizationId,
        string $entityId,
        int $expectedVersion,
        string $status,
        array $fields,
    ): void {
        $query = $this->pdo->prepare('UPDATE crm_documents
            SET status = :status, fields_json = :fields, version = version + 1
            WHERE organization_id = :org AND entity_id = :entity AND version = :version');
        $query->execute([
            'status' => $status, 'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
            'org' => $organizationId, 'entity' => $entityId, 'version' => $expectedVersion,
        ]);
        if ($query->rowCount() !== 1) {
            throw new DomainException('CRM projection changed before saving.');
        }
    }

    public function saveReceipt(string $organizationId, string $deliveryId, string $requestHash, array $outcome): void
    {
        $query = $this->pdo->prepare('INSERT INTO document_receipts VALUES (:org, :delivery, :hash, :outcome)');
        $query->execute([
            'org' => $organizationId, 'delivery' => $deliveryId, 'hash' => $requestHash,
            'outcome' => json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('Invalid stored document data.');
        }
        return $value;
    }
}
