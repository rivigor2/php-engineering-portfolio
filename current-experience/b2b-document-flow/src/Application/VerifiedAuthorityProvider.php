<?php
declare(strict_types=1);
namespace Portfolio\DocumentFlow\Application;
use Portfolio\DocumentFlow\Domain\Authority;
use Portfolio\DocumentFlow\Domain\DocumentPackage;

/** Server adapter: verified identity, mandate and scope for this specific document. */
interface VerifiedAuthorityProvider
{
    /** @param array{entity_id: string, type: string, direction: string, status: string} $entity */
    public function forDocument(DocumentPackage $package, array $entity): Authority;
}
