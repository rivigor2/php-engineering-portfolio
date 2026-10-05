<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Infrastructure\SQLite;

use PDO;
use Portfolio\Commerce\Application\CatalogRepository;
use Portfolio\Commerce\Domain\CatalogItem;

final readonly class PdoCatalogRepository implements CatalogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function find(string $externalId): ?CatalogItem
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT external_id, version, name, price_minor, currency, stock
            FROM catalog_items
            WHERE external_id = :external_id
            SQL);
        $statement->execute(['external_id' => $externalId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return new CatalogItem(
            (string) $row['external_id'],
            (int) $row['version'],
            (string) $row['name'],
            (int) $row['price_minor'],
            (string) $row['currency'],
            (int) $row['stock'],
        );
    }

    public function save(CatalogItem $item): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO catalog_items (
                external_id, version, name, price_minor, currency, stock, updated_at
            ) VALUES (
                :external_id, :version, :name, :price_minor, :currency, :stock, :updated_at
            )
            ON CONFLICT(external_id) DO UPDATE SET
                version = excluded.version,
                name = excluded.name,
                price_minor = excluded.price_minor,
                currency = excluded.currency,
                stock = excluded.stock,
                updated_at = excluded.updated_at
            SQL);
        $statement->execute([
            'external_id' => $item->externalId,
            'version' => $item->version,
            'name' => $item->name,
            'price_minor' => $item->priceMinor,
            'currency' => $item->currency,
            'stock' => $item->stock,
            'updated_at' => gmdate('c'),
        ]);
    }
}

