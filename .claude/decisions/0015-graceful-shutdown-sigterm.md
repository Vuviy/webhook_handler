# 0015. Graceful shutdown: catching SIGTERM/SIGINT to finish the in-flight job

- **Status:** Accepted
- **Date:** 2026-06-06

## Context

T2.5 fills the last remaining seam ADR 0012 left in `app/worker.php`: the
`$job === null` idle tick at `worker.php:85-88`, whose comment already reserves it
("T2.5's graceful-shutdown check lands here"). The other two seams are closed —
T2.3 added the retry path (ADR 0013) and T2.4 the DLQ on exhaustion (ADR 0014).

**Goal.** When the `worker` container receives `SIGTERM` (`docker stop`,
`docker compose down`, or a deploy/restart), the worker should *finish the job it
is currently handling* and then exit cleanly with status `0`, instead of being
`SIGKILL`ed by Docker after the default 10s `stop_grace_period`. Locally, the same
should happen on `SIGINT` (Ctrl-C) when the worker is run in the foreground.

**Why this is about cleanliness, not correctness.** Delivery is already
*at-least-once* and the handlers are *idempotent* (spec §8 `webhook-handler.spec.md:94`,
FR-11 `:41`, NFR-2 `:46`), and the row claim is the guarded
`markProcessing … AND status='received'` (`EventRepository.php`, ADR 0012 D2). So a
hard kill mid-handler is *already survivable*: the job stays on no-one's queue only
briefly — actually the job has been BLPOP'd off `webhooks:queue` already, so a kill
between `markProcessing` and `markProcessed` would strand the row in `processing`
(the known, out-of-scope stuck-`processing` reaper covers that). Graceful shutdown
exists to **avoid that needless reprocess / stranded row** and to give the container
a **clean lifecycle** (exit 0, no SIGKILL, no half-written status). It is a quality /
operability improvement layered on top of correctness guarantees that already hold.

The skill is explicit: "Make the worker handle SIGTERM to finish the current job
before exiting (graceful shutdown)" (`queue-processing/SKILL.md:68`).

### Hard constraint carried in from the environment

PHP **cannot register a signal handler without the `pcntl` extension.** The base
image ships `posix` (verified: `php -m` lists `posix`, not `pcntl`) but `posix`
only exposes process *information*/utilities (e.g. `posix_kill`, `posix_getpid`) —
it **cannot install a handler**. `docker/php/Dockerfile` installs extensions via
`docker-php-ext-install …` and does **not** include `pcntl` (confirmed: no `pcntl`
token in the Dockerfile, lines 26-34). The `php` and `worker` services share the one
image (`build: './docker/php'` for both). Therefore T2.5 **necessarily** edits the
Dockerfile to add `pcntl` (a PHP-core extension, `docker-php-ext-install pcntl`) and
requires an image rebuild. There is no library-level alternative for a true,
catchable graceful shutdown — this is weighed honestly below, but it is unavoidable.

Constraints carried in from the existing loop (`app/worker.php`):
- finite `BLPOP_TIMEOUT_SECONDS = 5` with a `$job === null` idle `continue`
  (`worker.php:83-88`) — the reserved hook;
- dependencies built once before `while (true)` (fail-fast at boot, `worker.php:64-73`);
- an outer `try/catch (\Throwable)` infra backstop that `error_log()`s and
  `sleep(INFRA_BACKOFF_SECONDS)` (`worker.php:163-169`);
- `restart: unless-stopped`, no explicit `stop_grace_period` on the `worker`
  service → Docker's default **10s** applies before SIGKILL (`docker-compose.yml:42-59`).

Four genuine sub-decisions arise.

## Options considered

### Decision 0 — getting a catchable signal at all (the pcntl prerequisite)

#### Option Z0 — do nothing in PHP; rely on at-least-once + the 10s grace + SIGKILL
- Pros: zero code, no rebuild. The kill is "safe" because at-least-once + idempotency
  already tolerate it.
- Cons: every `docker stop` SIGKILLs the worker mid-handler after 10s, stranding the
  in-flight row in `processing` and forcing a needless reprocess on the next start.
  Directly contradicts the skill (`SKILL.md:68`) and the explicit T2.5 goal. The
  whole point of the task is to *avoid* the hard kill. Rejected.

#### Option Z1 — add `pcntl` to the image and catch the signal in PHP
- Pros: the only way to actually *catch* SIGTERM/SIGINT in PHP and run our own
  finish-the-job logic. `pcntl` is a PHP-core extension, a one-line
  `docker-php-ext-install pcntl`, no PECL, no system libs. Cost is a single rebuild of
  the shared image; the `php` (FPM) service simply gains an unused extension (harmless).
