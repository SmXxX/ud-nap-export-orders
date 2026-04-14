<?php
/**
 * CSV (Excel-compatible) export orchestrator. Mirrors the XML exporter's
 * lifecycle: start_job -> step (batched) -> finalize -> stream_download.
 *
 * The output is a UTF-8 CSV file with a BOM so Excel renders Bulgarian /
 * Cyrillic characters correctly when the file is opened directly.
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UD_NAP_Exporter_CSV_Exporter {

	const JOB_TRANSIENT = 'ud_nap_exporter_csv_job_';

	/** @var UD_NAP_Exporter_Settings */
	private $settings;

	/** @var UD_NAP_Exporter_Query */
	private $query;

	public function __construct( UD_NAP_Exporter_Settings $settings ) {
		$this->settings = $settings;
		$this->query    = new UD_NAP_Exporter_Query();
	}

	/**
	 * @param string   $date_from
	 * @param string   $date_to
	 * @param bool     $with_refunds
	 * @param string[] $payment_methods Optional gateway IDs to include.
	 * @param string   $report_type     'all' (default), 'card', or 'cod'. When
	 *                                  set to 'card' or 'cod' the payment
	 *                                  filter is replaced with the configured
	 *                                  COD gateway list (or its complement),
	 *                                  so the resulting file contains only
	 *                                  the chosen payment category.
	 * @return array
	 */
	public function start_job( $date_from, $date_to, $with_refunds, array $payment_methods = array(), $report_type = 'all' ) {
		// Per client requirement: the CSV report must only contain orders that
		// are actually finalized — Completed only, never Processing — so we
		// force the status filter here regardless of the global setting.
		$statuses    = array( 'completed' );
		$cod_methods = array_map( 'strtolower', (array) $this->settings->get( 'csv_cod_methods', array( 'cod' ) ) );

		$report_type = in_array( $report_type, array( 'all', 'card', 'cod' ), true ) ? $report_type : 'all';

		// When the user explicitly asks for a single category, the report-type
		// filter wins over the per-method checkbox list.
		if ( 'cod' === $report_type ) {
			$payment_methods = $cod_methods;
		} elseif ( 'card' === $report_type ) {
			$all_known       = array_map( 'strtolower', $this->settings->get_known_payment_gateway_ids() );
			$payment_methods = array_values( array_diff( $all_known, $cod_methods ) );
			// Empty list means "no gateways match" — make sure we do not fall
			// back to "all gateways" by passing a sentinel that will yield 0.
			if ( empty( $payment_methods ) ) {
				$payment_methods = array( '__ud_nap_no_gateway__' );
			}
		}

		$ids = $this->query->get_ids( $date_from, $date_to, $statuses, $with_refunds, -1, 0, $payment_methods );

		$columns    = $this->resolve_columns();
		$extra_meta = $this->settings->get_extra_meta_keys();

		$job_id = wp_generate_password( 12, false, false );
		$file   = $this->job_file_path( $job_id );

		// UTF-8 BOM so Excel/Numbers handle Cyrillic correctly.
		$fp = fopen( $file, 'w' );
		fwrite( $fp, "\xEF\xBB\xBF" );
		$this->fputcsv_bg( $fp, $this->build_header_row( $columns, $extra_meta ) );
		fclose( $fp );

		$job = array(
			'id'           => $job_id,
			'date_from'    => $date_from,
			'date_to'      => $date_to,
			'with_refunds' => (bool) $with_refunds,
			'ids'          => array_values( $ids ),
			'total'        => count( $ids ),
			'processed'    => 0,
			'file'         => $file,
			'columns'      => $columns,
			'extra_meta'   => $extra_meta,
			'cod_methods'  => $cod_methods,
			'report_type'  => $report_type,
			// Running totals accumulated across batches, grouped by payment
			// category ('card' vs 'cod') so the summary block at the bottom
			// of the file can list each total separately.
			'totals'       => array(
				'card' => array( 'count' => 0, 'subtotal' => 0.0, 'shipping' => 0.0, 'total' => 0.0 ),
				'cod'  => array( 'count' => 0, 'subtotal' => 0.0, 'shipping' => 0.0, 'total' => 0.0 ),
			),
			'created_at'   => time(),
			'finished'     => false,
		);

		$this->save_job( $job );
		return $job;
	}

	public function step( $job_id ) {
		$job = $this->load_job( $job_id );
		if ( ! $job ) {
			return array( 'error' => __( 'Експорт задачата не е намерена или е изтекла.', 'ud-nap-orders-exporter' ) );
		}
		if ( $job['finished'] ) {
			return $job;
		}

		$batch_size = (int) $this->settings->get( 'batch_size', 50 );
		$slice      = array_slice( $job['ids'], $job['processed'], $batch_size );

		if ( empty( $slice ) ) {
			return $this->finalize( $job );
		}

		$fp = fopen( $job['file'], 'a' );
		foreach ( $slice as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$row = $this->build_row( $order, $job['columns'], $job['extra_meta'] );
			$this->fputcsv_bg( $fp, $row );
			$this->accumulate_totals( $job['totals'], $order, $job['cod_methods'] );
		}
		fclose( $fp );

		$job['processed'] += count( $slice );

		if ( $job['processed'] >= $job['total'] ) {
			return $this->finalize( $job );
		}

		$this->save_job( $job );
		return $job;
	}

	private function finalize( array $job ) {
		$this->write_summary( $job );

		// Convert the staging CSV file we have been streaming rows into to a
		// real .xlsx file so the user can open it directly in Excel / Numbers
		// without any "this is text, do you want to import?" dialog. The CSV
		// file is deleted after a successful conversion.
		$xlsx_path = $this->convert_to_xlsx( $job );
		if ( $xlsx_path ) {
			@unlink( $job['file'] );
			$job['file'] = $xlsx_path;
		}

		$job['finished']     = true;
		$job['download_url'] = $this->build_download_url( $job['id'] );
		$this->save_job( $job );
		return $job;
	}

	/**
	 * Read the staging CSV row by row and emit a single-sheet .xlsx via the
	 * bundled minimal XLSX writer.
	 *
	 * @param array $job
	 * @return string|null Absolute path of the produced .xlsx file, or null
	 *                     on failure (caller falls back to the CSV).
	 */
	private function convert_to_xlsx( array $job ) {
		if ( ! class_exists( 'UD_NAP_Exporter_XLSX_Writer' ) || ! class_exists( 'ZipArchive' ) ) {
			return null;
		}

		$csv_path  = $job['file'];
		$xlsx_path = preg_replace( '/\.csv$/', '.xlsx', $csv_path );
		if ( $xlsx_path === $csv_path ) {
			$xlsx_path .= '.xlsx';
		}

		$fp = fopen( $csv_path, 'r' );
		if ( ! $fp ) {
			return null;
		}

		// Skip the UTF-8 BOM if present so it does not end up as part of the
		// first cell value.
		$bom = fread( $fp, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $fp );
		}

		$rows = array();
		while ( ( $row = fgetcsv( $fp, 0, ';', '"', '\\' ) ) !== false ) {
			// fgetcsv returns array( null ) for blank lines — keep them as
			// empty rows so the spacer between data and summary is preserved.
			if ( array( null ) === $row ) {
				$rows[] = array();
				continue;
			}
			$rows[] = $row;
		}
		fclose( $fp );

		$ok = UD_NAP_Exporter_XLSX_Writer::write( $rows, 'Поръчки', $xlsx_path );
		return $ok ? $xlsx_path : null;
	}

	/**
	 * Accumulate running totals for one order, bucketed by payment category
	 * ('cod' for "наложен платеж", 'card' for everything else). The category
	 * is decided against the configured COD gateway list. Refund objects
	 * already report negative values from WooCommerce, so summing them in
	 * naturally subtracts.
	 *
	 * @param array             $totals       passed by reference.
	 * @param WC_Abstract_Order $order
	 * @param string[]          $cod_methods  lowercased gateway IDs that mean COD.
	 */
	private function accumulate_totals( array &$totals, $order, array $cod_methods ) {
		$bucket = $this->classify_payment( $order, $cod_methods );

		// Defensive: ensure both buckets exist (older jobs / future categories).
		if ( ! isset( $totals[ $bucket ] ) ) {
			$totals[ $bucket ] = array( 'count' => 0, 'subtotal' => 0.0, 'shipping' => 0.0, 'total' => 0.0 );
		}

		$totals[ $bucket ]['count']    += 1;
		$totals[ $bucket ]['subtotal'] += method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : 0;
		$totals[ $bucket ]['shipping'] += (float) $order->get_shipping_total();
		$totals[ $bucket ]['total']    += (float) $order->get_total();
	}

	/**
	 * Decide whether an order belongs to the "cod" or "card" bucket. Refunds
	 * inherit the parent order's payment method via WooCommerce, so they fall
	 * into the same bucket as the order they reverse.
	 *
	 * @param WC_Abstract_Order $order
	 * @param string[]          $cod_methods lowercased gateway IDs.
	 * @return string 'cod' or 'card'.
	 */
	private function classify_payment( $order, array $cod_methods ) {
		$method = method_exists( $order, 'get_payment_method' ) ? strtolower( (string) $order->get_payment_method() ) : '';
		if ( '' !== $method && in_array( $method, $cod_methods, true ) ) {
			return 'cod';
		}
		return 'card';
	}

	/**
	 * Append the summary block to the bottom of the file. Reports the total
	 * subtotal, shipping and order total for orders paid by card and orders
	 * paid by cash on delivery ("наложен платеж") separately.
	 *
	 * @param array $job
	 */
	private function write_summary( array $job ) {
		if ( empty( $job['totals'] ) ) {
			return;
		}

		$labels = array(
			'card' => 'Платени с карта',
			'cod'  => 'Наложен платеж',
		);

		// When the file is restricted to a single category, only show that
		// bucket in the summary so the report stays focused.
		$report_type = isset( $job['report_type'] ) ? (string) $job['report_type'] : 'all';
		$buckets     = ( 'card' === $report_type || 'cod' === $report_type ) ? array( $report_type ) : array( 'card', 'cod' );

		$fp = fopen( $job['file'], 'a' );
		// Spacer + section title.
		$this->fputcsv_bg( $fp, array() );
		$this->fputcsv_bg( $fp, array( 'ОБОБЩЕНИЕ', $job['date_from'] . ' → ' . $job['date_to'] ) );

		// Header row for the summary table — money columns include the shop
		// currency code so the report is unambiguous on its own.
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$suffix   = '' !== $currency ? ' (' . $currency . ')' : '';
		$this->fputcsv_bg(
			$fp,
			array(
				'Метод на плащане',
				'Брой поръчки',
				'Междинна сума' . $suffix,
				'Доставка' . $suffix,
				'Обща сума' . $suffix,
			)
		);

		$grand = array( 'count' => 0, 'subtotal' => 0.0, 'shipping' => 0.0, 'total' => 0.0 );
		foreach ( $buckets as $bucket ) {
			$t = isset( $job['totals'][ $bucket ] ) ? $job['totals'][ $bucket ] : array( 'count' => 0, 'subtotal' => 0.0, 'shipping' => 0.0, 'total' => 0.0 );
			$this->fputcsv_bg(
				$fp,
				array(
					$labels[ $bucket ],
					(int) $t['count'],
					$this->fmt_money( $t['subtotal'] ),
					$this->fmt_money( $t['shipping'] ),
					$this->fmt_money( $t['total'] ),
				)
			);
			$grand['count']    += (int) $t['count'];
			$grand['subtotal'] += (float) $t['subtotal'];
			$grand['shipping'] += (float) $t['shipping'];
			$grand['total']    += (float) $t['total'];
		}

		// Grand total row — only meaningful when both buckets are reported,
		// otherwise it would just duplicate the single bucket row above.
		if ( count( $buckets ) > 1 ) {
			$this->fputcsv_bg(
				$fp,
				array(
					'ОБЩО',
					$grand['count'],
					$this->fmt_money( $grand['subtotal'] ),
					$this->fmt_money( $grand['shipping'] ),
					$this->fmt_money( $grand['total'] ),
				)
			);
		}

		fclose( $fp );
	}

	private function fmt_money( $value ) {
		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * Write a CSV row using a semicolon delimiter. macOS Numbers and Excel
	 * with a Bulgarian / European locale interpret comma-delimited files as a
	 * single column unless the delimiter is `;`, so we standardize on that.
	 *
	 * @param resource $fp
	 * @param array    $row
	 */
	private function fputcsv_bg( $fp, array $row ) {
		fputcsv( $fp, $row, ';', '"', '\\' );
	}

	public function stream_download( $job_id ) {
		$job = $this->load_job( $job_id );
		if ( ! $job || empty( $job['finished'] ) || ! file_exists( $job['file'] ) ) {
			wp_die( esc_html__( 'Експорт файлът не е наличен.', 'ud-nap-orders-exporter' ) );
		}

		$type_slug = isset( $job['report_type'] ) && in_array( $job['report_type'], array( 'card', 'cod' ), true ) ? $job['report_type'] . '-' : '';

		// Pick the right MIME / extension based on what convert_to_xlsx
		// produced. If the conversion failed for any reason we still serve
		// the CSV staging file as a graceful fallback.
		$is_xlsx = ( '.xlsx' === substr( $job['file'], -5 ) );
		if ( $is_xlsx ) {
			$filename     = sprintf( 'orders-%s%s_%s.xlsx', $type_slug, $job['date_from'], $job['date_to'] );
			$content_type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
		} else {
			$filename     = sprintf( 'orders-%s%s_%s.csv', $type_slug, $job['date_from'], $job['date_to'] );
			$content_type = 'text/csv; charset=UTF-8';
		}

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $job['file'] ) );

		readfile( $job['file'] );

		@unlink( $job['file'] );
		delete_transient( self::JOB_TRANSIENT . $job_id );
		exit;
	}

	/**
	 * Resolve which standard columns the user has enabled, in the same order
	 * as the canonical definition (so the CSV layout is stable regardless of
	 * checkbox click order).
	 *
	 * @return string[] column keys.
	 */
	private function resolve_columns() {
		$selected = (array) $this->settings->get( 'csv_columns', array() );
		$standard = array_keys( UD_NAP_Exporter_Settings::standard_csv_columns() );
		if ( empty( $selected ) ) {
			return $standard;
		}
		return array_values( array_intersect( $standard, $selected ) );
	}

	private function build_header_row( array $columns, array $extra_meta ) {
		$labels    = UD_NAP_Exporter_Settings::standard_csv_columns();
		$currency  = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$money_col = array(
			'subtotal'       => true,
			'discount_total' => true,
			'shipping_total' => true,
			'shipping_tax'   => true,
			'total_tax'      => true,
			'total'          => true,
		);

		$row = array();
		foreach ( $columns as $key ) {
			$label = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			if ( '' !== $currency && isset( $money_col[ $key ] ) ) {
				$label .= ' (' . $currency . ')';
			}
			$row[] = $label;
		}
		foreach ( $extra_meta as $key ) {
			$row[] = $key;
		}
		return $row;
	}

	/**
	 * @param WC_Abstract_Order $order
	 * @param string[]          $columns
	 * @param string[]          $extra_meta
	 * @return string[]
	 */
	private function build_row( $order, array $columns, array $extra_meta ) {
		$row = array();
		foreach ( $columns as $key ) {
			$row[] = $this->get_column_value( $order, $key );
		}
		foreach ( $extra_meta as $meta_key ) {
			$value = $order->get_meta( $meta_key );
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			}
			$row[] = (string) $value;
		}
		return $row;
	}

	/**
	 * @param WC_Abstract_Order $order
	 * @param string            $key
	 * @return string
	 */
	private function get_column_value( $order, $key ) {
		switch ( $key ) {
			case 'order_id':
				return (string) $order->get_id();
			case 'order_number':
				return (string) $order->get_order_number();
			case 'order_status':
				return (string) $order->get_status();
			case 'date_created':
				return $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '';
			case 'date_paid':
				return method_exists( $order, 'get_date_paid' ) && $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d H:i:s' ) : '';
			case 'date_completed':
				return method_exists( $order, 'get_date_completed' ) && $order->get_date_completed() ? $order->get_date_completed()->date( 'Y-m-d H:i:s' ) : '';
			case 'currency':
				return (string) $order->get_currency();
			case 'subtotal':
				return method_exists( $order, 'get_subtotal' ) ? (string) $order->get_subtotal() : '';
			case 'discount_total':
				return (string) $order->get_total_discount();
			case 'shipping_total':
				return (string) $order->get_shipping_total();
			case 'shipping_tax':
				return (string) $order->get_shipping_tax();
			case 'total_tax':
				return (string) $order->get_total_tax();
			case 'total':
				return (string) $order->get_total();
			case 'payment_method':
				return method_exists( $order, 'get_payment_method' ) ? (string) $order->get_payment_method() : '';
			case 'payment_method_title':
				// Older orders may have HTML (e.g. an injected <img> for card
				// brand icons) baked into the saved payment method title.
				// Strip tags + collapse whitespace so the export shows clean
				// text only.
				$title = method_exists( $order, 'get_payment_method_title' ) ? (string) $order->get_payment_method_title() : '';
				$title = wp_strip_all_tags( $title );
				$title = trim( preg_replace( '/\s+/u', ' ', $title ) );
				return $title;
			case 'transaction_id':
				$txn_meta = (string) $this->settings->get( 'meta_transaction_id', '_transaction_id' );
				$txn      = $txn_meta ? (string) $order->get_meta( $txn_meta ) : '';
				if ( '' === $txn && method_exists( $order, 'get_transaction_id' ) ) {
					$txn = (string) $order->get_transaction_id();
				}
				return $txn;
			case 'customer_id':
				return method_exists( $order, 'get_customer_id' ) ? (string) $order->get_customer_id() : '';
			case 'customer_note':
				return method_exists( $order, 'get_customer_note' ) ? (string) $order->get_customer_note() : '';
			case 'items_count':
				return (string) $order->get_item_count();
			case 'items_summary':
				return $this->build_items_summary( $order );
		}

		// Billing / shipping fields all map to a getter named get_<key>().
		$method = 'get_' . $key;
		if ( method_exists( $order, $method ) ) {
			$value = $order->{$method}();
			return is_scalar( $value ) ? (string) $value : '';
		}

		return '';
	}

	private function build_items_summary( $order ) {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return '';
		}
		$parts = array();
		foreach ( $order->get_items() as $item ) {
			$parts[] = $item->get_name() . ' x ' . (int) $item->get_quantity();
		}
		return implode( ' | ', $parts );
	}

	private function build_download_url( $job_id ) {
		return add_query_arg(
			array(
				'action'   => 'ud_nap_csv_download',
				'job'      => $job_id,
				'_wpnonce' => wp_create_nonce( 'ud_nap_csv_download_' . $job_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	private function job_file_path( $job_id ) {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'ud-nap-exports';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir . '/orders-' . $job_id . '.csv';
	}

	public function save_job( array $job ) {
		set_transient( self::JOB_TRANSIENT . $job['id'], $job, HOUR_IN_SECONDS );
	}

	public function load_job( $job_id ) {
		$job = get_transient( self::JOB_TRANSIENT . $job_id );
		return is_array( $job ) ? $job : null;
	}
}
