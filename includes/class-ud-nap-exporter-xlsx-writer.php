<?php
/**
 * Minimal XLSX writer with no external dependencies. An .xlsx file is a ZIP
 * archive containing a handful of XML parts; we assemble exactly the parts
 * Excel / Numbers / LibreOffice need to open a single-sheet workbook with
 * inline string and numeric cells. No styles, no formulas, no shared strings.
 *
 * Use:
 *   UD_NAP_Exporter_XLSX_Writer::write( $rows, 'Поръчки', '/tmp/file.xlsx' );
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UD_NAP_Exporter_XLSX_Writer {

	/**
	 * Write a single-sheet XLSX file.
	 *
	 * @param iterable $rows         Each row is an array of scalar values.
	 * @param string   $sheet_name   Worksheet tab name (max 31 chars, certain
	 *                               characters not allowed in Excel).
	 * @param string   $output_path  Absolute filesystem path of the .xlsx to
	 *                               create. Existing file is overwritten.
	 * @return bool true on success.
	 */
	public static function write( $rows, $sheet_name, $output_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$zip->addFromString( '[Content_Types].xml', self::content_types_xml() );
		$zip->addFromString( '_rels/.rels', self::root_rels_xml() );
		$zip->addFromString( 'xl/workbook.xml', self::workbook_xml( self::sanitize_sheet_name( $sheet_name ) ) );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels_xml() );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', self::sheet_xml( $rows ) );

		$zip->close();
		return true;
	}

	/**
	 * Build the worksheet body. Each cell is auto-typed: numeric strings
	 * become number cells (so Excel formats / sums them), everything else is
	 * an inline string. Values that look numeric but represent identifiers
	 * (leading zero, very long digit strings like phone numbers) are kept as
	 * strings to preserve their original form.
	 */
	private static function sheet_xml( $rows ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheetData>';

		$row_index = 1;
		foreach ( $rows as $row ) {
			$xml .= '<row r="' . $row_index . '">';
			$col_index = 0;
			foreach ( $row as $value ) {
				$cell_ref = self::col_letter( $col_index ) . $row_index;
				$xml     .= self::cell_xml( $cell_ref, $value );
				$col_index++;
			}
			$xml .= '</row>';
			$row_index++;
		}

		$xml .= '</sheetData></worksheet>';
		return $xml;
	}

	private static function cell_xml( $ref, $value ) {
		if ( null === $value || '' === $value ) {
			return '<c r="' . $ref . '"/>';
		}

		$str = (string) $value;

		if ( self::looks_numeric( $str ) ) {
			return '<c r="' . $ref . '"><v>' . self::xml_escape( $str ) . '</v></c>';
		}

		return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml_escape( $str ) . '</t></is></c>';
	}

	/**
	 * Strict numeric check. Avoids treating phone numbers / postcodes / IDs
	 * with leading zeros as numbers (which would silently strip the zero).
	 */
	private static function looks_numeric( $value ) {
		if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
			return false;
		}
		// "0" alone is fine; "0123" is a string.
		if ( strlen( $value ) > 1 && '0' === $value[0] && '.' !== $value[1] ) {
			return false;
		}
		// Phone-length integers should not be silently formatted into
		// scientific notation by spreadsheets.
		if ( false === strpos( $value, '.' ) && strlen( ltrim( $value, '-' ) ) > 11 ) {
			return false;
		}
		return true;
	}

	/**
	 * Convert a 0-based column index to its Excel column letter (A, B, ...,
	 * Z, AA, AB, ...).
	 */
	private static function col_letter( $index ) {
		$letter = '';
		$n      = $index + 1;
		while ( $n > 0 ) {
			$rem    = ( $n - 1 ) % 26;
			$letter = chr( 65 + $rem ) . $letter;
			$n      = (int) ( ( $n - 1 ) / 26 );
		}
		return $letter;
	}

	private static function sanitize_sheet_name( $name ) {
		// Excel disallows these characters in sheet names.
		$name = preg_replace( '#[\\\\/\?\*\[\]:]#', ' ', (string) $name );
		$name = trim( $name );
		if ( '' === $name ) {
			$name = 'Sheet1';
		}
		// Excel sheet names are limited to 31 characters.
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 31 );
		} else {
			$name = substr( $name, 0, 31 );
		}
		return $name;
	}

	private static function xml_escape( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	// ---- Static XML parts ---------------------------------------------------

	private static function content_types_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '</Types>';
	}

	private static function root_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	private static function workbook_xml( $sheet_name ) {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="' . self::xml_escape( $sheet_name ) . '" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	private static function workbook_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '</Relationships>';
	}
}
