<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Queue;

use KornSW\UjmwQueue\Clock;
use KornSW\UjmwQueue\Maintenance\Retention;
use KornSW\UjmwQueue\Monitoring\Notifier;
use KornSW\UjmwQueue\Processors\LockState;
use KornSW\UjmwQueue\Processors\ProcessorInstance;
use KornSW\UjmwQueue\Processors\ProcessorRegistry;
use KornSW\UjmwQueue\Protocol\AsyncId;
use KornSW\UjmwQueue\Protocol\CallbackBuffer;
use KornSW\UjmwQueue\Protocol\Json;
use KornSW\UjmwQueue\Protocol\MethodFilter;
use KornSW\UjmwQueue\Protocol\PollDelay;
use KornSW\UjmwQueue\Protocol\PollingAuth;
use KornSW\UjmwQueue\Protocol\RequeuePosition;
use KornSW\UjmwQueue\Settings;
use RuntimeException;

/**
 * The queuing logic of the relay - the PHP counterpart of 'IUjmwQueuingService' (caller side) and 'IUjmwQueue'
 * (processor side) of the UJMW queue epic. Request and response payloads are opaque JSON; the relay never needs
 * the contract.
 */
final class QueueService {

  public const FAULT_CAPACITY = 'Queue capacity exceeded';
  public const FAULT_WITHDRAWN = 'Withdrawn - dequeue confirmation missed';
  public const FAULT_WRONG_REQUEST = 'Wrong requestId or pollingHash';
  public const FAULT_WRONG_ASYNC = 'Wrong asyncId or pollingHash';
  public const FAULT_PROCESSOR_LOST = 'processor lost';
  public const FAULT_LEASE_LOST = 'Lease lost';
  public const FAULT_BUFFER_FULL = 'Callback buffer full - retry with the next heartbeat';
  public const FAULT_NOT_CONFIRMED = 'Dequeue not confirmed by a heartbeat';
  public const FAULT_TIMEOUT = 'Queue processing timeout';
  public const FAULT_NO_PROCESSOR = 'No processor available';
  public const FAULT_PROCESSOR_LOCK = 'Processor-Lock applied during execution';
  public const FAULT_ENDPOINT_LOCK = 'Endpoint-Lock applied during execution';
  public const FAULT_ADMIN_CANCEL = 'Cancelled by administrator';

  /**
   * Filter hook deciding about redelivery after the loss of a confirmed lease (decision Q-RETRY, default: no).
   * Arguments: (bool $redeliver, string $route, string $method, int $attempt, string $reason).
   */
  public const REDELIVERY_POLICY_FILTER = 'kornsw_uq_redelivery_policy';

  private const MAX_UPDATE_ATTEMPTS = 8;

  private const HOUSEKEEPING_INTERVAL_MS = 2000;

  private const DEQUEUE_PAGE_SIZE = 50;

  private const DEQUEUE_MAX_PAGES = 20;

  /**
   * Base of the FIFO sort positions (2026-01-01T00:00:00Z) - keeps the values small, so that midpoints for
   * reinsertions have enough floating point precision.
   */
  private const POSITION_EPOCH_MS = 1767225600000;

  private const CANCELLED_RESPONSE = '{"fault":null}';

  private int $lastHousekeepingMs = 0;

  public function __construct(
    private readonly Settings $settings,
    private readonly QueueRepository $queues,
    private readonly EntryRepository $entries,
    private readonly TerminationLog $terminationLog,
    private readonly ProcessorRegistry $processors,
    private readonly Notifier $notifier,
    private readonly Retention $retention
  ) {
  }

  #region Learning

  /**
   * Returns the queue of a route and learns (creates) it if it is unknown. With the security setting
   * 'lockNewEndpoints' a new queue starts locked and the administrator is notified.
   */
  public function getOrLearnQueue(string $route): QueueRecord {
    $initialLockLevel = LockState::LEVEL_NONE;
    if ($this->settings->getBool('lockNewEndpoints')) {
      $initialLockLevel = LockState::LEVEL_DRAIN;
    }
    [$queue, $isCreated] = $this->queues->getOrCreate($route, Clock::nowMs(), $initialLockLevel);
    if ($isCreated && $queue->lockLevel !== LockState::LEVEL_NONE) {
      $this->notifier->notifyNewEndpointLocked($queue);
    }
    return $queue;
  }

  #endregion

  #region Caller side

