<?php

declare(strict_types=1);

namespace Portfolio\DocumentFlow\Application;

use DomainException;
use InvalidArgumentException;
use Portfolio\DocumentFlow\Domain\DocumentPackage;

/** Applies only to the local CRM projection; never calls signing APIs. */
final readonly class ApplyIncomingPackage
{
    public function __construct(
        private ProcessIncomingPackage $processor,
        private MapActToCrmFields $mapper,
        private DocumentFlowStore $store,
    ) {}

    /** @return array<string, mixed> */
    public function handle(string $deliveryId, DocumentPackage $package): array
    {
        if (trim($deliveryId) === '') {
            throw new InvalidArgumentException('Delivery identity is required.');
        }
        $requestHash = hash('sha256', json_encode($this->canonicalize([
            'package_id' => $package->packageId,
            'organization_id' => $package->organizationId,
            'entities' => $package->entities,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return $this->store->transaction(function () use ($deliveryId, $package, $requestHash): array {
            $receipt = $this->store->receipt($package->organizationId, $deliveryId);
            if ($receipt !== null) {
                if (!hash_equals($receipt['request_hash'], $requestHash)) {
                    throw new DomainException('Delivery ID already has another package payload.');
                }
                // Preserve a previous manual decision; a retry is not an approval or another write.
                $outcome = $receipt['outcome'];
                $outcome['duplicate_delivery'] = true;
                $outcome['changes'] = [];
                return $outcome;
            }

            $localDocuments = [];
            $entities = [];
            foreach ($package->entities as $entity) {
                $id = $entity['entity_id'];
                $entities[$id] = $entity;
                $document = $this->store->document($package->organizationId, $id);
                if ($document !== null) {
                    $localDocuments[$id] = $document;
                }
            }
            $plan = $this->processor->handle($package, $localDocuments);
            $changes = [];
            $manual = $plan['manual_review'];
            foreach ($plan['actions'] as $action) {
                $id = $action['entity_id'];
                if (!in_array($action['document_type'], ['acceptance_act', 'reconciliation_act'], true)) {
                    $manual[] = $id . ':autofill_type_not_supported';
                    continue;
                }
                try {
                    $fields = $entities[$id]['fields'] ?? null;
                    if (!is_array($fields)) {
                        throw new InvalidArgumentException('Act fields are required.');
                    }
                    $mapped = $this->mapper->map($fields);
                } catch (InvalidArgumentException $error) {
                    $manual[] = $id . ':invalid_act_fields';
                    continue;
                }
                $local = $localDocuments[$id];
                // Corrections to an already populated act need a separate reviewed revision.
                if ($local['fields'] !== [] && $local['fields'] !== $mapped) {
                    $manual[] = $id . ':document_fields_changed_requires_review';
                    continue;
                }
                if ($local['status'] === $action['status'] && $local['fields'] === $mapped) {
                    continue;
                }
                $this->store->saveDocument(
                    $package->organizationId, $id, $local['version'], $action['status'], $mapped,
                );
                $changes[] = [
                    'entity_id' => $id,
                    'crm_record_id' => $local['crm_record_id'],
                    'version' => $local['version'] + 1,
                    'status' => $action['status'],
                    'fields' => $mapped,
                    'signature_action' => $action['signature_action'],
                ];
            }
            $outcome = [
                'delivery_id' => $deliveryId,
                'package_id' => $package->packageId,
                'result' => $manual !== [] ? 'manual_review' : ($changes !== [] ? 'applied' : 'unchanged'),
                'duplicate_delivery' => false,
                'changes' => $changes,
                'ignored' => $plan['ignored'],
                'manual_review' => array_values(array_unique($manual)),
            ];
            // Changes and receipt share one transaction; failures roll back this whole local step.
            $this->store->saveReceipt($package->organizationId, $deliveryId, $requestHash, $outcome);
            return $outcome;
        });
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Only JSON values are accepted.');
            }
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            $item = $this->canonicalize($item);
        }
        unset($item);
        return $value;
    }
}
