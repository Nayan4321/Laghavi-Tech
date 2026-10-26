<?php
/**
 * "License" tab on WooCommerce products: what the product licenses, for how many sites and how long,
 * and the plugin zip delivered as updates.
 *
 * @package LaghaviLicenseManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product license settings.
 */
class LLM_Product {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'form_enctype' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
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
	 * Settings of a product. Variations use their parent's, except for their own number of sites when set
	 * (e.g. 1 site, 5 sites, unlimited at different prices).
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
			'enabled'      => 'yes' === get_post_meta( $id, '_llm_enabled', true ),
			'slug'         => (string) get_post_meta( $id, '_llm_slug', true ),
			'sites'        => '' === get_post_meta( $id, '_llm_sites', true ) ? 1 : (int) get_post_meta( $id, '_llm_sites', true ),
			'days'         => '' === get_post_meta( $id, '_llm_days', true ) ? 365 : (int) get_post_meta( $id, '_llm_days', true ),
			'version'      => (string) get_post_meta( $id, '_llm_version', true ),
			'tested'       => (string) get_post_meta( $id, '_llm_tested', true ),
			'requires'     => (string) get_post_meta( $id, '_llm_requires', true ),
			'requires_php' => (string) get_post_meta( $id, '_llm_requires_php', true ),
			'changelog'    => (string) get_post_meta( $id, '_llm_changelog', true ),
			'file'         => (string) get_post_meta( $id, '_llm_file', true ),
		);
		if ( $is_child && '' !== (string) get_post_meta( $product_id, '_llm_sites', true ) ) {
			$settings['sites'] = (int) get_post_meta( $product_id, '_llm_sites', true );
		}
		return $settings;
	}

	/**
	 * "Sites per license" on each variation.
	 *
	 * @param int     $loop           Variation index.
	 * @param array   $variation_data Data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function variation_fields( $loop, $variation_data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'                => '_llm_var_sites_' . $loop,
				'name'              => '_llm_var_sites[' . $loop . ']',
				'label'             => __( 'License: sites per key', 'laghavi-license-manager' ),
				'description'       => __( '0 = unlimited. Leave empty to use the License tab\'s number.', 'laghavi-license-manager' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'value'             => (string) get_post_meta( $variation->ID, '_llm_sites', true ),
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
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked the variations nonce.
		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}
		$value = isset( $_POST['_llm_var_sites'][ $i ] ) ? trim( sanitize_text_field( wp_unslash( $_POST['_llm_var_sites'][ $i ] ) ) ) : '';
		// phpcs:enable
		if ( '' === $value ) {
			delete_post_meta( $variation_id, '_llm_sites' );
		} else {
			update_post_meta( $variation_id, '_llm_sites', absint( $value ) );
		}
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
		$tabs['llm_license'] = array(
			'label'    => __( 'License', 'laghavi-license-manager' ),
			'target'   => 'llm_license_data',
			'class'    => array(),
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
		<div id="llm_license_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_llm_enabled',
						'label'       => __( 'Sell a license', 'laghavi-license-manager' ),
						'description' => __( 'Create a license key for each one bought.', 'laghavi-license-manager' ),
						'value'       => $s['enabled'] ? 'yes' : 'no',
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_llm_slug',
						'label'       => __( 'Plugin slug', 'laghavi-license-manager' ),
						'description' => __( 'The plugin\'s folder name, e.g. sarala-login-page-pro. Keys only activate in the plugin with this name.', 'laghavi-license-manager' ),
						'desc_tip'    => true,
						'placeholder' => 'sarala-login-page-pro',
						'value'       => $s['slug'],
					)
				);
				if ( $s['enabled'] && '' === $s['slug'] ) {
					echo '<p class="form-field" style="color:#b32d2e"><strong>' . esc_html__( 'Fill in the Plugin slug, otherwise license keys for this product cannot be activated.', 'laghavi-license-manager' ) . '</strong></p>';
				}
				woocommerce_wp_text_input(
					array(
						'id'                => '_llm_sites',
						'label'             => __( 'Sites per license', 'laghavi-license-manager' ),
						'description'       => __( '0 = unlimited sites.', 'laghavi-license-manager' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0 ),
						'value'             => $s['sites'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_llm_days',
						'label'             => __( 'Updates for (days)', 'laghavi-license-manager' ),
						'description'       => __( '0 = lifetime. After this, the plugin keeps working but stops getting updates.', 'laghavi-license-manager' ),
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
						'id'    => '_llm_version',
						'label' => __( 'Latest version', 'laghavi-license-manager' ),
						'value' => $s['version'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'    => '_llm_requires',
						'label' => __( 'Requires WordPress', 'laghavi-license-manager' ),
						'value' => $s['requires'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'    => '_llm_tested',
						'label' => __( 'Tested up to', 'laghavi-license-manager' ),
						'value' => $s['tested'],
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'    => '_llm_requires_php',
						'label' => __( 'Requires PHP', 'laghavi-license-manager' ),
						'value' => $s['requires_php'],
					)
				);
				woocommerce_wp_textarea_input(
					array(
						'id'    => '_llm_changelog',
						'label' => __( 'Changelog', 'laghavi-license-manager' ),
						'value' => $s['changelog'],
					)
				);
				?>
				<p class="form-field">
					<label for="llm_zip"><?php esc_html_e( 'Plugin zip', 'laghavi-license-manager' ); ?></label>
					<input type="file" name="llm_zip" id="llm_zip" accept=".zip" />
					<span class="description">
						<?php
						if ( '' !== $s['file'] ) {
							/* translators: %s: file name. */
							echo esc_html( sprintf( __( 'Current file: %s. Upload a new zip to replace it, and update "Latest version" to match.', 'laghavi-license-manager' ), $s['file'] ) );
						} else {
							esc_html_e( 'Upload the plugin zip. It is stored privately and only licensed sites can download it.', 'laghavi-license-manager' );
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
		update_post_meta( $product_id, '_llm_enabled', isset( $_POST['_llm_enabled'] ) ? 'yes' : 'no' );
		$text = array( '_llm_slug', '_llm_version', '_llm_tested', '_llm_requires', '_llm_requires_php' );
		foreach ( $text as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			update_post_meta( $product_id, $key, '_llm_slug' === $key ? self::clean_slug( $value ) : $value );
		}
		update_post_meta( $product_id, '_llm_sites', isset( $_POST['_llm_sites'] ) ? absint( $_POST['_llm_sites'] ) : 1 );
		update_post_meta( $product_id, '_llm_days', isset( $_POST['_llm_days'] ) ? absint( $_POST['_llm_days'] ) : 365 );
		update_post_meta( $product_id, '_llm_changelog', isset( $_POST['_llm_changelog'] ) ? wp_kses_post( wp_unslash( $_POST['_llm_changelog'] ) ) : '' );

		if ( ! empty( $_FILES['llm_zip']['tmp_name'] ) && isset( $_FILES['llm_zip']['error'] ) && UPLOAD_ERR_OK === $_FILES['llm_zip']['error'] ) {
			$name = isset( $_FILES['llm_zip']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['llm_zip']['name'] ) ) : '';
			if ( '.zip' === strtolower( substr( $name, -4 ) ) && is_uploaded_file( $_FILES['llm_zip']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP's temporary path.
				$slug   = (string) get_post_meta( $product_id, '_llm_slug', true );
				$target = ( '' !== $slug ? $slug : 'product-' . $product_id ) . '-' . gmdate( 'YmdHis' ) . '.zip';
				if ( move_uploaded_file( $_FILES['llm_zip']['tmp_name'], LLM_Licenses::private_dir() . $target ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					update_post_meta( $product_id, '_llm_file', $target );
				}
			}
		}
		// phpcs:enable
	}
}
