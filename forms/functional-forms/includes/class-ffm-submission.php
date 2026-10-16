<?php
/**
 * Submission handling: validation, file uploads, entries and emails.
 */

defined( 'ABSPATH' ) || exit;

class FFM_Submission {

	public static function init() {
		add_action( 'wp_ajax_ffm_submit', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_ffm_submit', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_ffm_submit', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_ffm_submit', array( __CLASS__, 'handle' ) );
	}

	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified below once the form ID is known.
		$form_id = isset( $_POST['ffm_form_id'] ) ? absint( $_POST['ffm_form_id'] ) : 0;
		$form    = $form_id ? get_post( $form_id ) : null;

		if ( ! $form || FFM_Forms::POST_TYPE !== $form->post_type || 'publish' !== $form->post_status ) {
			self::respond( $form_id, false, __( 'This form is not available.', 'functional-forms' ) );
		}

		$nonce = isset( $_POST['_ffm_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_ffm_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ffm_submit_' . $form_id ) ) {
			self::respond( $form_id, false, __( 'Your session has expired. Please reload the page and try again.', 'functional-forms' ) );
		}
		// phpcs:enable

		$settings = FFM_Forms::get_settings( $form_id );

		// Honeypot: bots get a normal-looking success, nothing is saved or sent.
		if ( ! empty( $_POST['ffm_hp'] ) ) {
			self::respond( $form_id, true, $settings['success_message'] );
		}

		$fields = FFM_Forms::get_fields( $form_id );
		$input  = isset( $_POST['ffm'] ) && is_array( $_POST['ffm'] ) ? wp_unslash( $_POST['ffm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is sanitized by FFM_Fields::validate().
		$data   = array();
		$old    = array();
		$errors = array();
		$files  = array();

		foreach ( $fields as $f ) {
			if ( ! FFM_Fields::is_input( $f ) ) {
				continue;
			}
			$name          = $f['name'];
			$data[ $name ] = array(
				'label' => $f['label'] ? $f['label'] : $name,
				'type'  => $f['type'],
				'value' => '',
			);

			if ( 'file' === $f['type'] ) {
				list( $uploads, $error ) = self::check_files( $f );
				if ( $error ) {
					$errors[ $name ] = $error;
				} elseif ( $uploads ) {
					$files[ $name ] = array( $f, $uploads );
				}
				continue;
			}

			list( $value, $error ) = FFM_Fields::validate( $f, isset( $input[ $name ] ) ? $input[ $name ] : '' );
			$data[ $name ]['value'] = $value;
			$old[ $name ]           = $value;
			if ( $error ) {
				$errors[ $name ] = $error;
			}
		}

		if ( $errors ) {
			self::respond( $form_id, false, __( 'Please correct the highlighted fields and try again.', 'functional-forms' ), $errors, $old );
		}

		// Everything validated: now move uploaded files into place.
		$stored = array();
		foreach ( $files as $name => $pair ) {
			list( $f, $uploads ) = $pair;
			$result              = self::store_files( $f, $uploads );
			if ( is_wp_error( $result ) ) {
				self::delete_files( $stored );
				self::respond( $form_id, false, $result->get_error_message(), array( $name => $result->get_error_message() ), $old );
			}
			$data[ $name ]['value'] = $result;
			$stored                 = array_merge( $stored, $result );
		}

		$data     = apply_filters( 'ffm_submission_data', $data, $form_id );
		$page_url = self::referer();
		$entry_id = 0;

		if ( $settings['store_entries'] ) {
			$entry_id = FFM_Entries::create( $form_id, $data, $page_url );
		}

		self::send_emails( $form, $settings, $data, $entry_id, $page_url );

		do_action( 'ffm_submission', $form_id, $data, $entry_id );

		self::respond( $form_id, true, $settings['success_message'], array(), array(), $settings['redirect'] );
	}

	/**
	 * Collect and validate the uploads for one file field without moving them.
	 *
	 * @return array { 0: file arrays, 1: error message or '' }
	 */
	protected static function check_files( $f ) {
		$key   = 'ffm_file_' . $f['name'];
		$label = $f['label'] ? $f['label'] : $f['name'];
		$files = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- nonce checked in handle(); files validated below.
		if ( ! empty( $_FILES[ $key ] ) && isset( $_FILES[ $key ]['name'] ) ) {
			$raw = $_FILES[ $key ];
			foreach ( (array) $raw['name'] as $i => $name ) {
				$err = isset( $raw['error'][ $i ] ) ? (int) $raw['error'][ $i ] : ( is_array( $raw['error'] ) ? UPLOAD_ERR_NO_FILE : (int) $raw['error'] );
				if ( '' === $name || UPLOAD_ERR_NO_FILE === $err ) {
					continue;
				}
				$files[] = array(
					'name'     => (string) $name,
					'type'     => is_array( $raw['type'] ) ? $raw['type'][ $i ] : $raw['type'],
					'tmp_name' => is_array( $raw['tmp_name'] ) ? $raw['tmp_name'][ $i ] : $raw['tmp_name'],
					'error'    => $err,
					'size'     => is_array( $raw['size'] ) ? (int) $raw['size'][ $i ] : (int) $raw['size'],
				);
			}
		}
		// phpcs:enable

		if ( ! $files ) {
			/* translators: %s: field label */
			return array( array(), $f['required'] ? sprintf( __( '%s is required.', 'functional-forms' ), $label ) : '' );
		}

		if ( count( $files ) > $f['max_files'] ) {
			/* translators: %d: max number of files */
			return array( array(), sprintf( _n( 'You can upload %d file.', 'You can upload up to %d files.', $f['max_files'], 'functional-forms' ), $f['max_files'] ) );
		}

		$max   = FFM_Fields::max_bytes( $f );
		$mimes = self::mimes( $f );

		foreach ( $files as $file ) {
			$name = sanitize_file_name( $file['name'] );
			if ( in_array( $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || $file['size'] > $max ) {
				/* translators: 1: file name, 2: max size */
				return array( array(), sprintf( __( '%1$s is too large. Maximum size is %2$s.', 'functional-forms' ), $name, size_format( $max ) ) );
			}
			if ( UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				/* translators: %s: file name */
				return array( array(), sprintf( __( '%s could not be uploaded. Please try again.', 'functional-forms' ), $name ) );
			}
			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
			if ( ! $mimes || empty( $check['ext'] ) || empty( $check['type'] ) ) {
				/* translators: 1: file name, 2: allowed extensions */
				return array( array(), sprintf( __( '%1$s is not an allowed file type. Allowed: %2$s.', 'functional-forms' ), $name, implode( ', ', FFM_Fields::allowed_extensions( $f ) ) ) );
			}
		}

		return array( $files, '' );
	}

	/**
	 * WordPress mime map restricted to the field's allowed extensions.
	 */
	protected static function mimes( $f ) {
		$allowed = FFM_Fields::allowed_extensions( $f );
		$out     = array();
		foreach ( get_allowed_mime_types() as $exts => $mime ) {
			if ( array_intersect( explode( '|', $exts ), $allowed ) ) {
				$out[ $exts ] = $mime;
			}
		}
		return $out;
	}

	/**
	 * Move validated uploads to uploads/functional-forms/YYYY/MM.
	 *
	 * @return array|WP_Error Stored files (name, url, file, type, size).
	 */
	protected static function store_files( $f, $uploads ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$stored = array();
		$mimes  = self::mimes( $f );

		add_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );
		foreach ( $uploads as $file ) {
			$result = wp_handle_upload(
				$file,
				array(
					'test_form'                => false,
					'mimes'                    => $mimes,
					'unique_filename_callback' => array( __CLASS__, 'unique_filename' ),
				)
			);
			if ( isset( $result['error'] ) ) {
				remove_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );
				self::delete_files( $stored );
				return new WP_Error( 'ffm_upload', $result['error'] );
			}
			$stored[] = array(
				'name' => sanitize_file_name( $file['name'] ),
				'url'  => $result['url'],
				'file' => $result['file'],
				'type' => $result['type'],
				'size' => (int) $file['size'],
			);
		}
		remove_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );

		self::protect_dir();
		return $stored;
	}

