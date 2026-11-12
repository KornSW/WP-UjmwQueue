<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Admin;

use KornSW\UjmwQueue\Clock;
use KornSW\UjmwQueue\Queue\EntryRepository;
use KornSW\UjmwQueue\Queue\EntryState;
use KornSW\UjmwQueue\Queue\QueueRepository;
use KornSW\UjmwQueue\Security\KeyStore;
use KornSW\UjmwQueue\Settings;

/**
 * 'Settings > UJMW Queue': learned queues, access keys and settings.
 */
final class AdminPage {

  private const PAGE_SLUG = 'kornsw-ujmwqueue';

  private const CAPABILITY = 'manage_options';

  private const NEW_KEY_TRANSIENT_PREFIX = 'kornsw_uq_new_key_';

  public function __construct(
    private readonly Settings $settings,
    private readonly KeyStore $keyStore,
    private readonly QueueRepository $queues,
    private readonly EntryRepository $entries
  ) {
  }

  /**
   * Registers the menu entry and the form handlers.
   */
  public function register(): void {
    add_action('admin_menu', [$this, 'addMenuEntry']);
    add_action('admin_post_kornsw_uq_save_settings', [$this, 'handleSaveSettings']);
    add_action('admin_post_kornsw_uq_create_key', [$this, 'handleCreateKey']);
    add_action('admin_post_kornsw_uq_delete_key', [$this, 'handleDeleteKey']);
    add_action('admin_post_kornsw_uq_delete_queue', [$this, 'handleDeleteQueue']);
  }

  /**
   * Adds the page below 'Settings'.
   */
  public function addMenuEntry(): void {
    add_options_page('UJMW Queue', 'UJMW Queue', self::CAPABILITY, self::PAGE_SLUG, [$this, 'render']);
  }

