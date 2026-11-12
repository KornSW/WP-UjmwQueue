<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Admin;

use KornSW\UjmwQueue\Clock;
use KornSW\UjmwQueue\Processors\Liveness;
use KornSW\UjmwQueue\Processors\LockState;
use KornSW\UjmwQueue\Processors\ProcessorInstance;
use KornSW\UjmwQueue\Processors\ProcessorRegistry;
use KornSW\UjmwQueue\Queue\EntryRepository;
use KornSW\UjmwQueue\Queue\QueueRecord;
use KornSW\UjmwQueue\Queue\QueueRepository;

/**
 * Live data of the admin tabs "Processors" and "Endpoints" (polled every second by the admin script). Built with
 * a fixed, small number of queries regardless of the number of queues and instances.
 */
final class StatusSnapshot {

  public function __construct(
    private readonly QueueRepository $queues,
    private readonly EntryRepository $entries,
    private readonly ProcessorRegistry $processors
  ) {
  }

  /**
   * Builds the snapshot.
   *
   * @return array{processors: list<array<string, mixed>>, endpoints: list<array<string, mixed>>}
   */
  public function build(): array {
    $nowMs = Clock::nowMs();
    $queues = $this->queues->getAll();
    $instances = $this->processors->getAll();
    $links = $this->processors->getLinks();
    $activePerProcessor = $this->entries->countActivePerProcessor(null);
    $openPerQueue = $this->entries->countOpenPerQueue();

    $instancesById = [];
    foreach ($instances as $instance) {
      $instancesById[$instance->id] = $instance;
    }
    $routesById = [];
    foreach ($queues as $queue) {
      $routesById[$queue->id] = $queue->route;
    }

    $routesPerAgent = [];
    $agentsPerQueue = [];
    $availableQueueIds = [];
    foreach ($links as $link) {
      if (!isset($instancesById[$link['processorId']]) || !isset($routesById[$link['queueId']])) {
        continue;
      }
      $instance = $instancesById[$link['processorId']];
      $routesPerAgent[$instance->agentName][$routesById[$link['queueId']]] = true;
      $agentsPerQueue[$link['queueId']][$instance->agentName] = true;
      $isLinkFresh = ($nowMs - $link['lastSeenMs']) < Liveness::OFFLINE_AFTER_MS;
      if ($isLinkFresh && $instance->lockLevel === LockState::LEVEL_NONE && Liveness::isAvailable($instance->lastSeenMs, $nowMs)) {
        $availableQueueIds[$link['queueId']] = true;
      }
    }

    return [
      'processors' => $this->buildProcessors($instances, $activePerProcessor, $routesPerAgent, $nowMs),
      'endpoints' => $this->buildEndpoints($queues, $openPerQueue, $agentsPerQueue, $availableQueueIds),
    ];
  }

  /**
   * One row per agent: its instances (status, active jobs, lock) and the endpoints it has polled.
   *
   * @param list<ProcessorInstance> $instances
   * @param array<int, int> $activePerProcessor
   * @param array<string, array<string, true>> $routesPerAgent
   * @return list<array<string, mixed>>
   */
  private function buildProcessors(array $instances, array $activePerProcessor, array $routesPerAgent, int $nowMs): array {
    $agents = [];
    foreach ($instances as $instance) {
      $activeJobs = $activePerProcessor[$instance->id] ?? 0;
      if (!isset($agents[$instance->agentName])) {
        $routes = array_keys($routesPerAgent[$instance->agentName] ?? []);
        sort($routes, SORT_STRING | SORT_FLAG_CASE);
        $agents[$instance->agentName] = ['agent' => $instance->agentName, 'instances' => [], 'endpoints' => $routes];
      }
      $agents[$instance->agentName]['instances'][] = [
        'id' => $instance->id,
        'name' => '#' . $instance->instanceName,
        'status' => Liveness::of($instance->lastSeenMs, $nowMs, $activeJobs),
        'active' => $activeJobs,
        'lock' => LockState::of($instance->lockLevel, $activeJobs),
        'lastSeen' => Clock::toIsoUtc($instance->lastSeenMs),
      ];
    }
    uksort($agents, 'strcasecmp');
    return array_values($agents);
  }

  /**
   * One row per endpoint.
   *
   * @param list<QueueRecord> $queues
   * @param array<int, array{queued: int, active: int}> $openPerQueue
   * @param array<int, array<string, true>> $agentsPerQueue
   * @param array<int, true> $availableQueueIds
   * @return list<array<string, mixed>>
   */
  private function buildEndpoints(array $queues, array $openPerQueue, array $agentsPerQueue, array $availableQueueIds): array {
    $endpoints = [];
    foreach ($queues as $queue) {
      $counts = $openPerQueue[$queue->id] ?? ['queued' => 0, 'active' => 0];
      $agentNames = array_keys($agentsPerQueue[$queue->id] ?? []);
      sort($agentNames, SORT_STRING | SORT_FLAG_CASE);
      $isAvailable = ($queue->lockLevel === LockState::LEVEL_NONE && isset($availableQueueIds[$queue->id]));
      $endpoints[] = [
        'id' => $queue->id,
        'route' => $queue->route,
        'methods' => $queue->methods,
        'queued' => $counts['queued'],
        'active' => $counts['active'],
        'unserved' => !$isAvailable,
        'agents' => $agentNames,
        'lock' => LockState::of($queue->lockLevel, $counts['active']),
        'monitoring' => $queue->isMonitored,
      ];
    }
    return $endpoints;
  }

}
