<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use Portfolio\Commerce\Domain\CatalogItem;

interface CatalogRepository
{
    public function find(string $externalId): ?CatalogItem;

    public function save(CatalogItem $item): void;
}

