<?php
/**
 * Field groups: admin post type, builder UI and location rules.
 */

defined( 'ABSPATH' ) || exit;

class FCF_Field_Groups {

	const POST_TYPE = 'fcf_group';

	/** @var array|null Cached published groups. */
	protected static $groups = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
	}

	public static function register_post_type() {
		$cap = 'manage_options';

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Field Groups', 'functional-custom-fields' ),
					'singular_name' => __( 'Field Group', 'functional-custom-fields' ),
					'menu_name'     => __( 'Custom Fields', 'functional-custom-fields' ),
					'add_new'       => __( 'Add New', 'functional-custom-fields' ),
					'add_new_item'  => __( 'Add New Field Group', 'functional-custom-fields' ),
					'edit_item'     => __( 'Edit Field Group', 'functional-custom-fields' ),
					'search_items'  => __( 'Search Field Groups', 'functional-custom-fields' ),
					'not_found'     => __( 'No field groups found.', 'functional-custom-fields' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-forms',
				'menu_position'   => 80,
				'supports'        => array( 'title', 'page-attributes' ),
				'hierarchical'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'map_meta_cap'    => false,
				'capabilities'    => array(
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

	public static function add_meta_boxes() {
		add_meta_box( 'fcf-builder', __( 'Fields', 'functional-custom-fields' ), array( __CLASS__, 'render_builder' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'fcf-location', __( 'Location & Settings', 'functional-custom-fields' ), array( __CLASS__, 'render_location' ), self::POST_TYPE, 'normal', 'default' );
	}

	public static function render_builder( $post ) {
		wp_nonce_field( 'fcf_save_group', 'fcf_group_nonce' );
		$fields = self::get_fields( $post->ID );
		?>
		<div id="fcf-builder">
			<div class="fcf-b-columns">
				<span class="fcf-b-col-label"><?php esc_html_e( 'Label', 'functional-custom-fields' ); ?></span>
				<span class="fcf-b-col-name"><?php esc_html_e( 'Name', 'functional-custom-fields' ); ?></span>
				<span class="fcf-b-col-type"><?php esc_html_e( 'Type', 'functional-custom-fields' ); ?></span>
			</div>
			<ul class="fcf-b-list fcf-b-root"></ul>
			<p class="fcf-b-empty"><?php esc_html_e( 'No fields yet. Click "Add Field" to create your first field.', 'functional-custom-fields' ); ?></p>
			<p><button type="button" class="button button-primary fcf-b-add"><?php esc_html_e( '+ Add Field', 'functional-custom-fields' ); ?></button></p>
			<textarea id="fcf-fields-json" name="fcf_fields_json" hidden><?php echo esc_textarea( wp_json_encode( $fields ) ); ?></textarea>
		</div>
		<?php
	}

	public static function render_location( $post ) {
		$selected_types = (array) get_post_meta( $post->ID, '_fcf_post_types', true );
		$template       = (string) get_post_meta( $post->ID, '_fcf_page_template', true );
		$position       = (string) get_post_meta( $post->ID, '_fcf_position', true );

		if ( 'auto-draft' === $post->post_status && ! array_filter( $selected_types ) ) {
			$selected_types = array( 'page' );
		}

		$templates = array_merge(
			array( 'default' => __( 'Default Template', 'functional-custom-fields' ) ),
			wp_get_theme()->get_page_templates()
		);
		?>
		<table class="form-table fcf-location-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Show on post types', 'functional-custom-fields' ); ?></th>
				<td>
					<?php foreach ( self::available_post_types() as $type ) : ?>
						<label class="fcf-inline-check">
							<input type="checkbox" name="fcf_post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $selected_types, true ) ); ?> />
							<?php echo esc_html( $type->labels->singular_name ); ?> <code><?php echo esc_html( $type->name ); ?></code>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fcf-page-template"><?php esc_html_e( 'Only for page template', 'functional-custom-fields' ); ?></label></th>
				<td>
					<select id="fcf-page-template" name="fcf_page_template">
						<option value=""><?php esc_html_e( '— Any template —', 'functional-custom-fields' ); ?></option>
						<?php foreach ( $templates as $file => $label ) : ?>
							<option value="<?php echo esc_attr( $file ); ?>" <?php selected( $template, $file ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Optional. After changing a page\'s template, save and reload the page to see matching field groups.', 'functional-custom-fields' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fcf-position"><?php esc_html_e( 'Position', 'functional-custom-fields' ); ?></label></th>
				<td>
					<select id="fcf-position" name="fcf_position">
						<option value="normal" <?php selected( $position, 'normal' ); ?>><?php esc_html_e( 'Normal (below content)', 'functional-custom-fields' ); ?></option>
						<option value="side" <?php selected( $position, 'side' ); ?>><?php esc_html_e( 'Side', 'functional-custom-fields' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Use "Order" in the Page Attributes box to sort multiple groups.', 'functional-custom-fields' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['fcf_group_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['fcf_group_nonce'] ), 'fcf_save_group' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$raw    = isset( $_POST['fcf_fields_json'] ) ? json_decode( wp_unslash( $_POST['fcf_fields_json'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized below.
		$fields = FCF_Fields::sanitize_definitions( $raw );
		update_post_meta( $post_id, '_fcf_fields', wp_slash( $fields ) );

		$valid_types = wp_list_pluck( self::available_post_types(), 'name' );
		$types       = isset( $_POST['fcf_post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fcf_post_types'] ) ) : array();
		update_post_meta( $post_id, '_fcf_post_types', array_values( array_intersect( $types, $valid_types ) ) );

		$template = isset( $_POST['fcf_page_template'] ) ? sanitize_text_field( wp_unslash( $_POST['fcf_page_template'] ) ) : '';
		update_post_meta( $post_id, '_fcf_page_template', $template );

		$position = isset( $_POST['fcf_position'] ) && 'side' === $_POST['fcf_position'] ? 'side' : 'normal';
		update_post_meta( $post_id, '_fcf_position', $position );
	}

	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'fcf-builder', FCF_URL . 'assets/css/builder.css', array(), FCF_VERSION );

		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'fcf-builder', FCF_URL . 'assets/js/builder.js', array( 'jquery', 'jquery-ui-sortable' ), FCF_VERSION, true );
		wp_localize_script(
			'fcf-builder',
			'fcfBuilder',
			array(
				'types' => FCF_Fields::types(),
				'i18n'  => array(
					'newField'      => __( 'New Field', 'functional-custom-fields' ),
					'addSubField'   => __( '+ Add Sub Field', 'functional-custom-fields' ),
					'confirmDelete' => __( 'Delete this field?', 'functional-custom-fields' ),
					'edit'          => __( 'Edit', 'functional-custom-fields' ),
					'duplicate'     => __( 'Duplicate', 'functional-custom-fields' ),
					'delete'        => __( 'Delete', 'functional-custom-fields' ),
				),
			)
		);
	}

	public static function columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : null;
		unset( $columns['date'] );
		$columns['fcf_fields']   = __( 'Fields', 'functional-custom-fields' );
		$columns['fcf_location'] = __( 'Location', 'functional-custom-fields' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public static function column_content( $column, $post_id ) {
		if ( 'fcf_fields' === $column ) {
			echo (int) count( self::get_fields( $post_id ) );
		} elseif ( 'fcf_location' === $column ) {
			$types    = array_filter( (array) get_post_meta( $post_id, '_fcf_post_types', true ) );
			$template = get_post_meta( $post_id, '_fcf_page_template', true );
			echo esc_html( $types ? implode( ', ', $types ) : '—' );
			if ( $template ) {
				echo '<br><small>' . esc_html( sprintf( /* translators: %s: template file */ __( 'Template: %s', 'functional-custom-fields' ), $template ) ) . '</small>';
			}
		}
	}

	/**
	 * Post types a group can be attached to.
	 *
	 * @return WP_Post_Type[]
	 */
	public static function available_post_types() {
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );
		foreach ( $types as $name => $type ) {
			if ( self::POST_TYPE === $name || 'attachment' === $name || 0 === strpos( $name, 'wp_' ) ) {
				unset( $types[ $name ] );
			}
		}
		return $types;
	}

	/**
	 * Field definitions of a group.
	 *
	 * @param int $group_id Group post ID.
	 * @return array
	 */
	public static function get_fields( $group_id ) {
		$fields = get_post_meta( $group_id, '_fcf_fields', true );
		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * All published field groups.
	 *
	 * @return array
	 */
	public static function all_groups() {
		if ( null !== self::$groups ) {
			return self::$groups;
		}

		self::$groups = array();
		$posts        = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'suppress_filters' => true,
			)
		);

		foreach ( $posts as $group ) {
			self::$groups[] = array(
				'id'         => $group->ID,
				'title'      => $group->post_title,
				'fields'     => self::get_fields( $group->ID ),
				'post_types' => array_filter( (array) get_post_meta( $group->ID, '_fcf_post_types', true ) ),
				'template'   => (string) get_post_meta( $group->ID, '_fcf_page_template', true ),
				'position'   => 'side' === get_post_meta( $group->ID, '_fcf_position', true ) ? 'side' : 'normal',
			);
		}

		return self::$groups;
	}

	/**
	 * Groups shown for a post.
	 *
	 * @param WP_Post $post           Post object.
	 * @param bool    $check_template Whether to apply the page template rule.
	 * @return array
	 */
	public static function groups_for_post( $post, $check_template = true ) {
		$matches = array();
		$current = get_page_template_slug( $post );
		$current = '' === $current ? 'default' : $current;

		foreach ( self::all_groups() as $group ) {
			if ( ! in_array( $post->post_type, $group['post_types'], true ) ) {
				continue;
			}
			if ( $check_template && '' !== $group['template'] && $group['template'] !== $current ) {
				continue;
			}
			$matches[] = $group;
		}

		return $matches;
	}

	/**
	 * Find a top-level field definition by name.
	 *
	 * @param string $name    Field name.
	 * @param int    $post_id Post the value belongs to.
	 * @return array|null
	 */
	public static function find_field( $name, $post_id = 0 ) {
		$post   = $post_id ? get_post( $post_id ) : null;
		$groups = $post ? self::groups_for_post( $post, false ) : array();

		// Prefer groups attached to this post, then fall back to any group.
		foreach ( array( $groups, self::all_groups() ) as $set ) {
			foreach ( $set as $group ) {
				foreach ( $group['fields'] as $field ) {
					if ( $field['name'] === $name ) {
						return $field;
					}
				}
			}
		}

		return null;
	}
}
