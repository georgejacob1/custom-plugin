<?php
/**
 * Field types: definitions, rendering, sanitizing and formatting.
 */

defined( 'ABSPATH' ) || exit;

class BCF_Fields {

	/**
	 * Available field types.
	 *
	 * @return array type => label
	 */
	public static function types() {
		return array(
			'text'       => __( 'Text', 'benzy-custom-fields' ),
			'textarea'   => __( 'Text Area', 'benzy-custom-fields' ),
			'wysiwyg'    => __( 'WYSIWYG Editor', 'benzy-custom-fields' ),
			'number'     => __( 'Number', 'benzy-custom-fields' ),
			'email'      => __( 'Email', 'benzy-custom-fields' ),
			'url'        => __( 'URL', 'benzy-custom-fields' ),
			'select'     => __( 'Select', 'benzy-custom-fields' ),
			'checkbox'   => __( 'Checkbox', 'benzy-custom-fields' ),
			'radio'      => __( 'Radio Buttons', 'benzy-custom-fields' ),
			'true_false' => __( 'True / False', 'benzy-custom-fields' ),
			'date'       => __( 'Date', 'benzy-custom-fields' ),
			'color'      => __( 'Color Picker', 'benzy-custom-fields' ),
			'image'      => __( 'Image', 'benzy-custom-fields' ),
			'gallery'    => __( 'Gallery', 'benzy-custom-fields' ),
			'file'       => __( 'File', 'benzy-custom-fields' ),
			'repeater'   => __( 'Repeater', 'benzy-custom-fields' ),
		);
	}

