<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Domain;

use InvalidArgumentException;

final class CompositeAvailability
{
    /**
     * @param list<array{component_id: string, available: int, required: int}> $components
     */
    public function calculate(array $components): int
    {
        if ($components === []) {
            throw new InvalidArgumentException('Composite product must contain components.');
        }

        $result = PHP_INT_MAX;
        $seen = [];

        foreach ($components as $component) {
            $id = trim($component['component_id']);
            $available = $component['available'];
            $required = $component['required'];

            if ($id === '' || isset($seen[$id])) {
                throw new InvalidArgumentException('Component identifiers must be non-empty and unique.');
            }
            if ($available < 0 || $required < 1) {
                throw new InvalidArgumentException('Stock must be non-negative and required quantity must be positive.');
            }

            $seen[$id] = true;
            $result = min($result, intdiv($available, $required));
        }

        return $result;
    }
}