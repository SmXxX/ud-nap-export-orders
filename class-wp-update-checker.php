<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP Update Checker - Custom WordPress Plugin Update & License SDK
 *
 * Include this file in your WordPress plugin and initialize it:
 *
 * require_once __DIR__ . '/class-wp-update-checker.php';
 * new WP_Update_Checker(
 *     'https://your-update-server.com',  // Laravel server URL
 *     __FILE__,                          // Main plugin file
 *     'your-plugin-slug'                 // Plugin slug
 * );
 *
 * For license-protected plugins:
 * $checker = new WP_Update_Checker(
 *     'https://your-update-server.com',
 *     __FILE__,
 *     'your-plugin-slug',
 *     'XXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX'  // License key
 * );
 *
 * Or set the license key later:
 * $checker->set_license_key('XXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX');
 */

if (!class_exists('WP_Update_Checker')) {

    class WP_Update_Checker
    {
        private string $api_url;
        private string $plugin_file;
        private string $plugin_slug;
        private string $plugin_basename;
        private string $current_version;
        private string $cache_key;
        private int $cache_duration = 43200; // 12 hours in seconds
        private ?string $license_key = null;
        private string $license_option_key;

        /**
         * @param string $api_url       The base URL of your Laravel update server (no trailing slash)
         * @param string $plugin_file   The main plugin file path (__FILE__ from main plugin file)
         * @param string $plugin_slug   The plugin slug (must match the slug on the update server)
         * @param string|null $license_key  Optional license key for licensed plugins
         */
        public function __construct(string $api_url, string $plugin_file, string $plugin_slug, ?string $license_key = null)
        {
            $this->api_url = rtrim($api_url, '/');
            $this->plugin_file = $plugin_file;
            $this->plugin_slug = $plugin_slug;
            $this->plugin_basename = plugin_basename($plugin_file);
            $this->cache_key = 'wuc_' . md5($plugin_slug);
            $this->license_option_key = 'wuc_license_' . $plugin_slug;

            // Get current version from plugin header
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $plugin_data = get_plugin_data($plugin_file);
            $this->current_version = $plugin_data['Version'] ?? '0.0.0';

            // Set license key from parameter, saved option, or null
            if ($license_key) {
                $this->license_key = $license_key;
            } else {
                $this->license_key = get_option($this->license_option_key, null);
            }

            // Hook into WordPress update system
            add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
            add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
            add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);

            // Add license settings page
            add_action('admin_menu', [$this, 'register_license_page']);
            add_action('admin_init', [$this, 'register_license_settings']);

            // License expiration admin notice
            add_action('admin_notices', [$this, 'license_expiration_notice']);
        }

        /**
         * Set the license key programmatically.
         */
        public function set_license_key(string $key): void
        {
            $this->license_key = $key;
            update_option($this->license_option_key, $key);
            // Clear cache when license changes
            delete_transient($this->cache_key);
            delete_transient($this->cache_key . '_info');
            delete_transient($this->cache_key . '_license_status');
        }

        /**
         * Get the current license key.
         */
        public function get_license_key(): ?string
        {
            return $this->license_key;
        }

        /**
         * Verify the license key with the server.
         */
        public function verify_license(?string $key = null): array
        {
            $key = $key ?? $this->license_key;

            if (!$key) {
                return ['valid' => false, 'error' => 'No license key provided.'];
            }

            $response = wp_remote_post($this->api_url . '/api/v1/license/verify', [
                'timeout' => 15,
                'body' => [
                    'license_key' => $key,
                    'slug' => $this->plugin_slug,
                    'domain' => $this->get_site_domain(),
                ],
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                return ['valid' => false, 'error' => $body['error'] ?? 'Could not verify license.'];
            }

            return json_decode(wp_remote_retrieve_body($response), true) ?? ['valid' => false];
        }

        /**
         * Deactivate the license for this domain.
         */
        public function deactivate_license(): array
        {
            if (!$this->license_key) {
                return ['success' => false, 'error' => 'No license key set.'];
            }

            $response = wp_remote_post($this->api_url . '/api/v1/license/deactivate', [
                'timeout' => 15,
                'body' => [
                    'license_key' => $this->license_key,
                    'slug' => $this->plugin_slug,
                    'domain' => $this->get_site_domain(),
                ],
            ]);

            if (is_wp_error($response)) {
                return ['success' => false, 'error' => 'Network error.'];
            }

            return json_decode(wp_remote_retrieve_body($response), true) ?? ['success' => false];
        }

        /**
         * Check for plugin updates.
         */
        public function check_for_update($transient)
        {
            if (empty($transient->checked)) {
                return $transient;
            }

            $update_data = $this->get_update_data();

            if ($update_data && !empty($update_data['update_available'])) {
                $plugin_info = new stdClass();
                $plugin_info->slug = $this->plugin_slug;
                $plugin_info->plugin = $this->plugin_basename;
                $plugin_info->new_version = $update_data['version'];
                $plugin_info->url = $update_data['homepage'] ?? '';
                $plugin_info->tested = $update_data['tested_wp_version'] ?? '';
                $plugin_info->requires = $update_data['min_wp_version'] ?? '';
                $plugin_info->requires_php = $update_data['min_php_version'] ?? '';

                // Add license key to download URL if required
                $download_url = $update_data['download_url'];
                if (!empty($update_data['requires_license']) && $this->license_key) {
                    $download_url = add_query_arg([
                        'license_key' => $this->license_key,
                        'domain' => $this->get_site_domain(),
                    ], $download_url);
                }
                $plugin_info->package = $download_url;

                $transient->response[$this->plugin_basename] = $plugin_info;
            } else {
                $plugin_info = new stdClass();
                $plugin_info->slug = $this->plugin_slug;
                $plugin_info->plugin = $this->plugin_basename;
                $plugin_info->new_version = $this->current_version;
                $plugin_info->url = '';
                $plugin_info->package = '';

                $transient->no_update[$this->plugin_basename] = $plugin_info;
            }

            return $transient;
        }

        /**
         * Provide plugin information for the "View Details" popup.
         */
        public function plugin_info($result, $action, $args)
        {
            if ($action !== 'plugin_information') {
                return $result;
            }

            if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
                return $result;
            }

            $info = $this->get_plugin_info();

            if (!$info) {
                return $result;
            }

            $plugin_info = new stdClass();
            $plugin_info->name = $info['name'] ?? $this->plugin_slug;
            $plugin_info->slug = $info['slug'] ?? $this->plugin_slug;
            $plugin_info->version = $info['version'] ?? $this->current_version;
            $plugin_info->author = $info['author'] ?? '';
            $plugin_info->author_profile = $info['author_profile'] ?? '';
            $plugin_info->homepage = $info['homepage'] ?? '';
            $plugin_info->download_link = $info['download_link'] ?? '';
            $plugin_info->tested = $info['tested'] ?? '';
            $plugin_info->requires = $info['requires'] ?? '';
            $plugin_info->requires_php = $info['requires_php'] ?? '';
            $plugin_info->last_updated = $info['last_updated'] ?? '';

            if (!empty($info['sections'])) {
                $plugin_info->sections = $info['sections'];
            }

            if (!empty($info['icons'])) {
                $plugin_info->icons = $info['icons'];
            }

            if (!empty($info['banners'])) {
                $plugin_info->banners = $info['banners'];
            }

            return $plugin_info;
        }

        /**
         * Ensure the plugin directory name stays correct after update.
         */
        public function after_install($response, $hook_extra, $result)
        {
            global $wp_filesystem;

            if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
                return $result;
            }

            $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($this->plugin_basename);
            $wp_filesystem->move($result['destination'], $plugin_dir);
            $result['destination'] = $plugin_dir;

            // Clear cache after update
            delete_transient($this->cache_key);
            delete_transient($this->cache_key . '_info');

            // WordPress handles re-activation automatically after update.
            // Calling activate_plugin() here causes premature re-activation
            // while the upgrader is still running, which can break state.

            return $result;
        }

        /**
         * Register a license settings page under the plugin's menu.
         */
        public function register_license_page(): void
        {
            add_submenu_page(
                'bgdc-settings',
                'License',
                'License',
                'manage_options',
                $this->plugin_slug . '-license',
                [$this, 'render_license_page']
            );
        }

        /**
         * Register license settings.
         */
        public function register_license_settings(): void
        {
            register_setting($this->plugin_slug . '_license', $this->license_option_key, [
                'sanitize_callback' => function ($value) {
                    return sanitize_text_field(trim($value));
                },
            ]);

            // Handle license actions
            if (isset($_POST[$this->plugin_slug . '_license_action'])) {
                if (!wp_verify_nonce($_POST[$this->plugin_slug . '_nonce'] ?? '', $this->plugin_slug . '_license_nonce')) {
                    return;
                }

                $action = sanitize_text_field($_POST[$this->plugin_slug . '_license_action']);

                if ($action === 'activate') {
                    $key = sanitize_text_field($_POST[$this->license_option_key] ?? '');
                    if ($key) {
                        $this->set_license_key($key);
                        $result = $this->verify_license($key);
                        if ($result['valid']) {
                            add_settings_error($this->plugin_slug . '_license', 'activated', 'License activated successfully.', 'success');
                        } else {
                            add_settings_error($this->plugin_slug . '_license', 'error', $result['error'] ?? 'Activation failed.');
                        }
                    }
                } elseif ($action === 'deactivate') {
                    $result = $this->deactivate_license();
                    delete_option($this->license_option_key);
                    $this->license_key = null;
                    delete_transient($this->cache_key);
                    delete_transient($this->cache_key . '_license_status');
                    add_settings_error($this->plugin_slug . '_license', 'deactivated', 'License deactivated.', 'success');
                }
            }
        }

        /**
         * Render the license settings page.
         */
        public function render_license_page(): void
        {
            $license_key = $this->license_key;
            $license_status = null;

            if ($license_key) {
                $license_status = $this->verify_license($license_key);
            }

            ?>
            <div class="wrap">
                <h1><?php echo esc_html($this->plugin_slug); ?> - License</h1>

                <?php settings_errors($this->plugin_slug . '_license'); ?>

                <form method="post" action="">
                    <?php wp_nonce_field($this->plugin_slug . '_license_nonce', $this->plugin_slug . '_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">License Key</th>
                            <td>
                                <input type="text" name="<?php echo esc_attr($this->license_option_key); ?>"
                                       value="<?php echo esc_attr($license_key ?? ''); ?>"
                                       class="regular-text" placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX"
                                       <?php echo $license_key ? 'readonly' : ''; ?>>
                            </td>
                        </tr>
                        <?php if ($license_key && $license_status): ?>
                        <tr>
                            <th scope="row">Status</th>
                            <td>
                                <?php if (!empty($license_status['valid'])): ?>
                                    <span style="color: green; font-weight: bold;">Active</span>
                                    <?php if (!empty($license_status['license'])): ?>
                                        <br><small>
                                            Type: <?php echo esc_html($license_status['license']['type']); ?> |
                                            Domains: <?php echo esc_html($license_status['license']['active_domains']); ?> /
                                            <?php echo $license_status['license']['max_domains'] == 0 ? '∞' : esc_html($license_status['license']['max_domains']); ?>
                                            <?php if (!empty($license_status['license']['expires_at'])): ?>
                                                | Expires: <?php echo esc_html(date('Y-m-d', strtotime($license_status['license']['expires_at']))); ?>
                                            <?php endif; ?>
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: red; font-weight: bold;">Invalid</span>
                                    <?php if (!empty($license_status['error'])): ?>
                                        <br><small><?php echo esc_html($license_status['error']); ?></small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <?php if ($license_key): ?>
                        <input type="hidden" name="<?php echo esc_attr($this->plugin_slug); ?>_license_action" value="deactivate">
                        <?php submit_button('Deactivate License', 'secondary'); ?>
                    <?php else: ?>
                        <input type="hidden" name="<?php echo esc_attr($this->plugin_slug); ?>_license_action" value="activate">
                        <?php submit_button('Activate License'); ?>
                    <?php endif; ?>
                </form>
            </div>
            <?php
        }

        /**
         * Display admin notice when the license is expired or missing.
         */
        public function license_expiration_notice(): void
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            $cache_key = $this->cache_key . '_license_status';
            $status = get_transient($cache_key);

            if ($status === false) {
                if (!$this->license_key) {
                    $status = ['state' => 'missing'];
                } else {
                    $result = $this->verify_license();
                    if (!empty($result['valid']) && !empty($result['license']['expires_at'])) {
                        $expires = strtotime($result['license']['expires_at']);
                        if ($expires && $expires < time()) {
                            $status = [
                                'state'      => 'expired',
                                'expires_at' => $result['license']['expires_at'],
                            ];
                        } else {
                            $status = ['state' => 'active'];
                        }
                    } elseif (empty($result['valid'])) {
                        $status = ['state' => 'invalid'];
                    } else {
                        $status = ['state' => 'active'];
                    }
                }
                set_transient($cache_key, $status, 12 * HOUR_IN_SECONDS);
            }

            if ($status['state'] === 'expired') {
                $license_page = admin_url('options-general.php?page=' . $this->plugin_slug . '-license');
                $date = date_i18n('d.m.Y', strtotime($status['expires_at']));
                printf(
                    '<div class="notice notice-error"><p><strong>%s:</strong> %s <a href="%s">%s</a></p></div>',
                    esc_html($this->plugin_slug),
                    esc_html(sprintf('Лицензът ви е изтекъл на %s. Моля, подновете го, за да получавате най-новите актуализации.', $date)),
                    esc_url($license_page),
                    esc_html('Управление на лиценза')
                );
            } elseif ($status['state'] === 'missing') {
                $license_page = admin_url('options-general.php?page=' . $this->plugin_slug . '-license');
                printf(
                    '<div class="notice notice-warning"><p><strong>%s:</strong> %s <a href="%s">%s</a></p></div>',
                    esc_html($this->plugin_slug),
                    esc_html('Не е въведен лицензен ключ. Моля, активирайте лиценза си, за да получавате актуализации.'),
                    esc_url($license_page),
                    esc_html('Въведете лиценз')
                );
            } elseif ($status['state'] === 'invalid') {
                $license_page = admin_url('options-general.php?page=' . $this->plugin_slug . '-license');
                printf(
                    '<div class="notice notice-error"><p><strong>%s:</strong> %s <a href="%s">%s</a></p></div>',
                    esc_html($this->plugin_slug),
                    esc_html('Лицензният ключ е невалиден. Моля, проверете го или се свържете с поддръжката.'),
                    esc_url($license_page),
                    esc_html('Управление на лиценза')
                );
            }
        }

        /**
         * Get update data from the server (with caching).
         */
        private function get_update_data(): ?array
        {
            // Skip cache when WordPress is doing a force check (Dashboard → Updates → "Check again")
            // WordPress deletes the update_plugins site transient on force-check, so if we're
            // being called it means WP is actively re-checking. Compare with our last check time.
            $force = isset($_GET['force-check']) && $_GET['force-check'];
            $site_transient_fresh = !get_site_transient('update_plugins');
            if ($force || $site_transient_fresh) {
                delete_transient($this->cache_key);
                delete_transient($this->cache_key . '_info');
            }

            $cached = get_transient($this->cache_key);

            if ($cached !== false) {
                return $cached;
            }

            $body = [
                'slug' => $this->plugin_slug,
                'version' => $this->current_version,
                'domain' => $this->get_site_domain(),
            ];

            $response = wp_remote_post($this->api_url . '/api/v1/check-update', [
                'timeout' => 15,
                'body' => $body,
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return null;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (!is_array($body)) {
                return null;
            }

            set_transient($this->cache_key, $body, $this->cache_duration);

            return $body;
        }

        /**
         * Get full plugin information from the server (with caching).
         */
        private function get_plugin_info(): ?array
        {
            $cache_key = $this->cache_key . '_info';
            $cached = get_transient($cache_key);

            if ($cached !== false) {
                return $cached;
            }

            $response = wp_remote_get($this->api_url . '/api/v1/plugin/' . $this->plugin_slug . '/info', [
                'timeout' => 15,
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return null;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (!is_array($body)) {
                return null;
            }

            set_transient($cache_key, $body, $this->cache_duration);

            return $body;
        }

        /**
         * Get the current site domain (normalized).
         */
        private function get_site_domain(): string
        {
            $url = home_url();
            $domain = strtolower(wp_parse_url($url, PHP_URL_HOST));
            $domain = preg_replace('/^www\./', '', $domain);
            return $domain;
        }
    }
}
