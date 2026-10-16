<?php
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Migrates the existing Media Library in small AJAX batches. Progress is the post meta itself,
 * so the run can be stopped and resumed at any time.
 */
final class BS3O_Bulk {

	public const BATCH = 10;

	public static function init(): void {
		add_action( 'wp_ajax_bs3o_bulk_batch', [ __CLASS__, 'ajax_batch' ] );
	}

	public static function counts(): array {
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
		$done  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = 'attachment' AND m.meta_key = %s",
			BS3O_Uploader::META
		) );
		$failed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = 'attachment' AND m.meta_key = %s",
			BS3O_Uploader::ERROR
		) );
		return [
			'total'     => $total,
			'offloaded' => $done,
			'failed'    => $failed,
			'remaining' => max( 0, $total - $done ),
		];
	}

	/**
	 * Next attachments that are neither offloaded nor marked as failed.
	 *
	 * @return int[]
	 */
	public static function next_ids( int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
			WHERE p.post_type = 'attachment' AND i.meta_id IS NULL AND e.meta_id IS NULL
			ORDER BY p.ID ASC LIMIT %d",
			BS3O_Uploader::META,
			BS3O_Uploader::ERROR,
			$limit
		) );
		return array_map( 'intval', $ids );
	}

	/** Clear failure markers so failed items are retried. */
	public static function reset_failed(): void {
		delete_post_meta_by_key( BS3O_Uploader::ERROR );
	}

	/**
	 * Offload one batch.
	 *
	 * @return array{processed:int, errors:array<int,array{id:int,title:string,message:string}>, finished:bool}
	 */
	public static function run_batch( int $limit ): array {
		$ids    = self::next_ids( $limit );
		$errors = [];
		foreach ( $ids as $id ) {
			$result = BS3O_Uploader::offload( $id );
			if ( is_wp_error( $result ) ) {
				$errors[] = [ 'id' => $id, 'title' => get_the_title( $id ), 'message' => $result->get_error_message() ];
			}
		}
		return [ 'processed' => count( $ids ), 'errors' => $errors, 'finished' => count( $ids ) < $limit ];
	}

	public static function ajax_batch(): void {
		check_ajax_referer( 'bs3o', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}
		if ( ! BS3O_Settings::is_configured() ) {
			wp_send_json_error( 'Configure and save the bucket settings first.' );
		}
		if ( ! empty( $_POST['retry'] ) ) {
			self::reset_failed();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$batch = self::run_batch( (int) apply_filters( 'bs3o_bulk_batch_size', self::BATCH ) );
		wp_send_json_success( $batch + [ 'counts' => self::counts() ] );
	}

	public static function render(): void {
		$c = self::counts();
		?>
		<h2>Offload existing media</h2>
		<?php if ( ! BS3O_Settings::is_configured() ) : ?>
			<div class="notice notice-warning inline"><p>Configure and save the bucket on the Settings tab first.</p></div>
		<?php endif; ?>
		<p>Uploads every Media Library item (original, scaled original and all generated sizes) that is not in S3 yet.
		<?php echo BS3O_Settings::get( 'remove_local' ) ? 'Local copies are deleted after each item uploads successfully.' : 'Local copies are kept.'; ?>
		You can leave and come back — progress is saved per item. For very large libraries use WP-CLI: <code>wp bs3o offload</code>.</p>

		<table class="widefat striped bs3o-counts">
			<tbody>
				<tr><th>Media items</th><td id="bs3o-total"><?php echo esc_html( number_format_i18n( $c['total'] ) ); ?></td></tr>
				<tr><th>In S3</th><td id="bs3o-offloaded"><?php echo esc_html( number_format_i18n( $c['offloaded'] ) ); ?></td></tr>
				<tr><th>Remaining</th><td id="bs3o-remaining"><?php echo esc_html( number_format_i18n( $c['remaining'] ) ); ?></td></tr>
				<tr><th>Failed</th><td id="bs3o-failed"><?php echo esc_html( number_format_i18n( $c['failed'] ) ); ?></td></tr>
			</tbody>
		</table>

		<div class="bs3o-progress" aria-hidden="true"><div class="bs3o-progress-bar" style="width:<?php echo esc_attr( (string) ( $c['total'] ? round( $c['offloaded'] / $c['total'] * 100 ) : 0 ) ); ?>%"></div></div>
		<p id="bs3o-status" aria-live="polite"></p>

		<p>
			<button type="button" class="button button-primary" id="bs3o-start" <?php disabled( ! BS3O_Settings::is_configured() ); ?>>Start offloading</button>
			<button type="button" class="button" id="bs3o-retry" <?php disabled( ! BS3O_Settings::is_configured() || ! $c['failed'] ); ?>>Retry failed</button>
			<button type="button" class="button" id="bs3o-stop" disabled>Stop</button>
		</p>

		<ul id="bs3o-errors" class="bs3o-errors"></ul>
		<?php
	}
}
