<?php
/**
 * Field types: definitions, rendering, sanitizing and formatting.
 */

defined( 'ABSPATH' ) || exit;

class FCF_Fields {

	/**
	 * Available field types.
	 *
	 * @return array type => label
	 */
	public static function types() {
		return array(
			'text'       => __( 'Text', 'functional-custom-fields' ),
			'textarea'   => __( 'Text Area', 'functional-custom-fields' ),
			'wysiwyg'    => __( 'WYSIWYG Editor', 'functional-custom-fields' ),
			'number'     => __( 'Number', 'functional-custom-fields' ),
			'email'      => __( 'Email', 'functional-custom-fields' ),
			'url'        => __( 'URL', 'functional-custom-fields' ),
			'select'     => __( 'Select', 'functional-custom-fields' ),
			'checkbox'   => __( 'Checkbox', 'functional-custom-fields' ),
			'radio'      => __( 'Radio Buttons', 'functional-custom-fields' ),
			'true_false' => __( 'True / False', 'functional-custom-fields' ),
			'date'       => __( 'Date', 'functional-custom-fields' ),
			'color'      => __( 'Color Picker', 'functional-custom-fields' ),
			'image'      => __( 'Image', 'functional-custom-fields' ),
			'gallery'    => __( 'Gallery', 'functional-custom-fields' ),
			'file'       => __( 'File', 'functional-custom-fields' ),
			'repeater'   => __( 'Repeater', 'functional-custom-fields' ),
			'tab'        => __( 'Tab', 'functional-custom-fields' ),
		);
	}

	/**
	 * Every setting a field definition can have, with its default.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'key'               => '',
			'label'             => '',
			'name'              => '',
			'type'              => 'text',
			'instructions'      => '',
			'required'          => 0,
			'default_value'     => '',
			'placeholder'       => '',
			'choices'           => '',
			'min'               => '',
			'max'               => '',
			'button_label'      => '',
			'layout'            => 'table',
			'placement'         => 'top',
			'endpoint'          => 0,
			'maxlength'         => '',
			'prepend'           => '',
			'append'            => '',
			'return_format'     => 'array',
			'library'           => 'all',
			'preview_size'      => 'medium',
			'min_width'         => '',
			'min_height'        => '',
			'min_size'          => '',
			'max_width'         => '',
			'max_height'        => '',
			'max_size'          => '',
			'mime_types'        => '',
			'wrapper_width'     => '',
			'wrapper_class'     => '',
			'wrapper_id'        => '',
			'conditional_logic' => array(),
			'sub_fields'        => array(),
		);
	}

	/**
	 * Conditional logic operators.
	 *
	 * @return array operator => label
	 */
	public static function logic_operators() {
		return array(
			'!=empty'    => __( 'Has any value', 'functional-custom-fields' ),
			'==empty'    => __( 'Has no value', 'functional-custom-fields' ),
			'=='         => __( 'Value is equal to', 'functional-custom-fields' ),
			'!='         => __( 'Value is not equal to', 'functional-custom-fields' ),
			'==pattern'  => __( 'Value matches pattern', 'functional-custom-fields' ),
			'==contains' => __( 'Value contains', 'functional-custom-fields' ),
		);
	}

