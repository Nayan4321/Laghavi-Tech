<?php
/**
 * A currency per language (off by default). With Rinnovo Subscriptions & Licenses Pro's currency
 * switcher active, Vacanam picks its currency when the language changes and Rinnovo does the
 * converting; without it, Vacanam converts prices itself at the rates set here.
 *
 * @package VacanamLanguageSwitcherPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Currency.
 */
class VACANAMPRO_Currency {

	const COOKIE = 'vacanam_currency_lang';

	/**
	 * Guard against re-entering the price filters.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hook everything up (licensed, WooCommerce active).
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_filter( 'vacanampro_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 32 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save_settings' ) );
		$mode = self::mode();
		if ( 'rinnovo' === $mode ) {
			// Before Rinnovo reads its cookie (init, priority 5).
			add_action( 'init', array( __CLASS__, 'rinnovo_bridge' ), 4 );
		} elseif ( 'own' === $mode ) {
			self::own_filters();
		}
	}

	/**
	 * Defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$defaults['currency_mode'] = '';
		$defaults['currency_map']  = array();
		return $defaults;
	}

	/**
	 * Is Rinnovo's currency switcher here?
	 *
	 * @return bool
	 */
	public static function has_rinnovo() {
		return class_exists( 'RINNOVO_Currency' );
	}

	/**
	 * Mode in use: '' (off), 'rinnovo' or 'own'.
	 *
	 * @return string
	 */
	public static function mode() {
		$mode = (string) VACANAMPRO_Settings::get( 'currency_mode' );
		if ( 'rinnovo' === $mode && ! self::has_rinnovo() ) {
			return '';
		}
		return in_array( $mode, array( 'rinnovo', 'own' ), true ) ? $mode : '';
	}

	/**
	 * Currency settings of a language.
	 *
	 * @param string $lang Language.
	 * @return array { code, rate, decimals, rounding } (code '' when none).
	 */
	public static function for_language( $lang ) {
		$map = (array) VACANAMPRO_Settings::get( 'currency_map' );
		$row = isset( $map[ $lang ] ) && is_array( $map[ $lang ] ) ? $map[ $lang ] : array();
		return array(
			'code'     => isset( $row['code'] ) ? strtoupper( (string) $row['code'] ) : '',
			'rate'     => isset( $row['rate'] ) ? (float) $row['rate'] : 0.0,
			'decimals' => isset( $row['decimals'] ) ? (int) $row['decimals'] : 2,
			'rounding' => isset( $row['rounding'] ) ? (string) $row['rounding'] : 'none',
		);
	}

	/**
	 * Store currency.
	 *
	 * @return string
	 */
	public static function base() {
		return (string) get_option( 'woocommerce_currency', 'USD' );
	}

	/**
	 * Currency shown to this visitor ('' when the store currency).
	 *
	 * @return array|null Settings of the language, or null.
	 */
	private static function active() {
		if ( ! Vacanam_Router::is_front() || is_admin() && ! wp_doing_ajax() ) {
			return null;
		}
		$row = self::for_language( Vacanam_Router::current() );
		return '' !== $row['code'] && $row['code'] !== self::base() && $row['rate'] > 0 ? $row : null;
	}

	/*
	 * Rinnovo bridge.
	 */

	/**
	 * When the visitor arrives in a language they were not in before, choose that language's
	 * currency in Rinnovo's switcher. Picking another currency afterwards still works.
	 */
	public static function rinnovo_bridge() {
		if ( ! Vacanam_Router::is_front() || Vacanam_Frontend::is_bot() ) {
			return;
		}
		$lang = Vacanam_Router::current();
		$seen = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( $seen === $lang ) {
			return;
		}
		self::cookie( self::COOKIE, $lang );
		$codes = RINNOVO_Currency::codes();
		$code  = self::for_language( $lang )['code'];
		// "Store currency" (or one Rinnovo does not offer): the store's own, listed first by Rinnovo.
		if ( '' === $code || ! in_array( $code, $codes, true ) ) {
			$code = (string) reset( $codes );
		}
		if ( '' === $code ) {
			return;
		}
		self::cookie( RINNOVO_Currency::COOKIE, $code );
	}

