<?php

declare(strict_types=1);

namespace KornSW\UjmwQueue\Admin;

use KornSW\UjmwQueue\Security\KeyStore;
use KornSW\UjmwQueue\Settings;

/**
 * 'Settings > UJMW Queue' with the tabs "Processors" and "Endpoints" (live views, rendered by assets/admin.js from
 * the AJAX snapshot), "Security" (access keys, lock for new endpoints) and "Settings".
 */
final class AdminPage {

  public const PAGE_SLUG = 'kornsw-ujmwqueue';

  private const CAPABILITY = 'manage_options';

  private const NEW_KEY_TRANSIENT_PREFIX = 'kornsw_uq_new_key_';

  private const TABS = [
    'processors' => 'Processors',
    'endpoints' => 'Endpoints',
    'security' => 'Security',
    'settings' => 'Settings',
  ];

  /**
   * Integer settings shown in the group "Queue behavior" (name => description).
   */
  private const BEHAVIOR_SETTINGS = [
    'maxQueueSize' => 'Maximum number of queued entries per endpoint (a full queue rejects new calls immediately).',
    'incomingCallQueueWaitTimeoutSec' => 'Seconds a call may wait in the queue without being picked up by a processor (fault "No processor available"). Without any available processor the fault is sent immediately.',
    'processorSilentAfterSec' => 'Seconds without any contact after which a processor instance turns yellow and no longer counts as available (calls are then rejected with "No processor available" if no other instance is green).',
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

  public function __construct(
    private readonly Settings $settings,
    private readonly KeyStore $keyStore
  ) {
  }

  /**
   * Registers the menu entry, the assets and the form handlers.
   */
  public function register(): void {
    add_action('admin_menu', [$this, 'addMenuEntry']);
    add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    add_action('admin_post_kornsw_uq_save_settings', [$this, 'handleSaveSettings']);
    add_action('admin_post_kornsw_uq_create_key', [$this, 'handleCreateKey']);
    add_action('admin_post_kornsw_uq_delete_key', [$this, 'handleDeleteKey']);
  }

  /**
   * Adds the page below 'Settings'.
   */
  public function addMenuEntry(): void {
    add_options_page('UJMW Queue', 'UJMW Queue', self::CAPABILITY, self::PAGE_SLUG, [$this, 'render']);
  }

  /**
   * Loads script and styles on this page only.
   */
  public function enqueueAssets(string $hookSuffix): void {
    if ($hookSuffix !== 'settings_page_' . self::PAGE_SLUG) {
      return;
    }
    wp_enqueue_style('kornsw-uq-admin', KORNSW_UQ_URL . 'assets/admin.css', [], KORNSW_UQ_VERSION);
    wp_enqueue_script('kornsw-uq-admin', KORNSW_UQ_URL . 'assets/admin.js', [], KORNSW_UQ_VERSION, true);
    $config = [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce(AdminAjax::NONCE_ACTION),
      'tab' => $this->getCurrentTab(),
      'refreshMs' => 1000,
    ];
    wp_add_inline_script('kornsw-uq-admin', 'window.kornswUqConfig = ' . wp_json_encode($config) . ';', 'before');
  }

  /**
   * Stores the settings of one tab.
   */
  public function handleSaveSettings(): void {
    $this->verifyRequest('kornsw_uq_save_settings');
    $input = [];
    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
      $input = wp_unslash($_POST['settings']);
    }
    $section = sanitize_key(wp_unslash((string) ($_POST['section'] ?? '')));
    if ($section !== Settings::SECTION_SECURITY) {
      $section = Settings::SECTION_SETTINGS;
    }
    $this->settings->save($input, $section);
    $this->redirectBack($section, 'settings-saved');
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
      $this->redirectBack('security', 'invalid-role');
    }
    if ($routes === '') {
      $routes = '*';
    }
    $plainKey = $this->keyStore->create($label, $role, $routes);
    set_transient(self::NEW_KEY_TRANSIENT_PREFIX . get_current_user_id(), $plainKey, 300);
    $this->redirectBack('security', 'key-created');
  }

  /**
   * Deletes an access key.
   */
  public function handleDeleteKey(): void {
    $this->verifyRequest('kornsw_uq_delete_key');
    $this->keyStore->delete(sanitize_text_field(wp_unslash((string) ($_POST['keyId'] ?? ''))));
    $this->redirectBack('security', 'key-deleted');
  }

  /**
   * Renders the page.
   */
  public function render(): void {
    if (!current_user_can(self::CAPABILITY)) {
      wp_die(esc_html('Access denied'));
    }
    $currentTab = $this->getCurrentTab();
    echo '<div class="wrap kornsw-uq"><h1>UJMW Queue</h1>';
    $this->renderNotice();

    echo '<nav class="nav-tab-wrapper">';
    foreach (self::TABS as $tab => $title) {
      $class = 'nav-tab';
      if ($tab === $currentTab) {
        $class .= ' nav-tab-active';
      }
      $url = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $tab], admin_url('options-general.php'));
      echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($title) . '</a>';
    }
    echo '</nav>';

    if ($currentTab === 'processors') {
      $this->renderProcessorsTab();
    } elseif ($currentTab === 'endpoints') {
      $this->renderEndpointsTab();
    } elseif ($currentTab === 'security') {
      $this->renderSecurityTab();
    } else {
      $this->renderSettingsTab();
    }
    echo '</div>';
  }

  /**
   * Live table of the processor agents and their instances.
   */
  private function renderProcessorsTab(): void {
    $silentMinutes = round($this->settings->getInt('processorSilentAfterSec') / 60, 1);
    echo '<p class="kornsw-uq-legend">';
    echo 'LED: green = idle (polling), flickering green = busy, yellow = no contact for ' . esc_html((string) $silentMinutes) . ' min, off = no contact for 60 min.<br>';
    echo 'Lock: grey = open, orange = draining (no new jobs, running jobs may finish; click again to kill them), red = locked (click to unlock).<br>';
    echo '(n) = active jobs; the bar shows the load if the instance announces a capacity &gt; 1 (<code>&amp;capacity=</code>).</p>';
    echo '<table class="widefat striped kornsw-uq-table"><thead><tr><th>Processor</th><th>Instances</th><th>Endpoints</th></tr></thead>';
    echo '<tbody id="kornsw-uq-processors"><tr><td colspan="3">Loading&hellip;</td></tr></tbody></table>';
    echo '<noscript><p>This view needs JavaScript.</p></noscript>';
  }

  /**
   * Live table of the endpoints.
   */
  private function renderEndpointsTab(): void {
    $baseUrl = home_url('/' . $this->settings->getUrlPrefix() . '/');
    echo '<p>Callers use <code>' . esc_html($baseUrl) . '&lt;endpoint&gt;/&lt;Method&gt;</code>, processors use <code>' . esc_html($baseUrl) . '&lt;endpoint&gt;/_queue</code>. ';
    echo 'An endpoint is learned when the first processor connects (or an in-process client calls it). ';
    echo 'Jobs: <span class="kornsw-uq-queued">waiting</span> / <span class="kornsw-uq-active">active</span>; waiting jobs turn <span class="kornsw-uq-unserved">red</span> when no processor is polling. ';
    echo 'An endpoint lock stops the jobs of this endpoint only; the processors keep running.</p>';
    echo '<table class="widefat striped kornsw-uq-table"><thead><tr><th>Endpoint</th><th>Methods</th><th>Jobs</th><th>Processors</th><th>Monitoring</th></tr></thead>';
    echo '<tbody id="kornsw-uq-endpoints"><tr><td colspan="5">Loading&hellip;</td></tr></tbody></table>';
    echo '<p class="description">Monitoring: email to ' . esc_html($this->settings->getNotificationEmail()) . ' when the queue overflows or calls wait without any processor polling (at most once per hour and kind).</p>';
    echo '<noscript><p>This view needs JavaScript.</p></noscript>';
  }

  /**
   * Access keys and endpoint approval.
   */
  private function renderSecurityTab(): void {
    echo '<h2>Access keys</h2>';
    echo '<p>Send a key as <code>Authorization: Bearer &lt;key&gt;</code>, as <code>Authorization: &lt;key&gt;</code> or as <code>X-Ujmw-Queue-Key: &lt;key&gt;</code> (for hosts that strip the Authorization header). ';
    echo 'Routes: comma-separated patterns, <code>*</code> matches anything (e.g. <code>api/reports*</code>). In-process clients (other plugins) need no key.</p>';
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

    echo '<h2>New endpoints</h2>';
    $this->openSettingsForm(Settings::SECTION_SECURITY);
    echo '<table class="form-table"><tbody>';
    $this->renderCheckboxRow('lockNewEndpoints', 'Approval', 'New endpoints are locked by default', 'A newly learned endpoint must be unlocked on the tab "Endpoints" before processors receive its jobs (callers get "No processor available" until then). The administrator is notified by email.');
    echo '</tbody></table><p><button class="button button-primary">Save</button></p></form>';
  }

  /**
   * General settings, queue behavior and retention.
   */
  private function renderSettingsTab(): void {
    $this->openSettingsForm(Settings::SECTION_SETTINGS);

    echo '<h2>General</h2><table class="form-table"><tbody>';
    echo '<tr><th><label for="kornsw-uq-urlPrefix">URL prefix</label></th><td><input id="kornsw-uq-urlPrefix" type="text" name="settings[urlPrefix]" value="' . esc_attr($this->settings->getUrlPrefix()) . '">';
    echo '<p class="description">Relay base URL: <code>' . esc_html(home_url('/' . $this->settings->getUrlPrefix() . '/')) . '</code></p></td></tr>';
    echo '<tr><th><label for="kornsw-uq-notificationEmail">Notification email</label></th><td><input id="kornsw-uq-notificationEmail" type="email" class="regular-text" name="settings[notificationEmail]" value="' . esc_attr($this->settings->getConfiguredNotificationEmail()) . '" placeholder="' . esc_attr((string) get_option('admin_email')) . '">';
    echo '<p class="description">Empty = the site\'s administration email address.</p></td></tr>';
    $this->renderCheckboxRow('allowAnonymousInfo', 'Anonymous statistics', 'Allow GET <route>/_queue without key', '');
    echo '</tbody></table>';

    echo '<h2>Queue behavior</h2><table class="form-table"><tbody>';
    foreach (self::BEHAVIOR_SETTINGS as $name => $description) {
      $this->renderNumberRow($name, $name, $description, '');
    }
    echo '</tbody></table>';

    echo '<h2>Retention</h2><table class="form-table"><tbody>';
    $this->renderCheckboxRow('instanceRetentionEnabled', 'Instance retention', 'Enable instance retention', 'Instances offline for more than one day are deleted automatically, provided at least one other instance of the same processor is alive.');
    $this->renderCheckboxRow('processorRetentionEnabled', 'Processor retention', 'Enable processor retention', 'Processors whose instances are all offline are deleted after the number of days below.');
    $this->renderNumberRow('processorRetentionDays', 'Delete orphaned processors after', '', 'days');
    $this->renderCheckboxRow('endpointRetentionEnabled', 'Endpoint retention', 'Enable endpoint retention', 'Endpoints without any known processor (and without open jobs) are deleted after the number of days below, counted from the last processor contact.');
    $this->renderNumberRow('endpointRetentionUsedDays', 'Endpoints that had calls', '', 'days');
    $this->renderNumberRow('endpointRetentionUnusedDays', 'Endpoints that were only announced', '', 'days');
    echo '</tbody></table>';

    echo '<p><button class="button button-primary">Save settings</button></p></form>';
  }

  /**
   * Opens the settings form of one section.
   */
  private function openSettingsForm(string $section): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="kornsw_uq_save_settings">';
    echo '<input type="hidden" name="section" value="' . esc_attr($section) . '">';
    wp_nonce_field('kornsw_uq_save_settings');
  }

  /**
   * Renders a checkbox setting.
   */
  private function renderCheckboxRow(string $name, string $title, string $label, string $description): void {
    $checked = '';
    if ($this->settings->getBool($name)) {
      $checked = ' checked';
    }
    echo '<tr><th>' . esc_html($title) . '</th><td><label><input type="checkbox" name="settings[' . esc_attr($name) . ']" value="1"' . $checked . '> ' . esc_html($label) . '</label>';
    if ($description !== '') {
      echo '<p class="description">' . esc_html($description) . '</p>';
    }
    echo '</td></tr>';
  }

  /**
   * Renders an integer setting.
   */
  private function renderNumberRow(string $name, string $title, string $description, string $unit): void {
    echo '<tr><th><label for="kornsw-uq-' . esc_attr($name) . '">' . esc_html($title) . '</label></th><td>';
    echo '<input id="kornsw-uq-' . esc_attr($name) . '" class="small-text" type="number" name="settings[' . esc_attr($name) . ']" value="' . esc_attr((string) $this->settings->getInt($name)) . '"> ' . esc_html($unit);
    if ($description !== '') {
      echo '<p class="description">' . esc_html($description) . '</p>';
    }
    echo '</td></tr>';
  }

  /**
   * Renders the result notice of the last action (and a freshly created key once).
   */
  private function renderNotice(): void {
    $newKeyTransient = self::NEW_KEY_TRANSIENT_PREFIX . get_current_user_id();
    $newKey = get_transient($newKeyTransient);
    if (is_string($newKey)) {
      delete_transient($newKeyTransient);
      echo '<div class="notice notice-success"><p>New key (shown only once, copy it now): <code>' . esc_html($newKey) . '</code></p></div>';
      return;
    }
    $notice = sanitize_key(wp_unslash((string) ($_GET['kornsw_uq_notice'] ?? '')));
    $messages = [
      'settings-saved' => 'Settings saved.',
      'key-deleted' => 'Key deleted.',
      'invalid-role' => 'Invalid role.',
    ];
    if (isset($messages[$notice])) {
      echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }
  }

  /**
   * The selected tab (default "Processors").
   */
  private function getCurrentTab(): string {
    $tab = sanitize_key(wp_unslash((string) ($_GET['tab'] ?? '')));
    if (!isset(self::TABS[$tab])) {
      return 'processors';
    }
    return $tab;
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
   * Redirects back to a tab with a notice.
   */
  private function redirectBack(string $tab, string $notice): never {
    wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $tab, 'kornsw_uq_notice' => $notice], admin_url('options-general.php')));
    exit;
  }

}
