<?php
/**
 * Puts designs on the website: in place of the theme's menu at a location, and as an own header
 * bar on the devices that use one. Which design shows is decided by screen width in CSS, so page
 * caches keep working and nothing is detected on the server.
 *
 * @package ManoharaMenuDesign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front end.
 */
class Manohara_Frontend {

	/**
	 * Getting the theme's own menu (no takeover).
	 *
	 * @var bool
	 */
	private static $bypass = false;

	/**
	 * Header bars already printed.
	 *
	 * @var bool
	 */
	private static $bars_done = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'init', array( __CLASS__, 'hooks' ) );
	}

	/**
	 * Front-end hooks, only when there is a design.
	 */
	public static function hooks() {
		if ( is_admin() || ! Manohara_Store::designs() ) {
			return;
		}
		add_filter( 'pre_wp_nav_menu', array( __CLASS__, 'takeover' ), 20, 2 );
		add_action( 'wp_head', array( __CLASS__, 'head_css' ), 99 );
		add_action( 'wp_body_open', array( __CLASS__, 'bars' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'bars_fallback' ), 5 );
	}

	/**
	 * Register the styles and script.
	 */
	public static function register() {
		if ( wp_style_is( 'manohara-base', 'registered' ) ) {
			return;
		}
		wp_register_style( 'manohara-base', MANOHARA_URL . 'assets/css/base.css', array(), manohara_asset_version( 'assets/css/base.css' ) );
		foreach ( array_keys( Manohara_Templates::all() ) as $key ) {
			$file = 'assets/css/templates/' . $key . '.css';
			if ( file_exists( MANOHARA_DIR . $file ) ) {
				wp_register_style( 'manohara-' . $key, MANOHARA_URL . $file, array( 'manohara-base' ), manohara_asset_version( $file ) );
			}
		}
		wp_register_script(
			'manohara',
			MANOHARA_URL . 'assets/js/manohara.js',
			array(),
			manohara_asset_version( 'assets/js/manohara.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Register the assets; load those used by designs on theme locations.
	 */
	public static function assets() {
		self::register();
		foreach ( Manohara_Store::designs() as $design ) {
			if ( '' === $design['location'] ) {
				continue;
			}
			foreach ( $design['devices'] as $set ) {
				if ( 'design' === $set['mode'] ) {
					self::enqueue_template( $set['template'] );
				}
			}
		}
	}

	/**
	 * Load a template's style (and the script).
	 *
	 * @param string $key Template.
	 */
	public static function enqueue_template( $key ) {
		self::register();
		wp_enqueue_style( 'manohara-base' );
		if ( wp_style_is( 'manohara-' . $key, 'registered' ) ) {
			wp_enqueue_style( 'manohara-' . $key );
		}
		wp_enqueue_script( 'manohara' );
	}

	/**
	 * Which devices show what for a design at its location.
	 *
	 * @param array $design Design.
	 * @return array theme, inline, bar => devices.
	 */
	public static function split( $design ) {
		$out = array(
			'theme'  => array(),
			'inline' => array(),
			'bar'    => array(),
		);
		foreach ( $design['devices'] as $device => $set ) {
			if ( 'design' !== $set['mode'] ) {
				$out['theme'][] = $device;
			} elseif ( Manohara_Templates::is_toggle( $set['template'] ) && 'bar' === $set['placement'] ) {
				$out['bar'][] = $device;
			} else {
				$out['inline'][] = $device;
			}
		}
		return $out;
	}

	/**
	 * In place of the theme's menu at a location: the theme's menu on the devices that keep it, the
	 * design on the others.
	 *
	 * @param string|null $output Menu HTML (null to let WordPress build it).
	 * @param stdClass    $args   wp_nav_menu() arguments.
	 * @return string|null
	 */
	public static function takeover( $output, $args ) {
		if ( null !== $output || self::$bypass || empty( $args->theme_location ) ) {
			return $output;
		}
		$design = Manohara_Store::design_for_location( $args->theme_location );
		if ( ! $design ) {
			return $output;
		}
		$split = self::split( $design );
		if ( 3 === count( $split['theme'] ) ) {
			return $output;
		}
		$html = '';
		if ( $split['theme'] ) {
			self::$bypass     = true;
			$theme_args       = clone $args;
			$theme_args->echo = false;
			$original         = wp_nav_menu( (array) $theme_args );
			self::$bypass     = false;
			$classes          = 'mnh-part mnh-theme-part';
			foreach ( $split['theme'] as $device ) {
				$classes .= ' mnh-on-' . $device;
			}
			$html .= '<div class="' . esc_attr( $classes ) . '">' . $original . '</div>';
		}
		foreach ( Manohara_Render::groups( $design, $split['inline'] ) as $devices ) {
			self::enqueue_template( $design['devices'][ $devices[0] ]['template'] );
			$html .= Manohara_Render::device( $design, $devices[0], $devices, 'location' );
		}
		return $html;
	}

	/**
	 * Breakpoints, and the theme's header hidden on devices with their own header bar.
	 */
	public static function head_css() {
		$s         = Manohara_Store::settings();
		$t         = max( 480, (int) $s['tablet_max'] );
		$m         = max( 320, min( $t - 1, (int) $s['mobile_max'] ) );
		$css       = self::device_css();
		$hide      = array();
		$selectors = self::selectors();
		if ( '' !== $selectors ) {
			foreach ( Manohara_Store::designs() as $design ) {
				if ( '' !== $design['location'] ) {
					$hide = array_merge( $hide, self::split( $design )['bar'] );
				}
			}
		}
		$queries = array(
			'desktop' => '(min-width:' . ( $t + 1 ) . 'px)',
			'tablet'  => '(min-width:' . ( $m + 1 ) . 'px) and (max-width:' . $t . 'px)',
			'mobile'  => '(max-width:' . $m . 'px)',
		);
		foreach ( array_unique( $hide ) as $device ) {
			$css .= '@media ' . $queries[ $device ] . '{' . $selectors . '{display:none!important}}';
		}
		echo '<style id="manohara-devices">' . wp_strip_all_tags( $css ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS built from numbers and the saved, cleaned selector list.
	}

	/**
	 * Which part shows on which screen width.
	 *
	 * @return string
	 */
	public static function device_css() {
		$s = Manohara_Store::settings();
		$t = max( 480, (int) $s['tablet_max'] );
		$m = max( 320, min( $t - 1, (int) $s['mobile_max'] ) );
		return '.mnh-theme-part{display:contents}'
			. '@media (min-width:' . ( $t + 1 ) . 'px){.mnh-part:not(.mnh-on-desktop){display:none!important}}'
			. '@media (min-width:' . ( $m + 1 ) . 'px) and (max-width:' . $t . 'px){.mnh-part:not(.mnh-on-tablet){display:none!important}}'
			. '@media (max-width:' . $m . 'px){.mnh-part:not(.mnh-on-mobile){display:none!important}}';
	}

	/**
	 * Selectors of the theme's header (hidden where a design shows its own header bar).
	 *
	 * @return string
	 */
	public static function selectors() {
		$raw  = (string) Manohara_Store::settings()['header_selectors'];
		$list = array();
		foreach ( explode( ',', $raw ) as $sel ) {
			$sel = trim( $sel );
			// Simple selectors only: element, #id, .class, attribute-free descendants.
			if ( '' !== $sel && preg_match( '/^[A-Za-z0-9_\-\.\#\s>:\(\)\*]+$/', $sel ) && false === strpos( $sel, 'mnh' ) ) {
				$list[] = $sel . ':not(.mnh *)';
			}
		}
		return implode( ',', $list );
	}

	/**
	 * Own header bars, at the top of the page.
	 */
	public static function bars() {
		if ( self::$bars_done ) {
			return;
		}
		self::$bars_done = true;
		foreach ( Manohara_Store::designs() as $design ) {
			if ( '' === $design['location'] ) {
				continue;
			}
			$devices = self::split( $design )['bar'];
			foreach ( Manohara_Render::groups( $design, $devices ) as $group ) {
				self::enqueue_template( $design['devices'][ $group[0] ]['template'] );
				echo Manohara_Render::device( $design, $group[0], $group, 'bar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while rendering.
			}
		}
	}

	/**
	 * Themes without wp_body_open: the bars are printed at the end and fixed to the top.
	 */
	public static function bars_fallback() {
		if ( self::$bars_done ) {
			return;
		}
		ob_start();
		self::bars();
		$html = (string) ob_get_clean();
		if ( '' !== $html ) {
			echo '<div class="mnh-fallback">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while rendering.
		}
	}
}
