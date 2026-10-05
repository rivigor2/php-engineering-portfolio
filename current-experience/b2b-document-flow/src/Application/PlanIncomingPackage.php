<?php

declare(strict_types=1);

namespace Portfolio\DocumentFlow\Application;

use Portfolio\DocumentFlow\Domain\Authority;
use Portfolio\DocumentFlow\Domain\DocumentPackage;

final class PlanIncomingPackage
{
    /** @var list<string> */
    private const SERVICE_TYPES = ['delivery_receipt', 'operator_confirmation', 'signature_notice'];

    /** @var list<string> */
    private const BUSINESS_TYPES = ['acceptance_act', 'reconciliation_act', 'transfer_document'];

    public function handle(DocumentPackage $package, Authority|VerifiedAuthorityProvider $authority): PackagePlan
    {
        $documents = [];
        $ignored = [];
        $manual = [];
        $counts = array_count_values(array_column($package->entities, 'entity_id'));

        foreach ($package->entities as $entity) {
            $id = $entity['entity_id'];
            if ($counts[$id] > 1) {
                $manual[] = $id . ':duplicate_entity';
                continue;
            }


            if (in_array($entity['type'], self::SERVICE_TYPES, true)) {
                $ignored[] = $id;
                continue;
            }
            if (!in_array($entity['type'], self::BUSINESS_TYPES, true)) {
                $manual[] = $id . ':unknown_document_type';
                continue;
            }
            if ($entity['direction'] !== 'incoming') {
                $manual[] = $id . ':unexpected_direction';
                continue;
            }

            $verified = $authority instanceof VerifiedAuthorityProvider ? $authority->forDocument($package, $entity) : $authority;
            $decision = $verified->signingDecision($package->organizationId);
            if (!$decision->allowed) {
                $manual[] = $id . ':' . $decision->reason;
                continue;
            }

            $documents[] = $id;
        }

        return new PackagePlan($package->packageId, $documents, $ignored, array_values(array_unique($manual)));
    }
}