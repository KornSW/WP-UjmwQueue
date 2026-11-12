<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Http;

/**
 * A request path below the relay prefix, split into segments. The relay does not know any contract, so the
 * meaning of a path is resolved against the learned queues by the router:
 * '<route>/_queue' (processor side), '<route>/<Method>' (caller side) or '<route>' (endpoint info).
 */
final class RelayPath {

  public const QUEUE_SEGMENT = '_queue';

  private const MAX_ROUTE_LENGTH = 191;

  /**
   * @param list<string> $segments
   */
  private function __construct(private readonly array $segments) {
  }

  /**
   * Parses the path below the prefix (e.g. 'api/reports/RenderReport'). Returns null for invalid paths.
   */
  public static function parse(string $relativePath): ?RelayPath {
    $relativePath = trim($relativePath, '/');
    if ($relativePath === '') {
      return null;
    }
    $segments = explode('/', $relativePath);
    foreach ($segments as $segment) {
      if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.~-]*$/', $segment) !== 1) {
        return null;
      }
    }
    if (strlen($relativePath) > (self::MAX_ROUTE_LENGTH + 64)) {
      return null;
    }
    return new RelayPath($segments);
  }

  /**
   * Normalizes a route to its case-insensitive key (routes are matched case-insensitively, like ASP.NET routing).
   */
  public static function toRouteKey(string $route): string {
    return strtolower(trim($route, '/'));
  }

  /**
   * True if the path addresses the processor side ('<route>/_queue').
   */
  public function isQueueEndpoint(): bool {
    return (count($this->segments) >= 2 && $this->getLastSegment() === self::QUEUE_SEGMENT);
  }

  /**
   * The full path as route (used for the endpoint info and for '_queue' after stripping the last segment).
   */
  public function getFullRoute(): string {
    return implode('/', $this->segments);
  }

  /**
   * The route part when the last segment is a method name or '_queue' (null for single-segment paths).
   */
  public function getParentRoute(): ?string {
    if (count($this->segments) < 2) {
      return null;
    }
    $route = implode('/', array_slice($this->segments, 0, -1));
    if (strlen($route) > self::MAX_ROUTE_LENGTH) {
      return null;
    }
    return $route;
  }

  /**
   * The last segment (the method name on the caller side).
   */
  public function getLastSegment(): string {
    return $this->segments[count($this->segments) - 1];
  }

}
