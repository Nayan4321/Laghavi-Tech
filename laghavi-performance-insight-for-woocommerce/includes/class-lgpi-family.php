<?php
/**
 * Insights from the Rinnovo plugin family (subscriptions, licenses and the six Rinnovo extensions), Vacanam Language
 * Switcher and Sarala Login Page — shown only for the plugins that are active, read-only.
 *
 * Data source (provided by those plugins): a function that returns arrays —
 *   rinnovo_insights( $section, $args ), vacanam_insights( 'languages', $args ), sarala_insights( 'logins', $args )
 * or the filter 'lgpi_family_insights'. $args = from (Y-m-d), to (Y-m-d), currency (store currency), timezone.
 * Each section returns: totals (key => number), daily (list of [date, key => number]), tables (key => rows),
 * by_currency (code => [key => number]) and actions (list). The format is documented in docs/rinnovo-insights.md.
 * Until a plugin provides its data, its tab says it is installed and waiting for data — never an error.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rinnovo, Vacanam and Sarala data.
 */
class LGPI_Family {

	/** Data is cached for this many seconds. */
	const CACHE = 900;

	/**
	 * The plugins: key => [name, plugin folders, provider function, sections, tab, wordpress.org or product URL].
	 *
	 * @return array
	 */
	public static function plugins() {
		$list = array(
			'rinnovo'     => array( 'Rinnovo Subscriptions & Licenses', array( 'rinnovo-subscriptions-licenses' ), 'rinnovo_insights', array( 'subscriptions', 'licenses' ), 'rinnovo', 'https://wordpress.org/plugins/rinnovo-subscriptions-licenses/' ),
			'rinnovo_pro' => array( 'Rinnovo Subscriptions & Licenses Pro', array( 'rinnovo-subscriptions-licenses-pro' ), 'rinnovo_insights', array( 'quotes' ), 'rinnovo', 'https://laghavi.site/product/rinnovo-subscriptions-licenses-pro/' ),
			'boxes'       => array( 'Physical Subscription Boxes & Goods Pro', array( 'rinnovo-physical-subscription-boxes-pro' ), 'rinnovo_insights', array( 'boxes' ), 'rinnovo', 'https://laghavi.site/product/rinnovo-physical-subscription-boxes-pro/' ),
			'membership'  => array( 'Membership Content Pro', array( 'rinnovo-membership-content-pro' ), 'rinnovo_insights', array( 'membership' ), 'rinnovo', 'https://laghavi.site/product/rinnovo-membership-content-pro/' ),
			'media'       => array( 'Media Archives Pro', array( 'rinnovo-media-archives-pro' ), 'rinnovo_insights', array( 'media' ), 'rinnovo', 'https://laghavi.site/product/rinnovo-media-archives-pro/' ),
			'contracts'   => array( 'Service Contracts Pro', array( 'rinnovo-service-contracts-pro' ), 'rinnovo_insights', array( 'contracts' ), 'rinnovo', 'https://laghavi.site/product/rinnovo-service-contracts-pro/' ),
			'sessions'    => array( 'Session Membership Pro', array( 'rinnovo-session-membership-pro' ), 'rinnovo_insights', array( 'sessions' ), 'rinnovo', 'https://laghavi.site/product/rinnovo-session-membership-pro/' ),
			'bundles'     => array( 'Bundles & Variable Subscriptions Pro', array( 'rinnovo-bundles-variable-subscriptions-pro' ), 'rinnovo_insights', array( 'bundles' ), 'rinnovo', 'https://laghavi.site/product/rinnovo-bundles-variable-subscriptions-pro/' ),
			'vacanam'     => array( 'Vacanam Language Switcher', array( 'vacanam-language-switcher', 'vacanam-language-switcher-pro' ), 'vacanam_insights', array( 'languages' ), 'languages', 'https://laghavi.site/' ),
			'sarala'      => array( 'Sarala Login Page', array( 'sarala-login-page' ), 'sarala_insights', array( 'logins' ), 'logins', 'https://wordpress.org/plugins/sarala-login-page/' ),
			'sarala_pro'  => array( 'Sarala Login Page Pro', array( 'sarala-login-page-pro' ), 'sarala_insights', array(), 'logins', 'https://laghavi.site/product/sarala-login-page-pro/' ),
		);
		/**
		 * Change the plugin list, e.g. a renamed plugin folder.
		 *
		 * @param array $list Plugins.
		 */
		return (array) apply_filters( 'lgpi_family_plugins', $list );
	}

