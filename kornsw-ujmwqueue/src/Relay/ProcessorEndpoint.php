<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Relay;

use InvalidArgumentException;
use KornSW\UjmwQueue\Clock;
use KornSW\UjmwQueue\Http\HttpRequest;
use KornSW\UjmwQueue\Http\HttpResponse;
use KornSW\UjmwQueue\Http\RelayPath;
use KornSW\UjmwQueue\Protocol\CallbackBuffer;
use KornSW\UjmwQueue\Protocol\Json;
use KornSW\UjmwQueue\Protocol\MethodFilter;
use KornSW\UjmwQueue\Protocol\RawJson;
use KornSW\UjmwQueue\Queue\EntryRepository;
use KornSW\UjmwQueue\Queue\EntryState;
use KornSW\UjmwQueue\Queue\Lease;
use KornSW\UjmwQueue\Queue\QueueRecord;
use KornSW\UjmwQueue\Queue\QueueRepository;
use KornSW\UjmwQueue\Queue\QueueService;
use KornSW\UjmwQueue\Queue\QueueStatistics;
use KornSW\UjmwQueue\Security\AccessGuard;
use KornSW\UjmwQueue\Security\KeyStore;
use KornSW\UjmwQueue\Settings;

/**
 * Processor side: the well-known sub-route '<route>/_queue' of the UJMW queue epic (section 5):
 * GET = info / dequeue, PUT = heartbeat, DELETE = return / confirm cancellation, POST = completion.
 * The first processor contact creates (learns) the queue of the route.
 */
final class ProcessorEndpoint {

  private const MAX_PROCESSOR_NAME_LENGTH = 191;

  public function __construct(
    private readonly Settings $settings,
    private readonly QueueRepository $queues,
    private readonly EntryRepository $entries,
    private readonly QueueService $queueService,
    private readonly QueueStatistics $statistics,
    private readonly AccessGuard $accessGuard
  ) {
  }

  /**
   * Handles '<route>/_queue'.
   */
  public function handle(HttpRequest $request, string $route): HttpResponse {
    $routeKey = RelayPath::toRouteKey($route);

    if ($request->method === 'GET' && !$request->hasQuery('dequeue')) {
      return $this->info($request, $route, $routeKey);
    }

    $denied = $this->accessGuard->authorize($request, $routeKey, [KeyStore::ROLE_PROCESSOR]);
    if ($denied !== null) {
      return $denied;
    }
    if ($request->isBodyTooLarge()) {
      return HttpResponse::fault('Request body too large', 413);
    }

    $nowMs = Clock::nowMs();
    $processorName = self::sanitizeProcessorName($request->getQuery('processor'));
    $queue = $this->queues->getOrCreate($route, $nowMs);
    $queue = $this->queues->touchProcessor($queue, $processorName, $nowMs);

    if ($request->method === 'GET') {
      return $this->dequeue($request, $queue, $processorName);
    }
    if ($request->method === 'PUT') {
      return $this->heartbeat($request, $queue, $processorName);
    }
    if ($request->method === 'DELETE') {
      return $this->release($request, $queue);
    }
    if ($request->method === 'POST') {
      return $this->complete($request, $queue, $processorName);
    }
    return HttpResponse::fault('Method not allowed', 405)->withHeader('Allow', 'GET, PUT, DELETE, POST');
  }

  /**
   * GET without 'dequeue': statistics (no side effects).
   */
  private function info(HttpRequest $request, string $route, string $routeKey): HttpResponse {
    if (!$this->settings->isAnonymousInfoAllowed()) {
      $denied = $this->accessGuard->authorize($request, $routeKey, [KeyStore::ROLE_CALLER, KeyStore::ROLE_PROCESSOR]);
      if ($denied !== null) {
        return $denied;
      }
    }
    $queue = $this->queues->findByRoute($route);
    if ($queue === null) {
      return HttpResponse::fault("Unknown queue '" . $route . "'", 404);
    }
    return HttpResponse::json(Json::encode($this->statistics->build($queue)));
  }

  /**
   * GET with 'dequeue=<filter>': allocates the next matching entry.
   */
  private function dequeue(HttpRequest $request, QueueRecord $queue, string $processorName): HttpResponse {
    try {
      $filter = MethodFilter::parse((string) $request->getQuery('dequeue'));
    } catch (InvalidArgumentException $exception) {
      return $this->buildDequeueResponse($queue, null, $exception->getMessage());
    }
    $queue = $this->queues->learnMethods($queue, $filter->getMentionedNames());
    $lease = $this->queueService->dequeue($queue, $filter, $processorName);
    return $this->buildDequeueResponse($queue, $lease, null);
  }

