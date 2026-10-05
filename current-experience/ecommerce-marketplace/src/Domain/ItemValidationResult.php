<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Domain;

final readonly class ItemValidationResult
{
    /** @param list<string> $errors */
    private function __construct(
        public ?CatalogItem $item,
        public array $errors,
    ) {
    }

    public static function valid(CatalogItem $item): self
    {
        return new self($item, []);
    }

    /** @param list<string> $errors */
    public static function invalid(array $errors): self
    {
        return new self(null, $errors);
    }

    public function isValid(): bool
    {
        return $this->item !== null;
    }
}

