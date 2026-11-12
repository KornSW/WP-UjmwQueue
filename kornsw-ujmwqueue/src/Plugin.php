<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue;

use KornSW\UjmwQueue\Admin\AdminPage;
use KornSW\UjmwQueue\Http\RelayRouter;
use KornSW\UjmwQueue\Queue\EntryRepository;
use KornSW\UjmwQueue\Queue\QueueRepository;
use KornSW\UjmwQueue\Queue\QueueService;
use KornSW\UjmwQueue\Queue\QueueStatistics;
use KornSW\UjmwQueue\Queue\TerminationLog;
use KornSW\UjmwQueue\Relay\CallerEndpoint;
use KornSW\UjmwQueue\Relay\ProcessorEndpoint;
use KornSW\UjmwQueue\Security\AccessGuard;
use KornSW\UjmwQueue\Security\KeyStore;

/**
 * Composition root: creates the services and registers all WordPress hooks.
 */
final class Plugin {

  private static ?Plugin $instance = null;

  private function __construct(
    private readonly RelayRouter $relayRouter,
    private readonly AdminPage $adminPage
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
   * Boots the plugin once (hooked to plugins_loaded).
   */
  public static function boot(): void {
    if (self::$instance !== null) {
      return;
    }
    Schema::upgradeIfNeeded();
    self::$instance = self::create();
    self::$instance->registerHooks();
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
    $queueService = new QueueService($settings, $queueRepository, $entryRepository, $terminationLog);

    $callerEndpoint = new CallerEndpoint($settings, $queueRepository, $queueService, $accessGuard);
    $processorEndpoint = new ProcessorEndpoint(
      $settings,
      $queueRepository,
      $entryRepository,
      $queueService,
      new QueueStatistics($entryRepository, $terminationLog),
      $accessGuard
    );

    return new Plugin(
      new RelayRouter($settings, $queueRepository, $callerEndpoint, $processorEndpoint),
      new AdminPage($settings, $keyStore, $queueRepository, $entryRepository)
    );
  }

  /**
   * Registers the hooks of all features.
   */
  private function registerHooks(): void {
    $this->relayRouter->register();
    if (is_admin()) {
      $this->adminPage->register();
    }
  }

}
