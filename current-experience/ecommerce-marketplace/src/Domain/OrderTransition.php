<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Domain;

final readonly class OrderTransition
{
    public function __construct(
        public string $eventId,
        public OrderState $previous,
        public OrderState $current,
        public string $result,
        public string $reason,
    ) {
    }
}