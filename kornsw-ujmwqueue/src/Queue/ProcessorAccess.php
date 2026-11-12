<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Queue;

/**
 * Result of the processor authentication ('requestId' + 'pollingSalt' + 'pollingHash') for '_queue' requests.
 * 'isWithdrawn' is true if the hash matches an allocation that has been withdrawn in the meantime
 * (dequeue confirmation missed or lease lost and redelivered).
 */
final class ProcessorAccess {

  public function __construct(
    public readonly Entry $entry,
    public readonly bool $isWithdrawn
  ) {
  }

}
