<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Monitoring;

use KornSW\UjmwQueue\Queue\QueueRecord;
use KornSW\UjmwQueue\Settings;

/**
 * Email notifications to the administrator. Overflow and "no processor" notifications are sent only for endpoints
 * with monitoring switched on; every kind of notification is sent at most once per hour and endpoint.
 */
final class Notifier {

  public const KIND_OVERFLOW = 'overflow';

  public const KIND_UNSERVED = 'unserved';

  public const KIND_NEW_ENDPOINT = 'new-endpoint';

  /**
   * Filter hook to suppress or redirect notifications.
   * Arguments: (bool $send, string $kind, string $route, string $subject, string $message).
   */
  public const NOTIFICATION_FILTER = 'kornsw_uq_send_notification';

  private const TRANSIENT_PREFIX = 'kornsw_uq_notified_';

  private const SUPPRESSION_SECONDS = 3600;

  public function __construct(private readonly Settings $settings) {
  }

  /**
   * A call was rejected because the queue is full.
   */
  public function notifyOverflow(QueueRecord $queue): void {
    if (!$queue->isMonitored) {
      return;
    }
    $this->send(
      self::KIND_OVERFLOW,
      $queue,
      'Queue overflow on endpoint ' . $queue->route,
      "The queue of the endpoint '" . $queue->route . "' is full (maxQueueSize = " . $this->settings->getInt('maxQueueSize') . ").\n"
        . "New calls are rejected with the fault 'Queue capacity exceeded'. Check whether enough processors are running."
    );
  }

  /**
   * Calls cannot be served because no processor is available (none polling, all silent or locked).
   */
  public function notifyUnserved(QueueRecord $queue): void {
    if (!$queue->isMonitored) {
      return;
    }
    $this->send(
      self::KIND_UNSERVED,
      $queue,
      'No processor available for endpoint ' . $queue->route,
      "Calls to the endpoint '" . $queue->route . "' are waiting or being rejected, but no processor is polling the queue\n"
        . "(no instance seen within the last 5 minutes, or the endpoint / all instances are locked)."
    );
  }

  /**
   * A new endpoint has been learned and is locked until an administrator unlocks it.
   */
  public function notifyNewEndpointLocked(QueueRecord $queue): void {
    $this->send(
      self::KIND_NEW_ENDPOINT,
      $queue,
      'New endpoint ' . $queue->route . ' is waiting for approval',
      "A new endpoint '" . $queue->route . "' has been learned. New endpoints are locked by default:\n"
        . "processors receive no jobs and callers get 'No processor available' until you unlock it\n"
        . 'on the tab "Endpoints" of the UJMW Queue admin page.'
    );
  }

  /**
   * Sends one notification unless the same kind was sent for the queue within the last hour.
   */
  private function send(string $kind, QueueRecord $queue, string $subject, string $message): void {
    $transientName = self::TRANSIENT_PREFIX . $kind . '_' . $queue->id;
    if (get_transient($transientName) !== false) {
      return;
    }
    set_transient($transientName, 1, self::SUPPRESSION_SECONDS);

    $siteName = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
    $subject = '[' . $siteName . '] UJMW Queue: ' . $subject;
    $message .= "\n\n" . admin_url('options-general.php?page=kornsw-ujmwqueue') . "\n\n(At most one message of this kind per endpoint and hour.)";
    if (!(bool) apply_filters(self::NOTIFICATION_FILTER, true, $kind, $queue->route, $subject, $message)) {
      return;
    }
    wp_mail($this->settings->getNotificationEmail(), $subject, $message);
  }

}
