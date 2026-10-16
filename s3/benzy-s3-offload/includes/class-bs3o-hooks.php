<?php
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * WordPress integration: offload new uploads, serve URLs from S3, delete remote files,
 * and fetch files back when core needs them on disk.
 */
final class BS3O_Hooks {

	/** Attachments already handled in this request. */
	private static array $done = [];

	/** Offloaded attachments whose metadata changed (image edit, regenerate) — re-synced on shutdown. */
	private static array $queue = [];

	public static function init(): void {
		// New uploads: runs once all sub-sizes exist, before the upload response is sent.
		add_filter( 'wp_generate_attachment_metadata', [ __CLASS__, 'on_generate' ], 99, 2 );
		// Edits/regeneration call this repeatedly while sizes are being made, so only queue here.
		add_filter( 'wp_update_attachment_metadata', [ __CLASS__, 'on_update' ], 99, 2 );
		add_action( 'shutdown', [ __CLASS__, 'flush_queue' ] );

		add_action( 'delete_attachment', [ __CLASS__, 'on_delete' ] );

		add_filter( 'wp_get_attachment_url', [ __CLASS__, 'attachment_url' ], 99, 2 );
		add_filter( 'wp_calculate_image_srcset', [ __CLASS__, 'srcset' ], 99, 5 );
		add_filter( 'the_content', [ __CLASS__, 'content_urls' ], 99 );
		add_filter( 'widget_block_content', [ __CLASS__, 'content_urls' ], 99 );

		add_filter( 'load_image_to_edit_path', [ __CLASS__, 'edit_path' ], 99, 3 );
		add_filter( 'wp_get_original_image_path', [ __CLASS__, 'original_path' ], 99, 2 );

		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
	}

	public static function on_generate( mixed $meta, int $id ): mixed {
		if ( ! BS3O_Settings::get( 'offload_new' ) && ! BS3O_Uploader::info( $id ) ) {
			return $meta;
		}
		if ( ! BS3O_Settings::is_configured() ) {
			return $meta;
		}
		$result = BS3O_Uploader::offload( $id, is_array( $meta ) ? $meta : null );
		if ( is_wp_error( $result ) ) {
			set_transient( 'bs3o_notice', sprintf( '"%s" was not uploaded to S3: %s', get_the_title( $id ), $result->get_error_message() ), HOUR_IN_SECONDS );
		}
		self::$done[ $id ] = true;
		return $meta;
	}

	public static function on_update( mixed $meta, int $id ): mixed {
		if ( empty( self::$done[ $id ] ) && BS3O_Uploader::info( $id ) ) {
			self::$queue[ $id ] = true;
		}
		return $meta;
	}

	public static function flush_queue(): void {
		foreach ( array_keys( self::$queue ) as $id ) {
			if ( empty( self::$done[ $id ] ) ) {
				BS3O_Uploader::offload( (int) $id );
			}
		}
		self::$queue = [];
	}

	public static function on_delete( int $id ): void {
		if ( BS3O_Uploader::info( $id ) ) {
			BS3O_Uploader::delete_remote( $id );
		}
	}

	/** Map an uploads URL to its S3/CDN URL. */
	private static function rewrite( string $url, array $info ): string {
		$base = wp_get_upload_dir()['baseurl'] . '/';
		$url  = set_url_scheme( $url );
		$base = set_url_scheme( $base );
		if ( ! str_starts_with( $url, $base ) ) {
			return $url;
		}
		return BS3O_Uploader::url( $info, rawurldecode( substr( $url, strlen( $base ) ) ) );
	}

	public static function attachment_url( string $url, int $id ): string {
		$info = BS3O_Uploader::info( $id );
		return $info ? self::rewrite( $url, $info ) : $url;
	}

	public static function srcset( mixed $sources, array $size_array, string $src, array $meta, int $id ): mixed {
		$info = BS3O_Uploader::info( $id );
		if ( ! $info || ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $w => $source ) {
			$sources[ $w ]['url'] = self::rewrite( $source['url'], $info );
		}
		return $sources;
	}

	/**
	 * Hard-coded uploads URLs in post content (links, galleries, old posts): point the ones whose
	 * local file is gone at the bucket.
	 */
	public static function content_urls( mixed $content ): mixed {
		if ( ! is_string( $content ) || '' === $content || ! BS3O_Settings::is_configured() ) {
			return $content;
		}
		$uploads = wp_get_upload_dir();
		$base    = preg_replace( '#^https?:#i', '', $uploads['baseurl'] );
		if ( ! str_contains( $content, $base ) ) {
			return $content;
		}

		$info = [ 'bucket' => BS3O_Client::bucket(), 'prefix' => (string) BS3O_Settings::get( 'prefix' ) ];

		return preg_replace_callback(
			'#(?:https?:)?' . preg_quote( $base, '#' ) . '/([^"\'\s)<>?\#]+)#i',
			static function ( array $m ) use ( $uploads, $info ): string {
				$rel = rawurldecode( $m[1] );
				if ( str_contains( $rel, '..' ) || file_exists( $uploads['basedir'] . '/' . $rel ) ) {
					return $m[0];
				}
				return BS3O_Uploader::url( $info, $rel );
			},
			$content
		) ?? $content;
	}

	/** The image editor needs the original on disk. */
	public static function edit_path( mixed $path, int $id, mixed $size ): mixed {
		if ( is_string( $path ) && ! wp_http_validate_url( $path ) && file_exists( $path ) ) {
			return $path;
		}
		$files = BS3O_Uploader::info( $id ) ? BS3O_Uploader::files_for( $id ) : [];
		if ( $files && BS3O_Uploader::restore( $id, $files[0] ) ) {
			self::$queue[ $id ] = true; // Remove the restored copy again at the end of the request.
			return BS3O_Uploader::basedir() . '/' . $files[0];
		}
		return $path;
	}

	/** Regenerating sizes reads the (unscaled) original. */
	public static function original_path( mixed $path, int $id ): mixed {
		if ( ! is_string( $path ) || '' === $path || file_exists( $path ) || ! BS3O_Uploader::info( $id ) ) {
			return $path;
		}
		$basedir = wp_normalize_path( BS3O_Uploader::basedir() );
		$norm    = wp_normalize_path( $path );
		if ( str_starts_with( $norm, $basedir . '/' ) && BS3O_Uploader::restore( $id, substr( $norm, strlen( $basedir ) + 1 ) ) ) {
			self::$queue[ $id ] = true;
		}
		return $path;
	}

	public static function notice(): void {
		$msg = get_transient( 'bs3o_notice' );
		if ( ! $msg || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		delete_transient( 'bs3o_notice' );
		echo '<div class="notice notice-warning is-dismissible"><p><strong>S3 Offload:</strong> ' . esc_html( $msg ) . '</p></div>';
	}
}
