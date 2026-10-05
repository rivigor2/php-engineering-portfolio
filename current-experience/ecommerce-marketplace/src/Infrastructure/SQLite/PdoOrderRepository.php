<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Infrastructure\SQLite;

use DateTimeImmutable;
use PDO;
use Portfolio\Commerce\Application\OrderRepository;
use Portfolio\Commerce\Domain\MarketplaceOrder;
use Portfolio\Commerce\Domain\OrderState;

final readonly class PdoOrderRepository implements OrderRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS marketplace_orders (
            order_id TEXT PRIMARY KEY,
            state TEXT NOT NULL,
            last_event_at TEXT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS marketplace_order_details (
            order_id TEXT PRIMARY KEY,
            normalized_order_json TEXT NOT NULL,
            FOREIGN KEY(order_id) REFERENCES marketplace_orders(order_id)
        )');
    }

    public function find(string $orderId): ?MarketplaceOrder
    {
        $statement = $this->pdo->prepare('SELECT state, last_event_at FROM marketplace_orders WHERE order_id = :id');
        $statement->execute(['id' => $orderId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return new MarketplaceOrder(
            $orderId,
            OrderState::from((string) $row['state']),
            $row['last_event_at'] === null ? null : new DateTimeImmutable((string) $row['last_event_at']),
        );
    }

    public function save(MarketplaceOrder $order): void
    {
        $statement = $this->pdo->prepare('INSERT INTO marketplace_orders (order_id, state, last_event_at)
            VALUES (:id, :state, :event_at)
            ON CONFLICT(order_id) DO UPDATE SET state = excluded.state, last_event_at = excluded.last_event_at');
        $statement->execute([
            'id' => $order->orderId,
            'state' => $order->state()->value,
            'event_at' => $order->lastEventAt()?->format(DATE_ATOM),
        ]);
    }
    public function details(string $orderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT normalized_order_json FROM marketplace_order_details WHERE order_id = :id');
        $statement->execute(['id' => $orderId]);
        $json = $statement->fetchColumn();
        if ($json === false) {
            return null;
        }
        $details = json_decode((string) $json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($details)) {
            throw new \UnexpectedValueException('Stored order details are not an object.');
        }
        return $details;
    }

    public function saveDetails(string $orderId, array $details): void
    {
        $existing = $this->details($orderId);
        if ($existing !== null) {
            if ($existing !== $details) {
                throw new \DomainException('Order creation cannot replace existing order details.');
            }
            return;
        }
        $statement = $this->pdo->prepare('INSERT INTO marketplace_order_details (order_id, normalized_order_json) VALUES (:id, :data)');
        $statement->execute([
            'id' => $orderId,
            'data' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
