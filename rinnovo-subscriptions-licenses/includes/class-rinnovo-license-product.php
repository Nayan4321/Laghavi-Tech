<?php
/**
 * "License" tab on WooCommerce products: what the product licenses, for how many sites and how long,
 * and the plugin zip delivered as updates. On variable products each variation can be its own plan
 * (1 site, 5 sites, unlimited) with its own number of sites.
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
		$settings = array(
			'id'           => $id,
			'enabled'      => 'yes' === get_post_meta( $id, '_rinnovo_license_enabled', true ),
			'slug'         => (string) get_post_meta( $id, '_rinnovo_license_slug', true ),
			'sites'        => '' === get_post_meta( $id, '_rinnovo_license_sites', true ) ? 1 : (int) get_post_meta( $id, '_rinnovo_license_sites', true ),
			'days'         => '' === get_post_meta( $id, '_rinnovo_license_days', true ) ? 365 : (int) get_post_meta( $id, '_rinnovo_license_days', true ),
			'version'      => (string) get_post_meta( $id, '_rinnovo_license_version', true ),
			'tested'       => (string) get_post_meta( $id, '_rinnovo_license_tested', true ),
			'requires'     => (string) get_post_meta( $id, '_rinnovo_license_requires', true ),
			'requires_php' => (string) get_post_meta( $id, '_rinnovo_license_requires_php', true ),
			'changelog'    => (string) get_post_meta( $id, '_rinnovo_license_changelog', true ),
			'file'         => (string) get_post_meta( $id, '_rinnovo_license_file', true ),
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
	 * Clean a plugin slug the way WordPress names plugin folders: "My Plugin" -> "my-plugin".
	 *
	 * @param string $slug Slug as typed.
	 * @return string
	 */
	public static function clean_slug( $slug ) {
		return sanitize_title( remove_accents( trim( (string) $slug ) ) );
	}

	/**
	 * Whether two slugs name the same plugin, ignoring case, spaces and dashes
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
				woocommerce_wp_text_input(
					array(
						'id'          => '_rinnovo_license_slug',
						'label'       => __( 'Plugin slug', 'rinnovo-subscriptions-licenses' ),
						'description' => __( 'The plugin\'s folder name, e.g. my-plugin. Keys only activate in the plugin with this name.', 'rinnovo-subscriptions-licenses' ),
						'desc_tip'    => true,
						'placeholder' => 'my-plugin',
						'value'       => $s['slug'],
					)
				);
				if ( $s['enabled'] && '' === $s['slug'] ) {
					echo '<p class="form-field" style="color:#b32d2e"><strong>' . esc_html__( 'Fill in the Plugin slug, otherwise license keys for this product cannot be activated.', 'rinnovo-subscriptions-licenses' ) . '</strong></p>';
				}
				woocommerce_wp_text_input(
					array(
						'id'                => '_rinnovo_license_sites',
						'label'             => __( 'Sites per license', 'rinnovo-subscriptions-licenses' ),
						'description'       => __( '0 = unlimited sites.', 'rinnovo-subscriptions-licenses' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0 ),
						'value'             => $s['sites'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_rinnovo_license_days',
						'label'             => __( 'Updates for (days)', 'rinnovo-subscriptions-licenses' ),
						'description'       => __( '0 = lifetime. After this, the plugin keeps working but stops getting updates.', 'rinnovo-subscriptions-licenses' ),
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
						'id'    => '_rinnovo_license_version',
						'label' => __( 'Latest version', 'rinnovo-subscriptions-licenses' ),
						'value' => $s['version'],
					)
				);
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
				woocommerce_wp_textarea_input(
					array(
						'id'    => '_rinnovo_license_changelog',
						'label' => __( 'Changelog', 'rinnovo-subscriptions-licenses' ),
						'value' => $s['changelog'],
					)
				);
				?>
				<p class="form-field">
					<label for="rinnovo_license_zip"><?php esc_html_e( 'Plugin zip', 'rinnovo-subscriptions-licenses' ); ?></label>
					<input type="file" name="rinnovo_license_zip" id="rinnovo_license_zip" accept=".zip" />
					<span class="description">
						<?php
						if ( '' !== $s['file'] ) {
							/* translators: %s: file name. */
							echo esc_html( sprintf( __( 'Current file: %s. Upload a new zip to replace it, and update "Latest version" to match.', 'rinnovo-subscriptions-licenses' ), $s['file'] ) );
						} else {
							esc_html_e( 'Upload the plugin zip. It is stored privately and only licensed sites can download it.', 'rinnovo-subscriptions-licenses' );
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
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		update_post_meta( $product_id, '_rinnovo_license_enabled', isset( $_POST['_rinnovo_license_enabled'] ) ? 'yes' : 'no' );
		$text = array( '_rinnovo_license_slug', '_rinnovo_license_version', '_rinnovo_license_tested', '_rinnovo_license_requires', '_rinnovo_license_requires_php' );
		foreach ( $text as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			update_post_meta( $product_id, $key, '_rinnovo_license_slug' === $key ? self::clean_slug( $value ) : $value );
		}
		update_post_meta( $product_id, '_rinnovo_license_sites', isset( $_POST['_rinnovo_license_sites'] ) ? absint( $_POST['_rinnovo_license_sites'] ) : 1 );
		update_post_meta( $product_id, '_rinnovo_license_days', isset( $_POST['_rinnovo_license_days'] ) ? absint( $_POST['_rinnovo_license_days'] ) : 365 );
		update_post_meta( $product_id, '_rinnovo_license_changelog', isset( $_POST['_rinnovo_license_changelog'] ) ? wp_kses_post( wp_unslash( $_POST['_rinnovo_license_changelog'] ) ) : '' );

		if ( ! empty( $_FILES['rinnovo_license_zip']['tmp_name'] ) && isset( $_FILES['rinnovo_license_zip']['error'] ) && UPLOAD_ERR_OK === $_FILES['rinnovo_license_zip']['error'] ) {
			$name = isset( $_FILES['rinnovo_license_zip']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['rinnovo_license_zip']['name'] ) ) : '';
			if ( '.zip' === strtolower( substr( $name, -4 ) ) && is_uploaded_file( $_FILES['rinnovo_license_zip']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP's temporary path.
				$slug   = (string) get_post_meta( $product_id, '_rinnovo_license_slug', true );
				$target = ( '' !== $slug ? $slug : 'product-' . $product_id ) . '-' . gmdate( 'YmdHis' ) . '.zip';
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
						'mimes'                    => array( 'zip' => 'application/zip' ),
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
	 * "Sites per key" on each variation.
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
				'label'             => __( 'License: sites per key', 'rinnovo-subscriptions-licenses' ),
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
	 * Save a variation's number of sites.
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
