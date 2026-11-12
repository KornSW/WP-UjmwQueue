# Change log

## Upcoming Changes

*(none)*

## v 0.1.2
released **2026-11-12**, including:
 - Merge branch 'master' of https://github.com/KornSW/WP-UjmwQueue
 - iteration 3


## v 0.1.1
released **2026-11-12**, including:
 - Access keys: the `Bearer ` prefix of the `Authorization` header is optional.
 - Processor instances: `?processor=<agent>#<instance>`; names without `#` get a synthetic discriminator `MM-dd#HH-mm-ss`.
 - Admin page with the tabs Processors (live LEDs, active jobs, `[CANCEL]`), Endpoints (methods, waiting / active jobs, agents, monitoring switch), Security and Settings; the live tabs refresh every second.
 - Two-stage lock (drain / kill) per processor instance and per endpoint; new faults `Processor-Lock applied during execution`, `Endpoint-Lock applied during execution`, `Cancelled by administrator`.
 - New fault `No processor available`: immediately without an available processor, otherwise after the new setting `incomingCallQueueWaitTimeoutSec` (default 60 s).
 - Email notifications (monitoring per endpoint: overflow, no processor; new endpoints locked by default with approval notification); setting *Notification email*.
 - Retention of processor instances (default on), processors (30 days) and endpoints (200 days / 1 day), the last two off by default.
 - `GET <route>/_queue` and `GET <route>` answer with pretty-printed JSON.
 - In-process client API for other plugins (`KornSW\UjmwQueue\Api\QueueApi`, action `kornsw_uq_ready`), see `doc/api.md`.
 - Processors may send `&capacity=<max jobs>` with dequeue (GET) and completion (POST), otherwise -1 is stored. The Processors tab shows a load bar for capacities > 1.
 - New setting `processorSilentAfterSec` (default 180 s, previously fixed at 5 minutes): the threshold for the yellow LED and for availability.
 - The legend on the Processors tab describes the LED colors in words, without animated LEDs.
 - Database schema version 3: new tables `kornsw_uq_processors` and `kornsw_uq_processor_queues`, column `capacity`. The upgrade runs automatically.


## v 0.1.0
released **2026-11-12**, including:
 - initial commit
