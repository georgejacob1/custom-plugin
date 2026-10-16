<?php
/**
 * Plugin Name:       Benzy S3 Media Offload
 * Description:       Uploads the existing Media Library and every new upload (original + all image sizes) to an Amazon S3 (or S3-compatible) bucket, serves media from S3/CDN and removes the local copies.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Benzy Infotech
 * License:           GPL-2.0-or-later
 * Text Domain:       benzy-s3-offload
 * Update URI:        false
 */

defined( 'ABSPATH' ) || exit;

define( 'BS3O_VERSION', '1.0.0' );
define( 'BS3O_FILE', __FILE__ );
define( 'BS3O_DIR', plugin_dir_path( __FILE__ ) );
define( 'BS3O_URL', plugin_dir_url( __FILE__ ) );

/**
 * Show an admin notice and stop loading when the environment can't run the plugin.
 */
function bs3o_fail( string $message ): void {
	add_action( 'admin_notices', function () use ( $message ) {
		echo '<div class="notice notice-error"><p><strong>Benzy S3 Media Offload:</strong> ' . esc_html( $message ) . '</p></div>';
	} );
}

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	bs3o_fail( sprintf( 'PHP 8.1 or newer is required. This server runs PHP %s.', PHP_VERSION ) );
	return;
}

// Another plugin may already ship the AWS SDK; only load ours when it isn't present.
if ( ! class_exists( 'Aws\S3\S3Client' ) && is_readable( BS3O_DIR . 'vendor/autoload.php' ) ) {
	require_once BS3O_DIR . 'vendor/autoload.php';
}

if ( ! class_exists( 'Aws\S3\S3Client' ) ) {
	bs3o_fail( 'The AWS SDK is missing. Run "composer install --no-dev" in the plugin folder or install the release zip.' );
	return;
}

require_once BS3O_DIR . 'includes/class-bs3o-settings.php';
require_once BS3O_DIR . 'includes/class-bs3o-client.php';
require_once BS3O_DIR . 'includes/class-bs3o-uploader.php';
require_once BS3O_DIR . 'includes/class-bs3o-hooks.php';
require_once BS3O_DIR . 'includes/class-bs3o-bulk.php';

add_action( 'plugins_loaded', function () {
	BS3O_Settings::init();
	BS3O_Hooks::init();
	BS3O_Bulk::init();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once BS3O_DIR . 'includes/class-bs3o-cli.php';
		WP_CLI::add_command( 'bs3o', 'BS3O_CLI' );
	}
} );