  /**
   * Enqueues a UJMW request (wire format JSON incl. sidechannel) for a transport-level method name.
   * Returns the new entry or a fault message.
   */
  public function enqueue(QueueRecord $queue, string $methodName, string $requestJson, bool $isLongRunning): Entry|string {
    $this->runHousekeeping(false);
    $this->queues->learnMethods($queue, [$methodName]);
    $nowMs = Clock::nowMs();
    if (!$this->processors->isAvailableFor($queue, $nowMs)) {
      $this->notifier->notifyUnserved($queue);
      return self::FAULT_NO_PROCESSOR;
    }
    if ($this->entries->countInStates($queue->id, [EntryState::Queued]) >= $this->settings->getInt('maxQueueSize')) {
      $this->notifier->notifyOverflow($queue);
      return self::FAULT_CAPACITY;
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
      $entryId = AsyncId::create($nowMs);
      $isInserted = $this->entries->insert([
        'id' => $entryId,
        'queue_id' => $queue->id,
        'method' => $methodName,
        'state' => EntryState::Queued->value,
        'position' => ($nowMs - self::POSITION_EPOCH_MS) / 1000.0,
        'is_long_running' => (int) $isLongRunning,
        'request_json' => $requestJson,
        'caller_secret' => PollingAuth::generateSecret(),
        'created_ms' => $nowMs,
        'queued_ms' => $nowMs,
      ]);
      if ($isInserted) {
        $this->queues->incrementCalls($queue->id);
        $entry = $this->entries->find($entryId);
        if ($entry !== null) {
          return $entry;
        }
      }
    }
    throw new RuntimeException('Could not enqueue the request');
  }

  /**
   * Authenticates a caller poll / cancellation ('asyncId', 'pollingSalt', 'pollingHash'). Returns null if the entry
   * does not exist or the hash is wrong (no distinction, no information disclosure).
   */
  public function findCallerEntry(QueueRecord $queue, string $methodName, ?string $asyncId, ?string $pollingSalt, ?string $pollingHash): ?Entry {
    if (!AsyncId::isValid($asyncId)) {
      return null;
    }
    $entry = $this->entries->find((string) $asyncId);
    if ($entry === null || $entry->queueId !== $queue->id || strcasecmp($entry->method, $methodName) !== 0) {
      return null;
    }
    if (!PollingAuth::verify($entry->callerSecret, $pollingSalt, $pollingHash)) {
      return null;
    }
    return $entry;
  }

  /**
   * Drains the buffered callback invocations of a running entry (single consumer). Returns the current entry and
   * the drained buffer. For terminated entries the buffer is already part of the final response.
   *
   * @return array{0: Entry, 1: array<string, list<mixed>>}
   */
  public function drainCallbacks(Entry $entry): array {
    for ($attempt = 0; $attempt < self::MAX_UPDATE_ATTEMPTS; $attempt++) {
      if ($entry->state->isTerminal() || $entry->callbacksJson === null) {
        return [$entry, []];
      }
      $buffer = CallbackBuffer::decodeStored($entry->callbacksJson);
      if ($this->entries->tryUpdate($entry, ['callbacks_json' => null])) {
        $current = $this->entries->find($entry->id);
        if ($current === null) {
          return [$entry, $buffer];
        }
        return [$current, $buffer];
      }
      $reloaded = $this->entries->find($entry->id);
      if ($reloaded === null) {
        return [$entry, []];
      }
      $entry = $reloaded;
    }
    return [$entry, []];
  }

  /**
   * Requests the cancellation of an entry: a queued (or not yet confirmed) entry terminates immediately
   * (execState -2), a leased entry is flagged ('cancellationRequested' on the next heartbeat).
   */
  public function requestCancel(Entry $entry): Entry {
    $result = $this->mutate($entry->id, function (Entry $current): ?array {
      if ($current->state->isTerminal()) {
        return null;
      }
      if ($current->state === EntryState::Queued || $current->state === EntryState::Allocated) {
        $changes = $this->getTerminationChanges($current, EntryState::Cancelled, self::CANCELLED_RESPONSE);
        $changes['cancel_requested'] = 1;
        $changes['withdrawn_secret'] = $current->processorSecret;
        $changes['processor_secret'] = null;
        return $changes;
      }
      if ($current->isCancellationRequested) {
        return null;
      }
      return ['cancel_requested' => 1];
    });
    if ($result === null) {
      return $entry;
    }
    return $result[1];
  }

