<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

interface EventReceiptRepository
{
    public function find(string $eventId): ?StoredEventReceipt;

    public function save(StoredEventReceipt $receipt): void;
}