	/**
	 * Fill missing settings (e.g. on fields saved by older versions).
	 *
	 * @param mixed $fields Stored definitions.
	 * @return array
	 */
	public static function normalize( $fields ) {
		$out = array();
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$field                      = array_merge( self::defaults(), $field );
			$field['sub_fields']        = self::normalize( $field['sub_fields'] );
			$field['conditional_logic'] = is_array( $field['conditional_logic'] ) ? $field['conditional_logic'] : array();
			$out[]                      = $field;
		}
		return $out;
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
			$key = isset( $f['key'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( $f['key'] ) ) : '';
			if ( '' === $key ) {
				$key = 'field_' . strtolower( wp_generate_password( 10, false, false ) );
			}

			if ( 'tab' === $type ) {
				// Tabs only arrange the screen; they store nothing, so their name never clashes with a real field.
				$name = $key;
			} else {
				$name = self::sanitize_name( isset( $f['name'] ) && '' !== $f['name'] ? $f['name'] : $label );
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
				'layout'        => isset( $f['layout'] ) && in_array( $f['layout'], array( 'table', 'block', 'row' ), true ) ? $f['layout'] : 'table',
				'placement'     => isset( $f['placement'] ) && 'left' === $f['placement'] ? 'left' : 'top',
				'endpoint'      => ! empty( $f['endpoint'] ) ? 1 : 0,
				'maxlength'     => self::sanitize_number( $f, 'maxlength', true ),
				'prepend'       => sanitize_text_field( isset( $f['prepend'] ) ? $f['prepend'] : '' ),
				'append'        => sanitize_text_field( isset( $f['append'] ) ? $f['append'] : '' ),
				'return_format' => isset( $f['return_format'] ) && in_array( $f['return_format'], array( 'array', 'url', 'id' ), true ) ? $f['return_format'] : 'array',
				'library'       => isset( $f['library'] ) && 'uploadedTo' === $f['library'] ? 'uploadedTo' : 'all',
				'preview_size'  => isset( $f['preview_size'] ) && '' !== sanitize_key( $f['preview_size'] ) ? sanitize_key( $f['preview_size'] ) : 'medium',
				'min_width'     => self::sanitize_number( $f, 'min_width', true ),
				'min_height'    => self::sanitize_number( $f, 'min_height', true ),
				'min_size'      => self::sanitize_number( $f, 'min_size' ),
				'max_width'     => self::sanitize_number( $f, 'max_width', true ),
				'max_height'    => self::sanitize_number( $f, 'max_height', true ),
				'max_size'      => self::sanitize_number( $f, 'max_size' ),
				'mime_types'    => implode( ', ', self::parse_extensions( isset( $f['mime_types'] ) ? $f['mime_types'] : '' ) ),
				'wrapper_width' => self::sanitize_number( $f, 'wrapper_width' ),
				'wrapper_class' => isset( $f['wrapper_class'] ) ? implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $f['wrapper_class'] ) ) ) ) : '',
				'wrapper_id'    => isset( $f['wrapper_id'] ) ? sanitize_html_class( $f['wrapper_id'] ) : '',
				'conditional_logic' => self::sanitize_logic( isset( $f['conditional_logic'] ) ? $f['conditional_logic'] : array() ),
				'sub_fields'    => array(),
			);

			if ( '' !== $field['wrapper_width'] ) {
				$field['wrapper_width'] = min( 100, max( 1, $field['wrapper_width'] ) );
			}

			if ( 'repeater' === $type && isset( $f['sub_fields'] ) ) {
				$field['sub_fields'] = self::sanitize_definitions( $f['sub_fields'] );
			}

			$out[] = $field;
		}

		return $out;
	}

	/**
	 * A non-negative number setting, or '' when empty / invalid.
	 *
	 * @param array  $f   Raw definition.
	 * @param string $key Setting.
	 * @param bool   $int Whole numbers only.
	 * @return int|float|string
	 */
	protected static function sanitize_number( $f, $key, $int = false ) {
		if ( ! isset( $f[ $key ] ) || ! is_numeric( $f[ $key ] ) || $f[ $key ] < 0 ) {
			return '';
		}
		return $int ? absint( $f[ $key ] ) : $f[ $key ] + 0;
	}

	/**
	 * Conditional logic: OR-groups of AND-rules.
	 *
	 * @param mixed $groups Raw rules.
	 * @return array
	 */
	protected static function sanitize_logic( $groups ) {
		$operators = self::logic_operators();
		$out       = array();

		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			$rules = array();
			foreach ( is_array( $group ) ? $group : array() as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$field    = isset( $rule['field'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( $rule['field'] ) ) : '';
				$operator = isset( $rule['operator'] ) ? (string) $rule['operator'] : '';
				if ( '' === $field || ! isset( $operators[ $operator ] ) ) {
					continue;
				}
				$rules[] = array(
					'field'    => $field,
					'operator' => $operator,
					'value'    => sanitize_text_field( isset( $rule['value'] ) ? $rule['value'] : '' ),
				);
			}
			if ( $rules ) {
				$out[] = $rules;
			}
		}

		return $out;
	}

	/**
	 * "jpg, .PNG ,pdf" to array( 'jpg', 'png', 'pdf' ).
	 *
	 * @param string $raw Comma separated extensions.
	 * @return array
	 */
	public static function parse_extensions( $raw ) {
		$exts = array();
		foreach ( explode( ',', (string) $raw ) as $ext ) {
			$ext = preg_replace( '/[^a-z0-9]/', '', strtolower( $ext ) );
			if ( '' !== $ext ) {
				$exts[] = $ext;
			}
		}
		return array_values( array_unique( $exts ) );
	}

	/**
	 * Whether an attachment meets a media field's restrictions.
	 *
	 * @param array $field  Field definition.
	 * @param int   $att_id Attachment ID.
	 * @return bool
	 */
	public static function attachment_allowed( $field, $att_id ) {
		$path    = get_attached_file( $att_id );
		$allowed = self::parse_extensions( $field['mime_types'] );

		if ( $allowed && ! in_array( strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) ), $allowed, true ) ) {
			return false;
		}

		if ( $path && file_exists( $path ) ) {
			$mb = filesize( $path ) / MB_IN_BYTES;
			if ( ( '' !== $field['min_size'] && $mb < $field['min_size'] ) || ( '' !== $field['max_size'] && $field['max_size'] > 0 && $mb > $field['max_size'] ) ) {
				return false;
			}
		}

		if ( 'file' !== $field['type'] ) {
			$meta = wp_get_attachment_metadata( $att_id );
			$w    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
			$h    = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
			if ( $w && $h && (
				( $field['min_width'] && $w < $field['min_width'] ) ||
				( $field['min_height'] && $h < $field['min_height'] ) ||
				( $field['max_width'] && $w > $field['max_width'] ) ||
				( $field['max_height'] && $h > $field['max_height'] )
			) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Data attributes the media picker uses for library and restrictions.
	 *
	 * @param array $field Field definition.
	 * @return string
	 */
	protected static function media_attrs( $field ) {
		$attrs = array(
			'library'      => 'uploadedTo' === $field['library'] ? 'uploadedTo' : '',
			'preview-size' => 'image' === $field['type'] ? $field['preview_size'] : '',
			'min-width'    => $field['min_width'],
			'min-height'   => $field['min_height'],
			'min-size'     => $field['min_size'],
			'max-width'    => $field['max_width'],
			'max-height'   => $field['max_height'],
			'max-size'     => $field['max_size'],
			'mime-types'   => implode( ',', self::parse_extensions( $field['mime_types'] ) ),
		);

		$html = '';
		foreach ( $attrs as $name => $value ) {
			if ( '' !== (string) $value && '0' !== (string) $value ) {
				$html .= sprintf( ' data-%s="%s"', $name, esc_attr( $value ) );
			}
		}
		return $html;
	}

	/**
	 * Wrap an input with its prepend / append text.
	 *
	 * @param array  $field Field definition.
	 * @param string $input Input HTML (escaped).
	 * @return string
	 */
	protected static function affix( $field, $input ) {
		if ( '' === $field['prepend'] && '' === $field['append'] ) {
			return $input;
		}
		return '<div class="fcf-input-group">'
			. ( '' !== $field['prepend'] ? '<span class="fcf-input-prepend">' . esc_html( $field['prepend'] ) . '</span>' : '' )
			. $input
			. ( '' !== $field['append'] ? '<span class="fcf-input-append">' . esc_html( $field['append'] ) . '</span>' : '' )
			. '</div>';
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
		$field   = array_merge( self::defaults(), $field );

		// A tab is a marker; fields.js turns the markers into tab navigation.
		if ( 'tab' === $field['type'] ) {
			printf(
				'<div class="fcf-field fcf-type-tab" data-key="%s" data-type="tab" data-placement="%s" data-endpoint="%d" data-label="%s"></div>',
				esc_attr( $field['key'] ),
				'left' === $field['placement'] ? 'left' : 'top',
				! empty( $field['endpoint'] ) ? 1 : 0,
				esc_attr( '' !== $field['label'] ? $field['label'] : __( 'Tab', 'functional-custom-fields' ) )
			);
			return;
		}

		$classes = 'fcf-field fcf-type-' . $field['type'];
		if ( ! empty( $field['required'] ) ) {
			$classes .= ' is-required';
		}
		if ( '' !== $field['wrapper_class'] ) {
			$classes .= ' ' . $field['wrapper_class'];
		}

		$attrs = '';
		if ( '' !== $field['wrapper_id'] ) {
			$attrs .= sprintf( ' id="%s"', esc_attr( $field['wrapper_id'] ) );
		}
		if ( '' !== $field['wrapper_width'] ) {
			$attrs .= sprintf( ' style="width:%s%%"', esc_attr( $field['wrapper_width'] ) );
		}
		if ( $field['conditional_logic'] ) {
			$attrs .= sprintf( ' data-conditions="%s"', esc_attr( wp_json_encode( $field['conditional_logic'] ) ) );
		}

		printf(
			'<div class="%s" data-name="%s" data-key="%s" data-type="%s"%s>',
			esc_attr( $classes ),
			esc_attr( $field['name'] ),
			esc_attr( $field['key'] ),
			esc_attr( $field['type'] ),
			$attrs // Already escaped.
		);
		printf(
			'<label class="fcf-label" for="%s">%s%s</label>',
			esc_attr( $id ),
			esc_html( $field['label'] ),
			! empty( $field['required'] ) ? ' <span class="fcf-required">*</span>' : ''
		);
		if ( '' !== $field['instructions'] ) {
			printf( '<p class="description fcf-instructions">%s</p>', nl2br( esc_html( $field['instructions'] ) ) );
		}
		echo '<div class="fcf-input">';
		self::render_input( $field, $value, $name, $id );
		echo '</div></div>';
	}

	/**
	 * Render only the input for a field.
	 */
	public static function render_input( $field, $value, $name, $id ) {
		$field = array_merge( self::defaults(), $field );
		$type  = $field['type'];

		if ( null === $value && 'repeater' !== $type ) {
			$value = $field['default_value'];
		}

		$required = ! empty( $field['required'] ) ? ' data-fcf-required="1"' : '';

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
				if ( 'text' === $type && $field['maxlength'] ) {
					$extra .= ' maxlength="' . esc_attr( $field['maxlength'] ) . '"';
				}
				$input = sprintf(
					'<input type="%s" class="widefat" id="%s" name="%s" value="%s" placeholder="%s"%s%s />',
					esc_attr( $type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? $value : '' ),
					esc_attr( $field['placeholder'] ),
					$extra, // Already escaped.
					$required
				);
				echo 'date' === $type ? $input : self::affix( $field, $input ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
				break;

			case 'textarea':
				printf(
					'<textarea class="widefat" rows="4" id="%s" name="%s" placeholder="%s"%s%s>%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $field['placeholder'] ),
					$field['maxlength'] ? ' maxlength="' . esc_attr( $field['maxlength'] ) . '"' : '',
					$required,
					esc_textarea( is_scalar( $value ) ? $value : '' )
				);
				break;

			case 'wysiwyg':
				// Initialised as a full editor by fields.js (works inside repeaters too).
				printf(
					'<textarea class="fcf-wysiwyg" rows="10" id="%s" name="%s">%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( is_scalar( $value ) ? $value : '' )
				);
				break;

			case 'select':
				printf( '<select class="widefat" id="%s" name="%s"%s>', esc_attr( $id ), esc_attr( $name ), $required );
				printf( '<option value="">%s</option>', esc_html__( '— Select —', 'functional-custom-fields' ) );
				foreach ( self::parse_choices( $field['choices'] ) as $v => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( (string) $value, (string) $v, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;

			case 'radio':
				echo '<ul class="fcf-choice-list">';
				$i = 0;
				foreach ( self::parse_choices( $field['choices'] ) as $v => $label ) {
					printf(
						'<li><label><input type="radio" id="%s" name="%s" value="%s"%s%s /> %s</label></li>',
						esc_attr( $id . ( $i ? '-' . $i : '' ) ),
						esc_attr( $name ),
						esc_attr( $v ),
						checked( (string) $value, (string) $v, false ),
						$required,
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
				echo '<ul class="fcf-choice-list">';
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
					'<label class="fcf-switch"><input type="checkbox" id="%s" name="%s" value="1"%s%s /> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					$required,
					esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( 'Yes', 'functional-custom-fields' ) )
				);
				break;

			case 'color':
				printf(
					'<input type="text" class="fcf-color" id="%s" name="%s" value="%s" data-default-color="%s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? $value : '' ),
					esc_attr( $field['default_value'] )
				);
				break;

			case 'image':
				$img_id  = absint( $value );
				$preview = $img_id ? wp_get_attachment_image( $img_id, $field['preview_size'] ) : '';
				printf( '<div class="fcf-media fcf-image%s"%s>', $preview ? ' has-value' : '', self::media_attrs( $field ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in media_attrs().
				printf( '<input type="hidden" class="fcf-value" id="%s" name="%s" value="%s" />', esc_attr( $id ), esc_attr( $name ), $img_id ? esc_attr( $img_id ) : '' );
				echo '<div class="fcf-image-preview">' . $preview . '</div>'; // wp_get_attachment_image() output is escaped.
				printf(
					'<span class="fcf-media-empty"><span class="dashicons dashicons-format-image" aria-hidden="true"></span>%s</span>',
					esc_html__( 'No image selected', 'functional-custom-fields' )
				);
				printf(
					'<p class="fcf-media-actions"><button type="button" class="button fcf-image-select">%s</button> <button type="button" class="button-link fcf-media-remove">%s</button></p>',
					esc_html__( 'Select Image', 'functional-custom-fields' ),
					esc_html__( 'Remove', 'functional-custom-fields' )
				);
				echo '</div>';
				break;

			case 'gallery':
				$ids = is_array( $value ) ? $value : explode( ',', (string) $value );
				$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
				echo '<div class="fcf-media fcf-gallery"' . self::media_attrs( $field ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in media_attrs().
				printf( '<input type="hidden" class="fcf-value" id="%s" name="%s" value="%s" />', esc_attr( $id ), esc_attr( $name ), esc_attr( implode( ',', $ids ) ) );
				echo '<ul class="fcf-gallery-items">';
				foreach ( $ids as $att_id ) {
					printf(
						'<li data-id="%d">%s<button type="button" class="fcf-gallery-remove" aria-label="%s">&times;</button></li>',
						$att_id,
						wp_get_attachment_image( $att_id, 'thumbnail' ),
						esc_attr__( 'Remove image', 'functional-custom-fields' )
					);
				}
				echo '</ul>';
				printf( '<p><button type="button" class="button fcf-gallery-add">%s</button></p>', esc_html__( 'Add Images', 'functional-custom-fields' ) );
				echo '</div>';
				break;

			case 'file':
				$file_id = absint( $value );
				$file    = $file_id ? get_attached_file( $file_id ) : '';
				printf( '<div class="fcf-media fcf-file%s"%s>', $file_id ? ' has-value' : '', self::media_attrs( $field ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in media_attrs().
				printf( '<input type="hidden" class="fcf-value" id="%s" name="%s" value="%s" />', esc_attr( $id ), esc_attr( $name ), $file_id ? esc_attr( $file_id ) : '' );
				printf(
					'<p class="fcf-file-name"><span class="dashicons dashicons-media-default"></span> <a href="%s" target="_blank" rel="noopener">%s</a></p>',
					esc_url( $file_id ? wp_get_attachment_url( $file_id ) : '' ),
					esc_html( $file ? wp_basename( $file ) : '' )
				);
				printf(
					'<p><button type="button" class="button fcf-file-select">%s</button> <button type="button" class="button-link fcf-media-remove">%s</button></p>',
					esc_html__( 'Select File', 'functional-custom-fields' ),
					esc_html__( 'Remove', 'functional-custom-fields' )
				);
				echo '</div>';
				break;

			case 'repeater':
				$rows = is_array( $value ) ? array_values( $value ) : array();
				printf(
					'<div class="fcf-repeater fcf-layout-%s" data-key="%s" data-min="%d" data-max="%d">',
					esc_attr( in_array( $field['layout'], array( 'table', 'block', 'row' ), true ) ? $field['layout'] : 'table' ),
					esc_attr( $field['key'] ),
					(int) $field['min'],
					(int) $field['max']
				);
				// Empty hidden input lets "all rows removed" be saved.
				printf( '<input type="hidden" name="%s" value="" />', esc_attr( $name ) );
				echo '<div class="fcf-repeater-table">';
				if ( 'table' === $field['layout'] || ! in_array( $field['layout'], array( 'block', 'row' ), true ) ) {
					self::render_row_head( $field );
				}
				echo '<div class="fcf-rows">';
				foreach ( $rows as $i => $row ) {
					self::render_row( $field, $row, $name, $id, $i );
				}
				echo '</div>';
				printf( '<div class="fcf-rows-empty">%s</div>', esc_html__( 'No rows yet. Click "Add Row" to add the first one.', 'functional-custom-fields' ) );
				echo '</div>';
				// Row template; its placeholder index is never saved (non-numeric).
				echo '<div class="fcf-row-template" hidden>';
				self::render_row( $field, null, $name, $id, '__IDX_' . $field['key'] . '__' );
				echo '</div>';
				printf(
					'<div class="fcf-repeater-footer"><button type="button" class="button button-primary fcf-add-row"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>%s</button></div>',
					esc_html( '' !== $field['button_label'] ? $field['button_label'] : __( 'Add Row', 'functional-custom-fields' ) )
				);
				echo '</div>';
				break;
		}
	}

	/**
	 * Table header of a repeater: one column per sub field.
	 */
	protected static function render_row_head( $field ) {
		echo '<div class="fcf-row-head" aria-hidden="true"><div class="fcf-row-handle"></div><div class="fcf-row-fields">';
		foreach ( $field['sub_fields'] as $sub ) {
			$sub = array_merge( self::defaults(), $sub );
			if ( 'tab' === $sub['type'] ) {
				continue;
			}
			printf(
				'<div class="fcf-th"%s>%s%s',
				'' !== $sub['wrapper_width'] ? sprintf( ' style="width:%s%%"', esc_attr( $sub['wrapper_width'] ) ) : '',
				esc_html( $sub['label'] ),
				! empty( $sub['required'] ) ? ' <span class="fcf-required">*</span>' : ''
			);
			if ( '' !== $sub['instructions'] ) {
				printf( '<p class="description">%s</p>', nl2br( esc_html( $sub['instructions'] ) ) );
			}
			echo '</div>';
		}
		echo '</div><div class="fcf-row-actions"></div></div>';
	}

	/**
	 * Render one repeater row.
	 */
	protected static function render_row( $field, $row, $name, $id, $index ) {
		echo '<div class="fcf-row">';
		printf(
			'<div class="fcf-row-handle" title="%s"><span class="fcf-row-index">%s</span></div>',
			esc_attr__( 'Drag to reorder, click to collapse', 'functional-custom-fields' ),
			is_int( $index ) ? $index + 1 : ''
		);
		echo '<div class="fcf-row-fields">';
		$is_table = ! in_array( $field['layout'], array( 'block', 'row' ), true );
		foreach ( $field['sub_fields'] as $sub ) {
			// Table columns cannot be split into tabs.
			if ( $is_table && 'tab' === $sub['type'] ) {
				continue;
			}
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
			'<div class="fcf-row-actions"><button type="button" class="fcf-remove-row" aria-label="%s">&times;</button></div>',
			esc_attr__( 'Remove row', 'functional-custom-fields' )
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
		$field  = array_merge( self::defaults(), $field );
		$scalar = is_scalar( $value ) ? (string) $value : '';

		switch ( $field['type'] ) {
			case 'text':
			case 'textarea':
				$clean = 'text' === $field['type'] ? sanitize_text_field( $scalar ) : sanitize_textarea_field( $scalar );
				return $field['maxlength'] ? mb_substr( $clean, 0, (int) $field['maxlength'] ) : $clean;

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
				return $att && self::attachment_allowed( $field, $att ) ? $att : '';

			case 'gallery':
				$ids = array_filter( array_map( 'absint', explode( ',', $scalar ) ) );
				$ids = array_filter(
					$ids,
					function ( $att ) use ( $field ) {
						return self::attachment_allowed( $field, $att );
					}
				);
				return array_values( $ids );

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
							if ( 'tab' === $sub['type'] ) {
								continue;
							}
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
		$field = array_merge( self::defaults(), $field );

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
			case 'file':
				return self::media_value( $field, $value );

			case 'gallery':
				$ids = is_array( $value ) ? $value : explode( ',', (string) $value );
				$out = array();
				foreach ( $ids as $att_id ) {
					$item = self::media_value( $field, $att_id );
					if ( $item ) {
						$out[] = $item;
					}
				}
				return $out;

			case 'repeater':
				$rows = array();
				foreach ( is_array( $value ) ? $value : array() as $row ) {
					$formatted = array();
					foreach ( $field['sub_fields'] as $sub ) {
						if ( 'tab' === $sub['type'] ) {
							continue;
						}
						$formatted[ $sub['name'] ] = self::format_value( $sub, isset( $row[ $sub['name'] ] ) ? $row[ $sub['name'] ] : null );
					}
					$rows[] = $formatted;
				}
				return $rows;
		}

		return $value;
	}

	/**
	 * An attachment in the field's return format (array, url or id).
	 *
	 * @param array $field  Field definition.
	 * @param mixed $att_id Attachment ID.
	 * @return array|string|int|false
	 */
	protected static function media_value( $field, $att_id ) {
		$att_id = absint( $att_id );
		if ( ! $att_id || ! get_post( $att_id ) ) {
			return false;
		}
		if ( 'id' === $field['return_format'] ) {
			return $att_id;
		}
		if ( 'url' === $field['return_format'] ) {
			return wp_get_attachment_url( $att_id );
		}
		return 'file' === $field['type'] ? self::file_array( $att_id ) : self::image_array( $att_id );
	}

	/**
	 * Attachment ID to a file array.
	 *
	 * @param int $att_id Attachment ID.
	 * @return array
	 */
	public static function file_array( $att_id ) {
		$path = get_attached_file( $att_id );
		return array(
			'ID'        => $att_id,
			'url'       => wp_get_attachment_url( $att_id ),
			'filename'  => $path ? wp_basename( $path ) : '',
			'title'     => get_the_title( $att_id ),
			'mime_type' => get_post_mime_type( $att_id ),
			'filesize'  => $path && file_exists( $path ) ? filesize( $path ) : 0,
		);
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
