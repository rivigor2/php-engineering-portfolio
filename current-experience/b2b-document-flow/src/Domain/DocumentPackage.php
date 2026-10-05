<?php

declare(strict_types=1);

namespace Portfolio\DocumentFlow\Domain;

use InvalidArgumentException;

final readonly class DocumentPackage
{
    /** @param list<array{entity_id: string, type: string, direction: string, status: string, fields?: array<string, mixed>}> $entities */
    public function __construct(
        public string $packageId,
        public string $organizationId,
        public array $entities,
    ) {
        if (trim($packageId) === '' || trim($organizationId) === '' || $entities === [] || !array_is_list($entities)) {
            throw new InvalidArgumentException('Package, organization and a non-empty entity list are required.');
        }
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                throw new InvalidArgumentException('Entity must be an object.');
            }
            foreach (['entity_id', 'type', 'direction', 'status'] as $field) {
                if (!isset($entity[$field]) || !is_string($entity[$field]) || trim($entity[$field]) === '') {
                    throw new InvalidArgumentException('Entity identity, type, direction and status are required.');
                }
            }
            if (array_key_exists('fields', $entity) && !is_array($entity['fields'])) {
                throw new InvalidArgumentException('Document fields must be an object.');
            }
        }
    }
}
