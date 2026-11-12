<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Protocol;

/**
 * Release rule of the queue protocol (decision Q-REQUEUE): a released entry is reinserted at the index
 * 0, 1, 2, 3, 5, 8, 13, 21, 34, 55 (Fibonacci without the duplicate 1) for the releases 1 to 10;
 * the 11th release terminates the entry.
 */
final class RequeuePosition {

  private const POSITIONS = [0, 1, 2, 3, 5, 8, 13, 21, 34, 55];

  /**
   * Fault delivered to the caller when the release limit is exceeded.
   */
  public const LIMIT_FAULT = 'Dequeued and Released 10 Times (Limit)';

  /**
   * Returns the queue index for the given (1-based) release count, or null if the entry must be terminated.
   */
  public static function getIndexForRelease(int $releaseCount): ?int {
    if ($releaseCount < 1) {
      return 0;
    }
    if ($releaseCount > count(self::POSITIONS)) {
      return null;
    }
    return self::POSITIONS[$releaseCount - 1];
  }

  /**
   * Calculates the sort position to insert an entry at the given index, based on the ascending sort positions
   * of the currently queued entries. If the queue holds fewer entries, the entry is appended at the end.
   *
   * @param list<float> $queuedPositions ascending
   */
  public static function calculateSortPosition(int $index, array $queuedPositions): float {
    $count = count($queuedPositions);
    if ($count === 0) {
      return 0.0;
    }
    if ($index <= 0) {
      return $queuedPositions[0] - 1.0;
    }
    if ($index >= $count) {
      return $queuedPositions[$count - 1] + 1.0;
    }
    return ($queuedPositions[$index - 1] + $queuedPositions[$index]) / 2.0;
  }

}
