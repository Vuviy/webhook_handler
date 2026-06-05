<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * The three distinct outcomes of verifying an incoming webhook.
 *
 * Two states are not enough. A bare boolean collapses "the signature is a forgery"
 * with "we could not even check the signature", but the ingestion controller (T1.5)
 * must treat them differently:
 *
 *  - Valid   → the request is authentic: persist the event and enqueue it (HTTP 202).
 *  - Invalid → forged, missing or malformed signature: reject with HTTP 401 and do
 *              NOT enqueue (FR-5). This is the routine "someone sent us junk" path.
 *  - Error   → verification could not be performed at all — e.g. PayPal's remote
 *              verify-webhook-signature API timed out or returned 5xx. That is an
 *              outage on our side, not a forgery, so it must map to a 5xx (502),
 *              never a 401, and the provider should be told to retry.
 *
 * Only the PayPal verifier (T1.4) can ever produce Error; GitHub and Stripe verify
 * locally with HMAC and only ever return Valid or Invalid. The seam still models all
 * three so the controller is written once and stays stable when PayPal lands.
 */
enum VerificationOutcome: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Error = 'error';
}
