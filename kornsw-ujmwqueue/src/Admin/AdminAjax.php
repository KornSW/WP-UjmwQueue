<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Admin;

use KornSW\UjmwQueue\Processors\LockState;
use KornSW\UjmwQueue\Processors\ProcessorRegistry;
use KornSW\UjmwQueue\Queue\EntryRepository;
use KornSW\UjmwQueue\Queue\QueueRepository;
use KornSW\UjmwQueue\Queue\QueueService;

/**
 * AJAX actions of the live admin tabs. Every action answers with a fresh status snapshot. Lock toggles carry the
 * state the administrator has clicked on; if the state has changed in the meantime, nothing happens (no surprise
 * kill because a job finished a moment before the click).
 */
final class AdminAjax {

  public const NONCE_ACTION = 'kornsw_uq_admin';

  private const CAPABILITY = 'manage_options';

  public function __construct(
    private readonly QueueRepository $queues,
    private readonly ProcessorRegistry $processors,
    private readonly EntryRepository $entries,
    private readonly QueueService $queueService,
    private readonly StatusSnapshot $snapshot
  ) {
  }

  /**
   * Registers the AJAX handlers (logged-in administrators only).
   */
  public function register(): void {
    add_action('wp_ajax_kornsw_uq_snapshot', [$this, 'handleSnapshot']);
    add_action('wp_ajax_kornsw_uq_processor_lock', [$this, 'handleProcessorLock']);
    add_action('wp_ajax_kornsw_uq_processor_cancel', [$this, 'handleProcessorCancel']);
    add_action('wp_ajax_kornsw_uq_endpoint_lock', [$this, 'handleEndpointLock']);
    add_action('wp_ajax_kornsw_uq_endpoint_monitoring', [$this, 'handleEndpointMonitoring']);
    add_action('wp_ajax_kornsw_uq_endpoint_delete', [$this, 'handleEndpointDelete']);
  }

  /**
   * Returns the current snapshot (housekeeping included, so that the view reflects timeouts without traffic).
   */
  public function handleSnapshot(): void {
    $this->verifyRequest();
    $this->queueService->runHousekeeping(false);
    $this->respond();
  }

  /**
   * Cycles the lock of a processor instance: none -> drain -> (draining only) kill -> none.
   */
  public function handleProcessorLock(): void {
    $this->verifyRequest();
    $processorId = $this->getIntParameter('id');
    $instance = $this->processors->findById($processorId);
    if ($instance !== null) {
      $activeJobs = $this->entries->countActivePerProcessor(null)[$processorId] ?? 0;
      $currentState = LockState::of($instance->lockLevel, $activeJobs);
      if ($currentState === $this->getStringParameter('expected')) {
        $nextLevel = LockState::getNextLevel($currentState);
        $this->processors->setLockLevel($processorId, $nextLevel);
        if ($nextLevel === LockState::LEVEL_KILL) {
          $this->queueService->abortActiveJobs($processorId, null, QueueService::FAULT_PROCESSOR_LOCK);
        }
      }
    }
    $this->respond();
  }

  /**
   * Cancels all active jobs of one processor instance (callers get a fault, the instance a cancellation request).
   */
  public function handleProcessorCancel(): void {
    $this->verifyRequest();
    $this->queueService->abortActiveJobs($this->getIntParameter('id'), null, QueueService::FAULT_ADMIN_CANCEL);
    $this->respond();
  }

  /**
   * Cycles the lock of an endpoint: none -> drain -> (draining only) kill -> none. Processors may keep running,
   * they just receive no jobs of this endpoint.
   */
  public function handleEndpointLock(): void {
    $this->verifyRequest();
    $queueId = $this->getIntParameter('id');
    $queue = $this->queues->findById($queueId);
    if ($queue !== null) {
      $activeJobs = ($this->entries->countOpenPerQueue()[$queueId] ?? ['active' => 0])['active'];
      $currentState = LockState::of($queue->lockLevel, $activeJobs);
      if ($currentState === $this->getStringParameter('expected')) {
        $nextLevel = LockState::getNextLevel($currentState);
        $this->queues->setLockLevel($queueId, $nextLevel);
        if ($nextLevel === LockState::LEVEL_KILL) {
          $this->queueService->abortActiveJobs(null, $queueId, QueueService::FAULT_ENDPOINT_LOCK);
        }
      }
    }
    $this->respond();
  }

  /**
   * Switches the email monitoring of an endpoint.
   */
  public function handleEndpointMonitoring(): void {
    $this->verifyRequest();
    $this->queues->setMonitoring($this->getIntParameter('id'), $this->getStringParameter('enabled') === '1');
    $this->respond();
  }

  /**
   * Deletes an endpoint including its entries (it is learned again on the next processor contact).
   */
  public function handleEndpointDelete(): void {
    $this->verifyRequest();
    $this->queues->delete($this->getIntParameter('id'));
    $this->respond();
  }

  /**
   * Checks capability and nonce.
   */
  private function verifyRequest(): void {
    if (!current_user_can(self::CAPABILITY)) {
      wp_send_json_error(['message' => 'Access denied'], 403);
    }
    check_ajax_referer(self::NONCE_ACTION, 'nonce');
  }

  /**
   * Sends the fresh snapshot.
   */
  private function respond(): never {
    wp_send_json_success($this->snapshot->build());
    exit;
  }

  /**
   * Reads an integer POST parameter.
   */
  private function getIntParameter(string $name): int {
    return absint(wp_unslash($_POST[$name] ?? 0));
  }

  /**
   * Reads a string POST parameter.
   */
  private function getStringParameter(string $name): string {
    return sanitize_key(wp_unslash((string) ($_POST[$name] ?? '')));
  }

}
