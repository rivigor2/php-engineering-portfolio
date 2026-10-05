<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use Portfolio\Commerce\Domain\MarketplaceOrder;

interface OrderRepository
{
    public function find(string $orderId): ?MarketplaceOrder;

    public function save(MarketplaceOrder $order): void;
    /** @return array<string, mixed>|null */
    public function details(string $orderId): ?array;

    /** @param array<string, mixed> $details */
    public function saveDetails(string $orderId, array $details): void;
}
