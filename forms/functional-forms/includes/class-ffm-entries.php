<?php
/**
 * Entries: stored submissions, admin list/view and CSV export.
 */

defined( 'ABSPATH' ) || exit;

class FFM_Entries {

	const POST_TYPE = 'ffm_entry';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'bulk_actions' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'form_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_action( 'admin_post_ffm_export', array( __CLASS__, 'export' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_files' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_css' ) );
	}

	public static function register_post_type() {
		$cap = 'manage_options';

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Entries', 'functional-forms' ),
					'singular_name' => __( 'Entry', 'functional-forms' ),
					'menu_name'     => __( 'Entries', 'functional-forms' ),
					'all_items'     => __( 'Entries', 'functional-forms' ),
					'edit_item'     => __( 'View Entry', 'functional-forms' ),
					'search_items'  => __( 'Search Entries', 'functional-forms' ),
					'not_found'     => __( 'No entries yet.', 'functional-forms' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . FFM_Forms::POST_TYPE,
				'supports'     => false,
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
					'create_posts'       => 'do_not_allow',
				),
			)
		);
	}

	/**
	 * Save a submission.
	 *
	 * @param int    $form_id  Form ID.
	 * @param array  $data     name => label/type/value.
	 * @param string $page_url Page the form was submitted from.
	 * @return int Entry ID (0 on failure).
	 */
	public static function create( $form_id, $data, $page_url ) {
		$summary = '';
		foreach ( $data as $item ) {
			if ( in_array( $item['type'], array( 'text', 'email' ), true ) && '' !== $item['value'] ) {
				$summary = $item['value'];
				break;
			}
		}

		$entry_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_parent' => $form_id,
				'post_title'  => sanitize_text_field( $summary ? $summary : __( 'Entry', 'functional-forms' ) ),
			),
			true
		);
		if ( is_wp_error( $entry_id ) ) {
			return 0;
		}

		$ip = '';
		if ( apply_filters( 'ffm_store_ip', true ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

		update_post_meta( $entry_id, '_ffm_data', wp_slash( $data ) );
		update_post_meta( $entry_id, '_ffm_page', esc_url_raw( $page_url ) );
		update_post_meta( $entry_id, '_ffm_ip', $ip );
		update_post_meta( $entry_id, '_ffm_ua', $ua );

		return (int) $entry_id;
	}

	public static function get_data( $entry_id ) {
		$data = get_post_meta( $entry_id, '_ffm_data', true );
		return is_array( $data ) ? $data : array();
	}

	public static function count( $form_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_status = 'publish'",
				self::POST_TYPE,
				$form_id
			)
		);
	}

	public static function export_url( $form_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=ffm_export&form=' . (int) $form_id ), 'ffm_export_' . (int) $form_id );
	}

	public static function add_meta_boxes( $post ) {
		remove_meta_box( 'submitdiv', self::POST_TYPE, 'side' );
		remove_meta_box( 'slugdiv', self::POST_TYPE, 'normal' );
		add_meta_box( 'ffm-entry-data', __( 'Submission', 'functional-forms' ), array( __CLASS__, 'render_data' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'ffm-entry-info', __( 'Details', 'functional-forms' ), array( __CLASS__, 'render_info' ), self::POST_TYPE, 'side', 'high' );
	}

	public static function render_data( $post ) {
		$data = self::get_data( $post->ID );
		if ( ! $data ) {
			echo '<p>' . esc_html__( 'This entry has no data.', 'functional-forms' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped ffm-entry-table"><tbody>';
		foreach ( $data as $item ) {
			$value = FFM_Fields::value_to_html( $item );
			echo '<tr><th scope="row">' . esc_html( $item['label'] ) . '</th><td>' . ( '' === $value ? '&mdash;' : $value ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- value_to_html escapes.
		}
		echo '</tbody></table>';
	}

	public static function render_info( $post ) {
		$form = get_post( $post->post_parent );
		$page = get_post_meta( $post->ID, '_ffm_page', true );
		$ip   = get_post_meta( $post->ID, '_ffm_ip', true );
		$ua   = get_post_meta( $post->ID, '_ffm_ua', true );
		?>
		<p><strong><?php esc_html_e( 'Entry', 'functional-forms' ); ?>:</strong> #<?php echo (int) $post->ID; ?></p>
		<p><strong><?php esc_html_e( 'Form', 'functional-forms' ); ?>:</strong>
			<?php if ( $form ) : ?>
				<a href="<?php echo esc_url( get_edit_post_link( $form->ID ) ); ?>"><?php echo esc_html( get_the_title( $form ) ); ?></a>
			<?php else : ?>
				&mdash;
			<?php endif; ?>
		</p>
		<p><strong><?php esc_html_e( 'Submitted', 'functional-forms' ); ?>:</strong> <?php echo esc_html( get_date_from_gmt( $post->post_date_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></p>
		<?php if ( $page ) : ?>
			<p><strong><?php esc_html_e( 'Page', 'functional-forms' ); ?>:</strong> <a href="<?php echo esc_url( $page ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $page ); ?></a></p>
		<?php endif; ?>
		<?php if ( $ip ) : ?>
			<p><strong><?php esc_html_e( 'IP address', 'functional-forms' ); ?>:</strong> <?php echo esc_html( $ip ); ?></p>
		<?php endif; ?>
		<?php if ( $ua ) : ?>
			<p><strong><?php esc_html_e( 'Browser', 'functional-forms' ); ?>:</strong> <small><?php echo esc_html( $ua ); ?></small></p>
		<?php endif; ?>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&ffm_form=' . (int) $post->post_parent ) ); ?>"><?php esc_html_e( '← All entries', 'functional-forms' ); ?></a>
			<a class="submitdelete" style="margin-left:8px;color:#b32d2e;" href="<?php echo esc_url( get_delete_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Move to Trash', 'functional-forms' ); ?></a>
		</p>
		<?php
	}

	public static function columns( $columns ) {
		return array(
			'cb'           => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'        => __( 'Entry', 'functional-forms' ),
			'ffm_form'     => __( 'Form', 'functional-forms' ),
			'ffm_preview'  => __( 'Summary', 'functional-forms' ),
			'date'         => __( 'Submitted', 'functional-forms' ),
		);
	}

	public static function column_content( $column, $post_id ) {
		if ( 'ffm_form' === $column ) {
			$form_id = wp_get_post_parent_id( $post_id );
			if ( $form_id ) {
				$url = admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&ffm_form=' . $form_id );
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $form_id ) ) . '</a>';
			}
		} elseif ( 'ffm_preview' === $column ) {
			$parts = array();
			foreach ( array_slice( self::get_data( $post_id ), 0, 4 ) as $item ) {
				$text = FFM_Fields::value_to_text( $item );
				if ( 'file' === $item['type'] ) {
					$text = implode( ', ', wp_list_pluck( (array) $item['value'], 'name' ) );
				}
				if ( '' !== $text ) {
					$parts[] = '<strong>' . esc_html( $item['label'] ) . ':</strong> ' . esc_html( wp_trim_words( $text, 12 ) );
				}
			}
			echo implode( '<br>', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'] );
		if ( isset( $actions['edit'] ) ) {
			$actions['edit'] = '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html__( 'View', 'functional-forms' ) . '</a>';
		}
		return $actions;
	}

	public static function bulk_actions( $actions ) {
		unset( $actions['edit'] );
		return $actions;
	}

	public static function form_filter( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}
		$current = isset( $_GET['ffm_form'] ) ? absint( $_GET['ffm_form'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<select name="ffm_form"><option value="">' . esc_html__( 'All forms', 'functional-forms' ) . '</option>';
		foreach ( FFM_Forms::published_forms() as $form ) {
			printf( '<option value="%d"%s>%s</option>', (int) $form['id'], selected( $current, $form['id'], false ), esc_html( $form['title'] ) );
		}
		echo '</select>';
		if ( $current ) {
			echo '<a class="button" style="margin-right:6px;" href="' . esc_url( self::export_url( $current ) ) . '">' . esc_html__( 'Export CSV', 'functional-forms' ) . '</a>';
		}
	}

	public static function filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$form_id = isset( $_GET['ffm_form'] ) ? absint( $_GET['ffm_form'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $form_id ) {
			$query->set( 'post_parent', $form_id );
		}
	}

	public static function export() {
		$form_id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export entries.', 'functional-forms' ), 403 );
		}
		check_admin_referer( 'ffm_export_' . $form_id );

		$entries = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_parent'    => $form_id,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		// Columns: current fields first, then any fields only found in older entries.
		$columns = array();
		foreach ( FFM_Forms::get_fields( $form_id ) as $f ) {
			if ( FFM_Fields::is_input( $f ) ) {
				$columns[ $f['name'] ] = $f['label'] ? $f['label'] : $f['name'];
			}
		}
		foreach ( $entries as $entry ) {
			foreach ( self::get_data( $entry->ID ) as $name => $item ) {
				if ( ! isset( $columns[ $name ] ) ) {
					$columns[ $name ] = $item['label'];
				}
			}
		}

		$filename = sanitize_file_name( get_the_title( $form_id ) . '-entries-' . gmdate( 'Y-m-d' ) . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- UTF-8 BOM for Excel.
		fputcsv( $out, array_merge( array( __( 'Entry ID', 'functional-forms' ), __( 'Submitted', 'functional-forms' ) ), array_values( $columns ), array( __( 'Page', 'functional-forms' ), __( 'IP address', 'functional-forms' ) ) ) );

		foreach ( $entries as $entry ) {
			$data = self::get_data( $entry->ID );
			$row  = array( $entry->ID, get_date_from_gmt( $entry->post_date_gmt, 'Y-m-d H:i:s' ) );
			foreach ( array_keys( $columns ) as $name ) {
				$row[] = isset( $data[ $name ] ) ? FFM_Fields::value_to_text( $data[ $name ] ) : '';
			}
			$row[] = get_post_meta( $entry->ID, '_ffm_page', true );
			$row[] = get_post_meta( $entry->ID, '_ffm_ip', true );
			fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $row ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Stop spreadsheet apps from running cell values as formulas.
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $value : $value;
	}

	/**
	 * Remove uploaded files when an entry is permanently deleted.
	 */
	public static function delete_files( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		foreach ( self::get_data( $post_id ) as $item ) {
			if ( 'file' === $item['type'] && is_array( $item['value'] ) ) {
				foreach ( $item['value'] as $file ) {
					$path = isset( $file['file'] ) ? $file['file'] : '';
					$base = wp_upload_dir( null, false );
					if ( $path && 0 === strpos( wp_normalize_path( $path ), wp_normalize_path( $base['basedir'] . '/functional-forms/' ) ) ) {
						wp_delete_file( $path );
					}
				}
			}
		}
	}

	public static function admin_css() {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}
		echo '<style>.post-type-ffm_entry .page-title-action{display:none}.ffm-entry-table th{width:28%;font-weight:600}.ffm-entry-table td,.ffm-entry-table th{padding:10px 12px;vertical-align:top}#ffm-entry-info p{word-break:break-word}</style>';
	}
}
