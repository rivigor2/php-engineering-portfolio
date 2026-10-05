<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use Portfolio\Commerce\Domain\OutboundMessage;
use Portfolio\Commerce\Domain\DeliveryState;

interface OutboxRepository
{
    public function add(OutboundMessage $message): void;

    public function get(string $messageId): ?OutboundMessage;

    public function save(OutboundMessage $message, int $expectedVersion): void;

    /** @return list<OutboundMessage> */
    public function findByState(?DeliveryState $state): array;
}