	/**
	 * Which of the plugins are active (cached per request). Detected by plugin folder; a plugin can also announce
	 * itself with the filter 'lgpi_family_active' (key => true).
	 *
	 * @return array key => true
	 */
	public static function active() {
		static $active = null;
		if ( null !== $active ) {
			return $active;
		}
		$on = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$on = array_merge( $on, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$folders = array();
		foreach ( $on as $file ) {
			$folders[ strtok( (string) $file, '/' ) ] = true;
		}
		$active = array();
		foreach ( self::plugins() as $key => $p ) {
			foreach ( (array) $p[1] as $folder ) {
				if ( isset( $folders[ $folder ] ) ) {
					$active[ $key ] = true;
				}
			}
		}
		$active = array_filter( (array) apply_filters( 'lgpi_family_active', $active ) );
		return $active;
	}

	/**
	 * Is a plugin (or any plugin of a tab) active?
	 *
	 * @param string $key Plugin key or tab (rinnovo, languages, logins).
	 * @return bool
	 */
	public static function has( $key ) {
		$active = self::active();
		if ( isset( $active[ $key ] ) ) {
			return true;
		}
		foreach ( self::plugins() as $k => $p ) {
			if ( $p[4] === $key && isset( $active[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Shop managers and administrators only.
	 *
	 * @return bool
	 */
	public static function allowed() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Data of one section for a period, cached. Never throws; returns null when the plugin gives no data yet.
	 *
	 * @param string $section Section.
	 * @param string $from    Y-m-d.
	 * @param string $to      Y-m-d.
	 * @param bool   $fresh   Skip the cache.
	 * @return array|null [totals, daily, tables, by_currency, actions]
	 */
	public static function data( $section, $from, $to, $fresh = false ) {
		$fn = null;
		foreach ( self::plugins() as $key => $p ) {
			if ( in_array( $section, (array) $p[3], true ) ) {
				$fn = $p[2];
				break;
			}
		}
		$ck = 'lgpi_fam_' . md5( $section . '|' . $from . '|' . $to );
		if ( ! $fresh ) {
			$c = get_transient( $ck );
			if ( is_array( $c ) ) {
				return $c['d'];
			}
		}
		$args = array(
			'from'     => $from,
			'to'       => $to,
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'timezone' => wp_timezone_string(),
		);
		$raw  = null;
		try {
			if ( $fn && function_exists( $fn ) ) {
				$raw = call_user_func( $fn, $section, $args );
			}
			/**
			 * Provide (or change) the data of a section.
			 *
			 * @param array|null $raw     Data, or null.
			 * @param string     $section Section.
			 * @param array      $args    from, to, currency, timezone.
			 */
			$raw = apply_filters( 'lgpi_family_insights', $raw, $section, $args );
		} catch ( \Throwable $e ) {
			$raw = null;
		}
		$d = is_array( $raw ) ? self::clean( $raw ) : null;
		set_transient( $ck, array( 'd' => $d ), $d ? self::CACHE : 5 * MINUTE_IN_SECONDS );
		return $d;
	}

	/**
	 * Forget cached data ("Refresh").
	 */
	public static function flush() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_lgpi_fam_' ) . '%', $wpdb->esc_like( '_transient_timeout_lgpi_fam_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Keep only well-formed values: numbers as numbers, text as short text, at most 500 rows per table.
	 *
	 * @param array $raw Provider data.
	 * @return array
	 */
	private static function clean( $raw ) {
		$scalar = function ( $v ) {
			if ( is_bool( $v ) ) {
				return $v ? 1 : 0;
			}
			if ( is_int( $v ) || is_float( $v ) ) {
				return is_finite( (float) $v ) ? $v : 0;
			}
			if ( is_numeric( $v ) ) {
				return $v + 0;
			}
			return is_scalar( $v ) ? mb_substr( wp_strip_all_tags( (string) $v ), 0, 300 ) : '';
		};
		$out  = array(
			'totals'      => array(),
			'daily'       => array(),
			'tables'      => array(),
			'by_currency' => array(),
			'actions'     => array(),
		);
		foreach ( (array) ( $raw['totals'] ?? array() ) as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$out['totals'][ sanitize_key( $k ) ] = $scalar( $v );
			}
		}
		foreach ( array_slice( (array) ( $raw['daily'] ?? array() ), 0, 1000 ) as $row ) {
			$date = isset( $row['date'] ) ? substr( (string) $row['date'], 0, 10 ) : '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}
			$r = array( 'date' => $date );
			foreach ( (array) $row as $k => $v ) {
				if ( 'date' !== $k && is_numeric( $v ) ) {
					$r[ sanitize_key( $k ) ] = $v + 0;
				}
			}
			$out['daily'][] = $r;
		}
		foreach ( (array) ( $raw['tables'] ?? array() ) as $t => $rows ) {
			$t = sanitize_key( $t );
			foreach ( array_slice( (array) $rows, 0, 500 ) as $row ) {
				$r = array();
				foreach ( (array) $row as $k => $v ) {
					$r[ sanitize_key( $k ) ] = 'url' === $k ? self::safe_url( $v ) : $scalar( $v );
				}
				$out['tables'][ $t ][] = $r;
			}
		}
		foreach ( (array) ( $raw['by_currency'] ?? array() ) as $code => $vals ) {
			$code = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $code ), 0, 3 ) );
			if ( 3 === strlen( $code ) ) {
				foreach ( (array) $vals as $k => $v ) {
					if ( is_numeric( $v ) ) {
						$out['by_currency'][ $code ][ sanitize_key( $k ) ] = $v + 0;
					}
				}
			}
		}
		foreach ( array_slice( (array) ( $raw['actions'] ?? array() ), 0, 50 ) as $a ) {
			if ( ! empty( $a['title'] ) ) {
				$out['actions'][] = array(
					'title' => mb_substr( wp_strip_all_tags( (string) $a['title'] ), 0, 200 ),
					'count' => isset( $a['count'] ) ? (int) $a['count'] : null,
					'url'   => self::safe_url( $a['url'] ?? '' ),
					'level' => in_array( $a['level'] ?? '', array( 'bad', 'warn', 'info' ), true ) ? $a['level'] : 'warn',
				);
			}
		}
		return $out;
	}

	/**
	 * Links from the data must point to this site.
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	private static function safe_url( $url ) {
		$url  = esc_url_raw( (string) $url );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) === $host ? $url : '';
	}

	/**
	 * Actions worked out from the data (plus the ones the plugin sends itself).
	 *
	 * @param string $section Section.
	 * @param array  $d       Data.
	 * @return array[] [title, count, url, level]
	 */
	public static function actions( $section, $d ) {
		$t   = $d['totals'];
		$tb  = $d['tables'];
		$out = $d['actions'];
		$n   = fn( $k ) => (int) ( $t[ $k ] ?? 0 );
		switch ( $section ) {
			case 'subscriptions':
				if ( $n( 'failed_open' ) ) {
					/* translators: %d: renewals */
					$out[] = self::act( sprintf( _n( '%d failed renewal to follow up', '%d failed renewals to follow up', $n( 'failed_open' ), 'laghavi-performance-insight-for-woocommerce' ), $n( 'failed_open' ) ), $n( 'failed_open' ), 'bad' );
				}
				if ( $n( 'trials_ending_7d' ) ) {
					/* translators: %d: trials */
					$out[] = self::act( sprintf( _n( '%d trial ends this week', '%d trials end this week', $n( 'trials_ending_7d' ), 'laghavi-performance-insight-for-woocommerce' ), $n( 'trials_ending_7d' ) ), $n( 'trials_ending_7d' ), 'warn' );
				}
				break;
			case 'contracts':
				$near = array_filter( (array) ( $tb['allowances'] ?? array() ), fn( $r ) => (float) ( $r['included'] ?? 0 ) > 0 && (float) ( $r['used'] ?? 0 ) / (float) $r['included'] >= 0.9 );
				if ( $near ) {
					/* translators: %d: contracts */
					$out[] = self::act( sprintf( _n( '%d contract is at 90%% or more of its quota', '%d contracts are at 90%% or more of their quota', count( $near ), 'laghavi-performance-insight-for-woocommerce' ), count( $near ) ), count( $near ), 'warn' );
				}
				break;
			case 'sessions':
				$low = array_filter( (array) ( $tb['classes'] ?? array() ), fn( $r ) => self::util( $r ) !== null && self::util( $r ) < 40 );
				if ( $low ) {
					/* translators: %d: classes */
					$out[] = self::act( sprintf( _n( '%d class is under 40%% full', '%d classes are under 40%% full', count( $low ), 'laghavi-performance-insight-for-woocommerce' ), count( $low ) ), count( $low ), 'warn' );
				}
				break;
			case 'boxes':
				$short = self::box_shortages( $d );
				if ( $short ) {
					/* translators: %d: products */
					$out[] = self::act( sprintf( _n( '%d box item may run out before the next shipping day', '%d box items may run out before the next shipping day', count( $short ), 'laghavi-performance-insight-for-woocommerce' ), count( $short ) ), count( $short ), 'bad' );
				}
				break;
			case 'languages':
				$weak = self::weak_languages( $d );
				if ( $weak ) {
					/* translators: %s: languages */
					$out[] = self::act( sprintf( __( 'Traffic but low conversion in: %s — check the translations of key pages and checkout', 'laghavi-performance-insight-for-woocommerce' ), implode( ', ', array_column( $weak, 'language' ) ) ), count( $weak ), 'warn' );
				}
				break;
		}
		return $out;
	}

	/**
	 * One action.
	 *
	 * @param string $title Title.
	 * @param int    $count Count.
	 * @param string $level bad | warn | info.
	 * @return array
	 */
	private static function act( $title, $count, $level ) {
		return array(
			'title' => $title,
			'count' => $count,
			'url'   => '',
			'level' => $level,
		);
	}

	/**
	 * Fill rate of a class in percent (given, or booked ÷ capacity).
	 *
	 * @param array $r Row.
	 * @return float|null
	 */
	public static function util( $r ) {
		if ( isset( $r['utilisation_pct'] ) && is_numeric( $r['utilisation_pct'] ) ) {
			return (float) $r['utilisation_pct'];
		}
		return ! empty( $r['capacity'] ) ? (float) ( $r['booked'] ?? 0 ) / (float) $r['capacity'] * 100 : null;
	}

	/**
	 * Box items whose stock in WooCommerce is lower than what the next shipment needs.
	 *
	 * @param array $d Boxes data.
	 * @return array[] rows with name, need, stock
	 */
	public static function box_shortages( $d ) {
		$out = array();
		foreach ( (array) ( $d['tables']['expected_items'] ?? array() ) as $r ) {
			$need = (int) ( $r['next_shipment_qty'] ?? $r['qty_next_30d'] ?? 0 );
			$pid  = (int) ( $r['product_id'] ?? 0 );
			if ( ! $need || ! $pid || ! function_exists( 'wc_get_product' ) ) {
				continue;
			}
			$p = wc_get_product( $pid );
			if ( ! $p || ! $p->managing_stock() ) {
				continue;
			}
			$stock = (int) $p->get_stock_quantity();
			if ( $stock < $need ) {
				$out[] = array(
					'name'  => (string) ( $r['name'] ?? $p->get_name() ),
					'need'  => $need,
					'stock' => $stock,
					'edit'  => (string) get_edit_post_link( $p->get_parent_id() ? $p->get_parent_id() : $pid, 'raw' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Languages with real traffic but a conversion rate under half the store average.
	 *
	 * @param array $d Languages data.
	 * @return array[]
	 */
	public static function weak_languages( $d ) {
		$rows   = (array) ( $d['tables']['languages'] ?? array() );
		$views  = array_sum( array_map( fn( $r ) => (float) ( $r['views'] ?? 0 ), $rows ) );
		$orders = array_sum( array_map( fn( $r ) => (float) ( $r['orders'] ?? 0 ), $rows ) );
		if ( $views < 200 || ! $orders ) {
			return array();
		}
		$avg = $orders / $views;
		return array_values( array_filter( $rows, fn( $r ) => (float) ( $r['views'] ?? 0 ) >= 100 && (float) ( $r['orders'] ?? 0 ) / max( 1, (float) $r['views'] ) < $avg / 2 ) );
	}

	/**
	 * All actions of the active plugins for the dashboard strip (last 30 days, cached with the data).
	 *
	 * @return array[]
	 */
	public static function all_actions() {
		if ( ! self::allowed() ) {
			return array();
		}
		$tz   = wp_timezone();
		$to   = current_time( 'Y-m-d' );
		$from = ( new DateTime( '-29 days', $tz ) )->format( 'Y-m-d' );
		$out  = array();
		foreach ( self::sections() as $section => $tab ) {
			$d = self::data( $section, $from, $to );
			if ( $d ) {
				foreach ( self::actions( $section, $d ) as $a ) {
					$a['tab'] = $tab;
					$out[]    = $a;
				}
			}
		}
		return $out;
	}

	/**
	 * Sections of the active plugins: section => tab.
	 *
	 * @return array
	 */
	public static function sections() {
		$active = self::active();
		$out    = array();
		foreach ( self::plugins() as $key => $p ) {
			if ( isset( $active[ $key ] ) ) {
				foreach ( (array) $p[3] as $s ) {
					$out[ $s ] = $p[4];
				}
			}
		}
		return $out;
	}
}
