<?php

declare(strict_types=1);

namespace Portfolio\DocumentFlow\Application;

use DateTimeImmutable;
use InvalidArgumentException;

/** Independent normalized contract for act autofill, not a vendor payload or CRM field schema. */
final class MapActToCrmFields
{
    /** @param array<string, mixed> $fields @return array{act_number: string, act_date: string, amount_minor: int, currency: string} */
    public function map(array $fields): array
    {
        if (PHP_INT_SIZE < 8) {
            throw new \LogicException('64-bit PHP is required for integer monetary amounts.');
        }
        $number = $fields['number'] ?? null;
        $date = $fields['date'] ?? null;
        $amount = $fields['amount'] ?? null;
        $currency = $fields['currency'] ?? null;
        if (!is_string($number) || trim($number) === '' || strlen($number) > 128
            || preg_match('/[\x00-\x1f]/', $number)) {
            throw new InvalidArgumentException('Act number is invalid.');
        }
        if (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) || str_starts_with($date, '0000-')) {
            throw new InvalidArgumentException('Act date is required.');
        }
        // Business date is not a timestamp: no timezone conversion can move it to another day.
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Act date must be a real date in YYYY-MM-DD format.');
        }
        // Do not round money through float; the example supports non-negative amounts with two decimals.
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $amount, $parts)) {
            throw new InvalidArgumentException('Act amount must be a decimal string with at most two fractional digits.');
        }
        if ($currency !== 'RUB') {
            throw new InvalidArgumentException('This act example supports RUB amounts only.');
        }
        return [
            'act_number' => trim($number),
            'act_date' => $date,
            'amount_minor' => (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0'),
            'currency' => $currency,
        ];
    }
}
