<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Tests\Infrastructure\Json;

use PHPUnit\Framework\TestCase;
use Portfolio\Commerce\Infrastructure\Json\CatalogImportRequestParser;
use Portfolio\Commerce\Infrastructure\Json\RequestValidationException;

final class CatalogImportRequestParserTest extends TestCase
{
    private CatalogImportRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CatalogImportRequestParser();
    }

    public function testWhitespaceAndObjectKeyOrderDoNotChangeHash(): void
    {
        $first = $this->parser->parse(<<<'JSON'
            {"operation_id":"op-1","items":[{"external_id":"SKU-1","version":1,"name":"Item","price_minor":100,"currency":"RUB","stock":2}]}
            JSON);
        $second = $this->parser->parse(<<<'JSON'
            {
              "items": [{"stock":2,"currency":"RUB","price_minor":100,"name":"Item","version":1,"external_id":"SKU-1"}],
              "operation_id": "op-1"
            }
            JSON);

        self::assertSame($first->requestHash, $second->requestHash);
    }

    public function testDuplicateExternalIdRejectsWholeRequest(): void
    {
        $this->expectException(RequestValidationException::class);
        $this->expectExceptionMessage('Duplicate external_id');

        $this->parser->parse(<<<'JSON'
            {
              "operation_id": "op-duplicate",
              "items": [
                {"external_id":"SKU-1"},
                {"external_id":"SKU-1"}
              ]
            }
            JSON);
    }

    public function testMalformedJsonIsReportedAsRequestError(): void
    {
        $this->expectException(RequestValidationException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->parser->parse('{');
    }

    public function testUnknownFieldsAreRejectedInsteadOfIgnored(): void
    {
        $this->expectException(RequestValidationException::class);
        $this->expectExceptionMessage('Unknown fields in items[0]: price.');

        $this->parser->parse(<<<'JSON'
            {
              "operation_id": "op-unknown",
              "items": [{"external_id":"SKU-1","price":100}]
            }
            JSON);
    }
}

