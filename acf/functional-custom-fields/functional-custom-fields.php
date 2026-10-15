<?php
/**
 * Plugin Name:       Functional Custom Fields
 * Description:       Build custom field groups (text, textarea, WYSIWYG, image, gallery, file, repeater and more) and attach them to pages, posts and custom post types.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Benzy Infotech
 * License:           GPL-2.0-or-later
 * Text Domain:       functional-custom-fields
 * Update URI:        false
 */

defined( 'ABSPATH' ) || exit;

define( 'FCF_VERSION', '1.0.0' );
define( 'FCF_FILE', __FILE__ );
define( 'FCF_DIR', plugin_dir_path( __FILE__ ) );
define( 'FCF_URL', plugin_dir_url( __FILE__ ) );

require_once FCF_DIR . 'includes/class-fcf-fields.php';
require_once FCF_DIR . 'includes/class-fcf-field-groups.php';
require_once FCF_DIR . 'includes/class-fcf-meta-boxes.php';
require_once FCF_DIR . 'includes/functions.php';

add_action( 'plugins_loaded', function () {
	FCF_Field_Groups::init();
	FCF_Meta_Boxes::init();
	add_shortcode( 'fcf', 'fcf_shortcode' );
} );
