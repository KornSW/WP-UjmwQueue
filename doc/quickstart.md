# Quickstart

1. Install and activate the plugin (ZIP from the GitHub release or the folder `kornsw-ujmwqueue`).
2. Open *Settings > UJMW Queue*, tab **Security**, and create two keys:
   - role `processor`, routes e.g. `api/reports*`
   - role `caller`, routes e.g. `api/reports*`

   Copy each key when it is shown - it is displayed only once.
3. Start a processor against `https://<site>/ujmw/<route>/_queue`, e.g. with the .NET `UjmwQueueClient` (URL getter returns `https://<site>/ujmw/<route>`, auth header getter returns `Bearer <processor key>`; the `Bearer ` prefix is optional). Name the processor `?processor=<agent>#<instance>` (e.g. `reportAgent#host1-pid4711`); without `#` the relay assigns a discriminator from the time of the first contact. Its first dequeue creates the endpoint; it appears on the tabs **Processors** and **Endpoints**.
4. Point the caller's UJMW client to `https://<site>/ujmw/<route>` with `Bearer <caller key>`. Nothing else changes for the caller. Calls are rejected immediately with `No processor available` while no processor polls the endpoint.
5. Optional: switch on **Monitoring** for the endpoint (email on overflow or when no processor is available), and on the tab **Security** lock new endpoints by default. Other WordPress plugins can call endpoints without HTTP, see [api.md](api.md).

## Operating

- **Processors** tab: green LED = polling, flickering = busy, yellow = no contact for 5 min, off = 60 min. The "no entry" icon behind an instance drains it (orange, no new jobs), a second click kills its running jobs, a click on red unlocks. `[CANCEL]` aborts the running jobs of one instance.
- **Endpoints** tab: the same lock per endpoint (processors keep running, they just get no jobs of this endpoint). New endpoints that are locked by default are released here.

## Calling the protocol by hand

```bash
BASE=https://example.org/ujmw/api/reports

# processor: learn the queue / dequeue
curl -H "Authorization: Bearer $PROC" "$BASE/_queue?processor=w1&dequeue=*"

# caller: long-running call, returns after at most 2 seconds
curl -H "Authorization: Bearer $CALLER" -d '{"reportId":42}' "$BASE/RenderReport?wait=2"
```

`pollingHash` for `GET` / `DELETE` / `PUT` / `POST` with `asyncId` or `requestId`: `Base64Url(SHA-256(UTF-8(pollingSecret + pollingSalt)))` without padding, with a fresh 6-character `pollingSalt` per request.

## Hosting notes

- Hosts that strip the `Authorization` header (some CGI/FastCGI setups): send the key as `X-Ujmw-Queue-Key: <key>`.
- Every waiting request (`?wait`, synchronous calls) occupies a PHP worker for its duration. Keep `maxWaitSec` small (default 30) and prefer long-running methods (with `CancellationToken`) for queue mode.
- Synchronous calls are limited by `synchronousCallTimeoutSec` (default 100) and, if the host does not allow raising it, by PHP's `max_execution_time`.

## Development

```bash
cd tests/php-wasm && npm install        # once; no native PHP needed
node tests/php-wasm/run.mjs tests/run-tests.php
node tests/php-wasm/lint.mjs
```