- Cons: touches the Dockerfile and forces `docker compose build worker` (and `php`).
  Slightly larger image. Accepted — there is no alternative for a real catch.

**→ Decision 0: Option Z1.** Unavoidable; weighed and accepted.

### Decision 1 — signal delivery mechanism

#### Option A — `pcntl_async_signals(true)` + `pcntl_signal(SIGTERM, $handler)` (and SIGINT)
The handler does **only** `$shouldStop = true;` (sets a flag; never exits/throws).
With async signals on, the PHP runtime dispatches pending signals automatically,
including by **interrupting a blocking syscall** — so the `BLPOP` inside
`Queue::consume()` is interrupted (EINTR) the instant the signal arrives.
- Pros: shutdown is **near-immediate even while idle** — we do not wait out the
  remaining BLPOP window. No `pcntl_signal_dispatch()` call to remember to sprinkle
  through the loop. Canonical modern PHP CLI pattern.
- Cons: async dispatch can run the handler at "any" VM tick; safe *here* because the
  handler only assigns a bool (no I/O, no throw), which is the recommended discipline.
  The interrupted BLPOP needs handling (Decision 3).

#### Option B — manual `pcntl_signal_dispatch()` once per loop iteration (no async)
- Pros: signals only ever fire at the one point we call dispatch — fully deterministic,
  never mid-statement.
- Cons: dispatch runs only **between** iterations, so a SIGTERM that arrives while the
  worker is parked in the 5s `BLPOP` is **not seen until the BLPOP returns** — up to a
  full 5s of idle latency on every shutdown. It also does **not** interrupt the
  blocking pop, so we pay the worst case on the common (idle) shutdown. More wiring for
  worse latency.

**→ Decision 1: Option A.** Async signals + a flag-setting handler give near-instant
idle shutdown by interrupting BLPOP, and the "handler only sets a flag" rule makes
async dispatch safe. Register the **same** handler for `SIGTERM` (Docker stop) and
`SIGINT` (local Ctrl-C).

### Decision 2 — where the stop check lives; how the in-flight job is guaranteed to finish

The invariant: **the handler must never exit() or throw.** It only sets
`$shouldStop`. The current iteration then completes *naturally* and the loop checks
the flag **before claiming the next job**.

#### Option C — check `$shouldStop` at the top of the loop (and at the reserved idle tick)
- `while (true) { if ($shouldStop) break; … }` as the first thing inside the loop, and
  also short-circuit the existing `$job === null` idle tick (`worker.php:85-88`) so an
  idle worker that just had its BLPOP interrupted exits immediately rather than looping
  once more.
- Pros: the check sits *before* `promoteDue()` and *before* `consume()`, so once the
  flag is set we never claim a new job. Whatever iteration was in flight when the signal
  arrived runs to its terminal state first (see the landing-point analysis), then the
  next top-of-loop check breaks. Minimal, reads like the state machine, fits the seam
  ADR 0012 reserved.
- Cons: if the signal lands *during* a handler, we still finish that one job (by design)
  — bounded by the handler's own runtime.

#### Option D — convert to `while (!$shouldStop)` and rely solely on the loop condition
- Pros: terser.
- Cons: the condition is only re-evaluated at the *bottom*/top boundary, which is fine,
  but it does **not** cover the idle-tick `continue` (that `continue` re-tests the
  condition, so it actually works) — however an explicit `if ($shouldStop) break;` at
  the idle tick documents intent and avoids one extra `promoteDue()` sweep on the way
  out. Functionally close to C; C is chosen for the explicit, self-documenting break at
  the exact reserved seam. (We may also phrase the outer loop as `while (!$shouldStop)`
  *in addition* — belt and suspenders — but the load-bearing exit is the explicit check.)

**Where SIGTERM can land, and why each spot is safe (the core safety argument):**
- **During `promoteDue()`** (zset→list sweep): finishes the sweep; next top-of-loop
  check breaks. Promotion is idempotent-ish under at-least-once anyway. Safe.
- **During the 5s `BLPOP`** (idle, no job): async signal interrupts it (EINTR) →
  `consume()` surfaces it (Decision 3) → we treat it as "stop", break. No job was
  claimed. Safe and fast.
- **Just after `BLPOP` returned a job, before `markProcessing`**: the flag is set; the
  iteration proceeds to claim and handle this one job, then top-of-loop breaks. We
  honour "finish the in-flight job." Safe.
- **Between `markProcessing` (processing) and `markProcessed`** — i.e. **mid-handler**:
  the handler runs to completion (handler can't be interrupted because our handler only
  sets a flag, never throws/exits), then `markProcessed` writes, then top-of-loop
  breaks. The row reaches a clean terminal state. Safe — this is exactly the case
  graceful shutdown is meant to protect.
