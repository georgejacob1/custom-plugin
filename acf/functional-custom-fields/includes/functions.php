<?php
/**
 * Template functions for themes.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve a post ID, defaulting to the current post.
 * 'option' / 'options' resolve to 'option' (values of options pages).
 *
 * @param int|string|WP_Post|null $post_id Post.
 * @return int|string
 */
function fcf_resolve_post_id( $post_id = null ) {
	if ( 'option' === $post_id || 'options' === $post_id ) {
		return 'option';
	}
	if ( $post_id instanceof WP_Post ) {
		return $post_id->ID;
	}
	return $post_id ? absint( $post_id ) : (int) get_the_ID();
}

/**
 * Get a field value.
 *
 * Images return an array (ID, url, alt, sizes...), galleries an array of those,
 * files an array (ID, url, filename...), repeaters an array of rows.
 *
 * @param string   $name    Field name.
 * @param int|string|null $post_id Post ID (defaults to the current post), or 'option' for options pages.
 * @param bool            $format  False returns the raw stored value.
 * @return mixed
 */
function fcf_get_field( $name, $post_id = null, $format = true ) {
	$post_id = fcf_resolve_post_id( $post_id );
	if ( ! $post_id ) {
		return null;
	}

	$field = FCF_Field_Groups::find_field( $name, $post_id );
	if ( 'option' === $post_id ) {
		$value = FCF_Options_Pages::get_value( $name );
	} else {
		$value = metadata_exists( 'post', $post_id, $name ) ? get_post_meta( $post_id, $name, true ) : null;
	}

	if ( null === $value && $field && 'repeater' !== $field['type'] && '' !== $field['default_value'] ) {
		$value = $field['default_value'];
	}

	if ( ! $format || ! $field ) {
		return $value;
	}

	return FCF_Fields::format_value( $field, $value );
}

/**
 * Get all field values for a post, or of all options pages with 'option'.
 *
 * @param int|string|null $post_id Post ID or 'option'.
 * @return array name => value
 */
function fcf_get_fields( $post_id = null ) {
	$post_id = fcf_resolve_post_id( $post_id );
	$values  = array();

	if ( 'option' === $post_id ) {
		$groups = FCF_Options_Pages::all_option_groups();
	} else {
		$post   = get_post( $post_id );
		$groups = $post ? FCF_Field_Groups::groups_for_post( $post, false ) : array();
	}

	foreach ( $groups as $group ) {
		foreach ( $group['fields'] as $field ) {
			if ( 'tab' === $field['type'] ) {
				continue;
			}
			$values[ $field['name'] ] = fcf_get_field( $field['name'], $post_id );
		}
	}

	return $values;
}

/**
 * Echo a field value as HTML.
 *
 * @param string   $name    Field name.
 * @param int|null $post_id Post ID.
 */
function fcf_the_field( $name, $post_id = null ) {
	echo fcf_field_html( $name, $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in fcf_field_html().
}

/**
 * A field value rendered as safe HTML.
 *
 * @param string   $name    Field name.
 * @param int|null $post_id Post ID.
 * @param string   $size    Image size for image / gallery fields.
 * @return string
 */
function fcf_field_html( $name, $post_id = null, $size = 'large' ) {
	$post_id = fcf_resolve_post_id( $post_id );
	$field   = FCF_Field_Groups::find_field( $name, $post_id );
	$value   = fcf_get_field( $name, $post_id );
	$type    = $field ? $field['type'] : 'text';

	switch ( $type ) {
		case 'wysiwyg':
			return (string) $value;

		case 'textarea':
			return nl2br( esc_html( $value ) );

		case 'url':
			return esc_url( $value );

		case 'true_false':
			return $value ? '1' : '';

		// Media is rendered from the stored IDs, whatever the field's return format.
		case 'image':
			$att_id = absint( fcf_get_field( $name, $post_id, false ) );
			return $att_id ? wp_get_attachment_image( $att_id, $size ) : '';

		case 'gallery':
			$raw  = fcf_get_field( $name, $post_id, false );
			$html = '';
			foreach ( array_filter( array_map( 'absint', is_array( $raw ) ? $raw : explode( ',', (string) $raw ) ) ) as $att_id ) {
				$html .= wp_get_attachment_image( $att_id, $size );
			}
			return $html;

		case 'file':
			$att_id = absint( fcf_get_field( $name, $post_id, false ) );
			$file   = $att_id ? get_attached_file( $att_id ) : '';
			return $file ? sprintf( '<a href="%s">%s</a>', esc_url( wp_get_attachment_url( $att_id ) ), esc_html( wp_basename( $file ) ) ) : '';

		case 'select':
		case 'radio':
		case 'checkbox':
			$choices = FCF_Fields::parse_choices( $field['choices'] );
			$labels  = array();
			foreach ( (array) $value as $v ) {
				if ( '' !== (string) $v ) {
					$labels[] = isset( $choices[ $v ] ) ? $choices[ $v ] : $v;
				}
			}
			return esc_html( implode( ', ', $labels ) );

		case 'repeater':
			return '';
	}

	return is_scalar( $value ) ? esc_html( $value ) : '';
}

/**
 * Shortcode: [fcf field="name" post_id="123" size="large"], post_id="option" for options pages.
 *
 * @param array $atts Attributes.
 * @return string
 */
function fcf_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'field'   => '',
			'post_id' => 0,
			'size'    => 'large',
		),
		$atts,
		'fcf'
	);

	if ( '' === $atts['field'] ) {
		return '';
	}

	$post_id = in_array( $atts['post_id'], array( 'option', 'options' ), true ) ? 'option' : absint( $atts['post_id'] );

	return fcf_field_html( sanitize_key( $atts['field'] ), $post_id ? $post_id : null, sanitize_key( $atts['size'] ) );
}

