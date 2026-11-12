<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Queue;

use KornSW\UjmwQueue\Schema;

/**
 * Persistence of queue entries. Every state change goes through 'tryUpdate()' (optimistic concurrency on
 * 'row_version'), because PHP requests of callers and processors run in parallel without shared memory.
 */
final class EntryRepository {

  /**
   * Inserts a new entry. Returns false if the id already exists (caller retries with a new id).
   *
   * @param array<string, mixed> $values column => value
   */
  public function insert(array $values): bool {
    global $wpdb;
    $suppressErrors = $wpdb->suppress_errors(true);
    $result = $wpdb->insert(Schema::entriesTable(), $values);
    $wpdb->suppress_errors($suppressErrors);
    return ($result === 1);
  }

  /**
   * Loads an entry by id.
   */
  public function find(string $entryId): ?Entry {
    global $wpdb;
    $table = Schema::entriesTable();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %s", $entryId));
    if ($row === null) {
      return null;
    }
    return Entry::fromRow($row);
  }

  /**
   * Applies changes if the entry was not modified concurrently. Returns true on success.
   *
   * @param array<string, mixed> $changes column => value (null values are written as SQL NULL)
   */
  public function tryUpdate(Entry $entry, array $changes): bool {
    global $wpdb;
    $changes['row_version'] = $entry->rowVersion + 1;
    $result = $wpdb->update(Schema::entriesTable(), $changes, ['id' => $entry->id, 'row_version' => $entry->rowVersion]);
    return ($result === 1);
  }

  /**
   * Raises the stored 'last_nbf_ms' (used for the per-operation outcome retention extension). Does not change the
   * row version, so it never conflicts with state changes.
   */
  public function raiseLastNbf(string $entryId, int $nbfMs): void {
    global $wpdb;
    $table = Schema::entriesTable();
    $wpdb->query($wpdb->prepare("UPDATE {$table} SET last_nbf_ms = %d WHERE id = %s AND last_nbf_ms < %d", $nbfMs, $entryId, $nbfMs));
  }

  /**
   * Returns queued entries in FIFO order (position, id), optionally restricted by method names.
   *
   * @param list<string>|null $methodNames null = all methods
   * @return list<Entry>
   */
  public function findQueued(int $queueId, ?array $methodNames, bool $isExcludingNames, int $limit, int $offset): array {
    global $wpdb;
    $table = Schema::entriesTable();
    $sql = "SELECT * FROM {$table} WHERE queue_id = %d AND state = %s";
    $arguments = [$queueId, EntryState::Queued->value];
    if ($methodNames !== null && count($methodNames) > 0) {
      $placeholders = implode(',', array_fill(0, count($methodNames), '%s'));
      if ($isExcludingNames) {
        $sql .= " AND method NOT IN ({$placeholders})";
      } else {
        $sql .= " AND method IN ({$placeholders})";
      }
      $arguments = array_merge($arguments, $methodNames);
    }
    $sql .= ' ORDER BY position ASC, id ASC LIMIT %d OFFSET %d';
    $arguments[] = $limit;
    $arguments[] = $offset;
    $rows = $wpdb->get_results($wpdb->prepare($sql, $arguments));
    $entries = [];
    foreach ($rows as $row) {
      $entries[] = Entry::fromRow($row);
    }
    return $entries;
  }

  /**
   * Returns the ascending sort positions of the first queued entries.
   *
   * @return list<float>
   */
  public function getQueuedPositions(int $queueId, int $limit): array {
    global $wpdb;
    $table = Schema::entriesTable();
    $values = $wpdb->get_col($wpdb->prepare(
      "SELECT position FROM {$table} WHERE queue_id = %d AND state = %s ORDER BY position ASC, id ASC LIMIT %d",
      $queueId,
      EntryState::Queued->value,
      $limit
    ));
    return array_map('floatval', $values);
  }