- **During a retry/DLQ branch** (`retryOrFail` / `deadLetter`): those run to completion
  (they are the iteration's terminal action), then break. Safe.
- **During the outer infra backstop `sleep(INFRA_BACKOFF_SECONDS)`**: async signal
  interrupts the `sleep`; control returns, loop continues, top-of-loop break. Safe.

**→ Decision 2: Option C.** Flag-only handler + an explicit `if ($shouldStop) break;`
at the top of the loop and at the reserved idle tick. Every signal landing point above
resolves to a clean terminal state before exit. Optionally also express the outer loop
as `while (!$shouldStop)`; the explicit checks are the guarantee.

### Decision 3 — the interrupted-BLPOP problem (EINTR during clean shutdown)

When the async signal interrupts the blocking `BLPOP`, phpredis typically either
returns `false`/`null` or **throws `RedisException`**. `Queue::consume()` today maps a
timeout to `null`; an interrupt is *not* a timeout. If the resulting throw propagates
to the **outer** `try/catch (\Throwable)` infra backstop (`worker.php:163-169`), the
worker would `error_log()` the exception and `sleep(INFRA_BACKOFF_SECONDS)` — i.e.
**mistake a clean shutdown for an infra fault**, log spurious noise, and delay the exit.

#### Option E — in the outer catch, check `$shouldStop` first: if set, `break` (clean), else log+sleep
- Pros: smallest, most local change. The catch already exists; we add one guard at its
  top: `if ($shouldStop) { break; }` *before* `error_log + sleep`. A throw caused by the
  shutdown-interrupted BLPOP is then recognised as shutdown and exits silently; a genuine
  infra throw (flag not set) still logs+sleeps unchanged. Distinguishes the two by the
  one fact that actually differs — whether we asked to stop — rather than by trying to
  pattern-match phpredis's EINTR error message (brittle, version-dependent).
- Cons: relies on the flag being set before the throw is observed; with async signals the
  handler runs the instant the signal arrives (which is what interrupted the syscall), so
  the flag is reliably set by the time we reach the catch. Acceptable.

#### Option F — make `Queue::consume()` swallow EINTR and return a sentinel
- Pros: keeps the worker's catch untouched.
- Cons: pushes signal/shutdown awareness *into* the queue layer, which ADR 0012 kept free
  of processing policy; `consume()` would need to know about `$shouldStop`. Also EINTR
  detection from phpredis is version-dependent and brittle. Wrong layer. Rejected.

#### Option G — match the RedisException message/code for "interrupted system call"
- Cons: brittle string/`errno` matching across phpredis versions; the flag check (E) is a
  cleaner, intent-based discriminator. Rejected.

**→ Decision 3: Option E.** Add `if ($shouldStop) { break; }` as the first line of the
existing outer catch, ahead of `error_log + sleep`. The shutdown flag — not error
pattern-matching — distinguishes a shutdown-interrupted BLPOP from a real infra fault.

### Decision 4 — deployment knob: explicit `stop_grace_period`?

Worst-case time from SIGTERM to clean exit = (time to finish the in-flight handler) +
(at most one BLPOP window, ≈ up to 5s). NOTE (corrected during implementation, see the
correction below): empirically phpredis does NOT abort BLPOP when the signal arrives — it
runs the call to its timeout — so the idle case is up to BLPOP_TIMEOUT_SECONDS (≈5s), not
sub-second. Still well inside the 10s grace.

#### Option H — keep Docker's default 10s `stop_grace_period`
- Pros: handlers are expected to be quick (verify-light webhook work); 10s comfortably
  covers a finishing handler + the (now-interrupted) BLPOP. No compose change, no new
  knob to explain to a junior maintainer. If a handler ever legitimately exceeds 10s it
  is a design smell to fix, not to paper over with a longer grace.
- Cons: a pathologically slow handler could still be SIGKILLed at 10s. Acceptable: such a
  handler is the real bug; the stuck-`processing` reaper (out of scope) is the safety net.

#### Option I — set an explicit `stop_grace_period: 30s` for headroom
- Pros: documents the intent in compose; more slack for slow handlers.
- Cons: masks slow handlers; a longer wait on every shutdown if anything goes wrong; an
  extra knob for a junior to reason about, for a workload that should finish in well under
  10s. Premature.

**→ Decision 4: Option H.** Keep the default 10s `stop_grace_period`. Document the
worst-case (handler runtime + ≤5s BLPOP) in the worker docblock so the number is
explainable. Revisit only if a real handler approaches 10s.

#### Correction during implementation — `docker-compose.yml` IS edited (STOPSIGNAL gotcha)

D4 originally concluded "`docker-compose.yml` is not edited by T2.5". **Empirical verification
overturned that.** The `php:8.3-fpm` base image sets `STOPSIGNAL SIGQUIT` (the graceful signal
for php-fpm), and the worker image inherits it (`docker inspect … StopSignal` = `SIGQUIT`). So
`docker compose stop` / `down` send **SIGQUIT, not SIGTERM** — which the worker did not handle —
and the worker was SIGKILLed after the full 10s grace (observed: exit 137, 10.6s). Two fixes,
both applied:

1. **`docker-compose.yml`** — pin `stop_signal: SIGTERM` on the `worker` service, so the
   compose stop path sends the signal we handle. (The `php` FPM service keeps the inherited
   SIGQUIT, which is correct for php-fpm — hence a per-service override, not a Dockerfile
   `STOPSIGNAL` change to the shared image.)
2. **`worker.php`** — additionally register a handler for **SIGQUIT** alongside SIGTERM/SIGINT,
   so the worker also winds down gracefully if stopped via the raw image default (defence in
   depth, one extra line).

After both: `docker compose stop worker` → clean **exit 0 in ≈3s** with the shutdown log line.
This is the standard fix for the php-fpm-image-as-CLI-worker situation; the lesson is that the
inherited `STOPSIGNAL` must be checked, never assumed to be SIGTERM. (Files edited by T2.5 are
therefore Dockerfile + worker.php + docker-compose.yml.)

## Decision (summary)

- **D0: Option Z1** — add `pcntl` to `docker/php/Dockerfile`
  (`docker-php-ext-install … pcntl`) and rebuild the shared image. Unavoidable: `posix`
  alone cannot register a handler. The FPM `php` service gains a harmless unused extension.
- **D1: Option A** — `pcntl_async_signals(true)` + `pcntl_signal()` for SIGTERM, SIGINT
  **and SIGQUIT** (the last because of the inherited STOPSIGNAL, see the D4 correction), with
  a handler that **only sets `$shouldStop = true`**. (Implementation note: phpredis does not
  abort BLPOP on the signal, so the flag is acted on when BLPOP next returns, ≤5s — not
  near-instant as first assumed; still well inside the grace window.)
- **D2: Option C (as built)** — the loop condition `while (!$shouldStop)` is the load-bearing
  exit: a signal mid-job only sets the flag, the iteration finishes (handler + status write),
  then the condition is re-checked before the next job is ever claimed. The idle tick's
  `continue` re-evaluates that condition. Every signal landing point resolves to a clean
  terminal state before exit.
- **D3: Option E** — the **outer infra backstop** checks `$shouldStop` first and `break`s
  on a shutdown-interrupted BLPOP, instead of logging it as an infra fault and sleeping.
- **D4: Option H + correction** — keep Docker's default **10s** `stop_grace_period`, BUT (per
  the correction above) DO edit `docker-compose.yml` to pin `stop_signal: SIGTERM`, because the
  inherited image STOPSIGNAL is SIGQUIT. Files touched: Dockerfile + worker.php + compose.

