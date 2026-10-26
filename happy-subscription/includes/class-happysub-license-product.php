<?php
/**
 * "License" tab on WooCommerce products: what the product licenses, for how many sites and how long,
 * and the plugin zip delivered as updates.
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product license settings.
 */
class HAPPYSUB_License_Product {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'form_enctype' ) );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'sold_individually' ), 20, 2 );
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
	 * Settings of a product. Variations use their parent's.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function settings( $product_id ) {
		$parent   = wp_get_post_parent_id( $product_id );
		$is_child = 'product_variation' === get_post_type( $product_id ) && $parent;
		$id       = $is_child ? $parent : $product_id;
		$settings = array(
			'id'           => $id,
			'enabled'      => 'yes' === get_post_meta( $id, '_happysub_license_enabled', true ),
			'slug'         => (string) get_post_meta( $id, '_happysub_license_slug', true ),
			'sites'        => '' === get_post_meta( $id, '_happysub_license_sites', true ) ? 1 : (int) get_post_meta( $id, '_happysub_license_sites', true ),
			'days'         => '' === get_post_meta( $id, '_happysub_license_days', true ) ? 365 : (int) get_post_meta( $id, '_happysub_license_days', true ),
			'version'      => (string) get_post_meta( $id, '_happysub_license_version', true ),
			'tested'       => (string) get_post_meta( $id, '_happysub_license_tested', true ),
			'requires'     => (string) get_post_meta( $id, '_happysub_license_requires', true ),
			'requires_php' => (string) get_post_meta( $id, '_happysub_license_requires_php', true ),
			'changelog'    => (string) get_post_meta( $id, '_happysub_license_changelog', true ),
			'file'         => (string) get_post_meta( $id, '_happysub_license_file', true ),
		);
		/**
		 * License settings of a product or variation.
		 *
		 * @param array $settings   Settings.
		 * @param int   $product_id Product or variation ID.
		 */
		return (array) apply_filters( 'happysub_license_settings', $settings, $product_id );
	}

	/**
	 * Clean a plugin slug the way WordPress names plugin folders: "Sarala Login Page Pro" -> "sarala-login-page-pro".
	 *
	 * @param string $slug Slug as typed.
	 * @return string
	 */
	public static function clean_slug( $slug ) {
		return sanitize_title( remove_accents( trim( (string) $slug ) ) );
	}

	/**
	 * Whether two slugs name the same plugin, ignoring case, spaces and dashes
	 * (so "saralaloginpagepro" saved by older versions still matches "sarala-login-page-pro").
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
		$tabs['happysub_license_license'] = array(
			'label'    => __( 'License', 'happy-subscription' ),
			'target'   => 'happysub_license_license_data',
			'class'    => array( 'show_if_simple' ),
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
		<div id="happysub_license_license_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_happysub_license_enabled',
						'label'       => __( 'Sell a license', 'happy-subscription' ),
						'description' => __( 'Create a license key for each one bought.', 'happy-subscription' ),
						'value'       => $s['enabled'] ? 'yes' : 'no',
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_happysub_license_slug',
						'label'       => __( 'Plugin slug', 'happy-subscription' ),
						'description' => __( 'The plugin\'s folder name, e.g. sarala-login-page-pro. Keys only activate in the plugin with this name.', 'happy-subscription' ),
						'desc_tip'    => true,
						'placeholder' => 'sarala-login-page-pro',
						'value'       => $s['slug'],
					)
				);
				if ( $s['enabled'] && '' === $s['slug'] ) {
					echo '<p class="form-field" style="color:#b32d2e"><strong>' . esc_html__( 'Fill in the Plugin slug, otherwise license keys for this product cannot be activated.', 'happy-subscription' ) . '</strong></p>';
				}
				woocommerce_wp_text_input(
					array(
						'id'                => '_happysub_license_sites',
						'label'             => __( 'Sites per license', 'happy-subscription' ),
						'description'       => __( '0 = unlimited sites.', 'happy-subscription' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0 ),
						'value'             => $s['sites'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_happysub_license_days',
						'label'             => __( 'Updates for (days)', 'happy-subscription' ),
						'description'       => __( '0 = lifetime. After this, the plugin keeps working but stops getting updates.', 'happy-subscription' ),
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
						'id'    => '_happysub_license_version',
						'label' => __( 'Latest version', 'happy-subscription' ),
						'value' => $s['version'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'    => '_happysub_license_requires',
						'label' => __( 'Requires WordPress', 'happy-subscription' ),
						'value' => $s['requires'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'    => '_happysub_license_tested',
						'label' => __( 'Tested up to', 'happy-subscription' ),
						'value' => $s['tested'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'    => '_happysub_license_requires_php',
						'label' => __( 'Requires PHP', 'happy-subscription' ),
						'value' => $s['requires_php'],
					)
				);
				woocommerce_wp_textarea_input(
					array(
						'id'    => '_happysub_license_changelog',
						'label' => __( 'Changelog', 'happy-subscription' ),
						'value' => $s['changelog'],
					)
				);
				?>
				<p class="form-field">
					<label for="happysub_license_zip"><?php esc_html_e( 'Plugin zip', 'happy-subscription' ); ?></label>
					<input type="file" name="happysub_license_zip" id="happysub_license_zip" accept=".zip" />
					<span class="description">
						<?php
						if ( '' !== $s['file'] ) {
							/* translators: %s: file name. */
							echo esc_html( sprintf( __( 'Current file: %s. Upload a new zip to replace it, and update "Latest version" to match.', 'happy-subscription' ), $s['file'] ) );
						} else {
							esc_html_e( 'Upload the plugin zip. It is stored privately and only licensed sites can download it.', 'happy-subscription' );
						}
						?>
					</span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Save the tab.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function save( $product_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product form nonce.
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		update_post_meta( $product_id, '_happysub_license_enabled', isset( $_POST['_happysub_license_enabled'] ) ? 'yes' : 'no' );
		$text = array( '_happysub_license_slug', '_happysub_license_version', '_happysub_license_tested', '_happysub_license_requires', '_happysub_license_requires_php' );
		foreach ( $text as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			update_post_meta( $product_id, $key, '_happysub_license_slug' === $key ? self::clean_slug( $value ) : $value );
		}
		update_post_meta( $product_id, '_happysub_license_sites', isset( $_POST['_happysub_license_sites'] ) ? absint( $_POST['_happysub_license_sites'] ) : 1 );
		update_post_meta( $product_id, '_happysub_license_days', isset( $_POST['_happysub_license_days'] ) ? absint( $_POST['_happysub_license_days'] ) : 365 );
		update_post_meta( $product_id, '_happysub_license_changelog', isset( $_POST['_happysub_license_changelog'] ) ? wp_kses_post( wp_unslash( $_POST['_happysub_license_changelog'] ) ) : '' );

		if ( ! empty( $_FILES['happysub_license_zip']['tmp_name'] ) && isset( $_FILES['happysub_license_zip']['error'] ) && UPLOAD_ERR_OK === $_FILES['happysub_license_zip']['error'] ) {
			$name = isset( $_FILES['happysub_license_zip']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['happysub_license_zip']['name'] ) ) : '';
			if ( '.zip' === strtolower( substr( $name, -4 ) ) && is_uploaded_file( $_FILES['happysub_license_zip']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP's temporary path.
				$slug   = (string) get_post_meta( $product_id, '_happysub_license_slug', true );
				$target = ( '' !== $slug ? $slug : 'product-' . $product_id ) . '-' . gmdate( 'YmdHis' ) . '.zip';
				$dir        = HAPPYSUB_Licenses::private_dir();
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
					$_FILES['happysub_license_zip'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked by wp_handle_upload().
					array(
						'test_form'                => false,
						'mimes'                    => array( 'zip' => 'application/zip' ),
						'unique_filename_callback' => static function () use ( $target ) {
							return $target;
						},
					)
				);
				remove_filter( 'upload_dir', $to_private );
				if ( empty( $result['error'] ) && ! empty( $result['file'] ) ) {
					update_post_meta( $product_id, '_happysub_license_file', basename( $result['file'] ) );
				}
			}
		}
		// phpcs:enable
	}
}
