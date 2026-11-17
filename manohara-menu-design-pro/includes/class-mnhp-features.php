<?php
/**
 * Pro settings per device of a design: sticky header modes, menu button styles and label, a mobile
 * logo, drawer width, swipe to close, tap to open submenus, and theme elements hidden on that device.
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Device features.
 */
class MNHP_Features {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'manohara_device_fields', array( __CLASS__, 'fields' ), 10, 4 );
		add_filter( 'manohara_device_clean', array( __CLASS__, 'clean' ), 10, 3 );
		add_filter( 'manohara_nav_classes', array( __CLASS__, 'classes' ), 10, 4 );
		add_filter( 'manohara_device_html', array( __CLASS__, 'html' ), 10, 4 );
		add_filter( 'manohara_translatable_fields', array( 'MNHP_I18n', 'fields' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mnhp_sticky'       => 'none',
			'mnhp_burger'       => 'lines',
			'mnhp_toggle_label' => '',
			'mnhp_mobile_logo'  => '',
			'mnhp_drawer_width' => 0,
			'mnhp_swipe'        => 1,
			'mnhp_tap'          => 1,
			'mnhp_hide'         => '',
		);
	}

	/**
	 * A device's Pro options.
	 *
	 * @param array $set Device setup.
	 * @return array
	 */
	public static function opts( $set ) {
		$o = isset( $set['opts'] ) && is_array( $set['opts'] ) ? $set['opts'] : array();
		return array_merge( self::defaults(), array_intersect_key( $o, self::defaults() ) );
	}

	/**
	 * Media picker on the design screen.
	 *
	 * @param string $hook Screen.
	 */
	public static function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, Manohara_Admin::PAGE ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'mnhp-items', MNHP_URL . 'assets/css/admin-items.css', array(), MNHP_VERSION );
		wp_enqueue_script( 'mnhp-design', MNHP_URL . 'assets/js/admin-design.js', array( 'jquery' ), MNHP_VERSION, true );
	}

	/**
	 * Fields on each device of a design.
	 *
	 * @param string $p      Field name prefix.
	 * @param array  $set    Device setup.
	 * @param string $device Device.
	 * @param array  $design Design.
	 */
	public static function fields( $p, $set, $device, $design ) {
		unset( $device, $design );
		$o = self::opts( $set );
		$n = static function ( $k ) use ( $p ) {
			return $p . '[mnhp][' . $k . ']';
		};
		?>
		<h3><?php esc_html_e( 'Pro: header and menu button', 'manohara-menu-design-pro' ); ?></h3>
		<?php if ( ! MNHP_License::is_valid() ) : ?>
			<p class="description"><?php esc_html_e( 'Activate your Manohara Pro license (Appearance › Manohara Pro license) to use these settings on the site.', 'manohara-menu-design-pro' ); ?></p>
		<?php endif; ?>
		<div class="mnhp-device-fields">
			<label><?php esc_html_e( 'When scrolling', 'manohara-menu-design-pro' ); ?>
				<select name="<?php echo esc_attr( $n( 'sticky' ) ); ?>">
					<option value="none" <?php selected( $o['mnhp_sticky'], 'none' ); ?>><?php esc_html_e( 'Scrolls away with the page', 'manohara-menu-design-pro' ); ?></option>
					<option value="sticky" <?php selected( $o['mnhp_sticky'], 'sticky' ); ?>><?php esc_html_e( 'Stays at the top', 'manohara-menu-design-pro' ); ?></option>
					<option value="shrink" <?php selected( $o['mnhp_sticky'], 'shrink' ); ?>><?php esc_html_e( 'Stays at the top and gets smaller', 'manohara-menu-design-pro' ); ?></option>
					<option value="reveal" <?php selected( $o['mnhp_sticky'], 'reveal' ); ?>><?php esc_html_e( 'Hides when scrolling down, comes back when scrolling up', 'manohara-menu-design-pro' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Menu button', 'manohara-menu-design-pro' ); ?>
				<select name="<?php echo esc_attr( $n( 'burger' ) ); ?>">
					<?php foreach ( self::burgers() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $o['mnhp_burger'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'Text next to the menu button', 'manohara-menu-design-pro' ); ?>
				<input type="text" name="<?php echo esc_attr( $n( 'toggle_label' ) ); ?>" value="<?php echo esc_attr( $o['mnhp_toggle_label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Menu', 'manohara-menu-design-pro' ); ?>" />
			</label>
			<label><?php esc_html_e( 'Logo on this device (header bar)', 'manohara-menu-design-pro' ); ?>
				<span class="mnhp-inline"><input type="url" class="mnhp-url-field" name="<?php echo esc_attr( $n( 'mobile_logo' ) ); ?>" value="<?php echo esc_attr( $o['mnhp_mobile_logo'] ); ?>" placeholder="https://" /><button type="button" class="button mnhp-pick-url"><?php esc_html_e( 'Choose', 'manohara-menu-design-pro' ); ?></button></span>
			</label>
			<label><?php esc_html_e( 'Side panel width (px, 0 = template)', 'manohara-menu-design-pro' ); ?>
				<input type="number" min="0" max="1200" class="small-text" name="<?php echo esc_attr( $n( 'drawer_width' ) ); ?>" value="<?php echo (int) $o['mnhp_drawer_width']; ?>" />
			</label>
			<div>
				<label class="mnh-check"><input type="hidden" name="<?php echo esc_attr( $n( 'sent' ) ); ?>" value="1" /><input type="checkbox" name="<?php echo esc_attr( $n( 'swipe' ) ); ?>" value="1" <?php checked( $o['mnhp_swipe'] ); ?> /> <?php esc_html_e( 'Swipe the panel to close it', 'manohara-menu-design-pro' ); ?></label>
				<label class="mnh-check"><input type="checkbox" name="<?php echo esc_attr( $n( 'tap' ) ); ?>" value="1" <?php checked( $o['mnhp_tap'] ); ?> /> <?php esc_html_e( 'On touch screens, the first tap on a parent item opens its submenu', 'manohara-menu-design-pro' ); ?></label>
			</div>
			<label class="mnhp-wide"><?php esc_html_e( 'Hide these page elements on this device (CSS selectors, comma separated)', 'manohara-menu-design-pro' ); ?>
				<input type="text" class="widefat code" name="<?php echo esc_attr( $n( 'hide' ) ); ?>" value="<?php echo esc_attr( $o['mnhp_hide'] ); ?>" placeholder=".top-bar, .theme-mobile-menu, #desktop-only-banner" />
				<span class="description"><?php esc_html_e( 'For a second theme menu, a desktop-only bar or anything that repeats what this menu shows.', 'manohara-menu-design-pro' ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Menu button styles.
	 *
	 * @return array
	 */
	public static function burgers() {
		return array(
			'lines'   => __( 'Three lines, turns into a cross', 'manohara-menu-design-pro' ),
			'squeeze' => __( 'Lines squeeze into a cross', 'manohara-menu-design-pro' ),
			'spin'    => __( 'Lines spin into a cross', 'manohara-menu-design-pro' ),
			'arrow'   => __( 'Lines turn into an arrow', 'manohara-menu-design-pro' ),
			'short'   => __( 'Lines of different lengths', 'manohara-menu-design-pro' ),
			'two'     => __( 'Two lines', 'manohara-menu-design-pro' ),
			'dots'    => __( 'Dots', 'manohara-menu-design-pro' ),
			'pill'    => __( 'Rounded button with lines', 'manohara-menu-design-pro' ),
		);
	}

	/**
	 * Clean the device's Pro fields.
	 *
	 * @param array  $set    Setup.
	 * @param array  $raw    Posted.
	 * @param string $device Device.
	 * @return array
	 */
	public static function clean( $set, $raw, $device ) {
		unset( $device );
		$in = isset( $raw['mnhp'] ) && is_array( $raw['mnhp'] ) ? $raw['mnhp'] : array();
		if ( empty( $in['sent'] ) ) {
			return $set;
		}
		$set['opts']['mnhp_sticky']       = isset( $in['sticky'] ) && in_array( $in['sticky'], array( 'none', 'sticky', 'shrink', 'reveal' ), true ) ? $in['sticky'] : 'none';
		$set['opts']['mnhp_burger']       = isset( $in['burger'] ) && isset( self::burgers()[ $in['burger'] ] ) ? $in['burger'] : 'lines';
		$set['opts']['mnhp_toggle_label'] = isset( $in['toggle_label'] ) ? substr( sanitize_text_field( $in['toggle_label'] ), 0, 40 ) : '';
		$set['opts']['mnhp_mobile_logo']  = isset( $in['mobile_logo'] ) ? esc_url_raw( trim( (string) $in['mobile_logo'] ) ) : '';
		$set['opts']['mnhp_drawer_width'] = isset( $in['drawer_width'] ) ? min( 1200, absint( $in['drawer_width'] ) ) : 0;
		$set['opts']['mnhp_swipe']        = empty( $in['swipe'] ) ? 0 : 1;
		$set['opts']['mnhp_tap']          = empty( $in['tap'] ) ? 0 : 1;
		$set['opts']['mnhp_hide']         = isset( $in['hide'] ) ? self::clean_selectors( (string) $in['hide'] ) : '';
		return $set;
	}

	/**
	 * Keep simple CSS selectors only.
	 *
	 * @param string $raw Selectors.
	 * @return string
	 */
	public static function clean_selectors( $raw ) {
		$out = array();
		foreach ( explode( ',', wp_strip_all_tags( $raw ) ) as $sel ) {
			$sel = trim( $sel );
			if ( '' !== $sel && strlen( $sel ) < 200 && preg_match( '/^[A-Za-z0-9_\-\.\#\s>:\(\)\*\[\]="\']+$/', $sel ) && false === strpos( $sel, 'mnh' ) && false === strpos( $sel, '{' ) ) {
				$out[] = $sel;
			}
		}
		return implode( ', ', array_slice( array_unique( $out ), 0, 30 ) );
	}

	/**
	 * Classes on the menu.
	 *
	 * @param string[] $classes Classes.
	 * @param array    $design  Design.
	 * @param string   $device  Device.
	 * @param string   $context Context.
	 * @return string[]
	 */
	public static function classes( $classes, $design, $device, $context ) {
		unset( $context );
		if ( ! MNHP_License::is_valid() || empty( $design['devices'][ $device ] ) ) {
			return $classes;
		}
		MNHP_Frontend::$used = true;
		$o         = self::opts( $design['devices'][ $device ] );
		$classes[] = 'mnhp';
		$classes[] = 'mnhp-burger-' . $o['mnhp_burger'];
		if ( 'none' !== $o['mnhp_sticky'] ) {
			$classes[] = 'mnhp-sticky-' . $o['mnhp_sticky'];
		}
		if ( $o['mnhp_swipe'] ) {
			$classes[] = 'mnhp-swipe';
		}
		if ( $o['mnhp_tap'] ) {
			$classes[] = 'mnhp-tap';
		}
		return $classes;
	}

	/**
	 * Menu button text, logo on this device and panel width.
	 *
	 * @param string $html    HTML.
	 * @param array  $design  Design.
	 * @param string $device  Device.
	 * @param string $context Context.
	 * @return string
	 */
	public static function html( $html, $design, $device, $context ) {
		unset( $context );
		if ( ! MNHP_License::is_valid() || empty( $design['devices'][ $device ] ) ) {
			return $html;
		}
		$o = Manohara_I18n::options( self::opts( $design['devices'][ $device ] ), $design, $device );
		if ( '' !== $o['mnhp_toggle_label'] ) {
			$label = '<span class="mnhp-toggle-label" aria-hidden="true">' . esc_html( $o['mnhp_toggle_label'] ) . '</span>';
			$html  = preg_replace( '#(<button type="button" class="mnh-toggle"[^>]*>.*?)(</button>)#s', '$1' . str_replace( '$', '\$', $label ) . '$2', $html, 1 );
		}
		if ( '' !== $o['mnhp_mobile_logo'] ) {
			$img  = '<a class="mnh-logo mnhp-device-logo" href="' . esc_url( Manohara_I18n::home() ) . '"><img src="' . esc_url( $o['mnhp_mobile_logo'] ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" /></a>';
			$html = preg_replace( '#<(a|span) class="mnh-logo[^"]*"[^>]*>.*?</\1>#s', str_replace( '$', '\$', $img ), $html, 1 );
		}
		if ( $o['mnhp_drawer_width'] ) {
			$html = preg_replace( '# style="#', ' style="--mnhp-panel-w:' . (int) $o['mnhp_drawer_width'] . 'px;', $html, 1 );
		}
		return (string) $html;
	}

	/**
	 * CSS hiding page elements per device.
	 *
	 * @return string
	 */
	public static function hide_css() {
		$s       = Manohara_Store::settings();
		$t       = max( 480, (int) $s['tablet_max'] );
		$m       = max( 320, min( $t - 1, (int) $s['mobile_max'] ) );
		$queries = array(
			'desktop' => '(min-width:' . ( $t + 1 ) . 'px)',
			'tablet'  => '(min-width:' . ( $m + 1 ) . 'px) and (max-width:' . $t . 'px)',
			'mobile'  => '(max-width:' . $m . 'px)',
		);
		$css     = '';
		foreach ( Manohara_Store::designs() as $design ) {
			foreach ( $design['devices'] as $device => $set ) {
				$o = self::opts( $set );
				if ( 'design' !== $set['mode'] || '' === $o['mnhp_hide'] || ! isset( $queries[ $device ] ) ) {
					continue;
				}
				$list = array();
				foreach ( explode( ',', $o['mnhp_hide'] ) as $sel ) {
					$list[] = trim( $sel ) . ':not(.mnh *)';
				}
				$css .= '@media ' . $queries[ $device ] . '{' . implode( ',', $list ) . '{display:none!important}}';
			}
		}
		// Per-item hiding uses the same breakpoints.
		$css .= '@media ' . $queries['desktop'] . '{.mnh .mnhp-hide-desktop{display:none!important}}';
		$css .= '@media ' . $queries['tablet'] . '{.mnh .mnhp-hide-tablet{display:none!important}}';
		$css .= '@media ' . $queries['mobile'] . '{.mnh .mnhp-hide-mobile{display:none!important}}';
		return $css;
	}
}