## Consequences

- **Positive:**
  - `docker stop` / `compose down` lets the worker finish its current job and exit `0`,
    no SIGKILL, no row stranded in `processing` from a clean stop — satisfies the skill
    (`SKILL.md:68`) and the T2.5 goal.
  - Idle shutdown is near-instant (async signal interrupts BLPOP); busy shutdown waits
    only for the one in-flight handler.
  - The last ADR-0012 seam (the idle tick) closes **by addition** — the loop shape,
    `Job`, `Handler`, retry and DLQ paths are untouched; only a flag, two checks, a
    handler registration, and the outer-catch guard are added.
  - SIGINT support makes local `Ctrl-C` behave identically — pleasant for the dev loop.
  - Correctness never depended on this: at-least-once + idempotency + the guarded claim
    already tolerate a hard kill; T2.5 is purely cleanliness/operability on top.

- **Negative / debt:**
  - **The shared image now carries `pcntl` and must be rebuilt** (`docker compose build`);
    the FPM `php` service has an unused extension (harmless).
  - A handler that runs longer than the 10s grace can still be SIGKILLed; the stranded
    `processing` row is then the **out-of-scope stuck-`processing` reaper**'s job, not
    T2.5's. Documented, not solved here.
  - Async signal handling assumes the handler stays trivial (flag set only). If a future
    change makes the handler do real work, the safety argument must be revisited; called
    out so it is not violated silently.
  - This is graceful shutdown **only** — the **DLQ drain / re-queue path (T3.2)** and the
    **dashboard (T3.x)** are explicitly out of scope and untouched.
