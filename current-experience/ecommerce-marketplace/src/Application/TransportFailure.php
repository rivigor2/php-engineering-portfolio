<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use RuntimeException;

final class TransportFailure extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}
