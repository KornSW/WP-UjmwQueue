# Decision: Contract-agnostic relay, queues learned on processor contact

**Date:** 2026-11-11

## Context

The .NET queue host (`AddQueueFor<T>`) knows the contract: it emits the controller, knows which methods are long-running and rejects unknown methods. A WordPress relay cannot load .NET contracts and should stay a passive mediation point.

## Decision

1. **Queues are created by the first authenticated processor contact** with `<route>/_queue` (any verb except the anonymous info). Callers on a route without a queue receive `404` immediately instead of waiting for a processor that never comes.
2. **Methods are learned** from caller requests and from the names in processor filters (`dequeue`, `dequeueNext`). The list is informational (admin page, `GET <route>`); unknown methods are never rejected - the processor answers them with a fault.
3. **Long-running is decided per call:** a call with `?wait` follows the long-running protocol, a call without it blocks synchronously.
4. **Payloads are opaque:** request JSON is passed through byte-for-byte, responses are stored as delivered.
5. **URL:** own prefix (`<site>/ujmw/…`, configurable), intercepted in `do_parse_request` instead of the WordPress REST API (no `wp-json` path, no REST envelope, less overhead).

## Alternatives

- Callers create queues implicitly: rejected, any caller key could create garbage queues.
- Queues must be approved in the admin first: rejected, contradicts "learning on demand"; access is already controlled by keys with route patterns.
- WordPress REST API namespace: rejected, see 5.

## Consequences

- A caller cannot be told that a method does not exist before a processor dequeues the call.
- The relay cannot answer the regular UJMW endpoint info like a .NET host (no contract identity / assembly version); `GET <route>` returns a relay-specific document (see [backlog](../work/backlog.md)).
