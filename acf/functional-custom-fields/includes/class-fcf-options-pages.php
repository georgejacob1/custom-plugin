<?php
/**
 * Options pages: admin pages (e.g. "Common Content") whose field values are
 * site-wide and stored in wp_options as "options_{field name}" (same keys as ACF).
 *
 * Pages are created in Custom Fields → Options Pages, or in code with fcf_add_options_page().
 */

defined( 'ABSPATH' ) || exit;

class FCF_Options_Pages {

	const POST_TYPE = 'fcf_options_page';

	/** Prefix of the option names values are stored under. */
	const OPTION_PREFIX = 'options_';

	/** @var array Pages registered in code. slug => args */
	protected static $registered = array();

	/** @var array|null Cached pages. */
	protected static $pages = null;

	/** @var array Admin hook suffix => page slug. */
	protected static $hooks = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 99 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_page_settings' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
	}

	/* ---------- Registry ---------- */

	/**
	 * Register an options page in code.
	 *
	 * @param array $args page_title, menu_title, menu_slug, parent_slug, capability, position, icon_url.
	 * @return array The page.
	 */
	public static function add( $args ) {
		$page                                 = self::normalize( $args );
		self::$registered[ $page['menu_slug'] ] = $page;
		self::$pages                          = null;
		return $page;
	}

	protected static function normalize( $args ) {
		$args  = wp_parse_args(
			$args,
			array(
				'page_title'  => __( 'Options', 'functional-custom-fields' ),
				'menu_title'  => '',
				'menu_slug'   => '',
				'parent_slug' => '',
				'capability'  => 'edit_posts',
				'position'    => null,
				'icon_url'    => 'dashicons-admin-generic',
			)
		);
		$title = (string) $args['page_title'];

		return array(
			'page_title'  => $title,
			'menu_title'  => '' !== (string) $args['menu_title'] ? (string) $args['menu_title'] : $title,
			'menu_slug'   => '' !== (string) $args['menu_slug'] ? sanitize_title( $args['menu_slug'] ) : 'options-' . sanitize_title( $title ),
			'parent_slug' => (string) $args['parent_slug'],
			'capability'  => '' !== (string) $args['capability'] ? (string) $args['capability'] : 'edit_posts',
			'position'    => is_numeric( $args['position'] ) ? (float) $args['position'] : null,
			'icon_url'    => '' !== (string) $args['icon_url'] ? (string) $args['icon_url'] : 'dashicons-admin-generic',
		);
	}

	/**
	 * All options pages, from the admin UI and from code.
	 *
	 * @return array slug => page
	 */
	public static function pages() {
		if ( null !== self::$pages ) {
			return self::$pages;
		}

		self::$pages = array();
		$posts       = get_posts(
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

		foreach ( $posts as $post ) {
			$page                              = self::normalize( self::post_settings( $post ) );
			self::$pages[ $page['menu_slug'] ] = $page;
		}

		self::$pages = self::$registered + self::$pages;
		return self::$pages;
	}

	protected static function post_settings( $post ) {
		return array(
			'page_title'  => '' !== $post->post_title ? $post->post_title : __( 'Options', 'functional-custom-fields' ),
			'menu_title'  => get_post_meta( $post->ID, '_fcf_menu_title', true ),
			'menu_slug'   => '' !== $post->post_name ? $post->post_name : 'options-' . $post->ID,
			'parent_slug' => get_post_meta( $post->ID, '_fcf_parent_slug', true ),
			'capability'  => get_post_meta( $post->ID, '_fcf_capability', true ),
			'position'    => get_post_meta( $post->ID, '_fcf_menu_position', true ),
			'icon_url'    => get_post_meta( $post->ID, '_fcf_icon', true ),
		);
	}

	/**
	 * Field groups whose location rules point at an options page.
	 *
	 * @param string $slug Page slug.
	 * @return array
	 */
	public static function groups_for_page( $slug ) {
		$matches = array();
		foreach ( FCF_Field_Groups::all_groups() as $group ) {
			if ( FCF_Location::matches_options_page( $group['location'], $slug ) ) {
				$matches[] = $group;
			}
		}
		return $matches;
	}

	/**
	 * Groups shown on any options page.
	 *
	 * @return array
	 */
	public static function all_option_groups() {
		$matches = array();
		foreach ( array_keys( self::pages() ) as $slug ) {
			foreach ( self::groups_for_page( $slug ) as $group ) {
				$matches[ $group['id'] ] = $group;
			}
		}
		return array_values( $matches );
	}

	/* ---------- Values ---------- */

	public static function option_name( $name ) {
		return self::OPTION_PREFIX . $name;
	}

	/**
	 * Stored value of an options field, or null when never saved.
	 */
	public static function get_value( $name ) {
		$missing = new stdClass();
		$value   = get_option( self::option_name( $name ), $missing );
		return $missing === $value ? null : $value;
	}

	public static function update_value( $name, $value ) {
		return update_option( self::option_name( $name ), $value, false );
	}

	/* ---------- Admin menu and page ---------- */

	public static function admin_menu() {
		$pages = self::pages();

		// Top-level pages first so sub pages can attach to them.
		uasort(
			$pages,
			function ( $a, $b ) {
				return (int) ( '' !== $a['parent_slug'] ) - (int) ( '' !== $b['parent_slug'] );
			}
		);

		$has_children = array();
		foreach ( $pages as $page ) {
			if ( '' !== $page['parent_slug'] ) {
				$has_children[ $page['parent_slug'] ] = true;
			}
		}

		foreach ( $pages as $slug => $page ) {
			if ( '' === $page['parent_slug'] ) {
				$hook = add_menu_page( $page['page_title'], $page['menu_title'], $page['capability'], $slug, array( __CLASS__, 'render_page' ), $page['icon_url'], $page['position'] );
				if ( isset( $has_children[ $slug ] ) ) {
					// Keep the parent's own entry as the first item of its sub menu.
					add_submenu_page( $slug, $page['page_title'], $page['menu_title'], $page['capability'], $slug, array( __CLASS__, 'render_page' ) );
				}
			} else {
				$hook = add_submenu_page( $page['parent_slug'], $page['page_title'], $page['menu_title'], $page['capability'], $slug, array( __CLASS__, 'render_page' ), $page['position'] );
			}

			if ( $hook ) {
				self::$hooks[ $hook ] = $slug;
				add_action( 'load-' . $hook, array( __CLASS__, 'save_values' ) );
			}
		}
	}

	/**
	 * Slug of the options page being viewed, or ''.
	 */
	protected static function current_slug() {
		global $plugin_page;
		return is_string( $plugin_page ) && in_array( $plugin_page, self::$hooks, true ) ? $plugin_page : '';
	}

	public static function save_values() {
		$slug  = self::current_slug();
		$pages = self::pages();
		if ( '' === $slug || ! isset( $pages[ $slug ] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		if ( ! isset( $_POST['fcf_options_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['fcf_options_nonce'] ), 'fcf_save_options_' . $slug ) ) {
			return;
		}
		if ( ! current_user_can( $pages[ $slug ]['capability'] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit these options.', 'functional-custom-fields' ) );
		}

		$submitted = isset( $_POST['fcf'] ) && is_array( $_POST['fcf'] ) ? wp_unslash( $_POST['fcf'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field type.

		foreach ( self::groups_for_page( $slug ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( ! array_key_exists( $field['name'], $submitted ) ) {
					continue;
				}
				self::update_value( $field['name'], FCF_Fields::sanitize_value( $field, $submitted[ $field['name'] ] ) );
			}
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ? wp_get_referer() : menu_page_url( $slug, false ) ) );
		exit;
	}

	public static function render_page() {
		$slug   = self::current_slug();
		$pages  = self::pages();
		$page   = $pages[ $slug ];
		$groups = array_filter(
			self::groups_for_page( $slug ),
			function ( $group ) {
				return (bool) $group['fields'];
			}
		);
		$main   = wp_list_filter( $groups, array( 'position' => 'normal' ) );
		$side   = wp_list_filter( $groups, array( 'position' => 'side' ) );
		?>
		<div class="wrap fcf-options-wrap">
			<h1><?php echo esc_html( $page['page_title'] ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Options updated.', 'functional-custom-fields' ); ?></p></div>
			<?php endif; ?>

			<form id="fcf-options-form" method="post">
				<?php wp_nonce_field( 'fcf_save_options_' . $slug, 'fcf_options_nonce' ); ?>
				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="postbox-container-1" class="postbox-container">
							<div class="postbox">
								<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Publish', 'functional-custom-fields' ); ?></h2></div>
								<div class="inside">
									<div id="major-publishing-actions">
										<div id="publishing-action">
											<span class="spinner"></span>
											<button type="submit" id="publish" class="button button-primary button-large"><?php esc_html_e( 'Update', 'functional-custom-fields' ); ?></button>
										</div>
										<div class="clear"></div>
									</div>
								</div>
							</div>
							<?php self::render_boxes( $side ); ?>
						</div>
						<div id="postbox-container-2" class="postbox-container">
							<?php if ( ! $groups ) : ?>
								<div class="notice notice-info inline">
									<p>
										<?php
										printf(
											/* translators: 1: link to the field groups screen, 2: options page title */
											esc_html__( 'No field groups are assigned to this options page yet. Create one in %1$s with the location rule "Options Page is equal to %2$s".', 'functional-custom-fields' ),
											'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . FCF_Field_Groups::POST_TYPE ) ) . '">' . esc_html__( 'Custom Fields', 'functional-custom-fields' ) . '</a>',
											esc_html( $page['page_title'] )
										);
										?>
									</p>
								</div>
							<?php endif; ?>
							<?php self::render_boxes( $main ); ?>
						</div>
					</div>
					<br class="clear" />
				</div>
			</form>
		</div>
		<?php
	}

	protected static function render_boxes( $groups ) {
		foreach ( $groups as $group ) {
			?>
			<div class="postbox" id="fcf-group-<?php echo esc_attr( $group['id'] ); ?>">
				<div class="postbox-header"><h2 class="hndle"><?php echo esc_html( '' !== $group['title'] ? $group['title'] : __( 'Custom Fields', 'functional-custom-fields' ) ); ?></h2></div>
				<div class="inside">
					<div class="fcf-fields">
						<?php
						foreach ( $group['fields'] as $field ) {
							FCF_Fields::render_field( $field, self::get_value( $field['name'] ), 'fcf[' . $field['name'] . ']', 'fcf-' . $field['name'] );
						}
						?>
					</div>
				</div>
			</div>
			<?php
		}
	}

	public static function enqueue() {
		$slug = self::current_slug();
		if ( '' === $slug || ! self::groups_for_page( $slug ) ) {
			return;
		}
		FCF_Meta_Boxes::enqueue_assets();
		wp_add_inline_style( 'fcf-fields', '.fcf-options-wrap #publishing-action{float:none;text-align:right}.fcf-options-wrap #major-publishing-actions{border-top:0;background:none;padding:0}' );
	}

	/* ---------- Admin UI for options pages ---------- */

	public static function register_post_type() {
		$cap = 'manage_options';

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Options Pages', 'functional-custom-fields' ),
					'singular_name' => __( 'Options Page', 'functional-custom-fields' ),
					'menu_name'     => __( 'Options Pages', 'functional-custom-fields' ),
					'all_items'     => __( 'Options Pages', 'functional-custom-fields' ),
					'add_new'       => __( 'Add New', 'functional-custom-fields' ),
					'add_new_item'  => __( 'Add New Options Page', 'functional-custom-fields' ),
					'edit_item'     => __( 'Edit Options Page', 'functional-custom-fields' ),
					'search_items'  => __( 'Search Options Pages', 'functional-custom-fields' ),
					'not_found'     => __( 'No options pages found.', 'functional-custom-fields' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . FCF_Field_Groups::POST_TYPE,
				'supports'     => array( 'title' ),
				'hierarchical' => false,
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

	public static function title_placeholder( $placeholder, $post ) {
		return self::POST_TYPE === $post->post_type ? __( 'Page title, e.g. Common Content', 'functional-custom-fields' ) : $placeholder;
	}

	public static function add_meta_boxes() {
		add_meta_box( 'fcf-options-page-settings', __( 'Settings', 'functional-custom-fields' ), array( __CLASS__, 'render_settings' ), self::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Menus an options page can be placed under.
	 *
	 * @param string $exclude Slug of the page being edited.
	 * @return array slug => label
	 */
	protected static function parent_choices( $exclude = '' ) {
		$choices = array(
			''                         => __( 'No parent (top-level menu)', 'functional-custom-fields' ),
			'index.php'                => __( 'Dashboard', 'functional-custom-fields' ),
			'edit.php'                 => __( 'Posts', 'functional-custom-fields' ),
			'upload.php'               => __( 'Media', 'functional-custom-fields' ),
			'edit.php?post_type=page'  => __( 'Pages', 'functional-custom-fields' ),
			'themes.php'               => __( 'Appearance', 'functional-custom-fields' ),
			'plugins.php'              => __( 'Plugins', 'functional-custom-fields' ),
			'users.php'                => __( 'Users', 'functional-custom-fields' ),
			'tools.php'                => __( 'Tools', 'functional-custom-fields' ),
			'options-general.php'      => __( 'Settings', 'functional-custom-fields' ),
		);
		foreach ( self::pages() as $slug => $page ) {
			if ( $slug !== $exclude && '' === $page['parent_slug'] ) {
				/* translators: %s: options page title */
				$choices[ $slug ] = sprintf( __( 'Options page: %s', 'functional-custom-fields' ), $page['page_title'] );
			}
		}
		return $choices;
	}

	public static function render_settings( $post ) {
		wp_nonce_field( 'fcf_save_options_page', 'fcf_options_page_nonce' );
		$is_new   = 'auto-draft' === $post->post_status;
		$parent   = (string) get_post_meta( $post->ID, '_fcf_parent_slug', true );
		$cap      = (string) get_post_meta( $post->ID, '_fcf_capability', true );
		$position = (string) get_post_meta( $post->ID, '_fcf_menu_position', true );
		$icon     = (string) get_post_meta( $post->ID, '_fcf_icon', true );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fcf-menu-title"><?php esc_html_e( 'Menu Title', 'functional-custom-fields' ); ?></label></th>
				<td>
					<input type="text" id="fcf-menu-title" class="regular-text" name="fcf_menu_title" value="<?php echo esc_attr( get_post_meta( $post->ID, '_fcf_menu_title', true ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Text in the admin menu. Defaults to the page title.', 'functional-custom-fields' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fcf-menu-slug"><?php esc_html_e( 'Menu Slug', 'functional-custom-fields' ); ?></label></th>
				<td>
					<input type="text" id="fcf-menu-slug" class="regular-text" name="fcf_menu_slug" value="<?php echo esc_attr( $is_new ? '' : $post->post_name ); ?>" />
					<p class="description"><?php esc_html_e( 'Used in the page URL (admin.php?page=slug). Defaults to "options-" plus the page title.', 'functional-custom-fields' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fcf-parent-slug"><?php esc_html_e( 'Parent Page', 'functional-custom-fields' ); ?></label></th>
				<td>
					<select id="fcf-parent-slug" name="fcf_parent_slug">
						<?php foreach ( self::parent_choices( $post->post_name ) as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $parent, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fcf-menu-position"><?php esc_html_e( 'Menu Position', 'functional-custom-fields' ); ?></label></th>
				<td>
					<input type="number" step="any" id="fcf-menu-position" class="small-text" name="fcf_menu_position" value="<?php echo esc_attr( $position ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. E.g. 2 = below Dashboard, 20 = below Pages, 80 = below Settings.', 'functional-custom-fields' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fcf-icon"><?php esc_html_e( 'Menu Icon', 'functional-custom-fields' ); ?></label></th>
				<td>
					<input type="text" id="fcf-icon" class="regular-text" name="fcf_icon" value="<?php echo esc_attr( $icon ); ?>" placeholder="dashicons-admin-generic" />
					<p class="description"><?php esc_html_e( 'Dashicon class or image URL, for top-level pages.', 'functional-custom-fields' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fcf-capability"><?php esc_html_e( 'Capability', 'functional-custom-fields' ); ?></label></th>
				<td>
					<input type="text" id="fcf-capability" class="regular-text" name="fcf_capability" value="<?php echo esc_attr( $cap ); ?>" placeholder="edit_posts" />
					<p class="description"><?php esc_html_e( 'Users need this capability to see and edit the page.', 'functional-custom-fields' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_page_settings( $post_id, $post ) {
		if ( ! isset( $_POST['fcf_options_page_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['fcf_options_page_nonce'] ), 'fcf_save_options_page' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$text = function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		};

		update_post_meta( $post_id, '_fcf_menu_title', $text( 'fcf_menu_title' ) );
		update_post_meta( $post_id, '_fcf_parent_slug', $text( 'fcf_parent_slug' ) );
		update_post_meta( $post_id, '_fcf_capability', sanitize_key( $text( 'fcf_capability' ) ) );
		update_post_meta( $post_id, '_fcf_menu_position', is_numeric( $text( 'fcf_menu_position' ) ) ? $text( 'fcf_menu_position' ) : '' );
		update_post_meta( $post_id, '_fcf_icon', $text( 'fcf_icon' ) );

		// The menu slug is the post slug.
		$slug = sanitize_title( $text( 'fcf_menu_slug' ) );
		if ( '' === $slug ) {
			$slug = 'options-' . sanitize_title( $post->post_title );
		}
		if ( 'options-' !== $slug && $slug !== $post->post_name ) {
			remove_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_page_settings' ), 10 );
			wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => wp_unique_post_slug( $slug, $post_id, 'publish', self::POST_TYPE, 0 ),
				)
			);
			add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_page_settings' ), 10, 2 );
		}
	}

	public static function columns( $columns ) {
		return array(
			'cb'         => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'      => __( 'Title', 'functional-custom-fields' ),
			'fcf_slug'   => __( 'Menu Slug', 'functional-custom-fields' ),
			'fcf_parent' => __( 'Parent Page', 'functional-custom-fields' ),
			'fcf_groups' => __( 'Field Groups', 'functional-custom-fields' ),
		);
	}

	public static function column_content( $column, $post_id ) {
		$post = get_post( $post_id );
		$slug = $post->post_name;

		if ( 'fcf_slug' === $column ) {
			if ( 'publish' === $post->post_status && '' !== $slug ) {
				printf( '<a href="%s"><code>%s</code></a>', esc_url( admin_url( 'admin.php?page=' . $slug ) ), esc_html( $slug ) );
			} else {
				echo '<code>' . esc_html( $slug ) . '</code>';
			}
		} elseif ( 'fcf_parent' === $column ) {
			$parent  = (string) get_post_meta( $post_id, '_fcf_parent_slug', true );
			$choices = self::parent_choices( $slug );
			echo esc_html( isset( $choices[ $parent ] ) ? $choices[ $parent ] : $parent );
		} elseif ( 'fcf_groups' === $column ) {
			$titles = wp_list_pluck( self::groups_for_page( $slug ), 'title' );
			echo $titles ? esc_html( implode( ', ', $titles ) ) : '&mdash;';
		}
	}
}
