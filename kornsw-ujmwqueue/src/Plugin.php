<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue;

use KornSW\UjmwQueue\Admin\AdminAjax;
use KornSW\UjmwQueue\Admin\AdminPage;
use KornSW\UjmwQueue\Admin\StatusSnapshot;
use KornSW\UjmwQueue\Api\QueueApi;
use KornSW\UjmwQueue\Http\RelayRouter;
use KornSW\UjmwQueue\Maintenance\Retention;
use KornSW\UjmwQueue\Monitoring\Notifier;
use KornSW\UjmwQueue\Processors\ProcessorRegistry;
use KornSW\UjmwQueue\Queue\EntryRepository;
use KornSW\UjmwQueue\Queue\QueueRepository;
use KornSW\UjmwQueue\Queue\QueueService;
use KornSW\UjmwQueue\Queue\QueueStatistics;
use KornSW\UjmwQueue\Queue\TerminationLog;
use KornSW\UjmwQueue\Relay\CallerEndpoint;
use KornSW\UjmwQueue\Relay\CallerOperations;
use KornSW\UjmwQueue\Relay\ProcessorEndpoint;
use KornSW\UjmwQueue\Security\AccessGuard;
use KornSW\UjmwQueue\Security\KeyStore;

/**
 * Composition root: creates the services and registers all WordPress hooks.
 */
final class Plugin {

  /**
   * Action fired once the plugin is booted; receives the in-process client API ('Api\QueueApi').
   */
  public const READY_ACTION = 'kornsw_uq_ready';

  private static ?Plugin $instance = null;

  private function __construct(
    private readonly RelayRouter $relayRouter,
    private readonly AdminPage $adminPage,
    private readonly AdminAjax $adminAjax,
    private readonly QueueApi $api
  ) {
  }

  /**
   * Activation hook: creates the tables and options. Existing data is never touched; uninstalling keeps it as well.
   */
  public static function activate(): void {
    Schema::install();
    add_option(Settings::OPTION_NAME, Settings::getDefaults(), '', false);
    add_option(KeyStore::OPTION_NAME, [], '', false);
  }

  /**
   * Boots the plugin once (hooked to plugins_loaded; also triggered by 'QueueApi::get()').
   */
  public static function boot(): void {
    if (self::$instance !== null) {
      return;
    }
    Schema::upgradeIfNeeded();
    self::$instance = self::create();
    self::$instance->registerHooks();
    do_action(self::READY_ACTION, self::$instance->api);
  }

  /**
   * Returns the in-process client API (boots the plugin if necessary).
   */
  public static function getApi(): QueueApi {
    self::boot();
    return self::$instance->api;
  }

  /**
   * Wires all services.
   */
  private static function create(): Plugin {
    $settings = new Settings();
    $keyStore = new KeyStore();
    $accessGuard = new AccessGuard($keyStore);
    $queueRepository = new QueueRepository();
    $entryRepository = new EntryRepository();
    $terminationLog = new TerminationLog();
    $processorRegistry = new ProcessorRegistry($settings);
    $notifier = new Notifier($settings);
    $retention = new Retention($settings, $processorRegistry, $queueRepository, $entryRepository);
    $queueService = new QueueService(
      $settings,
      $queueRepository,
      $entryRepository,
      $terminationLog,
      $processorRegistry,
      $notifier,
      $retention
    );
    $callerOperations = new CallerOperations($settings, $queueService);

    $callerEndpoint = new CallerEndpoint($queueRepository, $queueService, $callerOperations, $accessGuard);
    $processorEndpoint = new ProcessorEndpoint(
      $settings,
      $queueRepository,
      $entryRepository,
      $queueService,
      new QueueStatistics($entryRepository, $terminationLog, $processorRegistry, $settings),
      $processorRegistry,
      $accessGuard
    );
    $snapshot = new StatusSnapshot($queueRepository, $entryRepository, $processorRegistry, $settings);

    return new Plugin(
      new RelayRouter($settings, $queueRepository, $callerEndpoint, $processorEndpoint),
      new AdminPage($settings, $keyStore),
      new AdminAjax($queueRepository, $processorRegistry, $entryRepository, $queueService, $snapshot),
      new QueueApi($queueRepository, $queueService, $callerOperations)
    );
  }

  /**
   * Registers the hooks of all features.
   */
  private function registerHooks(): void {
    $this->relayRouter->register();
    if (is_admin()) {
      $this->adminPage->register();
      $this->adminAjax->register();
    }
  }

}
