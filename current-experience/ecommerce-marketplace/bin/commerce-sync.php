<?php

declare(strict_types=1);

use Portfolio\Commerce\Application\ImportCatalog;
use Portfolio\Commerce\Application\OperationConflict;
use Portfolio\Commerce\Infrastructure\Json\CatalogImportRequestParser;
use Portfolio\Commerce\Infrastructure\Json\RequestValidationException;
use Portfolio\Commerce\Infrastructure\SQLite\ConnectionFactory;
use Portfolio\Commerce\Infrastructure\SQLite\PdoCatalogRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoOperationRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoTransactionManager;
use Portfolio\Commerce\Infrastructure\SQLite\Schema;

$root = dirname(__DIR__, 3);
$autoload = is_file($root . '/vendor/autoload.php')
    ? $root . '/vendor/autoload.php'
    : $root . '/autoload.php';
require $autoload;

/** @param array<string, mixed> $payload */
function writeResult(array $payload): void
{
    fwrite(STDOUT, json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ) . PHP_EOL);
}

$inputPath = $argv[1] ?? null;
$databasePath = $argv[2] ?? dirname(__DIR__) . '/var/commerce.sqlite';

if (!is_string($inputPath) || $inputPath === '') {
    fwrite(STDERR, "Usage: php commerce-sync.php <input.json> [database.sqlite]\n");
    exit(1);
}

try {
    $inputSize = filesize($inputPath);
    if ($inputSize === false || $inputSize > 1024 * 1024) {
        throw new RuntimeException('Input file must be readable and no larger than 1 MiB.');
    }
    $json = file_get_contents($inputPath);
    if ($json === false) {
        throw new RuntimeException(sprintf('Cannot read input file "%s".', $inputPath));
    }

    $request = (new CatalogImportRequestParser())->parse($json);
    $pdo = ConnectionFactory::file($databasePath);
    (new Schema($pdo))->ensure();

    $report = (new ImportCatalog(
        new PdoCatalogRepository($pdo),
        new PdoOperationRepository($pdo),
        new PdoTransactionManager($pdo),
    ))->handle($request);

    writeResult($report->toArray());
    exit($report->hasRejectedItems() ? 2 : 0);
} catch (RequestValidationException $error) {
    writeResult([
        'status' => 'request_rejected',
        'code' => 'INVALID_REQUEST',
        'errors' => $error->errors,
    ]);
    exit(1);
} catch (OperationConflict $error) {
    writeResult([
        'status' => 'request_rejected',
        'code' => 'OPERATION_CONFLICT',
        'errors' => [$error->getMessage()],
    ]);
    exit(1);
} catch (Throwable $error) {
    writeResult([
        'status' => 'failed',
        'code' => 'INTERNAL_ERROR',
        'errors' => ['The import could not be completed.'],
    ]);
    fwrite(STDERR, sprintf("%s\n", $error->getMessage()));
    exit(1);
}
