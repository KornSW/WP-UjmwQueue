<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue;

/**
 * Database schema of the relay (own tables via dbDelta, versioned by the option 'kornsw_uq_db_version').
 * PHP has no process-wide memory, therefore the short-term queue of the UJMW epic lives in these tables.
 */
final class Schema {

  public const VERSION = 1;

  public const VERSION_OPTION = 'kornsw_uq_db_version';

  /**
   * Name of the table holding the learned queues (one per route).
   */
  public static function queuesTable(): string {
    global $wpdb;
    return $wpdb->prefix . 'kornsw_uq_queues';
  }

  /**
   * Name of the table holding the queue entries (queued, active and retained outcomes).
   */
  public static function entriesTable(): string {
    global $wpdb;
    return $wpdb->prefix . 'kornsw_uq_entries';
  }

  /**
   * Name of the table holding termination timestamps for the 'pastXX' statistics (pruned after 10 hours).
   */
  public static function terminationsTable(): string {
    global $wpdb;
    return $wpdb->prefix . 'kornsw_uq_terminations';
  }

  /**
   * Creates or upgrades the tables if the stored schema version is outdated.
   */
  public static function upgradeIfNeeded(): void {
    if ((int) get_option(self::VERSION_OPTION, 0) >= self::VERSION) {
      return;
    }
    self::install();
  }

  /**
   * Creates or upgrades the tables (dbDelta never drops data).
   */
  public static function install(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charsetCollate = $wpdb->get_charset_collate();
    $queuesTable = self::queuesTable();
    $entriesTable = self::entriesTable();
    $terminationsTable = self::terminationsTable();

    dbDelta("CREATE TABLE {$queuesTable} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  route_key varchar(191) NOT NULL,
  route varchar(191) NOT NULL,
  methods longtext NULL,
  processors longtext NULL,
  created_ms bigint(20) NOT NULL,
  last_processor_ms bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY route_key (route_key)
) {$charsetCollate};");

    dbDelta("CREATE TABLE {$entriesTable} (
  id bigint(20) unsigned NOT NULL,
  queue_id bigint(20) unsigned NOT NULL,
  method varchar(191) NOT NULL,
  state varchar(12) NOT NULL,
  position double NOT NULL,
  is_long_running tinyint(1) NOT NULL DEFAULT 0,
  request_json longtext NOT NULL,
  response_json longtext NULL,
  caller_secret varchar(32) NOT NULL,
  processor_secret varchar(32) NULL,
  withdrawn_secret varchar(32) NULL,
  processor_name varchar(191) NULL,
  release_count int(11) NOT NULL DEFAULT 0,
  attempt_count int(11) NOT NULL DEFAULT 0,
  cancel_requested tinyint(1) NOT NULL DEFAULT 0,
  callbacks_json longtext NULL,
  row_version int(10) unsigned NOT NULL DEFAULT 0,
  created_ms bigint(20) NOT NULL,
  allocated_ms bigint(20) NOT NULL DEFAULT 0,
  heartbeat_ms bigint(20) NOT NULL DEFAULT 0,
  terminated_ms bigint(20) NOT NULL DEFAULT 0,
  last_nbf_ms bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY queue_state_position (queue_id,state,position),
  KEY state_terminated (state,terminated_ms)
) {$charsetCollate};");

    dbDelta("CREATE TABLE {$terminationsTable} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  queue_id bigint(20) unsigned NOT NULL,
  method varchar(191) NOT NULL,
  terminated_ms bigint(20) NOT NULL,
  PRIMARY KEY  (id),
  KEY queue_terminated (queue_id,terminated_ms)
) {$charsetCollate};");

    update_option(self::VERSION_OPTION, self::VERSION, false);
  }

}
