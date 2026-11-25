<?php
/**
 * Admin screens (free): dashboard with real-time visitors, visitors (countries, devices, browsers…), traffic sources
 * (search engines, social networks, AI assistants, campaigns), pages (top, entry, exit, site search, not found),
 * products with the store funnel, coupons and every page's SEO score — for any period, with CSV export.
 * Ads, profit and AI fixes are in Laghavi Ads Hub (paid service), connected with the Performance Insight Pro add-on.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class LGPI_Admin {

	const CAP       = 'manage_options';
	const VIEW      = 'lgpi_view_reports';
	const HUB       = 'https://laghavi.site/performance-insight/';
	const LINK_HTML = array(
		'a' => array(
			'href'   => true,
			'class'  => true,
			'target' => true,
			'rel'    => true,
			'title'  => true,
		),
	);

	const PAGES = array( 'lgpi', 'lgpi-visitors', 'lgpi-sources', 'lgpi-pages', 'lgpi-products', 'lgpi-coupons', 'lgpi-stock', 'lgpi-journeys', 'lgpi-rinnovo', 'lgpi-languages', 'lgpi-logins', 'lgpi-seo', 'lgpi-connect', 'lgpi-settings' );

	/** Date ranges offered above every report. */
	const RANGES = array( 'today', 'yesterday', '7d', '30d', 'month', 'lastmonth', 'year', 'custom' );

	/**
	 * The chosen period (worked out once per request).
	 *
	 * @var array|null
	 */
	private static $range = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_screens' ) );
		add_action( 'admin_post_lgpi_stock_settings', array( __CLASS__, 'handle_stock_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_lgpi_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_lgpi_family_refresh', array( 'LGPI_Family_View', 'refresh' ) );
		add_action( 'admin_post_lgpi_export', array( __CLASS__, 'handle_export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LGPI_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Is the Pro add-on connected to a Hub?
	 *
	 * @return bool
	 */
	private static function connected() {
		return (bool) apply_filters( 'lgpi_pro_connected', false );
	}

	/**
	 * The Laghavi page, tagged so laghavi.site knows the visit came from this plugin (no data about the store is sent).
	 *
	 * @param string $where Which button.
	 * @return string
	 */
	private static function site_url( $where ) {
		return add_query_arg(
			array(
				'utm_source'   => 'wp-plugin',
				'utm_medium'   => 'performance-insight',
				'utm_campaign' => sanitize_key( $where ),
			),
			self::HUB
		);
	}

	/**
	 * Hub address when connected (else the Laghavi page).
	 *
	 * @return string
	 */
	private static function hub_url() {
		// (see site_url() for the plain Laghavi page)
		$u = (string) apply_filters( 'lgpi_pro_hub_url', '' );
		return self::connected() && $u ? $u : self::site_url( 'upgrade' );
	}

	/**
	 * Where our screens live: inside the WooCommerce menu (WooCommerce is required), else under Dashboard.
	 *
	 * @return string
	 */
	public static function parent_slug() {
		return LGPI_Plugin::has_woo() ? 'woocommerce' : 'index.php';
	}

	/**
	 * Menu: one "Performance Insight" item (and the Pro page) inside WooCommerce. The other screens are
	 * registered too, so their links keep working, but are reached from the plugin's own tab bar.
	 */
	public static function menu() {
		$parent = self::parent_slug();
		$render = array( __CLASS__, 'render' );
		add_submenu_page( $parent, __( 'Performance Insight', 'laghavi-performance-insight-for-woocommerce' ), __( 'Performance Insight', 'laghavi-performance-insight-for-woocommerce' ), self::VIEW, 'lgpi', $render );
		add_submenu_page( $parent, __( 'Laghavi Ads Hub', 'laghavi-performance-insight-for-woocommerce' ), __( 'Insight Pro', 'laghavi-performance-insight-for-woocommerce' ) . ' <span class="lgpi-menu-pro">Pro</span>', self::CAP, 'lgpi-connect', $render );
		$hidden = array(
			'lgpi-visitors' => array( __( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ), self::VIEW ),
			'lgpi-sources'  => array( __( 'Traffic sources', 'laghavi-performance-insight-for-woocommerce' ), self::VIEW ),
			'lgpi-pages'    => array( __( 'Pages & posts', 'laghavi-performance-insight-for-woocommerce' ), self::VIEW ),
			'lgpi-seo'      => array( __( 'SEO scores', 'laghavi-performance-insight-for-woocommerce' ), self::VIEW ),
			'lgpi-settings' => array( __( 'Settings', 'laghavi-performance-insight-for-woocommerce' ), self::CAP ),
		);
		if ( LGPI_Plugin::has_woo() ) {
			$hidden['lgpi-products'] = array( __( 'Products', 'laghavi-performance-insight-for-woocommerce' ), self::VIEW );
			$hidden['lgpi-coupons']  = array( __( 'Coupons', 'laghavi-performance-insight-for-woocommerce' ), self::VIEW );
			$hidden['lgpi-stock']    = array( __( 'Stock', 'laghavi-performance-insight-for-woocommerce' ), self::VIEW );
			$hidden['lgpi-journeys'] = array( __( 'Customer journeys', 'laghavi-performance-insight-for-woocommerce' ), self::VIEW );
		}
		// Rinnovo, Vacanam and Sarala screens: only when the plugin is active, for shop managers and admins.
		$shop = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
		if ( LGPI_Family::has( 'rinnovo' ) ) {
			$hidden['lgpi-rinnovo'] = array( 'Rinnovo', $shop );
		}
		if ( LGPI_Family::has( 'languages' ) ) {
			$hidden['lgpi-languages'] = array( __( 'Languages', 'laghavi-performance-insight-for-woocommerce' ), $shop );
		}
		if ( LGPI_Family::has( 'logins' ) ) {
			$hidden['lgpi-logins'] = array( __( 'Logins', 'laghavi-performance-insight-for-woocommerce' ), $shop );
		}
		foreach ( $hidden as $slug => $it ) {
			/* translators: %s: screen name, e.g. Visitors */
			add_submenu_page( $parent, sprintf( __( 'Performance Insight: %s', 'laghavi-performance-insight-for-woocommerce' ), $it[0] ), $it[0], $it[1], $slug, $render );
		}
		self::$hidden = array_keys( $hidden );
	}

	/**
	 * Screens registered in menu() but kept out of the visible menu.
	 *
	 * @var array
	 */
	private static $hidden = array();

	/**
	 * Take the extra screens out of the visible menu. Runs on admin_head — after WordPress has checked
	 * access to the current screen (which needs them registered) and before the menu is printed.
	 */
	public static function hide_screens() {
		foreach ( self::$hidden as $slug ) {
			remove_submenu_page( self::parent_slug(), $slug );
		}
	}

	/**
	 * Keep "Performance Insight" highlighted in the WooCommerce menu on every one of our screens.
	 *
	 * @param string|null $file Current submenu file.
	 * @return string|null
	 */
	public static function submenu_file( $file ) {
		$page = self::current_page();
		if ( in_array( $page, self::PAGES, true ) && 'lgpi-connect' !== $page ) {
			return 'lgpi';
		}
		return $file;
	}

	/**
	 * Every screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::VIEW ) ) {
			return;
		}
		$page = self::current_page();
		$page = '' !== $page ? $page : 'lgpi';
		if ( in_array( $page, array( 'lgpi-connect', 'lgpi-settings' ), true ) && ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap lgpi">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'Performance Insight', 'laghavi-performance-insight-for-woocommerce' ) . '</h1>';
		self::nav( $page );
		self::notices();
		if ( ! in_array( $page, array( 'lgpi-seo', 'lgpi-connect', 'lgpi-settings' ), true ) ) {
			self::range_bar( $page );
		}
		$item = sanitize_text_field( self::view_arg( 'item' ) );
		if ( $item && in_array( $page, array( 'lgpi', 'lgpi-pages', 'lgpi-products', 'lgpi-seo' ), true ) && preg_match( '/^([a-z0-9_\-]{1,20}):(\d+)$/', $item, $m ) ) {
			self::page_item( $m[1], (int) $m[2], $page );
			echo '<p class="lgpi-foot">' . esc_html__( 'Performance Insight by Laghavi Digital', 'laghavi-performance-insight-for-woocommerce' ) . ' · v' . esc_html( LGPI_VERSION ) . '</p></div>';
			return;
		}
		switch ( $page ) {
			case 'lgpi-visitors':
				self::page_visitors();
				break;
			case 'lgpi-sources':
				self::page_sources();
				break;
			case 'lgpi-pages':
				self::page_pages();
				break;
			case 'lgpi-products':
				self::page_products();
				break;
			case 'lgpi-coupons':
				self::page_coupons();
				break;
			case 'lgpi-stock':
				self::page_stock();
				break;
			case 'lgpi-journeys':
				if ( LGPI_Plugin::has_woo() ) {
					self::page_journeys();
				}
				break;
			case 'lgpi-rinnovo':
				if ( LGPI_Family::allowed() && LGPI_Family::has( 'rinnovo' ) ) {
					LGPI_Family_View::page_rinnovo();
				}
				break;
			case 'lgpi-languages':
				if ( LGPI_Family::allowed() && LGPI_Family::has( 'languages' ) ) {
					LGPI_Family_View::page_languages();
				}
				break;
			case 'lgpi-logins':
				if ( LGPI_Family::allowed() && LGPI_Family::has( 'logins' ) ) {
					LGPI_Family_View::page_logins();
				}
				break;
			case 'lgpi-seo':
				self::page_seo();
				break;
			case 'lgpi-connect':
				self::page_hub();
				break;
			case 'lgpi-settings':
				self::page_settings();
				break;
			default:
				self::page_dashboard();
		}
		echo '<p class="lgpi-foot">' . esc_html__( 'Performance Insight by Laghavi Digital', 'laghavi-performance-insight-for-woocommerce' ) . ' · v' . esc_html( LGPI_VERSION ) . '</p>';
		echo '</div>';
	}

	/**
	 * Top bar.
	 *
	 * @param string $page Current page.
	 */
	private static function nav( $page ) {
		$items = array(
			'lgpi'          => array( 'dashicons-dashboard', __( 'Dashboard', 'laghavi-performance-insight-for-woocommerce' ) ),
			'lgpi-visitors' => array( 'dashicons-groups', __( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ) ),
			'lgpi-sources'  => array( 'dashicons-randomize', __( 'Sources', 'laghavi-performance-insight-for-woocommerce' ) ),
			'lgpi-pages'    => array( 'dashicons-admin-page', __( 'Pages & posts', 'laghavi-performance-insight-for-woocommerce' ) ),
		);
		if ( LGPI_Plugin::has_woo() ) {
			$items['lgpi-journeys'] = array( 'dashicons-location-alt', __( 'Journeys', 'laghavi-performance-insight-for-woocommerce' ) );
			$items['lgpi-products'] = array( 'dashicons-products', __( 'Products', 'laghavi-performance-insight-for-woocommerce' ) );
			$items['lgpi-coupons']  = array( 'dashicons-tickets-alt', __( 'Coupons', 'laghavi-performance-insight-for-woocommerce' ) );
			$items['lgpi-stock']    = array( 'dashicons-archive', __( 'Stock', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		if ( LGPI_Family::allowed() ) {
			if ( LGPI_Family::has( 'rinnovo' ) ) {
				$items['lgpi-rinnovo'] = array( 'dashicons-update', 'Rinnovo' );
			}
			if ( LGPI_Family::has( 'languages' ) ) {
				$items['lgpi-languages'] = array( 'dashicons-translation', __( 'Languages', 'laghavi-performance-insight-for-woocommerce' ) );
			}
			if ( LGPI_Family::has( 'logins' ) ) {
				$items['lgpi-logins'] = array( 'dashicons-admin-users', __( 'Logins', 'laghavi-performance-insight-for-woocommerce' ) );
			}
		}
		$items['lgpi-seo'] = array( 'dashicons-search', __( 'SEO scores', 'laghavi-performance-insight-for-woocommerce' ) );
		if ( current_user_can( self::CAP ) ) {
			$items['lgpi-connect']  = array( 'dashicons-awards', __( 'Laghavi Ads Hub', 'laghavi-performance-insight-for-woocommerce' ) );
			$items['lgpi-settings'] = array( 'dashicons-admin-generic', __( 'Settings', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		echo '<div class="lgpi-top"><a class="lgpi-logo" href="' . esc_url( self::url( 'lgpi' ) ) . '"><span>PI</span></a><nav>';
		foreach ( $items as $slug => $it ) {
			$badge = '';
			if ( 'lgpi-stock' === $slug ) {
				$al    = LGPI_Stock::alerts();
				$badge = $al['out'] + $al['low'] ? '<em class="lgpi-badge">' . esc_html( number_format_i18n( $al['out'] + $al['low'] ) ) . '</em>' : '';
			}
			echo '<a href="' . esc_url( self::url( $slug ) ) . '" class="' . esc_attr( $slug === $page ? 'on' : '' ) . '"><span class="dashicons ' . esc_attr( $it[0] ) . '"></span>' . esc_html( $it[1] ) . wp_kses( $badge, array( 'em' => array( 'class' => true ) ) ) . '</a>';
		}
		echo '</nav>';
		if ( self::connected() ) {
			echo '<a class="lgpi-cta" href="' . esc_url( self::hub_url() ) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span>' . esc_html__( 'Open your full dashboard', 'laghavi-performance-insight-for-woocommerce' ) . '</a>';
		} else {
			echo '<a class="lgpi-cta" href="' . esc_url( self::url( 'lgpi-connect' ) ) . '"><span class="dashicons dashicons-awards"></span>' . esc_html__( 'Get the full dashboard', 'laghavi-performance-insight-for-woocommerce' ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * Button to the Hub.
	 *
	 * @param string $label Label.
	 */
	private static function hub_button( $label ) {
		echo '<a class="button button-primary" href="' . esc_url( self::hub_url() ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Whole-site totals for a period: views, visitors, sources and devices.
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array
	 */
	public static function site_totals( $since, $until ) {
		$t = array(
			'views'    => 0,
			'visitors' => 0,
			'src'      => array_fill_keys( LGPI_DB::SOURCES, 0 ),
			'dev'      => array_fill_keys( LGPI_DB::DEVICES, 0 ),
			'daily'    => array(),
		);
		foreach ( LGPI_DB::site_daily( $since, $until ) as $r ) {
			$t['views']               += (int) $r['views'];
			$t['visitors']            += (int) $r['visitors'];
			$t['daily'][ $r['day'] ]   = (int) $r['visitors'];
			foreach ( LGPI_DB::SOURCES as $k ) {
				$t['src'][ $k ] += (int) ( $r[ 'src_' . $k ] ?? 0 );
			}
			foreach ( LGPI_DB::DEVICES as $k ) {
				$t['dev'][ $k ] += (int) ( $r[ 'dev_' . $k ] ?? 0 );
			}
		}
		$v = array(
			'visits'  => 0,
			'bounces' => 0,
			'pages'   => 0,
			'seconds' => 0,
		);
		foreach ( LGPI_DB::visits_daily( $since, $until ) as $row ) {
			foreach ( $v as $k => $n ) {
				$v[ $k ] = $n + (int) $row[ $k ];
			}
		}
		$t['visits']      = $v['visits'];
		$t['bounce']      = $v['visits'] ? $v['bounces'] / $v['visits'] * 100 : null;
		$t['avg_seconds'] = $v['visits'] ? $v['seconds'] / $v['visits'] : null;
		$t['per_visit']   = $v['visits'] ? $v['pages'] / $v['visits'] : null;
		return $t;
	}

	/**
	 * Labels of the traffic sources.
	 *
	 * @return array
	 */
	public static function source_labels() {
		return array(
			'search'   => __( 'Search engines', 'laghavi-performance-insight-for-woocommerce' ),
			'social'   => __( 'Social media', 'laghavi-performance-insight-for-woocommerce' ),
			'ai'       => __( 'AI assistants', 'laghavi-performance-insight-for-woocommerce' ),
			'paid'     => __( 'Paid ads', 'laghavi-performance-insight-for-woocommerce' ),
			'direct'   => __( 'Direct', 'laghavi-performance-insight-for-woocommerce' ),
			'referral' => __( 'Other websites', 'laghavi-performance-insight-for-woocommerce' ),
			'email'    => __( 'Email', 'laghavi-performance-insight-for-woocommerce' ),
		);
	}

	/**
	 * Labels of the device types.
	 *
	 * @return array
	 */
	public static function device_labels() {
		return array(
			'mobile'  => __( 'Mobile', 'laghavi-performance-insight-for-woocommerce' ),
			'tablet'  => __( 'Tablet', 'laghavi-performance-insight-for-woocommerce' ),
			'desktop' => __( 'Desktop', 'laghavi-performance-insight-for-woocommerce' ),
		);
	}

	/**
	 * Bar chart of visitors per day (per week when the period is long).
	 *
	 * @param array  $daily Y-m-d => visitors.
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 */
	public static function day_chart( $daily, $since, $until ) {
		$days = (int) round( ( strtotime( $until ) - strtotime( $since ) ) / DAY_IN_SECONDS ) + 1;
		$week = $days > 62;
		$bars = array();
		for ( $d = $since; $d <= $until; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			$k            = $week ? gmdate( 'Y-m-d', strtotime( $d . ' -' . ( (int) gmdate( 'N', strtotime( $d ) ) - 1 ) . ' days' ) ) : $d;
			$bars[ $k ]   = ( $bars[ $k ] ?? 0 ) + (int) ( $daily[ $d ] ?? 0 );
		}
		$max = max( 1, max( $bars ) );
		echo '<div class="lgpi-days' . ( count( $bars ) > 20 ? ' dense' : '' ) . '">';
		foreach ( $bars as $d => $n ) {
			$label = $week ? date_i18n( 'j M', strtotime( $d ) ) : date_i18n( count( $bars ) > 20 ? 'j' : 'D j', strtotime( $d ) );
			echo '<div class="lgpi-day" title="' . esc_attr( ( $week ? __( 'Week of', 'laghavi-performance-insight-for-woocommerce' ) . ' ' : '' ) . date_i18n( 'j M Y', strtotime( $d ) ) . ': ' . number_format_i18n( $n ) ) . '"><b style="height:' . esc_attr( (string) round( $n / $max * 100 ) ) . '%"></b><span>' . esc_html( $label ) . '</span><em>' . esc_html( number_format_i18n( $n ) ) . '</em></div>';
		}
		echo '</div>';
		if ( $week ) {
			echo '<p class="lgpi-muted">' . esc_html__( 'One bar per week.', 'laghavi-performance-insight-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * Visitors on the site right now (last 30 minutes) and the latest page views.
	 */
	private static function realtime_card() {
		$hits     = LGPI_DB::hits_since( time() - 30 * MINUTE_IN_SECONDS, 2000 );
		$visitors = count( array_unique( array_column( $hits, 'v' ) ) );
		$labels   = self::source_labels();
		echo '<div class="lgpi-card lgpi-live"><h3><span class="lgpi-dot"></span>' . esc_html__( 'Right now', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>';
		echo '<div class="lgpi-livenum"><strong>' . esc_html( number_format_i18n( $visitors ) ) . '</strong><span>' . esc_html__( 'visitors in the last 30 minutes', 'laghavi-performance-insight-for-woocommerce' ) . ' · ' . esc_html( sprintf( /* translators: %s: page views */ __( '%s page views', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( count( $hits ) ) ) ) . '</span></div>';
		if ( ! $hits ) {
			echo '<p class="lgpi-muted">' . esc_html__( 'Nobody on the site at the moment.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div>';
			return;
		}
		echo '<table class="lgpi-table"><tbody>';
		foreach ( array_slice( $hits, 0, 8 ) as $h ) {
			list( $type, $id )    = array_pad( explode( ':', (string) $h['obj'], 2 ), 2, 0 );
			list( $label, $link ) = self::object_label( $type, (int) $id );
			$ago                  = max( 0, time() - (int) $h['ts'] );
			$from                 = '' !== $h['ref'] ? $h['ref'] . ' · ' : ( in_array( $h['src'], array( 'paid', 'email' ), true ) ? $labels[ $h['src'] ] . ' · ' : '' );
			echo '<tr><td class="lgpi-muted" style="width:56px">' . esc_html( $ago < 60 ? __( 'now', 'laghavi-performance-insight-for-woocommerce' ) : sprintf( /* translators: %d: minutes */ __( '%d min', 'laghavi-performance-insight-for-woocommerce' ), intdiv( $ago, 60 ) ) ) . '</td><td>' . wp_kses( self::item_link( 'lgpi-pages', $type, (int) $id, $label, (string) $link ), self::LINK_HTML ) . '</td><td class="lgpi-muted">' . ( '' !== $h['cc'] ? '<span class="lgpi-flag" title="' . esc_attr( self::country_name( $h['cc'] ) ) . '">' . esc_html( self::flag( $h['cc'] ) ) . '</span> ' : '' ) . esc_html( $from . ( self::device_labels()[ $h['dev'] ] ?? $h['dev'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Dashboard: totals with the change vs the previous period, visitors per day, right now, sources, devices,
	 * top countries, top 5 pages and products, and what the Hub adds.
	 */
	private static function page_dashboard() {
		list( $since, $until, $label, $psince, $puntil ) = self::range();
		$cur  = self::site_totals( $since, $until );
		$prev = self::site_totals( $psince, $puntil );
		$woo  = LGPI_Plugin::has_woo();
		$none = array(
			'orders'  => 0,
			'revenue' => 0,
			'aov'     => 0,
		);
		$tot  = $woo ? LGPI_Store::totals( $since, $until ) : $none;
		$ptot = $woo ? LGPI_Store::totals( $psince, $puntil ) : $none;
		echo '<div class="lgpi-head"><div><h2>' . esc_html( $label ) . '</h2><p>' . esc_html__( 'Your site and store at a glance. Numbers count each visitor once a day, without cookies.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div></div>';
		self::history_note( $since );
		echo '<div class="lgpi-kpis">';
		self::kpi( __( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $cur['visitors'] ), '', self::delta( $cur['visitors'], $prev['visitors'] ) );
		self::kpi( __( 'Page views', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $cur['views'] ), '', self::delta( $cur['views'], $prev['views'] ) );
		self::kpi( __( 'Visits', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $cur['visits'] ), null === $cur['per_visit'] ? '' : sprintf( /* translators: %s: pages */ __( '%s pages per visit', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $cur['per_visit'], 1 ) ), self::delta( $cur['visits'], $prev['visits'] ) );
		self::kpi( __( 'Bounce rate', 'laghavi-performance-insight-for-woocommerce' ), null === $cur['bounce'] ? '–' : number_format_i18n( $cur['bounce'], 1 ) . '%', __( 'visits that saw one page', 'laghavi-performance-insight-for-woocommerce' ), null === $cur['bounce'] || null === $prev['bounce'] ? array() : self::delta( $cur['bounce'], $prev['bounce'], false ) );
		self::kpi( __( 'Time per visit', 'laghavi-performance-insight-for-woocommerce' ), null === $cur['avg_seconds'] ? '–' : self::duration( $cur['avg_seconds'] ), __( 'average', 'laghavi-performance-insight-for-woocommerce' ), null === $cur['avg_seconds'] || null === $prev['avg_seconds'] ? array() : self::delta( $cur['avg_seconds'], $prev['avg_seconds'] ) );
		if ( $woo ) {
			self::kpi( __( 'Orders', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $tot['orders'] ), '', self::delta( $tot['orders'], $ptot['orders'] ) );
			self::kpi( __( 'Sales', 'laghavi-performance-insight-for-woocommerce' ), self::money( $tot['revenue'] ), $tot['orders'] ? sprintf( /* translators: %s: amount */ __( 'avg. order %s', 'laghavi-performance-insight-for-woocommerce' ), self::money( $tot['aov'] ) ) : '', self::delta( $tot['revenue'], $ptot['revenue'] ) );
			$conv  = $cur['visitors'] ? $tot['orders'] / $cur['visitors'] * 100 : null;
			$pconv = $prev['visitors'] ? $ptot['orders'] / $prev['visitors'] * 100 : null;
			self::kpi( __( 'Conversion rate', 'laghavi-performance-insight-for-woocommerce' ), null === $conv ? '–' : number_format_i18n( $conv, 2 ) . '%', __( 'orders ÷ visitors', 'laghavi-performance-insight-for-woocommerce' ), null === $conv || null === $pconv ? array() : self::delta( $conv, $pconv ) );
		}
		echo '</div><div class="lgpi-grid2 lgpi-wide"><div class="lgpi-card"><h3>' . esc_html__( 'Visitors per day', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>';
		self::day_chart( $cur['daily'], $since, $until );
		echo '</div>';
		self::stock_alert_card();
		LGPI_Family_View::dashboard_strip();
		self::realtime_card();
		echo '</div><div class="lgpi-grid3"><div class="lgpi-card"><h3>' . esc_html__( 'Where visitors came from', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>';
		self::bars( $cur['src'], self::source_labels() );
		echo '<p class="lgpi-muted lgpi-more"><a href="' . esc_url( self::url( 'lgpi-sources' ) ) . '">' . esc_html__( 'Search engines, social networks, AI assistants and campaigns', 'laghavi-performance-insight-for-woocommerce' ) . ' →</a></p></div><div class="lgpi-card"><h3>' . esc_html__( 'Devices', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>';
		self::bars( $cur['dev'], self::device_labels() );
		echo '</div><div class="lgpi-card"><h3>' . esc_html__( 'Top countries', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>';
		$cc = LGPI_DB::dims( 'country', $since, $until, 6 );
		if ( $cc ) {
			$vals = array();
			$lbl  = array();
			foreach ( $cc as $c ) {
				$vals[ $c['k'] ] = (int) $c['visitors'];
				$lbl[ $c['k'] ]  = self::country_name( $c['k'] );
			}
			self::bars( $vals, $lbl );
		} else {
			echo '<p class="lgpi-muted">' . esc_html__( 'Countries appear with the next visits.', 'laghavi-performance-insight-for-woocommerce' ) . '</p>';
		}
		echo '<p class="lgpi-muted lgpi-more"><a href="' . esc_url( self::url( 'lgpi-visitors' ) ) . '">' . esc_html__( 'All countries, browsers and screens', 'laghavi-performance-insight-for-woocommerce' ) . ' →</a></p></div></div>';
		echo '<div class="lgpi-grid2"><div class="lgpi-card"><h3>' . esc_html__( 'Top 5 pages', 'laghavi-performance-insight-for-woocommerce' ) . '</h3><table class="lgpi-table"><tbody>';
		$pages = array_slice( LGPI_DB::totals( $since, $until ), 0, 5 );
		foreach ( $pages as $p ) {
			list( $plabel, $link ) = self::object_label( $p['obj_type'], (int) $p['obj_id'] );
			echo '<tr><td>' . wp_kses( self::item_link( 'lgpi-pages', $p['obj_type'], (int) $p['obj_id'], $plabel, $link ), self::LINK_HTML ) . ' <span class="lgpi-type">' . esc_html( self::type_label( $p['obj_type'] ) ) . '</span></td><td class="n">' . esc_html( number_format_i18n( (int) $p['visitors'] ) ) . ' <span class="lgpi-muted">' . esc_html__( 'visitors', 'laghavi-performance-insight-for-woocommerce' ) . '</span></td></tr>';
		}
		if ( ! $pages ) {
			echo '<tr><td class="lgpi-muted">' . esc_html__( 'No visits yet.', 'laghavi-performance-insight-for-woocommerce' ) . '</td></tr>';
		}
		echo '</tbody></table><p class="lgpi-muted lgpi-more"><a href="' . esc_url( self::url( 'lgpi-pages' ) ) . '">' . esc_html__( 'All pages, entry and exit pages, site search', 'laghavi-performance-insight-for-woocommerce' ) . ' →</a></p></div>';
		if ( $woo ) {
			echo '<div class="lgpi-card"><h3>' . esc_html__( 'Top 5 products', 'laghavi-performance-insight-for-woocommerce' ) . '</h3><table class="lgpi-table"><tbody>';
			$prods = array_slice( array_filter( LGPI_Store::product_rows( $since, $until ), fn( $r ) => $r['units'] > 0 || $r['visitors'] > 0 ), 0, 5 );
			foreach ( $prods as $p ) {
				echo '<tr><td>' . wp_kses( self::item_link( 'lgpi-products', 'product', (int) $p['id'], $p['name'], (string) get_permalink( $p['id'] ) ), self::LINK_HTML ) . '</td><td class="n">' . esc_html( number_format_i18n( $p['units'] ) ) . ' <span class="lgpi-muted">' . esc_html__( 'sold', 'laghavi-performance-insight-for-woocommerce' ) . '</span></td></tr>';
			}
			if ( ! $prods ) {
				echo '<tr><td class="lgpi-muted">' . esc_html__( 'No sales or visits yet.', 'laghavi-performance-insight-for-woocommerce' ) . '</td></tr>';
			}
			echo '</tbody></table><p class="lgpi-muted lgpi-more"><a href="' . esc_url( self::url( 'lgpi-products' ) ) . '">' . esc_html__( 'All products and your store funnel', 'laghavi-performance-insight-for-woocommerce' ) . ' →</a></p></div>';
		}
		echo '</div>';
		self::hub_card();
		LGPI_Recommend::render( 'dashboard' );
	}

	/**
	 * Day the visit counter started (WordPress has no record of visits from before the plugin was active).
	 *
	 * @return string Y-m-d.
	 */
	public static function counting_since() {
		static $day = null;
		if ( null === $day ) {
			global $wpdb;
			$first = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(day) FROM %i', LGPI_DB::table( 'daily' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$inst  = wp_date( 'Y-m-d', (int) get_option( 'lgpi_installed_at', time() ) );
			$day   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $first ) && $first < $inst ? $first : $inst;
		}
		return $day;
	}

	/**
	 * Note when a period starts before the counter did: what already has history and what starts now.
	 *
	 * @param string $since Y-m-d.
	 */
	private static function history_note( $since ) {
		$start = self::counting_since();
		if ( $since >= $start ) {
			return;
		}
		$text = LGPI_Plugin::has_woo()
			/* translators: %s: date */
			? __( 'Orders, sales, products, discount codes, stock and the journeys of past orders already include your store’s history from WooCommerce. Visits, page views and time per page are counted from %s — WordPress keeps no record of visits from before the plugin was active.', 'laghavi-performance-insight-for-woocommerce' )
			/* translators: %s: date */
			: __( 'Visits are counted from %s — WordPress keeps no record of visits from before the plugin was active.', 'laghavi-performance-insight-for-woocommerce' );
		echo '<div class="lgpi-note">' . esc_html( sprintf( $text, wp_date( get_option( 'date_format' ), strtotime( $start ) ) ) ) . '</div>';
	}

	/**
	 * What the full dashboard adds.
	 */
	private static function hub_card() {
		$items = array(
			array( 'dashicons-chart-line', __( 'Profit per product', 'laghavi-performance-insight-for-woocommerce' ), __( 'Sales minus product cost, discounts, returns and the ad spend behind each product.', 'laghavi-performance-insight-for-woocommerce' ) ),
			array( 'dashicons-products', __( 'Stock vs ads', 'laghavi-performance-insight-for-woocommerce' ), __( 'Stop paying for ads on products that are about to sell out; reorder in time.', 'laghavi-performance-insight-for-woocommerce' ) ),
			array( 'dashicons-search', __( 'What to fix for SEO', 'laghavi-performance-insight-for-woocommerce' ), __( 'The exact fixes for each page, plus its Google searches and positions.', 'laghavi-performance-insight-for-woocommerce' ) ),
			array( 'dashicons-format-image', __( 'AI product-page review', 'laghavi-performance-insight-for-woocommerce' ), __( 'Why visitors don’t buy, with new text, photo and video ideas.', 'laghavi-performance-insight-for-woocommerce' ) ),
			array( 'dashicons-groups', __( 'Customer value', 'laghavi-performance-insight-for-woocommerce' ), __( 'What a customer costs and earns over time, who is about to stop buying, and ready-made segments.', 'laghavi-performance-insight-for-woocommerce' ) ),
			array( 'dashicons-cart', __( 'Abandoned carts', 'laghavi-performance-insight-for-woocommerce' ), __( 'Carts left behind over time, the products in them and their value.', 'laghavi-performance-insight-for-woocommerce' ) ),
			array( 'dashicons-lightbulb', __( 'AI action plan', 'laghavi-performance-insight-for-woocommerce' ), __( 'One prioritised list of what to do this week, with the money at stake.', 'laghavi-performance-insight-for-woocommerce' ) ),
			array( 'dashicons-megaphone', __( 'Sales next to your ads', 'laghavi-performance-insight-for-woocommerce' ), __( 'Meta, Google, TikTok and more — which ads really sell.', 'laghavi-performance-insight-for-woocommerce' ) ),
		);
		echo '<div class="lgpi-card lgpi-procard"><h3><span class="dashicons dashicons-awards"></span> ' . esc_html__( 'See everything in Laghavi Ads Hub', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>';
		echo '<p>' . esc_html__( 'This plugin shows what happens on your site. Laghavi Ads Hub adds your ads, costs and profit — and tells you what to do. Connect your store and up to 90 days of history appear straight away.', 'laghavi-performance-insight-for-woocommerce' ) . '</p><div class="lgpi-features">';
		foreach ( $items as $it ) {
			echo '<div><span class="dashicons ' . esc_attr( $it[0] ) . '"></span><strong>' . esc_html( $it[1] ) . '</strong><span>' . esc_html( $it[2] ) . '</span></div>';
		}
		echo '</div><p>';
		self::hub_button( self::connected() ? __( 'Open your full dashboard', 'laghavi-performance-insight-for-woocommerce' ) : __( 'Get Laghavi Ads Hub', 'laghavi-performance-insight-for-woocommerce' ) );
		echo '</p></div>';
	}

	/**
	 * The chosen period as [since, until].
	 *
	 * @return array
	 */
	private static function week() {
		$r = self::range();
		return array( $r[0], $r[1] );
	}

	/**
	 * A table of one report dimension (top values with visitors, views and share).
	 *
	 * @param string   $dim    Dimension (LGPI_DB::DIMS).
	 * @param string   $title  Card title.
	 * @param string   $col    First column name.
	 * @param callable $label  Formats a value for display (receives the stored value).
	 * @param int      $limit  Rows.
	 * @param string   $empty  Text when there is nothing yet.
	 * @param string   $metric visitors or views (what the share is based on).
	 * @param string   $count  Name of the count column when $metric is views.
	 */
	private static function dim_card( $dim, $title, $col, $label, $limit = 25, $empty = '', $metric = 'visitors', $count = '' ) {
		list( $since, $until ) = self::week();
		$rows                  = LGPI_DB::dims( $dim, $since, $until, $limit );
		$sum                   = array_sum( array_map( 'intval', array_column( $rows, $metric ) ) );
		echo '<div class="lgpi-card"><div class="lgpi-cardhead"><h3>' . esc_html( $title ) . '</h3>' . wp_kses( self::export_link( $dim ), self::LINK_HTML + array( 'span' => array( 'class' => true ) ) ) . '</div>';
		if ( ! $rows ) {
			echo '<p class="lgpi-muted">' . esc_html( '' !== $empty ? $empty : __( 'Nothing yet for this period.', 'laghavi-performance-insight-for-woocommerce' ) ) . '</p></div>';
			return;
		}
		echo '<div class="lgpi-tablewrap"><table class="lgpi-table lgpi-sortable"><thead><tr><th>' . esc_html( $col ) . '</th><th class="n">' . esc_html( 'views' === $metric ? ( '' !== $count ? $count : __( 'Times', 'laghavi-performance-insight-for-woocommerce' ) ) : __( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ) ) . '</th>' . ( 'views' === $metric ? '' : '<th class="n">' . esc_html__( 'Views', 'laghavi-performance-insight-for-woocommerce' ) . '</th>' ) . '<th class="n">%</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$n  = (int) $r[ $metric ];
			$pc = $sum ? $n / $sum * 100 : 0;
			echo '<tr><td>' . wp_kses( (string) call_user_func( $label, $r['k'] ), self::LINK_HTML + array( 'span' => array( 'class' => true, 'title' => true ) ) ) . '</td><td class="n">' . esc_html( number_format_i18n( $n ) ) . '</td>' . ( 'views' === $metric ? '' : '<td class="n">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>' ) . '<td class="n" data-v="' . esc_attr( (string) $pc ) . '"><span class="lgpi-share"><i style="width:' . esc_attr( (string) round( $pc ) ) . '%"></i></span>' . esc_html( number_format_i18n( $pc, 1 ) ) . '%</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * Visitors: visits per day, countries, devices, browsers, operating systems, screen sizes, languages.
	 */
	private static function page_visitors() {
		list( $since, $until, $label ) = self::range();
		$t                             = self::site_totals( $since, $until );
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ) . '</h2><p>' . esc_html( sprintf( /* translators: %s: period */ __( 'Who visited — %s: where they are, what they use, and how long they stay. Countries come from the visitor’s time zone — no IP lookup.', 'laghavi-performance-insight-for-woocommerce' ), $label ) ) . '</p></div></div>';
		echo '<div class="lgpi-kpis">';
		self::kpi( __( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $t['visitors'] ) );
		self::kpi( __( 'Visits', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $t['visits'] ) );
		self::kpi( __( 'Pages per visit', 'laghavi-performance-insight-for-woocommerce' ), null === $t['per_visit'] ? '–' : number_format_i18n( $t['per_visit'], 1 ) );
		self::kpi( __( 'Bounce rate', 'laghavi-performance-insight-for-woocommerce' ), null === $t['bounce'] ? '–' : number_format_i18n( $t['bounce'], 1 ) . '%' );
		self::kpi( __( 'Time per visit', 'laghavi-performance-insight-for-woocommerce' ), null === $t['avg_seconds'] ? '–' : self::duration( $t['avg_seconds'] ) );
		echo '</div>';
		$daily = LGPI_DB::visits_daily( $since, $until );
		if ( $daily ) {
			echo '<div class="lgpi-card"><div class="lgpi-cardhead"><h3>' . esc_html__( 'Visits per day', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>' . wp_kses( self::export_link( 'visits' ), self::LINK_HTML + array( 'span' => array( 'class' => true ) ) ) . '</div><div class="lgpi-tablewrap" style="max-height:340px;overflow:auto"><table class="lgpi-table"><thead><tr><th>' . esc_html__( 'Day', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Visits', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Pages per visit', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Bounce rate', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Time per visit', 'laghavi-performance-insight-for-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( $daily, true ) as $d => $v ) {
				echo '<tr><td>' . esc_html( date_i18n( 'D j M', strtotime( $d ) ) ) . '</td><td class="n">' . esc_html( number_format_i18n( $t['daily'][ $d ] ?? 0 ) ) . '</td><td class="n">' . esc_html( number_format_i18n( $v['visits'] ) ) . '</td><td class="n">' . esc_html( $v['visits'] ? number_format_i18n( $v['pages'] / $v['visits'], 1 ) : '–' ) . '</td><td class="n">' . esc_html( $v['visits'] ? number_format_i18n( $v['bounces'] / $v['visits'] * 100, 1 ) . '%' : '–' ) . '</td><td class="n">' . esc_html( $v['visits'] ? self::duration( $v['seconds'] / $v['visits'] ) : '–' ) . '</td></tr>';
			}
			echo '</tbody></table></div><p class="lgpi-muted">' . esc_html__( 'A visit ends after 30 minutes without a new page. Visits are counted from version 1.2.0 on.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div>';
		}
		$country = function ( $k ) {
			return '<span class="lgpi-flag">' . esc_html( self::flag( $k ) ) . '</span> ' . esc_html( self::country_name( $k ) );
		};
		$plain   = function ( $k ) {
			return esc_html( $k );
		};
		echo '<div class="lgpi-grid2">';
		self::dim_card( 'country', __( 'Countries', 'laghavi-performance-insight-for-woocommerce' ), __( 'Country', 'laghavi-performance-insight-for-woocommerce' ), $country, 50 );
		echo '<div class="lgpi-card"><h3>' . esc_html__( 'Devices', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>';
		self::bars( $t['dev'], self::device_labels() );
		echo '</div>';
		self::dim_card( 'browser', __( 'Browsers', 'laghavi-performance-insight-for-woocommerce' ), __( 'Browser', 'laghavi-performance-insight-for-woocommerce' ), $plain );
		self::dim_card( 'os', __( 'Operating systems', 'laghavi-performance-insight-for-woocommerce' ), __( 'System', 'laghavi-performance-insight-for-woocommerce' ), $plain );
		self::dim_card( 'screen', __( 'Screen sizes', 'laghavi-performance-insight-for-woocommerce' ), __( 'Screen', 'laghavi-performance-insight-for-woocommerce' ), $plain );
		self::dim_card(
			'lang',
			__( 'Languages', 'laghavi-performance-insight-for-woocommerce' ),
			__( 'Language', 'laghavi-performance-insight-for-woocommerce' ),
			function ( $k ) {
				return esc_html( self::language_name( $k ) );
			}
		);
		echo '</div>';
	}

	/**
	 * Traffic sources: channels, search engines, social networks, AI assistants, websites and campaigns.
	 */
	private static function page_sources() {
		list( $since, $until, $label, $psince, $puntil ) = self::range();
		$t      = self::site_totals( $since, $until );
		$p      = self::site_totals( $psince, $puntil );
		$labels = self::source_labels();
		$orders = LGPI_Plugin::has_woo() ? LGPI_Store::orders_by_source( $since, $until ) : array();
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'Traffic sources', 'laghavi-performance-insight-for-woocommerce' ) . '</h2><p>' . esc_html( sprintf( /* translators: %s: period */ __( 'Where your visitors came from — %s. A visitor counts once a day, for the source of their first visit.', 'laghavi-performance-insight-for-woocommerce' ), $label ) ) . '</p></div></div>';
		echo '<div class="lgpi-card"><div class="lgpi-cardhead"><h3>' . esc_html__( 'Channels', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>' . wp_kses( self::export_link( 'channels' ), self::LINK_HTML + array( 'span' => array( 'class' => true ) ) ) . '</div><div class="lgpi-tablewrap"><table class="lgpi-table lgpi-sortable"><thead><tr><th>' . esc_html__( 'Channel', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">%</th><th class="n">' . esc_html__( 'Change', 'laghavi-performance-insight-for-woocommerce' ) . '</th>' . ( $orders ? '<th class="n">' . esc_html__( 'Orders', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Sales', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Conversion', 'laghavi-performance-insight-for-woocommerce' ) . '</th>' : '' ) . '</tr></thead><tbody>';
		$sum = max( 1, array_sum( $t['src'] ) );
		$src = $t['src'];
		foreach ( array_keys( $orders ) as $k ) {
			$src[ $k ] = $src[ $k ] ?? 0;   // past orders show even before visits were counted
		}
		arsort( $src );
		foreach ( $src as $k => $n ) {
			$d = self::delta( $n, $p['src'][ $k ] ?? 0 );
			$o = $orders[ $k ] ?? array(
				'orders' => 0,
				'sales'  => 0.0,
			);
			echo '<tr><td>' . esc_html( $labels[ $k ] ?? $k ) . '</td><td class="n">' . esc_html( number_format_i18n( $n ) ) . '</td><td class="n">' . esc_html( number_format_i18n( $n / $sum * 100, 1 ) ) . '%</td><td class="n">' . ( $d ? '<span class="lgpi-delta ' . esc_attr( $d[1] ) . '">' . esc_html( $d[0] ) . '</span>' : '<span class="lgpi-muted">–</span>' ) . '</td>';
			if ( $orders ) {
				echo '<td class="n">' . esc_html( number_format_i18n( $o['orders'] ) ) . '</td><td class="n" data-v="' . esc_attr( (string) $o['sales'] ) . '">' . esc_html( self::money( $o['sales'] ) ) . '</td><td class="n">' . esc_html( $n ? number_format_i18n( min( 100, $o['orders'] / $n * 100 ), 2 ) . '%' : '–' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>' . ( $orders ? '<p class="lgpi-muted">' . esc_html__( 'Orders by the source WooCommerce recorded with each order (order attribution).', 'laghavi-performance-insight-for-woocommerce' ) . '</p>' : '' ) . '</div>';
		self::history_note( $since );
		$plain = function ( $k ) {
			return esc_html( $k );
		};
		$site  = function ( $k ) {
			return esc_html( $k ) . ' <a class="lgpi-ext" href="' . esc_url( 'https://' . $k ) . '" target="_blank" rel="noopener noreferrer">↗</a>';
		};
		echo '<div class="lgpi-grid2">';
		self::dim_card( 'engine', __( 'Search engines', 'laghavi-performance-insight-for-woocommerce' ), __( 'Search engine', 'laghavi-performance-insight-for-woocommerce' ), $plain );
		self::dim_card( 'social', __( 'Social networks', 'laghavi-performance-insight-for-woocommerce' ), __( 'Network', 'laghavi-performance-insight-for-woocommerce' ), $plain );
		self::dim_card( 'ai', __( 'AI assistants', 'laghavi-performance-insight-for-woocommerce' ), __( 'Assistant', 'laghavi-performance-insight-for-woocommerce' ), $plain, 25, __( 'No visits from ChatGPT, Perplexity, Gemini, Copilot or other AI assistants yet in this period.', 'laghavi-performance-insight-for-woocommerce' ) );
		self::dim_card( 'site', __( 'Websites', 'laghavi-performance-insight-for-woocommerce' ), __( 'Website', 'laghavi-performance-insight-for-woocommerce' ), $site, 50 );
		self::dim_card( 'campaign', __( 'Campaigns', 'laghavi-performance-insight-for-woocommerce' ), __( 'Campaign (utm_campaign)', 'laghavi-performance-insight-for-woocommerce' ), $plain, 50, __( 'No visits with a utm_campaign tag yet. Add ?utm_campaign=… to the links in your ads, emails and posts to see them here.', 'laghavi-performance-insight-for-woocommerce' ) );
		self::dim_card( 'utm', __( 'Source / medium', 'laghavi-performance-insight-for-woocommerce' ), __( 'utm_source / utm_medium', 'laghavi-performance-insight-for-woocommerce' ), $plain, 50, __( 'No visits with utm_source or utm_medium tags yet.', 'laghavi-performance-insight-for-woocommerce' ) );
		echo '</div>';
		self::bots_cards();
	}

	/**
	 * Name, link and detail screen of a journey step (cached per request).
	 *
	 * @param string $obj type:id.
	 * @return array [label, type, id]
	 */
	private static function step_info( $obj ) {
		static $cache = array();
		if ( ! isset( $cache[ $obj ] ) ) {
			list( $type, $id ) = array_pad( explode( ':', (string) $obj, 2 ), 2, 0 );
			$cache[ $obj ]     = array( ( ! (int) $id && in_array( $type, array( 'page', 'home' ), true ) ) ? __( 'Home page', 'laghavi-performance-insight-for-woocommerce' ) : self::object_label( $type, (int) $id )[0], $type, (int) $id );
		}
		return $cache[ $obj ];
	}

	/**
	 * The steps of one journey as chips: page name and time on it.
	 *
	 * @param array $steps [[type:id, seconds], …].
	 * @param bool  $links Link the chips to the item screens.
	 * @return string HTML
	 */
	public static function steps_html( $steps, $links = true ) {
		if ( ! $steps ) {
			return '<span class="lgpi-muted">' . esc_html__( 'No page views were seen for this visit (for example a cached page or an ad blocker).', 'laghavi-performance-insight-for-woocommerce' ) . '</span>';
		}
		$out = array();
		foreach ( $steps as $s ) {
			list( $label, $type, $id ) = self::step_info( $s[0] ?? '' );
			$cls                       = 'product' === $type ? 'p' : ( in_array( $type, array( 'product_cat', 'product_tag', 'shop' ), true ) ? 'c' : '' );
			$inner                     = '<b>' . esc_html( wp_html_excerpt( $label, 40, '…' ) ) . '</b>' . ( (int) ( $s[1] ?? 0 ) < 0 ? '' : '<i>' . esc_html( self::duration( (int) ( $s[1] ?? 0 ) ) ) . '</i>' );
			if ( $links && $id && in_array( $type, array( 'product', 'page', 'post' ), true ) ) {
				$out[] = '<a class="lgpi-step ' . esc_attr( $cls ) . '" href="' . esc_url( self::url( 'product' === $type ? 'lgpi-products' : 'lgpi-pages', array( 'item' => $type . ':' . $id ) ) ) . '">' . $inner . '</a>';
			} else {
				$out[] = '<span class="lgpi-step ' . esc_attr( $cls ) . '">' . $inner . '</span>';
			}
		}
		return '<div class="lgpi-steps">' . implode( '<span class="lgpi-arrow">→</span>', $out ) . '</div>';
	}

	/**
	 * Allowed HTML of steps_html().
	 *
	 * @return array
	 */
	public static function steps_kses() {
		return array(
			'div'  => array( 'class' => true ),
			'a'    => array(
				'class' => true,
				'href'  => true,
			),
			'span' => array( 'class' => true ),
			'b'    => array(),
			'i'    => array(),
		);
	}

	/**
	 * Labels of journey outcomes.
	 *
	 * @return array
	 */
	public static function outcome_labels() {
		return array(
			'order'     => __( 'Ordered', 'laghavi-performance-insight-for-woocommerce' ),
			'abandoned' => __( 'Abandoned cart', 'laghavi-performance-insight-for-woocommerce' ),
			'open'      => __( 'In cart now', 'laghavi-performance-insight-for-woocommerce' ),
			'account'   => __( 'Created an account', 'laghavi-performance-insight-for-woocommerce' ),
		);
	}

	/**
	 * Where a journey came from, in words.
	 *
	 * @param array $j Journey row.
	 * @return string
	 */
	public static function journey_source( $j ) {
		$labels = self::source_labels();
		$s      = $labels[ $j['src'] ] ?? ucfirst( (string) $j['src'] );
		$extra  = '' !== (string) $j['utm'] ? (string) $j['utm'] : (string) $j['ref'];
		return '' !== $extra && 'direct' !== $j['src'] ? $s . ' · ' . $extra : $s;
	}

	/**
	 * Customer journeys: where buyers, cart abandoners and new accounts came from and what they looked at.
	 */
	private static function page_journeys() {
		$importing = LGPI_Plugin::has_woo() && ! get_option( 'lgpi_history_done' );
		if ( $importing ) {
			LGPI_History::run();   // first 200 past orders now, the rest in the background
		}
		list( $since, $until, $label ) = self::range();
		$all                           = LGPI_Journey::rows( $since, $until );
		$labels                        = self::source_labels();
		$outs                          = self::outcome_labels();
		$filter                        = sanitize_key( self::view_arg( 'jo' ) );
		$fsrc                          = sanitize_key( self::view_arg( 'js' ) );
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'Customer journeys', 'laghavi-performance-insight-for-woocommerce' ) . '</h2><p>' . esc_html( sprintf( /* translators: %s: period */ __( 'Every visit that ended in an order, a cart or a new account — where it came from, each page and product looked at, and the time spent on each. %s.', 'laghavi-performance-insight-for-woocommerce' ), $label ) ) . '</p></div></div>';
		if ( $importing && ! get_option( 'lgpi_history_done' ) ) {
			echo '<div class="lgpi-note">' . esc_html__( 'Bringing in the journeys of your past orders and unfinished checkouts from WooCommerce — refresh in a minute.', 'laghavi-performance-insight-for-woocommerce' ) . '</div>';
		}
		LGPI_Recommend::render( 'journeys' );
		$o   = array_filter( $all, fn( $j ) => 'order' === $j['status'] );
		$ab  = array_filter( $all, fn( $j ) => 'abandoned' === $j['status'] );
		$ac  = array_filter( $all, fn( $j ) => (int) $j['acct'] );
		$avg = function ( $rows, $col ) {
			return $rows ? array_sum( array_column( $rows, $col ) ) / count( $rows ) : 0;
		};
		echo '<div class="lgpi-kpis">';
		self::kpi( __( 'Journeys to an order', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( count( $o ) ), self::money( array_sum( array_column( $o, 'value' ) ) ) );
		self::kpi( __( 'Abandoned carts', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( count( $ab ) ), sprintf( /* translators: %d: minutes */ __( 'no order within %d minutes', 'laghavi-performance-insight-for-woocommerce' ), max( 15, (int) LGPI_Plugin::settings()['abandon_after'] ) ) );
		self::kpi( __( 'Accounts created', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( count( $ac ) ) );
		$carts = count( $o ) + count( $ab );
		self::kpi( __( 'Cart → order', 'laghavi-performance-insight-for-woocommerce' ), $carts ? number_format_i18n( count( $o ) / $carts * 100, 1 ) . '%' : '–', __( 'of visits that added to cart', 'laghavi-performance-insight-for-woocommerce' ) );
		self::kpi( __( 'Time before buying', 'laghavi-performance-insight-for-woocommerce' ), $o ? self::duration( $avg( $o, 'seconds' ) ) : '–', $o ? sprintf( /* translators: %s: pages */ __( '%s pages on average', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $avg( $o, 'pages_n' ), 1 ) ) : '' );
		echo '</div>';
		if ( ! $all ) {
			echo '<div class="lgpi-card"><p>' . esc_html__( 'No journeys yet in this period. As soon as a visitor adds something to the cart, places an order or creates an account, their path appears here — with the source, every page and the time on each.', 'laghavi-performance-insight-for-woocommerce' ) . '</p><p class="lgpi-muted">' . esc_html__( 'Journeys are anonymous: no name, email or IP address is stored. An order is linked only by its order number.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div>';
			return;
		}
		// By source.
		$by = array();
		foreach ( $all as $j ) {
			$k        = (string) $j['src'];
			$by[ $k ] = $by[ $k ] ?? array(
				'order'     => 0,
				'sales'     => 0.0,
				'abandoned' => 0,
				'account'   => 0,
				'pages'     => 0,
				'secs'      => 0,
			);
			if ( 'order' === $j['status'] ) {
				++$by[ $k ]['order'];
				$by[ $k ]['sales'] += (float) $j['value'];
				$by[ $k ]['pages'] += (int) $j['pages_n'];
				$by[ $k ]['secs']  += (int) $j['seconds'];
			} elseif ( 'abandoned' === $j['status'] ) {
				++$by[ $k ]['abandoned'];
			}
			if ( (int) $j['acct'] ) {
				++$by[ $k ]['account'];
			}
		}
		uasort( $by, fn( $a, $b ) => $b['sales'] <=> $a['sales'] ?: $b['abandoned'] <=> $a['abandoned'] );
		echo '<div class="lgpi-card"><div class="lgpi-cardhead"><h3>' . esc_html__( 'Where buyers and abandoners came from', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>' . wp_kses( self::export_link( 'journeys' ), self::LINK_HTML + array( 'span' => array( 'class' => true ) ) ) . '</div><div class="lgpi-tablewrap"><table class="lgpi-table lgpi-sortable"><thead><tr><th>' . esc_html__( 'Source', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Orders', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Sales', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Abandoned carts', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Cart → order', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Accounts', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Pages before buying', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Time before buying', 'laghavi-performance-insight-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $by as $k => $r ) {
			$c = $r['order'] + $r['abandoned'];
			echo '<tr><td><a href="' . esc_url( self::url( 'lgpi-journeys', array( 'js' => $k ) ) ) . '">' . esc_html( $labels[ $k ] ?? ucfirst( $k ) ) . '</a></td><td class="n">' . esc_html( number_format_i18n( $r['order'] ) ) . '</td><td class="n" data-v="' . esc_attr( (string) $r['sales'] ) . '">' . esc_html( self::money( $r['sales'] ) ) . '</td><td class="n">' . esc_html( number_format_i18n( $r['abandoned'] ) ) . '</td><td class="n">' . esc_html( $c ? number_format_i18n( $r['order'] / $c * 100, 1 ) . '%' : '–' ) . '</td><td class="n">' . esc_html( number_format_i18n( $r['account'] ) ) . '</td><td class="n">' . esc_html( $r['order'] ? number_format_i18n( $r['pages'] / $r['order'], 1 ) : '–' ) . '</td><td class="n" data-v="' . esc_attr( (string) ( $r['order'] ? $r['secs'] / $r['order'] : 0 ) ) . '">' . esc_html( $r['order'] ? self::duration( $r['secs'] / $r['order'] ) : '–' ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
		// Products looked at before buying vs before abandoning.
		$pr = array();
		foreach ( $all as $j ) {
			if ( ! in_array( $j['status'], array( 'order', 'abandoned' ), true ) ) {
				continue;
			}
			$seen = array();
			foreach ( $j['steps'] as $s ) {
				if ( 0 !== strpos( (string) ( $s[0] ?? '' ), 'product:' ) ) {
					continue;
				}
				$id         = (int) substr( $s[0], 8 );
				$pr[ $id ]  = $pr[ $id ] ?? array(
					'order'     => 0,
					'o_secs'    => 0,
					'abandoned' => 0,
					'a_secs'    => 0,
					'bought'    => 0,
					'left'      => 0,
				);
				$key        = 'order' === $j['status'] ? 'o_secs' : 'a_secs';
				$pr[ $id ][ $key ] += (int) $s[1];
				if ( ! isset( $seen[ $id ] ) ) {
					++$pr[ $id ][ 'order' === $j['status'] ? 'order' : 'abandoned' ];
					$seen[ $id ] = 1;
				}
			}
			foreach ( $j['items'] as $id ) {
				$pr[ $id ] = $pr[ $id ] ?? array(
					'order'     => 0,
					'o_secs'    => 0,
					'abandoned' => 0,
					'a_secs'    => 0,
					'bought'    => 0,
					'left'      => 0,
				);
				++$pr[ $id ][ 'order' === $j['status'] ? 'bought' : 'left' ];
			}
		}
		uasort( $pr, fn( $a, $b ) => ( $b['order'] + $b['abandoned'] + $b['bought'] + $b['left'] ) <=> ( $a['order'] + $a['abandoned'] + $a['bought'] + $a['left'] ) );
		if ( $pr ) {
			echo '<div class="lgpi-card"><h3>' . esc_html__( 'Products looked at before buying — and before leaving', 'laghavi-performance-insight-for-woocommerce' ) . '</h3><p class="lgpi-muted">' . esc_html__( 'A product many people study and then leave in the cart usually needs a clearer price, photos, delivery time or reviews.', 'laghavi-performance-insight-for-woocommerce' ) . '</p><div class="lgpi-tablewrap"><table class="lgpi-table lgpi-sortable"><thead><tr><th>' . esc_html__( 'Product', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Seen by buyers', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Their time on it', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Bought', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Seen by abandoners', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Their time on it', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Left in cart', 'laghavi-performance-insight-for-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $pr, 0, 50, true ) as $id => $r ) {
				$name = self::object_label( 'product', $id )[0];
				$ot   = $r['order'] ? $r['o_secs'] / $r['order'] : 0;
				$at   = $r['abandoned'] ? $r['a_secs'] / $r['abandoned'] : 0;
				echo '<tr><td>' . wp_kses( self::item_link( 'lgpi-products', 'product', $id, $name, (string) get_permalink( $id ) ), self::LINK_HTML ) . '</td><td class="n">' . esc_html( number_format_i18n( $r['order'] ) ) . '</td><td class="n" data-v="' . esc_attr( (string) $ot ) . '">' . esc_html( $r['order'] ? self::duration( $ot ) : '–' ) . '</td><td class="n">' . esc_html( number_format_i18n( $r['bought'] ) ) . '</td><td class="n">' . esc_html( number_format_i18n( $r['abandoned'] ) ) . '</td><td class="n" data-v="' . esc_attr( (string) $at ) . '">' . esc_html( $r['abandoned'] ? self::duration( $at ) : '–' ) . '</td><td class="n">' . ( $r['left'] ? '<strong class="lgpi-bad">' . esc_html( number_format_i18n( $r['left'] ) ) . '</strong>' : '0' ) . '</td></tr>';
			}
			echo '</tbody></table></div></div>';
		}
		// The journeys themselves.
		$list = array_values(
			array_filter(
				$all,
				function ( $j ) use ( $filter, $fsrc ) {
					if ( '' !== $fsrc && $j['src'] !== $fsrc ) {
						return false;
					}
					if ( 'account' === $filter ) {
						return (bool) (int) $j['acct'];
					}
					return '' === $filter || $j['status'] === $filter;
				}
			)
		);
		echo '<div class="lgpi-card"><div class="lgpi-cardhead"><h3>' . esc_html__( 'Journeys', 'laghavi-performance-insight-for-woocommerce' ) . ' <span class="lgpi-muted">' . esc_html( number_format_i18n( count( $list ) ) ) . '</span></h3><div class="lgpi-chips">';
		$chips = array( '' => __( 'All', 'laghavi-performance-insight-for-woocommerce' ) ) + $outs;
		foreach ( $chips as $k => $l ) {
			echo '<a class="' . esc_attr( $k === $filter ? 'on' : '' ) . '" href="' . esc_url( self::url( 'lgpi-journeys', array( 'jo' => $k, 'js' => $fsrc ) ) ) . '">' . esc_html( $l ) . '</a>';
		}
		if ( '' !== $fsrc ) {
			echo '<a class="on" href="' . esc_url( self::url( 'lgpi-journeys', array( 'jo' => $filter ) ) ) . '">' . esc_html( ( $labels[ $fsrc ] ?? $fsrc ) . ' ✕' ) . '</a>';
		}
		echo '</div></div>';
		if ( ! $list ) {
			echo '<p class="lgpi-muted">' . esc_html__( 'No journeys match this filter.', 'laghavi-performance-insight-for-woocommerce' ) . '</p>';
		}
		$devs = self::device_labels();
		foreach ( array_slice( $list, 0, 100 ) as $j ) {
			$st = $j['status'];
			echo '<div class="lgpi-journey"><div class="lgpi-jhead"><span class="lgpi-pill jo-' . esc_attr( $st ) . '">' . esc_html( $outs[ $st ] ?? $st ) . '</span>';
			if ( ! empty( $j['imp'] ) ) {
				echo ' <span class="lgpi-muted" title="' . esc_attr__( 'Imported from WooCommerce’s order attribution: the source, landing page, number of pages and time before the order. Time per page is only known for visits counted by this plugin.', 'laghavi-performance-insight-for-woocommerce' ) . '">' . esc_html__( 'from WooCommerce’s order record', 'laghavi-performance-insight-for-woocommerce' ) . '</span>';
			}
			if ( (int) $j['acct'] && 'account' !== $st ) {
				echo ' <span class="lgpi-pill jo-account">' . esc_html__( '+ account', 'laghavi-performance-insight-for-woocommerce' ) . '</span>';
			}
			if ( $j['order_id'] ) {
				$ord = wc_get_order( (int) $j['order_id'] );
				if ( $ord ) {
					echo ' <a href="' . esc_url( $ord->get_edit_order_url() ) . '"><strong>' . esc_html( sprintf( /* translators: %s: order number */ __( 'Order #%s', 'laghavi-performance-insight-for-woocommerce' ), $ord->get_order_number() ) ) . '</strong></a> · ' . esc_html( self::money( (float) $j['value'] ) );
				}
			}
			echo ' <span class="lgpi-muted">' . esc_html( wp_date( 'j M, ' . get_option( 'time_format' ), (int) $j['ts_end'] ) ) . '</span><span class="lgpi-jmeta"><span title="' . esc_attr__( 'Came from', 'laghavi-performance-insight-for-woocommerce' ) . '">↘ ' . esc_html( self::journey_source( $j ) ) . '</span><span>' . esc_html( self::flag( $j['cc'] ) . ' ' . ( $devs[ $j['dev'] ] ?? '' ) ) . '</span><span>' . esc_html( sprintf( /* translators: 1: pages, 2: time */ _n( '%1$s page · %2$s', '%1$s pages · %2$s', (int) $j['pages_n'], 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( (int) $j['pages_n'] ), self::duration( (int) $j['seconds'] ) ) ) . '</span></span></div>';
			echo wp_kses( self::steps_html( $j['steps'] ), self::steps_kses() );
			if ( $j['items'] && 'order' !== $st ) {
				$names = array_map( fn( $id ) => self::object_label( 'product', $id )[0], array_slice( $j['items'], 0, 8 ) );
				echo '<p class="lgpi-muted lgpi-jcart"><span class="dashicons dashicons-cart"></span> ' . esc_html( implode( ', ', $names ) ) . '</p>';
			}
			echo '</div>';
		}
		if ( count( $list ) > 100 ) {
			self::more_in_hub( __( 'The newest 100 journeys are shown here. Laghavi Ads Hub keeps them all, next to your ad spend per source.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		echo '<p class="lgpi-muted">' . esc_html__( 'Journeys are anonymous: no name, email or IP address is stored, and an order is linked only by its order number. The time on the last page is counted until the order, cart or sign-up.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * AI crawlers and search engine bots reading the site (counted on the server; no visitor data).
	 */
	private static function bots_cards() {
		$plain = function ( $k ) {
			return esc_html( $k );
		};
		$page  = function ( $k ) {
			list( $type, $id ) = array_pad( explode( ':', $k, 2 ), 2, 0 );
			list( $l, $u )     = self::object_label( $type, (int) $id );
			return esc_html( $l ) . ( $u ? ' <a class="lgpi-ext" href="' . esc_url( $u ) . '" target="_blank" rel="noopener">↗</a>' : '' );
		};
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'AI crawlers & search bots', 'laghavi-performance-insight-for-woocommerce' ) . '</h2><p>' . esc_html__( 'Which AI companies (ChatGPT, Claude, Perplexity, Gemini…) and search engines read your pages — the pages they read are the ones they can recommend. Bots are never counted as visitors.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div></div><div class="lgpi-grid2">';
		self::dim_card( 'aibot', __( 'AI crawlers', 'laghavi-performance-insight-for-woocommerce' ), __( 'Crawler', 'laghavi-performance-insight-for-woocommerce' ), $plain, 25, __( 'No AI crawler visits yet in this period.', 'laghavi-performance-insight-for-woocommerce' ), 'views', __( 'Pages read', 'laghavi-performance-insight-for-woocommerce' ) );
		self::dim_card( 'srchbot', __( 'Search engine crawlers', 'laghavi-performance-insight-for-woocommerce' ), __( 'Crawler', 'laghavi-performance-insight-for-woocommerce' ), $plain, 25, __( 'No search engine crawler visits yet in this period.', 'laghavi-performance-insight-for-woocommerce' ), 'views', __( 'Pages read', 'laghavi-performance-insight-for-woocommerce' ) );
		echo '</div>';
		self::dim_card( 'aibotpg', __( 'Pages AI crawlers read most', 'laghavi-performance-insight-for-woocommerce' ), __( 'Page', 'laghavi-performance-insight-for-woocommerce' ), $page, 25, __( 'No pages read by AI crawlers yet in this period.', 'laghavi-performance-insight-for-woocommerce' ), 'views', __( 'Reads', 'laghavi-performance-insight-for-woocommerce' ) );
	}

	/**
	 * Line under a top list: the full list is in the Hub.
	 *
	 * @param string $text Text.
	 */
	private static function more_in_hub( $text ) {
		echo '<div class="lgpi-limit"><span class="dashicons dashicons-awards"></span><span>' . esc_html( $text ) . '</span><a href="' . esc_url( self::hub_url() ) . '" target="_blank" rel="noopener">' . esc_html( self::connected() ? __( 'Open your full dashboard', 'laghavi-performance-insight-for-woocommerce' ) : __( 'Get Laghavi Ads Hub', 'laghavi-performance-insight-for-woocommerce' ) ) . ' →</a></div>';
	}

	/**
	 * Top 20 pages and posts of the week.
	 */
	private static function page_pages() {
		list( $since, $until, $label ) = self::range();
		$tab                           = sanitize_key( self::view_arg( 'tab' ) );
		$tabs                          = array(
			''         => __( 'Most visited', 'laghavi-performance-insight-for-woocommerce' ),
			'entry'    => __( 'Entry pages', 'laghavi-performance-insight-for-woocommerce' ),
			'exit'     => __( 'Exit pages', 'laghavi-performance-insight-for-woocommerce' ),
			'search'   => __( 'Site search', 'laghavi-performance-insight-for-woocommerce' ),
			'notfound' => __( 'Pages not found', 'laghavi-performance-insight-for-woocommerce' ),
		);
		$tab                           = isset( $tabs[ $tab ] ) ? $tab : '';
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'Pages & posts', 'laghavi-performance-insight-for-woocommerce' ) . '</h2><p>' . esc_html( sprintf( /* translators: %s: period */ __( '%s. Click a page for its visitors by day, sources and SEO problems.', 'laghavi-performance-insight-for-woocommerce' ), $label ) ) . '</p></div></div>';
		echo '<div class="lgpi-filters lgpi-tabs">';
		foreach ( $tabs as $k => $l ) {
			echo '<a class="' . esc_attr( $tab === $k ? 'on' : '' ) . '" href="' . esc_url( self::url( 'lgpi-pages', array( 'tab' => $k ) ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</div>';
		$page_label = function ( $k ) {
			list( $type, $id )   = array_pad( explode( ':', (string) $k, 2 ), 2, 0 );
			list( $name, $link ) = self::object_label( $type, (int) $id );
			return self::item_link( 'lgpi-pages', $type, (int) $id, $name, (string) $link ) . ' <span class="lgpi-type">' . esc_html( self::type_label( $type ) ) . '</span>';
		};
		if ( 'entry' === $tab ) {
			self::dim_card( 'entry', __( 'Entry pages — where visits start', 'laghavi-performance-insight-for-woocommerce' ), __( 'Page', 'laghavi-performance-insight-for-woocommerce' ), $page_label, 100, __( 'Entry pages appear from tomorrow: each day is worked out once it is over.', 'laghavi-performance-insight-for-woocommerce' ), 'views', __( 'Visits', 'laghavi-performance-insight-for-woocommerce' ) );
			return;
		}
		if ( 'exit' === $tab ) {
			self::dim_card( 'exit', __( 'Exit pages — where visits end', 'laghavi-performance-insight-for-woocommerce' ), __( 'Page', 'laghavi-performance-insight-for-woocommerce' ), $page_label, 100, __( 'Exit pages appear from tomorrow: each day is worked out once it is over.', 'laghavi-performance-insight-for-woocommerce' ), 'views', __( 'Visits', 'laghavi-performance-insight-for-woocommerce' ) );
			return;
		}
		if ( 'search' === $tab ) {
			self::dim_card(
				'search',
				__( 'What visitors searched for on your site', 'laghavi-performance-insight-for-woocommerce' ),
				__( 'Search', 'laghavi-performance-insight-for-woocommerce' ),
				function ( $k ) {
					return esc_html( $k ) . ' <a class="lgpi-ext" href="' . esc_url( home_url( '/?s=' . rawurlencode( $k ) ) ) . '" target="_blank" rel="noopener">↗</a>';
				},
				100,
				__( 'No searches on your site in this period.', 'laghavi-performance-insight-for-woocommerce' ),
				'views',
				__( 'Searches', 'laghavi-performance-insight-for-woocommerce' )
			);
			return;
		}
		if ( 'notfound' === $tab ) {
			self::dim_card(
				'notfound',
				__( 'Addresses that showed “page not found”', 'laghavi-performance-insight-for-woocommerce' ),
				__( 'Address', 'laghavi-performance-insight-for-woocommerce' ),
				function ( $k ) {
					return esc_html( $k );
				},
				100,
				__( 'No “page not found” errors in this period.', 'laghavi-performance-insight-for-woocommerce' ),
				'views'
			);
			echo '<p class="lgpi-muted">' . esc_html__( 'Redirect the ones that get visits to the right page (for example with a redirect plugin) so visitors and Google don’t hit a dead end.', 'laghavi-performance-insight-for-woocommerce' ) . '</p>';
			return;
		}
		$rows = array_slice( LGPI_DB::totals( $since, $until ), 0, 100 );
		echo '<div class="lgpi-card"><div class="lgpi-cardhead"><h3>' . esc_html__( 'Most visited', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>' . wp_kses( self::export_link( 'pages' ), self::LINK_HTML + array( 'span' => array( 'class' => true ) ) ) . '</div><div class="lgpi-tablewrap"><table class="lgpi-table lgpi-sortable"><thead><tr><th>#</th><th>' . esc_html__( 'Page', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th>' . esc_html__( 'Type', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Views', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'From search', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'SEO score', 'laghavi-performance-insight-for-woocommerce' ) . '</th></tr></thead><tbody>';
		$i = 0;
		foreach ( $rows as $r ) {
			++$i;
			list( $label, $link ) = self::object_label( $r['obj_type'], (int) $r['obj_id'] );
			$seo                  = self::seo( $r['obj_type'], (int) $r['obj_id'] );
			echo '<tr><td class="lgpi-muted">' . esc_html( (string) $i ) . '</td><td>' . wp_kses( self::item_link( 'lgpi-pages', $r['obj_type'], (int) $r['obj_id'], $label, $link ), self::LINK_HTML ) . '</td><td><span class="lgpi-type">' . esc_html( self::type_label( $r['obj_type'] ) ) . '</span></td>';
			echo '<td class="n">' . esc_html( number_format_i18n( (int) $r['visitors'] ) ) . '</td><td class="n">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td><td class="n">' . esc_html( number_format_i18n( (int) $r['src_search'] ) ) . '</td><td class="n" data-v="' . esc_attr( (string) ( $seo['score'] ?? -1 ) ) . '">';
			self::score_badge( $seo['score'] ?? null );
			echo '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7" class="lgpi-muted">' . esc_html__( 'No visits yet.', 'laghavi-performance-insight-for-woocommerce' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		self::more_in_hub( __( 'What to fix on each page, the Google searches it appears for and which ads bring buyers to it — in Laghavi Ads Hub.', 'laghavi-performance-insight-for-woocommerce' ) );
		echo '</div>';
	}

	/**
	 * Top 20 products of the week.
	 */
	private static function page_products() {
		if ( ! LGPI_Plugin::has_woo() ) {
			return;
		}
		list( $since, $until, $label ) = self::range();
		$rows                          = array_slice( array_values( array_filter( LGPI_Store::product_rows( $since, $until ), fn( $r ) => $r['units'] > 0 || $r['visitors'] > 0 ) ), 0, 100 );
		$kinds                 = array(
			'physical'     => __( 'Physical', 'laghavi-performance-insight-for-woocommerce' ),
			'digital'      => __( 'Digital', 'laghavi-performance-insight-for-woocommerce' ),
			'subscription' => __( 'Subscription', 'laghavi-performance-insight-for-woocommerce' ),
			'external'     => __( 'External', 'laghavi-performance-insight-for-woocommerce' ),
			'grouped'      => __( 'Grouped', 'laghavi-performance-insight-for-woocommerce' ),
		);
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'Products', 'laghavi-performance-insight-for-woocommerce' ) . '</h2><p>' . esc_html( sprintf( /* translators: %s: period */ __( '%s: how shoppers move from product page to order, and each product’s visitors, orders, units, sales and conversion. Click one for its details.', 'laghavi-performance-insight-for-woocommerce' ), $label ) ) . '</p></div></div>';
		LGPI_Recommend::render( 'products' );
		self::funnel( $since, $until );
		echo '<div class="lgpi-card"><div class="lgpi-cardhead"><h3>' . esc_html__( 'Products', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>' . wp_kses( self::export_link( 'products' ), self::LINK_HTML + array( 'span' => array( 'class' => true ) ) ) . '</div><div class="lgpi-tablewrap"><table class="lgpi-table lgpi-sortable"><thead><tr><th>#</th><th>' . esc_html__( 'Product', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th>' . esc_html__( 'Type', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Orders', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Units', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Sales', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Conversion', 'laghavi-performance-insight-for-woocommerce' ) . '</th></tr></thead><tbody>';
		$i = 0;
		foreach ( $rows as $r ) {
			++$i;
			echo '<tr><td class="lgpi-muted">' . esc_html( (string) $i ) . '</td><td>' . wp_kses( self::item_link( 'lgpi-products', 'product', (int) $r['id'], $r['name'], (string) get_permalink( $r['id'] ) ), self::LINK_HTML ) . '</td><td><span class="lgpi-type">' . esc_html( $kinds[ $r['kind'] ] ?? $r['kind'] ) . '</span></td>';
			echo '<td class="n">' . esc_html( number_format_i18n( $r['visitors'] ) ) . '</td><td class="n">' . esc_html( number_format_i18n( $r['orders'] ) ) . '</td><td class="n">' . esc_html( number_format_i18n( $r['units'] ) ) . '</td><td class="n" data-v="' . esc_attr( (string) $r['revenue'] ) . '">' . esc_html( self::money( $r['revenue'] ) ) . '</td><td class="n" data-v="' . esc_attr( (string) ( $r['conversion'] ?? -1 ) ) . '">' . esc_html( null === $r['conversion'] ? '–' : number_format_i18n( $r['conversion'], 2 ) . '%' ) . '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="8" class="lgpi-muted">' . esc_html__( 'No sales or visits yet.', 'laghavi-performance-insight-for-woocommerce' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		self::more_in_hub( __( 'Profit per product after ad spend, abandoned carts, stock vs ads and an AI review of each product page — in Laghavi Ads Hub.', 'laghavi-performance-insight-for-woocommerce' ) );
		echo '</div>';
	}

	/**
	 * Store funnel: product page visitors → added to cart → checkout → orders.
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 */
	private static function funnel( $since, $until ) {
		$prod = 0;
		$atc  = 0;
		foreach ( LGPI_DB::totals( $since, $until, 'product' ) as $r ) {
			$prod += (int) $r['visitors'];
			$atc  += (int) $r['add_to_cart'];
		}
		$co = 0;
		$cp = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		if ( $cp > 0 ) {
			foreach ( LGPI_DB::object_daily( 'page', $cp, $since, $until ) as $r ) {
				$co += (int) $r['visitors'];
			}
		}
		$orders = (int) LGPI_Store::totals( $since, $until )['orders'];
		$steps  = array(
			array( __( 'Viewed a product', 'laghavi-performance-insight-for-woocommerce' ), $prod ),
			array( __( 'Added to cart', 'laghavi-performance-insight-for-woocommerce' ), $atc ),
			array( __( 'Reached checkout', 'laghavi-performance-insight-for-woocommerce' ), $co ),
			array( __( 'Ordered', 'laghavi-performance-insight-for-woocommerce' ), $orders ),
		);
		$top    = max( 1, $prod );
		echo '<div class="lgpi-card"><h3>' . esc_html__( 'Store funnel', 'laghavi-performance-insight-for-woocommerce' ) . '</h3><div class="lgpi-funnel">';
		$prev = null;
		foreach ( $steps as $st ) {
			$drop = null !== $prev && $prev > 0 ? 100 - min( 100, $st[1] / $prev * 100 ) : null;
			echo '<div class="lgpi-step"><span class="lgpi-step-l">' . esc_html( $st[0] ) . '</span><span class="lgpi-step-bar"><i style="width:' . esc_attr( (string) max( 1, round( min( 100, $st[1] / $top * 100 ) ) ) ) . '%"></i></span><strong>' . esc_html( number_format_i18n( $st[1] ) ) . '</strong><em>' . ( null === $drop ? '' : esc_html( sprintf( /* translators: %s: percent */ __( '−%s%% left', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $drop ) ) ) ) . '</em></div>';
			$prev = $st[1];
		}
		echo '</div><p class="lgpi-muted">' . esc_html__( 'Product viewers and checkout visitors are counted once a day; add-to-carts and orders are all of them. The biggest drop is the step to work on first.', 'laghavi-performance-insight-for-woocommerce' ) . ( ! $prod && $orders && $since < self::counting_since() ? ' ' . esc_html__( 'Product views start on the day the plugin was activated; orders include your store’s history.', 'laghavi-performance-insight-for-woocommerce' ) : '' ) . '</p></div>';
	}

	/**
	 * Coupons of the week: totals, top 10 codes (sales, discount, who used them, where from) and active codes nobody used.
	 */
	private static function page_coupons() {
		if ( ! LGPI_Plugin::has_woo() ) {
			return;
		}
		list( $since, $until ) = self::week();
		$r                     = LGPI_Store::coupon_report( $since, $until );
		$countries             = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'Coupons', 'laghavi-performance-insight-for-woocommerce' ) . '</h2><p>' . esc_html__( 'How your coupon codes did in this period: the sales they brought, the discount they cost, and whether new or returning customers used them.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div></div>';
		echo '<div class="lgpi-kpis">';
		self::kpi( __( 'Codes used', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $r['codes_used'] ), sprintf( /* translators: %s: number of coupons */ __( 'of %s active coupons in your store', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $r['codes_in_store'] ) ) );
		self::kpi( __( 'Orders with a coupon', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $r['orders_with_code'] ), null === $r['share'] ? __( 'no orders yet', 'laghavi-performance-insight-for-woocommerce' ) : sprintf( /* translators: 1: percent, 2: orders */ __( '%1$s%% of %2$s orders', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $r['share'], 1 ), number_format_i18n( $r['orders'] ) ) );
		self::kpi( __( 'Sales with a coupon', 'laghavi-performance-insight-for-woocommerce' ), self::money( $r['sales_with_code'] ), sprintf( /* translators: %s: amount */ __( 'discount given %s', 'laghavi-performance-insight-for-woocommerce' ), self::money( $r['discount'] ) ) );
		self::kpi( __( 'Average order', 'laghavi-performance-insight-for-woocommerce' ), null === $r['avg_with'] ? '–' : self::money( $r['avg_with'] ), sprintf( /* translators: %s: amount */ __( 'with a coupon · %s without', 'laghavi-performance-insight-for-woocommerce' ), null === $r['avg_without'] ? '–' : self::money( $r['avg_without'] ) ) );
		echo '</div>';
		echo '<div class="lgpi-card"><h3>' . esc_html__( 'Top 10 coupon codes', 'laghavi-performance-insight-for-woocommerce' ) . '</h3><div class="lgpi-tablewrap"><table class="lgpi-table lgpi-sortable"><thead><tr><th>#</th><th>' . esc_html__( 'Code', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Used', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Sales', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Discount given', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Discount % of sales', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Avg. order', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th>' . esc_html__( 'Customers', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th>' . esc_html__( 'Top countries', 'laghavi-performance-insight-for-woocommerce' ) . '</th></tr></thead><tbody>';
		$i = 0;
		foreach ( $r['codes'] as $c ) {
			++$i;
			$where = array();
			foreach ( $c['countries'] as $cc => $n ) {
				$where[] = '<span title="' . esc_attr( $countries[ $cc ] ?? $cc ) . '">' . esc_html( $cc . ' ' . $n ) . '</span>';
			}
			echo '<tr><td class="lgpi-muted">' . esc_html( (string) $i ) . '</td><td><strong>' . esc_html( $c['code'] ) . '</strong></td><td class="n">' . esc_html( number_format_i18n( $c['uses'] ) ) . '</td>';
			echo '<td class="n" data-v="' . esc_attr( (string) $c['sales'] ) . '">' . esc_html( self::money( $c['sales'] ) ) . '</td><td class="n" data-v="' . esc_attr( (string) $c['discount'] ) . '">' . esc_html( self::money( $c['discount'] ) ) . '</td>';
			echo '<td class="n" data-v="' . esc_attr( (string) $c['discount_pct'] ) . '">' . esc_html( number_format_i18n( $c['discount_pct'], 1 ) . '%' ) . '</td><td class="n" data-v="' . esc_attr( (string) $c['avg_order'] ) . '">' . esc_html( self::money( $c['avg_order'] ) ) . '</td>';
			echo '<td>' . ( $c['new'] + $c['returning'] ? '<span class="lgpi-pill new">' . esc_html( sprintf( /* translators: %d: customers */ __( '%d new', 'laghavi-performance-insight-for-woocommerce' ), $c['new'] ) ) . '</span> <span class="lgpi-pill ret">' . esc_html( sprintf( /* translators: %d: customers */ __( '%d returning', 'laghavi-performance-insight-for-woocommerce' ), $c['returning'] ) ) . '</span>' : '<span class="lgpi-muted">–</span>' ) . '</td>';
			echo '<td>' . ( $where ? wp_kses( implode( ' · ', $where ), array( 'span' => array( 'title' => true ) ) ) : '<span class="lgpi-muted">–</span>' ) . '</td></tr>';
		}
		if ( ! $r['codes'] ) {
			echo '<tr><td colspan="9" class="lgpi-muted">' . esc_html__( 'No coupon codes used in this period.', 'laghavi-performance-insight-for-woocommerce' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		self::more_in_hub( __( 'Every coupon over any period, a weekly timeline, which ads bring coupon buyers and abandoned carts — in Laghavi Ads Hub.', 'laghavi-performance-insight-for-woocommerce' ) );
		echo '</div>';
		if ( $r['codes_in_store'] ) {
			echo '<div class="lgpi-card"><h3>' . esc_html__( 'Active coupons nobody used in this period', 'laghavi-performance-insight-for-woocommerce' ) . ( $r['unused_count'] > count( $r['unused'] ) ? ' <span class="lgpi-muted">' . esc_html( sprintf( /* translators: %d: coupons */ __( '%d — showing 10', 'laghavi-performance-insight-for-woocommerce' ), $r['unused_count'] ) ) . '</span>' : '' ) . '</h3>';
			if ( $r['unused'] ) {
				echo '<div class="lgpi-tablewrap"><table class="lgpi-table"><thead><tr><th>' . esc_html__( 'Code', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th>' . esc_html__( 'Offer', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Used (all time)', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Ends', 'laghavi-performance-insight-for-woocommerce' ) . '</th></tr></thead><tbody>';
				foreach ( $r['unused'] as $c ) {
					echo '<tr><td><strong>' . esc_html( $c['code'] ) . '</strong></td><td>' . esc_html( $c['offer'] ) . '</td><td class="n">' . esc_html( number_format_i18n( $c['used'] ) ) . '</td><td class="n">' . esc_html( $c['ends'] ? $c['ends'] : __( 'No end date', 'laghavi-performance-insight-for-woocommerce' ) ) . '</td></tr>';
				}
				echo '</tbody></table></div><p class="lgpi-muted">' . esc_html__( 'Coupons that stay unused are usually not seen: share them in your emails, on social media or in a site banner — or switch them off.', 'laghavi-performance-insight-for-woocommerce' ) . '</p>';
			} else {
				echo '<p class="lgpi-muted">' . esc_html__( 'Every active coupon was used in this period.', 'laghavi-performance-insight-for-woocommerce' ) . '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * Stock alert strip on the Dashboard (only when something is out of stock or low).
	 */
	private static function stock_alert_card() {
		if ( ! LGPI_Plugin::has_woo() ) {
			return;
		}
		$a = LGPI_Stock::alerts();
		if ( ! $a['out'] && ! $a['low'] && ! $a['soon'] ) {
			return;
		}
		$parts = array();
		if ( $a['out'] ) {
			/* translators: %d: products */
			$parts[] = '<span class="lgpi-pill st-out">' . esc_html( sprintf( _n( '%d out of stock', '%d out of stock', $a['out'], 'laghavi-performance-insight-for-woocommerce' ), $a['out'] ) ) . '</span>';
		}
		if ( $a['low'] ) {
			/* translators: %d: products */
			$parts[] = '<span class="lgpi-pill st-low">' . esc_html( sprintf( _n( '%d low on stock', '%d low on stock', $a['low'], 'laghavi-performance-insight-for-woocommerce' ), $a['low'] ) ) . '</span>';
		}
		if ( $a['soon'] ) {
			/* translators: %d: products */
			$parts[] = '<span class="lgpi-pill st-soon">' . esc_html( sprintf( _n( '%d runs out within a week', '%d run out within a week', $a['soon'], 'laghavi-performance-insight-for-woocommerce' ), $a['soon'] ) ) . '</span>';
		}
		echo '<div class="lgpi-note lgpi-stockstrip"><span class="dashicons dashicons-archive"></span> <strong>' . esc_html__( 'Stock alerts', 'laghavi-performance-insight-for-woocommerce' ) . '</strong> ' . wp_kses( implode( ' ', $parts ), array( 'span' => array( 'class' => true ) ) ) . ' <a href="' . esc_url( self::url( 'lgpi-stock' ) ) . '">' . esc_html__( 'See the list →', 'laghavi-performance-insight-for-woocommerce' ) . '</a></div>';
	}

	/**
	 * Stock: live levels, low and out-of-stock alerts, days left at the current pace, links to edit each product,
	 * and the option to hide sold-out products from the shop automatically.
	 */
	private static function page_stock() {
		if ( ! LGPI_Plugin::has_woo() ) {
			return;
		}
		list( $since, $until, $label ) = self::range();
		$rows                          = LGPI_Stock::rows( $since, $until );
		$c                             = LGPI_Stock::counts( $rows );
		$set                           = LGPI_Stock::settings();
		delete_transient( 'lgpi_stock_alerts' );
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'Stock', 'laghavi-performance-insight-for-woocommerce' ) . '</h2><p>' . esc_html( sprintf( /* translators: 1: time, 2: period */ __( 'Live from WooCommerce at %1$s. Sales pace = units sold in the last 30 days; visitors = %2$s.', 'laghavi-performance-insight-for-woocommerce' ), wp_date( get_option( 'time_format' ) ), strtolower( $label ) ) ) . ' <a href="' . esc_url( self::url( 'lgpi-stock' ) ) . '">' . esc_html__( 'Refresh', 'laghavi-performance-insight-for-woocommerce' ) . '</a></p></div></div>';
		echo '<div class="lgpi-kpis">';
		self::kpi( __( 'Tracked items', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $c['all'] ), __( 'products and variations with stock', 'laghavi-performance-insight-for-woocommerce' ) );
		self::kpi( __( 'Out of stock', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $c['out'] ), $c['visited'] ? sprintf( /* translators: %d: products */ __( '%d still getting visitors', 'laghavi-performance-insight-for-woocommerce' ), $c['visited'] ) : __( 'none visited in the period', 'laghavi-performance-insight-for-woocommerce' ) );
		self::kpi( __( 'Low stock', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $c['low'] ), sprintf( /* translators: %d: units */ __( 'at or under the low-stock level (store: %d)', 'laghavi-performance-insight-for-woocommerce' ), LGPI_Stock::store_low_amount() ) );
		self::kpi( __( 'Runs out within a week', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $c['soon'] ), __( 'at the current sales pace', 'laghavi-performance-insight-for-woocommerce' ) );
		self::kpi( __( 'Hidden automatically', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $c['auto'] ), $set['auto_hide'] ? __( 'shown again when back in stock', 'laghavi-performance-insight-for-woocommerce' ) : __( 'switched off', 'laghavi-performance-insight-for-woocommerce' ) );
		echo '</div>';
		$attn = array_values( array_filter( $rows, fn( $r ) => 'ok' !== $r['state'] ) );
		self::stock_table( __( 'Needs attention', 'laghavi-performance-insight-for-woocommerce' ), $attn, __( 'Nothing is out of stock, low or about to run out. 🎉', 'laghavi-performance-insight-for-woocommerce' ), true );
		$ok = array_values( array_filter( $rows, fn( $r ) => 'ok' === $r['state'] ) );
		if ( $ok ) {
			self::stock_table( __( 'In stock', 'laghavi-performance-insight-for-woocommerce' ), array_slice( $ok, 0, 300 ), '', false );
		}
		if ( current_user_can( self::CAP ) ) {
			echo '<div class="lgpi-card"><h3>' . esc_html__( 'Automatic actions', 'laghavi-performance-insight-for-woocommerce' ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'lgpi_stock_settings' );
			echo '<input type="hidden" name="action" value="lgpi_stock_settings"><p><label><input type="checkbox" name="auto_hide" value="1" ' . checked( $set['auto_hide'], 1, false ) . '> <strong>' . esc_html__( 'Hide products from the shop and search while they are out of stock', 'laghavi-performance-insight-for-woocommerce' ) . '</strong></label><br><span class="lgpi-muted">' . esc_html__( 'As soon as a product sells out it is hidden; when it is back in stock it is shown again exactly as before. Products you hid yourself are never touched, and the product page itself keeps working for links and search engines.', 'laghavi-performance-insight-for-woocommerce' ) . '</span></p>';
			echo '<p><label><input type="checkbox" name="stock_email" value="1" ' . checked( $set['daily_email'], 1, false ) . '> <strong>' . esc_html__( 'Email me a daily stock alert', 'laghavi-performance-insight-for-woocommerce' ) . '</strong></label> <input type="email" name="stock_email_to" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '" value="' . esc_attr( $set['email_to'] ) . '"><br><span class="lgpi-muted">' . esc_html__( 'Once a day, only when something is out of stock, low or about to run out — with a link to edit each product.', 'laghavi-performance-insight-for-woocommerce' ) . '</span></p>';
			echo '<p class="lgpi-muted">' . wp_kses( sprintf( /* translators: %s: link */ __( 'The low-stock level comes from %s, or from each product’s own “Low stock threshold”.', 'laghavi-performance-insight-for-woocommerce' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' ) ) . '">' . esc_html__( 'WooCommerce → Settings → Products → Inventory', 'laghavi-performance-insight-for-woocommerce' ) . '</a>' ), self::LINK_HTML ) . '</p><p><button class="button button-primary">' . esc_html__( 'Save', 'laghavi-performance-insight-for-woocommerce' ) . '</button></p></form>';
			$log = LGPI_Stock::recent();
			if ( $log ) {
				echo '<h4>' . esc_html__( 'Recent automatic changes', 'laghavi-performance-insight-for-woocommerce' ) . '</h4><ul class="lgpi-log">';
				foreach ( array_slice( $log, 0, 10 ) as $l ) {
					echo '<li><span class="lgpi-muted">' . esc_html( wp_date( 'j M, ' . get_option( 'time_format' ), (int) $l['t'] ) ) . '</span> · ' . esc_html( 'hidden' === $l['what'] ? __( 'Hidden (sold out):', 'laghavi-performance-insight-for-woocommerce' ) : __( 'Shown again (back in stock):', 'laghavi-performance-insight-for-woocommerce' ) ) . ' <a href="' . esc_url( (string) get_edit_post_link( (int) $l['id'], 'raw' ) ) . '">' . esc_html( get_the_title( (int) $l['id'] ) ) . '</a></li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}
	}

	/**
	 * One stock table.
	 *
	 * @param string $title Title.
	 * @param array  $rows  Rows.
	 * @param string $empty Text when empty.
	 * @param bool   $csv   Show the CSV link.
	 */
	private static function stock_table( $title, $rows, $empty, $csv ) {
		$names = array(
			'out'       => __( 'Out of stock', 'laghavi-performance-insight-for-woocommerce' ),
			'low'       => __( 'Low stock', 'laghavi-performance-insight-for-woocommerce' ),
			'soon'      => __( 'Runs out soon', 'laghavi-performance-insight-for-woocommerce' ),
			'backorder' => __( 'On backorder', 'laghavi-performance-insight-for-woocommerce' ),
			'ok'        => __( 'In stock', 'laghavi-performance-insight-for-woocommerce' ),
		);
		echo '<div class="lgpi-card"><div class="lgpi-cardhead"><h3>' . esc_html( $title ) . ' <span class="lgpi-muted">' . esc_html( number_format_i18n( count( $rows ) ) ) . '</span></h3>' . ( $csv ? wp_kses( self::export_link( 'stock' ), self::LINK_HTML + array( 'span' => array( 'class' => true ) ) ) : '' ) . '</div>';
		if ( ! $rows ) {
			echo '<p class="lgpi-muted">' . esc_html( $empty ) . '</p></div>';
			return;
		}
		echo '<div class="lgpi-tablewrap"><table class="lgpi-table lgpi-sortable"><thead><tr><th>' . esc_html__( 'Product', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th>' . esc_html__( 'SKU', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'In stock', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Sold (30 days)', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Days left', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th>' . esc_html__( 'In the shop', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$days = null === $r['days'] ? '–' : ( $r['days'] < 1 ? '<1' : number_format_i18n( floor( $r['days'] ) ) );
			$shop = $r['hidden'] ? '<span class="lgpi-pill st-hid">' . esc_html( $r['auto'] ? __( 'Hidden automatically', 'laghavi-performance-insight-for-woocommerce' ) : __( 'Hidden', 'laghavi-performance-insight-for-woocommerce' ) ) . '</span>' : '<span class="lgpi-muted">' . esc_html__( 'Shown', 'laghavi-performance-insight-for-woocommerce' ) . '</span>';
			echo '<tr><td><a href="' . esc_url( $r['edit'] ) . '"><strong>' . esc_html( $r['name'] ) . '</strong></a>' . ( $r['view'] ? ' <a class="lgpi-ext" href="' . esc_url( $r['view'] ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'View in the shop', 'laghavi-performance-insight-for-woocommerce' ) . '">↗</a>' : '' ) . '</td>';
			echo '<td class="lgpi-muted">' . esc_html( '' !== $r['sku'] ? $r['sku'] : '–' ) . '</td><td><span class="lgpi-pill st-' . esc_attr( $r['state'] ) . '">' . esc_html( $names[ $r['state'] ] ) . '</span></td>';
			echo '<td class="n" data-v="' . esc_attr( (string) ( $r['qty'] ?? -1 ) ) . '">' . esc_html( null === $r['qty'] ? '–' : number_format_i18n( $r['qty'] ) ) . '</td><td class="n">' . esc_html( number_format_i18n( $r['sold'] ) ) . '</td><td class="n" data-v="' . esc_attr( (string) ( $r['days'] ?? 99999 ) ) . '">' . esc_html( $days ) . '</td>';
			echo '<td class="n">' . esc_html( number_format_i18n( $r['visitors'] ) ) . '</td><td>' . wp_kses( $shop, array( 'span' => array( 'class' => true ) ) ) . '</td><td><a class="button button-small" href="' . esc_url( $r['edit'] ) . '">' . esc_html__( 'Edit', 'laghavi-performance-insight-for-woocommerce' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * Save the stock options (and apply the hide / show setting to the whole store straight away).
	 */
	public static function handle_stock_settings() {
		check_admin_referer( 'lgpi_stock_settings' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		$s                    = LGPI_Plugin::settings();
		$was                  = (int) ( $s['stock_auto_hide'] ?? 0 );
		$s['stock_auto_hide'] = empty( $_POST['auto_hide'] ) ? 0 : 1;
		$s['stock_email']     = empty( $_POST['stock_email'] ) ? 0 : 1;
		$to                   = isset( $_POST['stock_email_to'] ) ? sanitize_email( wp_unslash( $_POST['stock_email_to'] ) ) : '';
		$s['stock_email_to']  = is_email( $to ) ? $to : '';
		update_option( 'lgpi_settings', $s );
		$msg = __( 'Saved.', 'laghavi-performance-insight-for-woocommerce' );
		if ( $was !== $s['stock_auto_hide'] && LGPI_Plugin::has_woo() ) {
			$n   = LGPI_Stock::sync_all( (bool) $s['stock_auto_hide'] );
			$msg = $s['stock_auto_hide']
				/* translators: %d: products */
				? sprintf( _n( 'Saved. %d sold-out product is now hidden from the shop.', 'Saved. %d sold-out products are now hidden from the shop.', $n, 'laghavi-performance-insight-for-woocommerce' ), $n )
				/* translators: %d: products */
				: sprintf( _n( 'Saved. %d product is shown in the shop again.', 'Saved. %d products are shown in the shop again.', $n, 'laghavi-performance-insight-for-woocommerce' ), $n );
		}
		delete_transient( 'lgpi_stock_alerts' );
		self::back( 'lgpi-stock', $msg );
	}

	/**
	 * One page, post, product or category: visitors by day, where they came from, sales, and the SEO problems found.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object ID.
	 * @param string $from Screen to go back to.
	 */
	private static function page_item( $type, $id, $from ) {
		list( $since, $until ) = self::week();
		list( $label, $link )  = self::object_label( $type, $id );
		$back                  = 'lgpi' === $from ? 'lgpi' : $from;
		echo '<p><a class="lgpi-back" href="' . esc_url( self::url( $back ) ) . '">← ' . esc_html__( 'Back', 'laghavi-performance-insight-for-woocommerce' ) . '</a></p>';
		$seo = self::seo( $type, $id );
		echo '<div class="lgpi-head"><div><h2>' . esc_html( $label ) . ' <span class="lgpi-type">' . esc_html( self::type_label( $type ) ) . '</span></h2><p>' . esc_html( self::range()[2] ) . ( $link ? ' · <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the live page', 'laghavi-performance-insight-for-woocommerce' ) . ' ↗</a>' : '' ) . '</p></div>';
		if ( $seo ) {
			echo '<div class="lgpi-bigscore">';
			self::score_badge( $seo['score'] );
			echo '<small>' . esc_html__( 'SEO score', 'laghavi-performance-insight-for-woocommerce' ) . '</small></div>';
		}
		echo '</div>';
		$rows  = LGPI_DB::object_daily( $type, $id, $since, $until );
		$sum   = array(
			'views'    => 0,
			'visitors' => 0,
			'atc'      => 0,
		);
		$src   = array_fill_keys( LGPI_DB::SOURCES, 0 );
		$dev   = array_fill_keys( array( 'mobile', 'tablet', 'desktop' ), 0 );
		$daily = array();
		for ( $d = $since; $d <= $until; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			$daily[ $d ] = 0;
		}
		foreach ( $rows as $r ) {
			$sum['views']      += (int) $r['views'];
			$sum['visitors']   += (int) $r['visitors'];
			$sum['atc']        += (int) $r['add_to_cart'];
			$daily[ $r['day'] ] = (int) $r['visitors'];
			foreach ( array_keys( $src ) as $k ) {
				$src[ $k ] += (int) ( $r[ 'src_' . $k ] ?? 0 );
			}
			foreach ( array_keys( $dev ) as $k ) {
				$dev[ $k ] += (int) ( $r[ 'dev_' . $k ] ?? 0 );
			}
		}
		$vis = $sum['visitors'];
		echo '<div class="lgpi-kpis">';
		self::kpi( __( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $vis ), sprintf( /* translators: %s: views */ __( '%s page views', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $sum['views'] ) ) );
		self::kpi( __( 'From search engines', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $src['search'] ), $vis ? sprintf( /* translators: %s: percent */ __( '%s%% of visitors', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $src['search'] / $vis * 100 ) ) : '' );
		self::kpi( __( 'On mobile', 'laghavi-performance-insight-for-woocommerce' ), $vis ? number_format_i18n( $dev['mobile'] / $vis * 100 ) . '%' : '–', sprintf( /* translators: %s: visitors */ __( '%s visitors', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $dev['mobile'] ) ) );
		if ( 'product' === $type && LGPI_Plugin::has_woo() ) {
			$s = LGPI_Store::product_sales( $since, $until )[ $id ] ?? array(
				'orders'  => 0,
				'units'   => 0,
				'revenue' => 0.0,
			);
			self::kpi( __( 'Sales', 'laghavi-performance-insight-for-woocommerce' ), self::money( $s['revenue'] ), sprintf( /* translators: 1: orders, 2: units */ __( '%1$s orders · %2$s units', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $s['orders'] ), number_format_i18n( $s['units'] ) ) );
			self::kpi( __( 'Added to cart', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $sum['atc'] ), $vis ? sprintf( /* translators: %s: percent */ __( '%s%% of visitors buy', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( min( 100, $s['orders'] / $vis * 100 ), 2 ) ) : '' );
		}
		echo '</div><div class="lgpi-grid2"><div class="lgpi-card"><h3>' . esc_html__( 'Visitors per day', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>';
		self::day_chart( $daily, $since, $until );
		echo '</div><div class="lgpi-card"><h3>' . esc_html__( 'Where visitors came from', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>';
		self::bars( $src, self::source_labels() );
		echo '<h3 style="margin-top:14px">' . esc_html__( 'Devices', 'laghavi-performance-insight-for-woocommerce' ) . '</h3>';
		self::bars(
			$dev,
			array(
				'mobile'  => __( 'Mobile', 'laghavi-performance-insight-for-woocommerce' ),
				'tablet'  => __( 'Tablet', 'laghavi-performance-insight-for-woocommerce' ),
				'desktop' => __( 'Desktop', 'laghavi-performance-insight-for-woocommerce' ),
			)
		);
		echo '</div></div>';
		if ( $seo ) {
			$levels = array(
				'high'   => __( 'Important', 'laghavi-performance-insight-for-woocommerce' ),
				'medium' => __( 'Should fix', 'laghavi-performance-insight-for-woocommerce' ),
				'low'    => __( 'Nice to fix', 'laghavi-performance-insight-for-woocommerce' ),
			);
			/* translators: %d: number of problems */
			echo '<div class="lgpi-grid2"><div class="lgpi-card"><h3>' . esc_html( sprintf( __( 'Problems found (%d)', 'laghavi-performance-insight-for-woocommerce' ), count( $seo['issues'] ) ) ) . '</h3>';
			if ( $seo['issues'] ) {
				echo '<ul class="lgpi-issues">';
				foreach ( $seo['issues'] as $is ) {
					echo '<li class="' . esc_attr( $is[0] ) . '"><span>' . esc_html( $levels[ $is[0] ] ?? $is[0] ) . '</span>' . esc_html( $is[1] ) . '</li>';
				}
				echo '</ul>';
			} else {
				echo '<p class="lgpi-good">' . esc_html__( 'No problems found — well done.', 'laghavi-performance-insight-for-woocommerce' ) . '</p>';
			}
			echo '</div><div class="lgpi-card"><h3>' . esc_html__( 'What the check looked at', 'laghavi-performance-insight-for-woocommerce' ) . '</h3><table class="lgpi-table lgpi-checks"><tbody>';
			foreach ( self::checks( $seo ) as $c ) {
				echo '<tr><td><span class="dashicons ' . esc_attr( $c[0] ? 'dashicons-yes-alt lgpi-good' : 'dashicons-warning lgpi-bad' ) . '"></span></td><td>' . esc_html( $c[1] ) . '</td><td class="n lgpi-muted">' . esc_html( $c[2] ) . '</td></tr>';
			}
			echo '</tbody></table></div></div>';
			echo '<div class="lgpi-card lgpi-procard"><h3><span class="dashicons dashicons-awards"></span> ' . esc_html__( 'Get the exact fix for this page', 'laghavi-performance-insight-for-woocommerce' ) . '</h3><p>' . esc_html( 'product' === $type ? __( 'Laghavi Ads Hub reads this product page and writes the changes for you: a new SEO title and meta description, alt text for each photo, a better product description and FAQs, photo and video ideas, why visitors don’t buy — and the Google searches this product appears for.', 'laghavi-performance-insight-for-woocommerce' ) : __( 'Laghavi Ads Hub reads this page and writes the changes for you: a new SEO title and meta description, alt text for each image, better headings and text — and the Google searches this page appears for.', 'laghavi-performance-insight-for-woocommerce' ) ) . '</p><p>';
			self::hub_button( self::connected() ? __( 'Open your full dashboard', 'laghavi-performance-insight-for-woocommerce' ) : __( 'See Laghavi Ads Hub', 'laghavi-performance-insight-for-woocommerce' ) );
			echo '</p></div>';
		}
	}

	/**
	 * Checklist rows for a page: [ok, label, value].
	 *
	 * @param array $c SEO check.
	 * @return array[]
	 */
	private static function checks( $c ) {
		if ( ! isset( $c['title_len'] ) ) {
			return array(
				array( ( $c['words'] ?? 0 ) >= 50, __( 'Category description', 'laghavi-performance-insight-for-woocommerce' ), sprintf( /* translators: %d: words */ __( '%d words', 'laghavi-performance-insight-for-woocommerce' ), $c['words'] ?? 0 ) ),
				array( ( $c['count'] ?? 0 ) > 0, __( 'Items in it', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $c['count'] ?? 0 ) ),
			);
		}
		/* translators: %d: characters */
		$chars = __( '%d characters', 'laghavi-performance-insight-for-woocommerce' );
		$p     = $c['product'] ?? null;
		$rows  = array(
			array( ! $c['noindex'], __( 'Can appear in Google', 'laghavi-performance-insight-for-woocommerce' ), $c['noindex'] ? __( 'hidden (noindex)', 'laghavi-performance-insight-for-woocommerce' ) : __( 'yes', 'laghavi-performance-insight-for-woocommerce' ) ),
			array( $c['title_len'] >= 20 && $c['title_len'] <= 62, __( 'SEO title', 'laghavi-performance-insight-for-woocommerce' ), sprintf( $chars, $c['title_len'] ) ),
			array( '' !== $c['description'] && mb_strlen( $c['description'] ) <= 165, __( 'Meta description', 'laghavi-performance-insight-for-woocommerce' ), '' === $c['description'] ? __( 'missing', 'laghavi-performance-insight-for-woocommerce' ) : sprintf( $chars, mb_strlen( $c['description'] ) ) ),
			array( $p ? $p['desc_words'] >= 120 : $c['words'] >= 300, $p ? __( 'Product description', 'laghavi-performance-insight-for-woocommerce' ) : __( 'Text on the page', 'laghavi-performance-insight-for-woocommerce' ), sprintf( /* translators: %d: words */ __( '%d words', 'laghavi-performance-insight-for-woocommerce' ), $p ? $p['desc_words'] : $c['words'] ) ),
			array( 0 === $c['img_no_alt'], __( 'Image alt text', 'laghavi-performance-insight-for-woocommerce' ), $c['images'] ? sprintf( /* translators: 1: with alt, 2: images */ __( '%1$d of %2$d images', 'laghavi-performance-insight-for-woocommerce' ), $c['images'] - $c['img_no_alt'], $c['images'] ) : __( 'no images in the text', 'laghavi-performance-insight-for-woocommerce' ) ),
			array( $c['h1'] <= 1, __( 'Main heading (H1)', 'laghavi-performance-insight-for-woocommerce' ), $c['h1'] > 1 ? sprintf( /* translators: %d: headings */ __( '%d in the content', 'laghavi-performance-insight-for-woocommerce' ), $c['h1'] ) : __( 'page title', 'laghavi-performance-insight-for-woocommerce' ) ),
			array( $c['internal'] > 0, __( 'Links to your other pages', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $c['internal'] ) ),
			array( (bool) $c['featured'], __( 'Featured image', 'laghavi-performance-insight-for-woocommerce' ), $c['featured'] ? __( 'yes', 'laghavi-performance-insight-for-woocommerce' ) : __( 'none', 'laghavi-performance-insight-for-woocommerce' ) ),
		);
		if ( $p ) {
			$rows[] = array( $p['images'] >= 3, __( 'Product photos', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $p['images'] ) );
			$rows[] = array( $p['short_words'] > 0, __( 'Short description', 'laghavi-performance-insight-for-woocommerce' ), $p['short_words'] ? sprintf( /* translators: %d: words */ __( '%d words', 'laghavi-performance-insight-for-woocommerce' ), $p['short_words'] ) : __( 'none', 'laghavi-performance-insight-for-woocommerce' ) );
			$rows[] = array( $p['reviews'] > 0, __( 'Reviews', 'laghavi-performance-insight-for-woocommerce' ), $p['reviews'] ? number_format_i18n( $p['reviews'] ) . ' · ' . number_format_i18n( $p['rating'], 1 ) . '★' : __( 'none yet', 'laghavi-performance-insight-for-woocommerce' ) );
			$rows[] = array( $c['videos'] > 0, __( 'Video', 'laghavi-performance-insight-for-woocommerce' ), $c['videos'] ? __( 'yes', 'laghavi-performance-insight-for-woocommerce' ) : __( 'none', 'laghavi-performance-insight-for-woocommerce' ) );
			$rows[] = array( $p['sku'], __( 'SKU', 'laghavi-performance-insight-for-woocommerce' ), $p['sku'] ? __( 'yes', 'laghavi-performance-insight-for-woocommerce' ) : __( 'missing', 'laghavi-performance-insight-for-woocommerce' ) );
			$rows[] = array( 'outofstock' !== $p['stock'], __( 'Stock', 'laghavi-performance-insight-for-woocommerce' ), 'outofstock' === $p['stock'] ? __( 'out of stock', 'laghavi-performance-insight-for-woocommerce' ) : __( 'in stock', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		return $rows;
	}

	/**
	 * Horizontal bars with counts and shares.
	 *
	 * @param array $vals   key => count.
	 * @param array $labels key => label.
	 */
	public static function bars( $vals, $labels ) {
		$sum = array_sum( $vals );
		if ( ! $sum ) {
			echo '<p class="lgpi-muted">' . esc_html__( 'No visits yet.', 'laghavi-performance-insight-for-woocommerce' ) . '</p>';
			return;
		}
		arsort( $vals );
		foreach ( $vals as $k => $v ) {
			$pc = round( $v / $sum * 100 );
			echo '<div class="lgpi-barrow"><span>' . esc_html( $labels[ $k ] ?? $k ) . '</span><span class="bar"><i style="width:' . esc_attr( (string) $pc ) . '%"></i></span><span class="val">' . esc_html( number_format_i18n( $v ) ) . ' <em>' . esc_html( $pc . '%' ) . '</em></span></div>';
		}
	}

	/**
	 * Every page, post and product with its SEO score (the fixes are in the Hub).
	 */
	private static function page_seo() {
		$type  = sanitize_key( self::view_arg( 'type' ) );
		$paged = max( 1, absint( self::view_arg( 'paged' ) ) );
		$types = array_values( array_filter( array( 'page', 'post', LGPI_Plugin::has_woo() ? 'product' : '' ) ) );
		$q     = new WP_Query(
			array(
				'post_type'      => $type && in_array( $type, $types, true ) ? $type : $types,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'paged'          => $paged,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$rows  = array();
		foreach ( $q->posts as $id ) {
			$c = self::seo( get_post_type( $id ), $id );
			if ( $c ) {
				$rows[] = $c;
			}
		}
		usort( $rows, fn( $a, $b ) => $a['score'] <=> $b['score'] );
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'SEO scores', 'laghavi-performance-insight-for-woocommerce' ) . '</h2><p>' . esc_html__( 'Every page, post and product scored for what search engines and shoppers look for — weakest first. Click one to see its problems.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div></div>';
		echo '<div class="lgpi-note pro"><span class="dashicons dashicons-awards"></span> ' . esc_html__( 'This plugin finds the problems. Laghavi Ads Hub writes the exact fix for each page — a new SEO title and description, alt text for every image, better product text — and shows the Google searches each page appears for.', 'laghavi-performance-insight-for-woocommerce' ) . ' <a href="' . esc_url( self::hub_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'See Laghavi Ads Hub', 'laghavi-performance-insight-for-woocommerce' ) . '</a></div>';
		echo '<div class="lgpi-card"><div class="lgpi-filters"><a class="' . esc_attr( '' === $type ? 'on' : '' ) . '" href="' . esc_url( self::url( 'lgpi-seo' ) ) . '">' . esc_html__( 'All', 'laghavi-performance-insight-for-woocommerce' ) . '</a>';
		foreach ( $types as $t ) {
			echo '<a class="' . esc_attr( $type === $t ? 'on' : '' ) . '" href="' . esc_url( self::url( 'lgpi-seo', array( 'type' => $t ) ) ) . '">' . esc_html( self::type_label( $t ) ) . '</a>';
		}
		echo '</div><div class="lgpi-tablewrap"><table class="lgpi-table"><thead><tr><th>' . esc_html__( 'Page', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th>' . esc_html__( 'Type', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'Problems', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th class="n">' . esc_html__( 'SEO score', 'laghavi-performance-insight-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $c ) {
			$n = count( $c['issues'] );
			echo '<tr><td>' . wp_kses( self::item_link( 'lgpi-seo', $c['type'], (int) $c['id'], get_the_title( $c['id'] ), $c['url'] ), self::LINK_HTML ) . '</td><td>' . esc_html( self::type_label( $c['type'] ) ) . '</td><td class="n">' . ( $n ? esc_html( sprintf( /* translators: %d: number of problems */ _n( '%d to fix', '%d to fix', $n, 'laghavi-performance-insight-for-woocommerce' ), $n ) ) : '<span class="lgpi-good">' . esc_html__( 'none', 'laghavi-performance-insight-for-woocommerce' ) . '</span>' ) . '</td><td class="n">';
			self::score_badge( $c['score'] );
			echo '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="4" class="lgpi-muted">' . esc_html__( 'Nothing published yet.', 'laghavi-performance-insight-for-woocommerce' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		$pages = (int) $q->max_num_pages;
		if ( $pages > 1 ) {
			echo '<div class="lgpi-pager">';
			$last = min( $pages, 30 );
			for ( $p = 1; $p <= $last; $p++ ) {
				echo '<a class="' . esc_attr( $p === $paged ? 'on' : '' ) . '" href="' . esc_url(
					self::url(
						'lgpi-seo',
						array_filter(
							array(
								'type'  => $type,
								'paged' => $p,
							)
						)
					)
				) . '">' . esc_html( (string) $p ) . '</a>';
			}
			echo '</div>';
		}
		echo '<p class="lgpi-muted">' . esc_html__( '80+ is good, 55–79 needs work, under 55 needs attention.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * Laghavi Ads Hub: the Pro add-on's connect screen when installed, else how to get it.
	 */
	private static function page_hub() {
		if ( has_action( 'lgpi_pro_screen' ) ) {
			do_action( 'lgpi_pro_screen' );
			return;
		}
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'Laghavi Ads Hub', 'laghavi-performance-insight-for-woocommerce' ) . ' <span class="lgpi-menu-pro">Pro</span></h2><p>' . esc_html__( 'The full dashboard for your store, by Laghavi Digital — a paid service.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div></div>';
		echo '<div class="lgpi-card"><h3>' . esc_html__( 'How to connect', 'laghavi-performance-insight-for-woocommerce' ) . '</h3><ol class="lgpi-steps"><li>' . esc_html__( 'Choose a Laghavi Ads Hub plan on laghavi.site.', 'laghavi-performance-insight-for-woocommerce' ) . '</li><li>' . esc_html__( 'Install the Performance Insight Pro add-on you receive with your plan (Plugins → Add New → Upload Plugin).', 'laghavi-performance-insight-for-woocommerce' ) . '</li><li>' . esc_html__( 'Paste your Hub address and store key here — your store’s numbers appear in your dashboard within the hour.', 'laghavi-performance-insight-for-woocommerce' ) . '</li></ol><p>';
		self::hub_button( __( 'See plans', 'laghavi-performance-insight-for-woocommerce' ) );
		echo '</p></div>';
		self::hub_card();
	}


	/**
	 * Styles + small script on our screens only (menu badge style everywhere in admin).
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		wp_register_style( 'lgpi-menu', false, array(), LGPI_VERSION );
		wp_enqueue_style( 'lgpi-menu' );
		wp_add_inline_style( 'lgpi-menu', '.lgpi-menu-pro{display:inline-block;margin-left:4px;padding:0 6px;border-radius:3px;background:#f56e28;color:#fff;font-size:10px;line-height:17px;font-weight:600;vertical-align:middle}' );
		if ( in_array( self::current_page(), self::PAGES, true ) ) {
			wp_enqueue_style( 'lgpi-admin', LGPI_URL . 'assets/css/admin.css', array(), LGPI_VERSION );
			wp_enqueue_script( 'lgpi-admin', LGPI_URL . 'assets/js/admin.js', array(), LGPI_VERSION, true );
		}
	}

	/**
	 * Plugins screen links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=lgpi' ) ) . '">' . esc_html__( 'Dashboard', 'laghavi-performance-insight-for-woocommerce' ) . '</a>' );
		if ( ! self::connected() && ! has_action( 'lgpi_pro_screen' ) ) {
			$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=lgpi-connect' ) ) . '" style="font-weight:600">' . esc_html__( 'Go Pro', 'laghavi-performance-insight-for-woocommerce' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Admin URL of one of our pages.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra args.
	 * @return string
	 */
	public static function url( $page, $args = array() ) {
		$args = array_merge( self::range_args(), $args );
		$args = array_filter(
			$args,
			function ( $v ) {
				return null !== $v && '' !== $v;
			}
		);
		if ( $args ) {
			$args['_lgpi'] = wp_create_nonce( 'lgpi_view' );
		}
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Give report access to the roles chosen in Settings (administrators always have it).
	 *
	 * @param array   $allcaps All capabilities of the user.
	 * @param array   $caps    Capabilities being checked.
	 * @param array   $args    Arguments.
	 * @param WP_User $user    User.
	 * @return array
	 */
	public static function grant_view( $allcaps, $caps, $args, $user ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( in_array( self::VIEW, (array) $caps, true ) && empty( $allcaps[ self::VIEW ] ) ) {
			$roles = (array) LGPI_Plugin::settings()['view_roles'];
			if ( ! empty( $allcaps[ self::CAP ] ) || ( $user instanceof WP_User && array_intersect( $roles, (array) $user->roles ) ) ) {
				$allcaps[ self::VIEW ] = true;
			}
		}
		return $allcaps;
	}

	/**
	 * The period to show: [since, until, label, prev_since, prev_until, key] (dates Y-m-d in the site's time zone).
	 *
	 * @return array
	 */
	public static function range() {
		if ( null !== self::$range ) {
			return self::$range;
		}
		$key   = sanitize_key( self::view_arg( 'range' ) );
		$key   = in_array( $key, self::RANGES, true ) ? $key : '7d';
		$today = current_time( 'Y-m-d' );
		$day   = function ( $date, $mod ) {
			return gmdate( 'Y-m-d', strtotime( $date . ' ' . $mod ) );
		};
		switch ( $key ) {
			case 'today':
				$r = array( $today, $today, __( 'Today', 'laghavi-performance-insight-for-woocommerce' ) );
				break;
			case 'yesterday':
				$y = $day( $today, '-1 day' );
				$r = array( $y, $y, __( 'Yesterday', 'laghavi-performance-insight-for-woocommerce' ) );
				break;
			case '30d':
				$r = array( $day( $today, '-29 days' ), $today, __( 'Last 30 days', 'laghavi-performance-insight-for-woocommerce' ) );
				break;
			case 'month':
				$r = array( substr( $today, 0, 8 ) . '01', $today, __( 'This month', 'laghavi-performance-insight-for-woocommerce' ) );
				break;
			case 'lastmonth':
				$first = $day( substr( $today, 0, 8 ) . '01', '-1 month' );
				$r     = array( $first, $day( substr( $today, 0, 8 ) . '01', '-1 day' ), __( 'Last month', 'laghavi-performance-insight-for-woocommerce' ) );
				break;
			case 'year':
				$r = array( substr( $today, 0, 4 ) . '-01-01', $today, __( 'This year', 'laghavi-performance-insight-for-woocommerce' ) );
				break;
			case 'custom':
				$from = sanitize_text_field( self::view_arg( 'from' ) );
				$to   = sanitize_text_field( self::view_arg( 'to' ) );
				$ok   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to );
				if ( $ok ) {
					$from = min( $from, $to, $today );
					$to   = min( max( $from, $to ), $today );
					$from = max( $from, $day( $to, '-730 days' ) );
					$r    = array( $from, $to, $from === $to ? date_i18n( get_option( 'date_format' ), strtotime( $from ) ) : date_i18n( 'j M Y', strtotime( $from ) ) . ' – ' . date_i18n( 'j M Y', strtotime( $to ) ) );
					break;
				}
				$key = '7d';
				// Falls through to the default when the custom dates are missing.
			default:
				$r = array( $day( $today, '-6 days' ), $today, __( 'Last 7 days', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		$days        = (int) round( ( strtotime( $r[1] ) - strtotime( $r[0] ) ) / DAY_IN_SECONDS ) + 1;
		$r[3]        = $day( $r[0], '-' . $days . ' days' );
		$r[4]        = $day( $r[0], '-1 day' );
		$r[5]        = $key;
		self::$range = $r;
		return $r;
	}

	/**
	 * Query args that keep the chosen period on every link.
	 *
	 * @return array
	 */
	private static function range_args() {
		$r = self::range();
		if ( '7d' === $r[5] ) {
			return array();
		}
		return 'custom' === $r[5] ? array(
			'range' => 'custom',
			'from'  => $r[0],
			'to'    => $r[1],
		) : array( 'range' => $r[5] );
	}

	/**
	 * Period picker above a report.
	 *
	 * @param string $page Current screen.
	 */
	private static function range_bar( $page ) {
		$r      = self::range();
		$labels = array(
			'today'     => __( 'Today', 'laghavi-performance-insight-for-woocommerce' ),
			'yesterday' => __( 'Yesterday', 'laghavi-performance-insight-for-woocommerce' ),
			'7d'        => __( '7 days', 'laghavi-performance-insight-for-woocommerce' ),
			'30d'       => __( '30 days', 'laghavi-performance-insight-for-woocommerce' ),
			'month'     => __( 'This month', 'laghavi-performance-insight-for-woocommerce' ),
			'lastmonth' => __( 'Last month', 'laghavi-performance-insight-for-woocommerce' ),
			'year'      => __( 'This year', 'laghavi-performance-insight-for-woocommerce' ),
		);
		$keep   = array_filter(
			array(
				'item' => sanitize_text_field( self::view_arg( 'item' ) ),
				'tab'  => sanitize_key( self::view_arg( 'tab' ) ),
			)
		);
		echo '<div class="lgpi-range"><div class="lgpi-filters">';
		foreach ( $labels as $k => $l ) {
			$args = array_merge(
				$keep,
				array(
					'range' => $k,
					'from'  => '',
					'to'    => '',
				)
			);
			echo '<a class="' . esc_attr( $r[5] === $k ? 'on' : '' ) . '" href="' . esc_url( self::url( $page, $args ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</div><form method="get" class="lgpi-custom" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( $page ) . '"><input type="hidden" name="range" value="custom"><input type="hidden" name="_lgpi" value="' . esc_attr( wp_create_nonce( 'lgpi_view' ) ) . '">';
		foreach ( $keep as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<label>' . esc_html__( 'From', 'laghavi-performance-insight-for-woocommerce' ) . ' <input type="date" name="from" value="' . esc_attr( $r[0] ) . '" max="' . esc_attr( current_time( 'Y-m-d' ) ) . '"></label> <label>' . esc_html__( 'to', 'laghavi-performance-insight-for-woocommerce' ) . ' <input type="date" name="to" value="' . esc_attr( $r[1] ) . '" max="' . esc_attr( current_time( 'Y-m-d' ) ) . '"></label> <button class="button">' . esc_html__( 'Show', 'laghavi-performance-insight-for-woocommerce' ) . '</button></form>';
		echo '<span class="lgpi-muted lgpi-period">' . esc_html( sprintf( /* translators: 1: period, 2: previous period */ __( '%1$s · compared with %2$s', 'laghavi-performance-insight-for-woocommerce' ), self::fmt_period( $r[0], $r[1] ), self::fmt_period( $r[3], $r[4] ) ) ) . '</span></div>';
	}

	/**
	 * "3 – 9 Oct 2026" style dates.
	 *
	 * @param string $a Y-m-d.
	 * @param string $b Y-m-d.
	 * @return string
	 */
	private static function fmt_period( $a, $b ) {
		return $a === $b ? date_i18n( 'j M Y', strtotime( $a ) ) : date_i18n( 'j M', strtotime( $a ) ) . ' – ' . date_i18n( 'j M Y', strtotime( $b ) );
	}

	/**
	 * Change vs the previous period, as "+12%" with a colour class.
	 *
	 * @param float $now    This period.
	 * @param float $before Previous period.
	 * @param bool  $higher_is_better Whether up is good.
	 * @return array [text, class] or [] when there is nothing to compare.
	 */
	public static function delta( $now, $before, $higher_is_better = true ) {
		if ( ! $before ) {
			return array();
		}
		$d = ( $now - $before ) / $before * 100;
		if ( abs( $d ) < 0.5 ) {
			return array( '±0%', 'flat' );
		}
		$good = $higher_is_better ? $d > 0 : $d < 0;
		return array( ( $d > 0 ? '+' : '' ) . number_format_i18n( $d, abs( $d ) < 10 ? 1 : 0 ) . '%', $good ? 'up' : 'down' );
	}

	/**
	 * Seconds as "2m 05s".
	 *
	 * @param float $s Seconds.
	 * @return string
	 */
	public static function duration( $s ) {
		$s = (int) round( $s );
		return $s >= 60 ? sprintf( '%dm %02ds', intdiv( $s, 60 ), $s % 60 ) : $s . 's';
	}

	/**
	 * Country name (WooCommerce's list when available).
	 *
	 * @param string $cc Two-letter code.
	 * @return string
	 */
	private static function country_name( $cc ) {
		static $names = null;
		if ( null === $names ) {
			$names = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
		}
		return isset( $names[ $cc ] ) ? html_entity_decode( $names[ $cc ] ) : $cc;
	}

	/**
	 * Name of a browser language code (en → English).
	 *
	 * @param string $code Two- or three-letter code.
	 * @return string
	 */
	private static function language_name( $code ) {
		$names = array(
			'en' => 'English',
			'ar' => 'Arabic · العربية',
			'fr' => 'French · Français',
			'de' => 'German · Deutsch',
			'es' => 'Spanish · Español',
			'it' => 'Italian · Italiano',
			'pt' => 'Portuguese · Português',
			'nl' => 'Dutch · Nederlands',
			'ru' => 'Russian · Русский',
			'tr' => 'Turkish · Türkçe',
			'fa' => 'Persian · فارسی',
			'ur' => 'Urdu · اردو',
			'hi' => 'Hindi · हिन्दी',
			'bn' => 'Bengali · বাংলা',
			'ml' => 'Malayalam · മലയാളം',
			'ta' => 'Tamil · தமிழ்',
			'tl' => 'Filipino',
			'fil' => 'Filipino',
			'id' => 'Indonesian',
			'ms' => 'Malay',
			'zh' => 'Chinese · 中文',
			'ja' => 'Japanese · 日本語',
			'ko' => 'Korean · 한국어',
			'pl' => 'Polish · Polski',
			'sv' => 'Swedish · Svenska',
			'he' => 'Hebrew · עברית',
			'el' => 'Greek · Ελληνικά',
		);
		return $names[ $code ] ?? strtoupper( $code );
	}

	/**
	 * Flag emoji of a country code.
	 *
	 * @param string $cc Two-letter code.
	 * @return string
	 */
	public static function flag( $cc ) {
		$cc = strtoupper( (string) $cc );
		if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			return '🌐';
		}
		return html_entity_decode( '&#' . ( 0x1F1E6 + ord( $cc[0] ) - 65 ) . ';&#' . ( 0x1F1E6 + ord( $cc[1] ) - 65 ) . ';', ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Link to download a report as CSV for the chosen period.
	 *
	 * @param string $report Report key (see handle_export).
	 * @return string HTML
	 */
	private static function export_link( $report ) {
		$r   = self::range();
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'lgpi_export',
					'report' => $report,
					'from'   => $r[0],
					'to'     => $r[1],
				),
				admin_url( 'admin-post.php' )
			),
			'lgpi_export'
		);
		return '<a class="lgpi-export" href="' . esc_url( $url ) . '"><span class="dashicons dashicons-download"></span>' . esc_html__( 'CSV', 'laghavi-performance-insight-for-woocommerce' ) . '</a>';
	}

	/**
	 * The plugin screen being shown (set by WordPress from the menu slug).
	 *
	 * @return string
	 */
	private static function current_page() {
		global $plugin_page;
		return is_string( $plugin_page ) ? sanitize_key( $plugin_page ) : '';
	}

	/**
	 * A view filter from the URL (item, type, paged, message). Only read when the link's nonce
	 * is valid and the user may see our screens; otherwise the default is used.
	 *
	 * @param string $key Query arg.
	 * @return string Unslashed raw value; callers sanitize it for their own use.
	 */
	public static function view_arg( $key ) {
		if ( ! current_user_can( self::VIEW ) || ! isset( $_GET['_lgpi'], $_GET[ $key ] ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_lgpi'] ) ), 'lgpi_view' ) ) {
			return '';
		}
		return is_scalar( $_GET[ $key ] ) ? (string) wp_unslash( $_GET[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by each caller.
	}

	/**
	 * Money in the store currency.
	 *
	 * @param float $v Amount.
	 * @return string
	 */
	public static function money( $v ) {
		return function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $v ) ) : number_format_i18n( $v, 2 );
	}

	/**
	 * Name that opens the page's detail screen inside the plugin, plus a small link to the live page.
	 *
	 * @param string $page  Screen to open it on.
	 * @param string $type  Object type.
	 * @param int    $id    Object ID.
	 * @param string $label Name.
	 * @param string $link  Live URL.
	 * @return string HTML
	 */
	private static function item_link( $page, $type, $id, $label, $link ) {
		$html = $id ? '<a class="lgpi-open" href="' . esc_url( self::url( $page, array( 'item' => $type . ':' . $id ) ) ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
		if ( $link ) {
			$html .= ' <a class="lgpi-ext" href="' . esc_url( $link ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'Open the live page', 'laghavi-performance-insight-for-woocommerce' ) . '">↗</a>';
		}
		return $html;
	}

	/**
	 * Name + link of an object.
	 *
	 * @param string $type Type.
	 * @param int    $id   ID.
	 * @return array [label, url]
	 */
	public static function object_label( $type, $id ) {
		if ( 'home' === $type ) {
			return array( __( 'Home page (latest posts)', 'laghavi-performance-insight-for-woocommerce' ), home_url( '/' ) );
		}
		if ( 'search' === $type ) {
			return array( __( 'Site search results', 'laghavi-performance-insight-for-woocommerce' ), '' );
		}
		if ( 'archive' === $type ) {
			return array( __( 'Other archives', 'laghavi-performance-insight-for-woocommerce' ), '' );
		}
		if ( taxonomy_exists( $type ) ) {
			$t = get_term( $id, $type );
			return ( $t && ! is_wp_error( $t ) ) ? array( $t->name, get_term_link( $t ) ) : array( '#' . $id, '' );
		}
		$t = get_the_title( $id );
		return array( '' !== $t ? $t : '#' . $id, get_permalink( $id ) );
	}

	/**
	 * Human label of a type.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	private static function type_label( $type ) {
		$map = array(
			'page'        => __( 'Page', 'laghavi-performance-insight-for-woocommerce' ),
			'post'        => __( 'Post', 'laghavi-performance-insight-for-woocommerce' ),
			'product'     => __( 'Product', 'laghavi-performance-insight-for-woocommerce' ),
			'product_cat' => __( 'Product category', 'laghavi-performance-insight-for-woocommerce' ),
			'product_tag' => __( 'Product tag', 'laghavi-performance-insight-for-woocommerce' ),
			'category'    => __( 'Category', 'laghavi-performance-insight-for-woocommerce' ),
			'post_tag'    => __( 'Tag', 'laghavi-performance-insight-for-woocommerce' ),
			'shop'        => __( 'Shop', 'laghavi-performance-insight-for-woocommerce' ),
			'home'        => __( 'Home', 'laghavi-performance-insight-for-woocommerce' ),
			'search'      => __( 'Search', 'laghavi-performance-insight-for-woocommerce' ),
			'archive'     => __( 'Archive', 'laghavi-performance-insight-for-woocommerce' ),
		);
		if ( isset( $map[ $type ] ) ) {
			return $map[ $type ];
		}
		$o = get_post_type_object( $type );
		return $o ? $o->labels->singular_name : ucfirst( $type );
	}

	/**
	 * SEO score badge.
	 *
	 * @param int|null $s Score.
	 */
	private static function score_badge( $s ) {
		if ( null === $s ) {
			echo '<span class="lgpi-muted">–</span>';
			return;
		}
		$cls = $s >= 80 ? 'good' : ( $s >= 55 ? 'ok' : 'bad' );
		echo '<span class="lgpi-score ' . esc_attr( $cls ) . '">' . esc_html( (string) $s ) . '</span>';
	}

	/**
	 * Cached SEO check (refreshed when the post changes).
	 *
	 * @param string $type Type.
	 * @param int    $id   ID.
	 * @return array|null
	 */
	public static function seo( $type, $id ) {
		if ( ! $id ) {
			return null;
		}
		$is_term = taxonomy_exists( $type );
		$ver     = $is_term ? '' : (string) get_post_modified_time( 'U', true, $id );
		$key     = 'lgpi_seo_' . md5( $type . $id . $ver . LGPI_VERSION );
		$c       = get_transient( $key );
		if ( false === $c ) {
			$c = $is_term ? LGPI_SEO::check_term( $id, $type ) : LGPI_SEO::check_post( $id );
			set_transient( $key, $c ? $c : array(), DAY_IN_SECONDS );
		}
		return $c ? $c : null;
	}

	/**
	 * Saved / error messages.
	 */
	private static function notices() {
		$msg = sanitize_text_field( self::view_arg( 'lgpi_msg' ) );
		$err = sanitize_text_field( self::view_arg( 'lgpi_err' ) );
		if ( $msg ) {
			echo '<div class="lgpi-note ok">' . esc_html( $msg ) . '</div>';
		}
		if ( $err ) {
			echo '<div class="lgpi-note err">' . esc_html( $err ) . '</div>';
		}
	}

	/**
	 * KPI tile.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $sub   Sub line.
	 * @param array  $delta [text, class] change vs the previous period.
	 */
	public static function kpi( $label, $value, $sub = '', $delta = array() ) {
		$d = $delta ? '<span class="lgpi-delta ' . esc_attr( $delta[1] ) . '" title="' . esc_attr__( 'vs the previous period', 'laghavi-performance-insight-for-woocommerce' ) . '">' . esc_html( $delta[0] ) . '</span>' : '';
		echo '<div class="lgpi-kpi"><div class="l">' . esc_html( $label ) . '</div><div class="v">' . esc_html( $value ) . '</div>' . ( $d ? '<div class="d">' . wp_kses( $d, array( 'span' => array( 'class' => true, 'title' => true ) ) ) . '</div>' : '' ) . ( $sub ? '<div class="s">' . esc_html( $sub ) . '</div>' : '' ) . '</div>';
	}

	/**
	 * Settings.
	 */
	private static function page_settings() {
		$s     = LGPI_Plugin::settings();
		$roles = wp_roles()->get_names();
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'Settings', 'laghavi-performance-insight-for-woocommerce' ) . '</h2></div></div><div class="lgpi-card"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lgpi_settings' );
		echo '<input type="hidden" name="action" value="lgpi_settings"><table class="form-table"><tr><th>' . esc_html__( 'Don’t count these logged-in users', 'laghavi-performance-insight-for-woocommerce' ) . '</th><td>';
		foreach ( $roles as $k => $n ) {
			echo '<label style="display:inline-block;margin:0 14px 6px 0"><input type="checkbox" name="exclude_roles[]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, (array) $s['exclude_roles'], true ), true, false ) . '> ' . esc_html( translate_user_role( $n ) ) . '</label>';
		}
		echo '</td></tr><tr><th>' . esc_html__( 'Don’t count these IP addresses', 'laghavi-performance-insight-for-woocommerce' ) . '</th><td><textarea name="exclude_ips" rows="3" cols="40" class="code" placeholder="203.0.113.7&#10;192.168.*">' . esc_textarea( implode( "\n", (array) $s['exclude_ips'] ) ) . '</textarea><p class="description">' . esc_html__( 'One per line, for example your office. End with * to skip a whole range.', 'laghavi-performance-insight-for-woocommerce' ) . ' ' . esc_html( sprintf( /* translators: %s: IP address */ __( 'Your address now: %s', 'laghavi-performance-insight-for-woocommerce' ), self::visitor_ip() ) ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Who can see the reports', 'laghavi-performance-insight-for-woocommerce' ) . '</th><td>';
		foreach ( $roles as $k => $n ) {
			if ( 'administrator' === $k ) {
				continue;
			}
			echo '<label style="display:inline-block;margin:0 14px 6px 0"><input type="checkbox" name="view_roles[]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, (array) $s['view_roles'], true ), true, false ) . '> ' . esc_html( translate_user_role( $n ) ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Administrators always can. Others only see the reports, not these settings.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></td></tr><tr><th>' . esc_html__( 'Do Not Track', 'laghavi-performance-insight-for-woocommerce' ) . '</th><td><label><input type="checkbox" name="respect_dnt" value="1" ' . checked( (int) $s['respect_dnt'], 1, false ) . '> ' . esc_html__( 'Don’t count browsers that ask not to be tracked', 'laghavi-performance-insight-for-woocommerce' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Keep history for', 'laghavi-performance-insight-for-woocommerce' ) . '</th><td><select name="keep_days">';
		foreach ( array( 90, 180, 400, 730 ) as $d ) {
			echo '<option value="' . esc_attr( (string) $d ) . '" ' . selected( (int) $s['keep_days'], $d, false ) . '>' . esc_html( sprintf( /* translators: %d: days */ __( '%d days', 'laghavi-performance-insight-for-woocommerce' ), $d ) ) . '</option>';
		}
		echo '</select></td></tr>';
		if ( LGPI_Plugin::has_woo() ) {
			echo '<tr><th>' . esc_html__( 'Keep customer journeys for', 'laghavi-performance-insight-for-woocommerce' ) . '</th><td><select name="journey_days">';
			foreach ( array( 30, 90, 180, 365 ) as $d ) {
				echo '<option value="' . esc_attr( (string) $d ) . '" ' . selected( LGPI_Journey::keep_days(), $d, false ) . '>' . esc_html( sprintf( /* translators: %d: days */ __( '%d days', 'laghavi-performance-insight-for-woocommerce' ), $d ) ) . '</option>';
			}
			echo '</select></td></tr><tr><th>' . esc_html__( 'Abandoned cart after', 'laghavi-performance-insight-for-woocommerce' ) . '</th><td><select name="abandon_after">';
			foreach ( array( 30, 60, 120, 240, 1440 ) as $m ) {
				echo '<option value="' . esc_attr( (string) $m ) . '" ' . selected( (int) $s['abandon_after'], $m, false ) . '>' . esc_html( $m < 120 ? sprintf( /* translators: %d: minutes */ __( '%d minutes', 'laghavi-performance-insight-for-woocommerce' ), $m ) : sprintf( /* translators: %d: hours */ __( '%d hours', 'laghavi-performance-insight-for-woocommerce' ), $m / 60 ) ) . '</option>';
			}
			echo '</select><p class="description">' . esc_html__( 'A cart without an order after this time counts as abandoned.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></td></tr>';
			echo '<tr><th>' . esc_html__( 'Weekly store report', 'laghavi-performance-insight-for-woocommerce' ) . '</th><td><label><input type="checkbox" name="weekly_email" value="1" ' . checked( (int) $s['weekly_email'], 1, false ) . '> ' . esc_html__( 'Email me every Monday: visitors, orders, sales, conversion, best sources and products, abandoned carts and low stock', 'laghavi-performance-insight-for-woocommerce' ) . '</label><br><input type="email" name="weekly_to" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '" value="' . esc_attr( (string) $s['weekly_to'] ) . '"> <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lgpi_weekly_test' ), 'lgpi_weekly_test' ) ) . '">' . esc_html__( 'Send one now', 'laghavi-performance-insight-for-woocommerce' ) . '</a></td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Toolbar', 'laghavi-performance-insight-for-woocommerce' ) . '</th><td><label><input type="checkbox" name="admin_bar" value="1" ' . checked( (int) $s['admin_bar'], 1, false ) . '> ' . esc_html__( 'Show today’s visitors and orders in the admin toolbar', 'laghavi-performance-insight-for-woocommerce' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Delete data when the plugin is deleted', 'laghavi-performance-insight-for-woocommerce' ) . '</th><td><label><input type="checkbox" name="delete_on_uninstall" value="1" ' . checked( (int) get_option( 'lgpi_delete_on_uninstall', 1 ), 1, false ) . '> ' . esc_html__( 'Yes, remove all Performance Insight tables and settings', 'laghavi-performance-insight-for-woocommerce' ) . '</label></td></tr></table><p><button class="button button-primary">' . esc_html__( 'Save', 'laghavi-performance-insight-for-woocommerce' ) . '</button></p></form></div>';
		LGPI_Recommend::render( 'settings' );
	}

	/**
	 * The current visitor's IP address (shown in Settings so you can exclude your own).
	 *
	 * @return string
	 */
	private static function visitor_ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				return trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) )[0] );
			}
		}
		return '';
	}

	/**
	 * Download one report as CSV (any period up to 2 years).
	 */
	public static function handle_export() {
		check_admin_referer( 'lgpi_export' );
		if ( ! current_user_can( self::VIEW ) ) {
			wp_die( esc_html__( 'Not allowed.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		$report = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : '';
		$from   = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to     = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $from > $to ) {
			wp_die( esc_html__( 'Invalid dates.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		$rows = array();
		if ( 'pages' === $report ) {
			$rows[] = array( 'type', 'id', 'title', 'url', 'visitors', 'views', 'add_to_cart', 'from_search', 'from_social', 'from_ai', 'from_paid' );
			foreach ( LGPI_DB::totals( $from, $to ) as $r ) {
				list( $title, $url ) = self::object_label( $r['obj_type'], (int) $r['obj_id'] );
				$rows[]              = array( $r['obj_type'], $r['obj_id'], $title, (string) $url, $r['visitors'], $r['views'], $r['add_to_cart'], $r['src_search'], $r['src_social'], $r['src_ai'], $r['src_paid'] );
			}
		} elseif ( 'products' === $report && LGPI_Plugin::has_woo() ) {
			$rows[] = array( 'id', 'product', 'visitors', 'orders', 'units', 'sales', 'conversion_pct' );
			foreach ( LGPI_Store::product_rows( $from, $to ) as $r ) {
				$rows[] = array( $r['id'], $r['name'], $r['visitors'], $r['orders'], $r['units'], round( (float) $r['revenue'], 2 ), null === $r['conversion'] ? '' : $r['conversion'] );
			}
		} elseif ( 'stock' === $report && LGPI_Plugin::has_woo() ) {
			$rows[] = array( 'id', 'product', 'sku', 'status', 'in_stock', 'low_stock_level', 'sold_30_days', 'days_left', 'visitors', 'hidden_in_shop', 'edit_url' );
			foreach ( LGPI_Stock::rows( $from, $to ) as $r ) {
				$rows[] = array( $r['id'], $r['name'], $r['sku'], $r['state'], null === $r['qty'] ? '' : $r['qty'], $r['low'], $r['sold'], null === $r['days'] ? '' : round( $r['days'], 1 ), $r['visitors'], $r['hidden'] ? 'yes' : 'no', $r['edit'] );
			}
		} elseif ( 'journeys' === $report && LGPI_Plugin::has_woo() ) {
			$rows[] = array( 'ended', 'outcome', 'account_created', 'order', 'value', 'source', 'referrer', 'utm', 'device', 'country', 'pages', 'seconds', 'path', 'cart_products' );
			foreach ( LGPI_Journey::rows( $from, $to ) as $j ) {
				$path = array();
				foreach ( $j['steps'] as $st ) {
					$path[] = self::step_info( $st[0] ?? '' )[0] . ' (' . self::duration( (int) ( $st[1] ?? 0 ) ) . ')';
				}
				$cart = array_map( fn( $id ) => self::object_label( 'product', $id )[0], $j['items'] );
				$rows[] = array( wp_date( 'Y-m-d H:i', (int) $j['ts_end'] ), $j['status'], (int) $j['acct'] ? 'yes' : 'no', $j['order_id'] ? $j['order_id'] : '', $j['value'], $j['src'], $j['ref'], $j['utm'], $j['dev'], $j['cc'], $j['pages_n'], $j['seconds'], implode( ' > ', $path ), implode( ' | ', $cart ) );
			}
		} elseif ( 'channels' === $report ) {
			$t      = self::site_totals( $from, $to );
			$rows[] = array( 'channel', 'visitors' );
			foreach ( self::source_labels() as $k => $l ) {
				$rows[] = array( $l, $t['src'][ $k ] ?? 0 );
			}
		} elseif ( 'visits' === $report ) {
			$rows[] = array( 'day', 'visits', 'bounces', 'pages', 'seconds' );
			foreach ( LGPI_DB::visits_daily( $from, $to ) as $d => $v ) {
				$rows[] = array( $d, $v['visits'], $v['bounces'], $v['pages'], $v['seconds'] );
			}
		} elseif ( isset( LGPI_DB::DIMS[ $report ] ) ) {
			$rows[] = array( LGPI_DB::DIMS[ $report ], 'visitors', 'views' );
			foreach ( LGPI_DB::dims( $report, $from, $to, 5000 ) as $r ) {
				$k = $r['k'];
				if ( in_array( $report, array( 'entry', 'exit' ), true ) ) {
					list( $type, $id ) = array_pad( explode( ':', $k, 2 ), 2, 0 );
					$k                 = self::object_label( $type, (int) $id )[0];
				} elseif ( 'country' === $report ) {
					$k = self::country_name( $k );
				}
				$rows[] = array( $k, $r['visitors'], $r['views'] );
			}
		} else {
			wp_die( esc_html__( 'Unknown report.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="performance-insight-' . $report . '-' . $from . '-' . $to . '.csv"' );
		echo "\xEF\xBB\xBF"; // UTF-8 marker so Excel shows accents and Arabic correctly.
		foreach ( $rows as $row ) {
			echo implode( ',', array_map( array( __CLASS__, 'csv_cell' ), $row ) ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV cells are quoted by csv_cell().
		}
		exit;
	}

	/**
	 * One quoted CSV cell (formula characters neutralised so spreadsheets don't run them).
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	public static function csv_cell( $v ) {
		$v = (string) $v;
		if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) && ! is_numeric( $v ) ) {
			$v = "'" . $v;
		}
		return '"' . str_replace( '"', '""', $v ) . '"';
	}

	/**
	 * Redirect back with a message.
	 *
	 * @param string $page Page.
	 * @param string $msg  Message.
	 * @param bool   $err  Is it an error.
	 */
	public static function back( $page, $msg, $err = false ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                         => $page,
					$err ? 'lgpi_err' : 'lgpi_msg' => rawurlencode( $msg ),
					'_lgpi'                        => wp_create_nonce( 'lgpi_view' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Save settings.
	 */
	public static function handle_settings() {
		check_admin_referer( 'lgpi_settings' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		$roles              = isset( $_POST['exclude_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['exclude_roles'] ) ) : array();
		$s                  = LGPI_Plugin::settings();
		$s['exclude_roles'] = array_values( array_intersect( $roles, array_keys( wp_roles()->get_names() ) ) );
		$s['respect_dnt']   = empty( $_POST['respect_dnt'] ) ? 0 : 1;
		$s['keep_days']     = in_array( (int) ( $_POST['keep_days'] ?? 400 ), array( 90, 180, 400, 730 ), true ) ? (int) $_POST['keep_days'] : 400;
		$ips                = isset( $_POST['exclude_ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exclude_ips'] ) ) : '';
		$s['exclude_ips']   = array_slice( array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $ips ) ), fn( $ip ) => (bool) preg_match( '/^[0-9a-fA-F:.]+\*?$/', $ip ) ) ), 0, 100 );
		$view               = isset( $_POST['view_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['view_roles'] ) ) : array();
		$s['view_roles']    = array_values( array_diff( array_intersect( $view, array_keys( wp_roles()->get_names() ) ), array( 'administrator' ) ) );
		if ( isset( $_POST['journey_days'] ) ) {
			$s['journey_days']  = in_array( (int) $_POST['journey_days'], array( 30, 90, 180, 365 ), true ) ? (int) $_POST['journey_days'] : 90;
			$s['abandon_after'] = in_array( (int) ( $_POST['abandon_after'] ?? 60 ), array( 30, 60, 120, 240, 1440 ), true ) ? (int) $_POST['abandon_after'] : 60;
			$s['weekly_email']  = empty( $_POST['weekly_email'] ) ? 0 : 1;
			$to                 = isset( $_POST['weekly_to'] ) ? sanitize_email( wp_unslash( $_POST['weekly_to'] ) ) : '';
			$s['weekly_to']     = is_email( $to ) ? $to : '';
		}
		$s['admin_bar'] = empty( $_POST['admin_bar'] ) ? 0 : 1;
		update_option( 'lgpi_settings', $s );
		update_option( 'lgpi_delete_on_uninstall', empty( $_POST['delete_on_uninstall'] ) ? 0 : 1 );
		self::back( 'lgpi-settings', __( 'Saved.', 'laghavi-performance-insight-for-woocommerce' ) );
	}
}
