<?php

declare(strict_types=1);

namespace Portfolio\DocumentFlow\Application;

interface DocumentFlowStore
{
    /** @template T @param callable(): T $work @return T */
    public function transaction(callable $work): mixed;

    /** @return array{crm_record_id: string, status: string, version: int, fields: array<string, mixed>}|null */
    public function document(string $organizationId, string $entityId): ?array;

    /** @return array{request_hash: string, outcome: array<string, mixed>}|null */
    public function receipt(string $organizationId, string $deliveryId): ?array;

    /** @param array<string, mixed> $fields */
    public function saveDocument(
        string $organizationId,
        string $entityId,
        int $expectedVersion,
        string $status,
        array $fields,
    ): void;

    /** @param array<string, mixed> $outcome */
    public function saveReceipt(string $organizationId, string $deliveryId, string $requestHash, array $outcome): void;
}
