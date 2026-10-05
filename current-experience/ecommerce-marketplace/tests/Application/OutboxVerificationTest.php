<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Tests\Application;

use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use Portfolio\Commerce\Application\AttemptLog;
use Portfolio\Commerce\Application\Clock;
use Portfolio\Commerce\Application\DeliveryDecision;
use Portfolio\Commerce\Application\DispatchOutboundMessage;
use Portfolio\Commerce\Application\HttpResponse;
use Portfolio\Commerce\Application\HttpTransport;
use Portfolio\Commerce\Application\RetryPolicy;
use Portfolio\Commerce\Domain\DeliveryState;
use Portfolio\Commerce\Domain\OutboundMessage;
use Portfolio\Commerce\Infrastructure\SQLite\ConnectionFactory;
use Portfolio\Commerce\Infrastructure\SQLite\PdoAttemptLog;
use Portfolio\Commerce\Infrastructure\SQLite\PdoOutboxRepository;
use Portfolio\Commerce\Infrastructure\SQLite\PdoTransactionManager;
use Portfolio\Commerce\Infrastructure\SQLite\Schema;
use RuntimeException;

final class OutboxVerificationTest extends TestCase
{
    public function testJsonObjectOrderDoesNotBreakIdempotency(): void
    {
        $pdo = ConnectionFactory::memory();
        (new Schema($pdo))->ensure();
        $repository = new PdoOutboxRepository($pdo);
        $now = new DateTimeImmutable('2026-10-03T10:00:00Z');
        $repository->add(OutboundMessage::pending('order-key', 'delivery-key', 'http://127.0.0.1/', [
            'order_id' => 'ORDER-1', 'amount' => 1.0, 'nested' => ['b' => 2, 'a' => 1],
        ], $now));
        $repository->add(OutboundMessage::pending('order-key', 'delivery-key', 'http://127.0.0.1/', [
            'nested' => ['a' => 1, 'b' => 2], 'amount' => 1.0, 'order_id' => 'ORDER-1',
        ], $now));
        self::assertCount(1, $repository->findByState(null));
        self::assertSame(1.0, $repository->get('order-key')?->payload['amount']);
    }

    public function testAuditFailureRollsBackDeliveryStatus(): void
    {
        $pdo = ConnectionFactory::memory();
        (new Schema($pdo))->ensure();
        $repository = new PdoOutboxRepository($pdo);
        $clock = new class implements Clock {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-03T10:00:00Z'); }
        };
        $message = OutboundMessage::pending('rollback', 'rollback-key', 'http://127.0.0.1/', ['synthetic' => true], $clock->now());
        $repository->add($message);
        $transport = new class implements HttpTransport {
            public function postJson(string $url, string $idempotencyKey, array $payload): HttpResponse
            {
                return new HttpResponse(200, '{"accepted":true}');
            }
        };
        $audit = new class implements AttemptLog {
            public function record(string $messageId, int $cycleAttempt, DeliveryDecision $decision, DateTimeImmutable $createdAt): void
            {
                throw new RuntimeException('Synthetic audit failure');
            }
        };
        $service = new DispatchOutboundMessage($repository, $audit, $transport, new RetryPolicy(), $clock, new PdoTransactionManager($pdo));
        try {
            $service->dispatch('rollback');
            self::fail('Audit failure must abort local persistence.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic audit failure', $error->getMessage());
        }
        self::assertSame(DeliveryState::Pending, $repository->get('rollback')?->state);
        self::assertSame(1, $repository->get('rollback')->version);
        self::assertSame([], (new PdoAttemptLog($pdo))->history('rollback'));
    }

    public function testStaleSaveCannotOverwriteDeliveredState(): void
    {
        $pdo = ConnectionFactory::memory();
        (new Schema($pdo))->ensure();
        $repository = new PdoOutboxRepository($pdo);
        $message = OutboundMessage::pending('race', 'race-key', 'http://127.0.0.1/', [], new DateTimeImmutable());
        $repository->add($message);
        $repository->save($message->markSent(), 1);
        try {
            $repository->save($message->markFailed(DeliveryState::FailedTemporary, 'network', 'NETWORK'), 1);
            self::fail('Stale update must fail.');
        } catch (DomainException) {
            self::assertSame(DeliveryState::Sent, $repository->get('race')?->state);
        }
    }

    public function testPermanentFailureCannotBeManuallyRetried(): void
    {
        $message = OutboundMessage::pending('permanent', 'permanent-key', 'http://127.0.0.1/', [], new DateTimeImmutable());
        $failed = $message->markFailed(DeliveryState::FailedPermanent, 'business', 'BUSINESS_REJECTED');
        $this->expectException(DomainException::class);
        $failed->manualRetry(new DateTimeImmutable());
    }

    public function testRepeatedCliEnqueueReportsPersistedSentState(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'outbox-cli-');
        self::assertIsString($database);
        $pdo = ConnectionFactory::file($database);
        (new Schema($pdo))->ensure();
        $repository = new PdoOutboxRepository($pdo);
        $example = dirname(__DIR__, 2) . '/examples/outbox-success.json';
        $message = OutboundMessage::pending('message-demo-001', 'delivery-demo-001', 'http://127.0.0.1:8099/success', [
            'order_id' => 'ORDER-DEMO-001', 'total_minor' => 129900, 'currency' => 'RUB',
        ], new DateTimeImmutable());
        $repository->add($message);
        $repository->save($message->markSent(), 1);
        unset($repository, $pdo);
        try {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/outbox.php', 'enqueue', $example, $database], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
            self::assertSame('', $stderr);
            self::assertIsString($stdout);
            $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('sent', $result['state']);
            self::assertSame(2, $result['version']);
        } finally {
            unlink($database);
        }
    }
}
