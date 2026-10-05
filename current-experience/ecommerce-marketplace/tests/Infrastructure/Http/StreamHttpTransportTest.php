<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Tests\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Portfolio\Commerce\Application\TransportFailure;
use Portfolio\Commerce\Infrastructure\Http\StreamHttpTransport;
use Portfolio\Commerce\Tests\Support\LocalPhpServer;

final class StreamHttpTransportTest extends TestCase
{
    private LocalPhpServer $server;

    protected function setUp(): void
    {
        $this->server = new LocalPhpServer(__DIR__ . '/fixtures', __DIR__ . '/fixtures/router.php');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testRedirectIsReturnedWithoutFollowingItsTarget(): void
    {
        $response = (new StreamHttpTransport())->postJson($this->server->url . '/redirect', 'safe-key', []);
        self::assertSame(302, $response->statusCode);
        self::assertStringNotContainsString('redirect_followed', $response->body);
    }

    public function testHeaderInjectionIsRejectedBeforeDelivery(): void
    {
        $this->expectException(TransportFailure::class);
        (new StreamHttpTransport())->postJson($this->server->url, "key\r\nX-Injected: yes", []);
    }

    public function testOversizedResponseIsNotBufferedOrAccepted(): void
    {
        $this->expectException(TransportFailure::class);
        (new StreamHttpTransport())->postJson($this->server->url . '/large', 'safe-key', []);
    }

    public function testTimeoutIsAnExplicitTransportFailure(): void
    {
        try {
            (new StreamHttpTransport(0.05))->postJson($this->server->url . '/timeout', 'safe-key', []);
            self::fail('Timeout must not produce a successful response.');
        } catch (TransportFailure $error) {
            self::assertSame('network', $error->kind);
        }
    }

    public function testNormalResponseIsPreserved(): void
    {
        $response = (new StreamHttpTransport())->postJson($this->server->url, 'safe-key', ['synthetic' => true]);
        self::assertSame(200, $response->statusCode);
        self::assertSame(['accepted' => true, 'injected' => false], json_decode($response->body, true));
    }
}
