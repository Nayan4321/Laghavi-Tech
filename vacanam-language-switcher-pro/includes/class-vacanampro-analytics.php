<?php
/**
 * Language analytics: page views per language, language changes (and from where), automatic
 * redirects and the ones that found no translation, visitors who left right after changing
 * language, and WooCommerce orders and revenue per language. Daily counters only: no cookies, no
 * IP addresses, nothing personal.
 *
 * @package VacanamLanguageSwitcherPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Analytics.
 */
class VACANAMPRO_Analytics {

	const PAGE       = 'vacanam-pro-analytics';
	const DB_VERSION = '1';

	/**
	 * Counters a visitor's browser may send.
	 *
	 * @var string[]
	 */
	private static $events = array( 'view', 'switch', 'abandon', 'suggest_accepted', 'suggest_dismissed' );

	/**
	 * Hook everything up (licensed).
	 */
	public static function init() {
		self::install();
		add_action( 'rest_api_init', array( __CLASS__, 'route' ) );
		add_action( 'vacanampro_geo_redirect', array( __CLASS__, 'geo_redirect' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
		add_filter( 'vacanam_admin_tabs', array( __CLASS__, 'tab' ), 27 );
		add_action( 'vacanampro_analytics_cleanup', array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( 'vacanampro_analytics_cleanup' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'vacanampro_analytics_cleanup' );
		}
	}

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vacanampro_stats';
	}

	/**
	 * Create the table when needed.
	 */
	public static function install() {
		if ( get_option( 'vacanampro_stats_db' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				day date NOT NULL,
				lang varchar(20) NOT NULL,
				metric varchar(40) NOT NULL,
				n bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day,lang,metric)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'vacanampro_stats_db', self::DB_VERSION, false );
	}

	/**
	 * Add one to a counter.
	 *
	 * @param string $lang   Language.
	 * @param string $metric Counter.
	 */
	public static function count( $lang, $metric ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A counter in the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (day, lang, metric, n) VALUES (%s, %s, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1', self::table(), current_time( 'Y-m-d' ), substr( $lang, 0, 20 ), substr( $metric, 0, 40 ) ) );
	}

	/**
	 * POST /vacanam/v1/track.
	 */
	public static function route() {
		register_rest_route(
			VACANAMPRO_API::NS,
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'track' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Count an event from a visitor's browser.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function track( $request ) {
		$data  = json_decode( (string) $request->get_body(), true );
		$data  = is_array( $data ) ? $data : array();
		$event = isset( $data['e'] ) ? sanitize_key( (string) $data['e'] ) : '';
		$lang  = isset( $data['lang'] ) ? sanitize_key( (string) $data['lang'] ) : '';
		if ( ! VACANAMPRO_Settings::on( 'analytics' ) || ! in_array( $event, self::$events, true ) || ! Vacanam_Languages::exists( $lang ) || Vacanam_Frontend::is_bot() ) {
			return new WP_REST_Response( null, 204 );
		}
		if ( 'switch' === $event ) {
			$to  = isset( $data['to'] ) ? sanitize_key( (string) $data['to'] ) : '';
			$via = isset( $data['via'] ) ? sanitize_key( (string) $data['via'] ) : 'switcher';
			if ( ! Vacanam_Languages::exists( $to ) ) {
				return new WP_REST_Response( null, 204 );
			}
			// Counted on the language chosen.
			self::count( $to, 'chosen' );
			self::count( $to, 'chosen_' . ( in_array( $via, array( 'switcher', 'select', 'chooser', 'sheet' ), true ) ? $via : 'switcher' ) );
			self::count( $lang, 'left' );
		} elseif ( 'suggest_accepted' === $event || 'suggest_dismissed' === $event ) {
			$to = isset( $data['to'] ) ? sanitize_key( (string) $data['to'] ) : '';
			if ( Vacanam_Languages::exists( $to ) ) {
				self::count( $to, $event );
			}
		} else {
			self::count( $lang, $event );
		}
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Count automatic redirects.
	 *
	 * @param string $lang    Language.
	 * @param string $country Country.
	 * @param bool   $failed  No translation of the page.
	 */
	public static function geo_redirect( $lang, $country, $failed ) {
		unset( $country );
		if ( VACANAMPRO_Settings::on( 'analytics' ) ) {
			self::count( $lang, $failed ? 'redirect_failed' : 'redirect' );
		}
	}

	/**
	 * Keep 400 days.
	 */
	public static function cleanup() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::table(), gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Totals per language and counter since a day.
	 *
	 * @param string $since Day (Y-m-d).
	 * @return array lang => metric => n.
	 */
	public static function totals( $since ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT lang, metric, SUM(n) AS n FROM %i WHERE day >= %s GROUP BY lang, metric', self::table(), $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->lang ][ $row->metric ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Views per day and language (for the chart).
	 *
	 * @param string $since Day.
	 * @return array day => lang => n.
	 */
	public static function daily( $since ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, lang, n FROM %i WHERE day >= %s AND metric = 'view' ORDER BY day", self::table(), $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->day ][ $row->lang ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * WooCommerce orders and revenue per language since a day (in the store currency).
	 *
	 * @param string $since Day.
	 * @return array lang => array( orders, revenue ).
	 */
	public static function orders( $since ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$out   = array();
		$page  = 1;
		$base  = (string) get_option( 'woocommerce_currency' );
		$rinno = class_exists( 'RINNOVO_Currency' );
		do {
			$orders = wc_get_orders(
				array(
					'status'       => array( 'wc-processing', 'wc-completed' ),
					'date_created' => '>=' . $since,
					'type'         => 'shop_order',
					'limit'        => 200,
					'paged'        => $page,
				)
			);
			foreach ( $orders as $order ) {
				$lang = (string) $order->get_meta( VACANAMPRO_Woo::ORDER_META );
				$lang = '' !== $lang ? $lang : Vacanam_Languages::default_slug();
				if ( ! isset( $out[ $lang ] ) ) {
					$out[ $lang ] = array( 0, 0.0 );
				}
				$total = (float) $order->get_total();
				if ( $order->get_currency() !== $base ) {
					if ( $rinno ) {
						$total = (float) RINNOVO_Currency::to_base( $total, $order->get_currency() );
					} else {
						$row   = VACANAMPRO_Currency::for_language( $lang );
						$total = $row['rate'] > 0 && $row['code'] === $order->get_currency() ? $total / $row['rate'] : $total;
					}
				}
				++$out[ $lang ][0];
				$out[ $lang ][1] += $total;
			}
			++$page;
		} while ( count( $orders ) === 200 && $page < 50 );
		return $out;
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( 'vacanam', __( 'Language analytics', 'vacanam-language-switcher-pro' ), __( 'Analytics', 'vacanam-language-switcher-pro' ), VACANAMPRO_Settings::capability(), self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs[ self::PAGE ] = __( 'Analytics', 'vacanam-language-switcher-pro' );
		return $tabs;
	}

	/**
	 * Percentage.
	 *
	 * @param int $part  Part.
	 * @param int $whole Whole.
	 * @return string
	 */
	private static function pct( $part, $whole ) {
		return $whole > 0 ? number_format_i18n( 100 * $part / $whole, 1 ) . '%' : '–';
	}

	/**
	 * The screen.
	 */
	public static function page() {
		if ( ! current_user_can( VACANAMPRO_Settings::capability() ) ) {
			return;
		}
		Vacanam_Admin::header( __( 'Language analytics', 'vacanam-language-switcher-pro' ), self::PAGE );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A display filter.
		$days  = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		$days  = in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) - ( $days - 1 ) * DAY_IN_SECONDS );
		$data  = self::totals( $since );
		$sales = self::orders( $since );
		$langs = Vacanam_Languages::all();

		echo '<div class="vacanam-card"><form method="get" class="vacanam-filter"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><select name="days" onchange="this.form.submit()">';
		foreach ( array( 7, 30, 90, 365 ) as $option ) {
			/* translators: %d: number of days */
			echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( $days, $option, false ) . '>' . esc_html( sprintf( _n( 'Last %d day', 'Last %d days', $option, 'vacanam-language-switcher-pro' ), $option ) ) . '</option>';
		}
		echo '</select></form>';
		if ( ! VACANAMPRO_Settings::on( 'analytics' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . wp_kses_post( sprintf( /* translators: %s: link */ __( 'Counting is off. Switch it on in %s.', 'vacanam-language-switcher-pro' ), '<a href="' . esc_url( admin_url( 'admin.php?page=' . VACANAMPRO_Settings::PAGE ) ) . '">' . esc_html__( 'Languages > Pro > Switchers', 'vacanam-language-switcher-pro' ) . '</a>' ) ) . '</p></div>';
		}

		// Summary cards.
		$views   = 0;
		$chosen  = 0;
		$redir   = 0;
		$failed  = 0;
		$abandon = 0;
		foreach ( $data as $row ) {
			$views   += isset( $row['view'] ) ? $row['view'] : 0;
			$chosen  += isset( $row['chosen'] ) ? $row['chosen'] : 0;
			$redir   += isset( $row['redirect'] ) ? $row['redirect'] : 0;
			$failed  += isset( $row['redirect_failed'] ) ? $row['redirect_failed'] : 0;
			$abandon += isset( $row['abandon'] ) ? $row['abandon'] : 0;
		}
		$cards = array(
			array( __( 'Page views', 'vacanam-language-switcher-pro' ), number_format_i18n( $views ) ),
			array( __( 'Language changes', 'vacanam-language-switcher-pro' ), number_format_i18n( $chosen ) ),
			array( __( 'Country redirects', 'vacanam-language-switcher-pro' ), number_format_i18n( $redir + $failed ) ),
			array( __( 'Redirects without a translation', 'vacanam-language-switcher-pro' ), self::pct( $failed, $redir + $failed ) ),
			array( __( 'Left right after changing', 'vacanam-language-switcher-pro' ), self::pct( $abandon, $chosen ) ),
		);
		echo '<div class="vacanampro-cards">';
		foreach ( $cards as $card ) {
			echo '<div class="vacanampro-stat"><span>' . esc_html( $card[0] ) . '</span><strong>' . esc_html( $card[1] ) . '</strong></div>';
		}
		echo '</div></div>';

		// Per language.
		$money = function_exists( 'wc_price' );
		echo '<div class="vacanam-card"><h2>' . esc_html__( 'Per language', 'vacanam-language-switcher-pro' ) . '</h2><div style="overflow-x:auto"><table class="widefat striped vacanampro-analytics"><thead><tr><th>' . esc_html__( 'Language', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html__( 'Views', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html__( 'Share', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html__( 'Chosen by visitors', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html__( 'Left for another language', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html__( 'Country redirects (no translation)', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html__( 'Suggestion accepted / dismissed', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html__( 'Left right after changing', 'vacanam-language-switcher-pro' ) . '</th>';
		if ( $money ) {
			echo '<th>' . esc_html__( 'Orders', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html__( 'Revenue', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html__( 'Conversion', 'vacanam-language-switcher-pro' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $langs as $slug => $lang ) {
			$row = isset( $data[ $slug ] ) ? $data[ $slug ] : array();
			$get = static function ( $key ) use ( $row ) {
				return isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
			};
			echo '<tr><td>' . wp_kses_post( Vacanam_Admin::flag( $lang ) ) . ' ' . esc_html( $lang['name'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $get( 'view' ) ) ) . '</td><td>' . esc_html( self::pct( $get( 'view' ), $views ) ) . '</td>';
			$via = array();
			foreach ( array(
				'switcher' => __( 'switcher', 'vacanam-language-switcher-pro' ),
				'select'   => __( 'menu', 'vacanam-language-switcher-pro' ),
				'chooser'  => __( 'chooser', 'vacanam-language-switcher-pro' ),
				'sheet'    => __( 'phone sheet', 'vacanam-language-switcher-pro' ),
			) as $key => $label ) {
				if ( $get( 'chosen_' . $key ) ) {
					$via[] = $label . ' ' . number_format_i18n( $get( 'chosen_' . $key ) );
				}
			}
			echo '<td>' . esc_html( number_format_i18n( $get( 'chosen' ) ) ) . ( $via ? '<br /><span class="description">' . esc_html( implode( ', ', $via ) ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $get( 'left' ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $get( 'redirect' ) + $get( 'redirect_failed' ) ) . ' (' . number_format_i18n( $get( 'redirect_failed' ) ) . ')' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $get( 'suggest_accepted' ) ) . ' / ' . number_format_i18n( $get( 'suggest_dismissed' ) ) ) . '</td>';
			echo '<td>' . esc_html( self::pct( $get( 'abandon' ), $get( 'chosen' ) ) ) . '</td>';
			if ( $money ) {
				$sale = isset( $sales[ $slug ] ) ? $sales[ $slug ] : array( 0, 0 );
				echo '<td>' . esc_html( number_format_i18n( $sale[0] ) ) . '</td><td>' . wp_kses_post( wc_price( $sale[1] ) ) . '</td><td>' . esc_html( self::pct( $sale[0], $get( 'view' ) ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="description">' . esc_html__( '"Left right after changing": visitors who changed language and closed the page within 15 seconds without opening another page — often a sign the translation is missing or poor. "Country redirects (no translation)": visitors sent to a language whose version of the page does not exist yet, so they saw its home page. Conversion: orders per page view. Revenue in the store currency.', 'vacanam-language-switcher-pro' ) . '</p></div>';

		// Daily views chart (simple bars per language).
		$daily = self::daily( $since );
		if ( $daily ) {
			$max = 1;
			foreach ( $daily as $row ) {
				$max = max( $max, array_sum( $row ) );
			}
			$colors = array( '#4f46e5', '#0e9f6e', '#e3a008', '#e02424', '#0694a2', '#9061f9', '#ff5a1f', '#6b7280' );
			$index  = array_flip( array_keys( $langs ) );
			echo '<div class="vacanam-card"><h2>' . esc_html__( 'Page views per day', 'vacanam-language-switcher-pro' ) . '</h2><div class="vacanampro-chart" role="img" aria-label="' . esc_attr__( 'Page views per day and language', 'vacanam-language-switcher-pro' ) . '">';
			foreach ( $daily as $day => $row ) {
				echo '<div class="vacanampro-col" title="' . esc_attr( $day . ': ' . array_sum( $row ) ) . '">';
				foreach ( $row as $slug => $n ) {
					$color = isset( $index[ $slug ] ) ? $colors[ $index[ $slug ] % count( $colors ) ] : '#9ca3af';
					echo '<span style="height:' . esc_attr( (string) round( 100 * $n / $max, 2 ) ) . '%;background:' . esc_attr( $color ) . '"></span>';
				}
				echo '</div>';
			}
			echo '</div><p class="vacanampro-legend">';
			foreach ( $langs as $slug => $lang ) {
				echo '<span class="vacanampro-dot" style="background:' . esc_attr( $colors[ $index[ $slug ] % count( $colors ) ] ) . '"></span> ' . esc_html( $lang['name'] ) . ' &nbsp; ';
			}
			echo '</p></div>';
		}
		echo '</div>';
	}
}
