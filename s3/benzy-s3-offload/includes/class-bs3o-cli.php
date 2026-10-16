<?php
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Offload media to S3 from the command line.
 */
final class BS3O_CLI {

	/**
	 * Upload every Media Library item that is not in S3 yet.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<number>]
	 * : Items fetched per query.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--retry-failed]
	 * : Retry items that failed in an earlier run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bs3o offload
	 *     wp bs3o offload --retry-failed
	 */
	public function offload( array $args, array $assoc ): void {
		if ( ! BS3O_Settings::is_configured() ) {
			\WP_CLI::error( 'Configure the bucket under Settings → S3 Offload first.' );
		}
		if ( ! empty( $assoc['retry-failed'] ) ) {
			BS3O_Bulk::reset_failed();
		}

		$batch  = max( 1, (int) ( $assoc['batch'] ?? 50 ) );
		$counts = BS3O_Bulk::counts();
		$bar    = \WP_CLI\Utils\make_progress_bar( 'Offloading', $counts['remaining'] );
		$ok     = 0;
		$failed = 0;

		do {
			$result = BS3O_Bulk::run_batch( $batch );
			foreach ( $result['errors'] as $error ) {
				\WP_CLI::warning( sprintf( '#%d %s: %s', $error['id'], $error['title'], $error['message'] ) );
			}
			$failed += count( $result['errors'] );
			$ok     += $result['processed'] - count( $result['errors'] );
			for ( $i = 0; $i < $result['processed']; $i++ ) {
				$bar->tick();
			}
			// Keep memory flat on big libraries.
			wp_cache_flush_runtime();
		} while ( ! $result['finished'] );

		$bar->finish();
		$failed ? \WP_CLI::warning( "Offloaded $ok item(s), $failed failed." ) : \WP_CLI::success( "Offloaded $ok item(s)." );
	}

	/**
	 * Show how much of the Media Library is in S3.
	 */
	public function status(): void {
		$c = BS3O_Bulk::counts();
		\WP_CLI::log( sprintf( "Media items: %d\nIn S3:       %d\nRemaining:   %d\nFailed:      %d", $c['total'], $c['offloaded'], $c['remaining'], $c['failed'] ) );
	}

	/**
	 * Check that the bucket can be written, read and deleted.
	 */
	public function test(): void {
		$result = BS3O_Client::test();
		is_wp_error( $result ) ? \WP_CLI::error( $result->get_error_message() ) : \WP_CLI::success( 'Connection OK.' );
	}
}
