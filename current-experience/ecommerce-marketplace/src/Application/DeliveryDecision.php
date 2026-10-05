<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

final readonly class DeliveryDecision
{
    public function __construct(
        public DeliveryAction $action,
        public string $failureKind,
        public string $errorCode,
        public int $delaySeconds = 0,
    ) {
    }
}
