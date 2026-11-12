---
name: most-important-details-for-kornsw-ujmwqueue
description: Project knowledge for the WordPress plugin KornSW UjmwQueue (WP-UjmwQueue) - a contract-agnostic, publicly reachable relay host for the UJMW async queue mode (callers use regular UJMW endpoints incl. the long-running protocol, processors pull via '<route>/_queue'). Covers the upstream specification, fixed decisions, protocol invariants, concurrency model, testing (php-wasm, WordPress Playground) and pitfalls. Load for ANY work in this repository.
metadata:
  version: "2026-11-12"
---

# KornSW UjmwQueue - Project Skill

## Status

Initial version implemented 2026-11-11/12: caller side (POST `?wait` / sync, GET poll, DELETE cancel, endpoint info), processor side (`_queue` info, dequeue, heartbeat, return, completion with `dequeueNext`), access keys, admin page, housekeeping. Second iteration 2026-11-12 (schema v3 incl. the follow-up: optional `&capacity` on `_queue` GET / POST with a load bar, configurable yellow threshold `processorSilentAfterSec`, default 180 s):
- processor instances `<agent>#<instance>` with LED status;
- two-stage locks (drain / kill) per instance and endpoint;
- `No processor available` (immediate or after `incomingCallQueueWaitTimeoutSec`);
- email monitoring;
- retention of instances, processors and endpoints;
- tabbed admin UI with a 1-second AJAX refresh;
- pretty-printed info JSON;
- optional `Bearer ` prefix;
- in-process client API `Api\QueueApi` (`doc/api.md`).

Next steps: `doc/work/backlog.md`.

## Upstream specification (authoritative for the wire protocol)

Repository `UnifiedJsonMessageWrapper` (SmartStandards, local folder `(SmartStandards) UJMW`), **branch `develop`**:

- `doc/epics/epic-async-queue-mode.md` - `_queue` protocol, policies (Q-*), defaults. Section 16 names this plugin as a later deliverable.
- `doc/epics/epic-long-running-operations.md` - caller protocol (`wait`, `asyncId`, `execState`, `pollNbf`, `pollingSecret`, callbacks `<name>Invokes` with `#`).
- `doc/decisions/decision-http-verbs-for-execution-control.md`
- `.agents/skills/ujmw/SKILL.md` - general wire protocol.
- `dotnet/src/UJMW.DynamicClient/UjmwAsyncProtocol.cs` - reference implementation of polling hash, secrets, Fibonacci poll delay; `Protocol/PollDelay.php` and `PollingAuth.php` are 1:1 ports. Keep them in sync when the epic changes.

## Fixed decisions (user, 2026-11-11)

- Project language **English** (`doc/decisions/decision-project-language-english.md`).
- Queues are created on the first processor contact; callers on unknown routes get 404; methods are learned, never enforced (`doc/decisions/decision-contract-agnostic-learning.md`).
- Own URL prefix (default `ujmw`) via `do_parse_request`, not the WP REST API.
- Access keys for both roles with route patterns, hashes only (`doc/decisions/decision-access-keys.md`).
- Identity: slug `kornsw-ujmwqueue`, namespace `KornSW\UjmwQueue`, prefix `kornsw_uq_`, constants `KORNSW_UQ_*`, tables `wp_kornsw_uq_queues|entries|terminations`. WordPress 7.1, PHP 8.4. Guideline `wordpress-plugin-development-guidelines` (AI-SKILLS-SMART) applies in full.

## Invariants

- Payloads are opaque: never decode-and-re-encode a passed-through request (`RawJson`); add members to responses via `Json::addMembers` (splices unless a name collides).
- One entry serves both sides: `asyncId` == `requestId`, but **separate secrets** (`caller_secret`, `processor_secret`); `withdrawn_secret` keeps the secret of a withdrawn allocation so late heartbeats get `Withdrawn - dequeue confirmation missed`.
- Every state change goes through `QueueService::mutate()` / `EntryRepository::tryUpdate()` (optimistic concurrency on `row_version`). Never write state columns directly.
- Housekeeping runs on traffic (no cron), at most every 2 s per request; anything reporting a state to a caller must run it **before** loading the entry (bug fixed 2026-11-12 in `CallerEndpoint::poll`).
- SQL must stay portable to the SQLite driver of WordPress Playground: no `GREATEST`, no `INSERT IGNORE`, no `DELETE ... ORDER BY/LIMIT`.
- No ternaries, explicit `if`/`else` (guideline section 5, also in `assets/admin.js`); files UTF-8 without BOM.
- Availability and locks: `doc/decisions/decision-processor-availability-and-locks.md`.
  - A kill sets the entry terminal **with** `cancel_requested = 1` and keeps `processor_secret`. The heartbeat then answers `cancellationRequested: true` without a fault, and `release` accepts the confirmation. Do not "clean up" the secret.
  - The caller-side logic lives in `Relay/CallerOperations` (shared by HTTP and `Api\QueueApi`). Never duplicate it in an endpoint.
  - Admin lock toggles carry the `expected` displayed state, so a stale click must not escalate drain to kill.

## Testing

- Standalone (WordPress-free classes): `cd tests/php-wasm && npm install` once, then `node tests/php-wasm/run.mjs tests/run-tests.php` and `node tests/php-wasm/lint.mjs` (no native PHP on the dev machine).
- End-to-end: `tests/e2e/relay-e2e.py` against a **fresh** WordPress Playground started with `tests/e2e/blueprint.json` (instructions in the script header). Also mount `tests/e2e/helper-plugin` (TEST ONLY: admin via header `X-Uq-Test-Admin`, in-process API via `/?uqtest=`). 63 checks passed 2026-11-12. The Playground CLI emits a harmless `EPERM ... stale Playground temp dirs` warning on Windows.
- Pitfalls: Playground requests take 2-3 s each, so the blueprint raises the confirmation / lease / sync timeouts; the blueprint must set `preferredVersions` or Playground runs PHP 8.3 and the activation fails; on Windows use `--mount-dir` (a `C:` path breaks `--mount`); stop the Playground server only by its own PID.
