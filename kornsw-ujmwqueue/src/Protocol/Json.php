<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Protocol;

use JsonException;
use stdClass;

/**
 * JSON helpers of the relay. Passed-through UJMW payloads are treated as opaque JSON objects and embedded
 * byte-for-byte wherever possible (see 'RawJson'), so the relay never needs to know the contract.
 */
final class Json {

  public const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

  private const MAX_DEPTH = 512;

  /**
   * Encodes a value with the relay's standard flags.
   */
  public static function encode(mixed $value): string {
    return json_encode($value, self::ENCODE_FLAGS);
  }

  /**
   * Decodes a JSON object (objects stay 'stdClass', so '{}' and '[]' keep their meaning). Returns null if the input
   * is not valid JSON or not an object.
   */
  public static function decodeObject(string $json): ?stdClass {
    try {
      $value = json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
      return null;
    }
    if ($value instanceof stdClass) {
      return $value;
    }
    return null;
  }

  /**
   * Builds a JSON object from members in the given order. 'RawJson' values are embedded unchanged.
   *
   * @param array<string, mixed> $members
   */
  public static function buildObject(array $members): string {
    $parts = [];
    foreach ($members as $name => $value) {
      $parts[] = self::encode((string) $name) . ':' . self::encodeMemberValue($value);
    }
    return '{' . implode(',', $parts) . '}';
  }

  /**
   * Adds members to an existing raw JSON object. Without name collisions the raw object is kept byte-for-byte;
   * on collisions the object is re-encoded: arrays on both sides (callback '...Invokes') are concatenated,
   * other values are replaced.
   *
   * @param array<string, mixed> $members
   */
  public static function addMembers(string $rawObjectJson, array $members): string {
    if (count($members) === 0) {
      return $rawObjectJson;
    }
    $decoded = self::decodeObject($rawObjectJson);
    if ($decoded === null) {
      $decoded = new stdClass();
      $rawObjectJson = '{}';
    }

    $hasCollision = false;
    foreach (array_keys($members) as $name) {
      if (property_exists($decoded, (string) $name)) {
        $hasCollision = true;
        break;
      }
    }

    if (!$hasCollision) {
      $inner = trim(substr(trim($rawObjectJson), 1, -1));
      $added = substr(self::buildObject($members), 1, -1);
      if ($inner === '') {
        return '{' . $added . '}';
      }
      return '{' . $inner . ',' . $added . '}';
    }

    foreach ($members as $name => $value) {
      $name = (string) $name;
      if ($value instanceof RawJson) {
        $value = json_decode($value->json, false, self::MAX_DEPTH);
      }
      if (property_exists($decoded, $name) && is_array($decoded->{$name}) && is_array($value)) {
        $decoded->{$name} = array_merge($decoded->{$name}, $value);
      } else {
        $decoded->{$name} = $value;
      }
    }
    return self::encode($decoded);
  }

  /**
   * Encodes a single member value.
   */
  private static function encodeMemberValue(mixed $value): string {
    if ($value instanceof RawJson) {
      return $value->json;
    }
    return self::encode($value);
  }

}
