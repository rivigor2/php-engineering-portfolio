<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

final readonly class StoredOperation
{
    public function __construct(
        public string $requestHash,
        public ImportReport $report,
    ) {
    }
}

