<?php
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use Aws\S3\S3Client;

/**
 * Thin wrapper around the AWS SDK S3 client.
 */
final class BS3O_Client {

	private static ?S3Client $client = null;

	public static function client(): S3Client {
		if ( null === self::$client ) {
			$args = [
				'version'                 => 'latest',
				'region'                  => (string) BS3O_Settings::get( 'region' ),
				'use_path_style_endpoint' => (bool) BS3O_Settings::get( 'path_style' ),
			];

			$endpoint = (string) BS3O_Settings::get( 'endpoint' );
			if ( '' !== $endpoint ) {
				$args['endpoint'] = $endpoint;
			}

			$key    = BS3O_Settings::access_key();
			$secret = BS3O_Settings::secret_key();
			if ( '' !== $key && '' !== $secret ) {
				$args['credentials'] = [ 'key' => $key, 'secret' => $secret ];
			}
			// Otherwise the SDK's default chain is used (env vars, ~/.aws, EC2/ECS IAM role).

			self::$client = new S3Client( apply_filters( 'bs3o_client_args', $args ) );
		}
		return self::$client;
	}

	public static function bucket(): string {
		return (string) BS3O_Settings::get( 'bucket' );
	}

	/** Upload a local file. Throws Aws\Exception\AwsException on failure. */
	public static function put( string $key, string $path ): void {
		$type = wp_check_filetype( $path );
		$args = [
			'Bucket'       => self::bucket(),
			'Key'          => $key,
			'SourceFile'   => $path,
			'ContentType'  => $type['type'] ?: 'application/octet-stream',
			'CacheControl' => 'public, max-age=31536000',
		];
		if ( BS3O_Settings::get( 'acl_public' ) ) {
			$args['ACL'] = 'public-read';
		}
		self::client()->putObject( apply_filters( 'bs3o_put_object_args', $args, $path ) );
	}

	/** Download an object to a local path. */
	public static function download( string $bucket, string $key, string $path ): bool {
		try {
			wp_mkdir_p( dirname( $path ) );
			self::client()->getObject( [ 'Bucket' => $bucket, 'Key' => $key, 'SaveAs' => $path ] );
			return file_exists( $path );
		} catch ( \Throwable $e ) {
			self::log( 'Download failed for ' . $key . ': ' . $e->getMessage() );
			return false;
		}
	}

	/** Delete many keys from one bucket (batched at the API limit of 1000). */
	public static function delete( string $bucket, array $keys ): void {
		foreach ( array_chunk( array_values( array_unique( $keys ) ), 1000 ) as $chunk ) {
			try {
				self::client()->deleteObjects( [
					'Bucket' => $bucket,
					'Delete' => [ 'Objects' => array_map( static fn( $k ) => [ 'Key' => $k ], $chunk ), 'Quiet' => true ],
				] );
			} catch ( \Throwable $e ) {
				self::log( 'Delete failed: ' . $e->getMessage() );
			}
		}
	}

	/** Write, read and remove a tiny object to prove the credentials and bucket work. */
	public static function test(): bool|\WP_Error {
		if ( ! BS3O_Settings::is_configured() ) {
			return new \WP_Error( 'bs3o_config', 'Bucket and region are required.' );
		}
		$key = BS3O_Settings::get( 'prefix' ) . 'bs3o-connection-test-' . wp_generate_password( 8, false ) . '.txt';
		try {
			$client = self::client();
			$args   = [ 'Bucket' => self::bucket(), 'Key' => $key, 'Body' => 'ok', 'ContentType' => 'text/plain' ];
			if ( BS3O_Settings::get( 'acl_public' ) ) {
				$args['ACL'] = 'public-read';
			}
			$client->putObject( $args );
			$client->headObject( [ 'Bucket' => self::bucket(), 'Key' => $key ] );
			$client->deleteObject( [ 'Bucket' => self::bucket(), 'Key' => $key ] );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'bs3o_test', self::message( $e ) );
		}
		return true;
	}

	/**
	 * Public URL of an object. Built by hand (no SDK call) because it runs for every image on every page.
	 */
	public static function url( string $bucket, string $key ): string {
		$path = implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );

		$cdn = (string) BS3O_Settings::get( 'cdn_url' );
		if ( '' !== $cdn ) {
			return $cdn . '/' . $path;
		}

		$endpoint = (string) BS3O_Settings::get( 'endpoint' );
		if ( '' === $endpoint ) {
			$region   = (string) BS3O_Settings::get( 'region' );
			$endpoint = 'https://s3.' . $region . '.amazonaws.com';
		}

		$parts = wp_parse_url( $endpoint );
		$host  = $parts['host'] ?? '';
		$base  = ( $parts['scheme'] ?? 'https' ) . '://';
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		// Dotted bucket names break TLS on virtual-hosted URLs, so fall back to path style.
		if ( BS3O_Settings::get( 'path_style' ) || str_contains( $bucket, '.' ) ) {
			return $base . $host . $port . '/' . rawurlencode( $bucket ) . '/' . $path;
		}
		return $base . $bucket . '.' . $host . $port . '/' . $path;
	}

	public static function message( \Throwable $e ): string {
		if ( $e instanceof \Aws\Exception\AwsException && $e->getAwsErrorMessage() ) {
			return $e->getAwsErrorCode() . ': ' . $e->getAwsErrorMessage();
		}
		return $e->getMessage();
	}

	public static function log( string $message ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Benzy S3 Offload] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
