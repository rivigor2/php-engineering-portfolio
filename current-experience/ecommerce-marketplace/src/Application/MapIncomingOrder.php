<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use DateTimeZone;
use InvalidArgumentException;
use Portfolio\Commerce\Domain\DeliveryWindow;

/** Pure mapping before creating an order; uses a new synthetic public contract. */
final class MapIncomingOrder
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function map(array $payload, DateTimeZone $storeTimezone): array
    {
        $id = $payload['external_order_id'] ?? null;
        $delivery = $payload['delivery_window'] ?? null;
        $lines = $payload['lines'] ?? null;
        if (!is_string($id) || trim($id) === '' || !is_array($delivery)
            || !is_string($delivery['from'] ?? null) || !is_string($delivery['to'] ?? null)
            || !is_array($lines) || !array_is_list($lines) || $lines === []) {
            throw new InvalidArgumentException('Order identity, lines and explicit delivery window are required.');
        }
        $items = [];
        $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line) || !is_string($line['sku'] ?? null) || trim($line['sku']) === ''
                || !is_int($line['quantity'] ?? null) || $line['quantity'] < 1
                || !is_int($line['unit_price_minor'] ?? null) || $line['unit_price_minor'] < 0) {
                throw new InvalidArgumentException('Invalid order line.');
            }
            if (isset($seen[$line['sku']])) {
                throw new InvalidArgumentException('Duplicate order line must be normalized before mapping.');
            }
            $seen[$line['sku']] = true;
            $items[] = [
                'sku' => $line['sku'],
                'quantity' => $line['quantity'],
                'unit_price_minor' => $line['unit_price_minor'],
            ];
        }
        $window = DeliveryWindow::fromIso($delivery['from'], $delivery['to']);
        return [
            'external_order_id' => $id,
            'source' => 'external_marketplace',
            'currency' => 'RUB',
            'items' => $items,
        ] + $window->orderFields($storeTimezone);
    }
}
