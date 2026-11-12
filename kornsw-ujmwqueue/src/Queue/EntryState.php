<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Queue;

/**
 * Lifecycle of a queue entry. 'Allocated' is the tentative lease after a dequeue which must be confirmed by a
 * first heartbeat within the dequeue confirmation timeout (two-phase dequeue).
 */
enum EntryState: string {

  case Queued = 'queued';
  case Allocated = 'allocated';
  case Leased = 'leased';
  case Completed = 'completed';
  case Failed = 'failed';
  case Cancelled = 'cancelled';

  /**
   * True for completed, failed and cancelled.
   */
  public function isTerminal(): bool {
    return ($this === self::Completed || $this === self::Failed || $this === self::Cancelled);
  }

  /**
   * Maps the state to the 'execState' of the UJMW long-running protocol as seen by the caller.
   */
  public function toExecState(bool $isCancellationRequested): int {
    if ($this === self::Completed) {
      return 2;
    }
    if ($this === self::Failed) {
      return 3;
    }
    if ($this === self::Cancelled) {
      return -2;
    }
    if ($isCancellationRequested) {
      return -1;
    }
    if ($this === self::Leased) {
      return 1;
    }
    return 0;
  }

  /**
   * States of entries that wait for a processor or are processed right now.
   *
   * @return list<EntryState>
   */
  public static function activeStates(): array {
    return [self::Allocated, self::Leased];
  }

  /**
   * States of terminated entries (retained outcomes).
   *
   * @return list<EntryState>
   */
  public static function terminalStates(): array {
    return [self::Completed, self::Failed, self::Cancelled];
  }

}
