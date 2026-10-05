<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Tests\Infrastructure\Http;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Portfolio\Commerce\Domain\DeliveryState;
use Portfolio\Commerce\Domain\OutboundMessage;
use Portfolio\Commerce\Infrastructure\SQLite\ConnectionFactory;
use Portfolio\Commerce\Infrastructure\SQLite\PdoOutboxRepository;
use Portfolio\Commerce\Infrastructure\SQLite\Schema;
use Portfolio\Commerce\Tests\Support\LocalPhpServer;

final class OperatorViewTest extends TestCase
{
    private LocalPhpServer $server;
    private string $database;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'portfolio-ui-');
        self::assertIsString($path);
        $this->database = $path;
        $pdo = ConnectionFactory::file($path);
        (new Schema($pdo))->ensure();
        $repository = new PdoOutboxRepository($pdo);
        foreach (['temporary' => DeliveryState::FailedTemporary, 'permanent' => DeliveryState::FailedPermanent] as $id => $state) {
            $message = OutboundMessage::pending($id, $id . '-key', 'http://127.0.0.1/', ['private_payload' => 'SHOULD_NOT_APPEAR'], new DateTimeImmutable());
            $repository->add($message);
            $repository->save($message->markFailed($state, 'http', '<script>alert(1)</script>'), 1);
        }
        unset($repository, $pdo);
        $this->server = new LocalPhpServer(dirname(__DIR__, 3) . '/public', environment: ['COMMERCE_DB_PATH' => $path]);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        unlink($this->database);
    }

    public function testPostRequiresCsrfAndManualRetryWorksWithoutJavascript(): void
    {
        $page = $this->request('/?state=failed_temporary');
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('SHOULD_NOT_APPEAR', $page['body']);
        self::assertStringNotContainsString('<script>alert(1)', $page['body']);
        self::assertStringContainsString('&lt;script&gt;', $page['body']);
        if (preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page['body'], $token) !== 1
            || preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', implode("\n", $page['headers']), $cookie) !== 1) {
            self::fail('Expected a CSRF token and session cookie.');
        }

        $invalid = $this->request('/', 'POST', ['action' => 'retry', 'message_id' => 'temporary'], ['Cookie: ' . $cookie[1]]);
        self::assertSame(403, $invalid['status']);
        self::assertSame(DeliveryState::FailedTemporary, $this->state('temporary'));

        $valid = $this->request('/', 'POST', ['action' => 'retry', 'message_id' => 'temporary', 'csrf_token' => $token[1]], ['Cookie: ' . $cookie[1]]);
        self::assertSame(303, $valid['status']);
        self::assertSame(DeliveryState::RetryScheduled, $this->state('temporary'));

        $this->request('/', 'POST', ['action' => 'retry', 'message_id' => 'permanent', 'csrf_token' => $token[1]], ['Cookie: ' . $cookie[1]]);
        self::assertSame(DeliveryState::FailedPermanent, $this->state('permanent'));
    }

    public function testUntrustedHostAndMethodCannotReachLocalPanel(): void
    {
        self::assertSame(403, $this->request('/', headers: ['Host: unrelated.invalid'])['status']);
        self::assertSame(405, $this->request('/', 'DELETE')['status']);
    }

    private function state(string $messageId): ?DeliveryState
    {
        $repository = new PdoOutboxRepository(ConnectionFactory::file($this->database));
        return $repository->get($messageId)?->state;
    }

    /** @param array<string, string> $data
     *  @param list<string> $headers
     *  @return array{status: int, body: string, headers: list<string>}
     */
    private function request(string $path, string $method = 'GET', array $data = [], array $headers = []): array
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => $headers, 'content' => http_build_query($data),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 3,
        ]]);
        $body = file_get_contents($this->server->url . $path, false, $context);
        self::assertIsString($body);
        if (preg_match('/HTTP\/\S+ (\d{3})/', $http_response_header[0], $status) !== 1) {
            self::fail('Expected a valid HTTP status line.');
        }
        return ['status' => (int) $status[1], 'body' => $body, 'headers' => $http_response_header];
    }
}
