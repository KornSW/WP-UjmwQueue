<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Protocol;

/**
 * Polling authentication of the UJMW long-running and queue protocols (port of 'UjmwAsyncProtocol').
 * pollingHash = Base64Url(SHA-256(UTF-8(pollingSecret + pollingSalt))) without padding.
 */
final class PollingAuth {

  private const ALPHANUMERIC_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

  /**
   * Length of a generated polling secret (8 alphanumeric characters, as defined by the protocol).
   */
  public const SECRET_LENGTH = 8;

  /**
   * Generates a cryptographically random polling secret.
   */
  public static function generateSecret(): string {
    return self::generateRandomAlphanumeric(self::SECRET_LENGTH);
  }

  /**
   * Generates a cryptographically random string of the given length using 'A-Z', 'a-z' and '0-9'.
   */
  public static function generateRandomAlphanumeric(int $length): string {
    $result = '';
    $lastIndex = strlen(self::ALPHANUMERIC_CHARS) - 1;
    for ($index = 0; $index < $length; $index++) {
      $result .= self::ALPHANUMERIC_CHARS[random_int(0, $lastIndex)];
    }
    return $result;
  }

  /**
   * Computes the polling hash for the given secret and salt.
   */
  public static function computeHash(string $pollingSecret, string $pollingSalt): string {
    $rawHash = hash('sha256', $pollingSecret . $pollingSalt, true);
    return rtrim(strtr(base64_encode($rawHash), '+/', '-_'), '=');
  }

  /**
   * Verifies a polling hash with a constant-time comparison. Empty values never verify.
   */
  public static function verify(?string $pollingSecret, ?string $pollingSalt, ?string $pollingHash): bool {
    if ($pollingSecret === null || $pollingSecret === '') {
      return false;
    }
    if ($pollingSalt === null || $pollingSalt === '' || strlen($pollingSalt) > 64) {
      return false;
    }
    if ($pollingHash === null || $pollingHash === '') {
      return false;
    }
    return hash_equals(self::computeHash($pollingSecret, $pollingSalt), $pollingHash);
  }

}
