<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Domain;

enum OrderState: string
{
    case AwaitingCreation = 'awaiting_creation';
    case Created = 'created';
    case Confirmed = 'confirmed';
    case Paid = 'paid';
    case Assembling = 'assembling';
    case Ready = 'ready';
    case HandedToCourier = 'handed_to_courier';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }
}