<?php
/**
 * Forms, entries and uploaded files are kept by default so a reinstall picks them up again.
 * Add define( 'FFM_DELETE_DATA', true ); to wp-config.php before deleting the plugin to remove everything.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'FFM_DELETE_DATA' ) || ! FFM_DELETE_DATA ) {
	return;
}

global $wpdb;

$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('ffm_form', 'ffm_entry')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $ids as $id ) {
	wp_delete_post( (int) $id, true );
}

$upload = wp_upload_dir( null, false );
$dir    = $upload['basedir'] . '/functional-forms';
if ( is_dir( $dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
