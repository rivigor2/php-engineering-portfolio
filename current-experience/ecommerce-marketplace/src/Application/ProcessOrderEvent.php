<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use DomainException;
use Portfolio\Commerce\Domain\MarketplaceOrder;
use Portfolio\Commerce\Domain\OrderEvent;
use Portfolio\Commerce\Domain\OrderTransition;

final readonly class ProcessOrderEvent
{
    public function __construct(
        private OrderRepository $orders,
        private EventReceiptRepository $receipts,
        private TransactionManager $transactions,
    ) {
    }

    public function handle(OrderEvent $event): OrderTransition
    {
        return $this->transactions->run(function () use ($event): OrderTransition {
            $hash = $event->requestHash();
            $stored = $this->receipts->find($event->eventId);
            if ($stored !== null) {
                if (!hash_equals($stored->payloadHash, $hash)) {
                    throw new DomainException('Event ID was reused with another payload.');
                }

                return new OrderTransition(
                    $event->eventId,
                    $stored->transition->previous,
                    $stored->transition->current,
                    $stored->transition->result === 'manual_review' ? 'manual_review' : 'ignored',
                    $stored->transition->result === 'manual_review' ? $stored->transition->reason : 'duplicate_delivery',
                );
            }

            $order = $this->orders->find($event->orderId) ?? new MarketplaceOrder($event->orderId);
            $transition = $order->apply($event);
            $this->orders->save($order);
            if ($event->type === 'order_created' && $transition->result !== 'manual_review') {
                $details = $event->payload['order'] ?? null;
                if ($details !== null) {
                    if (!is_array($details) || ($details['external_order_id'] ?? null) !== $event->orderId) {
                        throw new DomainException('Normalized order details do not match the event.');
                    }
                    $this->orders->saveDetails($event->orderId, $details);
                }
            }
            $this->receipts->save(new StoredEventReceipt($event->eventId, $hash, $transition));

            return $transition;
        });
    }
}
