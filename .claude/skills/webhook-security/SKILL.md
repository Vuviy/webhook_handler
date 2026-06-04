---
name: webhook-security
description: How to verify webhook signatures for GitHub, Stripe and PayPal, and how to defend against replay and duplicate delivery. Use when implementing or reviewing the ingestion endpoints.
---

# Webhook security

The golden rule: **verify the signature over the exact raw bytes you received**, before
you trust anything in the payload. Decode JSON only after the signature passes.

## GitHub — HMAC-SHA256
- Header: `X-Hub-Signature-256: sha256=<hex>`.
- Compute `hash_hmac('sha256', $rawBody, $secret)` where `$secret` is the webhook secret.
- Compare timing-safe, including the `sha256=` prefix:
  `hash_equals('sha256=' . $computed, $headerValue)`.
- (Legacy `X-Hub-Signature` is SHA-1 — do not accept it for new code.)

## Stripe — scheme `t` + `v1`
- Header: `Stripe-Signature: t=<unix_ts>,v1=<hex>,v1=<hex>...`.
- Signed payload string = `"{t}.{rawBody}"`.
- Compute `hash_hmac('sha256', "{t}.{rawBody}", $signingSecret)` (the `whsec_...` value).
- Accept if **any** `v1` matches (timing-safe).
- **Replay protection:** reject if `abs(now - t) > tolerance` (Stripe default 300s).

## PayPal — certificate / API verification
- Headers: `Paypal-Transmission-Id`, `Paypal-Transmission-Time`, `Paypal-Transmission-Sig`,
  `Paypal-Cert-Url`, `Paypal-Auth-Algo`.
- Two options:
  1. **Server-side API call** to `/v1/notifications/verify-webhook-signature` (simplest, robust).
  2. **Local crypto:** download & validate the cert from `Paypal-Cert-Url`
     (must be a `*.paypal.com` host), build the expected string
     `transmissionId|transmissionTime|webhookId|crc32(rawBody)` and verify the RSA signature.
- Cache the certificate; never fetch an arbitrary URL blindly.

## Cross-cutting defenses
- **Timing-safe compare** everywhere: `hash_equals`, never `==`/`===`.
- **Replay window**: use the provider timestamp where available.
- **Idempotency / dedupe**: store the provider event id
  (`X-GitHub-Delivery`, Stripe `event.id`, PayPal `id`) with a UNIQUE constraint;
  a second delivery is a no-op that still returns `2xx`.
- **Fail closed**: missing/invalid signature → `401`, do not enqueue.
- **Don't leak**: never log secrets or full raw bodies at info level.

## Minimal verification flow (pseudocode)
```
raw   = read php://input
sig   = request header for this provider
if not provider.verify(raw, sig, secret): return 401
event = json_decode(raw)
if events.existsById(event.id): return 200   // dedupe
events.insert(provider, event.id, raw, status=received)
queue.push(jobFor(event))
return 202
```
