<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Processors;

/**
 * Two-stage lock of a processor instance or an endpoint (queue).
 *
 * Stored level: 0 = none, 1 = drain (no new jobs, running jobs may finish), 2 = kill (running jobs were cancelled).
 * Displayed state: 'none' (grey), 'draining' (orange: level 1 with running jobs), 'locked' (red: level 1 without
 * running jobs, or level 2). Clicking cycles none -> draining/locked -> (draining only) killed -> none.
 */
final class LockState {

  public const LEVEL_NONE = 0;

  public const LEVEL_DRAIN = 1;

  public const LEVEL_KILL = 2;

  public const NONE = 'none';

  public const DRAINING = 'draining';

  public const LOCKED = 'locked';

  /**
   * The displayed state.
   */
  public static function of(int $level, int $activeJobs): string {
    if ($level <= self::LEVEL_NONE) {
      return self::NONE;
    }
    if ($level === self::LEVEL_DRAIN && $activeJobs > 0) {
      return self::DRAINING;
    }
    return self::LOCKED;
  }

  /**
   * The level after a click on an icon in the given displayed state.
   */
  public static function getNextLevel(string $displayedState): int {
    if ($displayedState === self::NONE) {
      return self::LEVEL_DRAIN;
    }
    if ($displayedState === self::DRAINING) {
      return self::LEVEL_KILL;
    }
    return self::LEVEL_NONE;
  }

}
