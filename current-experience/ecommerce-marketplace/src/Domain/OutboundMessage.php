<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Domain;

use DateTimeImmutable;
use DomainException;

final readonly class OutboundMessage
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $messageId,
        public string $idempotencyKey,
        public string $targetUrl,
        public array $payload,
        public DeliveryState $state,
        public int $attempts,
        public DateTimeImmutable $availableAt,
        public ?string $lastFailureKind = null,
        public ?string $lastErrorCode = null,
        public int $version = 1,
    ) {
        foreach ([$messageId, $idempotencyKey] as $identifier) {
            if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $identifier) !== 1) {
                throw new DomainException('Message and idempotency identifiers must use 1–128 ASCII letters, digits, dot, underscore, colon or hyphen.');
            }
        }
        if ($attempts < 0 || $version < 1) {
            throw new DomainException('Attempts and version are outside their valid ranges.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function pending(
        string $messageId,
        string $idempotencyKey,
        string $targetUrl,
        array $payload,
        DateTimeImmutable $now,
    ): self {
        return new self($messageId, $idempotencyKey, $targetUrl, $payload, DeliveryState::Pending, 0, $now);
    }

    public function isReady(DateTimeImmutable $now): bool
    {
        return in_array($this->state, [DeliveryState::Pending, DeliveryState::RetryScheduled], true)
            && $this->availableAt <= $now;
    }

    public function markSent(): self
    {
        return $this->next(DeliveryState::Sent, $this->attempts + 1, $this->availableAt, null, null);
    }

    public function scheduleRetry(DateTimeImmutable $availableAt, string $failureKind, string $errorCode): self
    {
        return $this->next(
            DeliveryState::RetryScheduled,
            $this->attempts + 1,
            $availableAt,
            $failureKind,
            $errorCode,
        );
    }

    public function markFailed(DeliveryState $state, string $failureKind, string $errorCode): self
    {
        if (!in_array($state, [DeliveryState::FailedTemporary, DeliveryState::FailedPermanent], true)) {
            throw new DomainException('Only a failed state can be assigned by markFailed.');
        }

        return $this->next($state, $this->attempts + 1, $this->availableAt, $failureKind, $errorCode);
    }

    public function manualRetry(DateTimeImmutable $now): self
    {
        if ($this->state !== DeliveryState::FailedTemporary) {
            throw new DomainException('Only an exhausted temporary failure can be retried manually.');
        }

        return new self(
            $this->messageId,
            $this->idempotencyKey,
            $this->targetUrl,
            $this->payload,
            DeliveryState::RetryScheduled,
            0,
            $now,
            $this->lastFailureKind,
            $this->lastErrorCode,
            $this->version + 1,
        );
    }

    private function next(
        DeliveryState $state,
        int $attempts,
        DateTimeImmutable $availableAt,
        ?string $failureKind,
        ?string $errorCode,
    ): self {
        return new self(
            $this->messageId,
            $this->idempotencyKey,
            $this->targetUrl,
            $this->payload,
            $state,
            $attempts,
            $availableAt,
            $failureKind,
            $errorCode,
            $this->version + 1,
        );
    }
}
