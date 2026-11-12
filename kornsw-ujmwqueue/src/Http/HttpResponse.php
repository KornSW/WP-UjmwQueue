<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Http;

use KornSW\UjmwQueue\Protocol\Json;

/**
 * A JSON response of the relay. UJMW reports errors as 'fault' with HTTP 200; other status codes are used for
 * transport-level problems only (unknown endpoint, authentication, malformed requests).
 */
final class HttpResponse {

  /**
   * @param array<string, string> $headers
   */
  public function __construct(
    public readonly int $status,
    public readonly string $body,
    public readonly array $headers = []
  ) {
  }

  /**
   * A JSON response with an already serialized body.
   */
  public static function json(string $jsonBody, int $status = 200): HttpResponse {
    return new HttpResponse($status, $jsonBody);
  }

  /**
   * A response consisting of a 'fault' only.
   */
  public static function fault(string $message, int $status = 200): HttpResponse {
    return new HttpResponse($status, Json::encode(['fault' => $message]));
  }

  /**
   * Returns a copy with an additional header.
   */
  public function withHeader(string $name, string $value): HttpResponse {
    $headers = $this->headers;
    $headers[$name] = $value;
    return new HttpResponse($this->status, $this->body, $headers);
  }

  /**
   * Sends the response and ends the request.
   */
  public function send(): never {
    if (!headers_sent()) {
      http_response_code($this->status);
      header('Content-Type: application/json; charset=utf-8');
      header('Cache-Control: no-store');
      header('X-Content-Type-Options: nosniff');
      foreach ($this->headers as $name => $value) {
        header($name . ': ' . $value);
      }
    }
    echo $this->body;
    exit;
  }

}
