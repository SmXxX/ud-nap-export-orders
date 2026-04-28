<?php
/**
 * Receipt PDF generator — "Електронна разписка за поръчка № XXXX".
 *
 * Renders a single-order fiscal receipt matching the client's reference PDF:
 * header with doc_n + doc_date, QR (payload = YYYYMMDD of doc date), company
 * block, line items (EUR + BGN), totals, payment method + transaction id.
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

class UD_NAP_Exporter_Receipt {

	/** Fixed BGN/EUR conversion rate (Bulgarian lev pegged to the euro). */
	const EUR_TO_BGN = 1.95583;

	/** @var UD_NAP_Exporter_Settings */
	private $settings;

	/** @var UD_NAP_Exporter_Doc_Counter */
	private $doc_counter;

	/** @var UD_NAP_Exporter_XML_Writer */
	private $xml_writer;

	public function __construct( UD_NAP_Exporter_Settings $settings ) {
		$this->settings    = $settings;
		$this->doc_counter = new UD_NAP_Exporter_Doc_Counter( $settings );
		$this->xml_writer  = new UD_NAP_Exporter_XML_Writer( $settings, $this->doc_counter );
	}

	/**
	 * Stream the receipt PDF inline to the browser and exit.
	 *
	 * @param WC_Order $order
	 */
	public function stream( WC_Order $order ) {
		$filename = sprintf( 'nap-receipt-%s.pdf', $order->get_order_number() );
		$pdf      = $this->render( $order );

		// Discard anything WordPress / other plugins may have written to output
		// buffers before us — a single stray byte breaks PDF rendering.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Render the receipt to a file under uploads/ud-nap-exports and return
	 * the absolute path. Used to attach the PDF to customer order emails.
	 *
	 * @param WC_Order $order
	 * @return string|null Absolute path on success, null on failure.
	 */
	public function render_to_file( WC_Order $order ) {
		try {
			$uploads = wp_upload_dir();
			$dir     = trailingslashit( $uploads['basedir'] ) . 'ud-nap-exports/receipts';
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$doc_n = $this->doc_counter->ensure_for_order( $order );
			$path  = $dir . '/nap-receipt-' . $order->get_id() . '-' . $doc_n . '.pdf';

			// Reuse if already generated — receipts are immutable.
			if ( file_exists( $path ) && filesize( $path ) > 0 ) {
				return $path;
			}

			$pdf = $this->render( $order );
			file_put_contents( $path, $pdf );
			return $path;
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[UD NAP] Receipt render failed: ' . $e->getMessage() );
			}
			return null;
		}
	}

	/**
	 * Build the PDF binary.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public function render( WC_Order $order ) {
		$options = new Options();
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'isRemoteEnabled', true );
		$options->set( 'isHtml5ParserEnabled', true );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $this->build_html( $order ), 'UTF-8' );
		// ~80mm thermal-receipt-style page. Height is generous; Dompdf will
		// fit content and trim unused space with the CSS.
		$dompdf->setPaper( array( 0, 0, 280, 800 ), 'portrait' );
		$dompdf->render();

		return $dompdf->output();
	}

	private function build_html( WC_Order $order ) {
		$s          = $this->settings->get_all();
		$doc_n      = $this->doc_counter->ensure_for_order( $order );
		$doc_date   = $this->doc_counter->get_doc_date( $order );
		$lines      = $this->xml_writer->build_lines( $order );
		$paym_code  = $this->xml_writer->resolve_paym_code( $order );
		$paym_title = $order->get_payment_method_title();

		$txn_meta = $s['meta_transaction_id'] ? $s['meta_transaction_id'] : '_transaction_id';
		$trans_n  = $this->resolve_transaction_id( $order, $txn_meta );

		// QR payload: per the client's reference receipt, this is just the
		// document date formatted as YYYYMMDD.
		$qr_payload = str_replace( '-', '', $doc_date );
		$qr_data    = $this->build_qr_data_uri( $qr_payload );

		$ord_net = 0.0;
		$ord_vat = 0.0;
		foreach ( $lines as $l ) {
			$ord_net += $l['net'];
			$ord_vat += $l['vat'];
		}
		$ord_gross = $ord_net + $ord_vat;

		ob_start();
		?>
		<!DOCTYPE html>
		<html lang="bg">
		<head>
			<meta charset="UTF-8" />
			<style>
				@page { margin: 8mm 6mm; }
				* { font-family: "DejaVu Sans", sans-serif; }
				body { font-size: 9pt; color: #111; margin: 0; }
				.center { text-align: center; }
				.right  { text-align: right; }
				h1 { font-size: 13pt; margin: 0 0 2px; text-align: center; }
				h2 { font-size: 11pt; margin: 0 0 10px; text-align: center; font-weight: bold; }
				.order-no { text-align: center; margin-bottom: 8px; }
				.qr { text-align: center; margin: 6px 0 10px; }
				.qr img { width: 110px; height: 110px; }
				.company { text-align: center; margin-bottom: 8px; line-height: 1.35; }
				.company .name { font-weight: bold; }
				.cur-head { text-align: right; font-size: 8pt; margin: 4px 0 2px; }
				table.items { width: 100%; border-collapse: collapse; margin-bottom: 4px; table-layout: fixed; }
				table.items td { vertical-align: top; padding: 2px 0; font-size: 9pt; word-wrap: break-word; }
				table.items td.name { width: 60%; padding-right: 6px; }
				table.items td.amt { text-align: right; white-space: nowrap; width: 40%; }
				.qty-row td { padding-top: 4px; font-size: 8.5pt; color: #333; }
				.sep { border-top: 1px dashed #333; margin: 6px 0; }
				table.totals { width: 100%; border-collapse: collapse; }
				table.totals td { padding: 2px 0; }
				table.totals td.label { text-align: left; }
				table.totals td.amt { text-align: right; white-space: nowrap; }
				table.totals tr.grand td { font-size: 12pt; font-weight: bold; padding-top: 6px; }
				.payment { text-align: center; margin-top: 10px; font-weight: bold; }
				.footer { text-align: center; margin-top: 18px; font-size: 8pt; }
				.footer a { color: #000; text-decoration: underline; }
			</style>
		</head>
		<body>
			<h1>Документ за регистриране на продажба</h1>
			<h2>№ <?php echo esc_html( $doc_n ); ?> от дата: <?php echo esc_html( $doc_date ); ?></h2>

			<div class="order-no">Поръчка номер: <?php echo esc_html( $order->get_order_number() ); ?></div>

			<?php if ( $qr_data ) : ?>
				<div class="qr"><img src="<?php echo esc_attr( $qr_data ); ?>" alt="QR" /></div>
			<?php endif; ?>

			<div class="company">
				<div class="name"><?php echo esc_html( $s['company_name'] ); ?></div>
				<?php if ( $s['company_eik'] ) : ?>
					<div>ЕИК: <?php echo esc_html( $s['company_eik'] ); ?></div>
				<?php endif; ?>
				<?php if ( $s['company_vat'] ) : ?>
					<div>ИН по ЗДДС: <?php echo esc_html( $s['company_vat'] ); ?></div>
				<?php endif; ?>
				<?php if ( ! empty( $s['company_email'] ) ) : ?>
					<div>Имейл: <?php echo esc_html( $s['company_email'] ); ?></div>
				<?php endif; ?>
				<?php if ( $s['company_address'] || $s['company_city'] ) : ?>
					<div>Адрес: <?php echo esc_html( trim( $s['company_city'] . ( $s['company_city'] && $s['company_address'] ? ', ' : '' ) . $s['company_address'] ) ); ?></div>
				<?php endif; ?>
			</div>

			<div class="cur-head">EUR / BGN</div>

			<table class="items">
				<?php foreach ( $lines as $l ) :
					$unit_bgn  = $l['gross'] * self::EUR_TO_BGN;
					?>
					<tr class="qty-row">
						<td colspan="2"><?php echo esc_html( $this->fmt3( $l['qty'] ) ); ?> x €<?php echo esc_html( $this->fmt_price( $l['gross'] ) ); ?> / <?php echo esc_html( $this->fmt_price( $unit_bgn ) ); ?>лв</td>
					</tr>
					<tr>
						<td class="name"><?php echo esc_html( $l['name'] ); ?></td>
						<td class="amt">€<?php echo esc_html( $this->fmt_price( $l['gross'] ) ); ?> / <?php echo esc_html( $this->fmt_price( $unit_bgn ) ); ?>лв B</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<div class="sep"></div>

			<table class="totals">
				<tr>
					<td class="label">Междинна сума:</td>
					<td class="amt">€<?php echo esc_html( $this->fmt_price( $ord_net ) ); ?> / <?php echo esc_html( $this->fmt_price( $ord_net * self::EUR_TO_BGN ) ); ?>лв</td>
				</tr>
				<tr>
					<td class="label">ДДС:</td>
					<td class="amt">€<?php echo esc_html( $this->fmt_price( $ord_vat ) ); ?> / <?php echo esc_html( $this->fmt_price( $ord_vat * self::EUR_TO_BGN ) ); ?>лв</td>
				</tr>
				<tr class="grand">
					<td class="label">Общо:</td>
					<td class="amt">€<?php echo esc_html( $this->fmt_price( $ord_gross ) ); ?> / <?php echo esc_html( $this->fmt_price( $ord_gross * self::EUR_TO_BGN ) ); ?>лв</td>
				</tr>
			</table>

			<div class="payment">
				Доставчик на платежни услуги (<?php echo esc_html( $paym_title ); ?>)<br />
				Номер на транзакция: <?php echo esc_html( $trans_n ); ?>
			</div>

			<div class="footer">
				Този документ е генериран с:<br />
				<a href="https://unbelievable.digital/"><strong>Unbelievable Digital</strong></a>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	private function fmt_price( $v ) {
		$v = round( (float) $v, 2 );
		$s = number_format( $v, 2, '.', '' );
		// Match the reference PDF: trim trailing zeros so "15.00" → "15".
		if ( false !== strpos( $s, '.' ) ) {
			$s = rtrim( rtrim( $s, '0' ), '.' );
		}
		return '' === $s ? '0' : $s;
	}

	private function fmt3( $v ) {
		return number_format( (float) $v, 3, '.', '' );
	}

	/**
	 * Resolve a meaningful transaction id for the receipt. Tries the
	 * configured meta key first, then known gateway-specific keys (currently
	 * Borica EMV 3DS — webops_borica_emv_3ds writes via update_post_meta()
	 * which is invisible to $order->get_meta() under HPOS without compatibility
	 * mode, hence the get_post_meta() fallback). Values that look like
	 * 1–2-digit response codes (e.g. Borica "00" = approved) are skipped —
	 * they are status codes, not transaction references.
	 *
	 * @return string
	 */
	private function resolve_transaction_id( WC_Order $order, $configured_meta_key ) {
		$candidates = array( '_ud_nap_manual_txn_id' );
		if ( $configured_meta_key ) {
			$candidates[] = $configured_meta_key;
		}
		$candidates[] = '_wo_borica_emv_3ds_RRN';
		$candidates[] = '_wo_borica_emv_3ds_INT_REF';

		foreach ( array_unique( $candidates ) as $key ) {
			$value = (string) $order->get_meta( $key );
			if ( '' === $value ) {
				$value = (string) get_post_meta( $order->get_id(), $key, true );
			}
			$value = trim( $value );
			if ( '' !== $value && ! $this->looks_like_response_code( $value ) ) {
				return $value;
			}
		}

		if ( method_exists( $order, 'get_transaction_id' ) ) {
			$value = trim( (string) $order->get_transaction_id() );
			if ( '' !== $value && ! $this->looks_like_response_code( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	private function looks_like_response_code( $value ) {
		return (bool) preg_match( '/^\d{1,2}$/', (string) $value );
	}

	private function build_qr_data_uri( $payload ) {
		try {
			$result = Builder::create()
				->writer( new PngWriter() )
				->data( (string) $payload )
				->size( 220 )
				->margin( 4 )
				->build();
			return $result->getDataUri();
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
