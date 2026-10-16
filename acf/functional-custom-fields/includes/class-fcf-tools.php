<?php
/**
 * Tools: export field groups / options pages as JSON or PHP, and import JSON
 * (files exported by this plugin or by ACF).
 */

defined( 'ABSPATH' ) || exit;

class FCF_Tools {

	const SLUG = 'fcf-tools';

	/** ACF field types stored under another name here. */
	const TYPE_MAP = array(
		'date_picker'      => 'date',
		'date_time_picker' => 'date',
		'time_picker'      => 'text',
		'color_picker'     => 'color',
		'button_group'     => 'radio',
		'range'            => 'number',
		'password'         => 'text',
		'oembed'           => 'url',
		'link'             => 'url',
		'group'            => 'repeater',
	);

	/** ACF types that only show text on screen; nothing to import. */
	const SKIP_TYPES = array( 'message', 'accordion' );

	/** @var array Notices for this request: array( type, message ). */
	protected static $notices = array();

	/** @var string Generated PHP for this request. */
	protected static $php = '';

	/** @var array Unsupported fields met during the import. */
	protected static $skipped = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
	}

	public static function admin_menu() {
		$hook = add_submenu_page(
			'edit.php?post_type=' . FCF_Field_Groups::POST_TYPE,
			__( 'Tools', 'functional-custom-fields' ),
			__( 'Tools', 'functional-custom-fields' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		add_action( 'load-' . $hook, array( __CLASS__, 'handle' ) );
		add_action( 'admin_print_styles-' . $hook, array( __CLASS__, 'styles' ) );
	}

	public static function styles() {
		wp_enqueue_style( 'fcf-tools', FCF_URL . 'assets/css/tools.css', array(), FCF_VERSION );
	}

	/* ---------- Requests ---------- */

	public static function handle() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		if ( ! isset( $_POST['fcf_tools_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['fcf_tools_nonce'] ), 'fcf_tools' ) ) {
			self::$notices[] = array( 'error', __( 'The link has expired. Please try again.', 'functional-custom-fields' ) );
			return;
		}

		$action = isset( $_POST['fcf_action'] ) ? sanitize_key( $_POST['fcf_action'] ) : '';

		if ( 'import' === $action ) {
			self::import_upload();
			return;
		}

		$group_ids = isset( $_POST['fcf_groups'] ) ? array_map( 'absint', (array) $_POST['fcf_groups'] ) : array();
		$page_ids  = isset( $_POST['fcf_pages'] ) ? array_map( 'absint', (array) $_POST['fcf_pages'] ) : array();
		if ( ! $group_ids && ! $page_ids ) {
			self::$notices[] = array( 'warning', __( 'Select at least one field group or options page to export.', 'functional-custom-fields' ) );
			return;
		}

		$data = self::export_data( $group_ids, $page_ids );

		if ( 'export_json' === $action ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=fcf-export-' . gmdate( 'Y-m-d' ) . '.json' );
			echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download.
			exit;
		}

		if ( 'export_php' === $action ) {
			self::$php = self::php_code( $data );
		}
	}

	/* ---------- Export ---------- */

	/**
	 * Export items: options pages first, then field groups (ACF-like shape).
	 *
	 * @return array
	 */
	public static function export_data( $group_ids, $page_ids ) {
		$out = array();

		foreach ( $page_ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || FCF_Options_Pages::POST_TYPE !== $post->post_type ) {
				continue;
			}
			$out[] = array(
				'key'         => self::ensure_key( $post->ID, 'ui_options_page_' ),
				'page_title'  => $post->post_title,
				'menu_title'  => (string) get_post_meta( $post->ID, '_fcf_menu_title', true ),
				'menu_slug'   => $post->post_name,
				'parent_slug' => (string) get_post_meta( $post->ID, '_fcf_parent_slug', true ),
				'capability'  => (string) get_post_meta( $post->ID, '_fcf_capability', true ),
				'position'    => (string) get_post_meta( $post->ID, '_fcf_menu_position', true ),
				'icon_url'    => (string) get_post_meta( $post->ID, '_fcf_icon', true ),
				'active'      => 'publish' === $post->post_status,
			);
		}

		foreach ( $group_ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || FCF_Field_Groups::POST_TYPE !== $post->post_type ) {
				continue;
			}
			$out[] = array(
				'key'         => self::ensure_key( $post->ID, 'group_' ),
				'title'       => $post->post_title,
				'fields'      => FCF_Field_Groups::get_fields( $post->ID ),
				'location'    => FCF_Location::get( $post->ID ),
				'menu_order'  => (int) $post->menu_order,
				'position'    => 'side' === get_post_meta( $post->ID, '_fcf_position', true ) ? 'side' : 'normal',
				'active'      => 'publish' === $post->post_status,
				'description' => (string) get_post_meta( $post->ID, '_fcf_description', true ),
			);
		}

		return $out;
	}

	/**
	 * Stable key of a group / options page, created on first export.
	 */
	protected static function ensure_key( $post_id, $prefix ) {
		$key = (string) get_post_meta( $post_id, '_fcf_key', true );
		if ( '' === $key ) {
			$key = $prefix . strtolower( wp_generate_password( 13, false, false ) );
			update_post_meta( $post_id, '_fcf_key', $key );
		}
		return $key;
	}

	/**
	 * PHP that registers the exported items in code.
	 */
	public static function php_code( $data ) {
		$lines = array( "add_action( 'init', function () {" );
		foreach ( $data as $item ) {
			unset( $item['active'] );
			if ( isset( $item['menu_slug'] ) ) {
				$lines[] = "\tfcf_add_options_page( " . self::php_value( $item, 1 ) . ' );';
			} else {
				$lines[] = "\tfcf_add_local_field_group( " . self::php_value( $item, 1 ) . ' );';
			}
			$lines[] = '';
		}
		if ( '' === end( $lines ) ) {
			array_pop( $lines );
		}
		$lines[] = '} );';
		return implode( "\n", $lines );
	}

	/**
	 * var_export() with WordPress formatting (tabs, array()).
	 */
	protected static function php_value( $value, $depth ) {
		if ( is_array( $value ) ) {
			if ( ! $value ) {
				return 'array()';
			}
			$pad  = str_repeat( "\t", $depth + 1 );
			$list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			$rows = array();
			foreach ( $value as $k => $v ) {
				$rows[] = $pad . ( $list ? '' : var_export( $k, true ) . ' => ' ) . self::php_value( $v, $depth + 1 ) . ',';
			}
			return "array(\n" . implode( "\n", $rows ) . "\n" . str_repeat( "\t", $depth ) . ')';
		}
		return var_export( $value, true );
	}

	/* ---------- Import ---------- */

	protected static function import_upload() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified in handle().
		$file = isset( $_FILES['fcf_import_file'] ) ? $_FILES['fcf_import_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable

		if ( ! $file || empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			self::$notices[] = array( 'error', __( 'Please choose a JSON file to import.', 'functional-custom-fields' ) );
			return;
		}
		if ( 'json' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
			self::$notices[] = array( 'error', __( 'The file must be a .json export.', 'functional-custom-fields' ) );
			return;
		}

		$data = json_decode( (string) file_get_contents( $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) ) {
			self::$notices[] = array( 'error', __( 'The file is not valid JSON.', 'functional-custom-fields' ) );
			return;
		}

		$result = self::import( $data );

		if ( ! $result['groups'] && ! $result['pages'] ) {
			self::$notices[] = array( 'error', __( 'No field groups or options pages were found in the file.', 'functional-custom-fields' ) );
			return;
		}

		$links = array();
		foreach ( $result['groups'] as $id ) {
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $id ) ), esc_html( get_the_title( $id ) ) );
		}
		foreach ( $result['pages'] as $id ) {
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $id ) ), esc_html( get_the_title( $id ) ) );
		}
		self::$notices[] = array(
			'success',
			sprintf(
				/* translators: 1: number of field groups, 2: number of options pages, 3: list of links */
				esc_html__( 'Imported %1$d field group(s) and %2$d options page(s): %3$s', 'functional-custom-fields' ),
				count( $result['groups'] ),
				count( $result['pages'] ),
				implode( ', ', $links )
			),
			true,
		);

		if ( self::$skipped ) {
			self::$notices[] = array(
				'warning',
				sprintf(
					/* translators: %s: list of fields */
					__( 'These fields use types this plugin does not support and were skipped: %s', 'functional-custom-fields' ),
					implode( ', ', array_unique( self::$skipped ) )
				),
			);
		}
	}

	/**
	 * Import decoded JSON. Items with a key that already exists update the existing post.
	 *
	 * @param array $data A single item or a list of items.
	 * @return array array( 'groups' => ids, 'pages' => ids )
	 */
	public static function import( $data ) {
		// A single exported group / page.
		if ( isset( $data['fields'] ) || isset( $data['menu_slug'] ) ) {
			$data = array( $data );
		}

		$result = array(
			'groups' => array(),
			'pages'  => array(),
		);

		// Options pages first so location rules can point at them.
		foreach ( $data as $item ) {
			if ( is_array( $item ) && isset( $item['menu_slug'] ) && ! isset( $item['fields'] ) ) {
				$id = self::import_page( $item );
				if ( $id ) {
					$result['pages'][] = $id;
				}
			}
		}
		foreach ( $data as $item ) {
			if ( is_array( $item ) && isset( $item['fields'] ) && is_array( $item['fields'] ) ) {
				$id = self::import_group( $item );
				if ( $id ) {
					$result['groups'][] = $id;
				}
			}
		}

		return $result;
	}

	protected static function find_by_key( $key, $post_type ) {
		if ( '' === $key ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => '_fcf_key', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	protected static function import_group( $item ) {
		$key = isset( $item['key'] ) ? sanitize_key( $item['key'] ) : '';
		$id  = self::find_by_key( $key, FCF_Field_Groups::POST_TYPE );

		$postarr = array(
			'post_type'   => FCF_Field_Groups::POST_TYPE,
			'post_title'  => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
			'post_status' => ! isset( $item['active'] ) || ! empty( $item['active'] ) ? 'publish' : 'draft',
			'menu_order'  => isset( $item['menu_order'] ) ? (int) $item['menu_order'] : 0,
		);
		if ( $id ) {
			$postarr['ID'] = $id;
		}
		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return 0;
		}

		$label  = '' !== $postarr['post_title'] ? $postarr['post_title'] : '#' . $id;
		$fields = FCF_Fields::sanitize_definitions( self::convert_fields( $item['fields'], $label ) );

		update_post_meta( $id, '_fcf_key', '' !== $key ? $key : 'group_' . strtolower( wp_generate_password( 13, false, false ) ) );
		update_post_meta( $id, '_fcf_fields', wp_slash( $fields ) );
		update_post_meta( $id, '_fcf_location', wp_slash( FCF_Location::sanitize( isset( $item['location'] ) ? $item['location'] : array() ) ) );
		update_post_meta( $id, '_fcf_position', isset( $item['position'] ) && 'side' === $item['position'] ? 'side' : 'normal' );
		update_post_meta( $id, '_fcf_description', isset( $item['description'] ) ? sanitize_text_field( $item['description'] ) : '' );
		delete_post_meta( $id, '_fcf_post_types' );
		delete_post_meta( $id, '_fcf_page_template' );

		return $id;
	}

	protected static function import_page( $item ) {
		$slug = sanitize_title( $item['menu_slug'] );
		$key  = isset( $item['key'] ) ? sanitize_key( $item['key'] ) : '';
		$id   = self::find_by_key( $key, FCF_Options_Pages::POST_TYPE );
		if ( ! $id && '' !== $slug ) {
			$existing = get_page_by_path( $slug, OBJECT, FCF_Options_Pages::POST_TYPE );
			$id       = $existing ? $existing->ID : 0;
		}

		$title   = isset( $item['page_title'] ) && '' !== $item['page_title'] ? $item['page_title'] : ( isset( $item['title'] ) ? $item['title'] : $slug );
		$postarr = array(
			'post_type'   => FCF_Options_Pages::POST_TYPE,
			'post_title'  => sanitize_text_field( $title ),
			'post_name'   => $slug,
			'post_status' => ! isset( $item['active'] ) || ! empty( $item['active'] ) ? 'publish' : 'draft',
		);
		if ( $id ) {
			$postarr['ID'] = $id;
		}
		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return 0;
		}

		// ACF stores the icon as array( type, value ) and "none" for no parent.
		$icon = isset( $item['icon_url'] ) ? $item['icon_url'] : '';
		if ( is_array( $icon ) ) {
			$icon = isset( $icon['value'] ) && is_string( $icon['value'] ) ? $icon['value'] : '';
		}
		$parent = isset( $item['parent_slug'] ) && 'none' !== $item['parent_slug'] ? (string) $item['parent_slug'] : '';

		update_post_meta( $id, '_fcf_key', '' !== $key ? $key : 'ui_options_page_' . strtolower( wp_generate_password( 13, false, false ) ) );
		update_post_meta( $id, '_fcf_menu_title', isset( $item['menu_title'] ) ? sanitize_text_field( $item['menu_title'] ) : '' );
		update_post_meta( $id, '_fcf_parent_slug', sanitize_text_field( $parent ) );
		update_post_meta( $id, '_fcf_capability', isset( $item['capability'] ) ? sanitize_key( $item['capability'] ) : '' );
		update_post_meta( $id, '_fcf_menu_position', isset( $item['position'] ) && is_numeric( $item['position'] ) ? (string) $item['position'] : '' );
		update_post_meta( $id, '_fcf_icon', sanitize_text_field( (string) $icon ) );

		return $id;
	}

	/**
	 * Convert ACF field settings to this plugin's (own exports pass through unchanged).
	 *
	 * @param array  $fields Raw fields.
	 * @param string $where  Group label, for the skipped-fields notice.
	 * @return array
	 */
	public static function convert_fields( $fields, $where = '' ) {
		$types = FCF_Fields::types();
		$out   = array();

		foreach ( is_array( $fields ) ? $fields : array() as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['type'] ) ) {
				continue;
			}
			$type = isset( self::TYPE_MAP[ $f['type'] ] ) ? self::TYPE_MAP[ $f['type'] ] : $f['type'];

			if ( in_array( $f['type'], self::SKIP_TYPES, true ) ) {
				continue;
			}
			if ( ! isset( $types[ $type ] ) ) {
				self::$skipped[] = sprintf( '%s (%s, %s)', isset( $f['label'] ) ? $f['label'] : '?', $f['type'], $where );
				continue;
			}

			// ACF group field: one row of sub fields.
			if ( 'group' === $f['type'] ) {
				$f['min']    = 1;
				$f['max']    = 1;
				$f['layout'] = isset( $f['layout'] ) && 'table' === $f['layout'] ? 'table' : 'block';
			}
			$f['type'] = $type;

			if ( isset( $f['wrapper'] ) && is_array( $f['wrapper'] ) ) {
				$f['wrapper_width'] = isset( $f['wrapper']['width'] ) ? $f['wrapper']['width'] : '';
				$f['wrapper_class'] = isset( $f['wrapper']['class'] ) ? $f['wrapper']['class'] : '';
				$f['wrapper_id']    = isset( $f['wrapper']['id'] ) ? $f['wrapper']['id'] : '';
				unset( $f['wrapper'] );
			}

			if ( isset( $f['choices'] ) && is_array( $f['choices'] ) ) {
				$lines = array();
				foreach ( $f['choices'] as $value => $label ) {
					$lines[] = (string) $value === (string) $label ? (string) $value : $value . ' : ' . $label;
				}
				$f['choices'] = implode( "\n", $lines );
			}

			if ( isset( $f['default_value'] ) && is_array( $f['default_value'] ) ) {
				$f['default_value'] = implode( "\n", array_map( 'strval', $f['default_value'] ) );
			}

			if ( isset( $f['conditional_logic'] ) && ! is_array( $f['conditional_logic'] ) ) {
				$f['conditional_logic'] = array();
			}

			// ACF image / file sizes and limits use the same names; ACF uses "" or 0 for "no limit".
			foreach ( array( 'min', 'max', 'maxlength', 'min_width', 'min_height', 'max_width', 'max_height', 'min_size', 'max_size' ) as $limit ) {
				if ( isset( $f[ $limit ] ) && ( 0 === $f[ $limit ] || '0' === $f[ $limit ] ) && 'min' !== $limit ) {
					$f[ $limit ] = '';
				}
			}

			$f['sub_fields'] = isset( $f['sub_fields'] ) ? self::convert_fields( $f['sub_fields'], $where ) : array();
			$out[]           = $f;
		}

		return $out;
	}

	/* ---------- Screen ---------- */

	public static function render() {
		$groups = get_posts(
			array(
				'post_type'        => FCF_Field_Groups::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$pages  = get_posts(
			array(
				'post_type'        => FCF_Options_Pages::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		?>
		<div class="wrap fcf-tools">
			<h1><?php esc_html_e( 'Tools', 'functional-custom-fields' ); ?></h1>

			<?php foreach ( self::$notices as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible">
					<p><?php echo ! empty( $notice[2] ) ? wp_kses_post( $notice[1] ) : esc_html( $notice[1] ); ?></p>
				</div>
			<?php endforeach; ?>

			<div class="fcf-tools-grid">
				<form class="fcf-tools-card" method="post">
					<?php wp_nonce_field( 'fcf_tools', 'fcf_tools_nonce' ); ?>
					<div class="fcf-tools-card-head">
						<h2><span class="dashicons dashicons-external" aria-hidden="true"></span><?php esc_html_e( 'Export', 'functional-custom-fields' ); ?></h2>
						<span class="dashicons dashicons-editor-help fcf-tools-help" tabindex="0" title="<?php esc_attr_e( 'Download the selected items as a JSON file to import on another site, or generate PHP to register them in your theme.', 'functional-custom-fields' ); ?>"></span>
					</div>
					<div class="fcf-tools-card-body">
						<?php
						self::checklist( __( 'Select Field Groups', 'functional-custom-fields' ), 'fcf_groups', $groups, __( 'No field groups yet.', 'functional-custom-fields' ) );
						self::checklist( __( 'Select Options Pages', 'functional-custom-fields' ), 'fcf_pages', $pages, __( 'No options pages yet.', 'functional-custom-fields' ) );
						?>
						<div class="fcf-tools-actions">
							<button type="submit" class="button button-primary" name="fcf_action" value="export_json"><?php esc_html_e( 'Export As JSON', 'functional-custom-fields' ); ?></button>
							<button type="submit" class="button" name="fcf_action" value="export_php"><?php esc_html_e( 'Generate PHP', 'functional-custom-fields' ); ?></button>
						</div>

						<?php if ( '' !== self::$php ) : ?>
							<div class="fcf-tools-php">
								<p class="description"><?php esc_html_e( 'Add this code to your theme\'s functions.php (or a plugin) to register the selected items in code. Remove the database copies afterwards to avoid showing them twice.', 'functional-custom-fields' ); ?></p>
								<textarea class="code" rows="18" readonly onclick="this.select()"><?php echo esc_textarea( self::$php ); ?></textarea>
								<button type="button" class="button fcf-tools-copy"><?php esc_html_e( 'Copy to clipboard', 'functional-custom-fields' ); ?></button>
							</div>
						<?php endif; ?>
					</div>
				</form>

				<form class="fcf-tools-card" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'fcf_tools', 'fcf_tools_nonce' ); ?>
					<input type="hidden" name="fcf_action" value="import" />
					<div class="fcf-tools-card-head">
						<h2><span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Import', 'functional-custom-fields' ); ?></h2>
						<span class="dashicons dashicons-editor-help fcf-tools-help" tabindex="0" title="<?php esc_attr_e( 'Import a JSON file exported by this plugin or by ACF. Items whose key already exists here are updated instead of duplicated.', 'functional-custom-fields' ); ?>"></span>
					</div>
					<div class="fcf-tools-card-body">
						<h3 class="fcf-tools-label"><?php esc_html_e( 'Select File', 'functional-custom-fields' ); ?></h3>
						<div class="fcf-tools-box">
							<input type="file" name="fcf_import_file" accept=".json,application/json" required />
						</div>
						<p class="description"><?php esc_html_e( 'Works with exports from Functional Custom Fields and from ACF (Custom Fields → Tools → Export). Field values are not part of the file.', 'functional-custom-fields' ); ?></p>
						<div class="fcf-tools-actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Import JSON', 'functional-custom-fields' ); ?></button>
						</div>
					</div>
				</form>
			</div>
		</div>
		<script>
			document.addEventListener( 'change', function ( e ) {
				if ( e.target.classList.contains( 'fcf-tools-toggle' ) ) {
					e.target.closest( '.fcf-tools-box' ).querySelectorAll( 'input[type=checkbox]' ).forEach( function ( box ) {
						box.checked = e.target.checked;
					} );
				}
			} );
			document.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'fcf-tools-copy' ) ) {
					var area = e.target.parentNode.querySelector( 'textarea' );
					area.select();
					( navigator.clipboard ? navigator.clipboard.writeText( area.value ) : Promise.reject() ).catch( function () {
						document.execCommand( 'copy' );
					} );
					e.target.textContent = <?php echo wp_json_encode( __( 'Copied!', 'functional-custom-fields' ) ); ?>;
				}
			} );
		</script>
		<?php
	}

	protected static function checklist( $title, $name, $posts, $empty ) {
		?>
		<h3 class="fcf-tools-label"><?php echo esc_html( $title ); ?></h3>
		<div class="fcf-tools-box">
			<?php if ( ! $posts ) : ?>
				<p class="fcf-tools-empty"><?php echo esc_html( $empty ); ?></p>
			<?php else : ?>
				<ul class="fcf-tools-checks">
					<li><label><input type="checkbox" class="fcf-tools-toggle" /> <?php esc_html_e( 'Toggle All', 'functional-custom-fields' ); ?></label></li>
					<?php foreach ( $posts as $post ) : ?>
						<li>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $post->ID ); ?>" />
								<?php echo esc_html( '' !== $post->post_title ? $post->post_title : __( '(no title)', 'functional-custom-fields' ) ); ?>
								<?php if ( 'draft' === $post->post_status ) : ?>
									<span class="fcf-tools-muted"><?php esc_html_e( '(inactive)', 'functional-custom-fields' ); ?></span>
								<?php endif; ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
