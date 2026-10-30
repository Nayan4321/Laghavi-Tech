<?php
/**
 * Currency switcher: visitors pick a currency, prices convert from the store currency at set rates,
 * and the order (and its subscription, Stripe charge and renewals) stays in the chosen currency.
 *
 * @package HappySubscriptionPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Multi-currency.
 */
class HAPPYSUB_Currency {

	const COOKIE = 'happysub_currency';
	const OPTION = 'happysub_currencies';

	/**
	 * Currency forced for the current operation (renewals run in the subscription's currency).
	 *
	 * @var string|null
	 */
	private static $override = null;

	/**
	 * Currency chosen by the visitor for this request.
	 *
	 * @var string|null
	 */
	private static $chosen = null;

	/**
	 * Guard against re-entering the currency filter.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		// Happy Subscription asks through these filters when it needs another currency.
		add_filter( 'happysub_currency_runner', array( __CLASS__, 'runner' ) );
		add_filter( 'happysub_to_store_currency', array( __CLASS__, 'to_base' ), 10, 2 );
		add_filter( 'happysub_convert_price', array( __CLASS__, 'convert' ) );

		// Settings live on the Subscriptions settings tab.
		add_action( 'woocommerce_admin_field_happysub_currencies', array( __CLASS__, 'settings_table' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPTION, array( __CLASS__, 'sanitize_rows' ), 10, 3 );
		add_action( 'admin_post_happysub_fetch_rates', array( __CLASS__, 'fetch_rates_now' ) );
		add_action( 'happysub_daily_rates', array( __CLASS__, 'update_rates' ) );
		// Scheduled once WordPress is fully loaded (scheduling builds translated cron labels).
		add_action(
			'init',
			static function () {
				if ( HAPPYSUB_Settings::on( 'happysub_rates_auto' ) && self::currencies() && ! wp_next_scheduled( 'happysub_daily_rates' ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'happysub_daily_rates' );
				}
			}
		);

		if ( ! self::currencies() ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'pick_currency' ), 5 );
		add_filter( 'woocommerce_currency', array( __CLASS__, 'filter_currency' ), 999 );
		add_filter( 'wc_get_price_decimals', array( __CLASS__, 'filter_decimals' ), 999 );

		// Product prices.
		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_get_regular_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_regular_price', 'woocommerce_product_variation_get_sale_price', 'woocommerce_variation_prices_price', 'woocommerce_variation_prices_regular_price', 'woocommerce_variation_prices_sale_price' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'filter_price' ), 999 );
		}
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'variation_hash' ), 999 );
		add_filter( 'woocommerce_cart_hash', array( __CLASS__, 'cart_hash' ), 999 );

		// Coupons and shipping.
		add_filter( 'woocommerce_coupon_get_amount', array( __CLASS__, 'filter_coupon_amount' ), 999, 2 );
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( __CLASS__, 'filter_amount' ), 999 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', array( __CLASS__, 'filter_amount' ), 999 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_shipping' ), 999 );
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'shipping_package_key' ), 999 );

		// Switcher.
		add_shortcode( 'happysub_currency_switcher', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_footer', array( __CLASS__, 'floating_switcher' ) );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'checkout_switcher' ) );
	}

	/**
	 * Hand Happy Subscription the function that runs code in a given currency.
	 *
	 * @return callable
	 */
	public static function runner() {
		return array( __CLASS__, 'run_as' );
	}

	// ---------------------------------------------------------------- Data.

	/**
	 * The store's own currency (WooCommerce > Settings > General), unfiltered.
	 *
	 * @return string
	 */
	public static function base() {
		return (string) get_option( 'woocommerce_currency', 'USD' );
	}

