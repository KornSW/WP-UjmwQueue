<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Processors;

/**
 * Splits the processor name of a '_queue' request ('?processor=') into the stable agent name (identifies an agent
 * installation) and the instance discriminator (everything after the first '#', variable per process start).
 * Names without '#' get a synthetic discriminator assigned by the registry.
 */
final class ProcessorName {

  public const MAX_PART_LENGTH = 150;

  public const UNNAMED = 'unnamed';

  private function __construct(
    public readonly string $agentName,
    public readonly ?string $instanceName
  ) {
  }

  /**
   * Parses and sanitizes the raw name (control characters removed, parts limited in length).
   */
  public static function parse(?string $rawName): ProcessorName {
    $rawName = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $rawName));
    $separatorPosition = strpos($rawName, '#');
    if ($separatorPosition === false) {
      return new ProcessorName(self::limit($rawName, self::UNNAMED), null);
    }
    $agentName = self::limit(trim(substr($rawName, 0, $separatorPosition)), self::UNNAMED);
    $instanceName = trim(substr($rawName, $separatorPosition + 1));
    if ($instanceName === '') {
      return new ProcessorName($agentName, null);
    }
    return new ProcessorName($agentName, self::limit($instanceName, ''));
  }

  /**
   * The synthetic discriminator for names without '#': time of the first contact (or of the first contact after
   * being offline) as 'MM-dd#HH-mm-ss'.
   */
  public static function createSyntheticInstanceName(int $timestamp, ?\DateTimeZone $timeZone): string {
    $dateTime = (new \DateTimeImmutable('@' . $timestamp));
    if ($timeZone !== null) {
      $dateTime = $dateTime->setTimezone($timeZone);
    }
    return $dateTime->format('m-d#H-i-s');
  }

  /**
   * Cuts a name part to the maximum length; empty parts get the fallback.
   */
  private static function limit(string $value, string $fallback): string {
    if ($value === '') {
      return $fallback;
    }
    return mb_substr($value, 0, self::MAX_PART_LENGTH);
  }

}
