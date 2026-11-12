<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Relay;

use KornSW\UjmwQueue\Http\HttpRequest;
use KornSW\UjmwQueue\Http\HttpResponse;
use KornSW\UjmwQueue\Http\RelayPath;
use KornSW\UjmwQueue\Protocol\Json;
use KornSW\UjmwQueue\Protocol\MethodFilter;
use KornSW\UjmwQueue\Queue\Entry;
use KornSW\UjmwQueue\Queue\QueueRecord;
use KornSW\UjmwQueue\Queue\QueueRepository;
use KornSW\UjmwQueue\Queue\QueueService;
use KornSW\UjmwQueue\Security\AccessGuard;
use KornSW\UjmwQueue\Security\KeyStore;

/**
 * Caller side: a regular-looking UJMW endpoint '<route>/<Method>' including the long-running protocol
 * ('POST ?wait' / 'GET' poll / 'DELETE' cancel). The caller does not know that a queue exists.
 */
final class CallerEndpoint {

  public function __construct(
    private readonly QueueRepository $queues,
    private readonly QueueService $queueService,
    private readonly CallerOperations $operations,
    private readonly AccessGuard $accessGuard
  ) {
  }

  /**
   * Handles '<route>/<Method>'.
   */
  public function handle(HttpRequest $request, string $route, string $methodName): HttpResponse {
    $denied = $this->accessGuard->authorize($request, RelayPath::toRouteKey($route), [KeyStore::ROLE_CALLER]);
    if ($denied !== null) {
      return $denied;
    }
    $queue = $this->queues->findByRoute($route);
    if ($queue === null) {
      return HttpResponse::fault("Unknown endpoint: no processor has connected to '" . $route . "' yet", 404);
    }
    if (!MethodFilter::isValidMethodName($methodName)) {
      return HttpResponse::fault('Invalid method name', 400);
    }

    if ($request->method === 'POST') {
      return $this->initiate($request, $queue, $methodName);
    }
    if ($request->method === 'GET') {
      return $this->poll($request, $queue, $methodName);
    }
    if ($request->method === 'DELETE') {
      return $this->cancel($request, $queue, $methodName);
    }
    return HttpResponse::fault('Method not allowed', 405)->withHeader('Allow', 'POST, GET, DELETE');
  }

  /**
   * Handles 'GET <route>' (endpoint information of a learned queue).
   */
  public function info(HttpRequest $request, QueueRecord $queue): HttpResponse {
    $denied = $this->accessGuard->authorize($request, $queue->routeKey, [KeyStore::ROLE_CALLER, KeyStore::ROLE_PROCESSOR]);
    if ($denied !== null) {
      return $denied;
    }
    return HttpResponse::json(Json::encodePretty([
      'route' => $queue->route,
      'methods' => $queue->methods,
      'relay' => 'kornsw-ujmwqueue',
      'fault' => null,
    ]));
  }

  /**
   * POST: enqueues the call ('wait' = long-running method).
   */
  private function initiate(HttpRequest $request, QueueRecord $queue, string $methodName): HttpResponse {
    if ($request->isBodyTooLarge()) {
      return HttpResponse::fault('Request body too large', 413);
    }
    $requestJson = trim($request->getBody());
    if ($requestJson === '') {
      $requestJson = '{}';
    }
    if (Json::decodeObject($requestJson) === null) {
      return HttpResponse::fault('Request body must be a JSON object', 400);
    }
    $isLongRunning = $request->hasQuery('wait');
    return HttpResponse::json($this->operations->initiate($queue, $methodName, $requestJson, $isLongRunning, $this->getWaitSeconds($request)));
  }

  /**
   * GET: polls a long-running call ('asyncId', 'pollingSalt', 'pollingHash').
   */
  private function poll(HttpRequest $request, QueueRecord $queue, string $methodName): HttpResponse {
    if (!$request->hasQuery('asyncId')) {
      return HttpResponse::fault("Missing 'asyncId' (regular calls use POST)", 400);
    }
    // housekeeping first, so that a lost lease or a withdrawn allocation is already visible in this poll
    $this->queueService->runHousekeeping(false);
    $entry = $this->findEntry($request, $queue, $methodName);
    if ($entry === null) {
      return HttpResponse::fault(QueueService::FAULT_WRONG_ASYNC);
    }
    return HttpResponse::json($this->operations->poll($entry, 0.0));
  }

  /**
   * DELETE: requests the cancellation of a long-running call and optionally waits for its termination.
   */
  private function cancel(HttpRequest $request, QueueRecord $queue, string $methodName): HttpResponse {
    $entry = $this->findEntry($request, $queue, $methodName);
    if ($entry === null) {
      return HttpResponse::fault(QueueService::FAULT_WRONG_ASYNC);
    }
    return HttpResponse::json($this->operations->cancel($entry, $this->getWaitSeconds($request)));
  }

  /**
   * Authenticates the caller's poll / cancel request.
   */
  private function findEntry(HttpRequest $request, QueueRecord $queue, string $methodName): ?Entry {
    return $this->queueService->findCallerEntry(
      $queue,
      $methodName,
      $request->getQuery('asyncId'),
      $request->getQuery('pollingSalt'),
      $request->getQuery('pollingHash')
    );
  }

  /**
   * The 'wait' query parameter, limited by the setting 'maxWaitSec'.
   */
  private function getWaitSeconds(HttpRequest $request): float {
    $wait = $request->getQuery('wait');
    if ($wait === null || !is_numeric($wait)) {
      return 0.0;
    }
    return $this->operations->limitWaitSeconds((float) $wait);
  }

}
