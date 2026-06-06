<?php

declare(strict_types=1);

namespace App\Tests\Verification;

use App\Verification\StripeVerifier;
use App\Verification\VerificationOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks StripeVerifier's decision branches (FR-5). Stripe signs "{t}.{rawBody}" and ships a
 * composite `t=..,v1=..,v1=..` header; the verifier accepts if our HMAC matches ANY v1 AND `t` is
 * within the replay tolerance. The clock is an injected fixed closure so the window is tested
 * deterministically at its exact boundary (±300) and just beyond (±301) with no sleeping.
 */
#[CoversClass(StripeVerifier::class)]
final class StripeVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const NOW = 1_700_000_000;
    private const TOLERANCE = 300;
    private const BODY = '{"id":"evt_123","type":"payment_intent.succeeded"}';

    private static function verifier(): StripeVerifier
    {
        // Fixed clock: every replay-window check is measured against this frozen "now".
        return new StripeVerifier(self::SECRET, static fn (): int => self::NOW);
    }

    /** The v1 digest Stripe would compute for timestamp $t over $body. */
    private static function sign(int $t, string $body = self::BODY, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $t . '.' . $body, $secret);
    }

    /** A well-formed `t=..,v1=..` header for timestamp $t over $body. */
    private static function header(int $t, string $body = self::BODY): string
    {
        return sprintf('t=%d,v1=%s', $t, self::sign($t, $body));
    }

    public function testValidWithinToleranceYieldsValidWithIdAndTypeFromBody(): void
    {
        $result = self::verifier()->verify(self::BODY, ['stripe-signature' => self::header(self::NOW)]);

        self::assertTrue($result->isValid());
        self::assertSame('evt_123', $result->eventId());
        self::assertSame('payment_intent.succeeded', $result->eventType());
    }

    public function testMultipleV1RotationSecondMatchesIsValid(): void
    {
        // During a secret rotation Stripe sends several v1; we accept if our HMAC matches any.
        $header = sprintf('t=%d,v1=%s,v1=%s', self::NOW, str_repeat('0', 64), self::sign(self::NOW));

        $result = self::verifier()->verify(self::BODY, ['stripe-signature' => $header]);

        self::assertTrue($result->isValid());
    }

    public function testSignatureMismatchIsInvalid(): void
    {
        $header = sprintf('t=%d,v1=%s', self::NOW, str_repeat('a', 64));

        $result = self::verifier()->verify(self::BODY, ['stripe-signature' => $header]);

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
        self::assertSame('signature mismatch', $result->reason());
    }

    public function testOneByteOffV1IsInvalid(): void
    {
        // A v1 of the correct length but wrong by one nibble exercises the timing-safe hash_equals
        // path (the loop compares each candidate constant-time), parallel to the GitHub case.
        $valid = self::sign(self::NOW);
        $lastChar = $valid[-1];
        $tampered = substr($valid, 0, -1) . ($lastChar === '0' ? '1' : '0');

        $result = self::verifier()->verify(
            self::BODY,
            ['stripe-signature' => sprintf('t=%d,v1=%s', self::NOW, $tampered)],
        );

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
        self::assertSame('signature mismatch', $result->reason());
    }

    public function testMissingHeaderIsInvalid(): void
    {
        $result = self::verifier()->verify(self::BODY, []);

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
    }

    public function testMissingTimestampIsInvalid(): void
    {
        $result = self::verifier()->verify(
            self::BODY,
            ['stripe-signature' => 'v1=' . self::sign(self::NOW)],
        );

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
        self::assertSame('missing t in Stripe-Signature', $result->reason());
    }

    public function testMissingV1IsInvalid(): void
    {
        $result = self::verifier()->verify(
            self::BODY,
            ['stripe-signature' => 't=' . self::NOW],
        );

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
        self::assertSame('missing v1 signature in Stripe-Signature', $result->reason());
    }

    public function testNonNumericTimestampIsInvalid(): void
    {
        $result = self::verifier()->verify(
            self::BODY,
            ['stripe-signature' => 't=not-a-number,v1=' . self::sign(self::NOW)],
        );

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
        self::assertSame('non-numeric t in Stripe-Signature', $result->reason());
    }

    public function testTimestampAtToleranceBoundaryIsValid(): void
    {
        // abs(now - t) == TOLERANCE is NOT outside the window (the check is strictly `> tolerance`),
        // so both the past and future edges still verify (with a correct signature for that t).
        $past = self::verifier()->verify(
            self::BODY,
            ['stripe-signature' => self::header(self::NOW - self::TOLERANCE)],
        );
        $future = self::verifier()->verify(
            self::BODY,
            ['stripe-signature' => self::header(self::NOW + self::TOLERANCE)],
        );

        self::assertTrue($past->isValid());
        self::assertTrue($future->isValid());
    }

    public function testTimestampJustOutsideToleranceIsInvalid(): void
    {
        $past = self::verifier()->verify(
            self::BODY,
            ['stripe-signature' => self::header(self::NOW - self::TOLERANCE - 1)],
        );
        $future = self::verifier()->verify(
            self::BODY,
            ['stripe-signature' => self::header(self::NOW + self::TOLERANCE + 1)],
        );

        self::assertSame(VerificationOutcome::Invalid, $past->outcome());
        self::assertSame('timestamp outside tolerance', $past->reason());
        self::assertSame(VerificationOutcome::Invalid, $future->outcome());
        self::assertSame('timestamp outside tolerance', $future->reason());
    }

    public function testValidSignatureOverNonJsonBodyIsInvalid(): void
    {
        // The signature is authentic over these bytes, but the bytes are not JSON: we cannot pull a
        // dedupe id, so we fail closed as Invalid (not Error).
        $body = 'not json at all';
        $result = self::verifier()->verify(
            $body,
            ['stripe-signature' => self::header(self::NOW, $body)],
        );

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
        self::assertSame('payload is not valid JSON', $result->reason());
    }

    public function testValidSignatureOverObjectMissingIdOrTypeIsInvalid(): void
    {
        $body = '{"type":"payment_intent.succeeded"}';
        $result = self::verifier()->verify(
            $body,
            ['stripe-signature' => self::header(self::NOW, $body)],
        );

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
        self::assertSame('missing id or type in Stripe payload', $result->reason());
    }
}
