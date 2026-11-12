<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Processors;

/**
 * Status LED of a processor instance, derived from its last contact and its active jobs:
 * 'idle' (green, polls regularly), 'busy' (flickering green, has active jobs), 'silent' (yellow, no contact for
 * the configurable threshold 'processorSilentAfterSec', default 3 minutes), 'offline' (LED off, no contact for
 * 60 minutes). Only 'idle' and 'busy' instances count as available.
 */
final class Liveness {

  public const IDLE = 'idle';

  public const BUSY = 'busy';

  public const SILENT = 'silent';

  public const OFFLINE = 'offline';

  public const OFFLINE_AFTER_MS = 3600000;

  /**
   * Determines the status of an instance.
   */
  public static function of(int $lastSeenMs, int $nowMs, int $activeJobs, int $silentAfterMs): string {
    $silenceMs = $nowMs - $lastSeenMs;
    if ($silenceMs >= self::OFFLINE_AFTER_MS) {
      return self::OFFLINE;
    }
    if ($silenceMs >= $silentAfterMs) {
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
  public static function isAvailable(int $lastSeenMs, int $nowMs, int $silentAfterMs): bool {
    return ($nowMs - $lastSeenMs) < $silentAfterMs;
  }

}
