<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

final readonly class HttpResponse
{
    public function __construct(public int $statusCode, public string $body)
    {
    }
}
