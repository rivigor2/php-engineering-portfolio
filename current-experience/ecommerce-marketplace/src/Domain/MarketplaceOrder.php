<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Domain;

use DateTimeImmutable;
use DomainException;

final class MarketplaceOrder
{

    /** @var array<string, OrderState> */
    private const TARGET_BY_EVENT = [
        'order_created' => OrderState::Created,
        'order_confirmed' => OrderState::Confirmed,
        'payment_confirmed' => OrderState::Paid,
        'assembly_started' => OrderState::Assembling,
        'assembly_completed' => OrderState::Ready,
        'handed_to_courier' => OrderState::HandedToCourier,
        'delivery_completed' => OrderState::Delivered,
        'order_cancelled' => OrderState::Cancelled,
    ];

    /** @var array<string, list<OrderState>> */
    private const ALLOWED = [
        'awaiting_creation' => [OrderState::Created],
        'created' => [OrderState::Confirmed, OrderState::Cancelled],
        'confirmed' => [OrderState::Paid, OrderState::Assembling, OrderState::Cancelled],
        'paid' => [OrderState::Assembling, OrderState::Cancelled],
        'assembling' => [OrderState::Ready, OrderState::Cancelled],
        'ready' => [OrderState::HandedToCourier, OrderState::Cancelled],
        'handed_to_courier' => [OrderState::Delivered],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function __construct(
        public readonly string $orderId,
        private OrderState $state = OrderState::AwaitingCreation,
        private ?DateTimeImmutable $lastEventAt = null,
    ) {
        if (trim($orderId) === '') {
            throw new DomainException('Order identifier is required.');
        }
    }

    public function state(): OrderState
    {
        return $this->state;
    }

    public function lastEventAt(): ?DateTimeImmutable
    {
        return $this->lastEventAt;
    }

    public function apply(OrderEvent $event): OrderTransition
    {
        if ($event->orderId !== $this->orderId) {
            throw new DomainException('The event belongs to another order.');
        }

        $target = self::TARGET_BY_EVENT[$event->type] ?? null;
        if ($target === null) {
            return new OrderTransition($event->eventId, $this->state, $this->state, 'manual_review', 'unknown_event');
        }

        if ($this->lastEventAt !== null && $event->occurredAt < $this->lastEventAt) {
            return new OrderTransition($event->eventId, $this->state, $this->state, 'manual_review', 'out_of_order_event');
        }

        $previous = $this->state;
        if ($target === $previous) {
            $this->remember($event);
            return new OrderTransition($event->eventId, $previous, $previous, 'ignored', 'state_already_applied');
        }

        if (!in_array($target, self::ALLOWED[$previous->value], true)) {
            return new OrderTransition($event->eventId, $previous, $previous, 'manual_review', 'transition_not_allowed');
        }

        $this->state = $target;
        $this->remember($event);

        return new OrderTransition($event->eventId, $previous, $target, 'applied', 'state_changed');
    }

    private function remember(OrderEvent $event): void
    {
        $this->lastEventAt = $event->occurredAt;
    }
}