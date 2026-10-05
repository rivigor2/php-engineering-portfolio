<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

interface OperationRepository
{
    public function find(string $operationId): ?StoredOperation;

    public function save(string $operationId, string $requestHash, ImportReport $report): void;
}

