# 0007. Stripe verifier: clock injection and timestamp-tolerance source

- **Status:** Accepted
- **Date:** 2026-06-05

## Context

T1.3 adds `StripeVerifier`, the second concrete `ProviderVerifier`
(`app/src/Verification/StripeVerifier.php`). Unlike GitHub (T1.2,
`app/src/Verification/GitHubVerifier.php`), Stripe's scheme carries a **signed
timestamp** and mandates **replay protection**: reject the request when
`abs(now - t) > tolerance` (Stripe's own default tolerance is **300 s**, see
`.claude/skills/webhook-security/SKILL.md:23`).

That single requirement introduces a dependency the GitHub verifier never had:
the verifier must read **the current wall-clock time** and compare it to a value
in the request. This creates two design forces that GitHub did not surface, and
both have a real fork worth recording:

1. **Where does `now` come from?** The tolerance branch (`stale` / `clock-skewed`
   timestamp → `invalid`) is a security control, so it must be **unit-testable**
   without `sleep()` and without mutating global process time. A bare `time()`
   call inside `verify()` is effectively untestable for that branch — you cannot
   drive the "61 s in the future" or "301 s in the past" cases deterministically.
   GitHub's verifier is a pure function of `(rawBody, headers, secret)` and needed
   no such seam (`GitHubVerifier.php:74`), so this is genuinely new.

2. **Where does the 300 s tolerance live?** Hardcoded constant, or pulled from
   env/config? The project keeps configuration deliberately thin
   (`ProviderSecrets` holds *secrets only*, `ProviderSecrets.php:7-15`), and the
   spec's Open-Questions list fixes no tolerance knob.

House style to honour (from `GitHubVerifier.php`, `Queue.php`, ADR 0006):
small `final` classes, `private const` for the provider tag and header/parsing
constants, a static `fromSecrets(ProviderSecrets): self` that null-checks the
secret and throws `VerificationException::missingSecret()`, rich "why"
doc-comments, and **no abstraction introduced before it earns its keep**.

## Options considered — (1) clock source

### Option 1A — call `time()` directly inside `verify()`
- Pros: zero new constructor params; identical shape to `GitHubVerifier`.
- Cons: the replay/tolerance branch — the whole security point of T1.3 — becomes
  untestable without global-time hacks (`uopz`, running the suite at a fixed
  date, or `sleep`). We would ship a security control with no fast test for it.
  Rejected.

### Option 1B — inject a `Closure(): int` clock, defaulting to `time(...)` (chosen)
- A constructor parameter `private \Closure $clock` whose production default is
  the first-class callable `time(...)`. `verify()` reads `($this->clock)()`.
  A test passes `fn (): int => 1_700_000_000` to pin "now".
- Pros: the lightest possible seam — no new interface, no new file, one typed
  property; production code reads `time()` exactly as today; tests drive every
  tolerance case deterministically with a one-line closure. Matches "avoid
  abstractions that have only one implementation" (ADR 0006 / conventions).
- Cons: a `Closure` is structurally untyped (any `(): int` callable satisfies it);
  the contract lives in a doc-comment, not a nominal type. Acceptable for a single
  internal collaborator.

### Option 1C — introduce a `Clock` interface + `SystemClock` value
- Pros: nominal type; reusable once the worker's retry/backoff (T2.x) also needs
  "now"; conventional PSR-20-flavoured shape.
- Cons: a brand-new interface + implementation file for a *single* current caller,
  exactly the premature abstraction ADR 0006 warned against (cf. the rejected
  `WebhookRequest` DTO). The worker does not exist yet and may want a *monotonic*
  or *injected-via-Redis* time anyway, so designing the shared abstraction now
  would be guesswork. Defer until a second consumer is real.

## Options considered — (2) tolerance source

### Option 2A — `private const TOLERANCE_SECONDS = 300`, no constructor knob
- Pros: matches Stripe's own libraries (which default to 300 and rarely change);
  zero config surface; nothing new to document or wire through bootstrap.
- Cons: changing it needs a code edit + redeploy. In practice no one tunes it.

### Option 2B — constructor parameter `int $toleranceSeconds = 300` (chosen)
- The default const value lives in the signature; production constructs with the
  default, tests can pass a tiny tolerance to exercise the boundary cheaply, and a
  future operator knob can be wired in at the `fromSecrets`/bootstrap layer
  *without touching `verify()`* if it is ever needed.
- Pros: testable boundary; keeps `ProviderSecrets` (secrets-only) unpolluted;
  same "sane default in the signature, override for tests" shape as the clock.
- Cons: one extra constructor parameter. Trivial.

### Option 2C — read tolerance from env/config now
- Pros: operator-tunable without a deploy.
- Cons: adds a config key, a wiring path and a "what if it's absent / non-numeric"
  branch for a value that essentially never changes; bloats either `ProviderSecrets`
  (wrong home — it is not a secret) or forces a new config type. Premature; the
  constructor default already leaves the door open to add this later cheaply.

## Decision

- **(1) Option 1B** — inject `\Closure(): int $clock`, defaulting to `time(...)`.
  No `Clock` interface yet; revisit (→ possible Option 1C) when the worker's
  retry/backoff lands and a *second* consumer of "now" appears.
- **(2) Option 2B** — tolerance is `private const TOLERANCE_SECONDS = 300` used as
  the default of an `int $toleranceSeconds` constructor parameter. No env/config
  key in T1.3.
- `fromSecrets(ProviderSecrets $s): self` constructs with **both defaults**
  (`time(...)` clock, 300 s), mirroring `GitHubVerifier::fromSecrets()` and
  null-checking `stripeSecret()` → `VerificationException::missingSecret('stripe')`.

## Consequences

- **Positive:** every security branch (stale timestamp, future-skew, forged `t`)
  is unit-testable with a one-line closure and a small tolerance, no `sleep`, no
  global-time mutation; production behaviour is identical to a bare `time()` call;
  no new files, no new config surface, `ProviderSecrets` stays secrets-only; the
  shape stays a near-twin of `GitHubVerifier`, so the reader learns one pattern.
- **Negative / debt:** the clock contract is a doc-comment on an untyped `Closure`,
  not a nominal type; if/when T2.x needs a shared, possibly monotonic clock we will
  likely promote this to a `Clock` interface (superseding this ADR's part 1). The
  300 s tolerance requires a redeploy to change — accepted, it is effectively a
  constant in practice.
