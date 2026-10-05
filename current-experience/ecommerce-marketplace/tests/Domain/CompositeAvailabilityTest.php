<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Tests\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Portfolio\Commerce\Domain\CompositeAvailability;

final class CompositeAvailabilityTest extends TestCase
{
    public function testLimitingComponentDefinesAvailableBundles(): void
    {
        $available = (new CompositeAvailability())->calculate([
            ['component_id' => 'flower-a', 'available' => 19, 'required' => 3],
            ['component_id' => 'flower-b', 'available' => 8, 'required' => 2],
            ['component_id' => 'package', 'available' => 20, 'required' => 1],
        ]);

        self::assertSame(4, $available);
    }

    public function testInvalidBillOfMaterialsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CompositeAvailability())->calculate([
            ['component_id' => 'flower-a', 'available' => 10, 'required' => 0],
        ]);
    }
}