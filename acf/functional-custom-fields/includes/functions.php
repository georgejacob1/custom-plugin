<?php
/**
 * Template functions for themes.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve a post ID, defaulting to the current post.
 *
 * @param int|WP_Post|null $post_id Post.
 * @return int
 */
function fcf_resolve_post_id( $post_id = null ) {
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
 * @param int|null $post_id Post ID, defaults to the current post.
 * @param bool     $format  False returns the raw stored value.
 * @return mixed
 */
function fcf_get_field( $name, $post_id = null, $format = true ) {
	$post_id = fcf_resolve_post_id( $post_id );
	if ( ! $post_id ) {
		return null;
	}

	$field = FCF_Field_Groups::find_field( $name, $post_id );
	$value = metadata_exists( 'post', $post_id, $name ) ? get_post_meta( $post_id, $name, true ) : null;

	if ( null === $value && $field && 'repeater' !== $field['type'] && '' !== $field['default_value'] ) {
		$value = $field['default_value'];
	}

	if ( ! $format || ! $field ) {
		return $value;
	}

	return FCF_Fields::format_value( $field, $value );
}

/**
 * Get all field values for a post.
 *
 * @param int|null $post_id Post ID.
 * @return array name => value
 */
function fcf_get_fields( $post_id = null ) {
	$post_id = fcf_resolve_post_id( $post_id );
	$post    = get_post( $post_id );
	$values  = array();

	if ( ! $post ) {
		return $values;
	}

	foreach ( FCF_Field_Groups::groups_for_post( $post, false ) as $group ) {
		foreach ( $group['fields'] as $field ) {
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

		case 'image':
			return $value ? wp_get_attachment_image( $value['ID'], $size ) : '';

		case 'gallery':
			$html = '';
			foreach ( (array) $value as $image ) {
				$html .= wp_get_attachment_image( $image['ID'], $size );
			}
			return $html;

		case 'file':
			return $value ? sprintf( '<a href="%s">%s</a>', esc_url( $value['url'] ), esc_html( $value['filename'] ) ) : '';

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
 * Shortcode: [fcf field="name" post_id="123" size="large"]
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

	return fcf_field_html( sanitize_key( $atts['field'] ), $atts['post_id'] ? absint( $atts['post_id'] ) : null, sanitize_key( $atts['size'] ) );
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
