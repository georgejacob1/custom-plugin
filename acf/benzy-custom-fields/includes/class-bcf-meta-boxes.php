<?php
/**
 * Shows field groups on the post edit screen and saves their values.
 */

defined( 'ABSPATH' ) || exit;

class BCF_Meta_Boxes {

	/** @var bool Whether the nonce was printed already. */
	protected static $nonce_printed = false;

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add( $post_type, $post ) {
		if ( BCF_Field_Groups::POST_TYPE === $post_type || ! $post instanceof WP_Post ) {
			return;
		}

		foreach ( BCF_Field_Groups::groups_for_post( $post ) as $group ) {
			if ( ! $group['fields'] ) {
				continue;
			}
			add_meta_box(
				'bcf-group-' . $group['id'],
				'' !== $group['title'] ? $group['title'] : __( 'Custom Fields', 'benzy-custom-fields' ),
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
			wp_nonce_field( 'bcf_save_values', 'bcf_values_nonce' );
			self::$nonce_printed = true;
		}

		echo '<div class="bcf-fields">';
		foreach ( $group['fields'] as $field ) {
			$value = metadata_exists( 'post', $post->ID, $field['name'] ) ? get_post_meta( $post->ID, $field['name'], true ) : null;
			BCF_Fields::render_field( $field, $value, 'bcf[' . $field['name'] . ']', 'bcf-' . $field['name'] );
		}
		echo '</div>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['bcf_values_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bcf_values_nonce'] ), 'bcf_save_values' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$submitted = isset( $_POST['bcf'] ) && is_array( $_POST['bcf'] ) ? wp_unslash( $_POST['bcf'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field type.

		// Template rule is ignored here; only fields actually present in the form are saved.
		foreach ( BCF_Field_Groups::groups_for_post( $post, false ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( ! array_key_exists( $field['name'], $submitted ) ) {
					continue;
				}
				$value = BCF_Fields::sanitize_value( $field, $submitted[ $field['name'] ] );
				update_post_meta( $post_id, $field['name'], wp_slash( $value ) );
			}
		}
	}

	public static function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || BCF_Field_Groups::POST_TYPE === $screen->post_type ) {
			return;
		}

		$used = false;
		foreach ( BCF_Field_Groups::all_groups() as $group ) {
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
		wp_enqueue_style( 'bcf-fields', BCF_URL . 'assets/css/fields.css', array(), BCF_VERSION );
		wp_enqueue_script( 'bcf-fields', BCF_URL . 'assets/js/fields.js', array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ), BCF_VERSION, true );
		wp_localize_script(
			'bcf-fields',
			'bcfFields',
			array(
				'i18n' => array(
					'selectImage'  => __( 'Select Image', 'benzy-custom-fields' ),
					'selectImages' => __( 'Add Images', 'benzy-custom-fields' ),
					'selectFile'   => __( 'Select File', 'benzy-custom-fields' ),
					'use'          => __( 'Use', 'benzy-custom-fields' ),
					/* translators: %d: number of rows */
					'maxRows'      => __( 'Maximum rows reached (%d).', 'benzy-custom-fields' ),
					/* translators: %d: number of rows */
					'minRows'      => __( 'Minimum rows required (%d).', 'benzy-custom-fields' ),
					'removeRow'    => __( 'Remove this row?', 'benzy-custom-fields' ),
				),
			)
		);
	}
}
