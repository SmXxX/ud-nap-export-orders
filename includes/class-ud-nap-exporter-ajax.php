<?php
/**
 * AJAX + admin-post handlers for both export pipelines (XML SAF-T and CSV).
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UD_NAP_Exporter_Ajax {

	/** @var UD_NAP_Exporter_Settings */
	private $settings;

	/** @var UD_NAP_Exporter_Exporter */
	private $xml_exporter;

	/** @var UD_NAP_Exporter_CSV_Exporter */
	private $csv_exporter;

	public function __construct( UD_NAP_Exporter_Settings $settings ) {
		$this->settings     = $settings;
		$this->xml_exporter = new UD_NAP_Exporter_Exporter( $settings );
		$this->csv_exporter = new UD_NAP_Exporter_CSV_Exporter( $settings );
	}

	public function hooks() {
		// XML pipeline.
		add_action( 'wp_ajax_ud_nap_start', array( $this, 'handle_xml_start' ) );
		add_action( 'wp_ajax_ud_nap_step', array( $this, 'handle_xml_step' ) );
		add_action( 'admin_post_ud_nap_download', array( $this, 'handle_xml_download' ) );

		// CSV pipeline.
		add_action( 'wp_ajax_ud_nap_csv_start', array( $this, 'handle_csv_start' ) );
		add_action( 'wp_ajax_ud_nap_csv_step', array( $this, 'handle_csv_step' ) );
		add_action( 'admin_post_ud_nap_csv_download', array( $this, 'handle_csv_download' ) );

		// Inline auto-save for the CSV column picker.
		add_action( 'wp_ajax_ud_nap_save_columns', array( $this, 'handle_save_columns' ) );
	}

	/**
	 * Persist the CSV column selection without the user clicking "Save".
	 * Loads the current option, replaces only the csv_columns key, writes back.
	 */
	public function handle_save_columns() {
		$this->verify();

		$raw = isset( $_POST['columns'] ) && is_array( $_POST['columns'] ) ? wp_unslash( $_POST['columns'] ) : array();
		$raw = array_map( 'sanitize_key', $raw );

		$valid   = array_keys( UD_NAP_Exporter_Settings::standard_csv_columns() );
		$columns = array_values( array_intersect( $valid, $raw ) );

		$existing = get_option( UD_NAP_Exporter_Settings::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$existing['csv_columns'] = $columns;
		update_option( UD_NAP_Exporter_Settings::OPTION_KEY, $existing );

		wp_send_json_success( array( 'count' => count( $columns ) ) );
	}

	// ---- XML ----------------------------------------------------------------

	public function handle_xml_start() {
		$this->verify();
		if ( ! $this->settings->is_xml_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'XML експортът е изключен в настройките.', 'ud-nap-orders-exporter' ) ) );
		}
		list( $from, $to, $refunds ) = $this->parse_input();
		if ( ! $from || ! $to ) {
			wp_send_json_error( array( 'message' => __( 'Моля, изберете валиден период.', 'ud-nap-orders-exporter' ) ) );
		}
		$job = $this->xml_exporter->start_job( $from, $to, $refunds );
		wp_send_json_success( $this->job_payload( $job ) );
	}

	public function handle_xml_step() {
		$this->verify();
		$job = $this->xml_exporter->step( $this->job_id_from_request() );
		if ( isset( $job['error'] ) ) {
			wp_send_json_error( array( 'message' => $job['error'] ) );
		}
		wp_send_json_success( $this->job_payload( $job ) );
	}

	public function handle_xml_download() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате достатъчни права.', 'ud-nap-orders-exporter' ) );
		}
		$job_id = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : '';
		check_admin_referer( 'ud_nap_download_' . $job_id );
		$this->xml_exporter->stream_download( $job_id );
	}

	// ---- CSV ----------------------------------------------------------------

	public function handle_csv_start() {
		$this->verify();
		if ( ! $this->settings->is_csv_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Експортът на таблица е изключен в настройките.', 'ud-nap-orders-exporter' ) ) );
		}
		list( $from, $to, $refunds ) = $this->parse_input();
		if ( ! $from || ! $to ) {
			wp_send_json_error( array( 'message' => __( 'Моля, изберете валиден период.', 'ud-nap-orders-exporter' ) ) );
		}

		$payment_methods = array();
		if ( isset( $_POST['payment_methods'] ) && is_array( $_POST['payment_methods'] ) ) {
			$payment_methods = array_values(
				array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['payment_methods'] ) ) )
			);
		}

		$report_type = isset( $_POST['report_type'] ) ? sanitize_key( wp_unslash( $_POST['report_type'] ) ) : 'all';
		if ( ! in_array( $report_type, array( 'all', 'card', 'cod' ), true ) ) {
			$report_type = 'all';
		}

		$job = $this->csv_exporter->start_job( $from, $to, $refunds, $payment_methods, $report_type );
		wp_send_json_success( $this->job_payload( $job ) );
	}

	public function handle_csv_step() {
		$this->verify();
		$job = $this->csv_exporter->step( $this->job_id_from_request() );
		if ( isset( $job['error'] ) ) {
			wp_send_json_error( array( 'message' => $job['error'] ) );
		}
		wp_send_json_success( $this->job_payload( $job ) );
	}

	public function handle_csv_download() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате достатъчни права.', 'ud-nap-orders-exporter' ) );
		}
		$job_id = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : '';
		check_admin_referer( 'ud_nap_csv_download_' . $job_id );
		$this->csv_exporter->stream_download( $job_id );
	}

	// ---- helpers ------------------------------------------------------------

	private function verify() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Нямате достатъчни права.', 'ud-nap-orders-exporter' ) ), 403 );
		}
		check_ajax_referer( 'ud_nap_exporter', 'nonce' );
	}

	private function parse_input() {
		$from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
		$to   = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			return array( '', '', false );
		}
		return array( $from, $to, ! empty( $_POST['include_refunds'] ) );
	}

	private function job_id_from_request() {
		return isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
	}

	private function job_payload( array $job ) {
		return array(
			'id'           => $job['id'],
			'total'        => (int) $job['total'],
			'processed'    => (int) $job['processed'],
			'finished'     => ! empty( $job['finished'] ),
			'download_url' => isset( $job['download_url'] ) ? $job['download_url'] : '',
		);
	}
}
