<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use RuntimeException;

final class OperationConflict extends RuntimeException
{
    public function __construct(string $operationId)
    {
        parent::__construct(sprintf(
            'Operation "%s" already exists with different content.',
            $operationId,
        ));
    }
}

