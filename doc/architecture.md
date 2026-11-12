# Architecture

## Overview

```text
Caller (unchanged UJMW client)                         Processor (QueueProcessor<T> / UjmwQueueClient / any stack)
  │ POST <route>/<Method>[?wait]  GET / DELETE poll        │ GET  <route>/_queue?dequeue=<filter>
  ▼                                                        │ PUT / DELETE / POST <route>/_queue?requestId&pollingSalt&pollingHash
┌──────────────────────────────────────────────────────────▼───────────────────────────────┐
│ WordPress  ── filter 'do_parse_request' ── RelayRouter (prefix 'ujmw')                    │
│                                             │                                              │
│        CallerEndpoint  ◄────────────────────┴───────────────────►  ProcessorEndpoint      │
│              │            AccessGuard (keys: role + route pattern)        │                │
│              └──────────────────────►  QueueService  ◄────────────────────┘                │
│                                          │  (IUjmwQueuingService + IUjmwQueue in PHP)      │
│                     QueueRepository  EntryRepository  TerminationLog  QueueStatistics     │
│                          │                 │               │                               │
│                 wp_kornsw_uq_queues  wp_kornsw_uq_entries  wp_kornsw_uq_terminations       │
└───────────────────────────────────────────────────────────────────────────────────────────┘
Settings > UJMW Queue ── AdminPage ── Settings, KeyStore, QueueRepository
```

## Components

| Class (`kornsw-ujmwqueue/src/…`) | Responsibility |
| --- | --- |
| `Plugin` | Composition root, activation (tables, options), boot. |
| `Http/RelayRouter` | Intercepts `<site>/<prefix>/…` in `do_parse_request`, resolves the path against the learned queues, sends the response and ends the request. |
| `Http/RelayPath`, `HttpRequest`, `HttpResponse` | Path parsing (route segments, `_queue`), raw query parsing (WordPress slashes `$_GET`), JSON responses with `Cache-Control: no-store`. |
| `Relay/CallerEndpoint` | Regular-looking UJMW endpoint incl. long-running protocol; endpoint info `GET <route>`. |
| `Relay/ProcessorEndpoint` | `_queue` wire protocol (info, dequeue, heartbeat, return, completion). |
| `Queue/QueueService` | Queue logic: enqueue, drain callbacks, cancel, long-poll wait, dequeue, heartbeat, release, complete, housekeeping. |
| `Queue/*Repository`, `TerminationLog`, `QueueStatistics` | Persistence and statistics. |
| `Protocol/*` | WordPress-free protocol primitives: polling hash, async id, method filter, poll delay, requeue position, JSON pass-through, callback buffer. Tested standalone. |
| `Security/KeyStore`, `AccessGuard`, `RoutePattern` | Access keys (hashes only), role and route check. |
| `Admin/AdminPage` | Admin UI. |

## Key design points

- **Contract-agnostic pass-through.** Request JSON is stored as received and embedded byte-for-byte into the dequeue response (`RawJson`). The final processor response is stored as delivered; the relay only adds `async` (and remaining callback `...Invokes`), by splicing members into the raw JSON object. Only on a name collision is the object re-encoded.
- **Long-running detection per call.** The relay does not know which method declares a `CancellationToken`; it treats a call as long-running if the caller sends `?wait` (the .NET dynamic client does so exactly for long-running methods).
- **Single async layer.** Unlike the .NET host (outer operation store + inner queue entry), the relay uses one entry for both sides: `asyncId` (caller) and `requestId` (processor) are the same 53-bit id, but caller and processor have separate polling secrets. The caller therefore sees `execState 0` (queued) correctly.
- **Concurrency without shared memory.** Every state change uses optimistic concurrency (`row_version`); conflicting changes are re-evaluated on the reloaded row. Dequeue allocates by a conditional update, so two processors can never get the same entry.
- **FIFO with priority reinsertion.** One FIFO per queue across all methods (`position`, seconds since 2026-01-01 as `double`). Withdrawn allocations go to the head; releases use the Fibonacci index rule (0, 1, 2, 3, 5, 8, …, 55; the 11th release fails the entry).
- **Waiting = long-poll.** Blocking caller requests (`?wait`, synchronous calls, `DELETE ?wait`) re-read the entry every 100–500 ms. Each waiting request occupies a PHP worker; `maxWaitSec` limits this for long-running calls.
- **Housekeeping on traffic.** No cron: every request runs the housekeeping at most every 2 seconds (withdraw unconfirmed allocations, lease loss, outcome retention, statistics pruning).

## Data

| Table | Content |
| --- | --- |
| `wp_kornsw_uq_queues` | Learned queues: route, route key (lower case), learned methods, processors with last activity. |
| `wp_kornsw_uq_entries` | Entries: state, FIFO position, request / response JSON, caller / processor / withdrawn secret, callback buffer, timestamps (UTC ms), row version. Terminated entries are retained according to the outcome retention settings. |
| `wp_kornsw_uq_terminations` | Termination timestamps for the `pastXX` counters (pruned after 10 hours). |

Options: `kornsw_uq_settings`, `kornsw_uq_keys` (hashes), `kornsw_uq_db_version`.

## Security

- Every relay request except the optional anonymous statistics needs an access key with the matching role and route pattern. Keys are stored as SHA-256 hashes and shown once on creation.
- Caller polls / cancellations and processor heartbeats / returns / completions are additionally protected by the per-entry (caller) and per-allocation (processor) `pollingSecret` (`Base64Url(SHA-256(secret + salt))`). Wrong ids and wrong hashes are answered identically.
- The caller's `Authorization` header is consumed by the relay and **not** passed to the processor; identity information must travel in the sidechannel `_` if the processor needs it.
- Admin actions require `manage_options` and a nonce.
