<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use Portfolio\Commerce\Domain\CatalogItem;

final readonly class ImportCatalog
{
    public function __construct(
        private CatalogRepository $catalog,
        private OperationRepository $operations,
        private TransactionManager $transactions,
    ) {
    }

    public function handle(CatalogImportRequest $request): ImportReport
    {
        return $this->transactions->run(function () use ($request): ImportReport {
            $stored = $this->operations->find($request->operationId);
            if ($stored !== null) {
                if (!hash_equals($stored->requestHash, $request->requestHash)) {
                    throw new OperationConflict($request->operationId);
                }

                return $stored->report->asRepeated();
            }

            $results = [];
            $accepted = 0;
            $rejected = 0;

            foreach ($request->items as $candidate) {
                $validation = CatalogItem::validate($candidate);
                if (!$validation->isValid()) {
                    $externalId = is_string($candidate['external_id'] ?? null)
                        ? $candidate['external_id']
                        : null;
                    $results[] = new ItemResult(
                        $externalId,
                        'rejected',
                        'VALIDATION_ERROR',
                        $validation->errors,
                    );
                    ++$rejected;
                    continue;
                }

                $item = $validation->item;
                if ($item === null) {
                    throw new \LogicException('Valid result must contain an item.');
                }

                $current = $this->catalog->find($item->externalId);
                if ($current !== null && $item->version < $current->version) {
                    $results[] = new ItemResult(
                        $item->externalId,
                        'rejected',
                        'STALE_VERSION',
                        [sprintf('Current version is %d.', $current->version)],
                    );
                    ++$rejected;
                    continue;
                }

                if ($current !== null && $item->version === $current->version) {
                    if ($item->sameDataAs($current)) {
                        $results[] = new ItemResult($item->externalId, 'unchanged', 'SAME_VERSION_SAME_DATA');
                        ++$accepted;
                    } else {
                        $results[] = new ItemResult(
                            $item->externalId,
                            'rejected',
                            'VERSION_CONFLICT',
                            ['The same version already exists with different data.'],
                        );
                        ++$rejected;
                    }
                    continue;
                }

                $this->catalog->save($item);
                $results[] = new ItemResult($item->externalId, 'applied', 'APPLIED');
                ++$accepted;
            }

            $status = match (true) {
                $rejected === 0 => 'completed',
                $accepted === 0 => 'rejected',
                default => 'completed_with_errors',
            };

            $report = new ImportReport(
                $request->operationId,
                $status,
                false,
                $accepted,
                $rejected,
                $results,
            );
            $this->operations->save($request->operationId, $request->requestHash, $report);

            return $report;
        });
    }
}