	/**
	 * Parse "value : Label" lines into an array.
	 *
	 * @param string $raw Choices text.
	 * @return array value => label
	 */
	public static function parse_choices( $raw ) {
		$choices = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( false !== strpos( $line, ':' ) ) {
				list( $value, $label ) = array_map( 'trim', explode( ':', $line, 2 ) );
			} else {
				$value = $line;
				$label = $line;
			}
			if ( '' === $value ) {
				continue;
			}
			$choices[ $value ] = '' === $label ? $value : $label;
		}
		return $choices;
	}

	/**
	 * Sanitize field definitions coming from the builder.
	 *
	 * @param mixed $fields Raw definitions.
	 * @return array
	 */
	public static function sanitize_definitions( $fields ) {
		$out   = array();
		$types = self::types();
		$used  = array();

		if ( ! is_array( $fields ) ) {
			return $out;
		}

		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}

			$type  = isset( $f['type'], $types[ $f['type'] ] ) ? $f['type'] : 'text';
			$label = sanitize_text_field( isset( $f['label'] ) ? $f['label'] : '' );
			$name  = self::sanitize_name( isset( $f['name'] ) && '' !== $f['name'] ? $f['name'] : $label );
			if ( '' === $name ) {
				continue;
			}

			// Keep names unique on the same level.
			$base = $name;
			$n    = 2;
			while ( isset( $used[ $name ] ) ) {
				$name = $base . '_' . $n++;
			}
			$used[ $name ] = true;

			$key = isset( $f['key'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( $f['key'] ) ) : '';
			if ( '' === $key ) {
				$key = 'field_' . strtolower( wp_generate_password( 10, false, false ) );
			}

			$field = array(
				'key'           => $key,
				'label'         => '' === $label ? $name : $label,
				'name'          => $name,
				'type'          => $type,
				'instructions'  => sanitize_textarea_field( isset( $f['instructions'] ) ? $f['instructions'] : '' ),
				'required'      => ! empty( $f['required'] ) ? 1 : 0,
				'default_value' => sanitize_textarea_field( isset( $f['default_value'] ) ? $f['default_value'] : '' ),
				'placeholder'   => sanitize_text_field( isset( $f['placeholder'] ) ? $f['placeholder'] : '' ),
				'choices'       => sanitize_textarea_field( isset( $f['choices'] ) ? $f['choices'] : '' ),
				'min'           => isset( $f['min'] ) && is_numeric( $f['min'] ) ? $f['min'] + 0 : '',
				'max'           => isset( $f['max'] ) && is_numeric( $f['max'] ) ? $f['max'] + 0 : '',
				'button_label'  => sanitize_text_field( isset( $f['button_label'] ) ? $f['button_label'] : '' ),
				'sub_fields'    => array(),
			);

			if ( 'repeater' === $type && isset( $f['sub_fields'] ) ) {
				$field['sub_fields'] = self::sanitize_definitions( $f['sub_fields'] );
			}

			$out[] = $field;
		}

		return $out;
	}

	/**
	 * Turn a label or raw name into a safe meta key.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function sanitize_name( $name ) {
		$name = strtolower( remove_accents( (string) $name ) );
		$name = preg_replace( '/[^a-z0-9_]+/', '_', $name );
		// Leading underscores would hide the value as protected meta.
		return trim( $name, '_' );
	}

	/**
	 * Render a field with its label and instructions.
	 *
	 * @param array  $field Field definition.
	 * @param mixed  $value Stored value, or null when nothing is stored yet.
	 * @param string $name  Input name attribute.
	 * @param string $id    Input id attribute.
	 */
	public static function render_field( $field, $value, $name, $id ) {
		$classes = 'bcf-field bcf-type-' . $field['type'];
		if ( ! empty( $field['required'] ) ) {
			$classes .= ' is-required';
		}

		printf( '<div class="%s" data-name="%s">', esc_attr( $classes ), esc_attr( $field['name'] ) );
		printf(
			'<label class="bcf-label" for="%s">%s%s</label>',
			esc_attr( $id ),
			esc_html( $field['label'] ),
			! empty( $field['required'] ) ? ' <span class="bcf-required">*</span>' : ''
		);
		if ( '' !== $field['instructions'] ) {
			printf( '<p class="description bcf-instructions">%s</p>', nl2br( esc_html( $field['instructions'] ) ) );
		}
		echo '<div class="bcf-input">';
		self::render_input( $field, $value, $name, $id );
		echo '</div></div>';
	}

	/**
	 * Render only the input for a field.
	 */
	public static function render_input( $field, $value, $name, $id ) {
		$type = $field['type'];

		if ( null === $value && 'repeater' !== $type ) {
			$value = $field['default_value'];
		}

		$required = ! empty( $field['required'] ) ? ' data-bcf-required="1"' : '';

		switch ( $type ) {
			case 'text':
			case 'email':
			case 'url':
			case 'number':
			case 'date':
				$extra = '';
				if ( 'number' === $type ) {
					$extra .= ' step="any"';
					$extra .= '' !== $field['min'] ? ' min="' . esc_attr( $field['min'] ) . '"' : '';
					$extra .= '' !== $field['max'] ? ' max="' . esc_attr( $field['max'] ) . '"' : '';
				}
				printf(
					'<input type="%s" class="widefat" id="%s" name="%s" value="%s" placeholder="%s"%s%s />',
					esc_attr( $type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? $value : '' ),
					esc_attr( $field['placeholder'] ),
					$extra, // Already escaped.
					$required
				);
				break;

			case 'textarea':
				printf(
					'<textarea class="widefat" rows="4" id="%s" name="%s" placeholder="%s"%s>%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $field['placeholder'] ),
					$required,
					esc_textarea( is_scalar( $value ) ? $value : '' )
				);
				break;

			case 'wysiwyg':
				// Initialised as a full editor by fields.js (works inside repeaters too).
				printf(
					'<textarea class="bcf-wysiwyg" rows="10" id="%s" name="%s">%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( is_scalar( $value ) ? $value : '' )
				);
				break;

			case 'select':
				printf( '<select class="widefat" id="%s" name="%s"%s>', esc_attr( $id ), esc_attr( $name ), $required );
				printf( '<option value="">%s</option>', esc_html__( '— Select —', 'benzy-custom-fields' ) );
				foreach ( self::parse_choices( $field['choices'] ) as $v => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( (string) $value, (string) $v, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;

			case 'radio':
				echo '<ul class="bcf-choice-list">';
				$i = 0;
				foreach ( self::parse_choices( $field['choices'] ) as $v => $label ) {
					printf(
						'<li><label><input type="radio" id="%s" name="%s" value="%s"%s /> %s</label></li>',
						esc_attr( $id . ( $i ? '-' . $i : '' ) ),
						esc_attr( $name ),
						esc_attr( $v ),
						checked( (string) $value, (string) $v, false ),
						esc_html( $label )
					);
					$i++;
				}
				echo '</ul>';
				break;

			case 'checkbox':
				$values = is_array( $value ) ? array_map( 'strval', $value ) : array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' );
				// Empty hidden input lets "nothing checked" be saved.
				printf( '<input type="hidden" name="%s" value="" />', esc_attr( $name ) );
				echo '<ul class="bcf-choice-list">';
				$i = 0;
				foreach ( self::parse_choices( $field['choices'] ) as $v => $label ) {
					printf(
						'<li><label><input type="checkbox" id="%s" name="%s[]" value="%s"%s /> %s</label></li>',
						esc_attr( $id . ( $i ? '-' . $i : '' ) ),
						esc_attr( $name ),
						esc_attr( $v ),
						checked( in_array( (string) $v, $values, true ), true, false ),
						esc_html( $label )
					);
					$i++;
				}
				echo '</ul>';
				break;

			case 'true_false':
				printf( '<input type="hidden" name="%s" value="0" />', esc_attr( $name ) );
				printf(
					'<label class="bcf-switch"><input type="checkbox" id="%s" name="%s" value="1"%s /> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( 'Yes', 'benzy-custom-fields' ) )
				);
				break;

			case 'color':
				printf(
					'<input type="text" class="bcf-color" id="%s" name="%s" value="%s" data-default-color="%s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? $value : '' ),
					esc_attr( $field['default_value'] )
				);
				break;

			case 'image':
				$img_id  = absint( $value );
				$preview = $img_id ? wp_get_attachment_image( $img_id, 'medium' ) : '';
				printf( '<div class="bcf-media bcf-image%s">', $preview ? ' has-value' : '' );
				printf( '<input type="hidden" class="bcf-value" id="%s" name="%s" value="%s" />', esc_attr( $id ), esc_attr( $name ), $img_id ? esc_attr( $img_id ) : '' );
				echo '<div class="bcf-image-preview">' . $preview . '</div>'; // wp_get_attachment_image() output is escaped.
				printf(
					'<p><button type="button" class="button bcf-image-select">%s</button> <button type="button" class="button-link bcf-media-remove">%s</button></p>',
					esc_html__( 'Select Image', 'benzy-custom-fields' ),
					esc_html__( 'Remove', 'benzy-custom-fields' )
				);
				echo '</div>';
				break;

			case 'gallery':
				$ids = is_array( $value ) ? $value : explode( ',', (string) $value );
				$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
				echo '<div class="bcf-media bcf-gallery">';
				printf( '<input type="hidden" class="bcf-value" id="%s" name="%s" value="%s" />', esc_attr( $id ), esc_attr( $name ), esc_attr( implode( ',', $ids ) ) );
				echo '<ul class="bcf-gallery-items">';
				foreach ( $ids as $att_id ) {
					printf(
						'<li data-id="%d">%s<button type="button" class="bcf-gallery-remove" aria-label="%s">&times;</button></li>',
						$att_id,
						wp_get_attachment_image( $att_id, 'thumbnail' ),
						esc_attr__( 'Remove image', 'benzy-custom-fields' )
					);
				}
				echo '</ul>';
				printf( '<p><button type="button" class="button bcf-gallery-add">%s</button></p>', esc_html__( 'Add Images', 'benzy-custom-fields' ) );
				echo '</div>';
				break;

			case 'file':
				$file_id = absint( $value );
				$file    = $file_id ? get_attached_file( $file_id ) : '';
				printf( '<div class="bcf-media bcf-file%s">', $file_id ? ' has-value' : '' );
				printf( '<input type="hidden" class="bcf-value" id="%s" name="%s" value="%s" />', esc_attr( $id ), esc_attr( $name ), $file_id ? esc_attr( $file_id ) : '' );
				printf(
					'<p class="bcf-file-name"><span class="dashicons dashicons-media-default"></span> <a href="%s" target="_blank" rel="noopener">%s</a></p>',
					esc_url( $file_id ? wp_get_attachment_url( $file_id ) : '' ),
					esc_html( $file ? wp_basename( $file ) : '' )
				);
				printf(
					'<p><button type="button" class="button bcf-file-select">%s</button> <button type="button" class="button-link bcf-media-remove">%s</button></p>',
					esc_html__( 'Select File', 'benzy-custom-fields' ),
					esc_html__( 'Remove', 'benzy-custom-fields' )
				);
				echo '</div>';
				break;

			case 'repeater':
				$rows = is_array( $value ) ? array_values( $value ) : array();
				printf(
					'<div class="bcf-repeater" data-key="%s" data-min="%d" data-max="%d">',
					esc_attr( $field['key'] ),
					(int) $field['min'],
					(int) $field['max']
				);
				// Empty hidden input lets "all rows removed" be saved.
				printf( '<input type="hidden" name="%s" value="" />', esc_attr( $name ) );
				echo '<div class="bcf-rows">';
				foreach ( $rows as $i => $row ) {
					self::render_row( $field, $row, $name, $id, $i );
				}
				echo '</div>';
				// Row template; its placeholder index is never saved (non-numeric).
				echo '<div class="bcf-row-template" hidden>';
				self::render_row( $field, null, $name, $id, '__IDX_' . $field['key'] . '__' );
				echo '</div>';
				printf(
					'<button type="button" class="button bcf-add-row">%s</button>',
					esc_html( '' !== $field['button_label'] ? $field['button_label'] : __( 'Add Row', 'benzy-custom-fields' ) )
				);
				echo '</div>';
				break;
		}
	}

	/**
	 * Render one repeater row.
	 */
	protected static function render_row( $field, $row, $name, $id, $index ) {
		echo '<div class="bcf-row">';
		printf(
			'<div class="bcf-row-handle" title="%s"><span class="bcf-row-index">%s</span></div>',
			esc_attr__( 'Drag to reorder, click to collapse', 'benzy-custom-fields' ),
			is_int( $index ) ? $index + 1 : ''
		);
		echo '<div class="bcf-row-fields">';
		foreach ( $field['sub_fields'] as $sub ) {
			$sub_value = is_array( $row ) && array_key_exists( $sub['name'], $row ) ? $row[ $sub['name'] ] : null;
			self::render_field(
				$sub,
				$sub_value,
				$name . '[' . $index . '][' . $sub['name'] . ']',
				$id . '-' . $index . '-' . $sub['name']
			);
		}
		echo '</div>';
		printf(
			'<div class="bcf-row-actions"><button type="button" class="bcf-remove-row" aria-label="%s">&times;</button></div>',
			esc_attr__( 'Remove row', 'benzy-custom-fields' )
		);
		echo '</div>';
	}

	/**
	 * Sanitize a submitted value (already unslashed).
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function sanitize_value( $field, $value ) {
		$scalar = is_scalar( $value ) ? (string) $value : '';

		switch ( $field['type'] ) {
			case 'text':
				return sanitize_text_field( $scalar );

			case 'textarea':
				return sanitize_textarea_field( $scalar );

			case 'wysiwyg':
				return current_user_can( 'unfiltered_html' ) ? $scalar : wp_kses_post( $scalar );

			case 'number':
				return is_numeric( $scalar ) ? $scalar + 0 : '';

			case 'email':
				return sanitize_email( $scalar );

			case 'url':
				return esc_url_raw( $scalar );

			case 'select':
			case 'radio':
				$allowed = array_map( 'strval', array_keys( self::parse_choices( $field['choices'] ) ) );
				return in_array( $scalar, $allowed, true ) ? $scalar : '';

			case 'checkbox':
				$allowed = array_map( 'strval', array_keys( self::parse_choices( $field['choices'] ) ) );
				$values  = is_array( $value ) ? array_map( 'strval', $value ) : array();
				return array_values( array_intersect( $values, $allowed ) );

			case 'true_false':
				return ! empty( $value ) ? 1 : 0;

			case 'date':
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $scalar ) ? $scalar : '';

			case 'color':
				$color = sanitize_hex_color( $scalar );
				return $color ? $color : '';

			case 'image':
			case 'file':
				$att = absint( $scalar );
				return $att ? $att : '';

			case 'gallery':
				return array_values( array_filter( array_map( 'absint', explode( ',', $scalar ) ) ) );

			case 'repeater':
				$rows = array();
				if ( is_array( $value ) ) {
					foreach ( $value as $index => $row ) {
						// Skips the hidden row template ("__IDX_...__").
						if ( ! is_array( $row ) || ! ctype_digit( (string) $index ) ) {
							continue;
						}
						$clean = array();
						foreach ( $field['sub_fields'] as $sub ) {
							$clean[ $sub['name'] ] = self::sanitize_value( $sub, isset( $row[ $sub['name'] ] ) ? $row[ $sub['name'] ] : '' );
						}
						$rows[] = $clean;
					}
				}
				if ( $field['max'] ) {
					$rows = array_slice( $rows, 0, (int) $field['max'] );
				}
				return $rows;
		}

		return sanitize_text_field( $scalar );
	}

	/**
	 * Format a stored value for use in templates.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Stored value.
	 * @return mixed
	 */
	public static function format_value( $field, $value ) {
		switch ( $field['type'] ) {
			case 'wysiwyg':
				return '' === (string) $value ? '' : do_shortcode( shortcode_unautop( wpautop( wptexturize( $value ) ) ) );

			case 'number':
				return is_numeric( $value ) ? $value + 0 : null;

			case 'true_false':
				return ! empty( $value );

			case 'checkbox':
				return is_array( $value ) ? $value : array();

			case 'image':
				return self::image_array( $value );

			case 'gallery':
				$ids = is_array( $value ) ? $value : explode( ',', (string) $value );
				return array_values( array_filter( array_map( array( __CLASS__, 'image_array' ), $ids ) ) );

			case 'file':
				$att_id = absint( $value );
				if ( ! $att_id || ! get_post( $att_id ) ) {
					return false;
				}
				$path = get_attached_file( $att_id );
				return array(
					'ID'        => $att_id,
					'url'       => wp_get_attachment_url( $att_id ),
					'filename'  => $path ? wp_basename( $path ) : '',
					'title'     => get_the_title( $att_id ),
					'mime_type' => get_post_mime_type( $att_id ),
					'filesize'  => $path && file_exists( $path ) ? filesize( $path ) : 0,
				);

			case 'repeater':
				$rows = array();
				foreach ( is_array( $value ) ? $value : array() as $row ) {
					$formatted = array();
					foreach ( $field['sub_fields'] as $sub ) {
						$formatted[ $sub['name'] ] = self::format_value( $sub, isset( $row[ $sub['name'] ] ) ? $row[ $sub['name'] ] : null );
					}
					$rows[] = $formatted;
				}
				return $rows;
		}

		return $value;
	}

	/**
	 * Attachment ID to an image array.
	 *
	 * @param int $att_id Attachment ID.
	 * @return array|false
	 */
	public static function image_array( $att_id ) {
		$att_id = absint( $att_id );
		if ( ! $att_id || ! wp_attachment_is_image( $att_id ) ) {
			return false;
		}

		$full  = wp_get_attachment_image_src( $att_id, 'full' );
		$sizes = array();
		foreach ( get_intermediate_image_sizes() as $size ) {
			$src = wp_get_attachment_image_src( $att_id, $size );
			if ( $src ) {
				$sizes[ $size ] = $src[0];
			}
		}

		return array(
			'ID'      => $att_id,
			'url'     => $full ? $full[0] : '',
			'width'   => $full ? $full[1] : 0,
			'height'  => $full ? $full[2] : 0,
			'alt'     => get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
			'title'   => get_the_title( $att_id ),
			'caption' => wp_get_attachment_caption( $att_id ),
			'sizes'   => $sizes,
		);
	}
}
