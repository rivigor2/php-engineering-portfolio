<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OrderEvent
{
    /** @param array<string, mixed> $payload Normalized business data. */
    public function __construct(
        public string $eventId,
        public string $orderId,
        public string $type,
        public DateTimeImmutable $occurredAt,
        public array $payload = [],
    ) {
        if (trim($eventId) === '' || trim($orderId) === '') {
            throw new InvalidArgumentException('Event and order identifiers are required.');
        }
    }
    public function requestHash(): string
    {
        return hash('sha256', json_encode([
            'order_id' => $this->orderId,
            'type' => $this->type,
            'occurred_at' => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
            'payload' => self::canonicalize($this->payload),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = self::canonicalize($item);
            }
        } elseif (is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Event payload must contain JSON values only.');
        }
        return $value;
    }
}
