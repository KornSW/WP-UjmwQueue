<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Processors;

/**
 * Status LED of a processor instance, derived from its last contact and its active jobs:
 * 'idle' (green, polls regularly), 'busy' (flickering green, has active jobs), 'silent' (yellow, no contact for
 * 5 minutes), 'offline' (LED off, no contact for 60 minutes). Only 'idle' and 'busy' instances count as available.
 */
final class Liveness {

  public const IDLE = 'idle';

  public const BUSY = 'busy';

  public const SILENT = 'silent';

  public const OFFLINE = 'offline';

  public const SILENT_AFTER_MS = 300000;

  public const OFFLINE_AFTER_MS = 3600000;

  /**
   * Determines the status of an instance.
   */
  public static function of(int $lastSeenMs, int $nowMs, int $activeJobs): string {
    $silenceMs = $nowMs - $lastSeenMs;
    if ($silenceMs >= self::OFFLINE_AFTER_MS) {
      return self::OFFLINE;
    }
    if ($silenceMs >= self::SILENT_AFTER_MS) {
      return self::SILENT;
    }
    if ($activeJobs > 0) {
      return self::BUSY;
    }
    return self::IDLE;
  }

  /**
   * True if an instance with this last contact counts as available ("green").
   */
  public static function isAvailable(int $lastSeenMs, int $nowMs): bool {
    return ($nowMs - $lastSeenMs) < self::SILENT_AFTER_MS;
  }

}
