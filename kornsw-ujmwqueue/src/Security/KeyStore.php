<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Security;

use KornSW\UjmwQueue\Protocol\PollingAuth;

/**
 * Access keys of the relay. Every key has a role (caller or processor) and a route pattern. Only a SHA-256 hash of
 * each key is stored; the plain key is shown exactly once after its creation.
 */
final class KeyStore {

  public const OPTION_NAME = 'kornsw_uq_keys';

  public const ROLE_CALLER = 'caller';

  public const ROLE_PROCESSOR = 'processor';

  private const KEY_PREFIX = 'uq_';

  private const KEY_RANDOM_LENGTH = 40;

  /**
   * Returns all keys (without plain values).
   *
   * @return list<array{id: string, label: string, role: string, routes: string, hash: string, createdUtc: string}>
   */
  public function getAll(): array {
    $stored = get_option(self::OPTION_NAME, []);
    if (!is_array($stored)) {
      return [];
    }
    return array_values($stored);
  }

  /**
   * Creates a key and returns its plain value (to be shown once).
   */
  public function create(string $label, string $role, string $routes): string {
    if ($role !== self::ROLE_CALLER && $role !== self::ROLE_PROCESSOR) {
      throw new \InvalidArgumentException('Unknown role');
    }
    $plainKey = self::KEY_PREFIX . PollingAuth::generateRandomAlphanumeric(self::KEY_RANDOM_LENGTH);
    $keys = $this->getAll();
    $keys[] = [
      'id' => PollingAuth::generateRandomAlphanumeric(12),
      'label' => $label,
      'role' => $role,
      'routes' => $routes,
      'hash' => hash('sha256', $plainKey),
      'createdUtc' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    update_option(self::OPTION_NAME, $keys, false);
    return $plainKey;
  }

  /**
   * Deletes a key by id.
   */
  public function delete(string $keyId): void {
    $remainingKeys = [];
    foreach ($this->getAll() as $key) {
      if ($key['id'] !== $keyId) {
        $remainingKeys[] = $key;
      }
    }
    update_option(self::OPTION_NAME, $remainingKeys, false);
  }

  /**
   * Finds the key record of a plain key, or null.
   *
   * @return array{id: string, label: string, role: string, routes: string, hash: string, createdUtc: string}|null
   */
  public function findByPlainKey(string $plainKey): ?array {
    if (!str_starts_with($plainKey, self::KEY_PREFIX)) {
      return null;
    }
    $hash = hash('sha256', $plainKey);
    foreach ($this->getAll() as $key) {
      if (hash_equals((string) $key['hash'], $hash)) {
        return $key;
      }
    }
    return null;
  }

}
