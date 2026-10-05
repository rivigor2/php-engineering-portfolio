<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

final readonly class ItemResult
{
    /** @param list<string> $errors */
    public function __construct(
        public ?string $externalId,
        public string $status,
        public string $code,
        public array $errors = [],
    ) {
    }

    /** @return array{external_id: ?string, status: string, code: string, errors: list<string>} */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'status' => $this->status,
            'code' => $this->code,
            'errors' => $this->errors,
        ];
    }

    /** @param array{external_id: ?string, status: string, code: string, errors?: list<string>} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['external_id'],
            $data['status'],
            $data['code'],
            $data['errors'] ?? [],
        );
    }
}