  /**
   * PUT: heartbeat (the first one confirms the allocation), optionally with buffered callback invocations.
   */
  private function heartbeat(HttpRequest $request, QueueRecord $queue, string $processorName): HttpResponse {
    $access = $this->queueService->findProcessorEntry($queue, $request->getQuery('requestId'), $request->getQuery('pollingSalt'), $request->getQuery('pollingHash'));
    if ($access === null) {
      return HttpResponse::json(Json::encode(['cancellationRequested' => false, 'fault' => QueueService::FAULT_WRONG_REQUEST]));
    }
    try {
      $invocations = CallbackBuffer::parseInvocations($request->getBody());
    } catch (InvalidArgumentException $exception) {
      return HttpResponse::json(Json::encode(['cancellationRequested' => false, 'fault' => $exception->getMessage()]));
    }
    $result = $this->queueService->heartbeat($access, $processorName, $invocations);
    return HttpResponse::json(Json::encode($result));
  }

  /**
   * DELETE: returns the entry to the queue or confirms a requested cancellation.
   */
  private function release(HttpRequest $request, QueueRecord $queue): HttpResponse {
    $access = $this->queueService->findProcessorEntry($queue, $request->getQuery('requestId'), $request->getQuery('pollingSalt'), $request->getQuery('pollingHash'));
    if ($access === null) {
      return HttpResponse::fault(QueueService::FAULT_WRONG_REQUEST);
    }
    return HttpResponse::json(Json::encode(['fault' => $this->queueService->release($access)]));
  }

  /**
   * POST: delivers the final UJMW response; with 'dequeueNext' the next entry is allocated in the same round-trip.
   */
  private function complete(HttpRequest $request, QueueRecord $queue, string $processorName): HttpResponse {
    $access = $this->queueService->findProcessorEntry($queue, $request->getQuery('requestId'), $request->getQuery('pollingSalt'), $request->getQuery('pollingHash'));
    if ($access === null) {
      return HttpResponse::fault(QueueService::FAULT_WRONG_REQUEST);
    }
    $body = Json::decodeObject($request->getBody());
    if ($body === null || !isset($body->response) || !is_object($body->response)) {
      return HttpResponse::fault("Body must be a JSON object with the property 'response'", 400);
    }
    $dequeueNext = null;
    if (isset($body->dequeueNext) && is_string($body->dequeueNext) && trim($body->dequeueNext) !== '') {
      $dequeueNext = $body->dequeueNext;
    }

    $fault = $this->queueService->complete($access, Json::encode($body->response));
    if ($fault !== null) {
      return HttpResponse::fault($fault);
    }
    if ($dequeueNext === null) {
      return HttpResponse::json(Json::encode(['fault' => null]));
    }
    try {
      $filter = MethodFilter::parse($dequeueNext);
    } catch (InvalidArgumentException $exception) {
      return $this->buildDequeueResponse($queue, null, $exception->getMessage());
    }
    $queue = $this->queues->learnMethods($queue, $filter->getMentionedNames());
    return $this->buildDequeueResponse($queue, $this->queueService->dequeue($queue, $filter, $processorName), null);
  }

  /**
   * Builds the dequeue response shape (section 5.2 of the epic); 'request' is embedded byte-for-byte.
   */
  private function buildDequeueResponse(QueueRecord $queue, ?Lease $lease, ?string $fault): HttpResponse {
    if ($lease === null) {
      return HttpResponse::json(Json::buildObject([
        'totalInQueue' => $this->entries->countInStates($queue->id, [EntryState::Queued]),
        'method' => null,
        'requestId' => null,
        'request' => null,
        'pollingSecret' => null,
        'heartbeatSec' => 0,
        'fault' => $fault,
      ]));
    }
    return HttpResponse::json(Json::buildObject([
      'totalInQueue' => $lease->totalInQueue,
      'method' => $lease->entry->method,
      'requestId' => $lease->entry->id,
      'request' => new RawJson($lease->entry->requestJson),
      'pollingSecret' => $lease->pollingSecret,
      'heartbeatSec' => $this->settings->getInt('heartbeatSec'),
      'fault' => null,
    ]));
  }

  /**
   * The processor name is informational only (no security identity); keep it short and printable.
   */
  private static function sanitizeProcessorName(?string $processorName): string {
    $processorName = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $processorName));
    if ($processorName === '') {
      return 'unnamed';
    }
    return mb_substr($processorName, 0, self::MAX_PROCESSOR_NAME_LENGTH);
  }

}
