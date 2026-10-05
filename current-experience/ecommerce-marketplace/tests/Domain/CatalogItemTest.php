<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Portfolio\Commerce\Domain\CatalogItem;

final class CatalogItemTest extends TestCase
{
    public function testValidItemIsNormalized(): void
    {
        $result = CatalogItem::validate([
            'external_id' => ' SKU-1 ',
            'version' => 1,
            'name' => ' Demo item ',
            'price_minor' => 19900,
            'currency' => 'RUB',
            'stock' => 4,
        ]);

        self::assertTrue($result->isValid());
        self::assertNotNull($result->item);
        self::assertSame('SKU-1', $result->item->externalId);
        self::assertSame('Demo item', $result->item->name);
        self::assertSame([], $result->errors);
    }

    public function testInvalidFieldsReturnAllRelevantErrors(): void
    {
        $result = CatalogItem::validate([
            'external_id' => '',
            'version' => 0,
            'name' => '',
            'price_minor' => -1,
            'currency' => 'USD',
            'stock' => -2,
        ]);

        self::assertFalse($result->isValid());
        self::assertNull($result->item);
        self::assertCount(6, $result->errors);
    }

    public function testNumericStringsAreNotSilentlyAccepted(): void
    {
        $result = CatalogItem::validate([
            'external_id' => 'SKU-1',
            'version' => '1',
            'name' => 'Demo item',
            'price_minor' => '19900',
            'currency' => 'RUB',
            'stock' => '4',
        ]);

        self::assertFalse($result->isValid());
        self::assertCount(3, $result->errors);
    }
}

