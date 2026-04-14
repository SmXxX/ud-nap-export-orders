<?php
/**
 * НАП audit XML writer. Produces the format defined by Ordinance N-18 for
 * e-commerce sites using the alternative reporting method — matches the
 * reference sample shipped by the client:
 *
 *   <audit>
 *     <eik/><e_shop_n/><domain_name/><e_shop_type/>
 *     <creation_date/><mon/><god/>
 *     <order>
 *       <orderenum>
 *         <ord_n/><ord_d/><doc_n/><doc_date/>
 *         <art><artenum>...</artenum>...</art>
 *         <ord_total1/><ord_disc/><ord_vat/><ord_total2/>
 *         <paym/><trans_n/><proc_id/>
 *       </orderenum>
 *       ...
 *     </order>
 *     <r_ord/><r_total/>
 *   </audit>
 *
 * Prices in the shop are stored VAT-inclusive, so for each line we derive the
 * net price and VAT amount by dividing by (1 + rate).
 *
 * The file is written in three stages (header → per-order fragments → footer)
 * so the exporter can stream thousands of orders to disk without loading them
 * into memory at once.
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UD_NAP_Exporter_XML_Writer {

	/** @var UD_NAP_Exporter_Settings */
	private $settings;

	/** @var UD_NAP_Exporter_Doc_Counter */
	private $doc_counter;

	/** Running totals for the <r_ord>/<r_total> footer. */
	private $refund_count = 0;
	private $refund_total = 0.0;

	public function __construct( UD_NAP_Exporter_Settings $settings, UD_NAP_Exporter_Doc_Counter $doc_counter ) {
		$this->settings    = $settings;
		$this->doc_counter = $doc_counter;
	}

	/**
	 * Opens <audit>, writes the shop header, opens <order>.
	 *
	 * @param string $date_from Y-m-d
	 * @param string $date_to   Y-m-d
	 * @return string
	 */
	public function write_header( $date_from, $date_to ) {
		$s = $this->settings->get_all();

		// NAP expects mon/god = the reporting month/year. We infer it from
		// date_from; if the range straddles months we still use the start.
		$mon = date( 'm', strtotime( $date_from ) );
		$god = date( 'Y', strtotime( $date_from ) );

		$domain = $s['domain_name'];
		if ( '' === $domain ) {
			$domain = home_url( '/' );
		}

		$this->refund_count = 0;
		$this->refund_total = 0.0;

		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<audit>' . "\n";
		$out .= '  <eik>' . $this->esc( $s['company_eik'] ) . '</eik>' . "\n";
		$out .= '  <e_shop_n>' . $this->esc( $s['shop_unique_id'] ) . '</e_shop_n>' . "\n";
		$out .= '  <domain_name>' . $this->esc( $domain ) . '</domain_name>' . "\n";
		$out .= '  <e_shop_type>' . $this->esc( $s['e_shop_type'] ) . '</e_shop_type>' . "\n";
		$out .= '  <creation_date>' . gmdate( 'Y-m-d' ) . '</creation_date>' . "\n";
		$out .= '  <mon>' . $this->esc( $mon ) . '</mon>' . "\n";
		$out .= '  <god>' . $this->esc( $god ) . '</god>' . "\n";
		$out .= '  <order>' . "\n";

		return $out;
	}

	/**
	 * Emit a single <orderenum> block for a normal sale order.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public function write_invoice( WC_Order $order ) {
		return $this->write_orderenum( $order, false );
	}

	/**
	 * Emit a single <orderenum> block for a refund and add to the refund
	 * footer totals.
	 *
	 * @param WC_Order_Refund $refund
	 * @return string
	 */
	public function write_refund( $refund ) {
		$this->refund_count++;
		$this->refund_total += abs( (float) $refund->get_amount() );

		// Sample XML's refund section is just the aggregate <r_ord>/<r_total>
		// in the footer; individual refunds don't get their own orderenum.
		// We return empty string so the exporter skips writing per-item refund
		// blocks. The footer uses the accumulators above.
		return '';
	}

	/**
	 * @param WC_Abstract_Order $order
	 * @param bool              $is_refund unused — see write_refund()
	 * @return string
	 */
	private function write_orderenum( $order, $is_refund ) {
		$s = $this->settings->get_all();

		$doc_n    = $this->doc_counter->ensure_for_order( $order );
		$doc_date = $this->doc_counter->get_doc_date( $order );

		$created  = $order->get_date_created();
		$ord_d    = $created ? $created->date( 'Y-m-d' ) : $doc_date;

		$paym = $this->resolve_paym_code( $order );

		$txn_meta_key = $s['meta_transaction_id'] ? $s['meta_transaction_id'] : '_transaction_id';
		$trans_n      = (string) $order->get_meta( $txn_meta_key );
		if ( '' === $trans_n && method_exists( $order, 'get_transaction_id' ) ) {
			$trans_n = (string) $order->get_transaction_id();
		}

		$lines = $this->build_lines( $order );

		$ord_total1 = 0.0; // sum of net amounts.
		$ord_vat    = 0.0; // sum of vat amounts.
		$ord_total2 = 0.0; // sum of gross (= net + vat) on the lines.
		foreach ( $lines as $line ) {
			$ord_total1 += $line['net'];
			$ord_vat    += $line['vat'];
			$ord_total2 += $line['gross'];
		}

		// Coupon discount reported separately (in sample XML <ord_disc> is the
		// coupon total — line amounts are already net-of-discount because we
		// take them from WC line totals).
		$ord_disc = (float) $order->get_discount_total();

		$out  = '    <orderenum>' . "\n";
		$out .= '      <ord_n>' . $this->esc( $order->get_order_number() ) . '</ord_n>' . "\n";
		$out .= '      <ord_d>' . $this->esc( $ord_d ) . '</ord_d>' . "\n";
		$out .= '      <doc_n>' . $this->esc( $doc_n ) . '</doc_n>' . "\n";
		$out .= '      <doc_date>' . $this->esc( $doc_date ) . '</doc_date>' . "\n";
		$out .= '      <art>' . "\n";
		foreach ( $lines as $line ) {
			$out .= '        <artenum>' . "\n";
			$out .= '          <art_name>' . $this->esc( $line['name'] ) . '</art_name>' . "\n";
			$out .= '          <art_quant>' . $this->fmt( $line['qty'] ) . '</art_quant>' . "\n";
			$out .= '          <art_price>' . $this->fmt( $line['net'] ) . '</art_price>' . "\n";
			$out .= '          <art_vat_rate>' . $this->fmt( $line['vat_rate'], 0 ) . '</art_vat_rate>' . "\n";
			$out .= '          <art_vat>' . $this->fmt( $line['vat'] ) . '</art_vat>' . "\n";
			$out .= '          <art_sum>' . $this->fmt( $line['gross'] ) . '</art_sum>' . "\n";
			$out .= '        </artenum>' . "\n";
		}
		$out .= '      </art>' . "\n";
		$out .= '      <ord_total1>' . $this->fmt( $ord_total1 ) . '</ord_total1>' . "\n";
		$out .= '      <ord_disc>' . $this->fmt( $ord_disc ) . '</ord_disc>' . "\n";
		$out .= '      <ord_vat>' . $this->fmt( $ord_vat ) . '</ord_vat>' . "\n";
		$out .= '      <ord_total2>' . $this->fmt( $ord_total2 ) . '</ord_total2>' . "\n";
		$out .= '      <paym>' . $this->esc( $paym ) . '</paym>' . "\n";
		$out .= '      <trans_n>' . $this->esc( $trans_n ) . '</trans_n>' . "\n";
		$out .= '      <proc_id></proc_id>' . "\n";
		$out .= '    </orderenum>' . "\n";

		return $out;
	}

	/**
	 * Close <order> + write the refund aggregate + close <audit>.
	 */
	public function write_footer() {
		$out  = '  </order>' . "\n";
		$out .= '  <r_ord>' . (int) $this->refund_count . '</r_ord>' . "\n";
		$out .= '  <r_total>' . $this->fmt( $this->refund_total ) . '</r_total>' . "\n";
		$out .= '</audit>' . "\n";
		return $out;
	}

	/**
	 * Build the per-line art/artenum data from an order, matching the sample:
	 * products + shipping ("Доставка") + any fee items ("Такса наложен платеж").
	 * Prices are VAT-inclusive at the shop level, so we derive net + vat by
	 * dividing by (1 + rate).
	 *
	 * @param WC_Abstract_Order $order
	 * @return array<int,array{name:string,qty:float,net:float,vat:float,vat_rate:float,gross:float}>
	 */
	public function build_lines( $order ) {
		$out = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var WC_Order_Item_Product $item */
			$gross = (float) $item->get_total() + (float) $item->get_total_tax();
			$vat   = (float) $item->get_total_tax();
			$net   = $gross - $vat;
			$qty   = (float) $item->get_quantity();
			$rate  = $this->infer_rate( $net, $vat );

			$out[] = array(
				'name'     => $item->get_name(),
				'qty'      => $qty ?: 1,
				'net'      => $net,
				'vat'      => $vat,
				'vat_rate' => $rate,
				'gross'    => $gross,
			);
		}

		// Shipping line.
		foreach ( $order->get_items( 'shipping' ) as $ship ) {
			$gross = (float) $ship->get_total() + (float) $ship->get_total_tax();
			$vat   = (float) $ship->get_total_tax();
			$net   = $gross - $vat;
			if ( $gross <= 0 ) {
				continue;
			}
			$out[] = array(
				'name'     => $ship->get_name() ?: 'Доставка',
				'qty'      => 1,
				'net'      => $net,
				'vat'      => $vat,
				'vat_rate' => $this->infer_rate( $net, $vat ),
				'gross'    => $gross,
			);
		}

		// Fee items (COD fee etc.).
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			/** @var WC_Order_Item_Fee $fee */
			$gross = (float) $fee->get_total() + (float) $fee->get_total_tax();
			$vat   = (float) $fee->get_total_tax();
			$net   = $gross - $vat;
			if ( 0.0 === $gross ) {
				continue;
			}
			$out[] = array(
				'name'     => $fee->get_name(),
				'qty'      => 1,
				'net'      => $net,
				'vat'      => $vat,
				'vat_rate' => $this->infer_rate( $net, $vat ),
				'gross'    => $gross,
			);
		}

		return $out;
	}

	/**
	 * Map the order's WooCommerce payment method to the NAP paym code using
	 * the admin-configured map. Falls back to "8 — Други".
	 *
	 * @param WC_Abstract_Order $order
	 * @return string
	 */
	public function resolve_paym_code( $order ) {
		$map    = (array) $this->settings->get( 'payment_map', array() );
		$method = (string) $order->get_payment_method();
		if ( '' !== $method && isset( $map[ $method ] ) ) {
			return (string) $map[ $method ];
		}
		return '8';
	}

	private function infer_rate( $net, $vat ) {
		if ( $net <= 0 ) {
			return 0.0;
		}
		return round( ( $vat / $net ) * 100 );
	}

	private function fmt( $value, $decimals = 2 ) {
		$n = round( (float) $value, $decimals );
		// Strip trailing zeros so "37.00" → "37" and "30.80" → "30.8" —
		// matches the reference XML which uses minimal decimals.
		$s = number_format( $n, $decimals, '.', '' );
		if ( $decimals > 0 && false !== strpos( $s, '.' ) ) {
			$s = rtrim( $s, '0' );
			$s = rtrim( $s, '.' );
		}
		return '' === $s ? '0' : $s;
	}

	private function esc( $value ) {
		return htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
