# KornSW UjmwQueue

WordPress plugin that turns a WordPress site into a publicly reachable **relay host for the UJMW async queue mode**. Callers use regular-looking UJMW endpoints; processing units behind firewalls (outbound-only zones, desktop agents, containers without ingress) **pull** the calls via the well-known sub-route `_queue`, execute them and push the result back.

The relay is **contract-agnostic**: it does not know any contract. Routes are learned when the first processor connects, method names are learned from calls and processor filters, and request / response JSON is passed through unchanged (including the UJMW sidechannel `_`).

Status: 0.x - caller side incl. long-running protocol, processor side `_queue`, access keys, live admin tabs (processors, endpoints, locks, monitoring), retention, in-process API for other plugins. See [Quickstart](doc/quickstart.md).

## Motivation

The UJMW queue mode ([epic in the UJMW repository](https://github.com/SmartStandards/UnifiedJsonMessageWrapper/blob/develop/doc/epics/epic-async-queue-mode.md)) reverses the call direction of a contract: the caller pushes, the processor pulls. The .NET host implementation needs an ASP.NET Core server that knows the contract. This plugin offers the same wire protocol on any WordPress hosting - as a passive mediation point between callers and processors that never touches the payload.

## Example

```text
Caller     POST https://example.org/ujmw/api/reports/RenderReport?wait=10   {"reportId":42}
Processor  GET  https://example.org/ujmw/api/reports/_queue?processor=w1&dequeue=*
           PUT  .../_queue?processor=w1&requestId=...&pollingSalt=...&pollingHash=...   (heartbeat)
           POST .../_queue?processor=w1&requestId=...&pollingSalt=...&pollingHash=...   {"response":{"return":"..."}}
Caller     GET  https://example.org/ujmw/api/reports/RenderReport?asyncId=...&pollingSalt=...&pollingHash=...
```

## Documentation

- [Requirements](doc/requirements.md)
- [Architecture](doc/architecture.md)
- [Quickstart](doc/quickstart.md)
- [In-process client API for other plugins](doc/api.md)
- [Changelog](doc/changelog.md)
- [Decisions](doc/decisions/)
- [Work: backlog](doc/work/backlog.md)

## License

GPL v3, see [LICENSE](LICENSE).
