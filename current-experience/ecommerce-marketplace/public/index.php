<?php

declare(strict_types=1);

use Portfolio\Commerce\Application\DispatchOutboundMessage;
use Portfolio\Commerce\Application\RetryPolicy;
use Portfolio\Commerce\Application\SystemClock;
use Portfolio\Commerce\Domain\DeliveryState;
use Portfolio\Commerce\Infrastructure\Http\StreamHttpTransport;
use Portfolio\Commerce\Infrastructure\SQLite\ConnectionFactory;
use Portfolio\Commerce\Infrastructure\SQLite\PdoAttemptLog;
use Portfolio\Commerce\Infrastructure\SQLite\PdoOutboxRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoTransactionManager;
use Portfolio\Commerce\Infrastructure\SQLite\Schema;

require dirname(__DIR__, 3) . '/autoload.php';

set_exception_handler(static function (Throwable $error): void {
    error_log('Local operator failure: ' . $error::class);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не удалось выполнить операцию. Проверьте локальное хранилище.';
});

// Binding to loopback is necessary; reject untrusted Host headers as well.
$host = $_SERVER['HTTP_HOST'] ?? '';
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)
    || preg_match('/\A(?:127\.0\.0\.1|localhost)(?::[0-9]{1,5})?\z/', $host) !== 1) {
    http_response_code(403);
    exit('Local access only.');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    exit('Method not allowed.');
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$databasePath = getenv('COMMERCE_DB_PATH');
if ($databasePath === false || $databasePath === '') {
    $databasePath = dirname(__DIR__) . '/var/commerce.sqlite';
} else {
    $databasePath = str_replace('\\', '/', $databasePath);
    if (!str_starts_with($databasePath, '/') && preg_match('~\A[A-Za-z]:/~', $databasePath) !== 1) {
        $databasePath = dirname(__DIR__, 3) . '/' . $databasePath;
    }
}

$pdo = ConnectionFactory::file($databasePath);
(new Schema($pdo))->ensure();
$repository = new PdoOutboxRepository($pdo);
$attemptLog = new PdoAttemptLog($pdo);
$transactions = new PdoTransactionManager($pdo);
$dispatcher = new DispatchOutboundMessage(
    $repository,
    $attemptLog,
    new StreamHttpTransport(),
    new RetryPolicy(),
    new SystemClock(),
    $transactions,
);

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

if ($method === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? null;
    $messageId = $_POST['message_id'] ?? null;
    $action = $_POST['action'] ?? null;

    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        exit('Защитный токен устарел. Обновите страницу.');
    }
    try {
        if ($action !== 'retry') {
            throw new DomainException('Неизвестное действие.');
        }
        if (!is_string($messageId) || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $messageId) !== 1) {
            throw new DomainException('Некорректный идентификатор сообщения.');
        }

        $dispatcher->retryManually($messageId);
        $_SESSION['flash'] = ['type' => 'success', 'text' => 'Временная ошибка поставлена на повтор.'];
    } catch (DomainException $exception) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => $exception->getMessage()];
    }

    header('Location: /', true, 303);
    exit;
}

$stateValue = $_GET['state'] ?? '';
if (!is_string($stateValue)) {
    $stateValue = '';
}
$state = $stateValue === '' ? null : DeliveryState::tryFrom($stateValue);
$invalidFilter = $stateValue !== '' && $state === null;
$messages = $repository->findByState($invalidFilter ? null : $state);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/** @param scalar|null $value */
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Commerce Outbox · локальная панель</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/app.js" defer></script>
</head>
<body>
<main class="shell">
    <header class="page-header">
        <div>
            <p class="eyebrow">Clean-room demo · локальный режим</p>
            <h1>Исходящие сообщения</h1>
            <p>Состояние доставки и безопасные коды ошибок без содержимого заказов. История попыток доступна командой status.</p>
        </div>
        <span class="count"><?= count($messages) ?> / 100</span>
    </header>

    <?php if (is_array($flash) && isset($flash['type'], $flash['text'])): ?>
        <div class="notice notice--<?= h($flash['type']) ?>" role="status"><?= h($flash['text']) ?></div>
    <?php endif; ?>
    <?php if ($invalidFilter): ?>
        <div class="notice notice--error" role="alert">Неизвестный фильтр состояния; показаны все сообщения.</div>
    <?php endif; ?>

    <form class="filters" method="get" action="/">
        <label for="state">Состояние</label>
        <select id="state" name="state">
            <option value="">Все</option>
            <?php foreach (DeliveryState::cases() as $option): ?>
                <option value="<?= h($option->value) ?>" <?= $state === $option ? 'selected' : '' ?>>
                    <?= h($option->value) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Применить</button>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Сообщение</th>
                <th>Состояние</th>
                <th>Попытки</th>
                <th>Доступно</th>
                <th>Последняя ошибка</th>
                <th>Действие</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($messages === []): ?>
                <tr><td colspan="6" class="empty">Сообщений по выбранному фильтру нет.</td></tr>
            <?php endif; ?>
            <?php foreach ($messages as $message): ?>
                <tr>
                    <td><code><?= h($message->messageId) ?></code></td>
                    <td><span class="status status--<?= h($message->state->value) ?>"><?= h($message->state->value) ?></span></td>
                    <td><?= $message->attempts ?></td>
                    <td><time datetime="<?= h($message->availableAt->format(DATE_ATOM)) ?>"><?= h($message->availableAt->format('Y-m-d H:i:s P')) ?></time></td>
                    <td><?= h($message->lastErrorCode ?? '—') ?></td>
                    <td>
                        <?php if ($message->state === DeliveryState::FailedTemporary): ?>
                            <form method="post" action="/" data-confirm="Повторить временно ошибочную доставку?">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="message_id" value="<?= h($message->messageId) ?>">
                                <button type="submit" class="button-secondary">Повторить</button>
                            </form>
                        <?php else: ?>
                            <span aria-label="Действие недоступно">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="footnote">Панель предназначена для локальной демонстрации. Авторизация и публичное размещение не реализованы.</p>
</main>
</body>
</html>
