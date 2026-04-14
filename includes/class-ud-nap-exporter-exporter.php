<?php
/**
 * Export orchestrator. Manages a job's lifecycle (init -> step -> finalize),
 * stores progress in a transient and writes the SAF-T file in append mode so
 * we never load thousands of orders into memory at once.
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UD_NAP_Exporter_Exporter {

	const JOB_TRANSIENT = 'ud_nap_exporter_job_';

	/** @var UD_NAP_Exporter_Settings */
	private $settings;

	/** @var UD_NAP_Exporter_Query */
	private $query;

	/** @var UD_NAP_Exporter_XML_Writer */
	private $writer;

	public function __construct( UD_NAP_Exporter_Settings $settings ) {
		$this->settings = $settings;
		$this->query    = new UD_NAP_Exporter_Query();
		$this->writer   = new UD_NAP_Exporter_XML_Writer( $settings, new UD_NAP_Exporter_Doc_Counter( $settings ) );
	}

	/**
	 * Create a new export job.
	 *
	 * @param string $date_from  Y-m-d.
	 * @param string $date_to    Y-m-d.
	 * @param bool   $with_refunds
	 * @return array job descriptor.
	 */
	public function start_job( $date_from, $date_to, $with_refunds ) {
		$s        = $this->settings->get_all();
		$statuses = $s['order_statuses'];

		$ids = $this->query->get_ids( $date_from, $date_to, $statuses, $with_refunds, -1, 0 );

		$job_id   = wp_generate_password( 12, false, false );
		$file     = $this->job_file_path( $job_id );
		$prologue = $this->writer->write_header( $date_from, $date_to );

		// Start the file from scratch with the prologue.
		file_put_contents( $file, $prologue );

		$job = array(
			'id'           => $job_id,
			'date_from'    => $date_from,
			'date_to'      => $date_to,
			'with_refunds' => (bool) $with_refunds,
			'ids'          => array_values( $ids ),
			'total'        => count( $ids ),
			'processed'    => 0,
			'file'         => $file,
			'created_at'   => time(),
			'finished'     => false,
		);

		$this->save_job( $job );
		return $job;
	}

	/**
	 * Process the next batch for a job.
	 *
	 * @param string $job_id
	 * @return array updated job descriptor.
	 */
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

		$buffer = '';
		foreach ( $slice as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			if ( is_a( $order, 'WC_Order_Refund' ) ) {
				$buffer .= $this->writer->write_refund( $order );
			} else {
				$buffer .= $this->writer->write_invoice( $order );
			}
		}

		if ( '' !== $buffer ) {
			file_put_contents( $job['file'], $buffer, FILE_APPEND );
		}

		$job['processed'] += count( $slice );

		if ( $job['processed'] >= $job['total'] ) {
			return $this->finalize( $job );
		}

		$this->save_job( $job );
		return $job;
	}

	/**
	 * Append the closing tags and mark the job done.
	 *
	 * @param array $job
	 * @return array
	 */
	private function finalize( array $job ) {
		file_put_contents( $job['file'], $this->writer->write_footer(), FILE_APPEND );
		$job['finished']     = true;
		$job['download_url'] = $this->build_download_url( $job['id'] );
		$this->save_job( $job );
		return $job;
	}

	/**
	 * Stream the file to the browser then delete the job.
	 *
	 * @param string $job_id
	 */
	public function stream_download( $job_id ) {
		$job = $this->load_job( $job_id );
		if ( ! $job || empty( $job['finished'] ) || ! file_exists( $job['file'] ) ) {
			wp_die( esc_html__( 'Експорт файлът не е наличен.', 'ud-nap-orders-exporter' ) );
		}

		$filename = sprintf( 'nap-saft-%s_%s.xml', $job['date_from'], $job['date_to'] );

		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $job['file'] ) );

		readfile( $job['file'] );

		// Cleanup.
		@unlink( $job['file'] );
		delete_transient( self::JOB_TRANSIENT . $job_id );
		exit;
	}

	private function build_download_url( $job_id ) {
		return add_query_arg(
			array(
				'action' => 'ud_nap_download',
				'job'    => $job_id,
				'_wpnonce' => wp_create_nonce( 'ud_nap_download_' . $job_id ),
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
		return $dir . '/nap-saft-' . $job_id . '.xml';
	}

	public function save_job( array $job ) {
		set_transient( self::JOB_TRANSIENT . $job['id'], $job, HOUR_IN_SECONDS );
	}

	public function load_job( $job_id ) {
		$job = get_transient( self::JOB_TRANSIENT . $job_id );
		return is_array( $job ) ? $job : null;
	}
}
