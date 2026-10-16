<?php
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Settings → S3 Offload page and option access.
 */
final class BS3O_Settings {

	public const OPTION = 'bs3o_settings';
	public const PAGE   = 'bs3o';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_ajax_bs3o_test', [ __CLASS__, 'ajax_test' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( BS3O_FILE ), [ __CLASS__, 'action_links' ] );
	}

	public static function defaults(): array {
		return [
			'access_key'   => '',
			'secret_key'   => '',
			'region'       => 'us-east-1',
			'bucket'       => '',
			'endpoint'     => '',
			'path_style'   => false,
			'prefix'       => 'wp-content/uploads/',
			'cdn_url'      => '',
			'acl_public'   => false,
			'offload_new'  => true,
			'remove_local' => true,
		];
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/** Credentials defined in wp-config.php win over the stored ones. */
	public static function access_key(): string {
		return defined( 'BS3O_ACCESS_KEY_ID' ) ? (string) BS3O_ACCESS_KEY_ID : (string) self::get( 'access_key' );
	}

	public static function secret_key(): string {
		return defined( 'BS3O_SECRET_ACCESS_KEY' ) ? (string) BS3O_SECRET_ACCESS_KEY : (string) self::get( 'secret_key' );
	}

	public static function keys_in_config(): bool {
		return defined( 'BS3O_ACCESS_KEY_ID' ) && defined( 'BS3O_SECRET_ACCESS_KEY' );
	}

	public static function is_configured(): bool {
		return '' !== (string) self::get( 'bucket' ) && '' !== (string) self::get( 'region' );
	}

	public static function menu(): void {
		add_options_page( 'S3 Media Offload', 'S3 Offload', 'manage_options', self::PAGE, [ __CLASS__, 'render' ] );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">Settings</a>' );
		return $links;
	}

	public static function register(): void {
		register_setting( 'bs3o', self::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
			'default'           => self::defaults(),
		] );
	}

	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? wp_unslash( $input ) : [];
		$old   = self::all();

		$prefix = trim( sanitize_text_field( (string) ( $input['prefix'] ?? '' ) ), '/ ' );

		return [
			'access_key'   => sanitize_text_field( (string) ( $input['access_key'] ?? '' ) ),
			// An empty secret field means "keep the saved one" — it is never printed back.
			'secret_key'   => '' !== trim( (string) ( $input['secret_key'] ?? '' ) ) ? trim( (string) $input['secret_key'] ) : (string) $old['secret_key'],
			'region'       => sanitize_text_field( (string) ( $input['region'] ?? '' ) ),
			'bucket'       => sanitize_text_field( (string) ( $input['bucket'] ?? '' ) ),
			'endpoint'     => untrailingslashit( esc_url_raw( (string) ( $input['endpoint'] ?? '' ) ) ),
			'path_style'   => ! empty( $input['path_style'] ),
			'prefix'       => '' === $prefix ? '' : $prefix . '/',
			'cdn_url'      => untrailingslashit( esc_url_raw( (string) ( $input['cdn_url'] ?? '' ) ) ),
			'acl_public'   => ! empty( $input['acl_public'] ),
			'offload_new'  => ! empty( $input['offload_new'] ),
			'remove_local' => ! empty( $input['remove_local'] ),
		];
	}

	public static function assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'bs3o-admin', BS3O_URL . 'assets/css/admin.css', [], BS3O_VERSION );
		wp_enqueue_script( 'bs3o-admin', BS3O_URL . 'assets/js/admin.js', [], BS3O_VERSION, true );
		wp_localize_script( 'bs3o-admin', 'BS3O', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'bs3o' ),
		] );
	}

	public static function ajax_test(): void {
		check_ajax_referer( 'bs3o', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}
		$result = BS3O_Client::test();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( 'Connection OK — test object written, read and deleted.' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) && 'bulk' === $_GET['tab'] ? 'bulk' : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		?>
		<div class="wrap bs3o">
			<h1>S3 Media Offload</h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">Settings</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'bulk', $url ) ); ?>" class="nav-tab <?php echo 'bulk' === $tab ? 'nav-tab-active' : ''; ?>">Offload existing media</a>
			</nav>
			<?php 'bulk' === $tab ? BS3O_Bulk::render() : self::render_settings(); ?>
		</div>
		<?php
	}

	private static function render_settings(): void {
		$s    = self::all();
		$name = self::OPTION;
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'bs3o' ); ?>
			<h2>Bucket</h2>
			<table class="form-table" role="presentation">
				<?php if ( self::keys_in_config() ) : ?>
					<tr><th>Credentials</th><td><p>Using <code>BS3O_ACCESS_KEY_ID</code> / <code>BS3O_SECRET_ACCESS_KEY</code> from <code>wp-config.php</code>.</p></td></tr>
				<?php else : ?>
					<tr>
						<th><label for="bs3o-ak">Access key ID</label></th>
						<td><input id="bs3o-ak" class="regular-text" type="text" autocomplete="off" name="<?php echo esc_attr( $name ); ?>[access_key]" value="<?php echo esc_attr( $s['access_key'] ); ?>">
						<p class="description">Recommended: define <code>BS3O_ACCESS_KEY_ID</code> and <code>BS3O_SECRET_ACCESS_KEY</code> in <code>wp-config.php</code> instead. Leave both empty to use the server's IAM role / AWS environment credentials.</p></td>
					</tr>
					<tr>
						<th><label for="bs3o-sk">Secret access key</label></th>
						<td><input id="bs3o-sk" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[secret_key]" value="" placeholder="<?php echo '' !== $s['secret_key'] ? esc_attr__( 'Saved — leave empty to keep', 'benzy-s3-offload' ) : ''; ?>"></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th><label for="bs3o-bucket">Bucket</label></th>
					<td><input id="bs3o-bucket" class="regular-text" type="text" name="<?php echo esc_attr( $name ); ?>[bucket]" value="<?php echo esc_attr( $s['bucket'] ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="bs3o-region">Region</label></th>
					<td><input id="bs3o-region" class="regular-text" type="text" name="<?php echo esc_attr( $name ); ?>[region]" value="<?php echo esc_attr( $s['region'] ); ?>" placeholder="us-east-1" required></td>
				</tr>
				<tr>
					<th><label for="bs3o-prefix">Path prefix</label></th>
					<td><input id="bs3o-prefix" class="regular-text" type="text" name="<?php echo esc_attr( $name ); ?>[prefix]" value="<?php echo esc_attr( $s['prefix'] ); ?>">
					<p class="description">Objects are stored as <code>{prefix}{year}/{month}/{file}</code>.</p></td>
				</tr>
				<tr>
					<th><label for="bs3o-cdn">CDN / custom domain</label></th>
					<td><input id="bs3o-cdn" class="regular-text" type="url" name="<?php echo esc_attr( $name ); ?>[cdn_url]" value="<?php echo esc_attr( $s['cdn_url'] ); ?>" placeholder="https://cdn.example.com">
					<p class="description">Optional. Must point at the bucket root (e.g. a CloudFront distribution). Empty = serve straight from the bucket URL.</p></td>
				</tr>
				<tr>
					<th>Public access</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[acl_public]" value="1" <?php checked( $s['acl_public'] ); ?>> Upload objects with the <code>public-read</code> ACL</label>
					<p class="description">Leave off when the bucket uses "Bucket owner enforced" object ownership (AWS default) and grants read access through a bucket policy or CloudFront OAC.</p></td>
				</tr>
			</table>

			<h2>S3-compatible storage <span class="description">(optional)</span></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="bs3o-endpoint">Custom endpoint</label></th>
					<td><input id="bs3o-endpoint" class="regular-text" type="url" name="<?php echo esc_attr( $name ); ?>[endpoint]" value="<?php echo esc_attr( $s['endpoint'] ); ?>" placeholder="https://nyc3.digitaloceanspaces.com">
					<p class="description">For DigitalOcean Spaces, Wasabi, Cloudflare R2, MinIO… Leave empty for Amazon S3.</p></td>
				</tr>
				<tr>
					<th>Path-style URLs</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[path_style]" value="1" <?php checked( $s['path_style'] ); ?>> Use <code>endpoint/bucket/key</code> instead of <code>bucket.endpoint/key</code></label></td>
				</tr>
			</table>

			<h2>Behaviour</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th>New uploads</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[offload_new]" value="1" <?php checked( $s['offload_new'] ); ?>> Upload new media to S3 automatically</label></td>
				</tr>
				<tr>
					<th>Local files</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[remove_local]" value="1" <?php checked( $s['remove_local'] ); ?>> Delete local copies once they are safely in S3</label>
					<p class="description">Files are downloaded back on demand when WordPress needs them (image editing, regenerating thumbnails).</p></td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2>Test connection</h2>
		<p>Save first, then check that WordPress can write, read and delete in the bucket.</p>
		<p><button type="button" class="button" id="bs3o-test">Test connection</button> <span id="bs3o-test-result" aria-live="polite"></span></p>
		<?php
	}
}
