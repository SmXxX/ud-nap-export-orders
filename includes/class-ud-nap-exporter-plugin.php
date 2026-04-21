<?php
/**
 * Main plugin loader. Wires together admin UI, settings, AJAX handlers and
 * the export pipeline.
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UD_NAP_Exporter_Plugin {

	/** @var UD_NAP_Exporter_Plugin|null */
	private static $instance = null;

	/** @var UD_NAP_Exporter_Admin */
	public $admin;

	/** @var UD_NAP_Exporter_Settings */
	public $settings;

	/** @var UD_NAP_Exporter_Ajax */
	public $ajax;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->init_components();
	}

	private function load_dependencies() {
		require_once UD_NAP_EXPORTER_PATH . 'includes/class-ud-nap-exporter-settings.php';
		require_once UD_NAP_EXPORTER_PATH . 'includes/class-ud-nap-exporter-query.php';
		require_once UD_NAP_EXPORTER_PATH . 'includes/class-ud-nap-exporter-doc-counter.php';
		require_once UD_NAP_EXPORTER_PATH . 'includes/class-ud-nap-exporter-xml-writer.php';
		require_once UD_NAP_EXPORTER_PATH . 'includes/class-ud-nap-exporter-exporter.php';
		require_once UD_NAP_EXPORTER_PATH . 'includes/class-ud-nap-exporter-receipt.php';
		require_once UD_NAP_EXPORTER_PATH . 'includes/class-ud-nap-exporter-xlsx-writer.php';
		require_once UD_NAP_EXPORTER_PATH . 'includes/class-ud-nap-exporter-csv-exporter.php';
		require_once UD_NAP_EXPORTER_PATH . 'includes/class-ud-nap-exporter-admin.php';
		require_once UD_NAP_EXPORTER_PATH . 'includes/class-ud-nap-exporter-ajax.php';
	}

	private function init_components() {
		$this->settings = new UD_NAP_Exporter_Settings();
		$this->admin    = new UD_NAP_Exporter_Admin( $this->settings, $GLOBALS['ud_nap_update_checker'] ?? null );
		$this->ajax     = new UD_NAP_Exporter_Ajax( $this->settings );

		$this->settings->hooks();
		$this->admin->hooks();
		$this->ajax->hooks();
	}
}
