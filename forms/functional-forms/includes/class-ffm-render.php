<?php
/**
 * Front-end output: shortcode, block and assets.
 */

defined( 'ABSPATH' ) || exit;

class FFM_Render {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor_data' ) );
		add_shortcode( 'functional_form', array( __CLASS__, 'shortcode' ) );
	}

	public static function register_assets() {
		wp_register_style( 'ffm-form', FFM_URL . 'assets/css/form.css', array(), FFM_VERSION );
		wp_register_script( 'ffm-form', FFM_URL . 'assets/js/form.js', array(), FFM_VERSION, true );
		wp_localize_script(
			'ffm-form',
			'ffmForm',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'sending'  => __( 'Sending…', 'functional-forms' ),
					'error'    => __( 'Something went wrong. Please try again.', 'functional-forms' ),
					/* translators: %d: number of files */
					'tooMany'  => __( 'You can upload up to %d file(s).', 'functional-forms' ),
					/* translators: %s: file name */
					'tooBig'   => __( '%s is too large.', 'functional-forms' ),
					'remove'   => __( 'Remove', 'functional-forms' ),
				),
			)
		);

		wp_register_script(
			'ffm-block',
			FFM_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			FFM_VERSION,
			true
		);
	}

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			'functional-forms/form',
			array(
				'api_version'     => 2,
				'editor_script'   => 'ffm-block',
				'style'           => 'ffm-form',
				'attributes'      => array(
					'formId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'render_callback' => function ( $attrs ) {
					return self::shortcode( array( 'id' => isset( $attrs['formId'] ) ? $attrs['formId'] : 0 ) );
				},
			)
		);
	}

	public static function block_editor_data() {
		$data = array(
			'forms'  => FFM_Forms::published_forms(),
			'newUrl' => admin_url( 'post-new.php?post_type=' . FFM_Forms::POST_TYPE ),
		);
		wp_add_inline_script( 'ffm-block', 'window.ffmBlock = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * [functional_form id="123"]
	 */
	public static function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'functional_form' );
		$form_id = absint( $atts['id'] );
		$form    = $form_id ? get_post( $form_id ) : null;

		if ( ! $form || FFM_Forms::POST_TYPE !== $form->post_type || 'publish' !== $form->post_status ) {
			return current_user_can( 'manage_options' )
				? '<p class="ffm-notice">' . esc_html__( 'Functional Forms: form not found or not published.', 'functional-forms' ) . '</p>'
				: '';
		}

		wp_enqueue_style( 'ffm-form' );
		wp_enqueue_script( 'ffm-form' );

		$fields   = FFM_Forms::get_fields( $form_id );
		$settings = FFM_Forms::get_settings( $form_id );

		// No-JS fallback state, set by FFM_Submission::respond().
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display state.
		$success = isset( $_GET['ffm_success'] ) && absint( $_GET['ffm_success'] ) === $form_id;
		$errors  = array();
		$old     = array();
		$summary = '';
		if ( isset( $_GET['ffm_error'] ) ) {
			$state = get_transient( 'ffm_err_' . sanitize_key( $_GET['ffm_error'] ) );
			if ( is_array( $state ) && (int) $state['form_id'] === $form_id ) {
				$errors  = (array) $state['errors'];
				$old     = (array) $state['old'];
				$summary = (string) $state['message'];
			}
		}
		// phpcs:enable

		$classes = trim( 'ffm-wrap ' . $settings['css_class'] );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" id="ffm-form-<?php echo (int) $form_id; ?>">
			<div class="ffm-message ffm-success-message" role="status" <?php echo $success ? '' : 'hidden'; ?>><?php echo wp_kses_post( wpautop( $settings['success_message'] ) ); ?></div>
			<?php if ( ! $success ) : ?>
				<form class="ffm-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-form-id="<?php echo (int) $form_id; ?>">
					<input type="hidden" name="action" value="ffm_submit" />
					<input type="hidden" name="ffm_form_id" value="<?php echo (int) $form_id; ?>" />
					<input type="hidden" name="ffm_referer" value="<?php echo esc_url( self::current_url() ); ?>" />
					<?php wp_nonce_field( 'ffm_submit_' . $form_id, '_ffm_nonce', false ); ?>
					<div class="ffm-hp" aria-hidden="true">
						<label><?php esc_html_e( 'Leave this field empty', 'functional-forms' ); ?> <input type="text" name="ffm_hp" value="" tabindex="-1" autocomplete="off" /></label>
					</div>
					<div class="ffm-message ffm-error-summary" role="alert" <?php echo $summary ? '' : 'hidden'; ?>><?php echo esc_html( $summary ); ?></div>
					<div class="ffm-fields">
						<?php
						foreach ( $fields as $f ) {
							$value = array_key_exists( $f['name'], $old ) ? $old[ $f['name'] ] : FFM_Fields::default_value( $f );
							$error = isset( $errors[ $f['name'] ] ) ? $errors[ $f['name'] ] : '';
							FFM_Fields::render( $f, $form_id, $value, $error );
						}
						?>
					</div>
					<div class="ffm-submit-wrap">
						<button type="submit" class="ffm-submit"><?php echo esc_html( $settings['submit_text'] ); ?></button>
					</div>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * URL of the current page, without our state query args.
	 */
	protected static function current_url() {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$url = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		} else {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$url  = set_url_scheme( 'http://' . $host . $uri );
		}
		return remove_query_arg( array( 'ffm_success', 'ffm_error' ), $url );
	}
}
