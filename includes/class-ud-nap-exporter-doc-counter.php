<?php
/**
 * Allocates and persists the sequential fiscal document number (doc_n) that
 * appears on every НАП receipt / audit entry. Once an order is assigned a
 * number it is stored in order meta and never changes — required for fiscal
 * integrity.
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UD_NAP_Exporter_Doc_Counter {

	const ORDER_META_DOC_N      = '_ud_nap_doc_n';
	const ORDER_META_DOC_DATE   = '_ud_nap_doc_date';
	const OPTION_COUNTER        = 'ud_nap_exporter_doc_counter';
	const LOCK_TRANSIENT        = 'ud_nap_exporter_doc_lock';

	/** @var UD_NAP_Exporter_Settings */
	private $settings;

	public function __construct( UD_NAP_Exporter_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Return the already-assigned doc number for an order, or null if none.
	 *
	 * @param WC_Abstract_Order $order
	 * @return string|null
	 */
	public function get_for_order( $order ) {
		$existing = $order->get_meta( self::ORDER_META_DOC_N );
		return $existing ? (string) $existing : null;
	}

	/**
	 * Return the stored doc_n for an order, allocating (and persisting) a new
	 * one on first call.
	 *
	 * @param WC_Abstract_Order $order
	 * @return string doc number, padded to 10 digits.
	 */
	public function ensure_for_order( $order ) {
		$existing = $this->get_for_order( $order );
		if ( null !== $existing ) {
			return $existing;
		}

		$next = $this->allocate_next();

		$order->update_meta_data( self::ORDER_META_DOC_N, $next );
		// Persist the document date alongside the number so reprints always
		// show the original generation date.
		$order->update_meta_data( self::ORDER_META_DOC_DATE, gmdate( 'Y-m-d' ) );
		$order->save_meta_data();

		return $next;
	}

	/**
	 * Return the stored doc date, falling back to today if the order has a
	 * doc_n but no stored date (shouldn't happen after ensure_for_order, but
	 * keeps things robust for older data).
	 *
	 * @param WC_Abstract_Order $order
	 * @return string Y-m-d
	 */
	public function get_doc_date( $order ) {
		$d = (string) $order->get_meta( self::ORDER_META_DOC_DATE );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
			return $d;
		}
		$created = $order->get_date_created();
		return $created ? $created->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/**
	 * Atomically increment the counter. The counter option holds the
	 * last-issued number; the first allocation returns max(option+1, start).
	 *
	 * @return string
	 */
	private function allocate_next() {
		// Very simple lock to avoid double-allocation under concurrent AJAX.
		$waited = 0;
		while ( get_transient( self::LOCK_TRANSIENT ) && $waited < 2000000 ) {
			usleep( 50000 );
			$waited += 50000;
		}
		set_transient( self::LOCK_TRANSIENT, 1, 10 );

		$last  = (int) get_option( self::OPTION_COUNTER, 0 );
		$start = (int) $this->settings->get( 'doc_n_start', 2000000001 );
		$next  = $last + 1;
		if ( $next < $start ) {
			$next = $start;
		}
		update_option( self::OPTION_COUNTER, $next, false );

		delete_transient( self::LOCK_TRANSIENT );
		return (string) $next;
	}
}
