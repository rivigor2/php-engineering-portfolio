<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Infrastructure\Http;

use JsonException;
use InvalidArgumentException;
use Portfolio\Commerce\Application\HttpResponse;
use Portfolio\Commerce\Application\HttpTransport;
use Portfolio\Commerce\Application\TransportFailure;

final readonly class StreamHttpTransport implements HttpTransport
{
    private const MAX_RESPONSE_BYTES = 65_536;

    public function __construct(private float $timeoutSeconds = 1.0)
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 30) {
            throw new InvalidArgumentException('HTTP timeout must be between 0 and 30 seconds.');
        }
    }

    public function postJson(string $url, string $idempotencyKey, array $payload): HttpResponse
    {
        $this->assertLoopbackUrl($url);
        if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $idempotencyKey) !== 1) {
            throw new TransportFailure('policy', 'Invalid Idempotency-Key.');
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw new TransportFailure('serialization', 'Payload cannot be encoded as JSON.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Idempotency-Key: ' . $idempotencyKey,
                ],
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new TransportFailure('network', 'HTTP request did not complete before the local timeout.');
        }
        try {
            $responseBody = @stream_get_contents($stream, self::MAX_RESPONSE_BYTES + 1);
            $metadata = stream_get_meta_data($stream);
            if ($responseBody === false || $metadata['timed_out']) {
                throw new TransportFailure('network', 'HTTP response did not complete before the local timeout.');
            }
            if (strlen($responseBody) > self::MAX_RESPONSE_BYTES) {
                throw new TransportFailure('protocol', 'HTTP response exceeds 64 KiB.');
            }
            $headers = $metadata['wrapper_data'] ?? [];
        } finally {
            fclose($stream);
        }
        $statusLine = $headers[0] ?? '';
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $statusLine, $matches) !== 1) {
            throw new TransportFailure('protocol', 'HTTP response status is missing.');
        }

        return new HttpResponse((int) $matches[1], $responseBody);
    }

    private function assertLoopbackUrl(string $url): void
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new TransportFailure('policy', 'Malformed target URL.');
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        if ($scheme !== 'http' || !in_array($host, ['127.0.0.1', 'localhost'], true)) {
            throw new TransportFailure('policy', 'Demo transport permits only loopback HTTP targets.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new TransportFailure('policy', 'Credentials and fragments are not allowed in target URLs.');
        }
    }
}
