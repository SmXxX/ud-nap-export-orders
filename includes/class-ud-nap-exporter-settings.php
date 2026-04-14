<?php
/**
 * Plugin settings: company info, document-type toggles, XML field mapping
 * and CSV column selection.
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UD_NAP_Exporter_Settings {

	const OPTION_KEY = 'ud_nap_exporter_settings';

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Company / shop identification reported in the SAF-T header.
			'shop_unique_id'        => '',
			'company_name'          => '',
			'company_eik'           => '',
			'company_vat'           => '',
			'company_address'       => '',
			'company_city'          => '',
			'company_country'       => 'BG',

			// XML field mapping.
			'meta_transaction_id'   => '_transaction_id',
			'meta_payment_provider' => '',

			// Export defaults.
			'order_statuses'        => array( 'processing', 'completed' ),
			'include_refunds'       => 'yes',
			'batch_size'            => 50,

			// Document type toggles.
			'enable_xml_export'     => 'yes',
			'enable_csv_export'     => 'yes',

			// CSV column selection. Empty array on first run = "all standard columns".
			'csv_columns'           => array_keys( self::standard_csv_columns() ),
			'csv_extra_meta_keys'   => '',
			// Payment gateway IDs treated as "наложен платеж" (cash on delivery)
			// in the CSV summary. Everything else is reported as "card".
			'csv_cod_methods'       => array( 'cod' ),
		);
	}

	/**
	 * Standard, hard-coded CSV column definitions. Labels are in Bulgarian
	 * because the resulting file is consumed by Bulgarian-speaking accounting
	 * staff — both the admin column picker and the exported CSV use these.
	 *
	 * @return array<string,string> column key => label.
	 */
	public static function standard_csv_columns() {
		return array(
			'order_number'          => 'Номер на поръчка',
			'order_status'          => 'Статус',
			'date_created'          => 'Дата на създаване',
			'date_paid'             => 'Дата на плащане',
			'date_completed'        => 'Дата на завършване',
			'currency'              => 'Валута',
			'subtotal'              => 'Междинна сума',
			'discount_total'        => 'Отстъпка',
			'shipping_total'        => 'Доставка',
			'shipping_tax'          => 'ДДС върху доставка',
			'total_tax'             => 'Общо ДДС',
			'total'                 => 'Обща сума',
			'payment_method'        => 'Метод на плащане (код)',
			'payment_method_title'  => 'Метод на плащане',
			'transaction_id'        => 'ID на транзакция',
			'customer_id'           => 'ID на клиент',
			'customer_note'         => 'Бележка от клиента',
			'billing_first_name'    => 'Име (фактуриране)',
			'billing_last_name'     => 'Фамилия (фактуриране)',
			'billing_company'       => 'Фирма (фактуриране)',
			'billing_email'         => 'Имейл (фактуриране)',
			'billing_phone'         => 'Телефон (фактуриране)',
			'billing_address_1'     => 'Адрес 1 (фактуриране)',
			'billing_address_2'     => 'Адрес 2 (фактуриране)',
			'billing_city'          => 'Град (фактуриране)',
			'billing_postcode'      => 'Пощенски код (фактуриране)',
			'billing_state'         => 'Област (фактуриране)',
			'billing_country'       => 'Държава (фактуриране)',
			'shipping_first_name'   => 'Име (доставка)',
			'shipping_last_name'    => 'Фамилия (доставка)',
			'shipping_company'      => 'Фирма (доставка)',
			'shipping_address_1'    => 'Адрес 1 (доставка)',
			'shipping_address_2'    => 'Адрес 2 (доставка)',
			'shipping_city'         => 'Град (доставка)',
			'shipping_postcode'     => 'Пощенски код (доставка)',
			'shipping_state'        => 'Област (доставка)',
			'shipping_country'      => 'Държава (доставка)',
			'items_count'           => 'Брой артикули',
			'items_summary'         => 'Артикули',
		);
	}

	public function hooks() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting(
			'ud_nap_exporter_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public function get_all() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	public function get( $key, $fallback = null ) {
		$all = $this->get_all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public function is_xml_enabled() {
		return 'yes' === $this->get( 'enable_xml_export', 'yes' );
	}

	public function is_csv_enabled() {
		return 'yes' === $this->get( 'enable_csv_export', 'yes' );
	}

	/**
	 * Parse the configured extra meta keys textarea into a clean array.
	 *
	 * @return string[]
	 */
	public function get_extra_meta_keys() {
		$raw = (string) $this->get( 'csv_extra_meta_keys', '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$keys = preg_split( '/[\r\n,]+/', $raw );
		$keys = array_map( 'trim', $keys );
		$keys = array_filter( $keys, 'strlen' );
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Scan recent orders for distinct meta keys so the admin can pick from a
	 * concrete list. Cached for an hour.
	 *
	 * @return string[]
	 */
	/**
	 * Return every payment gateway ID known to the shop — both gateways that
	 * are currently registered with WooCommerce and historical IDs that still
	 * appear on past orders. Used by the CSV exporter to compute "everything
	 * except COD" when the admin asks for the card-only report.
	 *
	 * @return string[]
	 */
	public function get_known_payment_gateway_ids() {
		$ids = array();

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) {
				$ids[ (string) $id ] = true;
			}
		}

		global $wpdb;
		$historical = $wpdb->get_col(
			"SELECT DISTINCT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_payment_method'
			   AND p.post_type = 'shop_order'
			 LIMIT 100"
		);
		if ( is_array( $historical ) ) {
			foreach ( $historical as $gid ) {
				$gid = (string) $gid;
				if ( '' !== $gid ) {
					$ids[ $gid ] = true;
				}
			}
		}

		return array_keys( $ids );
	}

	public function discover_order_meta_keys() {
		$cache_key = 'ud_nap_exporter_meta_keys';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// Limit the scan so this never blows up on a large shop.
		$rows = $wpdb->get_col(
			"SELECT DISTINCT pm.meta_key
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = 'shop_order'
			 ORDER BY pm.meta_key ASC
			 LIMIT 500"
		);

		$rows = is_array( $rows ) ? array_values( array_filter( $rows, 'strlen' ) ) : array();
		set_transient( $cache_key, $rows, HOUR_IN_SECONDS );
		return $rows;
	}

	/**
	 * Section-aware sanitize. The settings are split across three pages
	 * (XML export, CSV export, global Settings); each page submits a hidden
	 * `_section` marker so we only overwrite the fields that page actually
	 * owns. This prevents one page from wiping the others' values.
	 *
	 * @param array $input
	 * @return array
	 */
	public function sanitize( $input ) {
		$existing = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$out = wp_parse_args( $existing, self::defaults() );

		$section = isset( $input['_section'] ) ? sanitize_key( $input['_section'] ) : 'all';

		if ( 'xml' === $section || 'all' === $section ) {
			$out['shop_unique_id']        = isset( $input['shop_unique_id'] ) ? sanitize_text_field( $input['shop_unique_id'] ) : '';
			$out['company_name']          = isset( $input['company_name'] ) ? sanitize_text_field( $input['company_name'] ) : '';
			$out['company_eik']           = isset( $input['company_eik'] ) ? sanitize_text_field( $input['company_eik'] ) : '';
			$out['company_vat']           = isset( $input['company_vat'] ) ? sanitize_text_field( $input['company_vat'] ) : '';
			$out['company_address']       = isset( $input['company_address'] ) ? sanitize_text_field( $input['company_address'] ) : '';
			$out['company_city']          = isset( $input['company_city'] ) ? sanitize_text_field( $input['company_city'] ) : '';
			$out['company_country']       = isset( $input['company_country'] ) ? strtoupper( sanitize_text_field( $input['company_country'] ) ) : 'BG';
			$out['meta_transaction_id']   = isset( $input['meta_transaction_id'] ) ? sanitize_text_field( $input['meta_transaction_id'] ) : '_transaction_id';
			$out['meta_payment_provider'] = isset( $input['meta_payment_provider'] ) ? sanitize_text_field( $input['meta_payment_provider'] ) : '';
		}

		if ( 'csv' === $section || 'all' === $section ) {
			$valid_columns = array_keys( self::standard_csv_columns() );
			if ( isset( $input['csv_columns'] ) && is_array( $input['csv_columns'] ) ) {
				$out['csv_columns'] = array_values( array_intersect( $valid_columns, array_map( 'sanitize_key', $input['csv_columns'] ) ) );
			} else {
				$out['csv_columns'] = array();
			}
			$out['csv_extra_meta_keys'] = isset( $input['csv_extra_meta_keys'] ) ? sanitize_textarea_field( $input['csv_extra_meta_keys'] ) : '';

			if ( isset( $input['csv_cod_methods'] ) && is_array( $input['csv_cod_methods'] ) ) {
				$out['csv_cod_methods'] = array_values( array_filter( array_map( 'sanitize_text_field', $input['csv_cod_methods'] ), 'strlen' ) );
			} else {
				$out['csv_cod_methods'] = array();
			}
		}

		if ( 'global' === $section || 'all' === $section ) {
			$out['enable_xml_export'] = ( isset( $input['enable_xml_export'] ) && 'yes' === $input['enable_xml_export'] ) ? 'yes' : 'no';
			$out['enable_csv_export'] = ( isset( $input['enable_csv_export'] ) && 'yes' === $input['enable_csv_export'] ) ? 'yes' : 'no';

			if ( isset( $input['order_statuses'] ) && is_array( $input['order_statuses'] ) ) {
				$out['order_statuses'] = array_values( array_filter( array_map( 'sanitize_key', $input['order_statuses'] ) ) );
			} else {
				$out['order_statuses'] = array();
			}

			$out['include_refunds'] = ( isset( $input['include_refunds'] ) && 'yes' === $input['include_refunds'] ) ? 'yes' : 'no';
			$out['batch_size']      = isset( $input['batch_size'] ) ? max( 10, min( 500, absint( $input['batch_size'] ) ) ) : 50;
		}

		return $out;
	}
}
