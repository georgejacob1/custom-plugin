<?php
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Moves one attachment (original, scaled original, every size, edit backups) to S3.
 *
 * State lives in post meta:
 *   _bs3o_info  = [ 'bucket' => string, 'prefix' => string, 'files' => string[] ]  (paths relative to uploads dir)
 *   _bs3o_error = last error message, set when offloading failed
 */
final class BS3O_Uploader {

	public const META  = '_bs3o_info';
	public const ERROR = '_bs3o_error';

	public static function info( int $id ): ?array {
		$info = get_post_meta( $id, self::META, true );
		return is_array( $info ) && ! empty( $info['bucket'] ) ? $info : null;
	}

	public static function basedir(): string {
		return wp_get_upload_dir()['basedir'];
	}

	/**
	 * Every file of an attachment, relative to the uploads directory.
	 */
	public static function files_for( int $id, ?array $meta = null ): array {
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $file ) {
			return [];
		}

		$basedir = wp_normalize_path( self::basedir() );
		$file    = wp_normalize_path( $file );
		if ( path_is_absolute( $file ) ) {
			if ( ! str_starts_with( $file, $basedir . '/' ) ) {
				return []; // Lives outside the uploads folder; leave it alone.
			}
			$file = substr( $file, strlen( $basedir ) + 1 );
		}

		$dir   = dirname( $file );
		$dir   = '.' === $dir ? '' : $dir . '/';
		$files = [ $file ];

		$meta ??= wp_get_attachment_metadata( $id );
		$meta   = is_array( $meta ) ? $meta : [];

		if ( ! empty( $meta['original_image'] ) ) {
			$files[] = $dir . $meta['original_image'];
		}
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $dir . $size['file'];
			}
		}
		$backups = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		foreach ( is_array( $backups ) ? $backups : [] as $backup ) {
			if ( ! empty( $backup['file'] ) ) {
				$files[] = $dir . $backup['file'];
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Upload all files of an attachment that aren't in S3 yet, then (optionally) remove the local copies.
	 * Nothing is deleted locally unless every upload succeeded.
	 */
	public static function offload( int $id, ?array $meta = null ): bool|\WP_Error {
		if ( ! BS3O_Settings::is_configured() ) {
			return new \WP_Error( 'bs3o_config', 'S3 bucket is not configured.' );
		}

		$files = self::files_for( $id, $meta );
		if ( ! $files ) {
			return self::fail( $id, 'Attachment has no file inside the uploads folder.' );
		}

		$info     = self::info( $id );
		$bucket   = $info['bucket'] ?? BS3O_Client::bucket();
		$prefix   = $info['prefix'] ?? (string) BS3O_Settings::get( 'prefix' );
		$uploaded = $info['files'] ?? [];
		$basedir  = self::basedir();

		if ( $bucket !== BS3O_Client::bucket() ) {
			return self::fail( $id, 'Attachment was offloaded to another bucket (' . $bucket . ').' );
		}

		foreach ( $files as $i => $rel ) {
			$path = $basedir . '/' . $rel;
			if ( ! file_exists( $path ) ) {
				if ( in_array( $rel, $uploaded, true ) ) {
					continue; // Already offloaded and removed locally.
				}
				if ( 0 === $i ) {
					return self::fail( $id, 'Local file is missing: ' . $rel );
				}
				continue; // A missing intermediate size isn't worth failing the whole attachment.
			}
			try {
				BS3O_Client::put( $prefix . $rel, $path );
				$uploaded[] = $rel;
			} catch ( \Throwable $e ) {
				return self::fail( $id, BS3O_Client::message( $e ) );
			}
		}

		$uploaded = array_values( array_unique( $uploaded ) );
		update_post_meta( $id, self::META, [ 'bucket' => $bucket, 'prefix' => $prefix, 'files' => $uploaded ] );
		delete_post_meta( $id, self::ERROR );

		if ( BS3O_Settings::get( 'remove_local' ) ) {
			self::remove_local( $uploaded );
		}

		do_action( 'bs3o_offloaded', $id, $uploaded );
		return true;
	}

	public static function remove_local( array $files ): void {
		$basedir = self::basedir();
		foreach ( $files as $rel ) {
			$path = $basedir . '/' . $rel;
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Bring an offloaded file back to disk (used when WordPress needs to read it).
	 */
	public static function restore( int $id, string $rel ): bool {
		$info = self::info( $id );
		$path = self::basedir() . '/' . $rel;
		if ( file_exists( $path ) ) {
			return true;
		}
		return $info ? BS3O_Client::download( $info['bucket'], $info['prefix'] . $rel, $path ) : false;
	}

	/** Remove every S3 object belonging to an attachment. */
	public static function delete_remote( int $id ): void {
		$info = self::info( $id );
		if ( ! $info ) {
			return;
		}
		$files = array_unique( array_merge( $info['files'], self::files_for( $id ) ) );
		$keys  = array_map( static fn( $rel ) => $info['prefix'] . $rel, $files );
		BS3O_Client::delete( $info['bucket'], $keys );
	}

	public static function url( array $info, string $rel ): string {
		return BS3O_Client::url( $info['bucket'], $info['prefix'] . $rel );
	}

	private static function fail( int $id, string $message ): \WP_Error {
		update_post_meta( $id, self::ERROR, $message );
		BS3O_Client::log( sprintf( 'Attachment %d: %s', $id, $message ) );
		return new \WP_Error( 'bs3o_offload', $message );
	}
}
