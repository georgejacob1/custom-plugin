<?php
/**
 * Plugin Name:       Functional Forms
 * Description:       Drag & drop form builder: text, email, phone, choices, file uploads and more. Stores entries in wp-admin and sends email notifications.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Benzy Infotech
 * License:           GPL-2.0-or-later
 * Text Domain:       functional-forms
 * Update URI:        false
 */

defined( 'ABSPATH' ) || exit;

define( 'FFM_VERSION', '1.0.0' );
define( 'FFM_FILE', __FILE__ );
define( 'FFM_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFM_URL', plugin_dir_url( __FILE__ ) );

require_once FFM_DIR . 'includes/class-ffm-fields.php';
require_once FFM_DIR . 'includes/class-ffm-forms.php';
require_once FFM_DIR . 'includes/class-ffm-render.php';
require_once FFM_DIR . 'includes/class-ffm-submission.php';
require_once FFM_DIR . 'includes/class-ffm-entries.php';

add_action( 'plugins_loaded', function () {
	FFM_Forms::init();
	FFM_Render::init();
	FFM_Submission::init();
	FFM_Entries::init();
} );
