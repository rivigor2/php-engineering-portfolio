<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

final readonly class ImportReport
{
    /** @param list<ItemResult> $items */
    public function __construct(
        public string $operationId,
        public string $status,
        public bool $repeated,
        public int $accepted,
        public int $rejected,
        public array $items,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'status' => $this->status,
            'repeated' => $this->repeated,
            'accepted' => $this->accepted,
            'rejected' => $this->rejected,
            'items' => array_map(
                static fn (ItemResult $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }

    /** @param array{operation_id: string, status: string, repeated: bool, accepted: int, rejected: int, items: list<array{external_id: ?string, status: string, code: string, errors?: list<string>}>} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['operation_id'],
            $data['status'],
            $data['repeated'],
            $data['accepted'],
            $data['rejected'],
            array_map(
                static fn (array $item): ItemResult => ItemResult::fromArray($item),
                $data['items'],
            ),
        );
    }

    public function asRepeated(): self
    {
        return new self(
            $this->operationId,
            $this->status,
            true,
            $this->accepted,
            $this->rejected,
            $this->items,
        );
    }

    public function hasRejectedItems(): bool
    {
        return $this->rejected > 0;
    }
}

