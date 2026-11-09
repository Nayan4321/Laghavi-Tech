<?php
/**
 * Button style: the look of the social login buttons (icon tiles, outline boxes, brand pills or
 * the standard full-width buttons), their shape, and your own brand colours.
 *
 * @package SaralaLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Button style settings.
 */
class SLPP_Button_Style {

	const OPTION = 'slpp_button_style';
	const PAGE   = 'sarala-login-page-button-style';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'saralopa_buttons_classes', array( __CLASS__, 'classes' ) );
		add_filter( 'saralopa_button_label', array( __CLASS__, 'label' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 23 );
		add_action( 'admin_post_slpp_button_style', array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	/**
	 * Styles and script of the settings screen (with the live preview).
	 *
	 * @param string $hook Screen.
	 */
	public static function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'sarala-login-page', SARALOPA_URL . 'assets/css/saralopa.css', array(), SARALOPA_VERSION );
		wp_enqueue_style( 'slpp-button-styles', SLPP_URL . 'assets/css/button-styles.css', array( 'sarala-login-page' ), SLPP_VERSION );
		wp_add_inline_style( 'slpp-button-styles', self::css( self::settings() ) . '.slpp-preview{max-width:380px;padding:24px;background:#fff;border:1px solid #dcdcde;border-radius:8px}' );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'slpp-button-style', SLPP_URL . 'assets/js/button-style.js', array( 'jquery', 'wp-color-picker' ), SLPP_VERSION, true );
	}

	/**
	 * Saved settings with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return wp_parse_args(
			$saved,
			array(
				'style'  => 'default',
				'shape'  => 'theme',
				'colors' => 'brand',
				'bg'     => '#2f55a6',
				'text'   => '#ffffff',
				'border' => '#2f55a6',
				'hover'  => '#24427f',
			)
		);
	}

	/**
	 * Styles.
	 *
	 * @return array
	 */
	public static function styles() {
		return array(
			'default' => __( 'Standard: full-width buttons with the name (or icons only, as chosen in Settings)', 'sarala-login-page-pro' ),
			'tiles'   => __( 'Icon tiles: small square buttons in a row', 'sarala-login-page-pro' ),
			'outline' => __( 'Outline boxes: equal boxes side by side, icon in the middle', 'sarala-login-page-pro' ),
			'pills'   => __( 'Brand pills: coloured buttons with icon and name, side by side', 'sarala-login-page-pro' ),
		);
	}

	/**
	 * Brand colour of each provider's icon on white buttons.
	 *
	 * @return array
	 */
	public static function brand_icon_colors() {
		$colors = array(
			'facebook' => '#1877f2',
			'apple'    => '#000000',
			'linkedin' => '#0a66c2',
			'twitter'  => '#000000',
			'reddit'   => '#ff4500',
			'github'   => '#181717',
			'discord'  => '#5865f2',
			'yahoo'    => '#6001d2',
		);
		foreach ( SLPP_Providers::colors() as $id => $c ) {
			$colors[ $id ] = '#ffffff' === strtolower( $c[0] ) ? $c[1] : $c[0];
		}
		return $colors;
	}

	/**
	 * Brand pills sit side by side, so they show just the provider's name.
	 *
	 * @param string|null $text     Text.
	 * @param string      $provider Provider ID.
	 * @return string|null
	 */
	public static function label( $text, $provider ) {
		return 'pills' === self::settings()['style'] ? SARALOPA_Providers::button_name( $provider ) : $text;
	}

	/**
	 * Classes on the buttons block, and the stylesheet.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public static function classes( $classes ) {
		$s = self::settings();
		wp_enqueue_style( 'slpp-button-styles', SLPP_URL . 'assets/css/button-styles.css', array( 'sarala-login-page' ), SLPP_VERSION );
		wp_add_inline_style( 'slpp-button-styles', self::css( $s ) );

		if ( 'default' !== $s['style'] && isset( self::styles()[ $s['style'] ] ) ) {
			$classes .= ' slpp-style-' . $s['style'];
		}
		if ( 'theme' !== $s['shape'] ) {
			$classes .= ' slpp-shape-' . $s['shape'];
		}
		if ( 'custom' === $s['colors'] ) {
			$classes .= ' slpp-colors-custom';
		}
		return $classes;
	}

	/**
	 * Inline CSS: icon colours on white buttons and custom colours.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public static function css( $s ) {
		$css = '';
		foreach ( self::brand_icon_colors() as $id => $color ) {
			$css .= '.slpp-style-tiles .saralopa-button--' . $id . ',.slpp-style-outline .saralopa-button--' . $id . '{color:' . $color . ' !important}';
		}
		$css .= '.slpp-colors-custom{--slpp-bg:' . $s['bg'] . ';--slpp-text:' . $s['text'] . ';--slpp-border:' . $s['border'] . ';--slpp-hover:' . $s['hover'] . '}';
		return $css;
	}

	/**
	 * Menu item.
	 */
	public static function menu() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		add_submenu_page( 'sarala-login-page', __( 'Button style', 'sarala-login-page-pro' ), __( 'Button style', 'sarala-login-page-pro' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Save.
	 */
	public static function handle_form() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sarala-login-page-pro' ) );
		}
		check_admin_referer( 'slpp_button_style' );
		$posted = isset( $_POST['slpp_button_style'] ) && is_array( $_POST['slpp_button_style'] ) ? wp_unslash( $_POST['slpp_button_style'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cleaned below.
		$clean  = array(
			'style'  => isset( $posted['style'] ) && isset( self::styles()[ $posted['style'] ] ) ? $posted['style'] : 'default',
			'shape'  => isset( $posted['shape'] ) && in_array( $posted['shape'], array( 'theme', 'rounded', 'pill', 'square', 'circle' ), true ) ? $posted['shape'] : 'theme',
			'colors' => isset( $posted['colors'] ) && 'custom' === $posted['colors'] ? 'custom' : 'brand',
		);
		foreach ( array( 'bg', 'text', 'border', 'hover' ) as $key ) {
			$color         = isset( $posted[ $key ] ) ? sanitize_hex_color( (string) $posted[ $key ] ) : '';
			$clean[ $key ] = $color ? $color : self::settings()[ $key ];
		}
		update_option( self::OPTION, $clean, false );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE,
					'saved'      => 1,
					'_slppnonce' => wp_create_nonce( 'slpp_button_style_view' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Settings screen with a live preview.
	 */
	public static function page() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		$nonce = isset( $_GET['_slppnonce'] ) ? sanitize_key( wp_unslash( $_GET['_slppnonce'] ) ) : '';
		$saved = '' !== $nonce && wp_verify_nonce( $nonce, 'slpp_button_style_view' ) && ! empty( $_GET['saved'] );
		$s     = self::settings();

		$sample = SARALOPA_Providers::enabled();
		if ( count( $sample ) < 3 ) {
			$sample = array_unique( array_merge( $sample, array( 'google', 'apple', 'facebook' ) ) );
		}
		$sample = array_slice( $sample, 0, 4 );
		?>
		<div class="wrap saralopa-admin slpp-button-style">
			<h1><?php esc_html_e( 'Button style', 'sarala-login-page-pro' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Choose how the social login buttons look on the login page, WooCommerce and everywhere else they appear.', 'sarala-login-page-pro' ); ?></p>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'sarala-login-page-pro' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slpp-button-style__form">
				<input type="hidden" name="action" value="slpp_button_style" />
				<?php wp_nonce_field( 'slpp_button_style' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Style', 'sarala-login-page-pro' ); ?></th>
						<td>
							<?php foreach ( self::styles() as $key => $label ) : ?>
								<label style="display:block;margin:0 0 6px"><input type="radio" name="slpp_button_style[style]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $s['style'], $key ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slpp-shape"><?php esc_html_e( 'Corners', 'sarala-login-page-pro' ); ?></label></th>
						<td>
							<select id="slpp-shape" name="slpp_button_style[shape]">
								<?php
								foreach ( array(
									'theme'   => __( 'As designed', 'sarala-login-page-pro' ),
									'rounded' => __( 'Rounded', 'sarala-login-page-pro' ),
									'pill'    => __( 'Pill', 'sarala-login-page-pro' ),
									'square'  => __( 'Square', 'sarala-login-page-pro' ),
									'circle'  => __( 'Circle (icon tiles)', 'sarala-login-page-pro' ),
								) as $key => $label ) :
									?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['shape'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Colours', 'sarala-login-page-pro' ); ?></th>
						<td>
							<label style="display:block;margin:0 0 6px"><input type="radio" name="slpp_button_style[colors]" value="brand" <?php checked( $s['colors'], 'brand' ); ?> /> <?php esc_html_e( 'Each provider\'s own colours', 'sarala-login-page-pro' ); ?></label>
							<label style="display:block;margin:0 0 10px"><input type="radio" name="slpp_button_style[colors]" value="custom" <?php checked( $s['colors'], 'custom' ); ?> /> <?php esc_html_e( 'My brand colours for every button', 'sarala-login-page-pro' ); ?></label>
							<div class="slpp-colors">
								<?php
								foreach ( array(
									'bg'     => __( 'Background', 'sarala-login-page-pro' ),
									'text'   => __( 'Text and icon', 'sarala-login-page-pro' ),
									'border' => __( 'Border', 'sarala-login-page-pro' ),
									'hover'  => __( 'Background on hover', 'sarala-login-page-pro' ),
								) as $key => $label ) :
									?>
									<div style="display:flex;align-items:center;gap:12px;margin:0 0 8px"><span style="min-width:160px"><?php echo esc_html( $label ); ?></span><input type="text" class="slpp-color" data-var="<?php echo esc_attr( $key ); ?>" name="slpp_button_style[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $s[ $key ] ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" /></div>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Preview', 'sarala-login-page-pro' ); ?></th>
						<td>
							<div class="slpp-preview">
								<div id="slpp-preview-buttons" class="<?php echo esc_attr( trim( self::preview_classes( $s ) ) ); ?>">
									<p class="saralopa-divider"><span><?php esc_html_e( 'or', 'sarala-login-page-pro' ); ?></span></p>
									<div class="saralopa-buttons__list">
										<?php foreach ( $sample as $provider ) : ?>
											<span class="saralopa-button saralopa-button--<?php echo esc_attr( $provider ); ?>"><span class="saralopa-button__icon" aria-hidden="true"><?php SARALOPA_Buttons::output( SARALOPA_Buttons::icon( $provider ) ); ?></span><span class="saralopa-button__label"><?php echo esc_html( SARALOPA_Buttons::button_label( $provider ) ); ?></span></span>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Classes for the preview block.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private static function preview_classes( $s ) {
		$classes = 'saralopa-buttons saralopa-buttons--full';
		if ( 'default' !== $s['style'] ) {
			$classes .= ' slpp-style-' . $s['style'];
		}
		if ( 'theme' !== $s['shape'] ) {
			$classes .= ' slpp-shape-' . $s['shape'];
		}
		if ( 'custom' === $s['colors'] ) {
			$classes .= ' slpp-colors-custom';
		}
		return $classes;
	}
}
