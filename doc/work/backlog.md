# Backlog

1. Live acceptance on a real hosting with the .NET `QueueProcessor<T>` / `UjmwQueueClient` and the .NET dynamic client as soon as the UJMW queue epic is implemented (branch `develop` of UJMW): long-running and synchronous calls, callbacks, cancellation, release limit. Check the 2 s dequeue confirmation against the real request latency of the host.
2. `GET <route>` endpoint info: align with the info format of the .NET dynamic controller if the dynamic client relies on it (version check); possibly let processors announce contract identity / version.
3. Option to pass the caller's `Authorization` header (or a configurable header set) to the processor inside the entry (end-to-end authentication), and HTTP-header sidechannels.
4. Maximum age of queued entries whose caller stopped polling (today they stay queued until a processor takes them or the queue is deleted).
5. CORS for browser callers (UShell `CreateServiceProxy`).
6. Load behavior: every waiting request occupies a PHP worker; consider a server-side cap of concurrently waiting requests per queue.
7. Translation readiness (`__()` with text domain).
