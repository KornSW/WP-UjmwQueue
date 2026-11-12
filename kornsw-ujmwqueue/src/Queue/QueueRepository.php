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

  private const MAX_TRACKED_PROCESSORS = 100;

  /**
   * Minimum gap before the activity time of the same processor is written again (reduces write load).
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
   * Returns the queue of the route and creates it if it does not exist yet (learning on first processor contact).
   */
  public function getOrCreate(string $route, int $nowMs): QueueRecord {
    $existing = $this->findByRoute($route);
    if ($existing !== null) {
      return $existing;
    }
    global $wpdb;
    // a concurrent request may create the same queue: the unique key rejects the second insert silently
    $suppressErrors = $wpdb->suppress_errors(true);
    $wpdb->insert(
      Schema::queuesTable(),
      [
        'route_key' => RelayPath::toRouteKey($route),
        'route' => trim($route, '/'),
        'methods' => '[]',
        'processors' => '{}',
        'created_ms' => $nowMs,
        'last_processor_ms' => 0,
      ],
      ['%s', '%s', '%s', '%s', '%d', '%d']
    );
    $wpdb->suppress_errors($suppressErrors);
    $created = $this->findByRoute($route);
    if ($created === null) {
      throw new \RuntimeException('Queue could not be created');
    }
    return $created;
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
    return new QueueRecord($queue->id, $queue->route, $queue->routeKey, $methods, $queue->processors, $queue->createdMs, $queue->lastProcessorMs);
  }

  /**
   * Records the activity of a processor (informational name, see decision Q-PROC of the UJMW epic).
   */
  public function touchProcessor(QueueRecord $queue, string $processorName, int $nowMs): QueueRecord {
    $processors = $queue->processors;
    $lastActivity = $processors[$processorName] ?? 0;
    if (($nowMs - $lastActivity) < self::PROCESSOR_TOUCH_INTERVAL_MS) {
      return $queue;
    }
    $processors[$processorName] = $nowMs;
    if (count($processors) > self::MAX_TRACKED_PROCESSORS) {
      arsort($processors);
      $processors = array_slice($processors, 0, self::MAX_TRACKED_PROCESSORS, true);
    }
    global $wpdb;
    $wpdb->update(
      Schema::queuesTable(),
      ['processors' => wp_json_encode((object) $processors), 'last_processor_ms' => $nowMs],
      ['id' => $queue->id],
      ['%s', '%d'],
      ['%d']
    );
    return new QueueRecord($queue->id, $queue->route, $queue->routeKey, $queue->methods, $processors, $queue->createdMs, $nowMs);
  }

  /**
   * Deletes a queue together with all its entries and statistics (explicit admin action only).
   */
  public function delete(int $queueId): void {
    global $wpdb;
    $wpdb->delete(Schema::entriesTable(), ['queue_id' => $queueId], ['%d']);
    $wpdb->delete(Schema::terminationsTable(), ['queue_id' => $queueId], ['%d']);
    $wpdb->delete(Schema::queuesTable(), ['id' => $queueId], ['%d']);
  }

}
