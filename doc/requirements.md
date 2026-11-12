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
| R2 | **Learning:** a queue (route) is created on the first authenticated processor contact with `<route>/_queue` (or the first in-process client call, R18). Callers on a route without a queue receive `404`. Method names are learned from caller requests and from processor filters. | implemented |
| R3 | **Caller side:** `POST <route>/<Method>` enqueues the call. With `?wait=n` (long-running method) the response returns after at most `n` seconds (limited by `maxWaitSec`) either with the final result (no `asyncId`) or with `async` (`asyncId`, `execState`, `pollNbf`, `cancellable`, `pollingSecret`). Without `wait` the request blocks until the result is available or `synchronousCallTimeoutSec` elapsed (fault `Queue processing timeout`, entry gets cancelled). | implemented |
| R4 | `GET <route>/<Method>?asyncId&pollingSalt&pollingHash` polls, `DELETE ...&wait` cancels. Buffered callback invocations are drained with every poll (single consumer). | implemented |
| R5 | **Processor side** `<route>/_queue`: `GET` info (statistics), `GET ?dequeue=<filter>` (two-phase dequeue with confirmation timeout), `PUT` heartbeat (with callback invocations, back pressure), `DELETE` return / confirm cancellation (release rule with Fibonacci reinsertion and limit 10), `POST` completion with optional `dequeueNext`. | implemented |
| R6 | Lease loss: default policy "no redelivery", fault `processor lost`; overridable via the filter `kornsw_uq_redelivery_policy`. | implemented |
| R7 | **Access keys:** every key has a role (`caller` / `processor`) and route patterns; sent as `Authorization: Bearer <key>`, `Authorization: <key>` (the `Bearer` prefix is optional) or `X-Ujmw-Queue-Key`. Only hashes are stored. The statistics endpoint can optionally be opened for anonymous access. | implemented |
| R8 | Admin page *Settings > UJMW Queue* with the tabs **Processors**, **Endpoints**, **Security** and **Settings**. The Processors and Endpoints tabs refresh every second via JavaScript. | implemented |
| R9 | Defaults follow the epic: queue capacity 1000, dequeue confirmation 2 s, heartbeat 3 s, lease loss 60 s, synchronous timeout 100 s, outcome retention 10 s / 300 s / 100. | implemented |
| R10 | **Processor identity:** `?processor=<agent>#<instance>`. The part before the first `#` identifies a stable agent installation, the rest is a variable instance discriminator. Names without `#` get the synthetic discriminator `MM-dd#HH-mm-ss`: the time of the first contact, or of the first contact after being offline. | implemented |
| R11 | **Tab Processors:** one row per agent. Column 1 is the agent name. Column 2 lists one line per instance: a status LED (green = idle, flickering green = busy, yellow = no contact for `processorSilentAfterSec`, default 3 min, off = no contact for 60 min), the discriminator, the lock icon, the number of active jobs `(n)`, a load bar `active / capacity` if the instance announces a capacity > 1, and a `[CANCEL]` link. The legend describes the colors in words only. Column 3 lists the endpoints the agent has polled, in alphabetical order. | implemented |
| R12 | **Two-stage lock** ("no entry" icon) per instance and per endpoint. Grey: open. First click: drain, so dequeue answers as if the queue was empty; the icon is orange while jobs are still running and red once none are. Click on orange: kill, so callers of running jobs get the fault `Processor-Lock applied during execution` (endpoint: `Endpoint-Lock applied during execution`) and the processor gets `cancellationRequested` with its next `PUT`. Click on red: unlock. An endpoint lock stops only the jobs of that endpoint; its processors keep running. `[CANCEL]` aborts the running jobs of one instance in the same way (fault `Cancelled by administrator`) without locking it. | implemented |
| R13 | **Tab Endpoints:** endpoint with lock icon, learned methods (scrollable cell), jobs `waiting / active` (waiting in blue, or red if no processor is available; active in dark green), agents that have polled the endpoint, and a monitoring switch. | implemented |
| R14 | **Availability:** a call is rejected immediately with `No processor available` if the endpoint is locked or no unlocked, green instance (contact within `processorSilentAfterSec`, default 3 min) has polled it within the last hour. Queued calls not picked up within `incomingCallQueueWaitTimeoutSec` (default 60 s) fail with the same fault. | implemented |
| R15 | **Notifications** by email (setting *Notification email*, default: the site admin email), at most once per hour, kind and endpoint: queue overflow and calls without an available processor, for endpoints with monitoring switched on. Security setting *New endpoints are locked by default*: a new endpoint starts locked and the administrator is notified. | implemented |
| R16 | **Retention** (tab Settings): instance retention (default on) deletes instances offline for more than one day if another instance of the same agent is alive. Processor retention (default off) deletes agents offline for *x* days (default 30). Endpoint retention (default off) deletes endpoints without known processors after 200 days (had calls) or 1 day (only announced), counted from the last processor contact. | implemented |
| R17 | `GET <route>/_queue` and `GET <route>` answer with pretty-printed JSON (readable in a browser). | implemented |
| R18 | **In-process client API** for other WordPress plugins (`KornSW\UjmwQueue\Api\QueueApi`, see [api.md](api.md)): raw JSON level, no HTTP loopback, no access key. In-process clients learn new endpoints and methods. | implemented |

Later / open: see [backlog](work/backlog.md).
