<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Security;

/**
 * Route pattern of an access key: '*' matches any characters (including '/'), matching is case-insensitive.
 * An empty pattern or '*' grants all routes. Several patterns can be separated by commas.
 */
final class RoutePattern {

  /**
   * Returns true if the route key matches one of the comma-separated patterns.
   */
  public static function matches(string $patterns, string $routeKey): bool {
    $patterns = trim($patterns);
    if ($patterns === '' || $patterns === '*') {
      return true;
    }
    foreach (explode(',', $patterns) as $pattern) {
      $pattern = strtolower(trim(trim($pattern), '/'));
      if ($pattern === '') {
        continue;
      }
      $regex = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#';
      if (preg_match($regex, strtolower($routeKey)) === 1) {
        return true;
      }
    }
    return false;
  }

}
