<?php
/**
 * Order query helper. Returns paginated lists of WooCommerce order IDs that
 * fall inside a given date range and status set.
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UD_NAP_Exporter_Query {

	/**
	 * Count matching orders.
	 *
	 * @param string   $date_from  Y-m-d.
	 * @param string   $date_to    Y-m-d.
	 * @param string[] $statuses   Statuses without the wc- prefix.
	 * @param bool     $with_refunds Include shop_order_refund objects.
	 * @return int
	 */
	public function count( $date_from, $date_to, array $statuses, $with_refunds ) {
		$ids = $this->get_ids( $date_from, $date_to, $statuses, $with_refunds, -1, 0 );
		return count( $ids );
	}

	/**
	 * @param string   $date_from
	 * @param string   $date_to
	 * @param string[] $statuses
	 * @param bool     $with_refunds
	 * @param int      $limit  -1 for all.
	 * @param int      $offset
	 * @param string[] $payment_methods Optional gateway IDs to filter by.
	 *                 An empty array means "all payment methods".
	 * @return int[]
	 */
	public function get_ids( $date_from, $date_to, array $statuses, $with_refunds, $limit, $offset, array $payment_methods = array() ) {
		$args = array(
			'type'         => $with_refunds ? array( 'shop_order', 'shop_order_refund' ) : array( 'shop_order' ),
			'status'       => $this->prefix_statuses( $statuses ),
			'date_created' => $this->date_range( $date_from, $date_to ),
			'limit'        => $limit,
			'offset'       => $offset,
			'orderby'      => 'date',
			'order'        => 'ASC',
			'return'       => 'ids',
		);

		// wc_get_orders applies different status semantics for refunds; refunds
		// inherit their parent status, so we don't filter them by status here.
		if ( $with_refunds ) {
			unset( $args['status'] );
			$args['status'] = 'any';
		}

		if ( ! empty( $payment_methods ) ) {
			$args['payment_method'] = array_values( $payment_methods );
		}

		$ids = wc_get_orders( $args );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	private function prefix_statuses( array $statuses ) {
		$out = array();
		foreach ( $statuses as $s ) {
			$s     = (string) $s;
			$out[] = ( 0 === strpos( $s, 'wc-' ) ) ? substr( $s, 3 ) : $s;
		}
		return $out ? $out : array( 'processing', 'completed' );
	}

	private function date_range( $from, $to ) {
		$from = $from ? $from : '1970-01-01';
		$to   = $to   ? $to   : gmdate( 'Y-m-d' );
		return $from . '...' . $to;
	}
}
