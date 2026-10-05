<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Tests\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Portfolio\Commerce\Domain\MarketplaceOrder;
use Portfolio\Commerce\Domain\OrderEvent;
use Portfolio\Commerce\Domain\OrderState;

final class MarketplaceOrderTest extends TestCase
{
    public function testLifecycleAndRepeatedTargetState(): void
    {
        $order = new MarketplaceOrder('order-42');
        self::assertSame('applied', $order->apply($this->event('e-0', 'order_created', '09:59'))->result);

        self::assertSame('applied', $order->apply($this->event('e-1', 'order_confirmed', '10:00'))->result);
        self::assertSame('applied', $order->apply($this->event('e-2', 'payment_confirmed', '10:01'))->result);
        $repeat = $order->apply($this->event('e-2-other', 'payment_confirmed', '10:01'));
        self::assertSame('ignored', $repeat->result);
        self::assertSame('state_already_applied', $repeat->reason);
        self::assertSame(OrderState::Paid, $order->state());
    }

    public function testOutOfOrderAndInvalidTransitionRequireReview(): void
    {
        $order = new MarketplaceOrder('order-42');
        self::assertSame('applied', $order->apply($this->event('e-0', 'order_created', '09:59'))->result);
        $order->apply($this->event('e-1', 'order_confirmed', '10:10'));

        self::assertSame('manual_review', $order->apply($this->event('e-2', 'delivery_completed', '10:11'))->result);
        self::assertSame('manual_review', $order->apply($this->event('e-3', 'payment_confirmed', '10:05'))->result);
        self::assertSame(OrderState::Confirmed, $order->state());
    }

    private function event(string $id, string $type, string $time): OrderEvent
    {
        return new OrderEvent($id, 'order-42', $type, new DateTimeImmutable('2026-01-01 ' . $time . ':00'));
    }
}
