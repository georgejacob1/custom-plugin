<?php
/**
 * Field types: definitions, front-end rendering and value validation.
 */

defined( 'ABSPATH' ) || exit;

class FFM_Fields {

	/**
	 * Field types available in the builder, keyed by type.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'text'     => __( 'Single Line Text', 'functional-forms' ),
			'textarea' => __( 'Paragraph Text', 'functional-forms' ),
			'email'    => __( 'Email', 'functional-forms' ),
			'phone'    => __( 'Phone', 'functional-forms' ),
			'number'   => __( 'Number', 'functional-forms' ),
			'url'      => __( 'Website / URL', 'functional-forms' ),
			'date'     => __( 'Date', 'functional-forms' ),
			'select'   => __( 'Dropdown', 'functional-forms' ),
			'radio'    => __( 'Radio Buttons', 'functional-forms' ),
			'checkbox' => __( 'Checkboxes', 'functional-forms' ),
			'file'     => __( 'File Upload', 'functional-forms' ),
			'consent'  => __( 'Consent / Terms', 'functional-forms' ),
			'hidden'   => __( 'Hidden Field', 'functional-forms' ),
			'html'     => __( 'HTML / Content', 'functional-forms' ),
		);
	}

	public static function defaults() {
		return array(
			'key'         => '',
			'type'        => 'text',
			'label'       => '',
			'name'        => '',
			'placeholder' => '',
			'help'        => '',
			'required'    => 0,
			'default'     => '',
			'choices'     => '',
			'width'       => 'full',
			'min'         => '',
			'max'         => '',
			'rows'        => 4,
			'allowed'     => 'jpg,jpeg,png,gif,pdf,doc,docx',
			'max_size'    => 5,
			'max_files'   => 1,
			'content'     => '',
		);
	}

	/**
	 * Clean field definitions coming from the builder.
	 *
	 * @param mixed $raw Decoded builder JSON.
	 * @return array
	 */
	public static function sanitize_definitions( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$types = self::types();
		$names = array();
		$out   = array();

		foreach ( $raw as $f ) {
			if ( ! is_array( $f ) || empty( $f['type'] ) || ! isset( $types[ $f['type'] ] ) ) {
				continue;
			}
			$f = wp_parse_args( $f, self::defaults() );

			$key = sanitize_key( $f['key'] );
			if ( '' === $key ) {
				$key = 'field_' . strtolower( wp_generate_password( 10, false, false ) );
			}

			$clean = array(
				'key'         => $key,
				'type'        => $f['type'],
				'label'       => sanitize_text_field( $f['label'] ),
				'name'        => sanitize_key( $f['name'] ),
				'placeholder' => sanitize_text_field( $f['placeholder'] ),
				'help'        => sanitize_text_field( $f['help'] ),
				'required'    => empty( $f['required'] ) ? 0 : 1,
				'default'     => sanitize_textarea_field( $f['default'] ),
				'choices'     => sanitize_textarea_field( $f['choices'] ),
				'width'       => 'half' === $f['width'] ? 'half' : 'full',
				'min'         => is_numeric( $f['min'] ) ? (string) $f['min'] : '',
				'max'         => is_numeric( $f['max'] ) ? (string) $f['max'] : '',
				'rows'        => max( 2, min( 20, absint( $f['rows'] ) ) ),
				'allowed'     => trim( preg_replace( '/[^a-z0-9,]+/', '', strtolower( (string) $f['allowed'] ) ), ',' ),
				'max_size'    => is_numeric( $f['max_size'] ) && $f['max_size'] > 0 ? (float) $f['max_size'] : 5,
				'max_files'   => max( 1, min( 20, absint( $f['max_files'] ) ) ),
				'content'     => wp_kses_post( $f['content'] ),
			);

			if ( 'html' === $clean['type'] ) {
				$clean['name'] = $key;
			} else {
				if ( '' === $clean['name'] ) {
					$clean['name'] = sanitize_key( str_replace( array( ' ', '-' ), '_', remove_accents( $clean['label'] ) ) );
				}
				if ( '' === $clean['name'] ) {
					$clean['name'] = 'field';
				}
				$base = $clean['name'];
				$i    = 2;
				while ( isset( $names[ $clean['name'] ] ) ) {
					$clean['name'] = $base . '_' . $i++;
				}
				$names[ $clean['name'] ] = true;
			}

			$out[] = $clean;
		}

		return $out;
	}

