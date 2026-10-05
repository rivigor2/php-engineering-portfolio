<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use Portfolio\Commerce\Domain\OrderTransition;

final readonly class StoredEventReceipt
{
    public function __construct(
        public string $eventId,
        public string $payloadHash,
        public OrderTransition $transition,
    ) {
    }
}