  /**
   * Long-poll: waits until the entry has terminated or the time is up. Returns the latest state.
   */
  public function waitForTermination(Entry $entry, float $maxSeconds): Entry {
    $deadline = microtime(true) + $maxSeconds;
    $sleepMicroseconds = 100000;
    while (!$entry->state->isTerminal() && microtime(true) < $deadline) {
      $remainingMicroseconds = (int) (($deadline - microtime(true)) * 1000000);
      usleep(max(1000, min($sleepMicroseconds, $remainingMicroseconds)));
      $sleepMicroseconds = min(500000, $sleepMicroseconds + 100000);
      $this->runHousekeeping(false);
      $reloaded = $this->entries->find($entry->id);
      if ($reloaded === null) {
        break;
      }
      $entry = $reloaded;
    }
    return $entry;
  }

  /**
   * Calculates the 'pollNbf' for a running entry (Unix ms) and records it for the outcome retention.
   */
  public function calculatePollNbf(Entry $entry): int {
    $nowMs = Clock::nowMs();
    $delaySeconds = PollDelay::calculateSeconds(
      ($nowMs - $entry->createdMs) / 1000.0,
      $this->entries->countAllUnterminated(),
      $this->settings->getInt('throttlingLevel')
    );
    $nbfMs = $nowMs + ($delaySeconds * 1000);
    $this->entries->raiseLastNbf($entry->id, $nbfMs);
    return $nbfMs;
  }

  #endregion

  #region Processor side

  /**
   * Allocates the front-most queued entry matching the filter (tentative lease, must be confirmed by a heartbeat).
   */
  public function dequeue(QueueRecord $queue, MethodFilter $filter, ProcessorInstance $processor): ?Lease {
    $this->runHousekeeping(false);
    if ($queue->lockLevel !== LockState::LEVEL_NONE || $processor->lockLevel !== LockState::LEVEL_NONE) {
      // locked: answered as if there was nothing to process
      return null;
    }

    $methodNames = null;
    if (!$filter->matchesAll()) {
      $methodNames = $filter->getMentionedNames();
    }
    for ($page = 0; $page < self::DEQUEUE_MAX_PAGES; $page++) {
      $candidates = $this->entries->findQueued(
        $queue->id,
        $methodNames,
        $filter->isBlacklist(),
        self::DEQUEUE_PAGE_SIZE,
        $page * self::DEQUEUE_PAGE_SIZE
      );
      if (count($candidates) === 0) {
        return null;
      }
      foreach ($candidates as $candidate) {
        if (!$filter->matches($candidate->method)) {
          continue;
        }
        $pollingSecret = PollingAuth::generateSecret();
        $nowMs = Clock::nowMs();
        $isAllocated = $this->entries->tryUpdate($candidate, [
          'state' => EntryState::Allocated->value,
          'processor_secret' => $pollingSecret,
          'withdrawn_secret' => null,
          'processor_name' => $processor->getFullName(),
          'processor_id' => $processor->id,
          'allocated_ms' => $nowMs,
          'heartbeat_ms' => $nowMs,
        ]);
        if ($isAllocated) {
          $allocated = $this->entries->find($candidate->id);
          if ($allocated !== null) {
            return new Lease($allocated, $pollingSecret, $this->entries->countInStates($queue->id, [EntryState::Queued]));
          }
        }
      }
    }
    return null;
  }

  /**
   * Authenticates a processor request against the current or a withdrawn allocation of the entry.
   */
  public function findProcessorEntry(QueueRecord $queue, ?string $requestId, ?string $pollingSalt, ?string $pollingHash): ?ProcessorAccess {
    if (!AsyncId::isValid($requestId)) {
      return null;
    }
    $entry = $this->entries->find((string) $requestId);
    if ($entry === null || $entry->queueId !== $queue->id) {
      return null;
    }
    if (PollingAuth::verify($entry->processorSecret, $pollingSalt, $pollingHash)) {
      return new ProcessorAccess($entry, false);
    }
    if (PollingAuth::verify($entry->withdrawnSecret, $pollingSalt, $pollingHash)) {
      return new ProcessorAccess($entry, true);
    }
    return null;
  }

