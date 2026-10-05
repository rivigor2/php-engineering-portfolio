<?php

declare(strict_types=1);

use Portfolio\Commerce\Application\MapIncomingOrder;
use Portfolio\Commerce\Application\ProcessOrderEvent;
use Portfolio\Commerce\Application\ReceiveMarketplaceOrder;
use Portfolio\Commerce\Domain\OrderEvent;
use Portfolio\Commerce\Domain\OrderTransition;
use Portfolio\Commerce\Infrastructure\SQLite\PdoEventReceiptRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoOrderRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoTransactionManager;

require dirname(__DIR__, 3) . '/autoload.php';

try {
    $path = $argv[1] ?? dirname(__DIR__) . '/examples/order-lifecycle.json';
    $database = $argv[2] ?? dirname(__DIR__) . '/var/marketplace-order.sqlite';
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException('Cannot read scenario.');
    }
    $scenario = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($scenario) || !is_array($scenario['order'] ?? null)
        || !is_array($scenario['creation'] ?? null) || !is_array($scenario['events'] ?? null)
        || !array_is_list($scenario['events']) || !is_string($scenario['store_timezone'] ?? null)) {
        throw new InvalidArgumentException('Scenario needs order, creation, timezone and event list.');
    }
    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $orders = new PdoOrderRepository($pdo);
    $processor = new ProcessOrderEvent($orders, new PdoEventReceiptRepository($pdo), new PdoTransactionManager($pdo));
    $receiver = new ReceiveMarketplaceOrder(new MapIncomingOrder(), $processor);
    $creation = $scenario['creation'];
    if (!is_string($creation['event_id'] ?? null) || !is_string($creation['occurred_at'] ?? null)) {
        throw new InvalidArgumentException('Creation event identity and time are required.');
    }
    $first = $receiver->handle($creation['event_id'], new DateTimeImmutable($creation['occurred_at']), $scenario['order'], new DateTimeZone($scenario['store_timezone']));
    $orderId = (string) $scenario['order']['external_order_id'];
    $print = static function (OrderTransition $result) use ($orders, $orderId): void {
        echo json_encode([
            'event_id' => $result->eventId,
            'result' => $result->result,
            'reason' => $result->reason,
            'event_transition' => [$result->previous->value, $result->current->value],
            'persisted_state' => $orders->find($orderId)?->state()->value,
            'persisted_order' => $orders->details($orderId),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    };
    $print($first);
    foreach ($scenario['events'] as $row) {
        foreach (['event_id', 'type', 'occurred_at'] as $key) {
            if (!is_array($row) || !is_string($row[$key] ?? null) || trim($row[$key]) === '') {
                throw new InvalidArgumentException('Incomplete normalized event.');
            }
        }
        $payload = $row['payload'] ?? [];
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Invalid normalized event payload.');
        }
        $print($processor->handle(new OrderEvent($row['event_id'], $orderId, $row['type'], new DateTimeImmutable($row['occurred_at']), $payload)));
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
