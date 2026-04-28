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

	/** @var UD_NAP_Exporter_Receipt */
	private $receipt;

	public function __construct( UD_NAP_Exporter_Settings $settings ) {
		$this->settings     = $settings;
		$this->xml_exporter = new UD_NAP_Exporter_Exporter( $settings );
		$this->csv_exporter = new UD_NAP_Exporter_CSV_Exporter( $settings );
		$this->receipt      = new UD_NAP_Exporter_Receipt( $settings );
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

		// НАП receipt PDF.
		add_action( 'admin_post_ud_nap_receipt', array( $this, 'handle_receipt' ) );

		// Order edit screen button + orders list row action.
		add_action( 'add_meta_boxes', array( $this, 'register_receipt_metabox' ) );
		add_filter( 'woocommerce_admin_order_actions', array( $this, 'add_orders_list_action' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_legacy_row_action' ), 10, 2 );

		// Attach receipt PDF to customer order emails.
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach_receipt_to_emails' ), 10, 3 );

		// Save manual RRN / transaction id entered on the order edit screen
		// (HPOS + classic both fire woocommerce_process_shop_order_meta).
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_manual_txn_id' ) );
	}

	/**
	 * Attach the НАП receipt PDF to customer-facing "processing" and "completed"
	 * order emails. The fiscal document number (doc_n) is allocated on first
	 * render and persisted on the order, so the emailed PDF is identical to
	 * anything reprinted later.
	 *
	 * @param array              $attachments
	 * @param string             $email_id
	 * @param WC_Abstract_Order  $order
	 * @return array
	 */
	public function attach_receipt_to_emails( $attachments, $email_id, $order ) {
		$allowed = apply_filters(
			'ud_nap_receipt_email_ids',
			array( 'customer_processing_order', 'customer_completed_order' )
		);
		if ( ! in_array( $email_id, (array) $allowed, true ) ) {
			return $attachments;
		}
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return $attachments;
		}

		$path = $this->receipt->render_to_file( $order );
		if ( $path ) {
			$attachments[] = $path;
		}
		return $attachments;
	}

	/**
	 * Streams a single НАП receipt PDF inline. Works for both classic (shop_order
	 * posts) and HPOS order URLs — the order ID is taken from the query arg.
	 */
	public function handle_receipt() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате достатъчни права.', 'ud-nap-orders-exporter' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'ud_nap_receipt_' . $order_id );
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			wp_die( esc_html__( 'Поръчката не е намерена.', 'ud-nap-orders-exporter' ) );
		}
		$this->receipt->stream( $order );
	}

	public function register_receipt_metabox() {
		// Classic post-type order screen.
		add_meta_box(
			'ud-nap-receipt',
			__( 'НАП електронна разписка', 'ud-nap-orders-exporter' ),
			array( $this, 'render_receipt_metabox' ),
			'shop_order',
			'side',
			'default'
		);
		// HPOS order screen.
		if ( class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			add_meta_box(
				'ud-nap-receipt',
				__( 'НАП електронна разписка', 'ud-nap-orders-exporter' ),
				array( $this, 'render_receipt_metabox' ),
				wc_get_page_screen_id( 'shop-order' ),
				'side',
				'default'
			);
		}
	}

	public function render_receipt_metabox( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post )
			? wc_get_order( $post_or_order->ID )
			: $post_or_order;
		if ( ! $order ) {
			return;
		}
		$url        = $this->receipt_url( $order->get_id() );
		$manual_txn = (string) $order->get_meta( '_ud_nap_manual_txn_id' );

		echo '<p>' . esc_html__( 'Генерира PDF документ за регистриране на продажба по Наредба Н-18.', 'ud-nap-orders-exporter' ) . '</p>';

		wp_nonce_field( 'ud_nap_save_manual_txn_' . $order->get_id(), '_ud_nap_manual_txn_nonce' );
		echo '<p style="margin-bottom:4px;"><label for="ud_nap_manual_txn_id" style="display:block;font-weight:600;">'
			. esc_html__( 'Ръчно въвеждане на RRN / Номер на транзакция', 'ud-nap-orders-exporter' )
			. '</label></p>';
		echo '<p style="margin-top:0;"><input type="text" id="ud_nap_manual_txn_id" name="ud_nap_manual_txn_id" value="'
			. esc_attr( $manual_txn ) . '" style="width:100%;" placeholder="' . esc_attr__( 'напр. RRN от Борика', 'ud-nap-orders-exporter' ) . '" /></p>';
		echo '<p class="description" style="font-size:11px;color:#666;margin:0 0 10px;">'
			. esc_html__( 'Попълнете, ако платежният шлюз не е записал номера автоматично. Записва се при "Update" на поръчката; кешираното PDF ще се регенерира автоматично.', 'ud-nap-orders-exporter' )
			. '</p>';

		echo '<p><a class="button button-primary" style="padding:4px 14px;" target="_blank" href="' . esc_url( $url ) . '">' . esc_html__( 'Отвори разписка (PDF)', 'ud-nap-orders-exporter' ) . '</a></p>';
	}

	/**
	 * Persist a manually-entered transaction reference (e.g. RRN looked up
	 * from the Borica merchant portal when the gateway didn't capture it).
	 * Stored as `_ud_nap_manual_txn_id`; consumed first by the receipt
	 * resolver. On change we delete the cached PDF for this order so the
	 * next render reflects the new value.
	 *
	 * @param int $order_id
	 */
	public function save_manual_txn_id( $order_id ) {
		if ( ! isset( $_POST['_ud_nap_manual_txn_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ud_nap_manual_txn_nonce'] ) ), 'ud_nap_save_manual_txn_' . $order_id ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$new = isset( $_POST['ud_nap_manual_txn_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ud_nap_manual_txn_id'] ) ) : '';
		$old = (string) $order->get_meta( '_ud_nap_manual_txn_id' );
		if ( $new === $old ) {
			return;
		}

		$order->update_meta_data( '_ud_nap_manual_txn_id', $new );
		$order->save();

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'ud-nap-exports/receipts';
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/nap-receipt-' . (int) $order_id . '-*.pdf' ) as $file ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * HPOS orders list row actions.
	 *
	 * @param array    $actions
	 * @param WC_Order $order
	 */
	public function add_orders_list_action( $actions, $order ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}
		$actions['ud_nap_receipt'] = array(
			'url'    => $this->receipt_url( $order->get_id() ),
			'name'   => __( 'Е-разписка', 'ud-nap-orders-exporter' ),
			'action' => 'ud-nap-receipt',
		);
		return $actions;
	}

	/**
	 * Classic (non-HPOS) orders list row action.
	 */
	public function add_legacy_row_action( $actions, $post ) {
		if ( 'shop_order' !== ( $post->post_type ?? '' ) ) {
			return $actions;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}
		$url = $this->receipt_url( $post->ID );
		$actions['ud_nap_receipt'] = '<a target="_blank" href="' . esc_url( $url ) . '">' . esc_html__( 'Е-разписка (PDF)', 'ud-nap-orders-exporter' ) . '</a>';
		return $actions;
	}

	private function receipt_url( $order_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'ud_nap_receipt',
					'order_id' => (int) $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ud_nap_receipt_' . (int) $order_id
		);
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
