# Decision: processor identity, availability and administrative locks

Date: 2026-11-12 (requested by the project owner, second iteration)

## Context

Operators need to see which processor agents are alive, stop them gracefully or hard, and callers should not wait for a queue that nobody serves.

## Decision

- **Identity:** `?processor=<agent>#<instance>`. The agent name (before the first `#`) is stable per installation, the discriminator is variable per process. Names without `#` get the discriminator `MM-dd#HH-mm-ss` (site time zone) of the first contact. After 60 minutes without contact, the next contact creates a new instance.
- **Liveness** derives from the last `_queue` request of any kind: idle / busy (active jobs) within 5 minutes, silent (yellow) from 5 minutes, offline from 60 minutes.
- **Availability:** a call is accepted only if the endpoint is unlocked and an unlocked, non-silent instance has polled it within the last hour. Otherwise it is rejected immediately with `No processor available`. Queued calls that are not picked up within `incomingCallQueueWaitTimeoutSec` fail with the same fault.
- **Locks** have two stages and exist per instance and per endpoint. *Drain* answers dequeues as if the queue was empty. *Kill* also aborts the running jobs: the caller gets a fault immediately, and the processor gets `cancellationRequested` with its next heartbeat. No new wire protocol element is needed. The admin UI sends the state that was clicked on, so a click on a state that has changed in the meantime is ignored.
- **New endpoints locked by default** (optional security setting): learning still happens, but the endpoint starts with drain level and the administrator is notified.

## Consequences

- Callers fail fast instead of waiting for the synchronous timeout when no processor is running.
- Shortly after a processor restart (new instance, still green), the old instance keeps counting as available for up to 5 minutes. This is harmless because the new instance serves the calls.
- A processor that only sends heartbeats for a long job stays green and keeps its endpoint available.
