<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue;

/**
 * Settings of the relay, stored in one array option. Defaults follow the UJMW queue epic (Q-TIMING, Q-CAP, Q-RET).
 * Every setting belongs to a section (= admin tab); saving a section leaves the other sections untouched.
 */
final class Settings {

  public const OPTION_NAME = 'kornsw_uq_settings';

  public const SECTION_SECURITY = 'security';

  public const SECTION_SETTINGS = 'settings';

  /**
   * Integer settings: name => [default, minimum, maximum].
   */
  private const INTEGER_SETTINGS = [
    'maxQueueSize' => [1000, 1, 100000],
    'incomingCallQueueWaitTimeoutSec' => [60, 1, 86400],
    'dequeueConfirmationTimeoutSec' => [2, 1, 60],
    'heartbeatSec' => [3, 1, 300],
    'leaseLossTimeoutSec' => [60, 5, 3600],
    'synchronousCallTimeoutSec' => [100, 1, 3600],
    'maxWaitSec' => [30, 0, 600],
    'callbackBufferLimit' => [1000, 1, 100000],
    'minOutcomePreservTime' => [10, 1, 3600],
    'maxOutcomePreservTime' => [300, 10, 86400],
    'outcomeCleanupLimit' => [100, 1, 100000],
    'throttlingLevel' => [1, 0, 100],
    'processorRetentionDays' => [30, 1, 3650],
    'endpointRetentionUsedDays' => [200, 1, 3650],
    'endpointRetentionUnusedDays' => [1, 1, 3650],
  ];

  /**
   * Boolean settings: name => [default, section].
   */
  private const BOOLEAN_SETTINGS = [
    'allowAnonymousInfo' => [false, self::SECTION_SETTINGS],
    'instanceRetentionEnabled' => [true, self::SECTION_SETTINGS],
    'processorRetentionEnabled' => [false, self::SECTION_SETTINGS],
    'endpointRetentionEnabled' => [false, self::SECTION_SETTINGS],
    'lockNewEndpoints' => [false, self::SECTION_SECURITY],
  ];

  public const DEFAULT_URL_PREFIX = 'ujmw';

  /** @var array<string, mixed>|null */
  private ?array $values = null;

  /**
   * Returns the default values of all settings.
   *
   * @return array<string, mixed>
   */
  public static function getDefaults(): array {
    $defaults = [
      'urlPrefix' => self::DEFAULT_URL_PREFIX,
      'notificationEmail' => '',
    ];
    foreach (self::BOOLEAN_SETTINGS as $name => $definition) {
      $defaults[$name] = $definition[0];
    }
    foreach (self::INTEGER_SETTINGS as $name => $definition) {
      $defaults[$name] = $definition[0];
    }
    return $defaults;
  }

  /**
   * Returns the names of all integer settings.
   *
   * @return list<string>
   */
  public static function getIntegerSettingNames(): array {
    return array_keys(self::INTEGER_SETTINGS);
  }

  /**
   * Returns an integer setting, clamped to its valid range.
   */
  public function getInt(string $name): int {
    $definition = self::INTEGER_SETTINGS[$name];
    $values = $this->load();
    $value = $definition[0];
    if (isset($values[$name]) && is_numeric($values[$name])) {
      $value = (int) $values[$name];
    }
    return max($definition[1], min($definition[2], $value));
  }

  /**
   * Returns a boolean setting.
   */
  public function getBool(string $name): bool {
    $definition = self::BOOLEAN_SETTINGS[$name];
    $values = $this->load();
    if (!array_key_exists($name, $values)) {
      return $definition[0];
    }
    return (bool) $values[$name];
  }

  /**
   * The first path segment below the site URL that is handled by the relay (e.g. 'ujmw').
   */
  public function getUrlPrefix(): string {
    $values = $this->load();
    $prefix = self::sanitizeUrlPrefix((string) ($values['urlPrefix'] ?? ''));
    if ($prefix === '') {
      return self::DEFAULT_URL_PREFIX;
    }
    return $prefix;
  }

  /**
   * Whether 'GET <route>/_queue' (statistics) may be called without an access key.
   */
  public function isAnonymousInfoAllowed(): bool {
    return $this->getBool('allowAnonymousInfo');
  }

  /**
   * Recipient of the admin notifications (falls back to the site's admin email).
   */
  public function getNotificationEmail(): string {
    $values = $this->load();
    $email = sanitize_email((string) ($values['notificationEmail'] ?? ''));
    if ($email === '') {
      return (string) get_option('admin_email');
    }
    return $email;
  }

  /**
   * The configured notification email as entered (may be empty).
   */
  public function getConfiguredNotificationEmail(): string {
    $values = $this->load();
    return (string) ($values['notificationEmail'] ?? '');
  }

  /**
   * Validates and stores the settings of one section submitted by the admin page; other sections stay unchanged.
   * Missing checkboxes of the section mean "off".
   *
   * @param array<string, mixed> $input
   */
  public function save(array $input, string $section): void {
    $values = $this->load();
    foreach (self::BOOLEAN_SETTINGS as $name => $definition) {
      if ($definition[1] === $section) {
        $values[$name] = !empty($input[$name]);
      }
    }
    if ($section === self::SECTION_SETTINGS) {
      $prefix = self::sanitizeUrlPrefix((string) ($input['urlPrefix'] ?? ''));
      if ($prefix !== '') {
        $values['urlPrefix'] = $prefix;
      }
      $values['notificationEmail'] = sanitize_email((string) ($input['notificationEmail'] ?? ''));
      foreach (self::INTEGER_SETTINGS as $name => $definition) {
        if (isset($input[$name]) && is_numeric($input[$name])) {
          $values[$name] = max($definition[1], min($definition[2], (int) $input[$name]));
        }
      }
    }
    update_option(self::OPTION_NAME, $values, false);
    $this->values = $values;
  }

  /**
   * Allows lower-case letters, digits, '-' and '_' and a single path segment only.
   */
  public static function sanitizeUrlPrefix(string $prefix): string {
    $prefix = strtolower(trim($prefix, " /\t\n\r"));
    if (preg_match('/^[a-z0-9_-]{1,40}$/', $prefix) !== 1) {
      return '';
    }
    return $prefix;
  }

  /**
   * Loads the option once per request.
   *
   * @return array<string, mixed>
   */
  private function load(): array {
    if ($this->values === null) {
      $stored = get_option(self::OPTION_NAME, []);
      if (!is_array($stored)) {
        $stored = [];
      }
      $this->values = array_merge(self::getDefaults(), $stored);
    }
    return $this->values;
  }

}
