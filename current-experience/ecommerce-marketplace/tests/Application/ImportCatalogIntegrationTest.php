<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Tests\Application;

use PHPUnit\Framework\TestCase;
use Portfolio\Commerce\Application\ImportCatalog;
use Portfolio\Commerce\Application\ImportReport;
use Portfolio\Commerce\Application\OperationConflict;
use Portfolio\Commerce\Application\OperationRepository;
use Portfolio\Commerce\Application\StoredOperation;
use Portfolio\Commerce\Infrastructure\Json\CatalogImportRequestParser;
use Portfolio\Commerce\Infrastructure\SQLite\ConnectionFactory;
use Portfolio\Commerce\Infrastructure\SQLite\PdoCatalogRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoOperationRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoTransactionManager;
use Portfolio\Commerce\Infrastructure\SQLite\Schema;
use RuntimeException;

final class ImportCatalogIntegrationTest extends TestCase
{
    private \PDO $pdo;
    private CatalogImportRequestParser $parser;
    private ImportCatalog $service;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::memory();
        (new Schema($this->pdo))->ensure();
        $this->parser = new CatalogImportRequestParser();
        $this->service = new ImportCatalog(
            new PdoCatalogRepository($this->pdo),
            new PdoOperationRepository($this->pdo),
            new PdoTransactionManager($this->pdo),
        );
    }

    public function testSuccessfulImportAndRepeatAreIdempotent(): void
    {
        $request = $this->request('op-success', [
            $this->item('SKU-1'),
            $this->item('SKU-2', priceMinor: 25000),
        ]);

        $first = $this->service->handle($request);
        $second = $this->service->handle($request);

        self::assertSame('completed', $first->status);
        self::assertFalse($first->repeated);
        self::assertSame(2, $first->accepted);
        self::assertTrue($second->repeated);
        self::assertSame($first->accepted, $second->accepted);
        self::assertSame(2, $this->countRows('catalog_items'));
        self::assertSame(1, $this->countRows('import_operations'));
    }

    public function testSameOperationIdWithDifferentContentIsRejected(): void
    {
        $this->service->handle($this->request('op-conflict', [$this->item('SKU-1')]));

        $this->expectException(OperationConflict::class);
        $this->service->handle($this->request('op-conflict', [$this->item('SKU-2')]));
    }

    public function testValidItemIsAppliedWhileInvalidItemIsReported(): void
    {
        $invalid = $this->item('SKU-BROKEN');
        $invalid['price_minor'] = -1;

        $report = $this->service->handle($this->request('op-partial', [
            $this->item('SKU-OK'),
            $invalid,
        ]));

        self::assertSame('completed_with_errors', $report->status);
        self::assertSame(1, $report->accepted);
        self::assertSame(1, $report->rejected);
        self::assertSame('VALIDATION_ERROR', $report->items[1]->code);
        self::assertSame(1, $this->countRows('catalog_items'));
    }

    public function testOlderAndConflictingSameVersionsAreRejected(): void
    {
        $this->service->handle($this->request('op-v2', [$this->item('SKU-1', version: 2)]));

        $older = $this->service->handle($this->request('op-v1', [$this->item('SKU-1', version: 1)]));
        $conflicting = $this->service->handle($this->request('op-v2-other', [
            $this->item('SKU-1', version: 2, priceMinor: 99900),
        ]));
        $same = $this->service->handle($this->request('op-v2-same', [$this->item('SKU-1', version: 2)]));

        self::assertSame('STALE_VERSION', $older->items[0]->code);
        self::assertSame('VERSION_CONFLICT', $conflicting->items[0]->code);
        self::assertSame('SAME_VERSION_SAME_DATA', $same->items[0]->code);
        self::assertSame('unchanged', $same->items[0]->status);
    }

    public function testCatalogChangesRollBackWhenOperationCannotBeSaved(): void
    {
        $failingOperations = new class implements OperationRepository {
            public function find(string $operationId): ?StoredOperation
            {
                return null;
            }

            public function save(string $operationId, string $requestHash, ImportReport $report): void
            {
                throw new RuntimeException('Synthetic persistence failure.');
            }
        };
        $service = new ImportCatalog(
            new PdoCatalogRepository($this->pdo),
            $failingOperations,
            new PdoTransactionManager($this->pdo),
        );

        try {
            $service->handle($this->request('op-rollback', [$this->item('SKU-ROLLBACK')]));
            self::fail('Expected a persistence failure.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic persistence failure.', $error->getMessage());
        }

        self::assertSame(0, $this->countRows('catalog_items'));
        self::assertSame(0, $this->countRows('import_operations'));
    }

    /** @param list<array<string, mixed>> $items */
    private function request(string $operationId, array $items): \Portfolio\Commerce\Application\CatalogImportRequest
    {
        return $this->parser->parse((string) json_encode([
            'operation_id' => $operationId,
            'items' => $items,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function item(
        string $externalId,
        int $version = 1,
        int $priceMinor = 10000,
    ): array {
        return [
            'external_id' => $externalId,
            'version' => $version,
            'name' => 'Demo item ' . $externalId,
            'price_minor' => $priceMinor,
            'currency' => 'RUB',
            'stock' => 5,
        ];
    }

    private function countRows(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);
        if ($statement === false) {
            throw new RuntimeException('Cannot count rows in test table.');
        }
        $value = $statement->fetchColumn();

        return (int) $value;
    }
}
