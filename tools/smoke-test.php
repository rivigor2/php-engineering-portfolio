<?php

declare(strict_types=1);

use Portfolio\Commerce\Application\ImportCatalog;
use Portfolio\Commerce\Application\ImportReport;
use Portfolio\Commerce\Application\OperationConflict;
use Portfolio\Commerce\Application\OperationRepository;
use Portfolio\Commerce\Application\StoredOperation;
use Portfolio\Commerce\Infrastructure\Json\CatalogImportRequestParser;
use Portfolio\Commerce\Infrastructure\Json\RequestValidationException;
use Portfolio\Commerce\Infrastructure\SQLite\ConnectionFactory;
use Portfolio\Commerce\Infrastructure\SQLite\PdoCatalogRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoOperationRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoTransactionManager;
use Portfolio\Commerce\Infrastructure\SQLite\Schema;

require dirname(__DIR__) . '/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function demoItem(string $id, int $version = 1, int $price = 10000): array
{
    return [
        'external_id' => $id,
        'version' => $version,
        'name' => 'Demo ' . $id,
        'price_minor' => $price,
        'currency' => 'RUB',
        'stock' => 5,
    ];
}

/** @param list<array<string, mixed>> $items */
function request(CatalogImportRequestParser $parser, string $id, array $items): \Portfolio\Commerce\Application\CatalogImportRequest
{
    return $parser->parse((string) json_encode([
        'operation_id' => $id,
        'items' => $items,
    ], JSON_THROW_ON_ERROR));
}

$pdo = ConnectionFactory::memory();
(new Schema($pdo))->ensure();
$parser = new CatalogImportRequestParser();
$service = new ImportCatalog(
    new PdoCatalogRepository($pdo),
    new PdoOperationRepository($pdo),
    new PdoTransactionManager($pdo),
);

$first = $service->handle(request($parser, 'smoke-success', [demoItem('SKU-1'), demoItem('SKU-2')]));
check($first->status === 'completed' && $first->accepted === 2, 'Successful import failed.');
$repeat = $service->handle(request($parser, 'smoke-success', [demoItem('SKU-1'), demoItem('SKU-2')]));
check($repeat->repeated && (int) $pdo->query('SELECT COUNT(*) FROM catalog_items')->fetchColumn() === 2, 'Repeat is not idempotent.');

$broken = demoItem('SKU-BROKEN');
$broken['price_minor'] = -1;
$partial = $service->handle(request($parser, 'smoke-partial', [demoItem('SKU-3'), $broken]));
check($partial->status === 'completed_with_errors' && $partial->rejected === 1, 'Partial result failed.');

$service->handle(request($parser, 'smoke-v2', [demoItem('SKU-4', 2)]));
$stale = $service->handle(request($parser, 'smoke-v1', [demoItem('SKU-4', 1)]));
check($stale->items[0]->code === 'STALE_VERSION', 'Stale version was not rejected.');

try {
    $service->handle(request($parser, 'smoke-success', [demoItem('OTHER')]));
    throw new RuntimeException('Operation conflict was not detected.');
} catch (OperationConflict) {
}

try {
    $parser->parse('{"operation_id":"duplicate","items":[{"external_id":"X"},{"external_id":"X"}]}');
    throw new RuntimeException('Duplicate ID was not rejected.');
} catch (RequestValidationException) {
}

$rollbackPdo = ConnectionFactory::memory();
(new Schema($rollbackPdo))->ensure();
$failingStore = new class implements OperationRepository {
    public function find(string $operationId): ?StoredOperation
    {
        return null;
    }

    public function save(string $operationId, string $requestHash, ImportReport $report): void
    {
        throw new RuntimeException('Synthetic failure.');
    }
};
$rollbackService = new ImportCatalog(
    new PdoCatalogRepository($rollbackPdo),
    $failingStore,
    new PdoTransactionManager($rollbackPdo),
);
try {
    $rollbackService->handle(request($parser, 'smoke-rollback', [demoItem('ROLLBACK')]));
    throw new RuntimeException('Synthetic failure was expected.');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'Synthetic failure.', 'Unexpected rollback error.');
}
check((int) $rollbackPdo->query('SELECT COUNT(*) FROM catalog_items')->fetchColumn() === 0, 'Rollback failed.');

fwrite(STDOUT, "SMOKE_TESTS_PASSED: success, repeat, partial, version, conflict, duplicate, rollback\n");

