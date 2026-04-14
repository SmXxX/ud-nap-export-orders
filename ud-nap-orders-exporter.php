<?php
/**
 * Plugin Name:       UD НАП Orders Exporter
 * Plugin URI:        https://unbelievable.digital/
 * Description:       Генерира стандартизиран одиторски XML файл (SAF-T) за докладване към НАП по Наредба Н-18, алтернативен метод за докладване за електронната търговия. Поддържа и експорт на поръчки в CSV таблица.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Unbelievable Digital
 * Author URI:        https://unbelievable.digital/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ud-nap-orders-exporter
 * Domain Path:       /languages
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UD_NAP_EXPORTER_VERSION', '0.1.0' );
define( 'UD_NAP_EXPORTER_FILE', __FILE__ );
define( 'UD_NAP_EXPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'UD_NAP_EXPORTER_URL', plugin_dir_url( __FILE__ ) );
define( 'UD_NAP_EXPORTER_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Bootstrap the plugin once all plugins are loaded so we can safely depend on
 * WooCommerce being present.
 */
add_action( 'plugins_loaded', 'ud_nap_exporter_bootstrap' );

function ud_nap_exporter_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'ud_nap_exporter_missing_wc_notice' );
		return;
	}

	require_once UD_NAP_EXPORTER_PATH . 'includes/class-ud-nap-exporter-plugin.php';
	UD_NAP_Exporter_Plugin::instance();
}

function ud_nap_exporter_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'UD НАП Експорт изисква WooCommerce да е инсталиран и активиран.', 'ud-nap-orders-exporter' );
	echo '</p></div>';
}

/**
 * On activation, make sure the export storage directory exists and is protected.
 */
register_activation_hook( __FILE__, 'ud_nap_exporter_activate' );

function ud_nap_exporter_activate() {
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'ud-nap-exports';

	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	// Block direct web access to generated XML files.
	$htaccess = $dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Order allow,deny\nDeny from all\n" );
	}
	$index = $dir . '/index.html';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, '' );
	}
}
