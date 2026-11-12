<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Queue;

use KornSW\UjmwQueue\Http\RelayPath;
use KornSW\UjmwQueue\Schema;

/**
 * Persistence of the learned queues.
 */
final class QueueRepository {

  private const MAX_LEARNED_METHODS = 500;

  /**
   * Minimum gap before the processor activity time of a queue is written again (reduces write load).
   */
  private const PROCESSOR_TOUCH_INTERVAL_MS = 1000;

  /**
   * Finds a queue by its route (case-insensitive).
   */
  public function findByRoute(string $route): ?QueueRecord {
    global $wpdb;
    $table = Schema::queuesTable();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE route_key = %s", RelayPath::toRouteKey($route)));
    if ($row === null) {
      return null;
    }
    return QueueRecord::fromRow($row);
  }

  /**
   * Finds a queue by id.
   */
  public function findById(int $queueId): ?QueueRecord {
    global $wpdb;
    $table = Schema::queuesTable();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $queueId));
    if ($row === null) {
      return null;
    }
    return QueueRecord::fromRow($row);
  }

  /**
   * Returns the queue of the route and creates it if it does not exist yet (learning). The second element is true
   * if this call has created the queue.
   *
   * @return array{0: QueueRecord, 1: bool}
   */
  public function getOrCreate(string $route, int $nowMs, int $initialLockLevel): array {
    $existing = $this->findByRoute($route);
    if ($existing !== null) {
      return [$existing, false];
    }
    global $wpdb;
    // a concurrent request may create the same queue: the unique key rejects the second insert silently
    $suppressErrors = $wpdb->suppress_errors(true);
    $insertedRows = $wpdb->insert(
      Schema::queuesTable(),
      [
        'route_key' => RelayPath::toRouteKey($route),
        'route' => trim($route, '/'),
        'methods' => '[]',
        'processors' => '{}',
        'created_ms' => $nowMs,
        'last_processor_ms' => 0,
        'lock_level' => $initialLockLevel,
        'monitoring' => 0,
        'total_calls' => 0,
      ],
      ['%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d']
    );
    $wpdb->suppress_errors($suppressErrors);
    $created = $this->findByRoute($route);
    if ($created === null) {
      throw new \RuntimeException('Queue could not be created');
    }
    return [$created, ($insertedRows === 1)];
  }

  /**
   * Returns all queues ordered by route.
   *
   * @return list<QueueRecord>
   */
  public function getAll(): array {
    global $wpdb;
    $table = Schema::queuesTable();
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY route_key");
    $queues = [];
    foreach ($rows as $row) {
      $queues[] = QueueRecord::fromRow($row);
    }
    return $queues;
  }

  /**
   * Adds method names that are not known yet. Lost updates under concurrency are harmless (informational list).
   *
   * @param list<string> $methodNames
   */
  public function learnMethods(QueueRecord $queue, array $methodNames): QueueRecord {
    $methods = $queue->methods;
    $hasChanged = false;
    foreach ($methodNames as $methodName) {
      if (count($methods) >= self::MAX_LEARNED_METHODS) {
        break;
      }
      $isKnown = false;
      foreach ($methods as $knownMethod) {
        if (strcasecmp($knownMethod, $methodName) === 0) {
          $isKnown = true;
          break;
        }
      }
      if (!$isKnown) {
        $methods[] = $methodName;
        $hasChanged = true;
      }
    }
    if (!$hasChanged) {
      return $queue;
    }
    sort($methods, SORT_STRING | SORT_FLAG_CASE);
    global $wpdb;
    $wpdb->update(Schema::queuesTable(), ['methods' => wp_json_encode($methods)], ['id' => $queue->id], ['%s'], ['%d']);
    return $queue->withMethods($methods);
  }

  /**
   * Records the last processor activity on the queue (reference time of the endpoint retention).
   */
  public function touchProcessor(QueueRecord $queue, int $nowMs): void {
    if (($nowMs - $queue->lastProcessorMs) < self::PROCESSOR_TOUCH_INTERVAL_MS) {
      return;
    }
    global $wpdb;
    $wpdb->update(Schema::queuesTable(), ['last_processor_ms' => $nowMs], ['id' => $queue->id], ['%d'], ['%d']);
  }

  /**
   * Counts an accepted call (distinguishes used endpoints from only announced ones for the retention).
   */
  public function incrementCalls(int $queueId): void {
    global $wpdb;
    $table = Schema::queuesTable();
    $wpdb->query($wpdb->prepare("UPDATE {$table} SET total_calls = total_calls + 1 WHERE id = %d", $queueId));
  }

  /**
   * Sets the lock level of a queue.
   */
  public function setLockLevel(int $queueId, int $lockLevel): void {
    global $wpdb;
    $wpdb->update(Schema::queuesTable(), ['lock_level' => $lockLevel], ['id' => $queueId], ['%d'], ['%d']);
  }

  /**
   * Switches the monitoring (admin notifications) of a queue.
   */
  public function setMonitoring(int $queueId, bool $isMonitored): void {
    global $wpdb;
    $wpdb->update(Schema::queuesTable(), ['monitoring' => (int) $isMonitored], ['id' => $queueId], ['%d'], ['%d']);
  }

  /**
   * Deletes a queue together with all its entries, statistics and processor links.
   */
  public function delete(int $queueId): void {
    global $wpdb;
    $wpdb->delete(Schema::entriesTable(), ['queue_id' => $queueId], ['%d']);
    $wpdb->delete(Schema::terminationsTable(), ['queue_id' => $queueId], ['%d']);
    $wpdb->delete(Schema::processorQueuesTable(), ['queue_id' => $queueId], ['%d']);
    $wpdb->delete(Schema::queuesTable(), ['id' => $queueId], ['%d']);
  }

}
