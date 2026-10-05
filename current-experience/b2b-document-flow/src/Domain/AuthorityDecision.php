<?php

declare(strict_types=1);

namespace Portfolio\DocumentFlow\Domain;

final readonly class AuthorityDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
    ) {
    }
}