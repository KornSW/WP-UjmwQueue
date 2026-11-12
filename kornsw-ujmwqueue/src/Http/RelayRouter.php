<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Http;

use KornSW\UjmwQueue\Queue\QueueRepository;
use KornSW\UjmwQueue\Relay\CallerEndpoint;
use KornSW\UjmwQueue\Relay\ProcessorEndpoint;
use KornSW\UjmwQueue\Settings;
use Throwable;

/**
 * Intercepts requests below '<site>/<prefix>/' before WordPress parses them ('do_parse_request') and dispatches
 * them to the caller or processor side. Everything else is left to WordPress.
 */
final class RelayRouter {

  public function __construct(
    private readonly Settings $settings,
    private readonly QueueRepository $queues,
    private readonly CallerEndpoint $callerEndpoint,
    private readonly ProcessorEndpoint $processorEndpoint
  ) {
  }

  /**
   * Registers the request interception.
   */
  public function register(): void {
    add_filter('do_parse_request', [$this, 'handleRequest'], 0, 1);
  }

  /**
   * Filter callback: handles relay requests (and ends the request), passes all others through.
   */
  public function handleRequest(mixed $doParseRequest): mixed {
    $relativePath = $this->getRelativePath();
    if ($relativePath === null) {
      return $doParseRequest;
    }
    $path = RelayPath::parse($relativePath);
    if ($path === null) {
      HttpResponse::fault('Unknown endpoint', 404)->send();
    }
    $request = HttpRequest::fromGlobals($path);
    try {
      $response = $this->dispatch($request);
    } catch (Throwable $throwable) {
      error_log('[kornsw-ujmwqueue] ' . get_class($throwable) . ': ' . $throwable->getMessage());
      $response = HttpResponse::fault('Internal relay error', 500);
    }
    $response->send();
  }

  /**
   * Resolves the meaning of the path against the learned queues.
   */
  private function dispatch(HttpRequest $request): HttpResponse {
    $path = $request->path;
    $parentRoute = $path->getParentRoute();

    if ($path->isQueueEndpoint() && $parentRoute !== null) {
      return $this->processorEndpoint->handle($request, $parentRoute);
    }
    if ($request->method === 'GET' && !$request->hasQuery('asyncId')) {
      $queue = $this->queues->findByRoute($path->getFullRoute());
      if ($queue !== null) {
        return $this->callerEndpoint->info($request, $queue);
      }
    }
    if ($parentRoute === null) {
      return HttpResponse::fault('Unknown endpoint', 404);
    }
    return $this->callerEndpoint->handle($request, $parentRoute, $path->getLastSegment());
  }

  /**
   * Returns the path below '<site path>/<prefix>/' or null if the request is not addressed to the relay.
   */
  private function getRelativePath(): ?string {
    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $sitePath = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($sitePath !== '') {
      if (!str_starts_with($requestPath, $sitePath . '/')) {
        return null;
      }
      $requestPath = substr($requestPath, strlen($sitePath));
    }
    $prefix = '/' . $this->settings->getUrlPrefix() . '/';
    if (!str_starts_with($requestPath, $prefix)) {
      return null;
    }
    return substr($requestPath, strlen($prefix));
  }

}
