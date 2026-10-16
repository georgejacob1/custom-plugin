<?php
/**
 * Removes the plugin settings. S3 objects and per-attachment offload data are kept on purpose:
 * deleting them would silently break media that only exists in the bucket.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bs3o_settings' );
delete_transient( 'bs3o_notice' );
