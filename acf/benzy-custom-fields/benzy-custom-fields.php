<?php
/**
 * Plugin Name:       Benzy Custom Fields
 * Description:       Build custom field groups (text, textarea, WYSIWYG, image, gallery, file, repeater and more) and attach them to pages, posts and custom post types.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Benzy Infotech
 * License:           GPL-2.0-or-later
 * Text Domain:       benzy-custom-fields
 * Update URI:        false
 */

defined( 'ABSPATH' ) || exit;

define( 'BCF_VERSION', '1.0.0' );
define( 'BCF_FILE', __FILE__ );
define( 'BCF_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCF_URL', plugin_dir_url( __FILE__ ) );

require_once BCF_DIR . 'includes/class-bcf-fields.php';
require_once BCF_DIR . 'includes/class-bcf-field-groups.php';
require_once BCF_DIR . 'includes/class-bcf-meta-boxes.php';
require_once BCF_DIR . 'includes/functions.php';

add_action( 'plugins_loaded', function () {
	BCF_Field_Groups::init();
	BCF_Meta_Boxes::init();
	add_shortcode( 'bcf', 'bcf_shortcode' );
} );
