<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Protocol;

/**
 * Default 'pollNbf' calculation of the UJMW long-running protocol (port of 'UjmwAsyncProtocol'):
 * Fibonacci(window of elapsed time) * ((overallActiveOperations * throttlingLevel) \ 100 + 1).
 */
final class PollDelay {

  /**
   * Returns the full Fibonacci value of the window (bounded by 1, 2, 3, 5, 8, 13, 21, ...) into which the elapsed time falls.
   * Example: 9 seconds fall into the window 8..13 and result in 5.
   */
  public static function getFibonacciWindowSeconds(float $elapsedSeconds): int {
    $lower = 1;
    $upper = 2;
    if ($elapsedSeconds < $upper) {
      return 1;
    }
    while ($elapsedSeconds >= $upper) {
      $next = $lower + $upper;
      $lower = $upper;
      $upper = $next;
      if ($upper > 1000000) {
        break;
      }
    }
    return $upper - $lower;
  }

  /**
   * Returns the poll delay in seconds.
   */
  public static function calculateSeconds(float $elapsedSeconds, int $overallActiveOperations, int $throttlingLevel): int {
    $fibonacci = self::getFibonacciWindowSeconds($elapsedSeconds);
    $throttlingFactor = intdiv($overallActiveOperations * $throttlingLevel, 100) + 1;
    return $fibonacci * $throttlingFactor;
  }

}
