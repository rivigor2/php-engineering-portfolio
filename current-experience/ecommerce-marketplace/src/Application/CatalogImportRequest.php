<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

final readonly class CatalogImportRequest
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public string $operationId,
        public array $items,
        public string $requestHash,
    ) {
    }
}

