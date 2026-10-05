<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Infrastructure\Json;

use JsonException;
use Portfolio\Commerce\Application\CatalogImportRequest;

final class CatalogImportRequestParser
{
    public function parse(string $json): CatalogImportRequest
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RequestValidationException(['Invalid JSON: ' . $error->getMessage()]);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RequestValidationException(['Request root must be a JSON object.']);
        }

        $unknownRootFields = array_diff(array_keys($decoded), ['operation_id', 'items']);
        if ($unknownRootFields !== []) {
            throw new RequestValidationException([
                'Unknown request fields: ' . implode(', ', $unknownRootFields) . '.',
            ]);
        }

        $operationId = $decoded['operation_id'] ?? null;
        $items = $decoded['items'] ?? null;
        $errors = [];

        if (!is_string($operationId) || trim($operationId) === '') {
            $errors[] = 'operation_id must be a non-empty string.';
        } elseif (strlen($operationId) > 100) {
            $errors[] = 'operation_id must not exceed 100 characters.';
        }

        if (!is_array($items) || !array_is_list($items) || $items === []) {
            $errors[] = 'items must be a non-empty JSON array.';
        }

        if ($errors !== []) {
            throw new RequestValidationException($errors);
        }

        /** @var list<mixed> $items */
        $normalizedItems = [];
        $seenIds = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                $errors[] = sprintf('items[%d] must be a JSON object.', $index);
                continue;
            }

            /** @var array<string, mixed> $item */
            $unknownItemFields = array_diff(array_keys($item), [
                'external_id',
                'version',
                'name',
                'price_minor',
                'currency',
                'stock',
            ]);
            if ($unknownItemFields !== []) {
                $errors[] = sprintf(
                    'Unknown fields in items[%d]: %s.',
                    $index,
                    implode(', ', $unknownItemFields),
                );
            }
            $normalizedItems[] = $item;
            $externalId = $item['external_id'] ?? null;
            if (is_string($externalId) && trim($externalId) !== '') {
                $key = trim($externalId);
                if (isset($seenIds[$key])) {
                    $errors[] = sprintf('Duplicate external_id "%s" in request.', $key);
                }
                $seenIds[$key] = true;
            }
        }

        if ($errors !== []) {
            throw new RequestValidationException($errors);
        }

        /** @var string $operationId */
        $canonical = [
            'operation_id' => trim($operationId),
            'items' => $normalizedItems,
        ];
        $this->sortObjectKeys($canonical);
        $canonicalJson = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return new CatalogImportRequest(
            trim($operationId),
            $normalizedItems,
            hash('sha256', $canonicalJson),
        );
    }

    /** @param array<mixed> $value */
    private function sortObjectKeys(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortObjectKeys($child);
            }
        }
        unset($child);

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
    }
}

