<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use DateTimeImmutable;

interface AttemptLog
{
    public function record(
        string $messageId,
        int $cycleAttempt,
        DeliveryDecision $decision,
        DateTimeImmutable $createdAt,
    ): void;
}
