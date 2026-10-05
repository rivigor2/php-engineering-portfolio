<?php

declare(strict_types=1);

use Portfolio\Commerce\Application\DispatchOutboundMessage;
use Portfolio\Commerce\Application\RetryPolicy;
use Portfolio\Commerce\Application\SystemClock;
use Portfolio\Commerce\Domain\OutboundMessage;
use Portfolio\Commerce\Infrastructure\Http\StreamHttpTransport;
use Portfolio\Commerce\Infrastructure\SQLite\ConnectionFactory;
use Portfolio\Commerce\Infrastructure\SQLite\PdoAttemptLog;
use Portfolio\Commerce\Infrastructure\SQLite\PdoOutboxRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoTransactionManager;
use Portfolio\Commerce\Infrastructure\SQLite\Schema;

require dirname(__DIR__, 3) . '/autoload.php';

$command = $argv[1] ?? '';
$argument = $argv[2] ?? '';
$databasePath = $argv[3] ?? dirname(__DIR__) . '/var/commerce.sqlite';

try {
    if (!in_array($command, ['enqueue', 'dispatch', 'status', 'retry'], true) || $argument === '') {
        throw new InvalidArgumentException(
            'Usage: outbox.php enqueue <request.json> [db] | dispatch|status|retry <message_id> [db]'
        );
    }

    $pdo = ConnectionFactory::file($databasePath);
    (new Schema($pdo))->ensure();
    $repository = new PdoOutboxRepository($pdo);
    $attemptLog = new PdoAttemptLog($pdo);
    $clock = new SystemClock();
    $transactions = new PdoTransactionManager($pdo);
    $dispatcher = new DispatchOutboundMessage(
        $repository,
        $attemptLog,
        new StreamHttpTransport(),
        new RetryPolicy(),
        $clock,
        $transactions,
    );

    if ($command === 'enqueue') {
        $message = parseMessageFile($argument, $clock->now());
        $message = $transactions->run(static function () use ($repository, $message): OutboundMessage {
            $repository->add($message);
            return $repository->get($message->messageId)
                ?? throw new RuntimeException('Enqueued message was not saved.');
        });
    } else {
        if ($command === 'dispatch') {
            $message = $dispatcher->dispatch($argument);
        } elseif ($command === 'retry') {
            $message = $dispatcher->retryManually($argument);
        } else {
            $message = $repository->get($argument);
        }
        if ($message === null) {
            throw new DomainException('Outbound message was not found.');
        }
    }

    $view = messageView($message);
    $view['attempt_history'] = $attemptLog->history($message->messageId);
    fwrite(STDOUT, json_encode($view, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'error' => $exception instanceof DomainException || $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'The outbox command could not be completed.',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

function parseMessageFile(string $path, DateTimeImmutable $now): OutboundMessage
{
    $size = is_file($path) ? filesize($path) : false;
    if ($size === false || $size > 1_048_576) {
        throw new InvalidArgumentException('Request file is missing or exceeds 1 MiB.');
    }

    $contents = @file_get_contents($path, length: 1_048_577);
    if ($contents === false) {
        throw new InvalidArgumentException('Request file cannot be read.');
    }

    if (strlen($contents) > 1_048_576) {
        throw new InvalidArgumentException('Request file exceeds 1 MiB.');
    }
    try {
        $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Request file contains invalid JSON.');
    }
    if (!is_array($data) || array_is_list($data)) {
        throw new InvalidArgumentException('Request must be a JSON object.');
    }
    $allowed = ['message_id', 'idempotency_key', 'target_url', 'payload'];
    $unknown = array_diff(array_keys($data), $allowed);
    if ($unknown !== [] || array_diff($allowed, array_keys($data)) !== []) {
        throw new InvalidArgumentException('Request fields must exactly match the documented contract.');
    }
    foreach (['message_id', 'idempotency_key', 'target_url'] as $field) {
        if (!is_string($data[$field]) || trim($data[$field]) === '' || strlen($data[$field]) > 255) {
            throw new InvalidArgumentException($field . ' must be a non-empty string up to 255 bytes.');
        }
    }
    if (!is_array($data['payload']) || array_is_list($data['payload'])) {
        throw new InvalidArgumentException('payload must be a JSON object.');
    }

    /** @var array<string, mixed> $payload */
    $payload = $data['payload'];

    return OutboundMessage::pending(
        $data['message_id'],
        $data['idempotency_key'],
        $data['target_url'],
        $payload,
        $now,
    );
}

/** @return array<string, int|string|null> */
function messageView(OutboundMessage $message): array
{
    return [
        'message_id' => $message->messageId,
        'state' => $message->state->value,
        'attempts_in_cycle' => $message->attempts,
        'available_at' => $message->availableAt->format(DATE_ATOM),
        'last_failure_kind' => $message->lastFailureKind,
        'last_error_code' => $message->lastErrorCode,
        'version' => $message->version,
    ];
}
