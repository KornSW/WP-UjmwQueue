<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Processors;

use KornSW\UjmwQueue\Queue\QueueRecord;
use KornSW\UjmwQueue\Schema;

/**
 * Persistence of the processor instances and of the queues each instance has polled. Every '_queue' request of a
 * processor touches its instance (liveness) and the link to the queue (availability, known agents per endpoint).
 */
final class ProcessorRegistry {

  /**
   * Minimum gap before the same activity time is written again (reduces write load).
   */
  private const TOUCH_INTERVAL_MS = 1000;

  /**
   * Returns the instance for the processor name of a request and records its activity. Names without '#' get a
   * synthetic discriminator: the time of the first contact, renewed after the instance has been offline.
   */
  public function touch(ProcessorName $name, int $nowMs): ProcessorInstance {
    $instance = null;
    if ($name->instanceName !== null) {
      $instance = $this->findByName($name->agentName, $name->instanceName);
      if ($instance === null) {
        $instance = $this->insert($name->agentName, $name->instanceName, false, $nowMs);
      }
    } else {
      $instance = $this->findLatestSynthetic($name->agentName);
      if ($instance === null || ($nowMs - $instance->lastSeenMs) >= Liveness::OFFLINE_AFTER_MS) {
        $instanceName = ProcessorName::createSyntheticInstanceName(intdiv($nowMs, 1000), wp_timezone());
        $instance = $this->insert($name->agentName, $instanceName, true, $nowMs);
      }
    }
    if (($nowMs - $instance->lastSeenMs) < self::TOUCH_INTERVAL_MS) {
      return $instance;
    }
    global $wpdb;
    $wpdb->update(Schema::processorsTable(), ['last_seen_ms' => $nowMs], ['id' => $instance->id], ['%d'], ['%d']);
    return new ProcessorInstance(
      $instance->id,
      $instance->agentName,
      $instance->instanceName,
      $instance->isSynthetic,
      $instance->firstSeenMs,
      $nowMs,
      $instance->lockLevel
    );
  }

  /**
   * Records that the instance has polled the queue.
   */
  public function link(ProcessorInstance $instance, int $queueId, int $nowMs): void {
    global $wpdb;
    $table = Schema::processorQueuesTable();
    $lastSeen = $wpdb->get_var($wpdb->prepare(
      "SELECT last_seen_ms FROM {$table} WHERE processor_id = %d AND queue_id = %d",
      $instance->id,
      $queueId
    ));
    if ($lastSeen === null) {
      $suppressErrors = $wpdb->suppress_errors(true);
      $wpdb->insert($table, ['processor_id' => $instance->id, 'queue_id' => $queueId, 'last_seen_ms' => $nowMs], ['%d', '%d', '%d']);
      $wpdb->suppress_errors($suppressErrors);
      return;
    }
    if (($nowMs - (int) $lastSeen) >= self::TOUCH_INTERVAL_MS) {
      $wpdb->update($table, ['last_seen_ms' => $nowMs], ['processor_id' => $instance->id, 'queue_id' => $queueId], ['%d'], ['%d', '%d']);
    }
  }

