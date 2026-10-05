<?php

declare(strict_types=1);

use Portfolio\Commerce\Application\ProcessOrderEvent;
use Portfolio\Commerce\Domain\OrderEvent;

use Portfolio\Commerce\Infrastructure\SQLite\PdoEventReceiptRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoOrderRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoTransactionManager;

require dirname(__DIR__, 3) . '/autoload.php';

try {
    $path = $argv[1] ?? dirname(__DIR__) . '/examples/order-events.json';
    $database = $argv[2] ?? dirname(__DIR__) . '/var/order-events.sqlite';
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException('Cannot read event fixture.');
    }
    $events = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($events) || !array_is_list($events)) {
        throw new InvalidArgumentException('Expected a list of normalized events.');
    }
    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $service = new ProcessOrderEvent(
        new PdoOrderRepository($pdo),
        new PdoEventReceiptRepository($pdo),
        new PdoTransactionManager($pdo),
    );
    foreach ($events as $row) {
        foreach (['event_id', 'order_id', 'type', 'occurred_at'] as $key) {
            if (!is_array($row) || !is_string($row[$key] ?? null) || trim($row[$key]) === '') {
                throw new InvalidArgumentException('Incomplete normalized event.');
            }
        }
        $payload = $row['payload'] ?? [];
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Normalized event payload must be an object.');
        }
        $event = new OrderEvent($row['event_id'], $row['order_id'], $row['type'], new DateTimeImmutable($row['occurred_at']), $payload);
        $result = $service->handle($event);
        echo json_encode([
            'event_id' => $result->eventId,
            'previous' => $result->previous->value,
            'current' => $result->current->value,
            'result' => $result->result,
            'reason' => $result->reason,
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