  /**
   * Counts the entries of a queue in the given states.
   *
   * @param list<EntryState> $states
   */
  public function countInStates(int $queueId, array $states): int {
    global $wpdb;
    $table = Schema::entriesTable();
    $stateValues = array_map(fn (EntryState $state): string => $state->value, $states);
    $placeholders = implode(',', array_fill(0, count($stateValues), '%s'));
    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE queue_id = %d AND state IN ({$placeholders})",
      array_merge([$queueId], $stateValues)
    ));
  }

  /**
   * Counts all non-terminated entries of all queues (input of the poll throttling).
   */
  public function countAllUnterminated(): int {
    global $wpdb;
    $table = Schema::entriesTable();
    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE state IN (%s, %s, %s)",
      EntryState::Queued->value,
      EntryState::Allocated->value,
      EntryState::Leased->value
    ));
  }

  /**
   * Returns entries in the given state whose reference time column is older than the threshold.
   *
   * @return list<Entry>
   */
  public function findStale(EntryState $state, string $timeColumn, int $olderThanMs, int $limit): array {
    global $wpdb;
    if (!in_array($timeColumn, ['allocated_ms', 'heartbeat_ms'], true)) {
      throw new \InvalidArgumentException('Unsupported time column');
    }
    $table = Schema::entriesTable();
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM {$table} WHERE state = %s AND {$timeColumn} < %d ORDER BY {$timeColumn} ASC LIMIT %d",
      $state->value,
      $olderThanMs,
      $limit
    ));
    $entries = [];
    foreach ($rows as $row) {
      $entries[] = Entry::fromRow($row);
    }
    return $entries;
  }

  /**
   * Deletes retained outcomes according to the retention rules of the long-running feature (Q-RET):
   * each outcome lives at least 'min' seconds; above 'cleanupLimit' the oldest are removed; none lives longer than
   * 'max' seconds after termination or after the last 'pollNbf' sent to the caller.
   */
  public function deleteExpiredOutcomes(int $nowMs, int $minPreserveSec, int $maxPreserveSec, int $cleanupLimit): void {
    global $wpdb;
    $table = Schema::entriesTable();
    $terminal = [EntryState::Completed->value, EntryState::Failed->value, EntryState::Cancelled->value];

    $wpdb->query($wpdb->prepare(
      "DELETE FROM {$table} WHERE state IN (%s, %s, %s) AND terminated_ms < %d AND last_nbf_ms < %d",
      $terminal[0],
      $terminal[1],
      $terminal[2],
      $nowMs - ($maxPreserveSec * 1000),
      $nowMs - ($maxPreserveSec * 1000)
    ));

    $retainedCount = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE state IN (%s, %s, %s)",
      $terminal[0],
      $terminal[1],
      $terminal[2]
    ));
    if ($retainedCount <= $cleanupLimit) {
      return;
    }
    $oldestIds = $wpdb->get_col($wpdb->prepare(
      "SELECT id FROM {$table} WHERE state IN (%s, %s, %s) AND terminated_ms < %d ORDER BY terminated_ms ASC LIMIT %d",
      $terminal[0],
      $terminal[1],
      $terminal[2],
      $nowMs - ($minPreserveSec * 1000),
      $retainedCount - $cleanupLimit
    ));
    if (count($oldestIds) === 0) {
      return;
    }
    $placeholders = implode(',', array_fill(0, count($oldestIds), '%s'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $oldestIds));
  }

  /**
   * Returns the number of entries per method and state of one queue.
   *
   * @return list<object{method: string, state: string, total: string}>
   */
  public function countByMethodAndState(int $queueId): array {
    global $wpdb;
    $table = Schema::entriesTable();
    return $wpdb->get_results($wpdb->prepare(
      "SELECT method, state, COUNT(*) AS total FROM {$table} WHERE queue_id = %d GROUP BY method, state",
      $queueId
    ));
  }

  /**
   * Returns the number of active (allocated or leased) entries per processor name of one queue.
   *
   * @return array<string, int>
   */
  public function countActivePerProcessor(int $queueId): array {
    global $wpdb;
    $table = Schema::entriesTable();
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT processor_name, COUNT(*) AS total FROM {$table} WHERE queue_id = %d AND state IN (%s, %s) GROUP BY processor_name",
      $queueId,
      EntryState::Allocated->value,
      EntryState::Leased->value
    ));
    $counts = [];
    foreach ($rows as $row) {
      $counts[(string) $row->processor_name] = (int) $row->total;
    }
    return $counts;
  }

}