  /**
   * Heartbeat: confirms an allocation (first heartbeat), renews the lease and buffers callback invocations.
   *
   * @param array<string, list<mixed>> $invocations
   * @return array{cancellationRequested: bool, fault: ?string}
   */
  public function heartbeat(ProcessorAccess $access, array $invocations): array {
    if ($access->isWithdrawn) {
      return ['cancellationRequested' => true, 'fault' => self::FAULT_WITHDRAWN];
    }
    $result = ['cancellationRequested' => true, 'fault' => self::FAULT_LEASE_LOST];
    $confirmationTimeoutMs = $this->settings->getInt('dequeueConfirmationTimeoutSec') * 1000;
    $bufferLimit = $this->settings->getInt('callbackBufferLimit');

    $this->mutate($access->entry->id, function (Entry $current) use (&$result, $access, $invocations, $confirmationTimeoutMs, $bufferLimit): ?array {
      $result = ['cancellationRequested' => true, 'fault' => self::FAULT_LEASE_LOST];
      if ($current->processorSecret !== $access->entry->processorSecret) {
        $result = ['cancellationRequested' => true, 'fault' => self::FAULT_WITHDRAWN];
        return null;
      }
      if ($current->state->isTerminal() && $current->isCancellationRequested) {
        // aborted by an administrator (lock / cancel): the caller has its fault already, the processor shall stop
        $result = ['cancellationRequested' => true, 'fault' => null];
        return null;
      }
      if ($current->state->isTerminal() || $current->state === EntryState::Queued) {
        return null;
      }

      $nowMs = Clock::nowMs();
      $changes = ['heartbeat_ms' => $nowMs];
      if ($current->state === EntryState::Allocated) {
        if ($nowMs > ($current->allocatedMs + $confirmationTimeoutMs)) {
          $result = ['cancellationRequested' => true, 'fault' => self::FAULT_WITHDRAWN];
          return $this->getWithdrawChanges($current);
        }
        $changes['state'] = EntryState::Leased->value;
        $changes['attempt_count'] = $current->attemptCount + 1;
      }

      $fault = null;
      if (count($invocations) > 0) {
        $buffer = CallbackBuffer::decodeStored($current->callbacksJson);
        if ((CallbackBuffer::countInvocations($buffer) + CallbackBuffer::countInvocations($invocations)) > $bufferLimit) {
          $fault = self::FAULT_BUFFER_FULL;
        } else {
          $changes['callbacks_json'] = CallbackBuffer::encodeStored(CallbackBuffer::merge($buffer, $invocations));
        }
      }
      $result = ['cancellationRequested' => $current->isCancellationRequested, 'fault' => $fault];
      return $changes;
    });
    return $result;
  }

  /**
   * Returns a leased entry to the queue (release rule Q-REQUEUE) or confirms a requested cancellation.
   * Returns a fault message or null.
   */
  public function release(ProcessorAccess $access): ?string {
    if ($access->isWithdrawn) {
      return null;
    }
    $fault = null;
    $this->mutate($access->entry->id, function (Entry $current) use (&$fault, $access): ?array {
      $fault = null;
      if ($current->processorSecret !== $access->entry->processorSecret) {
        return null;
      }
      if ($current->state->isTerminal()) {
        if ($current->state !== EntryState::Cancelled && !$current->isCancellationRequested) {
          $fault = self::FAULT_LEASE_LOST;
        }
        return null;
      }
      if ($current->state === EntryState::Allocated) {
        // never executed: back to the head of the queue, does not count as release
        return $this->getWithdrawChanges($current);
      }
      if ($current->isCancellationRequested) {
        return $this->getTerminationChanges($current, EntryState::Cancelled, self::CANCELLED_RESPONSE);
      }
      $releaseCount = $current->releaseCount + 1;
      $index = RequeuePosition::getIndexForRelease($releaseCount);
      if ($index === null) {
        return $this->getTerminationChanges($current, EntryState::Failed, Json::encode(['fault' => RequeuePosition::LIMIT_FAULT]));
      }
      $positions = $this->entries->getQueuedPositions($current->queueId, $index + 1);
      return [
        'state' => EntryState::Queued->value,
        'position' => RequeuePosition::calculateSortPosition($index, $positions),
        'release_count' => $releaseCount,
        'queued_ms' => Clock::nowMs(),
        'processor_secret' => null,
        'withdrawn_secret' => null,
        'allocated_ms' => 0,
        'heartbeat_ms' => 0,
      ];
    });
    return $fault;
  }

