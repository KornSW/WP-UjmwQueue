<?php
/**
 * Plugin Name: UjmwQueue e2e helper (TEST ONLY)
 *
 * TEST ONLY (mounted into WordPress Playground by tests/e2e): never install this on a real site.
 *
 * - Requests with the header 'X-Uq-Test-Admin: 1' run as user 1 (administrator), so that the e2e script can call
 *   the admin AJAX actions of the plugin.
 * - '/?uqtest=<action>' exposes the in-process client API ('KornSW\UjmwQueue\Api\QueueApi') over HTTP.
 */

declare(strict_types=1);

add_filter('determine_current_user', function ($userId) {
  if (isset($_SERVER['HTTP_X_UQ_TEST_ADMIN'])) {
    return 1;
  }
  return $userId;
}, 100);

add_action('init', function (): void {
  if (!isset($_GET['uqtest'])) {
    return;
  }
  header('Content-Type: application/json; charset=utf-8');
  $api = \KornSW\UjmwQueue\Api\QueueApi::get();
  $action = (string) $_GET['uqtest'];
  $route = (string) ($_GET['route'] ?? '');
  $method = (string) ($_GET['method'] ?? '');
  if ($action === 'nonce') {
    echo wp_json_encode(['nonce' => wp_create_nonce('kornsw_uq_admin')]);
  } elseif ($action === 'invoke') {
    echo $api->invoke($route, $method, (string) file_get_contents('php://input'));
  } elseif ($action === 'invokeLongRunning') {
    echo $api->invokeLongRunning($route, $method, (string) file_get_contents('php://input'), (float) ($_GET['wait'] ?? 0));
  } elseif ($action === 'poll') {
    echo $api->poll($route, $method, (string) ($_GET['asyncId'] ?? ''), (string) ($_GET['secret'] ?? ''), (float) ($_GET['wait'] ?? 0));
  } elseif ($action === 'endpoints') {
    echo wp_json_encode($api->getEndpoints());
  } else {
    echo wp_json_encode(['fault' => 'unknown test action']);
  }
  exit;
});
