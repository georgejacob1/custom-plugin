<?php
/**
 * Field groups: admin post type, builder UI and location rules.
 */

defined( 'ABSPATH' ) || exit;

class FCF_Field_Groups {

	const POST_TYPE = 'fcf_group';

	/** @var array|null Cached published groups. */
	protected static $groups = null;

	/** @var array Groups registered in code with fcf_add_local_field_group(). key => group */
	protected static $local = array();

	/**
	 * Register a field group in code (same shape as a JSON export item).
	 *
	 * @param array $group key, title, fields, location, menu_order, position, description.
	 */
	public static function add_local( $group ) {
		if ( ! is_array( $group ) || empty( $group['fields'] ) ) {
			return;
		}
		$key                 = isset( $group['key'] ) && '' !== $group['key'] ? sanitize_key( $group['key'] ) : 'group_' . md5( wp_json_encode( $group ) );
		self::$local[ $key ] = $group;
		self::$groups        = null;
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'in_admin_header', array( __CLASS__, 'list_toolbar' ) );
		add_filter( 'disable_months_dropdown', array( __CLASS__, 'disable_months_dropdown' ), 10, 2 );
		add_filter( 'get_user_option_screen_layout_' . self::POST_TYPE, array( __CLASS__, 'one_column' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'set_status' ), 10, 2 );
	}

	/**
	 * The edit screen is a single column; the Publish box stays in the DOM (hidden) for saving.
	 */
	public static function one_column() {
		return 1;
	}

	/**
	 * The "Active" switch decides whether a saved group is published or a draft.
	 *
	 * @param array $data    Post data about to be saved.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public static function set_status( $data, $postarr ) {
		if ( self::POST_TYPE !== $data['post_type'] || in_array( $data['post_status'], array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return $data;
		}
		if ( ! isset( $_POST['fcf_group_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['fcf_group_nonce'] ), 'fcf_save_group' ) ) {
			return $data;
		}
		$data['post_status'] = empty( $_POST['fcf_active'] ) ? 'draft' : 'publish';
		return $data;
	}

	/**
	 * White ACF-style bar on top of the list and edit screens.
	 */
	public static function list_toolbar() {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( 'edit' === $screen->base ) {
			?>
			<div class="fcf-admin-toolbar">
				<h2><?php esc_html_e( 'Field Groups', 'functional-custom-fields' ); ?></h2>
				<a class="fcf-toolbar-btn is-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ); ?>">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Add New', 'functional-custom-fields' ); ?>
				</a>
			</div>
			<?php
			return;
		}

		if ( 'post' !== $screen->base ) {
			return;
		}
		?>
		<div class="fcf-admin-toolbar fcf-admin-toolbar-edit">
			<h2><?php echo 'add' === $screen->action ? esc_html__( 'Add New Field Group', 'functional-custom-fields' ) : esc_html__( 'Edit Field Group', 'functional-custom-fields' ); ?></h2>
			<div class="fcf-toolbar-title"></div>
			<div class="fcf-toolbar-actions">
				<button type="button" class="fcf-toolbar-btn fcf-toolbar-add-field">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Add Field', 'functional-custom-fields' ); ?>
				</button>
				<button type="button" class="fcf-toolbar-btn is-primary fcf-toolbar-save"><?php esc_html_e( 'Save Changes', 'functional-custom-fields' ); ?></button>
			</div>
		</div>
		<?php
	}

	public static function disable_months_dropdown( $disable, $post_type ) {
		return self::POST_TYPE === $post_type ? true : $disable;
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
				'supports'        => array( 'title' ),
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
		add_meta_box( 'fcf-location', __( 'Settings', 'functional-custom-fields' ), array( __CLASS__, 'render_location' ), self::POST_TYPE, 'normal', 'default' );
	}

	public static function render_builder( $post ) {
		wp_nonce_field( 'fcf_save_group', 'fcf_group_nonce' );
		$fields = self::get_fields( $post->ID );
		?>
		<div id="fcf-builder">
			<div class="fcf-b-columns">
				<span class="fcf-b-col-order">#</span>
				<span class="fcf-b-col-toggle"></span>
				<span class="fcf-b-col-label"><?php esc_html_e( 'Label', 'functional-custom-fields' ); ?></span>
				<span class="fcf-b-col-name"><?php esc_html_e( 'Name', 'functional-custom-fields' ); ?></span>
				<span class="fcf-b-col-type"><?php esc_html_e( 'Type', 'functional-custom-fields' ); ?></span>
			</div>
			<ul class="fcf-b-list fcf-b-root"></ul>
			<p class="fcf-b-empty"><?php esc_html_e( 'No fields yet. Click "Add Field" to create your first field.', 'functional-custom-fields' ); ?></p>
			<div class="fcf-b-footer">
				<button type="button" class="button fcf-b-add fcf-b-add-field"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add Field', 'functional-custom-fields' ); ?></button>
			</div>
			<textarea id="fcf-fields-json" name="fcf_fields_json" hidden><?php echo esc_textarea( wp_json_encode( $fields ) ); ?></textarea>
		</div>
		<?php
	}

	public static function render_location( $post ) {
		$position    = (string) get_post_meta( $post->ID, '_fcf_position', true );
		$description = (string) get_post_meta( $post->ID, '_fcf_description', true );
		$is_new      = 'auto-draft' === $post->post_status;
		$active      = $is_new || 'publish' === $post->post_status;
		$location    = FCF_Location::get( $post->ID );

		if ( $is_new && ! $location ) {
			$location = array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) );
		}

		$tabs = array(
			'location'     => __( 'Location Rules', 'functional-custom-fields' ),
			'presentation' => __( 'Presentation', 'functional-custom-fields' ),
			'group'        => __( 'Group Settings', 'functional-custom-fields' ),
		);
		?>
		<div class="fcf-settings">
			<div class="fcf-s-tabs" role="tablist">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="#" class="fcf-s-tab<?php echo 'location' === $id ? ' is-active' : ''; ?>" role="tab" data-tab="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>

			<div class="fcf-s-panel is-active" data-tab="location">
				<div class="fcf-s-setting fcf-s-setting-wide">
					<div class="fcf-s-label-row">
						<span class="fcf-s-label"><?php esc_html_e( 'Rules', 'functional-custom-fields' ); ?></span>
						<span class="fcf-s-help-icon dashicons dashicons-editor-help" tabindex="0" title="<?php esc_attr_e( 'Rules in a group must all match ("and"). The field group is shown when any group matches ("or"). Template, status, format, category and parent rules are checked when the edit screen loads, so save and reload after changing them.', 'functional-custom-fields' ); ?>"></span>
					</div>
					<span class="fcf-s-label"><?php esc_html_e( 'Show this field group if', 'functional-custom-fields' ); ?></span>
					<div class="fcf-l-groups"></div>
					<p class="fcf-l-or-label"><?php esc_html_e( 'or', 'functional-custom-fields' ); ?></p>
					<button type="button" class="fcf-toolbar-btn fcf-l-add-group"><?php esc_html_e( 'Add rule group', 'functional-custom-fields' ); ?></button>
					<textarea id="fcf-location-json" name="fcf_location_json" hidden><?php echo esc_textarea( wp_json_encode( $location ) ); ?></textarea>
				</div>
			</div>

			<div class="fcf-s-panel" data-tab="presentation">
				<div class="fcf-s-setting">
					<span class="fcf-s-label"><?php esc_html_e( 'Position', 'functional-custom-fields' ); ?></span>
					<ul class="fcf-s-radios">
						<li><label><input type="radio" name="fcf_position" value="normal" <?php checked( 'side' !== $position ); ?> /> <?php esc_html_e( 'Normal (below content)', 'functional-custom-fields' ); ?></label></li>
						<li><label><input type="radio" name="fcf_position" value="side" <?php checked( 'side' === $position ); ?> /> <?php esc_html_e( 'Side', 'functional-custom-fields' ); ?></label></li>
					</ul>
				</div>
				<div class="fcf-s-setting">
					<label class="fcf-s-label" for="fcf-menu-order"><?php esc_html_e( 'Order No.', 'functional-custom-fields' ); ?></label>
					<input type="number" id="fcf-menu-order" class="fcf-s-input fcf-s-input-small" name="menu_order" value="<?php echo esc_attr( (int) $post->menu_order ); ?>" />
					<p class="fcf-s-desc"><?php esc_html_e( 'Field groups with a lower order will appear first.', 'functional-custom-fields' ); ?></p>
				</div>
			</div>

			<div class="fcf-s-panel" data-tab="group">
				<div class="fcf-s-setting">
					<label class="fcf-s-switch-wrap">
						<input type="checkbox" name="fcf_active" value="1" <?php checked( $active ); ?> />
						<span class="fcf-s-switch" aria-hidden="true"></span>
						<span class="fcf-s-label"><?php esc_html_e( 'Active', 'functional-custom-fields' ); ?></span>
					</label>
					<p class="fcf-s-desc"><?php esc_html_e( 'Inactive field groups are saved as drafts and are not shown on edit screens.', 'functional-custom-fields' ); ?></p>
				</div>
				<div class="fcf-s-setting">
					<label class="fcf-s-label" for="fcf-description"><?php esc_html_e( 'Description', 'functional-custom-fields' ); ?></label>
					<input type="text" id="fcf-description" class="fcf-s-input" name="fcf_description" value="<?php echo esc_attr( $description ); ?>" />
					<p class="fcf-s-desc"><?php esc_html_e( 'Shown in the field group list.', 'functional-custom-fields' ); ?></p>
				</div>
				<?php if ( ! $is_new && current_user_can( 'delete_post', $post->ID ) ) : ?>
					<div class="fcf-s-setting fcf-s-danger">
						<span class="fcf-s-label"><?php esc_html_e( 'Delete Field Group', 'functional-custom-fields' ); ?></span>
						<p class="fcf-s-desc"><?php esc_html_e( 'Moves this field group to the trash. Saved field values stay in the database and come back if the group is restored.', 'functional-custom-fields' ); ?></p>
						<a href="<?php echo esc_url( get_delete_post_link( $post->ID ) ); ?>" class="fcf-toolbar-btn fcf-s-delete" onclick="return window.confirm( <?php echo esc_attr( wp_json_encode( __( 'Move this field group to the trash?', 'functional-custom-fields' ) ) ); ?> );">
							<span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e( 'Delete Field Group', 'functional-custom-fields' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>
		</div>
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

		if ( isset( $_POST['fcf_location_json'] ) ) {
			$location = FCF_Location::sanitize( json_decode( wp_unslash( $_POST['fcf_location_json'] ), true ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by FCF_Location.
			update_post_meta( $post_id, '_fcf_location', wp_slash( $location ) );
			// Superseded by location rules.
			delete_post_meta( $post_id, '_fcf_post_types' );
			delete_post_meta( $post_id, '_fcf_page_template' );
		}

		$position = isset( $_POST['fcf_position'] ) && 'side' === $_POST['fcf_position'] ? 'side' : 'normal';
		update_post_meta( $post_id, '_fcf_position', $position );

		$description = isset( $_POST['fcf_description'] ) ? sanitize_text_field( wp_unslash( $_POST['fcf_description'] ) ) : '';
		update_post_meta( $post_id, '_fcf_description', $description );
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

		$sizes = array();
		foreach ( wp_get_registered_image_subsizes() as $size => $data ) {
			$sizes[ $size ] = sprintf( '%s (%d x %d)', ucwords( str_replace( array( '-', '_' ), ' ', $size ) ), $data['width'], $data['height'] );
		}
		$sizes['full'] = __( 'Full Size', 'functional-custom-fields' );

		wp_enqueue_script( 'fcf-builder', FCF_URL . 'assets/js/builder.js', array( 'jquery', 'jquery-ui-sortable' ), FCF_VERSION, true );
		wp_localize_script(
			'fcf-builder',
			'fcfBuilder',
			array(
				'types'      => FCF_Fields::types(),
				'operators'  => FCF_Fields::logic_operators(),
				'imageSizes' => $sizes,
				'location'   => array(
					'params'    => self::js_pairs( FCF_Location::params() ),
					'choices'   => FCF_Location::choices(),
					'operators' => FCF_Location::operators(),
				),
				'i18n'       => array(
					'newField'      => __( '(no label)', 'functional-custom-fields' ),
					'addSubField'   => __( '+ Add Sub Field', 'functional-custom-fields' ),
					'confirmDelete' => __( 'Delete this field?', 'functional-custom-fields' ),
					'edit'          => __( 'Edit', 'functional-custom-fields' ),
					'duplicate'     => __( 'Duplicate', 'functional-custom-fields' ),
					'delete'        => __( 'Delete', 'functional-custom-fields' ),
				),
			)
		);
	}

	/**
	 * array( group => array( value => label ) ) to an order-safe list for JS.
	 */
	protected static function js_pairs( $grouped ) {
		$out = array();
		foreach ( $grouped as $group => $options ) {
			$pairs = array();
			foreach ( $options as $value => $label ) {
				$pairs[] = array( (string) $value, $label );
			}
			$out[] = array( (string) $group, $pairs );
		}
		return $out;
	}

	public static function columns( $columns ) {
		return array(
			'cb'           => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'           => __( 'Title', 'functional-custom-fields' ),
			'fcf_description' => __( 'Description', 'functional-custom-fields' ),
			'fcf_location'    => __( 'Location', 'functional-custom-fields' ),
			'fcf_fields'   => __( 'Fields', 'functional-custom-fields' ),
		);
	}

	public static function column_content( $column, $post_id ) {
		if ( 'fcf_description' === $column ) {
			$description = (string) get_post_meta( $post_id, '_fcf_description', true );
			echo '' !== $description ? esc_html( $description ) : '<span class="fcf-muted">&mdash;</span>';
		} elseif ( 'fcf_fields' === $column ) {
			$count = count( self::get_fields( $post_id ) );
			if ( $count && current_user_can( 'manage_options' ) ) {
				printf( '<a href="%s">%d</a>', esc_url( get_edit_post_link( $post_id ) ), (int) $count );
			} else {
				echo (int) $count;
			}
		} elseif ( 'fcf_location' === $column ) {
			$location = FCF_Location::get( $post_id );

			if ( ! $location ) {
				echo '<span class="fcf-muted">&mdash;</span>';
				return;
			}

			$param = $location[0][0]['param'];
			if ( 'options_page' === $param ) {
				$icon = 'admin-generic';
			} elseif ( 0 === strpos( $param, 'page' ) ) {
				$icon = 'admin-page';
			} else {
				$icon = 'admin-post';
			}

			printf(
				'<span class="fcf-location"><span class="dashicons dashicons-%s" aria-hidden="true"></span>%s</span>',
				esc_attr( $icon ),
				esc_html( FCF_Location::summary( $location ) )
			);
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
		return FCF_Fields::normalize( get_post_meta( $group_id, '_fcf_fields', true ) );
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

		$keys = array();
		foreach ( $posts as $group ) {
			$key = (string) get_post_meta( $group->ID, '_fcf_key', true );
			if ( '' !== $key ) {
				$keys[ $key ] = true;
			}
			self::$groups[] = array(
				'id'         => $group->ID,
				'title'      => $group->post_title,
				'fields'     => self::get_fields( $group->ID ),
				'location'   => FCF_Location::get( $group->ID ),
				'position'   => 'side' === get_post_meta( $group->ID, '_fcf_position', true ) ? 'side' : 'normal',
				'menu_order' => (int) $group->menu_order,
			);
		}

		// Code groups; a database group with the same key (e.g. imported) takes precedence.
		foreach ( self::$local as $key => $group ) {
			if ( isset( $keys[ $key ] ) ) {
				continue;
			}
			self::$groups[] = array(
				'id'         => $key,
				'title'      => isset( $group['title'] ) ? (string) $group['title'] : '',
				'fields'     => FCF_Fields::sanitize_definitions( FCF_Tools::convert_fields( $group['fields'] ) ),
				'location'   => FCF_Location::sanitize( isset( $group['location'] ) ? $group['location'] : array() ),
				'position'   => isset( $group['position'] ) && 'side' === $group['position'] ? 'side' : 'normal',
				'menu_order' => isset( $group['menu_order'] ) ? (int) $group['menu_order'] : 0,
			);
		}

		if ( self::$local ) {
			$order = array_flip( array_keys( self::$groups ) );
			uksort(
				self::$groups,
				function ( $a, $b ) use ( $order ) {
					$diff = self::$groups[ $a ]['menu_order'] - self::$groups[ $b ]['menu_order'];
					return $diff ? $diff : $order[ $a ] - $order[ $b ];
				}
			);
			self::$groups = array_values( self::$groups );
		}

		return self::$groups;
	}

	/**
	 * Groups shown for a post.
	 *
	 * @param WP_Post $post   Post object.
	 * @param bool    $strict False ignores rules that can change while editing (template, terms...).
	 * @return array
	 */
	public static function groups_for_post( $post, $strict = true ) {
		$matches = array();
		foreach ( self::all_groups() as $group ) {
			if ( FCF_Location::matches( $group['location'], $post, ! $strict ) ) {
				$matches[] = $group;
			}
		}
		return $matches;
	}

	/**
	 * Find a top-level field definition by name.
	 *
	 * @param string $name    Field name.
	 * @param int|string $post_id Post the value belongs to, or 'option'.
	 * @return array|null
	 */
	public static function find_field( $name, $post_id = 0 ) {
		if ( 'option' === $post_id ) {
			$groups = FCF_Options_Pages::all_option_groups();
		} else {
			$post   = $post_id ? get_post( $post_id ) : null;
			$groups = $post ? self::groups_for_post( $post, false ) : array();
		}

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