	/**
	 * Whether the field collects a value.
	 */
	public static function is_input( $f ) {
		return 'html' !== $f['type'];
	}

	/**
	 * Parse "value : label" lines into an array.
	 *
	 * @return array value => label
	 */
	public static function choices( $f ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $f['choices'] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( false !== strpos( $line, ' : ' ) ) {
				list( $value, $label ) = array_map( 'trim', explode( ' : ', $line, 2 ) );
			} else {
				$value = $line;
				$label = $line;
			}
			$out[ (string) $value ] = $label;
		}
		return $out;
	}

	/**
	 * File extensions a file field accepts (limited to what WordPress allows).
	 *
	 * @return string[]
	 */
	public static function allowed_extensions( $f ) {
		$wanted = array_filter( explode( ',', (string) $f['allowed'] ) );
		$wp     = array();
		foreach ( array_keys( get_allowed_mime_types() ) as $exts ) {
			$wp = array_merge( $wp, explode( '|', $exts ) );
		}
		return array_values( array_intersect( $wanted, $wp ) );
	}

	/**
	 * Max upload size for a file field, in bytes.
	 */
	public static function max_bytes( $f ) {
		return (int) min( $f['max_size'] * MB_IN_BYTES, wp_max_upload_size() );
	}

	/**
	 * Echo one field's front-end markup.
	 *
	 * @param array  $f       Field definition.
	 * @param int    $form_id Form ID.
	 * @param mixed  $value   Current value (default or previous input).
	 * @param string $error   Error message, if any.
	 */
	public static function render( $f, $form_id, $value, $error = '' ) {
		$type = $f['type'];
		$id   = 'ffm-' . $form_id . '-' . $f['name'];
		$name = 'ffm[' . $f['name'] . ']';
		$req  = $f['required'] ? ' required aria-required="true"' : '';

		if ( 'hidden' === $type ) {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( (string) $value ) );
			return;
		}

		$classes = array( 'ffm-field', 'ffm-type-' . $type, 'ffm-w-' . $f['width'] );
		if ( $f['required'] ) {
			$classes[] = 'ffm-required';
		}
		if ( $error ) {
			$classes[] = 'ffm-has-error';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-name="' . esc_attr( $f['name'] ) . '">';

		if ( 'html' === $type ) {
			echo '<div class="ffm-html">' . wp_kses_post( $f['content'] ) . '</div></div>';
			return;
		}

		$grouped = in_array( $type, array( 'radio', 'checkbox' ), true );

		if ( $f['label'] && 'consent' !== $type ) {
			if ( $grouped ) {
				echo '<span class="ffm-label" id="' . esc_attr( $id ) . '-label">';
			} else {
				echo '<label class="ffm-label" for="' . esc_attr( $id ) . '">';
			}
			echo esc_html( $f['label'] );
			if ( $f['required'] ) {
				echo ' <span class="ffm-star" aria-hidden="true">*</span>';
			}
			echo $grouped ? '</span>' : '</label>';
		}

		$ph = $f['placeholder'] ? ' placeholder="' . esc_attr( $f['placeholder'] ) . '"' : '';

		switch ( $type ) {
			case 'textarea':
				printf(
					'<textarea id="%s" name="%s" rows="%d"%s%s>%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $f['rows'],
					$ph, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
					$req, // phpcs:ignore WordPress.Security.EscapeOutput -- static.
					esc_textarea( (string) $value )
				);
				break;

			case 'select':
				printf( '<select id="%s" name="%s"%s>', esc_attr( $id ), esc_attr( $name ), $req ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<option value="">' . esc_html( $f['placeholder'] ? $f['placeholder'] : __( '— Select —', 'functional-forms' ) ) . '</option>';
				foreach ( self::choices( $f ) as $v => $l ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( (string) $value, (string) $v, false ), esc_html( $l ) );
				}
				echo '</select>';
				break;

			case 'radio':
			case 'checkbox':
				$current = 'checkbox' === $type ? array_map( 'strval', (array) $value ) : array( (string) $value );
				$in_name = 'checkbox' === $type ? $name . '[]' : $name;
				echo '<div class="ffm-choices" role="' . ( 'radio' === $type ? 'radiogroup' : 'group' ) . '" aria-labelledby="' . esc_attr( $id ) . '-label">';
				$i = 0;
				foreach ( self::choices( $f ) as $v => $l ) {
					printf(
						'<label class="ffm-choice"><input type="%s" name="%s" value="%s"%s%s /> <span>%s</span></label>',
						esc_attr( $type ),
						esc_attr( $in_name ),
						esc_attr( $v ),
						checked( in_array( (string) $v, $current, true ), true, false ),
						( 'radio' === $type && $f['required'] && 0 === $i ) ? ' required' : '',
						esc_html( $l )
					);
					$i++;
				}
				echo '</div>';
				break;

			case 'consent':
				printf(
					'<label class="ffm-choice ffm-consent" for="%s"><input type="checkbox" id="%s" name="%s" value="1"%s%s /> <span>%s%s</span></label>',
					esc_attr( $id ),
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					$req, // phpcs:ignore WordPress.Security.EscapeOutput -- static.
					esc_html( $f['label'] ),
					$f['required'] ? ' <span class="ffm-star" aria-hidden="true">*</span>' : ''
				);
				break;

			case 'file':
				$exts     = self::allowed_extensions( $f );
				$accept   = $exts ? '.' . implode( ',.', $exts ) : '';
				$multiple = $f['max_files'] > 1;
				echo '<div class="ffm-dropzone" data-max-files="' . (int) $f['max_files'] . '" data-max-bytes="' . (int) self::max_bytes( $f ) . '">';
				printf(
					'<input type="file" id="%s" name="%s"%s%s%s />',
					esc_attr( $id ),
					esc_attr( 'ffm_file_' . $f['name'] . '[]' ),
					$accept ? ' accept="' . esc_attr( $accept ) . '"' : '',
					$multiple ? ' multiple' : '',
					$req // phpcs:ignore WordPress.Security.EscapeOutput -- static.
				);
				echo '<div class="ffm-dropzone-inner" aria-hidden="true">';
				echo '<span class="ffm-dropzone-icon">&#8679;</span>';
				echo '<strong>' . esc_html( $f['placeholder'] ? $f['placeholder'] : __( 'Drag & drop files here or click to browse', 'functional-forms' ) ) . '</strong>';
				echo '<small>';
				if ( $exts ) {
					echo esc_html( strtoupper( implode( ', ', $exts ) ) ) . ' &middot; ';
				}
				/* translators: %s: max file size */
				echo esc_html( sprintf( __( 'Max %s per file', 'functional-forms' ), size_format( self::max_bytes( $f ) ) ) );
				if ( $multiple ) {
					/* translators: %d: number of files */
					echo ' &middot; ' . esc_html( sprintf( __( 'Up to %d files', 'functional-forms' ), $f['max_files'] ) );
				}
				echo '</small></div><ul class="ffm-file-list"></ul></div>';
				break;

			default:
				$input_type = array(
					'email'  => 'email',
					'phone'  => 'tel',
					'number' => 'number',
					'url'    => 'url',
					'date'   => 'date',
				);
				$extra      = '';
				if ( 'number' === $type ) {
					$extra .= '' !== $f['min'] ? ' min="' . esc_attr( $f['min'] ) . '"' : '';
					$extra .= '' !== $f['max'] ? ' max="' . esc_attr( $f['max'] ) . '"' : '';
					$extra .= ' step="any"';
				}
				printf(
					'<input type="%s" id="%s" name="%s" value="%s"%s%s%s />',
					esc_attr( isset( $input_type[ $type ] ) ? $input_type[ $type ] : 'text' ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					$ph, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
					$extra, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
					$req // phpcs:ignore WordPress.Security.EscapeOutput -- static.
				);
		}

		if ( $f['help'] ) {
			echo '<p class="ffm-help">' . esc_html( $f['help'] ) . '</p>';
		}
		echo '<p class="ffm-error" role="alert">' . esc_html( $error ) . '</p>';
		echo '</div>';
	}

	/**
	 * Default value for a field, in the shape render() expects.
	 */
	public static function default_value( $f ) {
		if ( 'checkbox' === $f['type'] ) {
			return array_filter( array_map( 'trim', explode( ',', $f['default'] ) ), 'strlen' );
		}
		return $f['default'];
	}

	/**
	 * Sanitize and validate a submitted value. File fields are handled by FFM_Submission.
	 *
	 * @param array $f   Field definition.
	 * @param mixed $raw Unslashed raw input.
	 * @return array { 0: clean value, 1: error message or '' }
	 */
	public static function validate( $f, $raw ) {
		$label = $f['label'] ? $f['label'] : $f['name'];
		$error = '';
		$str   = is_scalar( $raw ) ? trim( (string) $raw ) : '';

		switch ( $f['type'] ) {
			case 'checkbox':
				$allowed = array_map( 'strval', array_keys( self::choices( $f ) ) );
				$given   = is_array( $raw ) ? array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) : array();
				$value   = array_values( array_intersect( $given, $allowed ) );
				break;

			case 'select':
			case 'radio':
				$value = $str;
				if ( '' !== $value && ! array_key_exists( $value, self::choices( $f ) ) ) {
					/* translators: %s: field label */
					$error = sprintf( __( 'Please choose a valid option for %s.', 'functional-forms' ), $label );
					$value = '';
				}
				break;

			case 'email':
				$value = sanitize_email( $str );
				if ( '' !== $str && ! is_email( $value ) ) {
					$error = __( 'Please enter a valid email address.', 'functional-forms' );
				}
				break;

			case 'url':
				$value = esc_url_raw( $str, array( 'http', 'https' ) );
				if ( '' !== $str && ( '' === $value || false === filter_var( $value, FILTER_VALIDATE_URL ) ) ) {
					$error = __( 'Please enter a valid URL (including https://).', 'functional-forms' );
				}
				break;

			case 'number':
				$value = $str;
				if ( '' !== $value ) {
					if ( ! is_numeric( $value ) ) {
						$error = __( 'Please enter a number.', 'functional-forms' );
					} elseif ( '' !== $f['min'] && (float) $value < (float) $f['min'] ) {
						/* translators: %s: minimum */
						$error = sprintf( __( 'Must be at least %s.', 'functional-forms' ), $f['min'] );
					} elseif ( '' !== $f['max'] && (float) $value > (float) $f['max'] ) {
						/* translators: %s: maximum */
						$error = sprintf( __( 'Must be at most %s.', 'functional-forms' ), $f['max'] );
					}
				}
				break;

			case 'date':
				$value = sanitize_text_field( $str );
				if ( '' !== $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
					$error = __( 'Please enter a valid date.', 'functional-forms' );
				}
				break;

			case 'phone':
				$value = sanitize_text_field( $str );
				if ( '' !== $value && ! preg_match( '/^[0-9+()\-.\s]{5,25}$/', $value ) ) {
					$error = __( 'Please enter a valid phone number.', 'functional-forms' );
				}
				break;

			case 'textarea':
				$value = sanitize_textarea_field( is_scalar( $raw ) ? (string) $raw : '' );
				$value = trim( $value );
				break;

			case 'consent':
				$value = '' !== $str ? __( 'Yes', 'functional-forms' ) : '';
				break;

			default:
				$value = sanitize_text_field( $str );
		}

		if ( ! $error && $f['required'] && ( is_array( $value ) ? ! $value : '' === $value ) ) {
			$error = 'consent' === $f['type']
				? __( 'Please accept to continue.', 'functional-forms' )
				/* translators: %s: field label */
				: sprintf( __( '%s is required.', 'functional-forms' ), $label );
		}

		return array( $value, $error );
	}

	/**
	 * Human readable version of a stored value.
	 *
	 * @param array $item Stored entry item (type, value).
	 * @return string Plain text.
	 */
	public static function value_to_text( $item ) {
		$value = isset( $item['value'] ) ? $item['value'] : '';
		if ( 'file' === $item['type'] ) {
			return implode( "\n", wp_list_pluck( (array) $value, 'url' ) );
		}
		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	/**
	 * HTML version of a stored value (links for files, URLs and emails).
	 */
	public static function value_to_html( $item ) {
		$value = isset( $item['value'] ) ? $item['value'] : '';
		switch ( $item['type'] ) {
			case 'file':
				$links = array();
				foreach ( (array) $value as $file ) {
					$links[] = '<a href="' . esc_url( $file['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $file['name'] ) . '</a>';
				}
				return implode( '<br>', $links );
			case 'email':
				return $value ? '<a href="mailto:' . esc_attr( $value ) . '">' . esc_html( $value ) . '</a>' : '';
			case 'url':
				return $value ? '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener">' . esc_html( $value ) . '</a>' : '';
			case 'textarea':
				return nl2br( esc_html( $value ) );
		}
		return esc_html( self::value_to_text( $item ) );
	}
}
