<?php
/**
 * Recommendations for other Laghavi plugins (Rinnovo Subscriptions & Licenses, Sarala Login Page), shown only on
 * Performance Insight screens, only when the store's own data points to them, and each can be dismissed.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data-based recommendations.
 */
class LGPI_Recommend {

	/** A dismissed recommendation stays hidden this long. */
	const HIDE_DAYS = 120;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_lgpi_rec_dismiss', array( __CLASS__, 'dismiss' ) );
	}

	/**
	 * Signals from the store, worked out at most twice a day.
	 *
	 * @return array
	 */
	public static function signals() {
		$s = get_transient( 'lgpi_rec_signals' );
		if ( is_array( $s ) ) {
			return $s;
		}
		global $wpdb;
		$s = array(
			'repeat'     => 0,
			'repeat_top' => '',
			'digital'    => 0,
			'abandoned'  => 0,
			'guest'      => 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' ),
			'countries'  => 0,
			'base'       => '',
		);
		if ( LGPI_Plugin::has_woo() ) {
			$lookup = $wpdb->prefix . 'wc_order_product_lookup';
			$stats  = $wpdb->prefix . 'wc_order_stats';
			$since  = gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) ) === $lookup ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT l.product_id, COUNT(*) AS buyers FROM (SELECT l.customer_id, l.product_id FROM %i l JOIN %i o ON o.order_id = l.order_id WHERE l.date_created >= %s AND l.customer_id > 0 AND o.status IN ('wc-completed','wc-processing') GROUP BY l.customer_id, l.product_id HAVING COUNT(DISTINCT l.order_id) >= 2) l GROUP BY l.product_id ORDER BY buyers DESC LIMIT 5", $lookup, $stats, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$s['repeat'] = (int) array_sum( array_column( $rows, 'buyers' ) );
				if ( $rows ) {
					$s['repeat_top'] = (string) get_the_title( (int) $rows[0]['product_id'] );
				}
			}
			$s['digital'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'product' AND p.post_status = 'publish' AND ( ( m.meta_key = '_downloadable' AND m.meta_value = 'yes' ) OR ( m.meta_key = '_virtual' AND m.meta_value = 'yes' ) )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$to             = current_time( 'Y-m-d' );
			$from           = gmdate( 'Y-m-d', strtotime( $to . ' -29 days' ) );
			$s['abandoned'] = count( array_filter( LGPI_Journey::rows( $from, $to ), fn( $j ) => 'abandoned' === $j['status'] ) );
			$s['countries'] = count( LGPI_DB::dims( 'country', $from, $to, 50 ) );
			$s['base']      = function_exists( 'WC' ) && WC()->countries ? (string) WC()->countries->get_base_country() : '';
		}
		set_transient( 'lgpi_rec_signals', $s, 12 * HOUR_IN_SECONDS );
		return $s;
	}

	/**
	 * Recommendations that fit a screen right now.
	 *
	 * @param string $where dashboard | products | journeys | rinnovo | logins.
	 * @return array[] key, icon, title, text, buttons [[label, url, primary]]
	 */
	public static function for_screen( $where ) {
		if ( ! LGPI_Family::allowed() ) {
			return array();
		}
		$sig = self::signals();
		$out = array();
		$fam = LGPI_Family::active();
		if ( in_array( $where, array( 'dashboard', 'products' ), true ) && empty( $fam['rinnovo'] ) ) {
			if ( $sig['repeat'] >= 1 ) {
				$out[] = array(
					'key'     => 'rinnovo_repeat',
					'icon'    => 'dashicons-update',
					'title'   => __( 'Customers buy the same products again', 'laghavi-performance-insight-for-woocommerce' ),
					/* translators: 1: number of customers, 2: product name */
					'text'    => sprintf( _n( '%1$d customer bought the same product more than once in the last year (%2$s). Offer it as a subscription so it renews automatically — with renewal reminders, failed-payment retries and a customer self-service area.', '%1$d customers bought the same product more than once in the last year (most often: %2$s). Offer it as a subscription so it renews automatically — with renewal reminders, failed-payment retries and a customer self-service area.', $sig['repeat'], 'laghavi-performance-insight-for-woocommerce' ), $sig['repeat'], $sig['repeat_top'] ),
					'buttons' => self::free_buttons( 'rinnovo-subscriptions-licenses', 'Rinnovo Subscriptions & Licenses', 'https://laghavi.site/rinnovo-subscriptions-licenses/' ),
				);
			} elseif ( $sig['digital'] >= 1 && 'products' === $where ) {
				$out[] = array(
					'key'     => 'rinnovo_digital',
					'icon'    => 'dashicons-admin-network',
					'title'   => __( 'Selling digital products?', 'laghavi-performance-insight-for-woocommerce' ),
					/* translators: %d: products */
					'text'    => sprintf( _n( 'You have %d downloadable or virtual product. Rinnovo can issue license keys with activation limits, and sell updates or access as a yearly or monthly subscription.', 'You have %d downloadable or virtual products. Rinnovo can issue license keys with activation limits, and sell updates or access as a yearly or monthly subscription.', $sig['digital'], 'laghavi-performance-insight-for-woocommerce' ), $sig['digital'] ),
					'buttons' => self::free_buttons( 'rinnovo-subscriptions-licenses', 'Rinnovo Subscriptions & Licenses', 'https://laghavi.site/rinnovo-subscriptions-licenses/' ),
				);
			}
		}
		if ( in_array( $where, array( 'dashboard', 'journeys' ), true ) && empty( $fam['sarala'] ) && $sig['abandoned'] >= 2 ) {
			$out[] = array(
				'key'     => 'sarala_checkout',
				'icon'    => 'dashicons-admin-users',
				'title'   => __( 'Make signing in at checkout one click', 'laghavi-performance-insight-for-woocommerce' ),
				/* translators: %d: abandoned carts */
				'text'    => sprintf( __( '%d carts were abandoned in the last 30 days. Letting shoppers sign in or create an account with Google, Apple or Facebook at checkout removes a common reason to leave — and brings them straight back to their cart. It also adds a custom login address and blocks password-guessing bots.', 'laghavi-performance-insight-for-woocommerce' ), $sig['abandoned'] ),
				'buttons' => self::free_buttons( 'sarala-login-page', 'Sarala Login Page', 'https://laghavi.site/sarala-login-page/' ),
			);
		}
		if ( 'rinnovo' === $where && ! empty( $fam['rinnovo'] ) && empty( $fam['rinnovo_pro'] ) && ( $sig['countries'] >= 5 || in_array( $sig['base'], array( 'AE', 'SA', 'BH', 'OM', 'QA', 'KW', 'GB' ), true ) || self::in_eu( $sig['base'] ) ) ) {
			$out[] = array(
				'key'     => 'rinnovo_pro',
				'icon'    => 'dashicons-money-alt',
				'title'   => __( 'Rinnovo Pro for international and VAT-registered stores', 'laghavi-performance-insight-for-woocommerce' ),
				/* translators: %d: countries */
				'text'    => sprintf( __( 'Visitors came from %d countries in the last 30 days. Rinnovo Pro adds a currency switcher with per-country currencies, tax invoices with your TRN or VAT number, VAT/GST reverse charge, card-free trials and "Ask for price" products.', 'laghavi-performance-insight-for-woocommerce' ), $sig['countries'] ),
				'buttons' => array( array( __( 'See Rinnovo Pro', 'laghavi-performance-insight-for-woocommerce' ), self::utm( 'https://laghavi.site/product/rinnovo-subscriptions-licenses-pro/', 'rinnovo_pro' ), true ) ),
			);
		}
		if ( 'rinnovo' === $where && ! empty( $fam['rinnovo'] ) ) {
			$missing = array();
			foreach ( array( 'boxes', 'membership', 'media', 'contracts', 'sessions', 'bundles' ) as $k ) {
				if ( empty( $fam[ $k ] ) ) {
					$missing[] = $k;
				}
			}
			if ( $missing ) {
				$out[] = array(
					'key'     => 'rinnovo_ext',
					'icon'    => 'dashicons-screenoptions',
					'title'   => __( 'More Rinnovo extensions', 'laghavi-performance-insight-for-woocommerce' ),
					'text'    => __( 'Each one adds its own tab here as soon as it is installed.', 'laghavi-performance-insight-for-woocommerce' ),
					'list'    => array_map(
						function ( $k ) {
							$p = LGPI_Family::plugins()[ $k ];
							return array( $p[0], self::utm( $p[5], 'rinnovo_ext' ), self::ext_text( $k ) );
						},
						$missing
					),
					'buttons' => array(),
				);
			}
		}
		if ( 'logins' === $where && ! empty( $fam['sarala'] ) && empty( $fam['sarala_pro'] ) ) {
			$to   = current_time( 'Y-m-d' );
			$d    = LGPI_Family::data( 'logins', gmdate( 'Y-m-d', strtotime( $to . ' -29 days' ) ), $to );
			$hits = $d ? (int) ( $d['totals']['failed'] ?? 0 ) + (int) ( $d['totals']['lockouts'] ?? 0 ) : 0;
			if ( $hits >= 10 ) {
				$out[] = array(
					'key'     => 'sarala_pro',
					'icon'    => 'dashicons-shield',
					'title'   => __( 'Stop login bots before they try a password', 'laghavi-performance-insight-for-woocommerce' ),
					/* translators: %d: failed logins and lockouts */
					'text'    => sprintf( __( '%d failed logins and lockouts in the last 30 days. Sarala Pro adds Cloudflare Turnstile, reCAPTCHA or hCaptcha, an activity log with CSV export, per-role redirects and branded login pages.', 'laghavi-performance-insight-for-woocommerce' ), $hits ),
					'buttons' => array( array( __( 'See Sarala Pro', 'laghavi-performance-insight-for-woocommerce' ), self::utm( 'https://laghavi.site/product/sarala-login-page-pro/', 'sarala_pro' ), true ) ),
				);
			}
		}
		$hidden = (array) get_user_meta( get_current_user_id(), 'lgpi_rec_hidden', true );
		$out    = array_values( array_filter( $out, fn( $r ) => empty( $hidden[ $r['key'] ] ) || $hidden[ $r['key'] ] < time() - self::HIDE_DAYS * DAY_IN_SECONDS ) );
		if ( 'dashboard' === $where ) {
			$out = array_slice( $out, 0, 1 );
		}
		// Always: "More from Laghavi Digital" on the Dashboard and Settings, for the plugins not installed yet.
		if ( in_array( $where, array( 'dashboard', 'settings' ), true ) && ( empty( $hidden['family'] ) || $hidden['family'] < time() - self::HIDE_DAYS * DAY_IN_SECONDS ) ) {
			$shown = implode( ' ', array_column( $out, 'key' ) );
			$items = array();
			if ( empty( $fam['rinnovo'] ) && false === strpos( $shown, 'rinnovo_' ) ) {
				$items[] = array( 'Rinnovo Subscriptions & Licenses', __( 'sell products and services as subscriptions with automatic renewals, free trials and failed-payment retries, and issue license keys for software and digital products.', 'laghavi-performance-insight-for-woocommerce' ), 'rinnovo-subscriptions-licenses', 'https://laghavi.site/rinnovo-subscriptions-licenses/' );
			}
			if ( empty( $fam['sarala'] ) && false === strpos( $shown, 'sarala_' ) ) {
				$items[] = array( 'Sarala Login Page', __( 'one-click sign-in and sign-up with Google, Apple, Facebook and more on the login, My Account and checkout pages, plus a custom login address and protection against password-guessing bots.', 'laghavi-performance-insight-for-woocommerce' ), 'sarala-login-page', 'https://laghavi.site/sarala-login-page/' );
			}
			if ( $items ) {
				$out[] = array(
					'key'     => 'family',
					'icon'    => 'dashicons-plugins-checked',
					'title'   => __( 'More free plugins from Laghavi Digital', 'laghavi-performance-insight-for-woocommerce' ),
					'text'    => __( 'From the makers of Performance Insight — free on WordPress.org, and their numbers appear right here once installed.', 'laghavi-performance-insight-for-woocommerce' ),
					'plugins' => $items,
					'buttons' => array(),
					'tag'     => false,
				);
			}
		}
		return $out;
	}

	/**
	 * Print the recommendations of a screen.
	 *
	 * @param string $where Screen.
	 */
	public static function render( $where ) {
		foreach ( self::for_screen( $where ) as $r ) {
			$dismiss = wp_nonce_url( add_query_arg( array( 'action' => 'lgpi_rec_dismiss', 'key' => $r['key'] ), admin_url( 'admin-post.php' ) ), 'lgpi_rec_dismiss' );
			echo '<div class="lgpi-card lgpi-rec"><span class="dashicons ' . esc_attr( $r['icon'] ) . '"></span><div><h3>' . esc_html( $r['title'] ) . ( isset( $r['tag'] ) && false === $r['tag'] ? '' : ' <span class="lgpi-rec-tag">' . esc_html__( 'Recommended for your store', 'laghavi-performance-insight-for-woocommerce' ) . '</span>' ) . '</h3><p>' . esc_html( $r['text'] ) . '</p>';
			foreach ( (array) ( $r['plugins'] ?? array() ) as $pl ) {
				echo '<div class="lgpi-rec-plugin"><p><strong>' . esc_html( $pl[0] ) . '</strong> — ' . esc_html( $pl[1] ) . '</p><p>';
				foreach ( self::free_buttons( $pl[2], $pl[0], $pl[3] ) as $b ) {
					echo '<a class="button' . ( $b[2] ? ' button-primary' : '' ) . '" href="' . esc_url( $b[1] ) . '"' . ( 0 === strpos( $b[1], admin_url() ) ? '' : ' target="_blank" rel="noopener"' ) . '>' . esc_html( $b[0] ) . '</a> ';
				}
				echo '</p></div>';
			}
			if ( ! empty( $r['list'] ) ) {
				echo '<ul class="lgpi-rec-list">';
				foreach ( $r['list'] as $it ) {
					echo '<li><a href="' . esc_url( $it[1] ) . '" target="_blank" rel="noopener">' . esc_html( $it[0] ) . '</a> — ' . esc_html( $it[2] ) . '</li>';
				}
				echo '</ul>';
			}
			echo '<p>';
			foreach ( $r['buttons'] as $b ) {
				echo '<a class="button' . ( $b[2] ? ' button-primary' : '' ) . '" href="' . esc_url( $b[1] ) . '"' . ( 0 === strpos( $b[1], admin_url() ) ? '' : ' target="_blank" rel="noopener"' ) . '>' . esc_html( $b[0] ) . '</a> ';
			}
			echo '<a class="lgpi-rec-dismiss" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Not now', 'laghavi-performance-insight-for-woocommerce' ) . '</a></p></div></div>';
		}
	}

	/**
	 * Buttons for a free plugin: install from WordPress.org (when allowed) and learn more.
	 *
	 * @param string $slug WordPress.org slug.
	 * @param string $name Plugin name.
	 * @param string $page Product page.
	 * @return array
	 */
	private static function free_buttons( $slug, $name, $page ) {
		$b = array();
		if ( current_user_can( 'install_plugins' ) ) {
			/* translators: %s: plugin name */
			$b[] = array( sprintf( __( 'Install %s (free)', 'laghavi-performance-insight-for-woocommerce' ), $name ), add_query_arg( array( 's' => $slug, 'tab' => 'search', 'type' => 'term' ), self_admin_url( 'plugin-install.php' ) ), true );
		} else {
			$b[] = array( __( 'View on WordPress.org', 'laghavi-performance-insight-for-woocommerce' ), 'https://wordpress.org/plugins/' . $slug . '/', true );
		}
		$b[] = array( __( 'Learn more', 'laghavi-performance-insight-for-woocommerce' ), self::utm( $page, $slug ), false );
		return $b;
	}

	/**
	 * Laghavi link with the source of the click.
	 *
	 * @param string $url   URL.
	 * @param string $where Campaign.
	 * @return string
	 */
	private static function utm( $url, $where ) {
		return add_query_arg(
			array(
				'utm_source'   => 'wp-plugin',
				'utm_medium'   => 'performance-insight',
				'utm_campaign' => sanitize_key( $where ),
			),
			$url
		);
	}

	/**
	 * One line about an extension.
	 *
	 * @param string $k Extension.
	 * @return string
	 */
	private static function ext_text( $k ) {
		$t = array(
			'boxes'      => __( 'subscription boxes, skips and swaps, prepaid and gift plans', 'laghavi-performance-insight-for-woocommerce' ),
			'membership' => __( 'members-only content, courses and download credits', 'laghavi-performance-insight-for-woocommerce' ),
			'media'      => __( 'streaming video and audio archives with quotas and device limits', 'laghavi-performance-insight-for-woocommerce' ),
			'contracts'  => __( 'service retainers with monthly allowances and minimum terms', 'laghavi-performance-insight-for-woocommerce' ),
			'sessions'   => __( 'class and session memberships with credits and waitlists', 'laghavi-performance-insight-for-woocommerce' ),
			'bundles'    => __( 'build-your-own bundles and plan switching', 'laghavi-performance-insight-for-woocommerce' ),
		);
		return $t[ $k ] ?? '';
	}

	/**
	 * EU country?
	 *
	 * @param string $cc Country code.
	 * @return bool
	 */
	private static function in_eu( $cc ) {
		return in_array( $cc, array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE' ), true );
	}

	/**
	 * "Not now": hide one recommendation for a while (this user only).
	 */
	public static function dismiss() {
		check_admin_referer( 'lgpi_rec_dismiss' );
		$key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
		if ( '' !== $key && is_user_logged_in() ) {
			$hidden         = (array) get_user_meta( get_current_user_id(), 'lgpi_rec_hidden', true );
			$hidden[ $key ] = time();
			update_user_meta( get_current_user_id(), 'lgpi_rec_hidden', array_filter( $hidden ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=lgpi' ) );
		exit;
	}
}
