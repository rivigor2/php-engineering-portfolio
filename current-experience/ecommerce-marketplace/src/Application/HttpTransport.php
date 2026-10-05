<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

interface HttpTransport
{
    /** @param array<string, mixed> $payload */
    public function postJson(string $url, string $idempotencyKey, array $payload): HttpResponse;
}
