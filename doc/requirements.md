# Requirements

## Project goal

KornSW UjmwQueue is a WordPress-hosted relay for the **UJMW async queue mode** (pull-based processing via `_queue`). It implements the wire protocol of the UJMW epic [Async Queue Mode](https://github.com/SmartStandards/UnifiedJsonMessageWrapper/blob/develop/doc/epics/epic-async-queue-mode.md) (branch `develop`) and the caller-side long-running protocol of the epic [Long-Running Operations](https://github.com/SmartStandards/UnifiedJsonMessageWrapper/blob/develop/doc/epics/epic-long-running-operations.md), without knowing any contract.

The epic itself lists "WordPress plugin acting as queue host" as a later deliverable ("a PHP implementation of the `_queue` wire protocol as a publicly reachable relay host"); this repository is that deliverable.

## Target group

Operators who want to reach processing units that cannot be called (security zones, outbound-only networks, desktop agents, dynamic addresses) from UJMW callers, using an ordinary WordPress hosting as the public meeting point.

## Constraints

- Project language: **English** for plugin description, UI texts, `README.md` and `/doc` (decision 2026-11-11, see [decision](decisions/decision-project-language-english.md)).
- New plugin according to `wordpress-plugin-development-guidelines` (AI-SKILLS-SMART): PSR-4 classes, release via the GitHub Actions pipeline. Minimum versions: WordPress 7.1, PHP 8.4.
- The relay is a passive mediation point: it never interprets, validates or transforms the UJMW payloads beyond what the protocol requires.
- PHP has no process-wide memory: queue entries are kept in own database tables, short-term only (no durability promise, see the epic's non-goals).
- Data (queues, keys, settings) is kept on deactivation and uninstall; deleting is an explicit admin action only.

## Functional requirements

| No. | Requirement | Status |
| --- | --- | --- |
| R1 | Requests below `<site>/<prefix>/` (default prefix `ujmw`) are handled by the relay before WordPress parses them. | implemented |
| R2 | **Learning:** a queue (route) is created on the first authenticated processor contact with `<route>/_queue`. Callers on a route without a queue receive `404`. Method names are learned from caller requests and from processor filters. | implemented |
| R3 | **Caller side:** `POST <route>/<Method>` enqueues the call. With `?wait=n` (long-running method) the response returns after at most `n` seconds (limited by `maxWaitSec`) either with the final result (no `asyncId`) or with `async` (`asyncId`, `execState`, `pollNbf`, `cancellable`, `pollingSecret`). Without `wait` the request blocks until the result is available or `synchronousCallTimeoutSec` elapsed (fault `Queue processing timeout`, entry gets cancelled). | implemented |
| R4 | `GET <route>/<Method>?asyncId&pollingSalt&pollingHash` polls, `DELETE ...&wait` cancels. Buffered callback invocations are drained with every poll (single consumer). | implemented |
| R5 | **Processor side** `<route>/_queue`: `GET` info (statistics), `GET ?dequeue=<filter>` (two-phase dequeue with confirmation timeout), `PUT` heartbeat (with callback invocations, back pressure), `DELETE` return / confirm cancellation (release rule with Fibonacci reinsertion and limit 10), `POST` completion with optional `dequeueNext`. | implemented |
| R6 | Lease loss: default policy "no redelivery", fault `processor lost`; overridable via the filter `kornsw_uq_redelivery_policy`. | implemented |
| R7 | **Access keys:** every key has a role (`caller` / `processor`) and route patterns; sent as `Authorization: Bearer <key>` or `X-Ujmw-Queue-Key`. Only hashes are stored. The statistics endpoint can optionally be opened for anonymous access. | implemented |
| R8 | Admin page *Settings > UJMW Queue*: learned queues (methods, counters, processors), key management, settings (prefix, timings, limits). | implemented |
| R9 | Defaults follow the epic: queue capacity 1000, dequeue confirmation 2 s, heartbeat 3 s, lease loss 60 s, synchronous timeout 100 s, outcome retention 10 s / 300 s / 100. | implemented |

Later / open: see [backlog](work/backlog.md).
