/* global jQuery, udNapExporter */
( function ( $ ) {
	'use strict';

	function sprintf( tpl, a, b ) {
		return tpl.replace( '%1$s', a ).replace( '%2$s', b ).replace( '%s', a );
	}

	function setStatus( $form, msg ) {
		$form.find( '.ud-nap-status' ).text( msg );
	}

	function setProgress( $form, processed, total ) {
		var pct = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;
		$form.find( '.ud-nap-progress progress' ).val( pct );
	}

	function step( $form, jobId ) {
		var actionStep = $form.data( 'action-step' );
		$.post( udNapExporter.ajaxUrl, {
			action: actionStep,
			nonce: udNapExporter.nonce,
			job_id: jobId
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				setStatus( $form, sprintf( udNapExporter.i18n.failed, ( res && res.data && res.data.message ) || 'Unknown error' ) );
				return;
			}
			var d = res.data;
			setProgress( $form, d.processed, d.total );
			setStatus( $form, sprintf( udNapExporter.i18n.processing, d.processed, d.total ) );

			if ( d.finished ) {
				setStatus( $form, udNapExporter.i18n.done );
				$form.find( '.ud-nap-result' )
					.html( '<p><a class="button button-primary" href="' + d.download_url + '">' + udNapExporter.i18n.download + '</a></p>' )
					.show();
				return;
			}
			step( $form, jobId );
		} ).fail( function () {
			setStatus( $form, sprintf( udNapExporter.i18n.failed, 'network error' ) );
		} );
	}

	$( function () {
		$( '.ud-nap-export-form .ud-nap-export-start' ).on( 'click', function () {
			var $form    = $( this ).closest( '.ud-nap-export-form' );
			var dateFrom = $form.find( '.ud-nap-date-from' ).val();
			var dateTo   = $form.find( '.ud-nap-date-to' ).val();
			if ( ! dateFrom || ! dateTo ) {
				return;
			}

			$form.find( '.ud-nap-result' ).hide().empty();
			$form.find( '.ud-nap-progress' ).show();
			setProgress( $form, 0, 1 );
			setStatus( $form, udNapExporter.i18n.starting );

			var paymentMethods = [];
			$form.find( '.ud-nap-payment-method:checked' ).each( function () {
				paymentMethods.push( $( this ).val() );
			} );

			var reportType = $form.find( '.ud-nap-report-type:checked' ).val() || 'all';

			$.post( udNapExporter.ajaxUrl, {
				action: $form.data( 'action-start' ),
				nonce: udNapExporter.nonce,
				date_from: dateFrom,
				date_to: dateTo,
				include_refunds: $form.find( '.ud-nap-include-refunds' ).is( ':checked' ) ? 1 : 0,
				payment_methods: paymentMethods,
				report_type: reportType
			} ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					setStatus( $form, sprintf( udNapExporter.i18n.failed, ( res && res.data && res.data.message ) || 'Unknown error' ) );
					return;
				}
				var d = res.data;
				setProgress( $form, d.processed, d.total );
				if ( d.total === 0 ) {
					setStatus( $form, sprintf( udNapExporter.i18n.processing, 0, 0 ) );
				}
				step( $form, d.id );
			} ).fail( function () {
				setStatus( $form, sprintf( udNapExporter.i18n.failed, 'network error' ) );
			} );
		} );

		// ----- CSV column picker: auto-save on change -----------------------
		var saveTimer = null;

		function saveColumns() {
			var $indicator = $( '.ud-nap-columns-saved' );
			var cols = [];
			$( '.ud-nap-columns-grid input[type=checkbox]:checked' ).each( function () {
				cols.push( $( this ).val() );
			} );

			$indicator
				.removeClass( 'is-error is-saved' )
				.addClass( 'is-saving' )
				.text( udNapExporter.i18n.saving )
				.stop( true, true )
				.show();

			$.post( udNapExporter.ajaxUrl, {
				action: 'ud_nap_save_columns',
				nonce: udNapExporter.nonce,
				columns: cols
			} ).done( function ( res ) {
				if ( res && res.success ) {
					$indicator
						.removeClass( 'is-saving is-error' )
						.addClass( 'is-saved' )
						.text( udNapExporter.i18n.saved )
						.delay( 1500 )
						.fadeOut( 400 );
				} else {
					$indicator
						.removeClass( 'is-saving is-saved' )
						.addClass( 'is-error' )
						.text( udNapExporter.i18n.saveFailed );
				}
			} ).fail( function () {
				$indicator
					.removeClass( 'is-saving is-saved' )
					.addClass( 'is-error' )
					.text( udNapExporter.i18n.saveFailed );
			} );
		}

		function scheduleSave( delay ) {
			if ( saveTimer ) {
				clearTimeout( saveTimer );
			}
			saveTimer = setTimeout( saveColumns, delay );
		}

		$( document ).on( 'change', '.ud-nap-columns-grid input[type=checkbox]', function () {
			scheduleSave( 300 );
		} );

		// Payment-method filter helper buttons (per export form).
		$( document ).on( 'click', '.ud-nap-pm-all', function () {
			$( this ).closest( 'td' ).find( '.ud-nap-payment-method' ).prop( 'checked', true );
		} );
		$( document ).on( 'click', '.ud-nap-pm-none', function () {
			$( this ).closest( 'td' ).find( '.ud-nap-payment-method' ).prop( 'checked', false );
		} );

		$( '#ud-nap-cols-all' ).on( 'click', function () {
			$( '.ud-nap-columns-grid input[type=checkbox]' ).prop( 'checked', true );
			scheduleSave( 100 );
		} );
		$( '#ud-nap-cols-none' ).on( 'click', function () {
			$( '.ud-nap-columns-grid input[type=checkbox]' ).prop( 'checked', false );
			scheduleSave( 100 );
		} );
	} );
} )( jQuery );
