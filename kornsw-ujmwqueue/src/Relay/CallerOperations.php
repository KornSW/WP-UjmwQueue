<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Relay;

use KornSW\UjmwQueue\Clock;
use KornSW\UjmwQueue\Protocol\Json;
use KornSW\UjmwQueue\Queue\Entry;
use KornSW\UjmwQueue\Queue\QueueRecord;
use KornSW\UjmwQueue\Queue\QueueService;
use KornSW\UjmwQueue\Settings;

/**
 * The caller side of the relay on raw JSON level, shared by the HTTP endpoint ('CallerEndpoint') and the in-process
 * API for other WordPress plugins ('Api\QueueApi'). Every method returns the UJMW response body.
 */
final class CallerOperations {

  public function __construct(
    private readonly Settings $settings,
    private readonly QueueService $queueService
  ) {
  }

  /**
   * Enqueues a call. Long-running: returns after at most 'waitSeconds' with the final result (no 'asyncId') or with
   * the 'async' state. Regular: blocks until the entry has terminated or the synchronous timeout elapsed.
   */
  public function initiate(QueueRecord $queue, string $methodName, string $requestJson, bool $isLongRunning, float $waitSeconds): string {
    $enqueueResult = $this->queueService->enqueue($queue, $methodName, $requestJson, $isLongRunning);
    if (is_string($enqueueResult)) {
      if ($isLongRunning) {
        return Json::buildObject(['fault' => $enqueueResult, 'async' => ['execState' => 3]]);
      }
      return Json::encode(['fault' => $enqueueResult]);
    }

    if ($isLongRunning) {
      $entry = $this->queueService->waitForTermination($enqueueResult, $waitSeconds);
      if ($entry->state->isTerminal()) {
        // completed within the wait time: nothing left to poll, therefore no asyncId
        return Json::addMembers((string) $entry->responseJson, ['async' => ['execState' => $entry->getExecState()]]);
      }
      return $this->buildRunningResponse($entry, true);
    }

    $entry = $this->queueService->waitForTermination($enqueueResult, $this->prepareSynchronousTimeout());
    if ($entry->state->isTerminal()) {
      return (string) $entry->responseJson;
    }
    $this->queueService->requestCancel($entry);
    return Json::encode(['fault' => QueueService::FAULT_TIMEOUT]);
  }

  /**
   * Polls an authenticated long-running call, optionally waiting up to 'waitSeconds' for its termination.
   */
  public function poll(Entry $entry, float $waitSeconds): string {
    if ($waitSeconds > 0) {
      $entry = $this->queueService->waitForTermination($entry, $waitSeconds);
    }
    if ($entry->state->isTerminal()) {
      return $this->buildTerminalResponse($entry);
    }
    return $this->buildRunningResponse($entry, false);
  }

  /**
   * Requests the cancellation of an authenticated long-running call and optionally waits for its termination.
   */
  public function cancel(Entry $entry, float $waitSeconds): string {
    $entry = $this->queueService->requestCancel($entry);
    $entry = $this->queueService->waitForTermination($entry, $waitSeconds);
    if ($entry->state->isTerminal()) {
      return $this->buildTerminalResponse($entry);
    }
    return $this->buildRunningResponse($entry, false);
  }

  /**
   * Limits a requested wait time by the setting 'maxWaitSec'.
   */
  public function limitWaitSeconds(float $requestedSeconds): float {
    return max(0.0, min($requestedSeconds, (float) $this->settings->getInt('maxWaitSec')));
  }

  /**
   * Response for a running entry: drained callback invocations, 'fault' null and the 'async' state.
   */
  private function buildRunningResponse(Entry $entry, bool $isInitiating): string {
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
    return Json::buildObject($members);
  }

  /**
   * Response for a terminated entry: the final UJMW response of the processor plus the 'async' state.
   */
  private function buildTerminalResponse(Entry $entry): string {
    $async = [
      'asyncId' => $entry->id,
      'execState' => $entry->getExecState(),
      'pollNbf' => Clock::toIsoUtc(Clock::nowMs()),
      'cancellable' => false,
    ];
    return Json::addMembers((string) $entry->responseJson, ['async' => $async]);
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