  /**
   * Delivers the final UJMW response of a leased entry. Returns a fault message or null.
   */
  public function complete(ProcessorAccess $access, string $responseJson): ?string {
    if ($access->isWithdrawn) {
      return self::FAULT_WITHDRAWN;
    }
    $decodedResponse = Json::decodeObject($responseJson);
    if ($decodedResponse === null) {
      return "'response' must be a JSON object";
    }
    $hasFault = (isset($decodedResponse->fault) && $decodedResponse->fault !== null && $decodedResponse->fault !== '');

    $fault = self::FAULT_LEASE_LOST;
    $this->mutate($access->entry->id, function (Entry $current) use (&$fault, $access, $responseJson, $hasFault): ?array {
      $fault = self::FAULT_LEASE_LOST;
      if ($current->processorSecret !== $access->entry->processorSecret) {
        $fault = self::FAULT_WITHDRAWN;
        return null;
      }
      if ($current->state->isTerminal() || $current->state === EntryState::Queued) {
        return null;
      }
      if ($current->state === EntryState::Allocated) {
        $fault = self::FAULT_NOT_CONFIRMED;
        return null;
      }
      $fault = null;
      if ($hasFault) {
        return $this->getTerminationChanges($current, EntryState::Failed, $responseJson);
      }
      return $this->getTerminationChanges($current, EntryState::Completed, $responseJson);
    });
    return $fault;
  }

  #endregion

  #region Administration

  /**
   * Aborts the active jobs of a processor instance or of a queue (administrator lock or cancel): the caller gets the
   * fault immediately, the processor gets 'cancellationRequested' with its next heartbeat. Returns the number of
   * aborted jobs.
   */
  public function abortActiveJobs(?int $processorId, ?int $queueId, string $fault): int {
    $abortedCount = 0;
    foreach ($this->entries->findActive($processorId, $queueId, 1000) as $activeEntry) {
      $result = $this->mutate($activeEntry->id, function (Entry $current) use ($fault): ?array {
        if ($current->state !== EntryState::Allocated && $current->state !== EntryState::Leased) {
          return null;
        }
        $changes = $this->getTerminationChanges($current, EntryState::Failed, Json::encode(['fault' => $fault]));
        $changes['cancel_requested'] = 1;
        return $changes;
      });
      if ($result !== null && !$result[0]->state->isTerminal() && $result[1]->state->isTerminal()) {
        $abortedCount++;
      }
    }
    return $abortedCount;
  }

  #endregion

  #region Housekeeping

  /**
   * Withdraws unconfirmed allocations, handles lost leases and evicts expired outcomes. Runs at most every
   * two seconds per request unless forced.
   */
  public function runHousekeeping(bool $isForced): void {
    $nowMs = Clock::nowMs();
    if (!$isForced && ($nowMs - $this->lastHousekeepingMs) < self::HOUSEKEEPING_INTERVAL_MS) {
      return;
    }
    $this->lastHousekeepingMs = $nowMs;

    $confirmationThresholdMs = $nowMs - ($this->settings->getInt('dequeueConfirmationTimeoutSec') * 1000);
    foreach ($this->entries->findStale(EntryState::Allocated, 'allocated_ms', $confirmationThresholdMs, 100) as $staleEntry) {
      $this->mutate($staleEntry->id, function (Entry $current) use ($confirmationThresholdMs): ?array {
        if ($current->state !== EntryState::Allocated || $current->allocatedMs >= $confirmationThresholdMs) {
          return null;
        }
        return $this->getWithdrawChanges($current);
      });
    }

    $leaseThresholdMs = $nowMs - ($this->settings->getInt('leaseLossTimeoutSec') * 1000);
    foreach ($this->entries->findStale(EntryState::Leased, 'heartbeat_ms', $leaseThresholdMs, 100) as $staleEntry) {
      $route = '';
      $queue = $this->queues->findById($staleEntry->queueId);
      if ($queue !== null) {
        $route = $queue->route;
      }
      $isRedelivered = (bool) apply_filters(
        self::REDELIVERY_POLICY_FILTER,
        false,
        $route,
        $staleEntry->method,
        $staleEntry->attemptCount,
        'leaseLost'
      );
      $this->mutate($staleEntry->id, function (Entry $current) use ($leaseThresholdMs, $isRedelivered): ?array {
        if ($current->state !== EntryState::Leased || $current->heartbeatMs >= $leaseThresholdMs) {
          return null;
        }
        if ($isRedelivered && !$current->isCancellationRequested) {
          return $this->getWithdrawChanges($current);
        }
        return $this->getTerminationChanges($current, EntryState::Failed, Json::encode(['fault' => self::FAULT_PROCESSOR_LOST]));
      });
    }

    $this->failUnservedEntries($nowMs);

    $this->entries->deleteExpiredOutcomes(
      $nowMs,
      $this->settings->getInt('minOutcomePreservTime'),
      $this->settings->getInt('maxOutcomePreservTime'),
      $this->settings->getInt('outcomeCleanupLimit')
    );
    $this->terminationLog->prune($nowMs);
    $this->retention->runIfDue($nowMs);
  }

