<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Queue;

/**
 * Result of a successful dequeue: the (tentatively) allocated entry and its per-allocation polling secret.
 */
final class Lease {

  public function __construct(
    public readonly Entry $entry,
    public readonly string $pollingSecret,
    public readonly int $totalInQueue
  ) {
  }

}
