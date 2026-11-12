<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Queue;

use KornSW\UjmwQueue\Schema;

/**
 * Timestamps of terminated entries for the sliding-window counters 'past1m' ... 'past10h' (decision Q-STATS).
 * Kept separately because outcomes themselves are evicted after a few minutes.
 */
final class TerminationLog {

  /**
   * Window name => window length in milliseconds.
   */
  public const WINDOWS = [
    'past1m' => 60000,
    'past10m' => 600000,
    'past1h' => 3600000,
    'past10h' => 36000000,
  ];

  /**
   * Records one termination.
   */
  public function record(int $queueId, string $method, int $nowMs): void {
    global $wpdb;
    $wpdb->insert(
      Schema::terminationsTable(),
      ['queue_id' => $queueId, 'method' => $method, 'terminated_ms' => $nowMs],
      ['%d', '%s', '%d']
    );
  }

  /**
   * Removes records older than the largest window.
   */
  public function prune(int $nowMs): void {
    global $wpdb;
    $table = Schema::terminationsTable();
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE terminated_ms < %d", $nowMs - self::WINDOWS['past10h']));
  }

  /**
   * Returns the window counters per method of one queue.
   *
   * @return array<string, array<string, int>> method => window name => count
   */
  public function countPerMethod(int $queueId, int $nowMs): array {
    global $wpdb;
    $table = Schema::terminationsTable();
    $selects = [];
    $arguments = [];
    foreach (self::WINDOWS as $windowName => $windowMs) {
      $selects[] = "SUM(CASE WHEN terminated_ms >= %d THEN 1 ELSE 0 END) AS {$windowName}";
      $arguments[] = $nowMs - $windowMs;
    }
    $arguments[] = $queueId;
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT method, ' . implode(', ', $selects) . " FROM {$table} WHERE queue_id = %d GROUP BY method",
      $arguments
    ));
    $result = [];
    foreach ($rows as $row) {
      $counters = [];
      foreach (array_keys(self::WINDOWS) as $windowName) {
        $counters[$windowName] = (int) $row->{$windowName};
      }
      $result[(string) $row->method] = $counters;
    }
    return $result;
  }

}
