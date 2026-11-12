<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Protocol;

/**
 * Marks an already serialized JSON value that must be embedded byte-for-byte (e.g. a passed-through UJMW request).
 */
final class RawJson {

  public function __construct(public readonly string $json) {
  }

}
