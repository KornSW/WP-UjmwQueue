# In-process client API for other WordPress plugins

Other plugins on the same WordPress site can call queued endpoints **directly in PHP**, without an HTTP loopback request and without an access key. The API works on raw UJMW JSON: you send the request body an HTTP caller would send and get back the response body an HTTP caller would get.

Unlike HTTP callers (which get `404` on unknown endpoints), in-process clients **learn** endpoints: the first call creates the endpoint and its method name, so it shows up on the admin page and can be served as soon as a processor connects. Until a processor is available, calls are answered with `{"fault":"No processor available"}`.

## Getting the API

```php
use KornSW\UjmwQueue\Api\QueueApi;

// any time after 'plugins_loaded' (or earlier: get() boots the relay on demand)
if (class_exists(QueueApi::class)) {
  $api = QueueApi::get();
}

// alternatively, as soon as the relay is ready:
add_action('kornsw_uq_ready', function (QueueApi $api): void {
  // ...
});
```

## Methods

All methods return a JSON string. Invalid arguments (route, method name, request that is not a JSON object) throw `InvalidArgumentException`. Everything else, such as capacity, missing processor or timeout, is reported as `fault` in the returned JSON, exactly like on HTTP.

| Method | Corresponds to | Returns |
| --- | --- | --- |
| `invoke(string $route, string $method, string $requestJson): string` | `POST <route>/<method>` (regular call) | The processor's response, e.g. `{"return":42,"fault":null}`. The call blocks until the processor answers or `synchronousCallTimeoutSec` elapses (`{"fault":"Queue processing timeout"}`). |
| `invokeLongRunning(string $route, string $method, string $requestJson, float $waitSeconds = 0.0): string` | `POST <route>/<method>?wait=n` | The final response plus `"async":{"execState":2}` when it finished within the wait. Otherwise `{"fault":null,"async":{"asyncId","execState","pollNbf","cancellable","pollingSecret"}}`. |
| `poll(string $route, string $method, string $asyncId, string $pollingSecret, float $waitSeconds = 0.0): string` | `GET <route>/<method>?asyncId…` | The running state with drained callback invocations (`<name>Invokes`), or the final response plus `async`. With `waitSeconds` the call waits for the termination (limited by `maxWaitSec`). |
| `cancel(string $route, string $method, string $asyncId, string $pollingSecret, float $waitSeconds = 0.0): string` | `DELETE <route>/<method>?asyncId…&wait=n` | Same shape as `poll`. |
| `getEndpoints(): array` | (none) | `route => list of learned method names`. |

The polling secret is the `pollingSecret` from the first long-running response. In-process clients pass it directly; the API computes the polling hash.

## Example

```php
$api = \KornSW\UjmwQueue\Api\QueueApi::get();

$started = json_decode($api->invokeLongRunning('api/reports', 'RenderReport', '{"reportId":42}', 2.0));
if ($started->fault !== null) {
  // e.g. 'No processor available' or 'Queue capacity exceeded'
  return;
}
if (!isset($started->async->asyncId)) {
  // finished within the wait time: $started->return holds the result
  return;
}

// later (e.g. in a cron job or the next request)
$state = json_decode($api->poll('api/reports', 'RenderReport', $started->async->asyncId, $started->async->pollingSecret));
if ($state->async->execState === 2) {
  $result = $state->return;
}
```

## Notes

- Waiting (`invoke`, `waitSeconds`) blocks the current PHP request just like the HTTP variants. Prefer `invokeLongRunning` with a short wait, then `poll`.
- Endpoint locks, the setting "New endpoints are locked by default", capacity, the incoming call wait timeout and monitoring apply to in-process calls as well.
