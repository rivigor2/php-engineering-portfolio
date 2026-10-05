<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Normalized delivery interval; marketplace field names are deliberately omitted. */
final readonly class DeliveryWindow
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
        if ($from >= $to) {
            throw new InvalidArgumentException('Delivery interval must have positive duration.');
        }
    }

    public static function fromIso(string $from, string $to): self
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D';
        foreach ([$from, $to] as $value) {
            if (!preg_match($pattern, $value)) {
                throw new InvalidArgumentException('Delivery time must include an explicit UTC offset.');
            }
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $from);
        $startErrors = DateTimeImmutable::getLastErrors();
        $finish = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $to);
        $finishErrors = DateTimeImmutable::getLastErrors();
        if ($start === false || $finish === false
            || ($startErrors !== false && ($startErrors['warning_count'] + $startErrors['error_count']) > 0)
            || ($finishErrors !== false && ($finishErrors['warning_count'] + $finishErrors['error_count']) > 0)) {
            throw new InvalidArgumentException('Invalid delivery date.');
        }
        return new self($start, $finish);
    }

    /** @return array{delivery_from: string, delivery_to: string, delivery_timezone: string} */
    public function orderFields(DateTimeZone $storeTimezone): array
    {
        return [
            'delivery_from' => $this->from->setTimezone($storeTimezone)->format(DATE_ATOM),
            'delivery_to' => $this->to->setTimezone($storeTimezone)->format(DATE_ATOM),
            'delivery_timezone' => $storeTimezone->getName(),
        ];
    }
}
