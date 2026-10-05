<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Domain;

final readonly class CatalogItem
{
    public function __construct(
        public string $externalId,
        public int $version,
        public string $name,
        public int $priceMinor,
        public string $currency,
        public int $stock,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function validate(array $data): ItemValidationResult
    {
        $errors = [];

        $externalId = $data['external_id'] ?? null;
        if (!is_string($externalId) || trim($externalId) === '') {
            $errors[] = 'external_id must be a non-empty string';
        } elseif (strlen($externalId) > 100) {
            $errors[] = 'external_id must not exceed 100 characters';
        }

        $version = $data['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            $errors[] = 'version must be an integer greater than or equal to 1';
        }

        $name = $data['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            $errors[] = 'name must be a non-empty string';
        } elseif (strlen($name) > 255) {
            $errors[] = 'name must not exceed 255 characters';
        }

        $priceMinor = $data['price_minor'] ?? null;
        if (!is_int($priceMinor) || $priceMinor < 0) {
            $errors[] = 'price_minor must be a non-negative integer';
        }

        $currency = $data['currency'] ?? null;
        if ($currency !== 'RUB') {
            $errors[] = 'currency must be RUB';
        }

        $stock = $data['stock'] ?? null;
        if (!is_int($stock) || $stock < 0) {
            $errors[] = 'stock must be a non-negative integer';
        }

        if ($errors !== []) {
            return ItemValidationResult::invalid($errors);
        }

        /** @var string $externalId */
        /** @var int $version */
        /** @var string $name */
        /** @var int $priceMinor */
        /** @var string $currency */
        /** @var int $stock */
        return ItemValidationResult::valid(new self(
            trim($externalId),
            $version,
            trim($name),
            $priceMinor,
            $currency,
            $stock,
        ));
    }

    public function sameDataAs(self $other): bool
    {
        return $this->externalId === $other->externalId
            && $this->version === $other->version
            && $this->name === $other->name
            && $this->priceMinor === $other->priceMinor
            && $this->currency === $other->currency
            && $this->stock === $other->stock;
    }
}

