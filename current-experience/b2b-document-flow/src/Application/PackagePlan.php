<?php

declare(strict_types=1);

namespace Portfolio\DocumentFlow\Application;

final readonly class PackagePlan
{
    /**
     * @param list<string> $documents
     * @param list<string> $ignoredServiceEntities
     * @param list<string> $manualReview
     */
    public function __construct(
        public string $packageId,
        public array $documents,
        public array $ignoredServiceEntities,
        public array $manualReview,
    ) {
    }
}