<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Tests\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Portfolio\Commerce\Application\DeliveryAction;
use Portfolio\Commerce\Application\HttpResponse;
use Portfolio\Commerce\Application\RetryPolicy;
use Portfolio\Commerce\Application\TransportFailure;

final class RetryPolicyTest extends TestCase
{
    /** @return iterable<string, array{HttpResponse, int, DeliveryAction, int}> */
    public static function responses(): iterable
    {
        yield 'success' => [new HttpResponse(202, '{"accepted":true}'), 1, DeliveryAction::Sent, 0];
        yield 'business rejection' => [
            new HttpResponse(200, '{"accepted":false,"code":"DECLINED"}'),
            1,
            DeliveryAction::FailedPermanent,
            0,
        ];
        yield 'rate limit' => [new HttpResponse(429, '{}'), 1, DeliveryAction::Retry, 5];
        yield 'server error' => [new HttpResponse(503, '{}'), 2, DeliveryAction::Retry, 30];
        yield 'retry exhausted' => [new HttpResponse(503, '{}'), 3, DeliveryAction::FailedTemporary, 0];
        yield 'client error' => [new HttpResponse(422, '{}'), 1, DeliveryAction::FailedPermanent, 0];
    }

    #[DataProvider('responses')]
    public function testClassifiesResponse(
        HttpResponse $response,
        int $attempt,
        DeliveryAction $expectedAction,
        int $expectedDelay,
    ): void {
        $decision = (new RetryPolicy())->forResponse($response, $attempt);

        self::assertSame($expectedAction, $decision->action);
        self::assertSame($expectedDelay, $decision->delaySeconds);
    }

    public function testRetriesNetworkFailureButRejectsPolicyFailure(): void
    {
        $policy = new RetryPolicy();

        self::assertSame(
            DeliveryAction::Retry,
            $policy->forTransportFailure(new TransportFailure('network', 'timeout'), 1)->action,
        );
        self::assertSame(
            DeliveryAction::FailedPermanent,
            $policy->forTransportFailure(new TransportFailure('policy', 'blocked target'), 1)->action,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAcknowledgements(): iterable
    {
        yield 'html' => ['<html>proxy error</html>'];
        yield 'empty' => [''];
        yield 'missing accepted' => ['{}'];
        yield 'null accepted' => ['{"accepted":null}'];
        yield 'string accepted' => ['{"accepted":"true"}'];
        yield 'list' => ['[true]'];
    }

    #[DataProvider('invalidAcknowledgements')]
    public function testMissingTypedAcknowledgementNeverMarksMessageSent(string $body): void
    {
        $decision = (new RetryPolicy())->forResponse(new HttpResponse(200, $body), 1);
        self::assertSame(DeliveryAction::FailedPermanent, $decision->action);
        self::assertSame('INVALID_ACKNOWLEDGEMENT', $decision->errorCode);
    }

    public function testUntrustedErrorTextIsNotCopiedIntoAudit(): void
    {
        $body = json_encode(['accepted' => false, 'code' => 'private@example.invalid'], JSON_THROW_ON_ERROR);
        $decision = (new RetryPolicy())->forResponse(new HttpResponse(200, $body), 1);
        self::assertSame('BUSINESS_REJECTED', $decision->errorCode);
    }
}