  /**
   * Loads an instance by id.
   */
  public function findById(int $processorId): ?ProcessorInstance {
    global $wpdb;
    $table = Schema::processorsTable();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $processorId));
    if ($row === null) {
      return null;
    }
    return ProcessorInstance::fromRow($row);
  }

  /**
   * Returns all instances ordered by agent name and first contact.
   *
   * @return list<ProcessorInstance>
   */
  public function getAll(): array {
    global $wpdb;
    $table = Schema::processorsTable();
    return self::toInstances($wpdb->get_results("SELECT * FROM {$table} ORDER BY agent_name ASC, first_seen_ms ASC, id ASC"));
  }

  /**
   * Returns the instances that have polled the queue.
   *
   * @return list<ProcessorInstance>
   */
  public function getForQueue(int $queueId): array {
    global $wpdb;
    $processorsTable = Schema::processorsTable();
    $linksTable = Schema::processorQueuesTable();
    return self::toInstances($wpdb->get_results($wpdb->prepare(
      "SELECT p.* FROM {$processorsTable} p INNER JOIN {$linksTable} l ON l.processor_id = p.id WHERE l.queue_id = %d ORDER BY p.agent_name ASC, p.first_seen_ms ASC",
      $queueId
    )));
  }

  /**
   * Returns all links between instances and queues.
   *
   * @return list<array{processorId: int, queueId: int, lastSeenMs: int}>
   */
  public function getLinks(): array {
    global $wpdb;
    $table = Schema::processorQueuesTable();
    $links = [];
    foreach ($wpdb->get_results("SELECT processor_id, queue_id, last_seen_ms FROM {$table}") as $row) {
      $links[] = ['processorId' => (int) $row->processor_id, 'queueId' => (int) $row->queue_id, 'lastSeenMs' => (int) $row->last_seen_ms];
    }
    return $links;
  }

  /**
   * True if the queue is not locked and at least one unlocked, available ("green") instance has polled it within
   * the last hour. Incoming calls are rejected immediately otherwise ("No processor available").
   */
  public function isAvailableFor(QueueRecord $queue, int $nowMs): bool {
    if ($queue->lockLevel !== LockState::LEVEL_NONE) {
      return false;
    }
    global $wpdb;
    $processorsTable = Schema::processorsTable();
    $linksTable = Schema::processorQueuesTable();
    $count = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$processorsTable} p INNER JOIN {$linksTable} l ON l.processor_id = p.id WHERE l.queue_id = %d AND p.lock_level = 0 AND p.last_seen_ms >= %d AND l.last_seen_ms >= %d",
      $queue->id,
      $nowMs - Liveness::SILENT_AFTER_MS,
      $nowMs - Liveness::OFFLINE_AFTER_MS
    ));
    return $count > 0;
  }

  /**
   * Sets the lock level of an instance.
   */
  public function setLockLevel(int $processorId, int $lockLevel): void {
    global $wpdb;
    $wpdb->update(Schema::processorsTable(), ['lock_level' => $lockLevel], ['id' => $processorId], ['%d'], ['%d']);
  }

  /**
   * Deletes instances together with their queue links.
   *
   * @param list<int> $processorIds
   */
  public function delete(array $processorIds): void {
    if (count($processorIds) === 0) {
      return;
    }
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($processorIds), '%d'));
    $linksTable = Schema::processorQueuesTable();
    $processorsTable = Schema::processorsTable();
    $wpdb->query($wpdb->prepare("DELETE FROM {$linksTable} WHERE processor_id IN ({$placeholders})", $processorIds));
    $wpdb->query($wpdb->prepare("DELETE FROM {$processorsTable} WHERE id IN ({$placeholders})", $processorIds));
  }

  /**
   * Removes all links of a queue (the queue is being deleted).
   */
  public function unlinkQueue(int $queueId): void {
    global $wpdb;
    $wpdb->delete(Schema::processorQueuesTable(), ['queue_id' => $queueId], ['%d']);
  }

  /**
   * Returns the number of links per queue id.
   *
   * @return array<int, int>
   */
  public function countLinksPerQueue(): array {
    global $wpdb;
    $table = Schema::processorQueuesTable();
    $counts = [];
    foreach ($wpdb->get_results("SELECT queue_id, COUNT(*) AS total FROM {$table} GROUP BY queue_id") as $row) {
      $counts[(int) $row->queue_id] = (int) $row->total;
    }
    return $counts;
  }

  /**
   * Finds an instance by agent and instance name.
   */
  private function findByName(string $agentName, string $instanceName): ?ProcessorInstance {
    global $wpdb;
    $table = Schema::processorsTable();
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE agent_name = %s AND instance_name = %s",
      $agentName,
      $instanceName
    ));
    if ($row === null) {
      return null;
    }
    return ProcessorInstance::fromRow($row);
  }

  /**
   * Finds the most recently active instance with a synthetic discriminator of an agent.
   */
  private function findLatestSynthetic(string $agentName): ?ProcessorInstance {
    global $wpdb;
    $table = Schema::processorsTable();
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE agent_name = %s AND is_synthetic = 1 ORDER BY last_seen_ms DESC LIMIT 1",
      $agentName
    ));
    if ($row === null) {
      return null;
    }
    return ProcessorInstance::fromRow($row);
  }

  /**
   * Inserts an instance; a concurrent insert of the same name is resolved by reloading.
   */
  private function insert(string $agentName, string $instanceName, bool $isSynthetic, int $nowMs): ProcessorInstance {
    global $wpdb;
    $suppressErrors = $wpdb->suppress_errors(true);
    $wpdb->insert(
      Schema::processorsTable(),
      [
        'agent_name' => $agentName,
        'instance_name' => $instanceName,
        'is_synthetic' => (int) $isSynthetic,
        'first_seen_ms' => $nowMs,
        'last_seen_ms' => $nowMs,
        'lock_level' => LockState::LEVEL_NONE,
      ],
      ['%s', '%s', '%d', '%d', '%d', '%d']
    );
    $wpdb->suppress_errors($suppressErrors);
    $instance = $this->findByName($agentName, $instanceName);
    if ($instance === null) {
      throw new \RuntimeException('Processor instance could not be registered');
    }
    return $instance;
  }

  /**
   * @param list<object> $rows
   * @return list<ProcessorInstance>
   */
  private static function toInstances(array $rows): array {
    $instances = [];
    foreach ($rows as $row) {
      $instances[] = ProcessorInstance::fromRow($row);
    }
    return $instances;
  }

}
