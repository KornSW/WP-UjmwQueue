<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Api;

use InvalidArgumentException;
use KornSW\UjmwQueue\Http\RelayPath;
use KornSW\UjmwQueue\Plugin;
use KornSW\UjmwQueue\Protocol\Json;
use KornSW\UjmwQueue\Protocol\MethodFilter;
use KornSW\UjmwQueue\Protocol\PollingAuth;
use KornSW\UjmwQueue\Queue\Entry;
use KornSW\UjmwQueue\Queue\QueueRepository;
use KornSW\UjmwQueue\Queue\QueueService;
use KornSW\UjmwQueue\Relay\CallerOperations;

/**
 * In-process client API for other WordPress plugins: calls queued endpoints directly (no HTTP loopback, no access
 * key) on raw UJMW JSON level - exactly the bodies an HTTP caller would send and receive. Unknown endpoints are
 * learned on the first call (unlike HTTP callers, which get 404), so they appear in the admin page and can be
 * served as soon as a processor connects.
 *
 * Usage (from any plugin, any time after 'plugins_loaded'):
 *
 *   if (class_exists(\KornSW\UjmwQueue\Api\QueueApi::class)) {
 *     $responseJson = \KornSW\UjmwQueue\Api\QueueApi::get()->invoke('api/reports', 'RenderReport', '{"reportId":42}');
 *   }
 *
 * See doc/api.md for all methods and response shapes.
 */
final class QueueApi {

  public function __construct(
    private readonly QueueRepository $queues,
    private readonly QueueService $queueService,
    private readonly CallerOperations $operations
  ) {
  }

  /**
   * Returns the API instance (boots the plugin if necessary).
   */
  public static function get(): QueueApi {
    return Plugin::getApi();
  }

  /**
   * Regular (synchronous) call: blocks until the processor has answered or 'synchronousCallTimeoutSec' elapsed.
   * Returns the UJMW response object of the processor, or '{"fault":"..."}'.
   *
   * @throws InvalidArgumentException for an invalid route, method name or request JSON
   */
  public function invoke(string $route, string $methodName, string $requestJson): string {
    $queue = $this->queueService->getOrLearnQueue(self::validateRoute($route));
    return $this->operations->initiate($queue, self::validateMethodName($methodName), self::validateRequest($requestJson), false, 0.0);
  }

  /**
   * Long-running call ('POST ?wait'): returns after at most 'waitSeconds' (limited by 'maxWaitSec') either with the
   * final result plus '"async":{"execState":2|3}' or with '"async":{"asyncId","execState","pollNbf","cancellable",
   * "pollingSecret"}' to be used with 'poll()' / 'cancel()'.
   *
   * @throws InvalidArgumentException for an invalid route, method name or request JSON
   */
  public function invokeLongRunning(string $route, string $methodName, string $requestJson, float $waitSeconds = 0.0): string {
    $queue = $this->queueService->getOrLearnQueue(self::validateRoute($route));
    return $this->operations->initiate(
      $queue,
      self::validateMethodName($methodName),
      self::validateRequest($requestJson),
      true,
      $this->operations->limitWaitSeconds($waitSeconds)
    );
  }

  /**
   * Polls a long-running call, optionally waiting up to 'waitSeconds' for its termination. Returns the same shape
   * as the HTTP poll (callback invocations are drained).
   */
  public function poll(string $route, string $methodName, string $asyncId, string $pollingSecret, float $waitSeconds = 0.0): string {
    $this->queueService->runHousekeeping(false);
    $entry = $this->findEntry($route, $methodName, $asyncId, $pollingSecret);
    if ($entry === null) {
      return Json::encode(['fault' => QueueService::FAULT_WRONG_ASYNC]);
    }
    return $this->operations->poll($entry, $this->operations->limitWaitSeconds($waitSeconds));
  }

  /**
   * Requests the cancellation of a long-running call and optionally waits for its termination.
   */
  public function cancel(string $route, string $methodName, string $asyncId, string $pollingSecret, float $waitSeconds = 0.0): string {
    $entry = $this->findEntry($route, $methodName, $asyncId, $pollingSecret);
    if ($entry === null) {
      return Json::encode(['fault' => QueueService::FAULT_WRONG_ASYNC]);
    }
    return $this->operations->cancel($entry, $this->operations->limitWaitSeconds($waitSeconds));
  }

  /**
   * Returns the known endpoints with their learned method names.
   *
   * @return array<string, list<string>> route => method names
   */
  public function getEndpoints(): array {
    $endpoints = [];
    foreach ($this->queues->getAll() as $queue) {
      $endpoints[$queue->route] = $queue->methods;
    }
    return $endpoints;
  }

  /**
   * Authenticates a poll / cancel with the polling secret (the hash is computed here with a fresh salt).
   */
  private function findEntry(string $route, string $methodName, string $asyncId, string $pollingSecret): ?Entry {
    $queue = $this->queues->findByRoute(self::validateRoute($route));
    if ($queue === null) {
      return null;
    }
    $pollingSalt = PollingAuth::generateRandomAlphanumeric(6);
    return $this->queueService->findCallerEntry(
      $queue,
      self::validateMethodName($methodName),
      $asyncId,
      $pollingSalt,
      PollingAuth::computeHash($pollingSecret, $pollingSalt)
    );
  }

  /**
   * Validates a route like the HTTP router does (segments, length, no '_queue').
   */
  private static function validateRoute(string $route): string {
    $path = RelayPath::parse($route . '/x');
    if ($path === null || $path->getParentRoute() === null) {
      throw new InvalidArgumentException('Invalid route: ' . $route);
    }
    $normalizedRoute = $path->getParentRoute();
    if (str_ends_with('/' . $normalizedRoute, '/' . RelayPath::QUEUE_SEGMENT)) {
      throw new InvalidArgumentException('Invalid route: ' . $route);
    }
    return $normalizedRoute;
  }

  /**
   * Validates a transport-level method name.
   */
  private static function validateMethodName(string $methodName): string {
    if (!MethodFilter::isValidMethodName($methodName)) {
      throw new InvalidArgumentException('Invalid method name: ' . $methodName);
    }
    return $methodName;
  }

  /**
   * Validates that the request is a JSON object ('' means '{}').
   */
  private static function validateRequest(string $requestJson): string {
    $requestJson = trim($requestJson);
    if ($requestJson === '') {
      return '{}';
    }
    if (Json::decodeObject($requestJson) === null) {
      throw new InvalidArgumentException('The request must be a JSON object');
    }
    return $requestJson;
  }

}