	/**
	 * Set a cookie for this request and the next ones.
	 *
	 * @param string $name  Name.
	 * @param string $value Value.
	 */
	private static function cookie( $name, $value ) {
		$_COOKIE[ $name ] = $value;
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/*
	 * Own conversion.
	 */

	/**
	 * Filters that show prices in the language's currency.
	 */
	private static function own_filters() {
		add_filter( 'woocommerce_currency', array( __CLASS__, 'currency' ), 999 );
		add_filter( 'wc_get_price_decimals', array( __CLASS__, 'decimals' ), 999 );
		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_get_regular_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_regular_price', 'woocommerce_product_variation_get_sale_price', 'woocommerce_variation_prices_price', 'woocommerce_variation_prices_regular_price', 'woocommerce_variation_prices_sale_price' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'price' ), 999 );
		}
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'hash' ), 999 );
		add_filter( 'woocommerce_cart_hash', array( __CLASS__, 'cart_hash' ), 999 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'shipping' ), 999 );
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'shipping_packages' ), 999 );
		add_filter( 'woocommerce_coupon_get_amount', array( __CLASS__, 'coupon_amount' ), 999, 2 );
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( __CLASS__, 'amount' ), 999 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', array( __CLASS__, 'amount' ), 999 );
	}

	/**
	 * Convert an amount from the store currency.
	 *
	 * @param mixed $amount Amount.
	 * @param bool  $round  Use the rounding rule (product prices).
	 * @return mixed
	 */
	public static function convert( $amount, $round = false ) {
		$row = self::active();
		if ( ! $row || '' === $amount || null === $amount || ! is_numeric( $amount ) ) {
			return $amount;
		}
		$value = (float) $amount * $row['rate'];
		if ( $round && $value > 0 ) {
			if ( 'whole' === $row['rounding'] ) {
				$value = ceil( $value );
			} elseif ( '99' === $row['rounding'] ) {
				$value = ceil( $value ) - 0.01;
			} elseif ( '5' === $row['rounding'] ) {
				$value = ceil( $value / 5 ) * 5;
			}
		}
		return round( $value, $row['decimals'] );
	}

	/**
	 * Currency code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function currency( $code ) {
		$row = self::active();
		return $row ? $row['code'] : $code;
	}

	/**
	 * Decimals.
	 *
	 * @param int $decimals Decimals.
	 * @return int
	 */
	public static function decimals( $decimals ) {
		$row = self::active();
		return $row ? $row['decimals'] : $decimals;
	}

	/**
	 * Product price.
	 *
	 * @param mixed $price Price.
	 * @return mixed
	 */
	public static function price( $price ) {
		if ( self::$busy ) {
			return $price;
		}
		self::$busy = true;
		$out        = self::convert( $price, true );
		self::$busy = false;
		return $out;
	}

	/**
	 * Other amounts (no rounding rule).
	 *
	 * @param mixed $amount Amount.
	 * @return mixed
	 */
	public static function amount( $amount ) {
		return self::convert( $amount );
	}

	/**
	 * Fixed coupon amounts (percentages stay).
	 *
	 * @param mixed     $amount Amount.
	 * @param WC_Coupon $coupon Coupon.
	 * @return mixed
	 */
	public static function coupon_amount( $amount, $coupon ) {
		return $coupon instanceof WC_Coupon && $coupon->is_type( 'percent' ) ? $amount : self::convert( $amount );
	}

	/**
	 * Cached variation prices per currency.
	 *
	 * @param array $hash Hash parts.
	 * @return array
	 */
	public static function hash( $hash ) {
		$row    = self::active();
		$hash[] = $row ? $row['code'] . $row['rate'] : self::base();
		return $hash;
	}

	/**
	 * Cart hash per currency.
	 *
	 * @param string $hash Hash.
	 * @return string
	 */
	public static function cart_hash( $hash ) {
		$row = self::active();
		return $row ? md5( $hash . $row['code'] ) : $hash;
	}

	/**
	 * Shipping costs.
	 *
	 * @param WC_Shipping_Rate[] $rates Rates.
	 * @return array
	 */
	public static function shipping( $rates ) {
		if ( ! self::active() ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			$rate->set_cost( self::convert( $rate->get_cost() ) );
			$taxes = array();
			foreach ( $rate->get_taxes() as $key => $tax ) {
				$taxes[ $key ] = self::convert( $tax );
			}
			$rate->set_taxes( $taxes );
		}
		return $rates;
	}

	/**
	 * Shipping is worked out again in another currency.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public static function shipping_packages( $packages ) {
		$row = self::active();
		foreach ( $packages as $key => $package ) {
			$packages[ $key ]['vacanam_currency'] = $row ? $row['code'] : self::base();
		}
		return $packages;
	}

	/*
	 * Settings.
	 */

	/**
	 * Section.
	 */
	public static function section() {
		$mode  = (string) VACANAMPRO_Settings::get( 'currency_mode' );
		$langs = Vacanam_Languages::all();
		$base  = self::base();
		$codes = array_keys( get_woocommerce_currencies() );
		echo '<div class="vacanam-card"><h2>' . esc_html__( 'A currency per language', 'vacanam-language-switcher-pro' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Currency', 'vacanam-language-switcher-pro' ) . '</th><td>';
		echo '<label><input type="radio" name="vacanampro_currency_mode" value=""' . checked( '', $mode, false ) . ' /> ' . esc_html( sprintf( /* translators: %s: currency code */ __( 'Off: every language uses %s', 'vacanam-language-switcher-pro' ), $base ) ) . '</label><br />';
		if ( self::has_rinnovo() ) {
			echo '<label><input type="radio" name="vacanampro_currency_mode" value="rinnovo"' . checked( 'rinnovo', $mode, false ) . ' /> ' . esc_html__( 'Use the currencies of Rinnovo\'s currency switcher: the language chooses one, the visitor can still pick another (recommended)', 'vacanam-language-switcher-pro' ) . '</label><br />';
		}
		echo '<label><input type="radio" name="vacanampro_currency_mode" value="own"' . checked( 'own', $mode, false ) . ' /> ' . esc_html__( 'Vacanam converts prices at the rates below', 'vacanam-language-switcher-pro' ) . '</label>';
		if ( ! self::has_rinnovo() ) {
			echo '<p class="description">' . esc_html__( 'Need visitors to pick any currency, automatic daily rates and subscriptions renewing in the customer\'s currency? Rinnovo Subscriptions & Licenses Pro has a full currency switcher, and Vacanam follows it.', 'vacanam-language-switcher-pro' ) . '</p>';
		}
		echo '</td></tr><tr><th scope="row">' . esc_html__( 'Languages', 'vacanam-language-switcher-pro' ) . '</th><td><table class="widefat striped vacanampro-currency-table"><thead><tr><th>' . esc_html__( 'Language', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html__( 'Currency', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html( sprintf( /* translators: %s: store currency */ __( 'Rate (1 %s =)', 'vacanam-language-switcher-pro' ), $base ) ) . '</th><th>' . esc_html__( 'Decimals', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html__( 'Round prices', 'vacanam-language-switcher-pro' ) . '</th></tr></thead><tbody>';
		$rounding = array(
			'none'  => __( 'No', 'vacanam-language-switcher-pro' ),
			'whole' => __( 'Up to a whole number', 'vacanam-language-switcher-pro' ),
			'99'    => __( 'To .99', 'vacanam-language-switcher-pro' ),
			'5'     => __( 'Up to 5', 'vacanam-language-switcher-pro' ),
		);
		foreach ( $langs as $slug => $lang ) {
			$row = self::for_language( $slug );
			echo '<tr><td>' . esc_html( $lang['name'] ) . '</td><td><select name="vacanampro_currency[' . esc_attr( $slug ) . '][code]"><option value="">' . esc_html( sprintf( /* translators: %s: currency code */ __( 'Store currency (%s)', 'vacanam-language-switcher-pro' ), $base ) ) . '</option>';
			foreach ( $codes as $code ) {
				if ( $code !== $base ) {
					echo '<option value="' . esc_attr( $code ) . '"' . selected( $row['code'], $code, false ) . '>' . esc_html( $code ) . '</option>';
				}
			}
			echo '</select></td><td><input type="number" step="any" min="0" class="small-text vacanampro-own" name="vacanampro_currency[' . esc_attr( $slug ) . '][rate]" value="' . esc_attr( $row['rate'] ? (string) $row['rate'] : '' ) . '" style="width:7em" /></td>';
			echo '<td><input type="number" min="0" max="4" class="small-text vacanampro-own" name="vacanampro_currency[' . esc_attr( $slug ) . '][decimals]" value="' . esc_attr( (string) $row['decimals'] ) . '" /></td><td><select class="vacanampro-own" name="vacanampro_currency[' . esc_attr( $slug ) . '][rounding]">';
			foreach ( $rounding as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '"' . selected( $row['rounding'], $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Rates, decimals and rounding are used only when Vacanam converts prices; with Rinnovo they come from Rinnovo\'s settings. Orders are placed and paid in the currency shown.', 'vacanam-language-switcher-pro' ) . '</p></td></tr></table></div>';
	}

	/**
	 * Save.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save_settings( $values ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in VACANAMPRO_Settings::save().
		$mode                    = isset( $_POST['vacanampro_currency_mode'] ) ? sanitize_key( wp_unslash( $_POST['vacanampro_currency_mode'] ) ) : '';
		$values['currency_mode'] = in_array( $mode, array( 'rinnovo', 'own' ), true ) ? $mode : '';
		$raw                     = isset( $_POST['vacanampro_currency'] ) && is_array( $_POST['vacanampro_currency'] ) ? map_deep( wp_unslash( $_POST['vacanampro_currency'] ), 'sanitize_text_field' ) : array();
		// phpcs:enable
		$codes = array_keys( get_woocommerce_currencies() );
		$map   = array();
		foreach ( Vacanam_Languages::slugs() as $slug ) {
			$row  = isset( $raw[ $slug ] ) && is_array( $raw[ $slug ] ) ? $raw[ $slug ] : array();
			$code = isset( $row['code'] ) ? strtoupper( (string) $row['code'] ) : '';
			if ( ! in_array( $code, $codes, true ) ) {
				continue;
			}
			$map[ $slug ] = array(
				'code'     => $code,
				'rate'     => isset( $row['rate'] ) ? max( 0, (float) $row['rate'] ) : 0,
				'decimals' => isset( $row['decimals'] ) ? min( 4, max( 0, (int) $row['decimals'] ) ) : 2,
				'rounding' => isset( $row['rounding'] ) && in_array( $row['rounding'], array( 'none', 'whole', '99', '5' ), true ) ? $row['rounding'] : 'none',
			);
		}
		$values['currency_map'] = $map;
		return $values;
	}
}
