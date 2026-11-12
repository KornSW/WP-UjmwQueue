<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Queue;

/**
 * A learned queue (= endpoint): created on the first processor contact with '<route>/_queue' (or by an in-process
 * client, see 'Api\QueueApi'). The relay knows nothing about the contract except the route and the method names it
 * has seen (from callers and from processor filters).
 */
final class QueueRecord {

  /**
   * @param list<string> $methods learned transport-level method names
   */
  public function __construct(
    public readonly int $id,
    public readonly string $route,
    public readonly string $routeKey,
    public readonly array $methods,
    public readonly int $createdMs,
    public readonly int $lastProcessorMs,
    public readonly int $lockLevel,
    public readonly bool $isMonitored,
    public readonly int $totalCalls
  ) {
  }

  /**
   * Creates a record from a database row.
   */
  public static function fromRow(object $row): QueueRecord {
    $methods = [];
    $decodedMethods = json_decode((string) ($row->methods ?? ''), true);
    if (is_array($decodedMethods)) {
      $methods = array_values(array_map('strval', $decodedMethods));
    }
    return new QueueRecord(
      (int) $row->id,
      (string) $row->route,
      (string) $row->route_key,
      $methods,
      (int) $row->created_ms,
      (int) $row->last_processor_ms,
      (int) ($row->lock_level ?? 0),
      ((int) ($row->monitoring ?? 0)) === 1,
      (int) ($row->total_calls ?? 0)
    );
  }

  /**
   * Returns a copy with other learned methods.
   *
   * @param list<string> $methods
   */
  public function withMethods(array $methods): QueueRecord {
    return new QueueRecord(
      $this->id,
      $this->route,
      $this->routeKey,
      $methods,
      $this->createdMs,
      $this->lastProcessorMs,
      $this->lockLevel,
      $this->isMonitored,
      $this->totalCalls
    );
  }

  /**
   * True if the method name (case-insensitive) has been learned already.
   */
  public function knowsMethod(string $methodName): bool {
    foreach ($this->methods as $knownMethod) {
      if (strcasecmp($knownMethod, $methodName) === 0) {
        return true;
      }
    }
    return false;
  }

}
