<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Maintenance;

use KornSW\UjmwQueue\Processors\Liveness;
use KornSW\UjmwQueue\Processors\ProcessorRegistry;
use KornSW\UjmwQueue\Queue\EntryRepository;
use KornSW\UjmwQueue\Queue\QueueRepository;
use KornSW\UjmwQueue\Settings;

/**
 * Automatic cleanup of processor instances, whole processor agents and endpoints (settings tab "Retention").
 * Runs on traffic as part of the housekeeping, at most every 10 minutes.
 *
 * - Instance retention: instances offline for more than a day are deleted if at least one other instance of the
 *   same agent is alive (not offline).
 * - Processor retention: agents whose instances are all offline for 'processorRetentionDays' are deleted.
 * - Endpoint retention: endpoints without any known processor and without open entries are deleted
 *   'endpointRetentionUsedDays' (had calls) or 'endpointRetentionUnusedDays' (only announced) after the last
 *   processor contact.
 */
final class Retention {

  public const LAST_RUN_OPTION = 'kornsw_uq_retention_last_ms';

  private const INTERVAL_MS = 600000;

  private const DAY_MS = 86400000;

  public function __construct(
    private readonly Settings $settings,
    private readonly ProcessorRegistry $processors,
    private readonly QueueRepository $queues,
    private readonly EntryRepository $entries
  ) {
  }

  /**
   * Runs the cleanup if the last run is older than the interval.
   */
  public function runIfDue(int $nowMs): void {
    $lastRunMs = (int) get_option(self::LAST_RUN_OPTION, 0);
    if (($nowMs - $lastRunMs) < self::INTERVAL_MS) {
      return;
    }
    update_option(self::LAST_RUN_OPTION, $nowMs, false);
    $this->run($nowMs);
  }

  /**
   * Runs the cleanup now.
   */
  public function run(int $nowMs): void {
    $this->processors->delete($this->selectExpiredInstances($nowMs));
    if ($this->settings->getBool('endpointRetentionEnabled')) {
      $this->deleteExpiredEndpoints($nowMs);
    }
  }

  /**
   * Selects the instances to delete according to the instance and processor retention.
   *
   * @return list<int>
   */
  private function selectExpiredInstances(int $nowMs): array {
    $isInstanceRetentionEnabled = $this->settings->getBool('instanceRetentionEnabled');
    $isProcessorRetentionEnabled = $this->settings->getBool('processorRetentionEnabled');
    if (!$isInstanceRetentionEnabled && !$isProcessorRetentionEnabled) {
      return [];
    }
    $processorThresholdMs = $nowMs - ($this->settings->getInt('processorRetentionDays') * self::DAY_MS);

    $instancesPerAgent = [];
    foreach ($this->processors->getAll() as $instance) {
      $instancesPerAgent[$instance->agentName][] = $instance;
    }

    $expiredIds = [];
    foreach ($instancesPerAgent as $instances) {
      $lastSeenMs = 0;
      $hasAliveInstance = false;
      foreach ($instances as $instance) {
        $lastSeenMs = max($lastSeenMs, $instance->lastSeenMs);
        if (($nowMs - $instance->lastSeenMs) < Liveness::OFFLINE_AFTER_MS) {
          $hasAliveInstance = true;
        }
      }
      if ($isProcessorRetentionEnabled && $lastSeenMs < $processorThresholdMs) {
        foreach ($instances as $instance) {
          $expiredIds[] = $instance->id;
        }
        continue;
      }
      if ($isInstanceRetentionEnabled && $hasAliveInstance) {
        foreach ($instances as $instance) {
          if (($nowMs - $instance->lastSeenMs) > self::DAY_MS) {
            $expiredIds[] = $instance->id;
          }
        }
      }
    }
    return $expiredIds;
  }

  /**
   * Deletes endpoints without known processors after the configured number of days.
   */
  private function deleteExpiredEndpoints(int $nowMs): void {
    $linkCounts = $this->processors->countLinksPerQueue();
    $openCounts = $this->entries->countOpenPerQueue();
    foreach ($this->queues->getAll() as $queue) {
      if (isset($linkCounts[$queue->id]) || isset($openCounts[$queue->id])) {
        continue;
      }
      $days = $this->settings->getInt('endpointRetentionUnusedDays');
      if ($queue->totalCalls > 0) {
        $days = $this->settings->getInt('endpointRetentionUsedDays');
      }
      $referenceMs = max($queue->lastProcessorMs, $queue->createdMs);
      if (($nowMs - $referenceMs) >= ($days * self::DAY_MS)) {
        $this->queues->delete($queue->id);
      }
    }
  }

}