	public static function upload_dir( $dirs ) {
		$sub            = '/functional-forms' . gmdate( '/Y/m' );
		$dirs['subdir'] = $sub;
		$dirs['path']   = $dirs['basedir'] . $sub;
		$dirs['url']    = $dirs['baseurl'] . $sub;
		return $dirs;
	}

	/**
	 * Random prefix so uploaded file URLs can't be guessed.
	 */
	public static function unique_filename( $dir, $name, $ext ) {
		return wp_unique_filename( $dir, strtolower( wp_generate_password( 12, false, false ) ) . '-' . $name );
	}

	/**
	 * Block directory listing of the upload folders.
	 */
	protected static function protect_dir() {
		$base = wp_upload_dir( null, false );
		$dirs = array( '/functional-forms', '/functional-forms' . gmdate( '/Y' ), '/functional-forms' . gmdate( '/Y/m' ) );
		foreach ( $dirs as $dir ) {
			$index = $base['basedir'] . $dir . '/index.php';
			if ( is_dir( dirname( $index ) ) && ! file_exists( $index ) ) {
				file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	protected static function delete_files( $files ) {
		foreach ( $files as $file ) {
			if ( ! empty( $file['file'] ) ) {
				wp_delete_file( $file['file'] );
			}
		}
	}

	/**
	 * Page the form was submitted from.
	 */
	protected static function referer() {
		$url = isset( $_POST['ffm_referer'] ) ? esc_url_raw( wp_unslash( $_POST['ffm_referer'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$url = wp_validate_redirect( $url, '' );
		if ( ! $url ) {
			$url = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		}
		return $url;
	}

	/**
	 * Send the JSON (AJAX) or redirect (no-JS) response and exit.
	 */
	protected static function respond( $form_id, $ok, $message, $errors = array(), $old = array(), $redirect = '' ) {
		if ( wp_doing_ajax() ) {
			wp_send_json(
				array(
					'success'  => (bool) $ok,
					'message'  => $ok ? wpautop( wp_kses_post( $message ) ) : esc_html( $message ),
					'errors'   => (object) $errors,
					'redirect' => $ok ? esc_url_raw( $redirect ) : '',
				)
			);
		}

		$back = self::referer();
		$hash = '#ffm-form-' . (int) $form_id;

		if ( $ok ) {
			if ( $redirect ) {
				wp_redirect( $redirect ); // phpcs:ignore WordPress.Security.SafeRedirect -- set by an admin in form settings.
				exit;
			}
			wp_safe_redirect( add_query_arg( 'ffm_success', (int) $form_id, $back ) . $hash );
			exit;
		}

		$token = strtolower( wp_generate_password( 16, false, false ) );
		set_transient(
			'ffm_err_' . $token,
			array(
				'form_id' => (int) $form_id,
				'message' => $message,
				'errors'  => $errors,
				'old'     => $old,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'ffm_error', $token, $back ) . $hash );
		exit;
	}

	/**
	 * Admin notification and submitter auto-reply.
	 */
	protected static function send_emails( $form, $s, $data, $entry_id, $page_url ) {
		$first_email = '';
		$paths       = array();
		foreach ( $data as $item ) {
			if ( 'email' === $item['type'] && ! $first_email && is_email( $item['value'] ) ) {
				$first_email = $item['value'];
			}
			if ( 'file' === $item['type'] && is_array( $item['value'] ) ) {
				$paths = array_merge( $paths, wp_list_pluck( $item['value'], 'file' ) );
			}
		}

		$context = array(
			'form'     => $form,
			'data'     => $data,
			'entry_id' => $entry_id,
			'page_url' => $page_url,
		);
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( $s['notify'] ) {
			$to = str_replace( '{admin_email}', get_option( 'admin_email' ), $s['notify_to'] );
			$to = array_filter( array_map( 'trim', explode( ',', $to ) ), 'is_email' );
			if ( $to ) {
				$h = $headers;
				if ( $first_email ) {
					$h[] = 'Reply-To: ' . $first_email;
				}
				wp_mail(
					$to,
					self::subject( $s['notify_subject'], $context ),
					self::body( $s['notify_body'], $context ),
					$h,
					$s['notify_attach'] ? $paths : array()
				);
			}
		}

		if ( $s['reply'] ) {
			$to = $first_email;
			if ( $s['reply_field'] && isset( $data[ $s['reply_field'] ] ) && 'email' === $data[ $s['reply_field'] ]['type'] ) {
				$to = $data[ $s['reply_field'] ]['value'];
			}
			if ( is_email( $to ) ) {
				wp_mail( $to, self::subject( $s['reply_subject'], $context ), self::body( $s['reply_body'], $context ), $headers );
			}
		}
	}

	/**
	 * Plain smart tag values.
	 */
	protected static function tags( $context ) {
		$tags = array(
			'{form_title}' => get_the_title( $context['form'] ),
			'{site_name}'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{page_url}'   => $context['page_url'],
			'{entry_id}'   => (string) $context['entry_id'],
		);
		foreach ( $context['data'] as $name => $item ) {
			$tags[ '{field:' . $name . '}' ] = FFM_Fields::value_to_text( $item );
		}
		return $tags;
	}

	protected static function subject( $template, $context ) {
		$subject = strtr( $template, self::tags( $context ) );
		$subject = str_replace( '{all_fields}', '', $subject );
		return trim( preg_replace( '/[\r\n]+/', ' ', $subject ) );
	}

	protected static function body( $template, $context ) {
		$html = array();
		foreach ( self::tags( $context ) as $tag => $value ) {
			$html[ $tag ] = nl2br( esc_html( $value ) );
		}
		$html['{all_fields}'] = self::all_fields_table( $context['data'] );

		$body = strtr( nl2br( esc_html( $template ) ), $html );
		return '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;line-height:1.6;color:#1d2327;">' . $body . '</div>';
	}

	protected static function all_fields_table( $data ) {
		$rows = '';
		foreach ( $data as $item ) {
			$value = FFM_Fields::value_to_html( $item );
			$rows .= '<tr><th style="text-align:left;vertical-align:top;padding:8px 12px;background:#f6f7f7;border:1px solid #dcdcde;width:30%;">' . esc_html( $item['label'] ) . '</th>'
				. '<td style="padding:8px 12px;border:1px solid #dcdcde;">' . ( '' === $value ? '&mdash;' : $value ) . '</td></tr>';
		}
		return '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;max-width:640px;">' . $rows . '</table>';
	}
}
