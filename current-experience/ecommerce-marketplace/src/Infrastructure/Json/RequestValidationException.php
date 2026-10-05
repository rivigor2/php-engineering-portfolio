<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Infrastructure\Json;

use InvalidArgumentException;

final class RequestValidationException extends InvalidArgumentException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}

