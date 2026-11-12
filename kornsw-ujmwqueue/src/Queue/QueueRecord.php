<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Queue;

/**
 * A learned queue: created on the first processor contact with '<route>/_queue'. The relay knows nothing about
 * the contract except the route and the method names it has seen (from callers and from processor filters).
 */
final class QueueRecord {

  /**
   * @param list<string> $methods learned transport-level method names
   * @param array<string, int> $processors processor name => last activity (Unix ms)
   */
  public function __construct(
    public readonly int $id,
    public readonly string $route,
    public readonly string $routeKey,
    public readonly array $methods,
    public readonly array $processors,
    public readonly int $createdMs,
    public readonly int $lastProcessorMs
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
    $processors = [];
    $decodedProcessors = json_decode((string) ($row->processors ?? ''), true);
    if (is_array($decodedProcessors)) {
      $processors = array_map('intval', $decodedProcessors);
    }
    return new QueueRecord(
      (int) $row->id,
      (string) $row->route,
      (string) $row->route_key,
      $methods,
      $processors,
      (int) $row->created_ms,
      (int) $row->last_processor_ms
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
