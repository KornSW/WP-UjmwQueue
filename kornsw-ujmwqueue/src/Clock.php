<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue;

/**
 * Time source of the relay. All times are stored as UTC Unix milliseconds.
 */
final class Clock {

  /**
   * Returns the current UTC time in Unix milliseconds.
   */
  public static function nowMs(): int {
    return (int) floor(microtime(true) * 1000);
  }

  /**
   * Formats Unix milliseconds as ISO 8601 UTC date-time (e.g. '2026-11-11T12:00:05Z').
   */
  public static function toIsoUtc(int $unixTimeMs): string {
    return gmdate('Y-m-d\TH:i:s\Z', intdiv($unixTimeMs, 1000));
  }

}
