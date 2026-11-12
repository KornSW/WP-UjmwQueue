<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Queue;

/**
 * Immutable snapshot of one queue entry row. Changes are applied through 'EntryRepository::tryUpdate()' with an
 * optimistic concurrency check on 'rowVersion'.
 */
final class Entry {

  public function __construct(
    public readonly string $id,
    public readonly int $queueId,
    public readonly string $method,
    public readonly EntryState $state,
    public readonly float $position,
    public readonly bool $isLongRunning,
    public readonly string $requestJson,
    public readonly ?string $responseJson,
    public readonly string $callerSecret,
    public readonly ?string $processorSecret,
    public readonly ?string $withdrawnSecret,
    public readonly ?string $processorName,
    public readonly int $releaseCount,
    public readonly int $attemptCount,
    public readonly bool $isCancellationRequested,
    public readonly ?string $callbacksJson,
    public readonly int $rowVersion,
    public readonly int $createdMs,
    public readonly int $allocatedMs,
    public readonly int $heartbeatMs,
    public readonly int $terminatedMs,
    public readonly int $lastNbfMs
  ) {
  }

  /**
   * Creates an entry from a database row (all values arrive as strings).
   */
  public static function fromRow(object $row): Entry {
    return new Entry(
      (string) $row->id,
      (int) $row->queue_id,
      (string) $row->method,
      EntryState::from((string) $row->state),
      (float) $row->position,
      ((int) $row->is_long_running) === 1,
      (string) $row->request_json,
      self::toNullableString($row->response_json),
      (string) $row->caller_secret,
      self::toNullableString($row->processor_secret),
      self::toNullableString($row->withdrawn_secret),
      self::toNullableString($row->processor_name),
      (int) $row->release_count,
      (int) $row->attempt_count,
      ((int) $row->cancel_requested) === 1,
      self::toNullableString($row->callbacks_json),
      (int) $row->row_version,
      (int) $row->created_ms,
      (int) $row->allocated_ms,
      (int) $row->heartbeat_ms,
      (int) $row->terminated_ms,
      (int) $row->last_nbf_ms
    );
  }

  /**
   * Converts a nullable database value to a nullable string.
   */
  private static function toNullableString(mixed $value): ?string {
    if ($value === null) {
      return null;
    }
    return (string) $value;
  }

  /**
   * The 'execState' as seen by the caller.
   */
  public function getExecState(): int {
    return $this->state->toExecState($this->isCancellationRequested);
  }

}
