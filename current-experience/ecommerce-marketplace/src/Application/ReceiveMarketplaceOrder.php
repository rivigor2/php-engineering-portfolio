<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use DateTimeImmutable;
use DateTimeZone;
use Portfolio\Commerce\Domain\OrderEvent;
use Portfolio\Commerce\Domain\OrderTransition;

final readonly class ReceiveMarketplaceOrder
{
    public function __construct(
        private MapIncomingOrder $mapper,
        private ProcessOrderEvent $events,
    ) {
    }

    /** @param array<string, mixed> $payload Synthetic public order contract. */
    public function handle(string $eventId, DateTimeImmutable $occurredAt, array $payload, DateTimeZone $storeTimezone): OrderTransition
    {
        $order = $this->mapper->map($payload, $storeTimezone);
        return $this->events->handle(new OrderEvent(
            $eventId,
            (string) $order['external_order_id'],
            'order_created',
            $occurredAt,
            ['order' => $order],
        ));
    }
}