/*
 * Repeater loop helpers:
 *
 * if ( fcf_have_rows( 'slides' ) ) :
 *     while ( fcf_have_rows( 'slides' ) ) : fcf_the_row();
 *         fcf_the_sub_field( 'title' );
 *     endwhile;
 * endif;
 */

/**
 * Whether a repeater has (more) rows. Works for nested repeaters too.
 *
 * @param string   $name    Repeater field name (or sub field name inside a row).
 * @param int|null $post_id Post ID.
 * @return bool
 */
function fcf_have_rows( $name, $post_id = null ) {
	global $fcf_rows_stack;
	if ( ! is_array( $fcf_rows_stack ) ) {
		$fcf_rows_stack = array();
	}

	$top     = end( $fcf_rows_stack );
	$post_id = fcf_resolve_post_id( $post_id );

	// Continue the loop that is already running for this field.
	if ( $top && $top['name'] === $name && $top['post_id'] === $post_id ) {
		if ( $top['index'] + 1 < count( $top['rows'] ) ) {
			return true;
		}
		array_pop( $fcf_rows_stack );
		return false;
	}

	// Start a nested loop from the current row, or a new top-level loop.
	if ( $top && $top['index'] >= 0 && isset( $top['rows'][ $top['index'] ][ $name ] ) ) {
		$rows = $top['rows'][ $top['index'] ][ $name ];
	} else {
		$rows = fcf_get_field( $name, $post_id );
	}

	if ( ! is_array( $rows ) || ! $rows ) {
		return false;
	}

	$fcf_rows_stack[] = array(
		'name'    => $name,
		'post_id' => $post_id,
		'rows'    => array_values( $rows ),
		'index'   => -1,
	);

	return true;
}

/**
 * Move to the next row of the current repeater loop.
 *
 * @return array|false The row.
 */
function fcf_the_row() {
	global $fcf_rows_stack;
	if ( empty( $fcf_rows_stack ) ) {
		return false;
	}
	$i = count( $fcf_rows_stack ) - 1;
	$fcf_rows_stack[ $i ]['index']++;
	$row = $fcf_rows_stack[ $i ]['rows'][ $fcf_rows_stack[ $i ]['index'] ] ?? false;
	return $row;
}

/**
 * Get a sub field value of the current row (formatted).
 *
 * @param string $name Sub field name.
 * @return mixed
 */
function fcf_get_sub_field( $name ) {
	global $fcf_rows_stack;
	if ( empty( $fcf_rows_stack ) ) {
		return null;
	}
	$top = end( $fcf_rows_stack );
	return $top['rows'][ $top['index'] ][ $name ] ?? null;
}

/**
 * Echo a sub field value of the current row.
 *
 * @param string $name Sub field name.
 */
function fcf_the_sub_field( $name ) {
	$value = fcf_get_sub_field( $name );
	if ( is_array( $value ) && isset( $value['ID'], $value['sizes'] ) ) {
		echo wp_get_attachment_image( $value['ID'], 'large' );
	} elseif ( is_array( $value ) && isset( $value['url'] ) ) {
		printf( '<a href="%s">%s</a>', esc_url( $value['url'] ), esc_html( $value['filename'] ?? $value['url'] ) );
	} elseif ( is_array( $value ) ) {
		echo esc_html( implode( ', ', array_filter( $value, 'is_scalar' ) ) );
	} elseif ( is_scalar( $value ) ) {
		echo wp_kses_post( $value );
	}
}

/**
 * Register an options page in code (it can also be created in Custom Fields → Options Pages).
 * Call it on or before the 'admin_menu' hook, e.g. from 'init'.
 *
 * fcf_add_options_page( array( 'page_title' => 'Common Content', 'menu_slug' => 'common-content' ) );
 * fcf_get_field( 'footer_text', 'option' );
 *
 * @param array|string $args Page title, or array: page_title, menu_title, menu_slug, parent_slug,
 *                           capability (default edit_posts), position, icon_url.
 * @return array The page.
 */
function fcf_add_options_page( $args = array() ) {
	return FCF_Options_Pages::add( is_string( $args ) ? array( 'page_title' => $args ) : $args );
}

/**
 * Register an options page under another one (default parent: Settings).
 *
 * @param array|string $args Same as fcf_add_options_page().
 * @return array The page.
 */
function fcf_add_options_sub_page( $args = array() ) {
	$args = is_string( $args ) ? array( 'page_title' => $args ) : $args;
	return FCF_Options_Pages::add( wp_parse_args( $args, array( 'parent_slug' => 'options-general.php' ) ) );
}

/**
 * Register a field group in code. Takes the same shape as a JSON export item
 * (Custom Fields → Tools → Generate PHP writes these calls for you).
 * Call it on 'init' or earlier. A group imported with the same key overrides it.
 *
 * @param array $group key, title, fields, location, menu_order, position, description.
 */
function fcf_add_local_field_group( $group ) {
	FCF_Field_Groups::add_local( $group );
}
