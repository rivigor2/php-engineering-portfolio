<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $idempotencyKey === '') {
    http_response_code(400);
    echo json_encode(['accepted' => false, 'code' => 'INVALID_REQUEST']);
    return;
}

switch ($path) {
    case '/success':
        echo json_encode(['accepted' => true]);
        break;
    case '/business-error':
        echo json_encode(['accepted' => false, 'code' => 'ORDER_NOT_ACCEPTED']);
        break;
    case '/rate-limit':
        http_response_code(429);
        echo json_encode(['accepted' => false, 'code' => 'RATE_LIMITED']);
        break;
    case '/client-error':
        http_response_code(422);
        echo json_encode(['accepted' => false, 'code' => 'INVALID_ORDER']);
        break;
    case '/server-error':
        http_response_code(503);
        echo json_encode(['accepted' => false, 'code' => 'UNAVAILABLE']);
        break;
    case '/timeout':
        usleep(1_500_000);
        echo json_encode(['accepted' => true]);
        break;
    default:
        http_response_code(404);
        echo json_encode(['accepted' => false, 'code' => 'NOT_FOUND']);
}
