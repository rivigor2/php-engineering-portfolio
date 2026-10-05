<?php
declare(strict_types=1);
namespace Portfolio\DocumentFlow\Application;
use Portfolio\DocumentFlow\Domain\DocumentPackage;

/** Produces CRM actions for review; never performs a cryptographic signature. */
final readonly class ProcessIncomingPackage
{
    public function __construct(
        private PlanIncomingPackage $planner,
        private ReconcileDocumentFlow $reconcile,
        private VerifiedAuthorityProvider $authorities,
    ) {}

    /**
     * @param array<string, array{crm_record_id: string, status: string}> $localDocuments
     * @return array{package_id: string, actions: list<array<string, string>>, ignored: list<string>, manual_review: list<string>}
     */
    public function handle(DocumentPackage $package, array $localDocuments): array
    {
        $plan = $this->planner->handle($package, $this->authorities);
        $actions = [];
        $manual = $plan->manualReview;
        $entities = [];
        foreach ($package->entities as $entity) {
            $entities[$entity['entity_id']] = $entity;
        }
        foreach ($plan->documents as $id) {
            $local = $localDocuments[$id] ?? null;
            if ($local === null || trim($local['crm_record_id']) === '') {
                $manual[] = $id . ':crm_document_link_missing';
                continue;
            }
            $transition = $this->reconcile->decide($local['status'], $entities[$id]['status']);
            if ($transition['result'] === 'manual_review') {
                $manual[] = $id . ':' . $transition['reason'];
                continue;
            }
            $actions[] = [
                'entity_id' => $id,
                'crm_record_id' => $local['crm_record_id'],
                'document_type' => $entities[$id]['type'],
                'previous_status' => $local['status'],
                'status' => $transition['status'],
                'action' => $transition['result'] === 'apply' ? 'update_crm_status' : 'no_change',
                // Eligibility is distinct from signing or claiming a signature exists.
                'signature_action' => $transition['result'] === 'apply' && $transition['status'] === 'ready_to_sign'
                    ? 'request_human_confirmation' : 'none',
                'reason' => $transition['reason'],
            ];
        }
        return [
            'package_id' => $package->packageId,
            'actions' => $actions,
            'ignored' => $plan->ignoredServiceEntities,
            'manual_review' => $manual,
        ];
    }
}
