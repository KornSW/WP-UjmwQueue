<?php
/**
 * Plugin Name:       KornSW UjmwQueue
 * Version: 0.1.2
 * Update URI: https://raw.githubusercontent.com/KornSW/WP-UjmwQueue/master/doc/kornsw-ujmwqueue.update.json
 * Plugin URI: https://github.com/KornSW/WP-UjmwQueue
 * Description:       Publicly reachable relay host for the UJMW async queue mode: callers use regular UJMW endpoints, processors behind firewalls pull the calls via '_queue'. Contract-agnostic - routes and methods are learned on demand.
 * Requires at least: 7.1
 * Requires PHP:      8.4
 * Author:            KornSW
 * License:           GPL-3.0
 * Text Domain:       kornsw-ujmwqueue
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}


/*************** SELF-UPDATE ***************/
define( 'KSWKORNSWUJMWQU9C8C_SELF_UPDATE_DIAGNOSTICS', false );
require_once __DIR__ . '/self-update.php';
kswkornswujmwqu9c8c_bootstrap( __FILE__ );
/*******************************************/

define('KORNSW_UQ_FILE', __FILE__);
define('KORNSW_UQ_DIR', plugin_dir_path(__FILE__));
define('KORNSW_UQ_URL', plugin_dir_url(__FILE__));
define('KORNSW_UQ_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);

spl_autoload_register(function (string $className): void {
  $namespacePrefix = 'KornSW\\UjmwQueue\\';
  if (strncmp($className, $namespacePrefix, strlen($namespacePrefix)) !== 0) {
    return;
  }
  $relativeClassName = substr($className, strlen($namespacePrefix));
  $fileName = KORNSW_UQ_DIR . 'src/' . str_replace('\\', '/', $relativeClassName) . '.php';
  if (is_readable($fileName)) {
    require_once $fileName;
  }
});

register_activation_hook(__FILE__, [\KornSW\UjmwQueue\Plugin::class, 'activate']);
add_action('plugins_loaded', [\KornSW\UjmwQueue\Plugin::class, 'boot']);
