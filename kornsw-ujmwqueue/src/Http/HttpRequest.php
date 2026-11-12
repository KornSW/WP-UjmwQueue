<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Http;

/**
 * A relay request. Query parameters are parsed from the raw query string (WordPress slashes '$_GET').
 */
final class HttpRequest {

  /**
   * Maximum accepted request body (UJMW requests and responses are passed through as a whole).
   */
  public const MAX_BODY_BYTES = 16777216;

  private ?string $body = null;

  /**
   * @param array<string, string> $query
   * @param array<string, string> $headers lower-case header name => value
   */
  public function __construct(
    public readonly string $method,
    public readonly RelayPath $path,
    private readonly array $query,
    private readonly array $headers,
    ?string $body = null
  ) {
    $this->body = $body;
  }

  /**
   * Creates the request from the PHP globals.
   */
  public static function fromGlobals(RelayPath $path): HttpRequest {
    $query = [];
    $rawQuery = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    if ($rawQuery !== '') {
      parse_str($rawQuery, $parsedQuery);
      foreach ($parsedQuery as $name => $value) {
        if (is_string($value)) {
          $query[(string) $name] = $value;
        }
      }
    }

    $headers = [];
    foreach ($_SERVER as $name => $value) {
      if (is_string($value) && str_starts_with((string) $name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $name, 5)))] = $value;
      }
    }
    // Authorization is often stripped by CGI/FastCGI setups and only available via fallbacks
    if (!isset($headers['authorization'])) {
      if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
      } elseif (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
          if (strtolower((string) $name) === 'authorization' && is_string($value)) {
            $headers['authorization'] = $value;
          }
        }
      }
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return new HttpRequest($method, $path, $query, $headers);
  }

  /**
   * Returns a query parameter or null.
   */
  public function getQuery(string $name): ?string {
    return $this->query[$name] ?? null;
  }

  /**
   * True if the query parameter is present (also with an empty value).
   */
  public function hasQuery(string $name): bool {
    return array_key_exists($name, $this->query);
  }

  /**
   * Returns a header (case-insensitive) or null.
   */
  public function getHeader(string $name): ?string {
    return $this->headers[strtolower($name)] ?? null;
  }

  /**
   * Returns the request body (read once, limited to MAX_BODY_BYTES).
   */
  public function getBody(): string {
    if ($this->body === null) {
      $content = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
      if ($content === false) {
        $content = '';
      }
      $this->body = $content;
    }
    return $this->body;
  }

  /**
   * True if the body exceeds the accepted size.
   */
  public function isBodyTooLarge(): bool {
    return (strlen($this->getBody()) > self::MAX_BODY_BYTES);
  }

}
