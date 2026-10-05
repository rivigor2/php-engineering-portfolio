<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json');
switch ($path) {
    case '/redirect':
        header('Location: /redirect-target', true, 302);
        echo '{"accepted":false}';
        break;
    case '/redirect-target':
        echo '{"accepted":true,"redirect_followed":true}';
        break;
    case '/large':
        echo str_repeat('x', 70_000);
        break;
    case '/timeout':
        usleep(400_000);
        echo '{"accepted":true}';
        break;
    default:
        echo json_encode(['accepted' => true, 'injected' => isset($_SERVER['HTTP_X_INJECTED'])]);
}
