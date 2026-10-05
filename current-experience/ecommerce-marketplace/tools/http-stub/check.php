<?php

declare(strict_types=1);

use Portfolio\Commerce\Application\TransportFailure;
use Portfolio\Commerce\Infrastructure\Http\StreamHttpTransport;

require dirname(__DIR__, 4) . '/autoload.php';

$transport = new StreamHttpTransport(0.2);
$expected = [
    '/success' => 200,
    '/business-error' => 200,
    '/rate-limit' => 429,
    '/client-error' => 422,
    '/server-error' => 503,
];

foreach ($expected as $path => $status) {
    $response = $transport->postJson(
        'http://127.0.0.1:8099' . $path,
        'stub-check-' . trim($path, '/'),
        ['synthetic' => true],
    );
    if ($response->statusCode !== $status) {
        throw new RuntimeException(sprintf('%s returned %d instead of %d.', $path, $response->statusCode, $status));
    }
}

try {
    $transport->postJson(
        'http://127.0.0.1:8099/timeout',
        'stub-check-timeout',
        ['synthetic' => true],
    );
    throw new RuntimeException('Timeout scenario unexpectedly completed.');
} catch (TransportFailure $failure) {
    if ($failure->kind !== 'network') {
        throw $failure;
    }
}

fwrite(STDOUT, "HTTP stub scenarios passed.\n");
