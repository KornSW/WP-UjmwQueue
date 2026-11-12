# Decision: Access keys for callers and processors

**Date:** 2026-11-11

## Context

In the .NET host the regular UJMW auth hooks authorize callers and processors. The relay is publicly reachable and needs its own access control.

## Decision

- The relay checks keys itself. Every key has a **role** (`caller` or `processor`) and **route patterns** (comma-separated, `*` wildcard, case-insensitive). Because queues only come into existence on processor contact, keys are bound to patterns, not to existing queues.
- Transport: `Authorization: Bearer <key>`; fallback header `X-Ujmw-Queue-Key` for hosts that strip `Authorization`.
- Only SHA-256 hashes are stored (option `kornsw_uq_keys`); the plain key is displayed once after creation.
- The statistics endpoint `GET <route>/_queue` accepts caller and processor keys; anonymous access can be enabled in the settings.
- Polling secrets (`pollingSalt` / `pollingHash`) protect entries in addition to the keys, exactly as in the epic.

## Alternatives

- Pass the caller's `Authorization` header through to the processor (end-to-end authentication): not chosen; can be added later as an option (see [backlog](../work/backlog.md)).
- Token validation via WP-AuthTokenHandling: not chosen for the first version.
- Anonymous relay: rejected for a publicly reachable host.

## Consequences

The processor does not see the caller's identity; if needed it must travel in the UJMW sidechannel `_`.
