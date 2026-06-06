<?php

declare(strict_types=1);

namespace App\Tests\Verification;

use App\Verification\GitHubVerifier;
use App\Verification\VerificationOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks GitHubVerifier's decision branches (FR-4): a correct HMAC-SHA256 over the raw bytes plus the
 * delivery/event headers yields Valid with the id/type from headers; everything else fails CLOSED as
 * Invalid (never Error — GitHub verifies locally, so "could not verify at all" cannot happen). The
 * security-critical line is the timing-safe hash_equals compare, exercised by the one-byte-off case.
 */
#[CoversClass(GitHubVerifier::class)]
final class GitHubVerifierTest extends TestCase
{
    private const SECRET = 'test_secret';
    private const BODY = '{"action":"opened","number":1}';

    /** Build the header value GitHub would send for $body signed with $secret. */
    private static function signature(string $body, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /** @return array<string, string> A well-formed, valid GitHub header set. */
    private static function validHeaders(string $body = self::BODY): array
    {
        return [
            'x-hub-signature-256' => self::signature($body),
            'x-github-delivery' => 'd5f3e7a0-0000-0000-0000-000000000001',
            'x-github-event' => 'pull_request',
        ];
    }

    public function testValidSignatureYieldsValidWithIdAndTypeFromHeaders(): void
    {
        $result = (new GitHubVerifier(self::SECRET))->verify(self::BODY, self::validHeaders());

        self::assertTrue($result->isValid());
        self::assertSame('d5f3e7a0-0000-0000-0000-000000000001', $result->eventId());
        self::assertSame('pull_request', $result->eventType());
    }

    public function testTamperedBodyIsInvalid(): void
    {
        $headers = self::validHeaders(self::BODY);

        // Same (valid) signature, but the body the bytes are checked against has changed.
        $result = (new GitHubVerifier(self::SECRET))->verify(self::BODY . ' ', $headers);

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
        self::assertSame('signature mismatch', $result->reason());
    }

    public function testWrongSecretIsInvalid(): void
    {
        $headers = ['x-hub-signature-256' => self::signature(self::BODY, 'other_secret')]
            + self::validHeaders();

        $result = (new GitHubVerifier(self::SECRET))->verify(self::BODY, $headers);

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
        self::assertSame('signature mismatch', $result->reason());
    }

    public function testMissingSignatureHeaderIsInvalid(): void
    {
        $headers = self::validHeaders();
        unset($headers['x-hub-signature-256']);

        $result = (new GitHubVerifier(self::SECRET))->verify(self::BODY, $headers);

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
    }

    public function testEmptyBodyIsInvalid(): void
    {
        $result = (new GitHubVerifier(self::SECRET))->verify('', self::validHeaders());

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
    }

    public function testValidSignatureButMissingDeliveryOrEventHeadersFailsClosed(): void
    {
        // Authentic signature, but no X-GitHub-Delivery / X-GitHub-Event: we have no dedupe key, so
        // we reject as Invalid (401), NOT Error (502) — verification succeeded, the request is junk.
        $result = (new GitHubVerifier(self::SECRET))->verify(
            self::BODY,
            ['x-hub-signature-256' => self::signature(self::BODY)],
        );

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
    }

    public function testOneByteOffSignatureIsInvalid(): void
    {
        // A near-miss of the correct length exercises the timing-safe hash_equals path: flip the
        // last hex nibble so the digest is the right shape but wrong by one byte.
        $valid = self::signature(self::BODY);
        $lastChar = $valid[-1];
        $tampered = substr($valid, 0, -1) . ($lastChar === '0' ? '1' : '0');

        $headers = ['x-hub-signature-256' => $tampered] + self::validHeaders();

        $result = (new GitHubVerifier(self::SECRET))->verify(self::BODY, $headers);

        self::assertSame(VerificationOutcome::Invalid, $result->outcome());
        self::assertSame('signature mismatch', $result->reason());
    }
}
