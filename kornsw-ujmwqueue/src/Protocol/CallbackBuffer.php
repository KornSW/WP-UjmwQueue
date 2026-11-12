<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Protocol;

use InvalidArgumentException;

/**
 * Entry-scoped buffer of callback invocations (UJMW callback wire format: '<callbackName>Invokes' arrays of
 * '{ "#": n, "arg1": ... }' items). The relay never interprets the items; it only buffers, counts and drains them.
 */
final class CallbackBuffer {

  public const INVOKES_SUFFIX = 'Invokes';

  /**
   * Parses the body of a heartbeat into a buffer. An empty body yields an empty buffer.
   *
   * @return array<string, list<mixed>>
   * @throws InvalidArgumentException on malformed input.
   */
  public static function parseInvocations(string $body): array {
    if (trim($body) === '') {
      return [];
    }
    $decoded = Json::decodeObject($body);
    if ($decoded === null) {
      throw new InvalidArgumentException('Heartbeat body must be a JSON object');
    }
    $buffer = [];
    foreach (get_object_vars($decoded) as $name => $value) {
      if (!str_ends_with((string) $name, self::INVOKES_SUFFIX)) {
        continue;
      }
      if ($value === null) {
        continue;
      }
      if (!is_array($value)) {
        throw new InvalidArgumentException("'" . $name . "' must be an array of callback invocations");
      }
      $buffer[(string) $name] = array_values($value);
    }
    return $buffer;
  }

  /**
   * Counts all buffered invocations.
   *
   * @param array<string, list<mixed>> $buffer
   */
  public static function countInvocations(array $buffer): int {
    $count = 0;
    foreach ($buffer as $invocations) {
      $count += count($invocations);
    }
    return $count;
  }

  /**
   * Appends the invocations of '$added' to '$existing' (order inside each array is preserved; the global order is
   * restored by the client via '#').
   *
   * @param array<string, list<mixed>> $existing
   * @param array<string, list<mixed>> $added
   * @return array<string, list<mixed>>
   */
  public static function merge(array $existing, array $added): array {
    foreach ($added as $name => $invocations) {
      if (array_key_exists($name, $existing)) {
        $existing[$name] = array_merge($existing[$name], $invocations);
      } else {
        $existing[$name] = $invocations;
      }
    }
    return $existing;
  }

  /**
   * Restores a buffer from its stored JSON form.
   *
   * @return array<string, list<mixed>>
   */
  public static function decodeStored(?string $storedJson): array {
    if ($storedJson === null || $storedJson === '') {
      return [];
    }
    $decoded = Json::decodeObject($storedJson);
    if ($decoded === null) {
      return [];
    }
    $buffer = [];
    foreach (get_object_vars($decoded) as $name => $value) {
      if (is_array($value)) {
        $buffer[(string) $name] = $value;
      }
    }
    return $buffer;
  }

  /**
   * Serializes a buffer for storage (null for an empty buffer).
   *
   * @param array<string, list<mixed>> $buffer
   */
  public static function encodeStored(array $buffer): ?string {
    if (self::countInvocations($buffer) === 0) {
      return null;
    }
    return Json::encode((object) $buffer);
  }

}