  /**
   * Stores the settings.
   */
  public function handleSaveSettings(): void {
    $this->verifyRequest('kornsw_uq_save_settings');
    $input = [];
    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
      $input = wp_unslash($_POST['settings']);
    }
    $this->settings->save($input);
    $this->redirectBack('settings-saved');
  }

  /**
   * Creates an access key and keeps its plain value for one display.
   */
  public function handleCreateKey(): void {
    $this->verifyRequest('kornsw_uq_create_key');
    $label = sanitize_text_field(wp_unslash((string) ($_POST['label'] ?? '')));
    $role = sanitize_key(wp_unslash((string) ($_POST['role'] ?? '')));
    $routes = sanitize_text_field(wp_unslash((string) ($_POST['routes'] ?? '*')));
    if ($role !== KeyStore::ROLE_CALLER && $role !== KeyStore::ROLE_PROCESSOR) {
      $this->redirectBack('invalid-role');
    }
    if ($routes === '') {
      $routes = '*';
    }
    $plainKey = $this->keyStore->create($label, $role, $routes);
    set_transient(self::NEW_KEY_TRANSIENT_PREFIX . get_current_user_id(), $plainKey, 300);
    $this->redirectBack('key-created');
  }

  /**
   * Deletes an access key.
   */
  public function handleDeleteKey(): void {
    $this->verifyRequest('kornsw_uq_delete_key');
    $this->keyStore->delete(sanitize_text_field(wp_unslash((string) ($_POST['keyId'] ?? ''))));
    $this->redirectBack('key-deleted');
  }

  /**
   * Deletes a learned queue including its entries (explicit action; the queue is learned again on the next
   * processor contact).
   */
  public function handleDeleteQueue(): void {
    $this->verifyRequest('kornsw_uq_delete_queue');
    $this->queues->delete(absint($_POST['queueId'] ?? 0));
    $this->redirectBack('queue-deleted');
  }

  /**
   * Renders the page.
   */
  public function render(): void {
    if (!current_user_can(self::CAPABILITY)) {
      wp_die(esc_html('Access denied'));
    }
    $newKeyTransient = self::NEW_KEY_TRANSIENT_PREFIX . get_current_user_id();
    $newKey = get_transient($newKeyTransient);
    if ($newKey !== false) {
      delete_transient($newKeyTransient);
    }
    $baseUrl = home_url('/' . $this->settings->getUrlPrefix() . '/');

    echo '<div class="wrap"><h1>UJMW Queue</h1>';
    $displayedKey = null;
    if (is_string($newKey)) {
      $displayedKey = $newKey;
    }
    $this->renderNotice(sanitize_key(wp_unslash((string) ($_GET['kornsw_uq_notice'] ?? ''))), $displayedKey);

    echo '<p>Relay base URL: <code>' . esc_html($baseUrl) . '</code><br>';
    echo 'Callers use <code>' . esc_html($baseUrl) . '&lt;route&gt;/&lt;Method&gt;</code>, processors use <code>' . esc_html($baseUrl) . '&lt;route&gt;/_queue</code>. ';
    echo 'A queue is created when the first processor connects to its route.</p>';

    $this->renderQueues();
    $this->renderKeys();
    $this->renderSettings();
    echo '</div>';
  }

  /**
   * Renders the learned queues.
   */
  private function renderQueues(): void {
    echo '<h2>Learned queues</h2>';
    $queues = $this->queues->getAll();
    if (count($queues) === 0) {
      echo '<p>No queue has been learned yet. Connect a processor to <code>&lt;route&gt;/_queue</code>.</p>';
      return;
    }
    echo '<table class="widefat striped"><thead><tr><th>Route</th><th>Methods</th><th>Queued</th><th>Active</th><th>Processors (last activity, UTC)</th><th></th></tr></thead><tbody>';
    foreach ($queues as $queue) {
      $processors = [];
      foreach ($queue->processors as $processorName => $lastActivityMs) {
        $processors[] = esc_html($processorName . ' (' . Clock::toIsoUtc($lastActivityMs) . ')');
      }
      echo '<tr>';
      echo '<td><code>' . esc_html($queue->route) . '</code></td>';
      echo '<td>' . esc_html(implode(', ', $queue->methods)) . '</td>';
      echo '<td>' . esc_html((string) $this->entries->countInStates($queue->id, [EntryState::Queued])) . '</td>';
      echo '<td>' . esc_html((string) $this->entries->countInStates($queue->id, EntryState::activeStates())) . '</td>';
      echo '<td>' . implode('<br>', $processors) . '</td>';
      echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete this queue including all queued and running entries?\');">';
      echo '<input type="hidden" name="action" value="kornsw_uq_delete_queue">';
      echo '<input type="hidden" name="queueId" value="' . esc_attr((string) $queue->id) . '">';
      wp_nonce_field('kornsw_uq_delete_queue');
      echo '<button class="button button-link-delete">Delete</button></form></td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
  }

  /**
   * Renders the access keys and the form to create one.
   */
  private function renderKeys(): void {
    echo '<h2>Access keys</h2>';
    echo '<p>Send a key as <code>Authorization: Bearer &lt;key&gt;</code> (or <code>X-Ujmw-Queue-Key: &lt;key&gt;</code> if the host strips the Authorization header). ';
    echo 'Routes: comma-separated patterns, <code>*</code> matches anything (e.g. <code>api/reports*</code>).</p>';
    $keys = $this->keyStore->getAll();
    if (count($keys) > 0) {
      echo '<table class="widefat striped"><thead><tr><th>Label</th><th>Role</th><th>Routes</th><th>Created (UTC)</th><th></th></tr></thead><tbody>';
      foreach ($keys as $key) {
        echo '<tr><td>' . esc_html((string) $key['label']) . '</td><td>' . esc_html((string) $key['role']) . '</td>';
        echo '<td><code>' . esc_html((string) $key['routes']) . '</code></td><td>' . esc_html((string) $key['createdUtc']) . '</td>';
        echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete this key?\');">';
        echo '<input type="hidden" name="action" value="kornsw_uq_delete_key">';
        echo '<input type="hidden" name="keyId" value="' . esc_attr((string) $key['id']) . '">';
        wp_nonce_field('kornsw_uq_delete_key');
        echo '<button class="button button-link-delete">Delete</button></form></td></tr>';
      }
      echo '</tbody></table>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="kornsw_uq_create_key">';
    wp_nonce_field('kornsw_uq_create_key');
    echo '<p><label>Label <input type="text" name="label" required></label> ';
    echo '<label>Role <select name="role"><option value="caller">caller</option><option value="processor">processor</option></select></label> ';
    echo '<label>Routes <input type="text" name="routes" value="*"></label> ';
    echo '<button class="button button-primary">Create key</button></p></form>';
  }

  /**
   * Renders the settings form.
   */
  private function renderSettings(): void {
    $descriptions = [
      'maxQueueSize' => 'Maximum number of queued entries per queue (a full queue rejects new calls immediately).',
      'dequeueConfirmationTimeoutSec' => 'Seconds within which the first heartbeat must confirm a dequeue.',
      'heartbeatSec' => 'Heartbeat interval announced to processors.',
      'leaseLossTimeoutSec' => 'Seconds without heartbeat after which a lease is lost (fault "processor lost").',
      'synchronousCallTimeoutSec' => 'Maximum blocking time of a regular (non long-running) call.',
      'maxWaitSec' => 'Upper limit for the "wait" parameter of long-running calls (each waiting call occupies a PHP worker).',
      'callbackBufferLimit' => 'Maximum number of buffered callback invocations per entry.',
      'minOutcomePreservTime' => 'Minimum seconds an outcome is retained.',
      'maxOutcomePreservTime' => 'Maximum seconds an outcome is retained (after termination or the last pollNbf).',
      'outcomeCleanupLimit' => 'Number of retained outcomes above which the oldest are removed.',
      'throttlingLevel' => 'Load-dependent factor of the poll interval.',
    ];
    echo '<h2>Settings</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="kornsw_uq_save_settings">';
    wp_nonce_field('kornsw_uq_save_settings');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label for="kornsw-uq-prefix">URL prefix</label></th><td><input id="kornsw-uq-prefix" type="text" name="settings[urlPrefix]" value="' . esc_attr($this->settings->getUrlPrefix()) . '"></td></tr>';
    foreach (Settings::getIntegerSettingNames() as $name) {
      echo '<tr><th><label for="kornsw-uq-' . esc_attr($name) . '">' . esc_html($name) . '</label></th><td>';
      echo '<input id="kornsw-uq-' . esc_attr($name) . '" type="number" name="settings[' . esc_attr($name) . ']" value="' . esc_attr((string) $this->settings->getInt($name)) . '">';
      echo '<p class="description">' . esc_html($descriptions[$name] ?? '') . '</p></td></tr>';
    }
    $checked = '';
    if ($this->settings->isAnonymousInfoAllowed()) {
      $checked = ' checked';
    }
    echo '<tr><th>Anonymous statistics</th><td><label><input type="checkbox" name="settings[allowAnonymousInfo]" value="1"' . $checked . '> Allow <code>GET &lt;route&gt;/_queue</code> without key</label></td></tr>';
    echo '</tbody></table><p><button class="button button-primary">Save settings</button></p></form>';
  }

  /**
   * Renders the result notice of the last action.
   */
  private function renderNotice(string $notice, ?string $newKey): void {
    if ($newKey !== null) {
      echo '<div class="notice notice-success"><p>New key (shown only once, copy it now): <code>' . esc_html($newKey) . '</code></p></div>';
      return;
    }
    $messages = [
      'settings-saved' => 'Settings saved.',
      'key-deleted' => 'Key deleted.',
      'queue-deleted' => 'Queue deleted.',
      'invalid-role' => 'Invalid role.',
    ];
    if (isset($messages[$notice])) {
      echo '<div class="notice notice-info"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }
  }

  /**
   * Checks capability and nonce of a form post.
   */
  private function verifyRequest(string $action): void {
    if (!current_user_can(self::CAPABILITY)) {
      wp_die(esc_html('Access denied'), '', ['response' => 403]);
    }
    check_admin_referer($action);
  }

  /**
   * Redirects back to the page with a notice.
   */
  private function redirectBack(string $notice): never {
    wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'kornsw_uq_notice' => $notice], admin_url('options-general.php')));
    exit;
  }

}
