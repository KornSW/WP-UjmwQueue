<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Protocol;

use InvalidArgumentException;

/**
 * The 'dequeue' / 'dequeueNext' method filter of the '_queue' protocol:
 * '*' = any method, 'm1,m4' = whitelist, '!m1,!m4' = blacklist. Mixed lists are rejected.
 * Method names are transport-level names and are compared case-insensitively (like ASP.NET routing).
 */
final class MethodFilter {

  /**
   * @param list<string> $names
   */
  private function __construct(
    private readonly bool $matchesAll,
    private readonly array $names,
    private readonly bool $isBlacklist
  ) {
  }

  /**
   * Parses a filter expression.
   *
   * @throws InvalidArgumentException if the expression is empty, malformed or mixes positive and negated items.
   */
  public static function parse(string $expression): MethodFilter {
    $expression = trim($expression);
    if ($expression === '') {
      throw new InvalidArgumentException('Empty method filter');
    }
    if ($expression === '*') {
      return new MethodFilter(true, [], false);
    }

    $positiveNames = [];
    $negatedNames = [];
    foreach (explode(',', $expression) as $rawItem) {
      $item = trim($rawItem);
      $isNegated = str_starts_with($item, '!');
      if ($isNegated) {
        $item = trim(substr($item, 1));
      }
      if (!self::isValidMethodName($item)) {
        throw new InvalidArgumentException("Invalid method name '" . $item . "' in method filter");
      }
      if ($isNegated) {
        $negatedNames[] = $item;
      } else {
        $positiveNames[] = $item;
      }
    }

    if (count($positiveNames) > 0 && count($negatedNames) > 0) {
      throw new InvalidArgumentException('Method filter must not mix positive and negated items');
    }
    if (count($negatedNames) > 0) {
      return new MethodFilter(false, $negatedNames, true);
    }
    return new MethodFilter(false, $positiveNames, false);
  }

  /**
   * Returns true if the given string is a valid transport-level method name.
   */
  public static function isValidMethodName(string $methodName): bool {
    return (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.~-]{0,190}$/', $methodName) === 1);
  }

  /**
   * Returns true if the given transport-level method name passes the filter.
   */
  public function matches(string $methodName): bool {
    if ($this->matchesAll) {
      return true;
    }
    $isListed = false;
    foreach ($this->names as $name) {
      if (strcasecmp($name, $methodName) === 0) {
        $isListed = true;
        break;
      }
    }
    if ($this->isBlacklist) {
      return !$isListed;
    }
    return $isListed;
  }

  /**
   * True for the filter '*'.
   */
  public function matchesAll(): bool {
    return $this->matchesAll;
  }

  /**
   * True if the listed names are excluded ('!m1,!m4').
   */
  public function isBlacklist(): bool {
    return $this->isBlacklist;
  }

  /**
   * Returns all method names mentioned in the filter (used to learn the methods of a queue).
   *
   * @return list<string>
   */
  public function getMentionedNames(): array {
    return $this->names;
  }

}
