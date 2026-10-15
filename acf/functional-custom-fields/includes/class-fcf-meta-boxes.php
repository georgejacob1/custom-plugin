<?php
/**
 * Shows field groups on the post edit screen and saves their values.
 */

defined( 'ABSPATH' ) || exit;

class FCF_Meta_Boxes {

	/** @var bool Whether the nonce was printed already. */
	protected static $nonce_printed = false;

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add( $post_type, $post ) {
		if ( FCF_Field_Groups::POST_TYPE === $post_type || ! $post instanceof WP_Post ) {
			return;
		}

		foreach ( FCF_Field_Groups::groups_for_post( $post ) as $group ) {
			if ( ! $group['fields'] ) {
				continue;
			}
			add_meta_box(
				'fcf-group-' . $group['id'],
				'' !== $group['title'] ? $group['title'] : __( 'Custom Fields', 'functional-custom-fields' ),
				array( __CLASS__, 'render' ),
				$post_type,
				$group['position'],
				'high',
				array( 'group' => $group )
			);
		}
	}

	public static function render( $post, $box ) {
		$group = $box['args']['group'];

		if ( ! self::$nonce_printed ) {
			wp_nonce_field( 'fcf_save_values', 'fcf_values_nonce' );
			self::$nonce_printed = true;
		}

		echo '<div class="fcf-fields">';
		foreach ( $group['fields'] as $field ) {
			$value = metadata_exists( 'post', $post->ID, $field['name'] ) ? get_post_meta( $post->ID, $field['name'], true ) : null;
			FCF_Fields::render_field( $field, $value, 'fcf[' . $field['name'] . ']', 'fcf-' . $field['name'] );
		}
		echo '</div>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['fcf_values_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['fcf_values_nonce'] ), 'fcf_save_values' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$submitted = isset( $_POST['fcf'] ) && is_array( $_POST['fcf'] ) ? wp_unslash( $_POST['fcf'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field type.

		// Template rule is ignored here; only fields actually present in the form are saved.
		foreach ( FCF_Field_Groups::groups_for_post( $post, false ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( ! array_key_exists( $field['name'], $submitted ) ) {
					continue;
				}
				$value = FCF_Fields::sanitize_value( $field, $submitted[ $field['name'] ] );
				update_post_meta( $post_id, $field['name'], wp_slash( $value ) );
			}
		}
	}

	public static function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || FCF_Field_Groups::POST_TYPE === $screen->post_type ) {
			return;
		}

		$used = false;
		foreach ( FCF_Field_Groups::all_groups() as $group ) {
			if ( in_array( $screen->post_type, $group['post_types'], true ) ) {
				$used = true;
				break;
			}
		}
		if ( ! $used ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_editor();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'fcf-fields', FCF_URL . 'assets/css/fields.css', array(), FCF_VERSION );
		wp_enqueue_script( 'fcf-fields', FCF_URL . 'assets/js/fields.js', array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ), FCF_VERSION, true );
		wp_localize_script(
			'fcf-fields',
			'fcfFields',
			array(
				'i18n' => array(
					'selectImage'  => __( 'Select Image', 'functional-custom-fields' ),
					'selectImages' => __( 'Add Images', 'functional-custom-fields' ),
					'selectFile'   => __( 'Select File', 'functional-custom-fields' ),
					'use'          => __( 'Use', 'functional-custom-fields' ),
					/* translators: %d: number of rows */
					'maxRows'      => __( 'Maximum rows reached (%d).', 'functional-custom-fields' ),
					/* translators: %d: number of rows */
					'minRows'      => __( 'Minimum rows required (%d).', 'functional-custom-fields' ),
					'removeRow'    => __( 'Remove this row?', 'functional-custom-fields' ),
				),
			)
		);
	}
}
