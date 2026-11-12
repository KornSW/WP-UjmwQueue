<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Processors;

/**
 * One running instance of a processor agent ('<agent>#<instance>').
 */
final class ProcessorInstance {

  public function __construct(
    public readonly int $id,
    public readonly string $agentName,
    public readonly string $instanceName,
    public readonly bool $isSynthetic,
    public readonly int $firstSeenMs,
    public readonly int $lastSeenMs,
    public readonly int $lockLevel
  ) {
  }

  /**
   * Creates an instance from a database row.
   */
  public static function fromRow(object $row): ProcessorInstance {
    return new ProcessorInstance(
      (int) $row->id,
      (string) $row->agent_name,
      (string) $row->instance_name,
      ((int) $row->is_synthetic) === 1,
      (int) $row->first_seen_ms,
      (int) $row->last_seen_ms,
      (int) $row->lock_level
    );
  }

  /**
   * The full name '<agent>#<instance>'.
   */
  public function getFullName(): string {
    return $this->agentName . '#' . $this->instanceName;
  }

}
