<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/autoload.php';

use Portfolio\DocumentFlow\Application\ApplyIncomingPackage;
use Portfolio\DocumentFlow\Application\MapActToCrmFields;
use Portfolio\DocumentFlow\Application\PlanIncomingPackage;
use Portfolio\DocumentFlow\Application\ProcessIncomingPackage;
use Portfolio\DocumentFlow\Application\ReconcileDocumentFlow;
use Portfolio\DocumentFlow\Application\VerifiedAuthorityProvider;
use Portfolio\DocumentFlow\Domain\Authority;
use Portfolio\DocumentFlow\Domain\DocumentPackage;
use Portfolio\DocumentFlow\Infrastructure\SQLite\PdoDocumentFlowStore;

try {
    $paths = array_slice($argv, 1);
    if ($paths === []) {
        $paths = [
            __DIR__ . '/../examples/incoming-package.json',
            __DIR__ . '/../examples/authority-refused.json',
            __DIR__ . '/../examples/status-gap.json',
        ];
    }
    foreach ($paths as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Cannot read synthetic scenario.');
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $package = new DocumentPackage($data['package_id'], $data['organization_id'], $data['entities']);

        // Fixture-only provider. User-submitted booleans are not production authority evidence.
        $provider = new class($data['authority']) implements VerifiedAuthorityProvider {
            public function __construct(private readonly array $claims) {}
            public function forDocument(DocumentPackage $package, array $entity): Authority
            {
                $c = $this->claims;
                if (!in_array($entity['entity_id'], $c['verified_document_ids'] ?? [], true)) {
                    return new Authority('', $package->organizationId, '', null);
                }
                return new Authority(
                    $c['employee_id'], $c['organization_id'], $c['certificate_owner_id'],
                    $c['power_of_attorney_status'] ?? null,
                    ($c['certificate_verified'] ?? false) === true,
                    ($c['direct_representation_verified'] ?? false) === true,
                    ($c['delegation_bound_to_signer_and_organization'] ?? false) === true,
                    ($c['document_action_within_granted_scope'] ?? false) === true,
                );
            }
        };
        // Separate local CRM projection per scenario; these links are server fixture data.
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $store = new PdoDocumentFlowStore($pdo);
        $seed = $pdo->prepare('INSERT INTO crm_documents VALUES (:org, :entity, :crm, :status, 1, :fields)');
        foreach ($data['local_documents'] ?? [] as $id => $record) {
            $seed->execute([
                'org' => $package->organizationId, 'entity' => $id,
                'crm' => $record['crm_record_id'], 'status' => $record['status'],
                'fields' => json_encode($record['fields'] ?? [], JSON_THROW_ON_ERROR),
            ]);
        }
        $processor = new ProcessIncomingPackage(new PlanIncomingPackage(), new ReconcileDocumentFlow(), $provider);
        $application = new ApplyIncomingPackage($processor, new MapActToCrmFields(), $store);
        $deliveryId = $data['delivery_id'];
        $first = $application->handle($deliveryId, $package);
        $repeat = $application->handle($deliveryId, $package);
        $stored = [];
        foreach ($data['local_documents'] ?? [] as $id => $record) {
            $stored[$id] = $store->document($package->organizationId, $id);
        }
        echo json_encode([
            'scenario' => basename($path),
            'first_delivery' => $first,
            'repeat_delivery' => $repeat,
            'crm_projection' => $stored,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
