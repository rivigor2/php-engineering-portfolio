<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Tests\Application;

use DateInterval;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use Portfolio\Commerce\Application\Clock;
use Portfolio\Commerce\Application\DispatchOutboundMessage;
use Portfolio\Commerce\Application\HttpResponse;
use Portfolio\Commerce\Application\HttpTransport;
use Portfolio\Commerce\Application\RetryPolicy;
use Portfolio\Commerce\Application\SystemClock;
use Portfolio\Commerce\Domain\DeliveryState;
use Portfolio\Commerce\Domain\OutboundMessage;
use Portfolio\Commerce\Infrastructure\SQLite\ConnectionFactory;
use Portfolio\Commerce\Infrastructure\SQLite\PdoAttemptLog;
use Portfolio\Commerce\Infrastructure\SQLite\PdoOutboxRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoTransactionManager;
use Portfolio\Commerce\Infrastructure\SQLite\Schema;

final class DispatchOutboundMessageIntegrationTest extends TestCase
{
    public function testTemporaryFailuresAreRetriedWithoutSleeping(): void
    {
        $pdo = ConnectionFactory::memory();
        (new Schema($pdo))->ensure();
        $repository = new PdoOutboxRepository($pdo);
        $log = new PdoAttemptLog($pdo);
        $clock = new MutableClock(new DateTimeImmutable('2026-10-03T10:00:00+00:00'));
        $transport = new SequenceTransport([
            new HttpResponse(503, '{}'),
            new HttpResponse(429, '{}'),
            new HttpResponse(202, '{"accepted":true}'),
        ]);
        $service = new DispatchOutboundMessage(
            $repository,
            $log,
            $transport,
            new RetryPolicy(),
            $clock,
            new PdoTransactionManager($pdo),
        );
        $repository->add(OutboundMessage::pending(
            'message-001',
            'delivery-key-001',
            'http://127.0.0.1:8099/server-error',
            ['order_id' => 'ORDER-001'],
            $clock->now(),
        ));

        $first = $service->dispatch('message-001');
        self::assertSame(DeliveryState::RetryScheduled, $first->state);
        self::assertSame('2026-10-03T10:00:05+00:00', $first->availableAt->format(DATE_ATOM));

        $this->expectNotReady($service);
        $clock->advance(5);
        $second = $service->dispatch('message-001');
        self::assertSame(DeliveryState::RetryScheduled, $second->state);
        self::assertSame('2026-10-03T10:00:35+00:00', $second->availableAt->format(DATE_ATOM));

        $clock->advance(30);
        $third = $service->dispatch('message-001');
        self::assertSame(DeliveryState::Sent, $third->state);
        self::assertCount(3, $log->history('message-001'));
        self::assertSame(3, $transport->calls);
    }

    public function testManualRetryIsLimitedToExhaustedTemporaryFailure(): void
    {
        $pdo = ConnectionFactory::memory();
        (new Schema($pdo))->ensure();
        $repository = new PdoOutboxRepository($pdo);
        $log = new PdoAttemptLog($pdo);
        $clock = new MutableClock(new DateTimeImmutable('2026-10-03T10:00:00+00:00'));
        $service = new DispatchOutboundMessage(
            $repository,
            $log,
            new SequenceTransport([
                new HttpResponse(503, '{}'),
                new HttpResponse(503, '{}'),
                new HttpResponse(503, '{}'),
            ]),
            new RetryPolicy(),
            $clock,
            new PdoTransactionManager($pdo),
        );
        $repository->add(OutboundMessage::pending(
            'message-002',
            'delivery-key-002',
            'http://127.0.0.1:8099/server-error',
            ['order_id' => 'ORDER-002'],
            $clock->now(),
        ));

        $service->dispatch('message-002');
        $clock->advance(5);
        $service->dispatch('message-002');
        $clock->advance(30);
        $failed = $service->dispatch('message-002');
        self::assertSame(DeliveryState::FailedTemporary, $failed->state);

        $retried = $service->retryManually('message-002');
        self::assertSame(DeliveryState::RetryScheduled, $retried->state);
        self::assertSame(0, $retried->attempts);
        self::assertCount(3, $log->history('message-002'));
    }

    public function testSameMessageIsIdempotentButDifferentContentConflicts(): void
    {
        $pdo = ConnectionFactory::memory();
        (new Schema($pdo))->ensure();
        $repository = new PdoOutboxRepository($pdo);
        $message = OutboundMessage::pending(
            'message-003',
            'delivery-key-003',
            'http://127.0.0.1:8099/success',
            ['order_id' => 'ORDER-003'],
            (new SystemClock())->now(),
        );

        $repository->add($message);
        $repository->add($message);
        self::assertNotNull($repository->get('message-003'));
        self::assertCount(1, $repository->findByState(null));
        self::assertCount(1, $repository->findByState(DeliveryState::Pending));
        self::assertSame([], $repository->findByState(DeliveryState::Sent));

        $this->expectException(DomainException::class);
        $repository->add(OutboundMessage::pending(
            'message-003',
            'delivery-key-different',
            'http://127.0.0.1:8099/success',
            ['order_id' => 'ORDER-003'],
            (new SystemClock())->now(),
        ));
    }

    private function expectNotReady(DispatchOutboundMessage $service): void
    {
        try {
            $service->dispatch('message-001');
            self::fail('Dispatch before available_at must fail.');
        } catch (DomainException $exception) {
            self::assertSame('Outbound message is not ready for delivery.', $exception->getMessage());
        }
    }
}

final class MutableClock implements Clock
{
    public function __construct(private DateTimeImmutable $current)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(int $seconds): void
    {
        $this->current = $this->current->add(new DateInterval('PT' . $seconds . 'S'));
    }
}

final class SequenceTransport implements HttpTransport
{
    public int $calls = 0;

    /** @param non-empty-list<HttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function postJson(string $url, string $idempotencyKey, array $payload): HttpResponse
    {
        $response = $this->responses[min($this->calls, count($this->responses) - 1)];
        $this->calls++;

        return $response;
    }
}
