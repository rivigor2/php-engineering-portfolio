<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use InvalidArgumentException;

final readonly class RetryPolicy
{
    /** @param non-empty-list<int> $delaysSeconds */
    public function __construct(private array $delaysSeconds = [5, 30])
    {
        foreach ($delaysSeconds as $delay) {
            if ($delay < 1 || $delay > 3600) {
                throw new InvalidArgumentException('Retry delays must be between 1 and 3600 seconds.');
            }
        }
    }

    public function forResponse(HttpResponse $response, int $attempt): DeliveryDecision
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('Attempt must be positive.');
        }
        if ($response->statusCode >= 200 && $response->statusCode < 300) {
            $body = json_decode($response->body, true, 16);
            if (!is_array($body) || !is_bool($body['accepted'] ?? null)) {
                return new DeliveryDecision(DeliveryAction::FailedPermanent, 'protocol', 'INVALID_ACKNOWLEDGEMENT');
            }
            if ($body['accepted'] === false) {
                return new DeliveryDecision(
                    DeliveryAction::FailedPermanent,
                    'business',
                    'BUSINESS_REJECTED',
                );
            }

            return new DeliveryDecision(DeliveryAction::Sent, 'none', 'OK');
        }

        if ($response->statusCode === 408 || $response->statusCode === 429
            || ($response->statusCode >= 500 && $response->statusCode <= 599)) {
            return $this->temporary($attempt, 'http', 'HTTP_' . $response->statusCode);
        }

        return new DeliveryDecision(
            DeliveryAction::FailedPermanent,
            'http',
            'HTTP_' . $response->statusCode,
        );
    }

    public function forTransportFailure(TransportFailure $failure, int $attempt): DeliveryDecision
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('Attempt must be positive.');
        }
        if (!in_array($failure->kind, ['network', 'timeout'], true)) {
            return new DeliveryDecision(
                DeliveryAction::FailedPermanent,
                $failure->kind,
                strtoupper($failure->kind),
            );
        }

        return $this->temporary($attempt, $failure->kind, strtoupper($failure->kind));
    }

    private function temporary(int $attempt, string $kind, string $code): DeliveryDecision
    {
        $delayIndex = $attempt - 1;
        if (isset($this->delaysSeconds[$delayIndex])) {
            return new DeliveryDecision(DeliveryAction::Retry, $kind, $code, $this->delaysSeconds[$delayIndex]);
        }

        return new DeliveryDecision(DeliveryAction::FailedTemporary, $kind, $code);
    }
}