	/**
	 * Extra currencies, keyed by code.
	 *
	 * @return array<string, array{rate: float, decimals: int, rounding: string, countries: string[]}>
	 */
	public static function currencies() {
		$rows = get_option( self::OPTION, array() );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( empty( $row['code'] ) || $row['code'] === self::base() || (float) $row['rate'] <= 0 ) {
				continue;
			}
			$out[ $row['code'] ] = array(
				'rate'      => (float) $row['rate'],
				'decimals'  => isset( $row['decimals'] ) ? (int) $row['decimals'] : 2,
				'rounding'  => isset( $row['rounding'] ) ? $row['rounding'] : 'none',
				'countries' => isset( $row['countries'] ) ? (array) $row['countries'] : array(),
			);
		}
		return $out;
	}

	/**
	 * Codes offered to visitors, store currency first.
	 *
	 * @return string[]
	 */
	public static function codes() {
		return array_merge( array( self::base() ), array_keys( self::currencies() ) );
	}

	/**
	 * Rate from the store currency to a currency (1 for the store currency).
	 *
	 * @param string $code Currency.
	 * @return float
	 */
	public static function rate( $code ) {
		$all = self::currencies();
		return isset( $all[ $code ] ) ? $all[ $code ]['rate'] : 1.0;
	}

	/**
	 * Currency in use right now.
	 *
	 * @return string
	 */
	public static function current() {
		if ( null !== self::$override ) {
			return self::$override;
		}
		if ( ! self::applies() ) {
			return self::base();
		}
		return null !== self::$chosen ? self::$chosen : self::base();
	}

	/**
	 * Run code with a fixed currency (used for renewals, which keep the subscription's currency).
	 *
	 * @param string   $code     Currency.
	 * @param callable $callback Code to run.
	 * @return mixed
	 */
	public static function run_as( $code, $callback ) {
		$before         = self::$override;
		self::$override = in_array( $code, self::codes(), true ) ? $code : self::base();
		try {
			return call_user_func( $callback );
		} finally {
			self::$override = $before;
		}
	}

	/**
	 * Whether this request shows prices to shoppers (not the admin, not admin API calls).
	 *
	 * @return bool
	 */
	private static function applies() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			return false !== strpos( $uri, 'wc/store' );
		}
		if ( is_admin() ) {
			if ( ! wp_doing_ajax() ) {
				return false;
			}
			// admin-ajax.php called from an admin screen (e.g. editing an order) keeps the store currency.
			$referer = wp_get_referer();
			return ! $referer || 0 !== strpos( $referer, admin_url() );
		}
		return ! wp_doing_cron();
	}

	/**
	 * Convert an amount from the store currency into a currency, with that currency's rounding.
	 *
	 * @param float       $amount Amount in the store currency.
	 * @param string|null $code   Currency; current when null.
	 * @param bool        $round  Apply the price rounding rule (for product prices).
	 * @return float
	 */
	public static function convert( $amount, $code = null, $round = false ) {
		$code = null === $code ? self::current() : $code;
		if ( '' === $amount || null === $amount || $code === self::base() ) {
			return $amount;
		}
		$all = self::currencies();
		if ( ! isset( $all[ $code ] ) ) {
			return $amount;
		}
		$value = (float) $amount * $all[ $code ]['rate'];
		if ( $round && $value > 0 ) {
			switch ( $all[ $code ]['rounding'] ) {
				case 'whole':
					$value = ceil( $value );
					break;
				case '99':
					$value = ceil( $value ) - 0.01;
					break;
				case '5':
					$value = ceil( $value / 5 ) * 5;
					break;
			}
		}
		return round( $value, $all[ $code ]['decimals'] );
	}

	/**
	 * Convert an amount in some currency back to the store currency (for reports).
	 *
	 * @param float  $amount Amount.
	 * @param string $code   Its currency.
	 * @return float
	 */
	public static function to_base( $amount, $code ) {
		return (float) $amount / max( 0.000001, self::rate( $code ) );
	}

	// ---------------------------------------------------------------- Choosing a currency.

	/**
	 * Read the visitor's choice (link, cookie, or country), and remember it.
	 */
	public static function pick_currency() {
		if ( ! self::applies() ) {
			return;
		}
		$codes = self::codes();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A display preference, nothing is changed on the server.
		$asked = isset( $_GET['currency'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['currency'] ) ) ) : '';
		if ( '' !== $asked && in_array( $asked, $codes, true ) ) {
			self::$chosen = $asked;
			self::remember( $asked );
			if ( ! wp_doing_ajax() && 'GET' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) {
				wp_safe_redirect( remove_query_arg( 'currency' ) );
				exit;
			}
			return;
		}

		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? strtoupper( sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) : '';
		if ( in_array( $cookie, $codes, true ) ) {
			self::$chosen = $cookie;
			return;
		}

		if ( HAPPYSUB_Settings::on( 'happysub_currency_geo' ) ) {
			$country = self::visitor_country();
			foreach ( self::currencies() as $code => $currency ) {
				if ( '' !== $country && in_array( $country, $currency['countries'], true ) ) {
					self::$chosen = $code;
					self::remember( $code );
					return;
				}
			}
		}
	}

	/**
	 * Remember the choice for a year.
	 *
	 * @param string $code Currency.
	 */
	private static function remember( $code ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$code,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $code;
	}

	/**
	 * Visitor's country: Cloudflare's header if present, else WooCommerce geolocation (MaxMind, no outside calls).
	 *
	 * @return string Two-letter code or ''.
	 */
	private static function visitor_country() {
		if ( isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$cc = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $cc ) && 'XX' !== $cc ) {
				return $cc;
			}
		}
		if ( class_exists( 'WC_Geolocation' ) ) {
			$geo = WC_Geolocation::geolocate_ip( '', true, false );
			return isset( $geo['country'] ) ? (string) $geo['country'] : '';
		}
		return '';
	}

	// ---------------------------------------------------------------- Filters.

	/**
	 * The currency WooCommerce uses for prices, the cart and new orders.
	 *
	 * @param string $currency Store currency.
	 * @return string
	 */
	public static function filter_currency( $currency ) {
		if ( self::$busy ) {
			return $currency;
		}
		self::$busy = true;
		$current    = self::current();
		self::$busy = false;
		return $current;
	}

	/**
	 * Decimals of the current currency.
	 *
	 * @param int $decimals Store decimals.
	 * @return int
	 */
	public static function filter_decimals( $decimals ) {
		$all  = self::currencies();
		$code = self::current();
		return isset( $all[ $code ] ) ? $all[ $code ]['decimals'] : $decimals;
	}

	/**
	 * Product prices in the current currency.
	 *
	 * @param mixed $price Price in the store currency.
	 * @return mixed
	 */
	public static function filter_price( $price ) {
		return '' === $price || null === $price ? $price : self::convert( $price, null, true );
	}

	/**
	 * Plain amounts (coupon limits) in the current currency.
	 *
	 * @param mixed $amount Amount.
	 * @return mixed
	 */
	public static function filter_amount( $amount ) {
		return '' === $amount || null === $amount ? $amount : self::convert( $amount );
	}

	/**
	 * Fixed coupon amounts (percentage coupons stay as they are).
	 *
	 * @param mixed     $amount Amount.
	 * @param WC_Coupon $coupon Coupon.
	 * @return mixed
	 */
	public static function filter_coupon_amount( $amount, $coupon ) {
		return $coupon->is_type( 'percent' ) ? $amount : self::filter_amount( $amount );
	}

	/**
	 * Shipping costs in the current currency.
	 *
	 * @param WC_Shipping_Rate[] $rates Rates.
	 * @return WC_Shipping_Rate[]
	 */
	public static function filter_shipping( $rates ) {
		if ( self::current() === self::base() ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			if ( $rate->get_meta_data() && isset( $rate->get_meta_data()['_happysub_converted'] ) ) {
				continue;
			}
			$rate->set_cost( self::convert( $rate->get_cost() ) );
			$taxes = array();
			foreach ( (array) $rate->get_taxes() as $key => $tax ) {
				$taxes[ $key ] = self::convert( $tax );
			}
			$rate->set_taxes( $taxes );
			$rate->add_meta_data( '_happysub_converted', self::current() );
		}
		return $rates;
	}

	/**
	 * Recalculate shipping when the currency changes.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public static function shipping_package_key( $packages ) {
		foreach ( $packages as $i => $package ) {
			$packages[ $i ]['happysub_currency'] = self::current();
		}
		return $packages;
	}

	/**
	 * Separate cached variation prices per currency and rate.
	 *
	 * @param array $hash Hash parts.
	 * @return array
	 */
	public static function variation_hash( $hash ) {
		$hash[] = self::current() . ':' . self::rate( self::current() );
		return $hash;
	}

	/**
	 * Refresh the mini-cart when the currency changes.
	 *
	 * @param string $hash Cart hash.
	 * @return string
	 */
	public static function cart_hash( $hash ) {
		return '' === $hash ? $hash : md5( $hash . self::current() );
	}

	// ---------------------------------------------------------------- Switcher.

	/**
	 * Switcher HTML: a button showing the current currency's symbol that opens a list of symbols.
	 * Plain links, no form field, so themes can't restyle it into a search box, and it works without JavaScript.
	 *
	 * @param string $class Extra class ("floating" or "inline").
	 * @return string
	 */
	public static function switcher( $class = '' ) {
		$current = self::current();
		$names   = get_woocommerce_currencies();
		$html    = '<div class="happysub-currency ' . esc_attr( $class ) . '"><details>';
		$html   .= '<summary aria-label="' . esc_attr( sprintf( /* translators: %s: currency code. */ __( 'Currency: %s', 'happy-subscription-pro' ), $current ) ) . '" title="' . esc_attr( $current ) . '">' . self::symbol_html( $current ) . '</summary><ul>';
		foreach ( self::codes() as $code ) {
			$label = ( isset( $names[ $code ] ) ? $names[ $code ] . ' ' : '' ) . '(' . $code . ')';
			$html .= '<li><a href="' . esc_url( add_query_arg( 'currency', $code, remove_query_arg( array( 'add-to-cart', 'added-to-cart', 'quantity', 'variation_id' ) ) ) ) . '" class="' . ( $current === $code ? 'is-current' : '' ) . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '">' . self::symbol_html( $code ) . '</a></li>';
		}
		$html .= '</ul></details></div>';
		return $html;
	}

	/**
	 * A currency's symbol, isolated so right-to-left symbols like the dirham's display correctly.
	 *
	 * @param string $code Currency code.
	 * @return string
	 */
	private static function symbol_html( $code ) {
		$symbol = html_entity_decode( get_woocommerce_currency_symbol( $code ), ENT_QUOTES, 'UTF-8' );
		return '<bdi class="happysub-currency__symbol">' . esc_html( '' !== $symbol ? $symbol : $code ) . '</bdi>';
	}

	/**
	 * Allowed switcher markup.
	 *
	 * @return array
	 */
	private static function allowed_html() {
		return array(
			'div'     => array( 'class' => true ),
			'details' => array(),
			'summary' => array(
				'aria-label' => true,
				'title'      => true,
			),
			'ul'      => array(),
			'li'      => array(),
			'a'       => array(
				'href'       => true,
				'class'      => true,
				'title'      => true,
				'aria-label' => true,
			),
			'bdi'     => array( 'class' => true ),
		);
	}

	/**
	 * [happysub_currency_switcher] for menus, headers and widgets.
	 *
	 * @return string
	 */
	public static function shortcode() {
		add_action( 'wp_footer', array( __CLASS__, 'close_script' ) );
		return wp_kses( self::switcher( 'inline' ), self::allowed_html() );
	}

	/**
	 * Close an open switcher when the visitor clicks elsewhere or presses Escape (once per page).
	 */
	public static function close_script() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		wp_print_inline_script_tag( "(function(){function c(e){document.querySelectorAll('.happysub-currency details[open]').forEach(function(d){if(!e||e.type==='keydown'||!d.contains(e.target)){d.removeAttribute('open');}});}document.addEventListener('click',c);document.addEventListener('keydown',function(e){if(e.key==='Escape'){c(e);}});})();" );
	}

	/**
	 * Floating switcher in a corner of every page (optional).
	 */
	public static function floating_switcher() {
		if ( HAPPYSUB_Settings::on( 'happysub_currency_floating' ) && ! is_admin() ) {
			echo wp_kses( self::switcher( 'floating' ), self::allowed_html() );
			self::close_script();
		}
	}

	/**
	 * Switcher next to the payment methods on checkout.
	 */
	public static function checkout_switcher() {
		if ( ! HAPPYSUB_Settings::on( 'happysub_currency_checkout' ) ) {
			return;
		}
		echo '<div class="happysub-currency-checkout"><span>' . esc_html__( 'Pay in', 'happy-subscription-pro' ) . '</span> ';
		// The checkout form must not be nested in another form: use links here instead.
		foreach ( self::codes() as $code ) {
			printf(
				'<a href="%s" class="%s">%s</a> ',
				esc_url( add_query_arg( 'currency', $code, wc_get_checkout_url() ) ),
				esc_attr( self::current() === $code ? 'is-current' : '' ),
				esc_html( $code )
			);
		}
		echo '</div>';
	}

	// ---------------------------------------------------------------- Settings.

	/**
	 * Common currencies for the picker.
	 *
	 * @return string[]
	 */
	private static function all_codes() {
		return array_keys( get_woocommerce_currencies() );
	}

	/**
	 * Editable table of currencies on the settings tab.
	 *
	 * @param array $field Field definition.
	 */
	public static function settings_table( $field ) {
		$rows   = get_option( self::OPTION, array() );
		$rows   = is_array( $rows ) ? $rows : array();
		$rows[] = array(
			'code'      => '',
			'rate'      => '',
			'decimals'  => 2,
			'rounding'  => 'none',
			'countries' => array(),
		);
		$names  = get_woocommerce_currencies();
		$base   = self::base();
		$fetch  = wp_nonce_url( admin_url( 'admin-post.php?action=happysub_fetch_rates' ), 'happysub_fetch_rates' );
		$stamp  = (int) get_option( 'happysub_rates_updated' );
		$error  = get_transient( 'happysub_rates_error' );
		if ( $error ) {
			delete_transient( 'happysub_rates_error' );
			echo '<tr><td colspan="2"><div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div></td></tr>';
		}
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $field['title'] ); ?></th>
			<td class="forminp">
				<p class="description">
					<?php
					/* translators: %s: store currency code. */
					echo esc_html( sprintf( __( 'Prices are entered in %s (your store currency, set in Settings > General) and converted with these rates. Leave the last row empty to add nothing.', 'happy-subscription-pro' ), $base ) );
					?>
				</p>
				<table class="widefat happysub-currency-table" style="max-width:900px;margin-top:8px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Currency', 'happy-subscription-pro' ); ?></th>
							<th>
								<?php
								/* translators: %s: store currency code. */
								echo esc_html( sprintf( __( 'Rate (1 %s =)', 'happy-subscription-pro' ), $base ) );
								?>
							</th>
							<th><?php esc_html_e( 'Decimals', 'happy-subscription-pro' ); ?></th>
							<th><?php esc_html_e( 'Round prices', 'happy-subscription-pro' ); ?></th>
							<th><?php esc_html_e( 'Visitor countries (auto-select)', 'happy-subscription-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_values( $rows ) as $i => $row ) : ?>
							<tr>
								<td>
									<select name="<?php echo esc_attr( self::OPTION . '[' . $i . '][code]' ); ?>">
										<option value=""><?php esc_html_e( '— None —', 'happy-subscription-pro' ); ?></option>
										<?php foreach ( self::all_codes() as $code ) : ?>
											<?php if ( $code !== $base ) : ?>
												<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $row['code'], $code ); ?>><?php echo esc_html( $code . ' — ' . $names[ $code ] ); ?></option>
											<?php endif; ?>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="number" step="0.000001" min="0" style="width:120px" name="<?php echo esc_attr( self::OPTION . '[' . $i . '][rate]' ); ?>" value="<?php echo esc_attr( $row['rate'] ); ?>" /></td>
								<td><input type="number" min="0" max="4" style="width:60px" name="<?php echo esc_attr( self::OPTION . '[' . $i . '][decimals]' ); ?>" value="<?php echo esc_attr( isset( $row['decimals'] ) ? $row['decimals'] : 2 ); ?>" /></td>
								<td>
									<select name="<?php echo esc_attr( self::OPTION . '[' . $i . '][rounding]' ); ?>">
										<option value="none" <?php selected( $row['rounding'], 'none' ); ?>><?php esc_html_e( 'Exact', 'happy-subscription-pro' ); ?></option>
										<option value="whole" <?php selected( $row['rounding'], 'whole' ); ?>><?php esc_html_e( 'Up to whole (e.g. 180)', 'happy-subscription-pro' ); ?></option>
										<option value="99" <?php selected( $row['rounding'], '99' ); ?>><?php esc_html_e( 'Ending .99 (e.g. 179.99)', 'happy-subscription-pro' ); ?></option>
										<option value="5" <?php selected( $row['rounding'], '5' ); ?>><?php esc_html_e( 'Up to 5 (e.g. 180, 185)', 'happy-subscription-pro' ); ?></option>
									</select>
								</td>
								<td><input type="text" style="width:100%" placeholder="AE, SA, QA" name="<?php echo esc_attr( self::OPTION . '[' . $i . '][countries]' ); ?>" value="<?php echo esc_attr( implode( ', ', isset( $row['countries'] ) ? (array) $row['countries'] : array() ) ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<a class="button" href="<?php echo esc_url( $fetch ); ?>"><?php esc_html_e( 'Update rates now', 'happy-subscription-pro' ); ?></a>
					<span class="description">
						<?php
						echo esc_html(
							$stamp
								/* translators: %s: date and time. */
								? sprintf( __( 'Last updated %s from open.er-api.com.', 'happy-subscription-pro' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stamp ) )
								: __( 'Fetches today\'s rates from the free open.er-api.com service. Save your currencies first.', 'happy-subscription-pro' )
						);
						?>
					</span>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Clean the submitted table.
	 *
	 * @param mixed $value     Sanitized value so far.
	 * @param array $option    Field.
	 * @param mixed $raw_value Raw submitted rows.
	 * @return array
	 */
	public static function sanitize_rows( $value, $option, $raw_value ) {
		$rows  = array();
		$valid = self::all_codes();
		$seen  = array();
		foreach ( is_array( $raw_value ) ? $raw_value : array() as $row ) {
			$code = isset( $row['code'] ) ? strtoupper( sanitize_text_field( $row['code'] ) ) : '';
			if ( '' === $code || ! in_array( $code, $valid, true ) || isset( $seen[ $code ] ) || $code === self::base() ) {
				continue;
			}
			$seen[ $code ] = true;
			$countries     = array_filter(
				array_map(
					static function ( $c ) {
						$c = strtoupper( trim( $c ) );
						return preg_match( '/^[A-Z]{2}$/', $c ) ? $c : '';
					},
					explode( ',', isset( $row['countries'] ) ? (string) $row['countries'] : '' )
				)
			);
			$rows[]        = array(
				'code'      => $code,
				'rate'      => max( 0, (float) ( isset( $row['rate'] ) ? $row['rate'] : 0 ) ),
				'decimals'  => min( 4, max( 0, (int) ( isset( $row['decimals'] ) ? $row['decimals'] : 2 ) ) ),
				'rounding'  => isset( $row['rounding'] ) && in_array( $row['rounding'], array( 'none', 'whole', '99', '5' ), true ) ? $row['rounding'] : 'none',
				'countries' => array_values( $countries ),
			);
		}
		return $rows;
	}

	/**
	 * Fetch rates now (button), then back to the settings.
	 */
	public static function fetch_rates_now() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'happy-subscription-pro' ) );
		}
		check_admin_referer( 'happysub_fetch_rates' );
		$result = self::update_rates();
		if ( is_wp_error( $result ) && class_exists( 'WC_Admin_Settings' ) ) {
			set_transient( 'happysub_rates_error', $result->get_error_message(), 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=' . HAPPYSUB_Settings::TAB ) );
		exit;
	}

	/**
	 * Update every rate from open.er-api.com (free, daily rates).
	 *
	 * @return true|WP_Error
	 */
	public static function update_rates() {
		$rows = get_option( self::OPTION, array() );
		if ( ! is_array( $rows ) || ! $rows ) {
			return new WP_Error( 'happysub_rates', __( 'Add a currency first.', 'happy-subscription-pro' ) );
		}
		$response = wp_remote_get( 'https://open.er-api.com/v6/latest/' . rawurlencode( self::base() ), array( 'timeout' => 15 ) );
		$data     = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['rates'] ) || ( isset( $data['result'] ) && 'success' !== $data['result'] ) ) {
			return new WP_Error( 'happysub_rates', __( 'Could not fetch exchange rates. Please try again later or enter them by hand.', 'happy-subscription-pro' ) );
		}
		foreach ( $rows as $i => $row ) {
			if ( isset( $row['code'], $data['rates'][ $row['code'] ] ) && (float) $data['rates'][ $row['code'] ] > 0 ) {
				$rows[ $i ]['rate'] = round( (float) $data['rates'][ $row['code'] ], 6 );
			}
		}
		update_option( self::OPTION, $rows );
		update_option( 'happysub_rates_updated', time(), false );
		return true;
	}
}
