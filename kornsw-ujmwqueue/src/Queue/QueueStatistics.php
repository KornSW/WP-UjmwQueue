<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Queue;

use KornSW\UjmwQueue\Clock;

/**
 * Builds the statistics of 'GET <route>/_queue' (queued / active entries, terminations in sliding windows,
 * processors with their last activity).
 */
final class QueueStatistics {

  public function __construct(
    private readonly EntryRepository $entries,
    private readonly TerminationLog $terminationLog
  ) {
  }

  /**
   * Returns the info document of a queue in the wire shape of the UJMW queue epic (section 5.1).
   *
   * @return array{statsTotal: object, statsPerMethod: object, processors: object, fault: null}
   */
  public function build(QueueRecord $queue): array {
    $nowMs = Clock::nowMs();
    $perMethod = [];

    foreach ($this->entries->countByMethodAndState($queue->id) as $row) {
      $method = (string) $row->method;
      if (!isset($perMethod[$method])) {
        $perMethod[$method] = self::createEmptyCounters();
      }
      $state = EntryState::from((string) $row->state);
      if ($state === EntryState::Queued) {
        $perMethod[$method]['queued'] += (int) $row->total;
      } elseif ($state === EntryState::Allocated || $state === EntryState::Leased) {
        $perMethod[$method]['active'] += (int) $row->total;
      }
    }

    foreach ($this->terminationLog->countPerMethod($queue->id, $nowMs) as $method => $windows) {
      if (!isset($perMethod[$method])) {
        $perMethod[$method] = self::createEmptyCounters();
      }
      foreach ($windows as $windowName => $count) {
        $perMethod[$method][$windowName] = $count;
      }
    }

    $total = self::createEmptyCounters();
    $perMethodObjects = [];
    ksort($perMethod, SORT_STRING | SORT_FLAG_CASE);
    foreach ($perMethod as $method => $counters) {
      foreach ($counters as $name => $value) {
        $total[$name] += $value;
      }
      $perMethodObjects[$method] = (object) $counters;
    }

    $activePerProcessor = $this->entries->countActivePerProcessor($queue->id);
    $processors = [];
    foreach ($queue->processors as $processorName => $lastActivityMs) {
      $processors[$processorName] = (object) [
        'active' => $activePerProcessor[$processorName] ?? 0,
        'lastActivity' => Clock::toIsoUtc($lastActivityMs),
      ];
    }

    return [
      'statsTotal' => (object) $total,
      'statsPerMethod' => (object) $perMethodObjects,
      'processors' => (object) $processors,
      'fault' => null,
    ];
  }

  /**
   * @return array<string, int>
   */
  private static function createEmptyCounters(): array {
    $counters = ['queued' => 0, 'active' => 0];
    foreach (array_keys(TerminationLog::WINDOWS) as $windowName) {
      $counters[$windowName] = 0;
    }
    return $counters;
  }

}
