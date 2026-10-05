<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use DateInterval;
use DomainException;
use Portfolio\Commerce\Domain\DeliveryState;
use Portfolio\Commerce\Domain\OutboundMessage;

final readonly class DispatchOutboundMessage
{
    public function __construct(
        private OutboxRepository $repository,
        private AttemptLog $attemptLog,
        private HttpTransport $transport,
        private RetryPolicy $retryPolicy,
        private Clock $clock,
        private TransactionManager $transactions,
    ) {
    }

    public function dispatch(string $messageId): OutboundMessage
    {
        $message = $this->repository->get($messageId);
        if ($message === null) {
            throw new DomainException('Outbound message was not found.');
        }

        $now = $this->clock->now();
        if (!$message->isReady($now)) {
            throw new DomainException('Outbound message is not ready for delivery.');
        }

        $attempt = $message->attempts + 1;
        try {
            $response = $this->transport->postJson(
                $message->targetUrl,
                $message->idempotencyKey,
                $message->payload,
            );
            $decision = $this->retryPolicy->forResponse($response, $attempt);
        } catch (TransportFailure $failure) {
            $decision = $this->retryPolicy->forTransportFailure($failure, $attempt);
        }

        // Backoff starts when the failed attempt ends, even if the transport was slow.
        $now = $this->clock->now();

        $updated = match ($decision->action) {
            DeliveryAction::Sent => $message->markSent(),
            DeliveryAction::Retry => $message->scheduleRetry(
                $now->add(new DateInterval('PT' . $decision->delaySeconds . 'S')),
                $decision->failureKind,
                $decision->errorCode,
            ),
            DeliveryAction::FailedTemporary => $message->markFailed(
                DeliveryState::FailedTemporary,
                $decision->failureKind,
                $decision->errorCode,
            ),
            DeliveryAction::FailedPermanent => $message->markFailed(
                DeliveryState::FailedPermanent,
                $decision->failureKind,
                $decision->errorCode,
            ),
        };

        $this->transactions->run(function () use ($updated, $message, $messageId, $attempt, $decision, $now): void {
            $this->repository->save($updated, $message->version);
            $this->attemptLog->record($messageId, $attempt, $decision, $now);
        });

        return $updated;
    }

    public function retryManually(string $messageId): OutboundMessage
    {
        $message = $this->repository->get($messageId);
        if ($message === null) {
            throw new DomainException('Outbound message was not found.');
        }

        $updated = $message->manualRetry($this->clock->now());
        $this->transactions->run(function () use ($updated, $message): void {
            $this->repository->save($updated, $message->version);
        });

        return $updated;
    }
}
