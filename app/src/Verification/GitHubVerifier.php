<?php

declare(strict_types=1);

namespace App\Verification;

use App\Config\ProviderSecrets;
use App\Exception\VerificationException;

/**
 * Verifies GitHub webhooks: HMAC-SHA256 over the exact raw request bytes.
 *
 * GitHub signs the raw body with the per-hook secret and sends the digest in the
 * `X-Hub-Signature-256` header as `sha256=<hex>`. We recompute the HMAC over the
 * untouched bytes and compare timing-safe. Two details that are easy to get wrong:
 *
 *  - The `sha256=` prefix is part of what we compare: GitHub puts it in the header,
 *    so we prepend it to OUR computed value and compare the whole strings. That way
 *    a single hash_equals() call covers both the algorithm tag and the digest.
 *  - We compare with hash_equals(), never `==`/`===`. A naive `==` leaks, through
 *    its early-exit timing, how many leading bytes of the digest matched — enough to
 *    forge a signature byte-by-byte over many tries. hash_equals() is constant-time.
 *
 * The legacy `X-Hub-Signature` header (SHA-1) is deliberately ignored: SHA-1 is
 * broken and this is new code.
 *
 * Where the id and type come from: for GitHub BOTH the dedupe id and the event type
 * live in HEADERS, not the JSON body — `X-GitHub-Delivery` (a per-delivery GUID,
 * our UNIQUE(provider, event_id) key, FR-11) and `X-GitHub-Event` (e.g. "push").
 * So this verifier never decodes the body at all; the body is opaque signed bytes.
 * (Stripe and PayPal differ — their ids live in the body — which is why extraction
 * is each verifier's own job behind the shared contract.)
 *
 * A valid signature whose delivery/event headers are missing is still `invalid()`,
 * not `error()`: verification SUCCEEDED, but a genuine GitHub delivery always carries
 * both headers, and without `X-GitHub-Delivery` we have no dedupe key to store. We
 * fail closed (401, no enqueue) rather than invent one. `error()` is reserved for
 * "we could not verify at all" (a remote outage), which never happens for local HMAC.
 *
 * The secret is resolved once, at construction (see fromSecrets()): a constructed
 * verifier is guaranteed to hold a secret, so verify() never re-checks it and the
 * "secret missing" misconfiguration surfaces as a 500 before any request is served.
 */
final class GitHubVerifier implements ProviderVerifier
{
    private const PROVIDER = 'github';
    private const SIGNATURE_HEADER = 'x-hub-signature-256';
    private const DELIVERY_HEADER = 'x-github-delivery';
    private const EVENT_HEADER = 'x-github-event';
    private const SIGNATURE_PREFIX = 'sha256=';

    public function __construct(
        private readonly string $secret,
    ) {
    }

    /**
     * Build the verifier from configured secrets, failing fast if GitHub's webhook
     * secret is absent. A missing secret is a deployment fault (→ 500), never a bad
     * signature (→ 401): returning 401 here would tell a legitimate provider "your
     * signature is wrong" when in fact WE are misconfigured.
     */
    public static function fromSecrets(ProviderSecrets $secrets): self
    {
        $secret = $secrets->githubSecret();

        if ($secret === null) {
            throw VerificationException::missingSecret(self::PROVIDER);
        }

        return new self($secret);
    }

    public function verify(string $rawBody, array $headers): VerificationResult
    {
        // An empty body can carry no legitimately signed event. Routine invalid
        // path, never an exception (spec Risk §8: a malformed request is never 5xx).
        if ($rawBody === '') {
            return VerificationResult::invalid('empty request body');
        }

        $provided = $headers[self::SIGNATURE_HEADER] ?? '';

        if ($provided === '') {
            return VerificationResult::invalid('missing X-Hub-Signature-256 header');
        }

        // The load-bearing line: recompute over the raw bytes, prefix to match the
        // header's `sha256=` shape, compare in constant time. A malformed header
        // (no prefix, wrong length, garbage) simply will not equal $computed and
        // falls through to invalid — hash_equals handles unequal lengths safely, so
        // no separate prefix/length pre-check is needed.
        $computed = self::SIGNATURE_PREFIX . hash_hmac('sha256', $rawBody, $this->secret);

        if (!hash_equals($computed, $provided)) {
            return VerificationResult::invalid('signature mismatch');
        }

        // Signature is authentic — now read the id and type from the headers. trim()
        // guards against a proxy delivering a blank-but-present header, which would
        // otherwise slip an empty/whitespace id past the check below (and Verification
        // Result::valid() throws on an empty id).
        $eventId = trim($headers[self::DELIVERY_HEADER] ?? '');
        $eventType = trim($headers[self::EVENT_HEADER] ?? '');

        if ($eventId === '' || $eventType === '') {
            return VerificationResult::invalid('missing X-GitHub-Delivery or X-GitHub-Event header');
        }

        return VerificationResult::valid($eventId, $eventType);
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }
}
