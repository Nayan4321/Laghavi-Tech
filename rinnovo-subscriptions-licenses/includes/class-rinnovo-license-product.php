<?php
/**
 * "License" tab on WooCommerce products: any product sold with a license key (a WordPress plugin or
 * theme, desktop or mobile software, a SaaS app, an online course…), for how many activations and
 * how long, the file (or link) customers download, and a ready-made connector for the product. On
 * variable products each variation can be its own plan (1, 5, unlimited activations).
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product license settings.
 */
class RINNOVO_License_Product {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'form_enctype' ) );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'sold_individually' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_rinnovo_license_client', array( __CLASS__, 'download_client' ) );
	}

	/**
	 * What a licensed product can be.
	 *
	 * @return array<string, string>
	 */
	public static function types() {
		return array(
			'wp_plugin' => __( 'WordPress plugin', 'rinnovo-subscriptions-licenses' ),
			'wp_theme'  => __( 'WordPress theme', 'rinnovo-subscriptions-licenses' ),
			'desktop'   => __( 'Desktop application (Windows, macOS, Linux)', 'rinnovo-subscriptions-licenses' ),
			'mobile'    => __( 'Mobile application (Android, iOS)', 'rinnovo-subscriptions-licenses' ),
			'extension' => __( 'Browser extension', 'rinnovo-subscriptions-licenses' ),
			'saas'      => __( 'SaaS or web application (license key)', 'rinnovo-subscriptions-licenses' ),
			'developer' => __( 'Developer product (SDK, library, CLI, API)', 'rinnovo-subscriptions-licenses' ),
			'game'      => __( 'Game or game development product', 'rinnovo-subscriptions-licenses' ),
			'asset'     => __( 'Digital asset or creative product (fonts, templates, presets, 3D, audio…)', 'rinnovo-subscriptions-licenses' ),
			'other'     => __( 'Other (course, content, service…)', 'rinnovo-subscriptions-licenses' ),
		);
	}

	/**
	 * File types that can be uploaded for download (never scripts or web pages).
	 *
	 * @return array<string, string> Extension => MIME type.
	 */
	public static function file_types() {
		/**
		 * File types sellers may upload on the License tab.
		 *
		 * @param array $types Extension => MIME type.
		 */
		return (array) apply_filters(
			'rinnovo_license_file_types',
			array(
				'zip'      => 'application/zip',
				'rar'      => 'application/vnd.rar',
				'7z'       => 'application/x-7z-compressed',
				'gz'       => 'application/gzip',
				'tgz'      => 'application/gzip',
				'tar'      => 'application/x-tar',
				'exe'      => 'application/vnd.microsoft.portable-executable',
				'msi'      => 'application/x-msi',
				'dmg'      => 'application/x-apple-diskimage',
				'pkg'      => 'application/octet-stream',
				'apk'      => 'application/vnd.android.package-archive',
				'aab'      => 'application/octet-stream',
				'ipa'      => 'application/octet-stream',
				'appimage' => 'application/octet-stream',
				'deb'      => 'application/vnd.debian.binary-package',
				'rpm'      => 'application/x-rpm',
				'jar'      => 'application/java-archive',
				'pdf'      => 'application/pdf',
				'epub'     => 'application/epub+zip',
				'mp3'      => 'audio/mpeg',
				'mp4'      => 'video/mp4',
				'psd'      => 'image/vnd.adobe.photoshop',
				'ai'       => 'application/postscript',
				'fig'      => 'application/octet-stream',
				'sketch'   => 'application/octet-stream',
				'ttf'      => 'font/ttf',
				'otf'      => 'font/otf',
				'woff2'    => 'font/woff2',
			)
		);
	}

	/**
	 * Whether a product type is a WordPress plugin or theme.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function is_wordpress( $type ) {
		return in_array( $type, array( 'wp_plugin', 'wp_theme' ), true );
	}

	/**
	 * Script that shows the WordPress-only fields for plugins and themes.
	 *
	 * @param string $hook Admin page.
	 */
	public static function assets( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && 'product' === get_post_type() ) {
			wp_enqueue_script( 'rinnovo-license-product', RINNOVO_URL . 'assets/js/license-product.js', array( 'jquery' ), rinnovo_asset_version( 'assets/js/license-product.js' ), true );
		}
	}

	/**
	 * One license per purchase: more sites come from choosing a bigger plan, not a quantity,
	 * so the product page shows no quantity box.
	 *
	 * @param bool       $single  Sold individually.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function sold_individually( $single, $product ) {
		if ( ! $single && $product instanceof WC_Product && self::settings( $product->get_id() )['enabled'] ) {
			return true;
		}
		return $single;
	}

	/**
	 * License settings of a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function settings( $product_id ) {
		$id = (int) $product_id;
		// A variation sells its product's license, with its own number of sites when set.
		$parent = 'product_variation' === get_post_type( $id ) ? wp_get_post_parent_id( $id ) : 0;
		if ( $parent ) {
			$settings = self::settings( $parent );
			if ( '' !== (string) get_post_meta( $id, '_rinnovo_license_sites', true ) ) {
				$settings['sites'] = (int) get_post_meta( $id, '_rinnovo_license_sites', true );
			}
			/** This filter is documented below. */
			return (array) apply_filters( 'rinnovo_license_settings', $settings, $product_id );
		}
		$type     = (string) get_post_meta( $id, '_rinnovo_license_type', true );
		$type     = 'software' === $type ? 'desktop' : $type;
		$settings = array(
			'id'           => $id,
			'enabled'      => 'yes' === get_post_meta( $id, '_rinnovo_license_enabled', true ),
			// Products saved before product types existed were WordPress plugins.
			'type'         => isset( self::types()[ $type ] ) ? $type : 'wp_plugin',
			'slug'         => (string) get_post_meta( $id, '_rinnovo_license_slug', true ),
			'sites'        => '' === get_post_meta( $id, '_rinnovo_license_sites', true ) ? 1 : (int) get_post_meta( $id, '_rinnovo_license_sites', true ),
			'days'         => '' === get_post_meta( $id, '_rinnovo_license_days', true ) ? 365 : (int) get_post_meta( $id, '_rinnovo_license_days', true ),
			'version'      => (string) get_post_meta( $id, '_rinnovo_license_version', true ),
			'tested'       => (string) get_post_meta( $id, '_rinnovo_license_tested', true ),
			'requires'     => (string) get_post_meta( $id, '_rinnovo_license_requires', true ),
			'requires_php' => (string) get_post_meta( $id, '_rinnovo_license_requires_php', true ),
			'changelog'    => (string) get_post_meta( $id, '_rinnovo_license_changelog', true ),
			'file'         => (string) get_post_meta( $id, '_rinnovo_license_file', true ),
			'url'          => (string) get_post_meta( $id, '_rinnovo_license_url', true ),
		);
		/**
		 * License settings of a product.
		 *
		 * @param array $settings   Settings.
		 * @param int   $product_id Product ID.
		 */
		return (array) apply_filters( 'rinnovo_license_settings', $settings, $product_id );
	}

	/**
	 * Clean a product ID the way WordPress names plugin folders: "My App" -> "my-app".
	 *
	 * @param string $slug Slug as typed.
	 * @return string
	 */
	public static function clean_slug( $slug ) {
		return sanitize_title( remove_accents( trim( (string) $slug ) ) );
	}

	/**
	 * Whether two product IDs name the same product, ignoring case, spaces and dashes
	 * (so "myplugin" saved by older versions still matches "my-plugin").
	 *
	 * @param string $a Slug.
	 * @param string $b Slug.
	 * @return bool
	 */
	public static function slugs_match( $a, $b ) {
		$a = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $a ) );
		$b = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $b ) );
		return '' !== $a && $a === $b;
	}

	/**
	 * Allow file uploads on the product form.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function form_enctype( $post ) {
		if ( $post && 'product' === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	/**
	 * Add the "License" tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['rinnovo_license_license'] = array(
			'label'    => __( 'License', 'rinnovo-subscriptions-licenses' ),
			'target'   => 'rinnovo_license_license_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 75,
		);
		return $tabs;
	}

	/**
	 * The tab's fields.
	 */
	public static function panel() {
		global $post;
		$s = self::settings( $post->ID );
		?>
		<div id="rinnovo_license_license_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_rinnovo_license_enabled',
						'label'       => __( 'Sell a license', 'rinnovo-subscriptions-licenses' ),
						'description' => __( 'Create a license key for each one bought.', 'rinnovo-subscriptions-licenses' ),
						'value'       => $s['enabled'] ? 'yes' : 'no',
					)
				);
				woocommerce_wp_select(
					array(
						'id'      => '_rinnovo_license_type',
						'label'   => __( 'What you sell', 'rinnovo-subscriptions-licenses' ),
						'options' => self::types(),
						'value'   => $s['type'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_rinnovo_license_slug',
						'label'       => __( 'Product ID', 'rinnovo-subscriptions-licenses' ),
						'description' => __( 'A short ID your product sends with the key, so a key only works for this product. For a WordPress plugin or theme, use its folder name.', 'rinnovo-subscriptions-licenses' ),
						'desc_tip'    => true,
						'placeholder' => 'my-product',
						'value'       => $s['slug'],
					)
				);
				if ( $s['enabled'] && '' === $s['slug'] ) {
					echo '<p class="form-field" style="color:#b32d2e"><strong>' . esc_html__( 'Fill in the Product ID, otherwise license keys for this product cannot be activated.', 'rinnovo-subscriptions-licenses' ) . '</strong></p>';
				}
				woocommerce_wp_text_input(
					array(
						'id'                => '_rinnovo_license_sites',
						'label'             => __( 'Activations per license', 'rinnovo-subscriptions-licenses' ),
						'description'       => __( 'How many websites, computers or devices can use one key. 0 = unlimited.', 'rinnovo-subscriptions-licenses' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0 ),
						'value'             => $s['sites'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_rinnovo_license_days',
						'label'             => __( 'License valid for (days)', 'rinnovo-subscriptions-licenses' ),
						'description'       => __( '0 = lifetime. With a subscription, the license follows the subscription instead. After this the key shows as expired: no new activations, downloads or updates. Your product decides what else stops.', 'rinnovo-subscriptions-licenses' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0 ),
						'value'             => $s['days'],
					)
				);
				?>
			</div>
			<div class="options_group">
				<?php
				woocommerce_wp_text_input(
					array(
						'id'          => '_rinnovo_license_version',
						'label'       => __( 'Latest version', 'rinnovo-subscriptions-licenses' ),
						'description' => __( 'Your product can ask for it to show an "update available" message.', 'rinnovo-subscriptions-licenses' ),
						'desc_tip'    => true,
						'value'       => $s['version'],
					)
				);
				echo '<div class="rinnovo-license-wp-only">';
				woocommerce_wp_text_input(
					array(
						'id'    => '_rinnovo_license_requires',
						'label' => __( 'Requires WordPress', 'rinnovo-subscriptions-licenses' ),
						'value' => $s['requires'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'    => '_rinnovo_license_tested',
						'label' => __( 'Tested up to', 'rinnovo-subscriptions-licenses' ),
						'value' => $s['tested'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'    => '_rinnovo_license_requires_php',
						'label' => __( 'Requires PHP', 'rinnovo-subscriptions-licenses' ),
						'value' => $s['requires_php'],
					)
				);
				echo '</div>';
				woocommerce_wp_textarea_input(
					array(
						'id'    => '_rinnovo_license_changelog',
						'label' => __( 'Changelog', 'rinnovo-subscriptions-licenses' ),
						'value' => $s['changelog'],
					)
				);
				?>
				<p class="form-field">
					<label for="rinnovo_license_zip"><?php esc_html_e( 'File to download', 'rinnovo-subscriptions-licenses' ); ?></label>
					<input type="file" name="rinnovo_license_zip" id="rinnovo_license_zip" accept="<?php echo esc_attr( '.' . implode( ',.', array_keys( self::file_types() ) ) ); ?>" />
					<span class="description">
						<?php
						if ( '' !== $s['file'] ) {
							/* translators: %s: file name. */
							echo esc_html( sprintf( __( 'Current file: %s. Upload a new file to replace it, and update "Latest version" to match.', 'rinnovo-subscriptions-licenses' ), $s['file'] ) );
						} else {
							/* translators: %s: file types. */
							echo esc_html( sprintf( __( 'Optional. Stored privately: only customers with an active key can download it. Allowed: %s.', 'rinnovo-subscriptions-licenses' ), implode( ', ', array_keys( self::file_types() ) ) ) );
						}
						?>
					</span>
				</p>
				<?php
				woocommerce_wp_text_input(
					array(
						'id'          => '_rinnovo_license_url',
						'label'       => __( 'Or a download link', 'rinnovo-subscriptions-licenses' ),
						'description' => __( 'For files kept elsewhere (App Store, Google Play, cloud storage, your app\'s sign-in page). Used when no file is uploaded; only customers with an active key see it.', 'rinnovo-subscriptions-licenses' ),
						'desc_tip'    => true,
						'type'        => 'url',
						'placeholder' => 'https://',
						'value'       => $s['url'],
					)
				);
				?>
			</div>
			<?php if ( 'auto-draft' !== $post->post_status ) : ?>
				<div class="options_group rinnovo-license-connect">
					<?php self::connect_help( $post->ID, $s ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Store's license API address.
	 *
	 * @return string
	 */
	public static function api_url() {
		return trailingslashit( rest_url( RINNOVO_License_Api::NS ) );
	}

	/**
	 * "Connect your product": how the product checks its key.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $s          Settings.
	 */
	private static function connect_help( $product_id, $s ) {
		$id  = '' !== $s['slug'] ? $s['slug'] : 'my-product';
		$api = self::api_url();
		?>
		<p class="form-field"><strong><?php esc_html_e( 'Connect your product to its license', 'rinnovo-subscriptions-licenses' ); ?></strong></p>
		<div class="form-field" style="padding:0 12px 12px 162px">
			<p><?php esc_html_e( 'Your product asks this store whether a key is valid. It sends the key, the Product ID and something that names where it is used (a website address, or a device or computer ID). The store answers active, expired, revoked, invalid or inactive, and counts each website or device as one activation.', 'rinnovo-subscriptions-licenses' ); ?></p>
			<p class="rinnovo-license-wp-only">
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rinnovo_license_client&product=' . $product_id ), 'rinnovo_license_client_' . $product_id ) ); ?>"><?php esc_html_e( 'Download the license file for your plugin or theme', 'rinnovo-subscriptions-licenses' ); ?></a><br />
				<span class="description"><?php esc_html_e( 'One PHP file, made for this product. Put it in your plugin or theme and add the two lines at its top: customers get a license screen to enter their key, and updates arrive in Dashboard > Updates while the key is active.', 'rinnovo-subscriptions-licenses' ); ?></span>
			</p>
			<p>
				<?php esc_html_e( 'Any other product (desktop or mobile app, browser extension, SaaS, game, developer tool) calls these addresses, from any programming language:', 'rinnovo-subscriptions-licenses' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . RINNOVO_License_Guide::PAGE ) ); ?>" target="_blank"><?php esc_html_e( 'Read the license guide with code examples', 'rinnovo-subscriptions-licenses' ); ?></a>
			</p>
			<pre style="white-space:pre-wrap;background:#f6f7f7;padding:10px;border-radius:4px;font-size:12px">POST <?php echo esc_html( $api ); ?>license/activate
POST <?php echo esc_html( $api ); ?>license/check
POST <?php echo esc_html( $api ); ?>license/deactivate
GET  <?php echo esc_html( $api ); ?>license/update

license_key=XXXXX-XXXXX-XXXXX-XXXXX
slug=<?php echo esc_html( $id ); ?>

instance=<?php esc_html_e( 'device or computer ID', 'rinnovo-subscriptions-licenses' ); ?>   (<?php esc_html_e( 'or', 'rinnovo-subscriptions-licenses' ); ?> site_url=https://example.com)

<?php esc_html_e( 'Answer (JSON):', 'rinnovo-subscriptions-licenses' ); ?> {"success":true,"status":"active","expires":"2027-01-31 00:00:00"}
<?php esc_html_e( 'Example:', 'rinnovo-subscriptions-licenses' ); ?> curl -X POST <?php echo esc_html( $api ); ?>license/check -d license_key=XXXXX-XXXXX-XXXXX-XXXXX -d slug=<?php echo esc_html( $id ); ?> -d instance=LAPTOP-01</pre>
			<p class="description"><?php esc_html_e( 'Call activate once when the customer enters the key, check once a day, and deactivate when they move to another device. license/update returns the latest version, the changelog and a download link while the key is active.', 'rinnovo-subscriptions-licenses' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The ready-made license file for a WordPress plugin or theme, filled in for one product.
	 */
	public static function download_client() {
		$product_id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
		check_admin_referer( 'rinnovo_license_client_' . $product_id );
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'rinnovo-subscriptions-licenses' ) );
		}
		$s     = self::settings( $product_id );
		$slug  = '' !== $s['slug'] ? $s['slug'] : self::clean_slug( get_the_title( $product_id ) );
		$kind  = 'wp_theme' === $s['type'] ? 'theme' : 'plugin';
		$class = str_replace( ' ', '_', ucwords( str_replace( '-', ' ', preg_replace( '/[^a-z0-9-]/', '', $slug ) ) ) ) . '_License';
		if ( ! preg_match( '/^[A-Za-z]/', $class ) ) {
			$class = 'Product_' . $class;
		}
		$name = wp_strip_all_tags( get_the_title( $product_id ) );
		$code = (string) file_get_contents( RINNOVO_DIR . 'templates/license-client/wordpress.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local template file.
		$code = strtr(
			$code,
			array(
				'{{NAME}}'        => str_replace( array( "'", '\\' ), '', $name ),
				'{{STORE_NAME}}'  => str_replace( array( "'", '\\' ), '', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				'{{KIND}}'        => $kind,
				'{{MAIN_HINT}}'   => 'theme' === $kind ? 'functions.php' : 'the file with the "Plugin Name:" header',
				'{{SCREEN_HINT}}' => 'theme' === $kind ? 'Appearance > ' . $name . ' license; the theme folder must be named ' . $slug : 'Settings > ' . $name . ' license',
				'{{FILE}}'        => $slug . '-license.php',
				'{{CLASS}}'       => $class,
				'{{API}}'         => self::api_url(),
				'{{PRODUCT_ID}}'  => $slug,
				'{{OPTION}}'      => str_replace( '-', '_', $slug ) . '_license',
			)
		);
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '-license.php"' );
		echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A PHP file for the seller to download, not HTML.
		exit;
	}

	/**
	 * Save the tab.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function save( $product_id ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		update_post_meta( $product_id, '_rinnovo_license_enabled', isset( $_POST['_rinnovo_license_enabled'] ) ? 'yes' : 'no' );
		$type = isset( $_POST['_rinnovo_license_type'] ) ? sanitize_key( wp_unslash( $_POST['_rinnovo_license_type'] ) ) : 'wp_plugin';
		update_post_meta( $product_id, '_rinnovo_license_type', isset( self::types()[ $type ] ) ? $type : 'wp_plugin' );
		$text = array( '_rinnovo_license_slug', '_rinnovo_license_version', '_rinnovo_license_tested', '_rinnovo_license_requires', '_rinnovo_license_requires_php' );
		foreach ( $text as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			update_post_meta( $product_id, $key, '_rinnovo_license_slug' === $key ? self::clean_slug( $value ) : $value );
		}
		update_post_meta( $product_id, '_rinnovo_license_url', isset( $_POST['_rinnovo_license_url'] ) ? esc_url_raw( wp_unslash( $_POST['_rinnovo_license_url'] ), array( 'https', 'http' ) ) : '' );
		update_post_meta( $product_id, '_rinnovo_license_sites', isset( $_POST['_rinnovo_license_sites'] ) ? absint( $_POST['_rinnovo_license_sites'] ) : 1 );
		update_post_meta( $product_id, '_rinnovo_license_days', isset( $_POST['_rinnovo_license_days'] ) ? absint( $_POST['_rinnovo_license_days'] ) : 365 );
		update_post_meta( $product_id, '_rinnovo_license_changelog', isset( $_POST['_rinnovo_license_changelog'] ) ? wp_kses_post( wp_unslash( $_POST['_rinnovo_license_changelog'] ) ) : '' );

		if ( ! empty( $_FILES['rinnovo_license_zip']['tmp_name'] ) && isset( $_FILES['rinnovo_license_zip']['error'] ) && UPLOAD_ERR_OK === $_FILES['rinnovo_license_zip']['error'] ) {
			$name  = isset( $_FILES['rinnovo_license_zip']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['rinnovo_license_zip']['name'] ) ) : '';
			$ext   = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$types = self::file_types();
			if ( isset( $types[ $ext ] ) && is_uploaded_file( $_FILES['rinnovo_license_zip']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP's temporary path.
				$slug       = (string) get_post_meta( $product_id, '_rinnovo_license_slug', true );
				$target     = ( '' !== $slug ? $slug : 'product-' . $product_id ) . '-' . gmdate( 'YmdHis' ) . '.' . $ext;
				$dir        = RINNOVO_Licenses::private_dir();
				$to_private = static function ( $uploads ) use ( $dir ) {
					$uploads['path']   = untrailingslashit( $dir );
					$uploads['subdir'] = '';
					$uploads['url']    = '';
					return $uploads;
				};
				if ( ! function_exists( 'wp_handle_upload' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				add_filter( 'upload_dir', $to_private );
				$result = wp_handle_upload(
					$_FILES['rinnovo_license_zip'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked by wp_handle_upload().
					array(
						'test_form'                => false,
						// Only the extensions above are accepted; installers and archives have no reliable MIME type to test.
						'test_type'                => false,
						'mimes'                    => array( $ext => $types[ $ext ] ),
						'unique_filename_callback' => static function () use ( $target ) {
							return $target;
						},
					)
				);
				remove_filter( 'upload_dir', $to_private );
				if ( empty( $result['error'] ) && ! empty( $result['file'] ) ) {
					update_post_meta( $product_id, '_rinnovo_license_file', basename( $result['file'] ) );
				}
			}
		}
	}

	/**
	 * "Activations per key" on each variation.
	 *
	 * @param int     $loop           Variation index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function variation_fields( $loop, $variation_data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'                => '_rinnovo_license_var_sites_' . $loop,
				'name'              => '_rinnovo_license_var_sites[' . $loop . ']',
				'label'             => __( 'License: activations per key', 'rinnovo-subscriptions-licenses' ),
				'description'       => __( '0 = unlimited. Leave empty to use the License tab\'s number.', 'rinnovo-subscriptions-licenses' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'value'             => (string) get_post_meta( $variation->ID, '_rinnovo_license_sites', true ),
				'wrapper_class'     => 'form-row form-row-full',
			)
		);
	}

	/**
	 * Save a variation's number of activations.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Variation index.
	 */
	public static function save_variation( $variation_id, $i ) {
		$ajax = isset( $_POST['security'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' );
		$form = isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		if ( ( ! $ajax && ! $form ) || ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}
		$value = isset( $_POST['_rinnovo_license_var_sites'][ $i ] ) ? trim( sanitize_text_field( wp_unslash( $_POST['_rinnovo_license_var_sites'][ $i ] ) ) ) : '';
		if ( '' === $value ) {
			delete_post_meta( $variation_id, '_rinnovo_license_sites' );
		} else {
			update_post_meta( $variation_id, '_rinnovo_license_sites', absint( $value ) );
		}
	}
}
