<?php

declare(strict_types=1);

namespace App\Tests\Verification;

use App\Http\HttpException;
use App\Http\HttpResponse;
use App\Tests\Support\FakeHttpClient;
use App\Verification\PayPalVerifier;
use App\Verification\VerificationOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks PayPalVerifier's three-way outcome (FR-6). PayPal has no local secret: it ASKS PayPal over
 * two calls (OAuth token, then verify-webhook-signature). So it is the only verifier that can return
 * Error — and the load-bearing distinction this suite pins is: a reachable PayPal that says
 * "not SUCCESS" is Invalid (401, a forgery), but an UNREACHABLE PayPal (throw / non-2xx / garbage
 * body, on either call) is Error (502, our outage) — never the other way round. A FakeHttpClient
 * scripts both calls so every branch runs without the network.
 */
#[CoversClass(PayPalVerifier::class)]
final class PayPalVerifierTest extends TestCase
{
    private const API_BASE = 'https://api-m.example.test';
    private const WEBHOOK_ID = 'WH-CONFIG-1';
    private const BODY = '{"id":"WH-EVT-1","event_type":"PAYMENT.CAPTURE.COMPLETED"}';

    /** @return array<string, string> The five required paypal-* transmission headers. */
    private static function validHeaders(): array
    {
        return [
            'paypal-transmission-id' => 'txn-1',
            'paypal-transmission-time' => '2026-06-06T00:00:00Z',
            'paypal-transmission-sig' => 'sig-bytes',
            'paypal-cert-url' => 'https://api-m.example.test/cert.pem',
            'paypal-auth-algo' => 'SHA256withRSA',
        ];
    }

    /**
     * @param list<HttpResponse|HttpException> $script
     */
    private static function verifier(array $script): PayPalVerifier
    {
        return new PayPalVerifier(
            new FakeHttpClient($script),
            self::API_BASE,
            self::WEBHOOK_ID,
            'client-id',
            'client-secret',
        );
    }

    private static function tokenOk(): HttpResponse
    {
        return new HttpResponse(200, '{"access_token":"A21AA-test-token"}');
    }

    public function testTokenThenVerifySuccessYieldsValidWithIdAndTypeFromBody(): void
    {
        $result = self::verifier([
            self::tokenOk(),
            new HttpResponse(200, '{"verification_status":"SUCCESS"}'),
        ])->verify(self::BODY, self::validHeaders());

        self::assertTrue($result->isValid());
        self::assertSame('WH-EVT-1', $result->eventId());
        self::assertSame('PAYMENT.CAPTURE.COMPLETED', $result->eventType());
    }

    public function testVerificationStatusNotSuccessIsInvalid(): void
    {
        $result = self::verifier([
            self::tokenOk(),
            new HttpResponse(200, '{"verification_status":"FAILURE"}'),
        ])->verify(self::BODY, self::validHeaders());

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
    }

    public function testTokenCallTransportFailureIsError(): void
    {
        $result = self::verifier([
            HttpException::transportFailure(self::API_BASE, 'connection refused'),
        ])->verify(self::BODY, self::validHeaders());

        self::assertSame(VerificationOutcome::Error, $result->outcome());
    }

    public function testTokenCallNon2xxIsError(): void
    {
        $result = self::verifier([
            new HttpResponse(401, '{"error":"invalid_client"}'),
        ])->verify(self::BODY, self::validHeaders());

        self::assertSame(VerificationOutcome::Error, $result->outcome());
    }

    public function testTokenResponseWithoutAccessTokenIsError(): void
    {
        $result = self::verifier([
            new HttpResponse(200, '{"scope":"https://uri.paypal.com/"}'),
        ])->verify(self::BODY, self::validHeaders());

        self::assertSame(VerificationOutcome::Error, $result->outcome());
    }

    public function testVerifyCallTransportFailureIsError(): void
    {
        $result = self::verifier([
            self::tokenOk(),
            HttpException::transportFailure(self::API_BASE, 'timeout'),
        ])->verify(self::BODY, self::validHeaders());

        self::assertSame(VerificationOutcome::Error, $result->outcome());
    }

    public function testVerifyCallNon2xxIsError(): void
    {
        $result = self::verifier([
            self::tokenOk(),
            new HttpResponse(500, 'upstream error'),
        ])->verify(self::BODY, self::validHeaders());

        self::assertSame(VerificationOutcome::Error, $result->outcome());
    }

    public function testVerifyCallUnreadableBodyIsError(): void
    {
        $result = self::verifier([
            self::tokenOk(),
            new HttpResponse(200, 'definitely not json'),
        ])->verify(self::BODY, self::validHeaders());

        self::assertSame(VerificationOutcome::Error, $result->outcome());
    }

    public function testMissingTransmissionHeaderIsInvalidWithoutAnyHttpCall(): void
    {
        $headers = self::validHeaders();
        unset($headers['paypal-transmission-sig']);

        // A missing header must fail closed BEFORE any network round-trip. We inspect the fake
        // directly: it recorded zero calls, so PayPal was never contacted (an empty script would
        // also throw LogicException if post() were reached — belt and braces).
        $http = new FakeHttpClient([]);
        $result = new PayPalVerifier($http, self::API_BASE, self::WEBHOOK_ID, 'client-id', 'client-secret');
        $outcome = $result->verify(self::BODY, $headers);

        self::assertSame(VerificationOutcome::Invalid, $outcome->outcome());
        self::assertCount(0, $http->calls);
    }

    public function testEmptyBodyIsInvalidWithoutAnyHttpCall(): void
    {
        $http = new FakeHttpClient([]);
        $result = new PayPalVerifier($http, self::API_BASE, self::WEBHOOK_ID, 'client-id', 'client-secret');
        $outcome = $result->verify('', self::validHeaders());

        self::assertSame(VerificationOutcome::Invalid, $outcome->outcome());
        self::assertCount(0, $http->calls);
    }

    public function testNonJsonBodyIsInvalidWithoutAnyHttpCall(): void
    {
        $http = new FakeHttpClient([]);
        $result = new PayPalVerifier($http, self::API_BASE, self::WEBHOOK_ID, 'client-id', 'client-secret');
        $outcome = $result->verify('not json', self::validHeaders());

        self::assertSame(VerificationOutcome::Invalid, $outcome->outcome());
        self::assertCount(0, $http->calls);
    }
}
