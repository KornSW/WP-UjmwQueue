<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Relay;

use KornSW\UjmwQueue\Clock;
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
use KornSW\UjmwQueue\Settings;

/**
 * Caller side: a regular-looking UJMW endpoint '<route>/<Method>' including the long-running protocol
 * ('POST ?wait' / 'GET' poll / 'DELETE' cancel). The caller does not know that a queue exists.
 */
final class CallerEndpoint {

  public function __construct(
    private readonly Settings $settings,
    private readonly QueueRepository $queues,
    private readonly QueueService $queueService,
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
    return HttpResponse::json(Json::encode([
      'route' => $queue->route,
      'methods' => $queue->methods,
      'relay' => 'kornsw-ujmwqueue',
      'fault' => null,
    ]));
  }

  /**
   * POST: enqueues the call. With 'wait' (long-running method) the response returns after at most 'wait' seconds,
   * otherwise (regular method) it blocks until the entry has terminated or the synchronous timeout elapsed.
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
    $enqueueResult = $this->queueService->enqueue($queue, $methodName, $requestJson, $isLongRunning);
    if (is_string($enqueueResult)) {
      if ($isLongRunning) {
        return HttpResponse::json(Json::buildObject(['fault' => $enqueueResult, 'async' => ['execState' => 3]]));
      }
      return HttpResponse::fault($enqueueResult);
    }

    if ($isLongRunning) {
      $entry = $this->queueService->waitForTermination($enqueueResult, $this->getWaitSeconds($request));
      if ($entry->state->isTerminal()) {
        // completed within the wait time: nothing left to poll, therefore no asyncId
        return HttpResponse::json(Json::addMembers((string) $entry->responseJson, ['async' => ['execState' => $entry->getExecState()]]));
      }
      return $this->buildRunningResponse($entry, true);
    }

    $entry = $this->queueService->waitForTermination($enqueueResult, $this->prepareSynchronousTimeout());
    if ($entry->state->isTerminal()) {
      return HttpResponse::json((string) $entry->responseJson);
    }
    $this->queueService->requestCancel($entry);
    return HttpResponse::fault(QueueService::FAULT_TIMEOUT);
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
    if ($entry->state->isTerminal()) {
      return $this->buildTerminalResponse($entry);
    }
    return $this->buildRunningResponse($entry, false);
  }

  /**
   * DELETE: requests the cancellation of a long-running call and optionally waits for its termination.
   */
  private function cancel(HttpRequest $request, QueueRecord $queue, string $methodName): HttpResponse {
    $entry = $this->findEntry($request, $queue, $methodName);
    if ($entry === null) {
      return HttpResponse::fault(QueueService::FAULT_WRONG_ASYNC);
    }
    $entry = $this->queueService->requestCancel($entry);
    $entry = $this->queueService->waitForTermination($entry, $this->getWaitSeconds($request));
    if ($entry->state->isTerminal()) {
      return $this->buildTerminalResponse($entry);
    }
    return $this->buildRunningResponse($entry, false);
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
   * Response for a running entry: drained callback invocations, 'fault' null and the 'async' state.
   */
  private function buildRunningResponse(Entry $entry, bool $isInitiating): HttpResponse {
    [$entry, $drainedInvocations] = $this->queueService->drainCallbacks($entry);
    if ($entry->state->isTerminal()) {
      return $this->buildTerminalResponse($entry);
    }
    $async = [
      'asyncId' => $entry->id,
      'execState' => $entry->getExecState(),
      'pollNbf' => Clock::toIsoUtc($this->queueService->calculatePollNbf($entry)),
      'cancellable' => !$entry->isCancellationRequested,
    ];
    if ($isInitiating) {
      $async['pollingSecret'] = $entry->callerSecret;
    }
    $members = $drainedInvocations;
    $members['fault'] = null;
    $members['async'] = $async;
    return HttpResponse::json(Json::buildObject($members));
  }

  /**
   * Response for a terminated entry: the final UJMW response of the processor plus the 'async' state.
   */
  private function buildTerminalResponse(Entry $entry): HttpResponse {
    $async = [
      'asyncId' => $entry->id,
      'execState' => $entry->getExecState(),
      'pollNbf' => Clock::toIsoUtc(Clock::nowMs()),
      'cancellable' => false,
    ];
    return HttpResponse::json(Json::addMembers((string) $entry->responseJson, ['async' => $async]));
  }

  /**
   * The 'wait' query parameter, limited by the setting 'maxWaitSec'.
   */
  private function getWaitSeconds(HttpRequest $request): float {
    $wait = $request->getQuery('wait');
    if ($wait === null || !is_numeric($wait)) {
      return 0.0;
    }
    return max(0.0, min((float) $wait, (float) $this->settings->getInt('maxWaitSec')));
  }

  /**
   * Raises the PHP time limit for a blocking synchronous call and returns the usable timeout in seconds.
   */
  private function prepareSynchronousTimeout(): float {
    $timeoutSeconds = $this->settings->getInt('synchronousCallTimeoutSec');
    $isLimitRaised = false;
    if (function_exists('set_time_limit')) {
      $isLimitRaised = set_time_limit($timeoutSeconds + 30);
    }
    $maxExecutionTime = (int) ini_get('max_execution_time');
    if (!$isLimitRaised && $maxExecutionTime > 0) {
      $timeoutSeconds = min($timeoutSeconds, max(1, $maxExecutionTime - 5));
    }
    return (float) $timeoutSeconds;
  }

}
