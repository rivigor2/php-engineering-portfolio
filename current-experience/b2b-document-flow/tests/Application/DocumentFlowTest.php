<?php

declare(strict_types=1);

namespace Portfolio\DocumentFlow\Tests\Application;

use PHPUnit\Framework\TestCase;
use Portfolio\DocumentFlow\Application\PlanIncomingPackage;
use Portfolio\DocumentFlow\Application\ReconcileDocumentFlow;
use Portfolio\DocumentFlow\Domain\Authority;
use Portfolio\DocumentFlow\Domain\DocumentPackage;

final class DocumentFlowTest extends TestCase
{
    public function testServiceEntitiesAreFilteredAndAuthorityIsChecked(): void
    {
        $package = new DocumentPackage('package-1', 'org-1', [
            ['entity_id' => 'service-1', 'type' => 'delivery_receipt', 'direction' => 'incoming', 'status' => 'received'],
            ['entity_id' => 'document-1', 'type' => 'acceptance_act', 'direction' => 'incoming', 'status' => 'received'],
        ]);
        $authority = new Authority('employee-1', 'org-1', 'employee-1', 'valid',
            certificateVerified: true,
            delegationBoundToSignerAndOrganization: true,
            documentActionWithinGrantedScope: true,
        );

        $plan = (new PlanIncomingPackage())->handle($package, $authority);

        self::assertSame(['document-1'], $plan->documents);
        self::assertSame(['service-1'], $plan->ignoredServiceEntities);
        self::assertSame([], $plan->manualReview);
    }

    public function testUnconfirmedAuthorityAndStatusGapGoToManualReview(): void
    {
        $package = new DocumentPackage('package-2', 'org-1', [
            ['entity_id' => 'document-2', 'type' => 'transfer_document', 'direction' => 'incoming', 'status' => 'received'],
        ]);
        $authority = new Authority('employee-1', 'org-1', 'employee-1', 'expired',
            certificateVerified: true,
            delegationBoundToSignerAndOrganization: true,
            documentActionWithinGrantedScope: true,
        );

        $plan = (new PlanIncomingPackage())->handle($package, $authority);
        $sync = (new ReconcileDocumentFlow())->decide('received', 'completed');

        self::assertSame(['document-2:authority_not_confirmed'], $plan->manualReview);
        self::assertSame('manual_review', $sync['result']);
    }
}
