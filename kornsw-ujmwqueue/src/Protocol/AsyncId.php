<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Protocol;

/**
 * 53-bit Snowflake-style id used as 'asyncId' (caller side) and 'requestId' (processor side):
 * 44 bits UTC milliseconds since 1900-01-01 (Snowflake44 epoch) shifted by 9 bits, plus 9 random bits.
 * Ids are always transported as JSON strings.
 */
final class AsyncId {

  /**
   * Milliseconds between 1900-01-01T00:00:00Z and the Unix epoch.
   */
  private const EPOCH_OFFSET_MS = 2208988800000;

  private const RANDOM_BITS = 9;

  /**
   * Creates a new id for the given Unix time in milliseconds.
   */
  public static function create(int $unixTimeMs): string {
    $snowflakeMs = $unixTimeMs + self::EPOCH_OFFSET_MS;
    $randomPart = random_int(0, (1 << self::RANDOM_BITS) - 1);
    return (string) (($snowflakeMs << self::RANDOM_BITS) | $randomPart);
  }

  /**
   * Returns true if the given string is a syntactically valid id (decimal, positive, at most 53 bits).
   */
  public static function isValid(?string $id): bool {
    if ($id === null || preg_match('/^[1-9][0-9]{0,15}$/', $id) !== 1) {
      return false;
    }
    return ((int) $id) < (1 << 53);
  }

  /**
   * Decodes the creation time (Unix milliseconds) of an id.
   */
  public static function decodeUnixTimeMs(string $id): int {
    return (((int) $id) >> self::RANDOM_BITS) - self::EPOCH_OFFSET_MS;
  }

}
