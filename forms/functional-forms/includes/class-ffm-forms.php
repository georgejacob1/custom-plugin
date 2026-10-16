<?php
/**
 * Forms: admin post type, drag & drop builder and form settings.
 */

defined( 'ABSPATH' ) || exit;

class FFM_Forms {

	const POST_TYPE = 'ffm_form';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
	}

	public static function register_post_type() {
		$cap = 'manage_options';

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Forms', 'functional-forms' ),
					'singular_name' => __( 'Form', 'functional-forms' ),
					'menu_name'     => __( 'Forms', 'functional-forms' ),
					'all_items'     => __( 'All Forms', 'functional-forms' ),
					'add_new'       => __( 'Add New', 'functional-forms' ),
					'add_new_item'  => __( 'Add New Form', 'functional-forms' ),
					'edit_item'     => __( 'Edit Form', 'functional-forms' ),
					'search_items'  => __( 'Search Forms', 'functional-forms' ),
					'not_found'     => __( 'No forms found.', 'functional-forms' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'show_in_rest' => false,
				'menu_icon'    => 'dashicons-feedback',
				'menu_position' => 81,
				'supports'     => array( 'title' ),
				'rewrite'      => false,
				'query_var'    => false,
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'          => $cap,
					'read_post'          => $cap,
					'delete_post'        => $cap,
					'edit_posts'         => $cap,
					'edit_others_posts'  => $cap,
					'delete_posts'       => $cap,
					'publish_posts'      => $cap,
					'read_private_posts' => $cap,
					'create_posts'       => $cap,
				),
			)
		);
	}

	/**
	 * Form settings with defaults applied.
	 */
	public static function default_settings() {
		return array(
			'submit_text'     => __( 'Submit', 'functional-forms' ),
			'success_message' => __( 'Thank you! Your submission has been received.', 'functional-forms' ),
			'redirect'        => '',
			'css_class'       => '',
			'store_entries'   => 1,
			'notify'          => 1,
			'notify_to'       => '{admin_email}',
			'notify_subject'  => __( 'New submission: {form_title}', 'functional-forms' ),
			'notify_body'     => '{all_fields}',
			'notify_attach'   => 0,
			'reply'           => 0,
			'reply_field'     => '',
			'reply_subject'   => __( 'Thanks for contacting us', 'functional-forms' ),
			'reply_body'      => __( "Hi,\n\nThanks for getting in touch. We have received your submission:\n\n{all_fields}", 'functional-forms' ),
		);
	}

	public static function get_settings( $form_id ) {
		$saved = get_post_meta( $form_id, '_ffm_settings', true );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
	}

	public static function get_fields( $form_id ) {
		$fields = get_post_meta( $form_id, '_ffm_fields', true );
		if ( ! is_array( $fields ) ) {
			return array();
		}
		return array_map(
			function ( $f ) {
				return wp_parse_args( $f, FFM_Fields::defaults() );
			},
			$fields
		);
	}

	/**
	 * Starter fields for a brand-new form (a simple contact form).
	 */
	protected static function starter_fields() {
		return FFM_Fields::sanitize_definitions(
			array(
				array( 'type' => 'text', 'label' => __( 'Name', 'functional-forms' ), 'name' => 'name', 'required' => 1, 'width' => 'half' ),
				array( 'type' => 'email', 'label' => __( 'Email', 'functional-forms' ), 'name' => 'email', 'required' => 1, 'width' => 'half' ),
				array( 'type' => 'text', 'label' => __( 'Subject', 'functional-forms' ), 'name' => 'subject' ),
				array( 'type' => 'textarea', 'label' => __( 'Message', 'functional-forms' ), 'name' => 'message', 'required' => 1, 'rows' => 5 ),
			)
		);
	}

	public static function add_meta_boxes( $post ) {
		add_meta_box( 'ffm-builder', __( 'Form Fields', 'functional-forms' ), array( __CLASS__, 'render_builder' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'ffm-settings', __( 'Form Settings', 'functional-forms' ), array( __CLASS__, 'render_settings' ), self::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'ffm-embed', __( 'Embed', 'functional-forms' ), array( __CLASS__, 'render_embed' ), self::POST_TYPE, 'side', 'default' );
	}

	public static function render_builder( $post ) {
		wp_nonce_field( 'ffm_save_form', 'ffm_form_nonce' );
		$fields = self::get_fields( $post->ID );
		if ( ! $fields && 'auto-draft' === $post->post_status ) {
			$fields = self::starter_fields();
		}
		?>
		<div id="ffm-builder">
			<div class="ffm-b-palette">
				<h4><?php esc_html_e( 'Add a field', 'functional-forms' ); ?></h4>
				<div class="ffm-b-palette-grid">
					<?php foreach ( FFM_Fields::types() as $type => $label ) : ?>
						<button type="button" class="button ffm-b-add" data-type="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Click a field to add it, then drag fields to reorder.', 'functional-forms' ); ?></p>
			</div>
			<div class="ffm-b-canvas">
				<ul class="ffm-b-list"></ul>
				<p class="ffm-b-empty"><?php esc_html_e( 'No fields yet. Click a field type on the left to start building your form.', 'functional-forms' ); ?></p>
			</div>
			<textarea id="ffm-fields-json" name="ffm_fields_json" hidden><?php echo esc_textarea( wp_json_encode( $fields ) ); ?></textarea>
		</div>
		<?php
	}

	public static function render_settings( $post ) {
		$s = self::get_settings( $post->ID );
		?>
		<div class="ffm-settings">
			<h3><?php esc_html_e( 'Submission', 'functional-forms' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="ffm-submit-text"><?php esc_html_e( 'Submit button text', 'functional-forms' ); ?></label></th>
					<td><input type="text" class="regular-text" id="ffm-submit-text" name="ffm_settings[submit_text]" value="<?php echo esc_attr( $s['submit_text'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ffm-success"><?php esc_html_e( 'Success message', 'functional-forms' ); ?></label></th>
					<td><textarea class="large-text" rows="2" id="ffm-success" name="ffm_settings[success_message]"><?php echo esc_textarea( $s['success_message'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="ffm-redirect"><?php esc_html_e( 'Redirect to URL', 'functional-forms' ); ?></label></th>
					<td>
						<input type="url" class="regular-text" id="ffm-redirect" name="ffm_settings[redirect]" value="<?php echo esc_attr( $s['redirect'] ); ?>" placeholder="https://" />
						<p class="description"><?php esc_html_e( 'Optional. Leave empty to show the success message instead.', 'functional-forms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ffm-css-class"><?php esc_html_e( 'CSS class', 'functional-forms' ); ?></label></th>
					<td><input type="text" class="regular-text" id="ffm-css-class" name="ffm_settings[css_class]" value="<?php echo esc_attr( $s['css_class'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Entries', 'functional-forms' ); ?></th>
					<td><label><input type="checkbox" name="ffm_settings[store_entries]" value="1" <?php checked( $s['store_entries'] ); ?> /> <?php esc_html_e( 'Save submissions under Forms → Entries', 'functional-forms' ); ?></label></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Admin notification', 'functional-forms' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable', 'functional-forms' ); ?></th>
					<td><label><input type="checkbox" name="ffm_settings[notify]" value="1" <?php checked( $s['notify'] ); ?> /> <?php esc_html_e( 'Email each submission', 'functional-forms' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="ffm-notify-to"><?php esc_html_e( 'Send to', 'functional-forms' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="ffm-notify-to" name="ffm_settings[notify_to]" value="<?php echo esc_attr( $s['notify_to'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Comma separated emails. {admin_email} is the site admin email.', 'functional-forms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ffm-notify-subject"><?php esc_html_e( 'Subject', 'functional-forms' ); ?></label></th>
					<td><input type="text" class="large-text" id="ffm-notify-subject" name="ffm_settings[notify_subject]" value="<?php echo esc_attr( $s['notify_subject'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ffm-notify-body"><?php esc_html_e( 'Message', 'functional-forms' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="5" id="ffm-notify-body" name="ffm_settings[notify_body]"><?php echo esc_textarea( $s['notify_body'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Smart tags: {all_fields}, {field:name} (use a field name), {form_title}, {site_name}, {page_url}. The reply-to is set to the first email field.', 'functional-forms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Attachments', 'functional-forms' ); ?></th>
					<td><label><input type="checkbox" name="ffm_settings[notify_attach]" value="1" <?php checked( $s['notify_attach'] ); ?> /> <?php esc_html_e( 'Attach uploaded files to the email', 'functional-forms' ); ?></label></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Auto-reply to the submitter', 'functional-forms' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable', 'functional-forms' ); ?></th>
					<td><label><input type="checkbox" name="ffm_settings[reply]" value="1" <?php checked( $s['reply'] ); ?> /> <?php esc_html_e( 'Send a confirmation email to the person who submitted', 'functional-forms' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="ffm-reply-field"><?php esc_html_e( 'Email field name', 'functional-forms' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="ffm-reply-field" name="ffm_settings[reply_field]" value="<?php echo esc_attr( $s['reply_field'] ); ?>" placeholder="email" />
						<p class="description"><?php esc_html_e( 'Name of the Email field to send to. Empty = first Email field.', 'functional-forms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ffm-reply-subject"><?php esc_html_e( 'Subject', 'functional-forms' ); ?></label></th>
					<td><input type="text" class="large-text" id="ffm-reply-subject" name="ffm_settings[reply_subject]" value="<?php echo esc_attr( $s['reply_subject'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ffm-reply-body"><?php esc_html_e( 'Message', 'functional-forms' ); ?></label></th>
					<td><textarea class="large-text" rows="5" id="ffm-reply-body" name="ffm_settings[reply_body]"><?php echo esc_textarea( $s['reply_body'] ); ?></textarea></td>
				</tr>
			</table>
		</div>
		<?php
	}

	public static function render_embed( $post ) {
		if ( 'publish' !== $post->post_status ) {
			echo '<p>' . esc_html__( 'Publish the form to embed it.', 'functional-forms' ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Shortcode:', 'functional-forms' ) . '</p>';
		echo '<input type="text" class="widefat code" readonly onfocus="this.select()" value="' . esc_attr( self::shortcode( $post->ID ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'Or add the "Functional Form" block in the block editor.', 'functional-forms' ) . '</p>';
	}

	public static function shortcode( $form_id ) {
		return '[functional_form id="' . (int) $form_id . '"]';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['ffm_form_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ffm_form_nonce'] ), 'ffm_save_form' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$raw    = isset( $_POST['ffm_fields_json'] ) ? json_decode( wp_unslash( $_POST['ffm_fields_json'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized below.
		$fields = FFM_Fields::sanitize_definitions( $raw );
		update_post_meta( $post_id, '_ffm_fields', wp_slash( $fields ) );

		$in = isset( $_POST['ffm_settings'] ) && is_array( $_POST['ffm_settings'] ) ? wp_unslash( $_POST['ffm_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized below.
		$s  = array(
			'submit_text'     => sanitize_text_field( isset( $in['submit_text'] ) ? $in['submit_text'] : '' ),
			'success_message' => wp_kses_post( isset( $in['success_message'] ) ? $in['success_message'] : '' ),
			'redirect'        => esc_url_raw( isset( $in['redirect'] ) ? $in['redirect'] : '' ),
			'css_class'       => implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', isset( $in['css_class'] ) ? (string) $in['css_class'] : '' ) ) ),
			'store_entries'   => empty( $in['store_entries'] ) ? 0 : 1,
			'notify'          => empty( $in['notify'] ) ? 0 : 1,
			'notify_to'       => sanitize_text_field( isset( $in['notify_to'] ) ? $in['notify_to'] : '' ),
			'notify_subject'  => sanitize_text_field( isset( $in['notify_subject'] ) ? $in['notify_subject'] : '' ),
			'notify_body'     => sanitize_textarea_field( isset( $in['notify_body'] ) ? $in['notify_body'] : '' ),
			'notify_attach'   => empty( $in['notify_attach'] ) ? 0 : 1,
			'reply'           => empty( $in['reply'] ) ? 0 : 1,
			'reply_field'     => sanitize_key( isset( $in['reply_field'] ) ? $in['reply_field'] : '' ),
			'reply_subject'   => sanitize_text_field( isset( $in['reply_subject'] ) ? $in['reply_subject'] : '' ),
			'reply_body'      => sanitize_textarea_field( isset( $in['reply_body'] ) ? $in['reply_body'] : '' ),
		);
		if ( '' === $s['submit_text'] ) {
			$s['submit_text'] = __( 'Submit', 'functional-forms' );
		}
		update_post_meta( $post_id, '_ffm_settings', wp_slash( $s ) );
	}

	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type || ( 'post.php' !== $hook && 'post-new.php' !== $hook ) ) {
			return;
		}

		wp_enqueue_style( 'ffm-builder', FFM_URL . 'assets/css/builder.css', array(), FFM_VERSION );
		wp_enqueue_script( 'ffm-builder', FFM_URL . 'assets/js/builder.js', array( 'jquery', 'jquery-ui-sortable' ), FFM_VERSION, true );
		wp_localize_script(
			'ffm-builder',
			'ffmBuilder',
			array(
				'types'    => FFM_Fields::types(),
				'defaults' => FFM_Fields::defaults(),
				'i18n'     => array(
					'untitled'      => __( '(no label)', 'functional-forms' ),
					'confirmDelete' => __( 'Delete this field?', 'functional-forms' ),
					'edit'          => __( 'Edit', 'functional-forms' ),
					'duplicate'     => __( 'Duplicate', 'functional-forms' ),
					'delete'        => __( 'Delete', 'functional-forms' ),
					'label'         => __( 'Label', 'functional-forms' ),
					'name'          => __( 'Field name', 'functional-forms' ),
					'nameHelp'      => __( 'Lowercase letters, numbers and underscores. Used in {field:name} tags and CSV export.', 'functional-forms' ),
					'placeholder'   => __( 'Placeholder', 'functional-forms' ),
					'dropText'      => __( 'Drop zone text', 'functional-forms' ),
					'help'          => __( 'Help text', 'functional-forms' ),
					'required'      => __( 'Required', 'functional-forms' ),
					'default'       => __( 'Default value', 'functional-forms' ),
					'defaultHelp'   => __( 'For checkboxes, separate values with commas.', 'functional-forms' ),
					'choices'       => __( 'Choices', 'functional-forms' ),
					'choicesHelp'   => __( 'One per line. Use "value : Label" to store a different value.', 'functional-forms' ),
					'width'         => __( 'Width', 'functional-forms' ),
					'full'          => __( 'Full width', 'functional-forms' ),
					'half'          => __( 'Half width', 'functional-forms' ),
					'min'           => __( 'Minimum', 'functional-forms' ),
					'max'           => __( 'Maximum', 'functional-forms' ),
					'rows'          => __( 'Rows', 'functional-forms' ),
					'allowed'       => __( 'Allowed file types', 'functional-forms' ),
					'allowedHelp'   => __( 'Comma separated extensions, e.g. jpg,png,pdf', 'functional-forms' ),
					'maxSize'       => __( 'Max size per file (MB)', 'functional-forms' ),
					'maxFiles'      => __( 'Max number of files', 'functional-forms' ),
					'content'       => __( 'Content (HTML allowed)', 'functional-forms' ),
					'consentLabel'  => __( 'I agree to the terms and privacy policy.', 'functional-forms' ),
				),
			)
		);
	}

	public static function columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : null;
		unset( $columns['date'] );
		$columns['ffm_shortcode'] = __( 'Shortcode', 'functional-forms' );
		$columns['ffm_fields']    = __( 'Fields', 'functional-forms' );
		$columns['ffm_entries']   = __( 'Entries', 'functional-forms' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public static function column_content( $column, $post_id ) {
		if ( 'ffm_shortcode' === $column ) {
			echo '<input type="text" class="code" readonly onfocus="this.select()" size="28" value="' . esc_attr( self::shortcode( $post_id ) ) . '" />';
		} elseif ( 'ffm_fields' === $column ) {
			echo (int) count( self::get_fields( $post_id ) );
		} elseif ( 'ffm_entries' === $column ) {
			$count = FFM_Entries::count( $post_id );
			$url   = add_query_arg(
				array(
					'post_type' => FFM_Entries::POST_TYPE,
					'ffm_form'  => $post_id,
				),
				admin_url( 'edit.php' )
			);
			echo '<a href="' . esc_url( $url ) . '">' . (int) $count . '</a>';
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( self::POST_TYPE === $post->post_type && current_user_can( 'manage_options' ) ) {
			$actions['ffm_entries'] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . FFM_Entries::POST_TYPE . '&ffm_form=' . $post->ID ) ) . '">' . esc_html__( 'Entries', 'functional-forms' ) . '</a>';
			$actions['ffm_export']  = '<a href="' . esc_url( FFM_Entries::export_url( $post->ID ) ) . '">' . esc_html__( 'Export CSV', 'functional-forms' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Published forms, for the block picker.
	 *
	 * @return array[] id, title
	 */
	public static function published_forms() {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		return array_map(
			function ( $p ) {
				return array(
					'id'    => $p->ID,
					'title' => $p->post_title ? $p->post_title : sprintf( /* translators: %d: form ID */ __( 'Form #%d', 'functional-forms' ), $p->ID ),
				);
			},
			$posts
		);
	}
}
