<?php
/**
 * SAF-T XML writer.
 *
 * Uses ext/xmlwriter for memory-efficient streaming. The exporter writes the
 * file in stages across multiple AJAX requests:
 *
 *   1. write_header()  — opens the root + Header section, returns markup.
 *   2. write_invoice() — emits a single <Invoice> fragment for an order.
 *   3. write_refund()  — emits a single <Invoice> fragment with refund flag.
 *   4. write_footer()  — closes the SourceDocuments / root tags.
 *
 * The fragments are appended to the on-disk export file by the exporter, so
 * the writer itself never has to keep the whole document in memory.
 *
 * NOTE: tag names follow the OECD SAF-T 2.0 model adapted to the Bulgarian
 * Ordinance N-18 reporting structure. The exact element names should be
 * cross-checked against the official XSD published by НАП before going to
 * production — every element name lives in this single class to make that
 * easy.
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UD_NAP_Exporter_XML_Writer {

	/** @var UD_NAP_Exporter_Settings */
	private $settings;

	public function __construct( UD_NAP_Exporter_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build the XML prologue + opening Header section.
	 *
	 * @param string $date_from Y-m-d.
	 * @param string $date_to   Y-m-d.
	 * @return string
	 */
	public function write_header( $date_from, $date_to ) {
		$s = $this->settings->get_all();

		$w = new XMLWriter();
		$w->openMemory();
		$w->setIndent( true );
		$w->setIndentString( '  ' );
		$w->startDocument( '1.0', 'UTF-8' );

		$w->startElement( 'AuditFile' );
		$w->writeAttribute( 'xmlns', 'urn:bg:nap:saft:1.0' );

		// ---- Header ----
		$w->startElement( 'Header' );
		$w->writeElement( 'AuditFileVersion', '1.0' );
		$w->writeElement( 'AuditFileCountry', $s['company_country'] ? $s['company_country'] : 'BG' );
		$w->writeElement( 'AuditFileDateCreated', gmdate( 'Y-m-d' ) );
		$w->writeElement( 'SoftwareCompanyName', 'Unbelievable Digital' );
		$w->writeElement( 'SoftwareID', 'UD НАП Orders Exporter' );
		$w->writeElement( 'SoftwareVersion', UD_NAP_EXPORTER_VERSION );

		$w->startElement( 'Company' );
		$w->writeElement( 'RegistrationNumber', $s['company_eik'] );
		$w->writeElement( 'Name', $s['company_name'] );
		if ( ! empty( $s['company_vat'] ) ) {
			$w->writeElement( 'TaxRegistrationNumber', $s['company_vat'] );
		}
		$w->startElement( 'Address' );
		$w->writeElement( 'StreetName', $s['company_address'] );
		$w->writeElement( 'City', $s['company_city'] );
		$w->writeElement( 'Country', $s['company_country'] );
		$w->endElement(); // Address
		$w->endElement(); // Company

		$w->writeElement( 'ShopUniqueID', $s['shop_unique_id'] );

		$w->startElement( 'SelectionCriteria' );
		$w->writeElement( 'SelectionStartDate', $date_from );
		$w->writeElement( 'SelectionEndDate', $date_to );
		$w->endElement(); // SelectionCriteria

		$w->endElement(); // Header

		// ---- Open SourceDocuments / SalesInvoices container ----
		$w->startElement( 'SourceDocuments' );
		$w->startElement( 'SalesInvoices' );

		// We don't end the SourceDocuments / SalesInvoices / AuditFile here —
		// the footer will. Flush the partial buffer.
		$xml = $w->outputMemory( true );

		// XMLWriter would normally close them all on flush; instead, we hand
		// back exactly the prefix we want and the footer writer will print the
		// matching closing tags.
		return $xml;
	}

	/**
	 * Emit a single <Invoice> fragment for an order.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public function write_invoice( WC_Order $order ) {
		return $this->write_order_fragment( $order, false );
	}

	/**
	 * Emit a single <Invoice> fragment for a refund.
	 *
	 * @param WC_Order_Refund $refund
	 * @return string
	 */
	public function write_refund( $refund ) {
		return $this->write_order_fragment( $refund, true );
	}

	/**
	 * @param WC_Abstract_Order $order
	 * @param bool              $is_refund
	 * @return string
	 */
	private function write_order_fragment( $order, $is_refund ) {
		$s = $this->settings->get_all();

		$w = new XMLWriter();
		$w->openMemory();
		$w->setIndent( true );
		$w->setIndentString( '  ' );

		$w->startElement( 'Invoice' );

		$w->writeElement( 'InvoiceNo', $order->get_order_number() );
		$w->writeElement( 'InvoiceType', $is_refund ? 'RC' : 'FT' ); // Refund credit / standard.
		$date = $order->get_date_created();
		$w->writeElement( 'InvoiceDate', $date ? $date->date( 'Y-m-d' ) : '' );

		// ---- Customer ----
		$w->startElement( 'Customer' );
		$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $customer_name ) {
			$customer_name = $order->get_formatted_billing_full_name();
		}
		$w->writeElement( 'CustomerName', $customer_name );
		if ( $order->get_billing_email() ) {
			$w->writeElement( 'CustomerEmail', $order->get_billing_email() );
		}
		if ( $order->get_billing_country() ) {
			$w->writeElement( 'CustomerCountry', $order->get_billing_country() );
		}
		if ( $order->get_billing_city() ) {
			$w->writeElement( 'CustomerCity', $order->get_billing_city() );
		}
		$w->endElement(); // Customer

		// ---- Lines ----
		$w->startElement( 'Lines' );
		$line_no = 0;
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$line_no++;
			$product = $item->get_product();
			$qty     = (float) $item->get_quantity();
			$total   = (float) $item->get_total();
			$tax     = (float) $item->get_total_tax();
			$net_unit = $qty ? $total / $qty : 0.0;

			$w->startElement( 'Line' );
			$w->writeElement( 'LineNumber', (string) $line_no );
			$w->writeElement( 'ProductCode', $product ? (string) $product->get_sku() : '' );
			$w->writeElement( 'ProductName', $item->get_name() );
			$w->writeElement( 'Quantity', $this->fmt( $qty ) );
			$w->writeElement( 'UnitPrice', $this->fmt( $net_unit ) );
			$w->writeElement( 'LineAmount', $this->fmt( $total ) );

			$vat_rate = $this->infer_vat_rate( $total, $tax );
			$w->startElement( 'Tax' );
			$w->writeElement( 'TaxType', 'VAT' );
			$w->writeElement( 'TaxPercentage', $this->fmt( $vat_rate, 2 ) );
			$w->writeElement( 'TaxAmount', $this->fmt( $tax ) );
			$w->endElement(); // Tax

			$w->endElement(); // Line
		}
		$w->endElement(); // Lines

		// ---- Totals ----
		$w->startElement( 'DocumentTotals' );
		$w->writeElement( 'TaxPayable', $this->fmt( (float) $order->get_total_tax() ) );
		$w->writeElement( 'NetTotal', $this->fmt( (float) $order->get_total() - (float) $order->get_total_tax() ) );
		$w->writeElement( 'GrossTotal', $this->fmt( (float) $order->get_total() ) );
		$w->writeElement( 'Currency', $order->get_currency() );
		$w->endElement(); // DocumentTotals

		// ---- Payment ----
		$w->startElement( 'Payment' );
		$w->writeElement( 'PaymentMethod', $order->get_payment_method() );
		$provider_meta_key = $s['meta_payment_provider'];
		$provider          = $provider_meta_key ? (string) $order->get_meta( $provider_meta_key ) : '';
		if ( '' === $provider ) {
			$provider = $order->get_payment_method_title();
		}
		$w->writeElement( 'PaymentProvider', $provider );
		$txn_meta_key = $s['meta_transaction_id'] ? $s['meta_transaction_id'] : '_transaction_id';
		$txn_id       = (string) $order->get_meta( $txn_meta_key );
		if ( '' === $txn_id && method_exists( $order, 'get_transaction_id' ) ) {
			$txn_id = (string) $order->get_transaction_id();
		}
		$w->writeElement( 'TransactionID', $txn_id );
		$w->endElement(); // Payment

		$w->endElement(); // Invoice

		return $w->outputMemory( true );
	}

	/**
	 * Closing tags for the file.
	 *
	 * @return string
	 */
	public function write_footer() {
		return "  </SalesInvoices>\n  </SourceDocuments>\n</AuditFile>\n";
	}

	private function fmt( $value, $decimals = 2 ) {
		return number_format( (float) $value, $decimals, '.', '' );
	}

	private function infer_vat_rate( $net, $tax ) {
		if ( $net <= 0 ) {
			return 0.0;
		}
		return round( ( $tax / $net ) * 100, 2 );
	}
}
