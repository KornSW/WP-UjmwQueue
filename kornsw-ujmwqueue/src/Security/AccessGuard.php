<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Security;

use KornSW\UjmwQueue\Http\HttpRequest;
use KornSW\UjmwQueue\Http\HttpResponse;

/**
 * Checks the access key of a relay request: 'Authorization: Bearer <key>' or, for hosts that strip the
 * Authorization header, 'X-Ujmw-Queue-Key: <key>'. The key must have one of the accepted roles and a route
 * pattern matching the route.
 */
final class AccessGuard {

  public const FALLBACK_HEADER = 'X-Ujmw-Queue-Key';

  public function __construct(private readonly KeyStore $keyStore) {
  }

  /**
   * Returns null if access is granted, otherwise the 401 / 403 response to send.
   *
   * @param list<string> $acceptedRoles
   */
  public function authorize(HttpRequest $request, string $routeKey, array $acceptedRoles): ?HttpResponse {
    $plainKey = $this->extractKey($request);
    if ($plainKey === null) {
      return HttpResponse::fault('Unauthorized', 401)->withHeader('WWW-Authenticate', 'Bearer');
    }
    $key = $this->keyStore->findByPlainKey($plainKey);
    if ($key === null) {
      return HttpResponse::fault('Unauthorized', 401)->withHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    }
    if (!in_array($key['role'], $acceptedRoles, true) || !RoutePattern::matches((string) $key['routes'], $routeKey)) {
      return HttpResponse::fault('Forbidden', 403);
    }
    return null;
  }

  /**
   * Reads the key from the request headers.
   */
  private function extractKey(HttpRequest $request): ?string {
    $authorization = $request->getHeader('Authorization');
    if ($authorization !== null && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $matches) === 1) {
      return $matches[1];
    }
    $fallback = $request->getHeader(self::FALLBACK_HEADER);
    if ($fallback !== null && trim($fallback) !== '') {
      return trim($fallback);
    }
    return null;
  }

}