  /**
   * Fails queued entries that waited longer than 'incomingCallQueueWaitTimeoutSec', and all queued entries of
   * queues without any available processor ('No processor available').
   */
  private function failUnservedEntries(int $nowMs): void {
    $faultJson = Json::encode(['fault' => self::FAULT_NO_PROCESSOR]);
    $waitThresholdMs = $nowMs - ($this->settings->getInt('incomingCallQueueWaitTimeoutSec') * 1000);
    $expiredQueueIds = [];
    foreach ($this->entries->findStale(EntryState::Queued, 'queued_ms', $waitThresholdMs, 100) as $staleEntry) {
      $this->mutate($staleEntry->id, function (Entry $current) use ($waitThresholdMs, $faultJson): ?array {
        if ($current->state !== EntryState::Queued || $current->queuedMs >= $waitThresholdMs) {
          return null;
        }
        return $this->getTerminationChanges($current, EntryState::Failed, $faultJson);
      });
      $expiredQueueIds[$staleEntry->queueId] = true;
    }
    foreach (array_keys($expiredQueueIds) as $queueId) {
      $queue = $this->queues->findById($queueId);
      if ($queue !== null) {
        $this->notifier->notifyUnserved($queue);
      }
    }

    foreach ($this->entries->getQueueIdsWithQueuedEntries() as $queueId) {
      $queue = $this->queues->findById($queueId);
      if ($queue === null || $this->processors->isAvailableFor($queue, $nowMs)) {
        continue;
      }
      foreach ($this->entries->findQueued($queueId, null, false, 100, 0) as $queuedEntry) {
        $this->mutate($queuedEntry->id, function (Entry $current) use ($faultJson): ?array {
          if ($current->state !== EntryState::Queued) {
            return null;
          }
          return $this->getTerminationChanges($current, EntryState::Failed, $faultJson);
        });
      }
      $this->notifier->notifyUnserved($queue);
    }
  }

  #endregion

  #region Helpers

  /**
   * Changes that put an entry back to the head of the queue (never executed or redelivered with priority).
   * The old processor secret is kept as 'withdrawn' so that a late heartbeat gets a meaningful answer.
   *
   * @return array<string, mixed>
   */
  private function getWithdrawChanges(Entry $entry): array {
    return [
      'state' => EntryState::Queued->value,
      'position' => RequeuePosition::calculateSortPosition(0, $this->entries->getQueuedPositions($entry->queueId, 1)),
      'withdrawn_secret' => $entry->processorSecret,
      'processor_secret' => null,
      'queued_ms' => Clock::nowMs(),
      'allocated_ms' => 0,
      'heartbeat_ms' => 0,
    ];
  }

  /**
   * Changes that terminate an entry. Callback invocations still buffered are merged into the final response.
   *
   * @return array<string, mixed>
   */
  private function getTerminationChanges(Entry $entry, EntryState $finalState, string $responseJson): array {
    $buffer = CallbackBuffer::decodeStored($entry->callbacksJson);
    if (count($buffer) > 0) {
      $responseJson = Json::addMembers($responseJson, $buffer);
    }
    return [
      'state' => $finalState->value,
      'response_json' => $responseJson,
      'callbacks_json' => null,
      'terminated_ms' => Clock::nowMs(),
    ];
  }

  /**
   * Applies a state change with optimistic concurrency: the mutation is re-evaluated on the reloaded entry after
   * a conflict. A mutation returning null means "nothing to change". Returns [before, after] or null if the entry
   * does not exist.
   *
   * @param callable(Entry): ?array<string, mixed> $mutation
   * @return array{0: Entry, 1: Entry}|null
   */
  private function mutate(string $entryId, callable $mutation): ?array {
    for ($attempt = 0; $attempt < self::MAX_UPDATE_ATTEMPTS; $attempt++) {
      $before = $this->entries->find($entryId);
      if ($before === null) {
        return null;
      }
      $changes = $mutation($before);
      if ($changes === null) {
        return [$before, $before];
      }
      if ($this->entries->tryUpdate($before, $changes)) {
        $after = $this->entries->find($entryId);
        if ($after === null) {
          return null;
        }
        if (!$before->state->isTerminal() && $after->state->isTerminal()) {
          $this->terminationLog->record($after->queueId, $after->method, $after->terminatedMs);
        }
        return [$before, $after];
      }
    }
    throw new RuntimeException('Concurrent modification of a queue entry could not be resolved');
  }

  #endregion

}
