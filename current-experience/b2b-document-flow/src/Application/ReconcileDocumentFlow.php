<?php

declare(strict_types=1);

namespace Portfolio\DocumentFlow\Application;

final class ReconcileDocumentFlow
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'received' => ['ready_to_sign', 'rejected'],
        'ready_to_sign' => ['signed', 'rejected'],
        'signed' => ['completed', 'rejected'],
        'completed' => [],
        'rejected' => [],
    ];

    /** @return array{result: string, status: string, reason: string} */
    public function decide(string $localStatus, string $remoteStatus): array
    {
        if (!array_key_exists($localStatus, self::ALLOWED)) {
            return ['result' => 'manual_review', 'status' => $localStatus, 'reason' => 'unknown_local_status'];
        }
        if ($localStatus === $remoteStatus) {
            return ['result' => 'unchanged', 'status' => $localStatus, 'reason' => 'already_synchronized'];
        }
        if (!in_array($remoteStatus, self::ALLOWED[$localStatus], true)) {
            return ['result' => 'manual_review', 'status' => $localStatus, 'reason' => 'status_regression_or_gap'];
        }

        return ['result' => 'apply', 'status' => $remoteStatus, 'reason' => 'remote_transition_confirmed'];
    }
}