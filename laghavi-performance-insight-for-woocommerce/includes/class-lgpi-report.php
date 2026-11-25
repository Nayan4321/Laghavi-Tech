<?php
/**
 * Weekly store report email, today's numbers in the admin toolbar, and a WordPress Dashboard widget.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports outside the plugin screens.
 */
class LGPI_Report {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'lgpi_daily', array( __CLASS__, 'maybe_weekly' ), 20 );
		add_action( 'admin_post_lgpi_weekly_test', array( __CLASS__, 'send_test' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_setup' ) );
	}

	/**
	 * Numbers of a period and the one before it.
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array
	 */
	public static function numbers( $since, $until ) {
		$site  = LGPI_Admin::site_totals( $since, $until );
		$store = LGPI_Plugin::has_woo() ? LGPI_Store::totals( $since, $until ) : array(
			'orders'  => 0,
			'revenue' => 0.0,
		);
		return array(
			'visitors'   => (int) $site['visitors'],
			'views'      => (int) $site['views'],
			'orders'     => (int) $store['orders'],
			'sales'      => (float) $store['revenue'],
			'conversion' => $site['visitors'] ? $store['orders'] / $site['visitors'] * 100 : 0,
			'src'        => $site['src'],
		);
	}

	/**
	 * "+12%" / "−5%" against the earlier number.
	 *
	 * @param float $now    Now.
	 * @param float $before Before.
	 * @return string
	 */
	private static function change( $now, $before ) {
		if ( ! $before ) {
			return $now ? '↑ ' . __( 'new', 'laghavi-performance-insight-for-woocommerce' ) : '–';
		}
		$p = ( $now - $before ) / $before * 100;
		return ( $p >= 0 ? '↑ +' : '↓ ' ) . number_format_i18n( $p, 0 ) . '%';
	}

	/**
	 * Send the weekly report on Monday (once a week).
	 */
	public static function maybe_weekly() {
		$s = LGPI_Plugin::settings();
		if ( empty( $s['weekly_email'] ) || '1' !== wp_date( 'N' ) ) {
			return;
		}
		$week = wp_date( 'o-W' );
		if ( get_option( 'lgpi_weekly_sent' ) === $week ) {
			return;
		}
		update_option( 'lgpi_weekly_sent', $week, false );
		self::send();
	}

	/**
	 * "Send one now" from Settings.
	 */
	public static function send_test() {
		check_admin_referer( 'lgpi_weekly_test' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		$ok = self::send();
		LGPI_Admin::back( 'lgpi-settings', $ok ? __( 'The weekly report was sent.', 'laghavi-performance-insight-for-woocommerce' ) : __( 'The email could not be sent. Check that your site can send email (for example with an SMTP plugin).', 'laghavi-performance-insight-for-woocommerce' ), ! $ok );
	}

	/**
	 * Build and send the report of the last 7 full days.
	 *
	 * @return bool
	 */
	public static function send() {
		$s  = LGPI_Plugin::settings();
		$to = is_email( (string) $s['weekly_to'] ) ? (string) $s['weekly_to'] : (string) get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return false;
		}
		$tz    = wp_timezone();
		$until = ( new DateTime( 'yesterday', $tz ) )->format( 'Y-m-d' );
		$since = ( new DateTime( '-7 days', $tz ) )->format( 'Y-m-d' );
		$psin  = ( new DateTime( '-14 days', $tz ) )->format( 'Y-m-d' );
		$puntl = ( new DateTime( '-8 days', $tz ) )->format( 'Y-m-d' );
		$n     = self::numbers( $since, $until );
		$p     = self::numbers( $psin, $puntl );
		$money = fn( $v ) => LGPI_Admin::money( $v );
		$name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$rows  = array(
			array( __( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $n['visitors'] ), self::change( $n['visitors'], $p['visitors'] ) ),
		);
		if ( LGPI_Plugin::has_woo() ) {
			$rows[] = array( __( 'Orders', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $n['orders'] ), self::change( $n['orders'], $p['orders'] ) );
			$rows[] = array( __( 'Sales', 'laghavi-performance-insight-for-woocommerce' ), $money( $n['sales'] ), self::change( $n['sales'], $p['sales'] ) );
			$rows[] = array( __( 'Conversion rate', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $n['conversion'], 2 ) . '%', self::change( $n['conversion'], $p['conversion'] ) );
		}
		$html  = '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:620px;margin:0 auto;color:#1d2327">';
		$html .= '<h2 style="margin:0 0 4px">' . esc_html( sprintf( /* translators: %s: site name */ __( 'Your week at %s', 'laghavi-performance-insight-for-woocommerce' ), $name ) ) . '</h2>';
		$html .= '<p style="color:#646970;margin:0 0 18px">' . esc_html( wp_date( 'j M', strtotime( $since ) ) . ' – ' . wp_date( 'j M Y', strtotime( $until ) ) . ' · ' . __( 'compared with the week before', 'laghavi-performance-insight-for-woocommerce' ) ) . '</p>';
		$html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:18px"><tr>';
		foreach ( $rows as $r ) {
			$col   = 0 === strpos( $r[2], '↓' ) ? '#b32d2e' : '#00753a';
			$html .= '<td style="border:1px solid #e0e0e0;padding:12px;border-radius:6px;vertical-align:top"><div style="font-size:12px;color:#646970">' . esc_html( $r[0] ) . '</div><div style="font-size:22px;font-weight:700">' . esc_html( $r[1] ) . '</div><div style="font-size:12px;color:' . esc_attr( $col ) . '">' . esc_html( $r[2] ) . '</div></td>';
		}
		$html .= '</tr></table>';
		$labels = LGPI_Admin::source_labels();
		$lines  = array();
		if ( LGPI_Plugin::has_woo() ) {
			$by = LGPI_Store::orders_by_source( $since, $until );
			uasort( $by, fn( $a, $b ) => $b['sales'] <=> $a['sales'] );
			if ( $by ) {
				$k       = (string) array_key_first( $by );
				$lines[] = sprintf( /* translators: 1: source, 2: orders, 3: sales */ __( 'Best source: %1$s — %2$s orders, %3$s.', 'laghavi-performance-insight-for-woocommerce' ), $labels[ $k ] ?? $k, number_format_i18n( $by[ $k ]['orders'] ), $money( $by[ $k ]['sales'] ) );
			}
			$j  = LGPI_Journey::rows( $since, $until );
			$jo = count( array_filter( $j, fn( $r ) => 'order' === $r['status'] ) );
			$ja = count( array_filter( $j, fn( $r ) => 'abandoned' === $r['status'] ) );
			if ( $jo + $ja ) {
				$lines[] = sprintf( /* translators: 1: abandoned carts, 2: percent */ __( 'Abandoned carts: %1$s (%2$s of carts became an order).', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $ja ), number_format_i18n( $jo / ( $jo + $ja ) * 100, 0 ) . '%' );
			}
			$c = LGPI_Stock::counts( LGPI_Stock::rows() );
			if ( $c['out'] + $c['low'] + $c['soon'] ) {
				$lines[] = sprintf( /* translators: 1: out, 2: low, 3: soon */ __( 'Stock: %1$d out of stock, %2$d low, %3$d running out within a week.', 'laghavi-performance-insight-for-woocommerce' ), $c['out'], $c['low'], $c['soon'] );
			}
		}
		$ai = array_sum( LGPI_Bots::summary( $since, $until )['ai'] );
		if ( $ai ) {
			$lines[] = sprintf( /* translators: %s: number */ __( 'AI crawlers (ChatGPT, Claude, Perplexity…) read %s of your pages.', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $ai ) );
		}
		if ( $lines ) {
			$html .= '<ul style="padding-left:18px;line-height:1.7">';
			foreach ( $lines as $l ) {
				$html .= '<li>' . esc_html( $l ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( LGPI_Plugin::has_woo() ) {
			$top = array_slice( array_filter( LGPI_Store::product_rows( $since, $until ), fn( $r ) => $r['revenue'] > 0 || $r['visitors'] > 0 ), 0, 5 );
			if ( $top ) {
				$html .= '<h3 style="margin:18px 0 6px">' . esc_html__( 'Top products', 'laghavi-performance-insight-for-woocommerce' ) . '</h3><table style="width:100%;border-collapse:collapse;font-size:14px"><tr style="color:#646970;text-align:left"><th style="padding:6px 0">' . esc_html__( 'Product', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th style="text-align:right">' . esc_html__( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th style="text-align:right">' . esc_html__( 'Orders', 'laghavi-performance-insight-for-woocommerce' ) . '</th><th style="text-align:right">' . esc_html__( 'Sales', 'laghavi-performance-insight-for-woocommerce' ) . '</th></tr>';
				foreach ( $top as $r ) {
					$html .= '<tr style="border-top:1px solid #eee"><td style="padding:6px 0">' . esc_html( $r['name'] ) . '</td><td style="text-align:right">' . esc_html( number_format_i18n( $r['visitors'] ) ) . '</td><td style="text-align:right">' . esc_html( number_format_i18n( $r['orders'] ) ) . '</td><td style="text-align:right">' . esc_html( $money( $r['revenue'] ) ) . '</td></tr>';
				}
				$html .= '</table>';
			}
		}
		$html .= '<p style="margin:22px 0"><a href="' . esc_url( admin_url( 'admin.php?page=lgpi' ) ) . '" style="background:#2271b1;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none">' . esc_html__( 'Open Performance Insight', 'laghavi-performance-insight-for-woocommerce' ) . '</a></p>';
		$html .= '<p style="font-size:12px;color:#8c8f94">' . esc_html__( 'Sent by Performance Insight on your own site. Turn it off in WooCommerce → Performance Insight → Settings.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div>';
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your weekly store report', 'laghavi-performance-insight-for-woocommerce' ), $name );
		return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Today's visitors and orders (cached for 5 minutes).
	 *
	 * @return array
	 */
	public static function today() {
		$t = get_transient( 'lgpi_today' );
		if ( is_array( $t ) ) {
			return $t;
		}
		$d = current_time( 'Y-m-d' );
		$n = self::numbers( $d, $d );
		$t = array(
			'visitors' => $n['visitors'],
			'orders'   => $n['orders'],
			'sales'    => $n['sales'],
			'live'     => count( array_unique( array_column( LGPI_DB::hits_since( time() - 30 * MINUTE_IN_SECONDS, 2000 ), 'v' ) ) ),
		);
		set_transient( 'lgpi_today', $t, 5 * MINUTE_IN_SECONDS );
		return $t;
	}

	/**
	 * Today's numbers in the admin toolbar.
	 *
	 * @param WP_Admin_Bar $bar Toolbar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( LGPI_Admin::VIEW ) || empty( LGPI_Plugin::settings()['admin_bar'] ) ) {
			return;
		}
		$t     = self::today();
		$title = '<span class="ab-icon dashicons dashicons-chart-area" style="top:2px"></span><span class="ab-label">' . esc_html( number_format_i18n( $t['visitors'] ) ) . ( LGPI_Plugin::has_woo() ? ' · ' . esc_html( sprintf( /* translators: %s: orders */ _n( '%s order', '%s orders', $t['orders'], 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $t['orders'] ) ) ) : '' ) . '</span>';
		$bar->add_node(
			array(
				'id'    => 'lgpi-today',
				'title' => $title,
				'href'  => admin_url( 'admin.php?page=lgpi' ),
				'meta'  => array( 'title' => __( 'Today: visitors and orders (Performance Insight)', 'laghavi-performance-insight-for-woocommerce' ) ),
			)
		);
		$items = array(
			'live'     => sprintf( /* translators: %s: visitors */ __( 'On the site now: %s', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $t['live'] ) ),
			'visitors' => sprintf( /* translators: %s: visitors */ __( 'Visitors today: %s', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $t['visitors'] ) ),
		);
		if ( LGPI_Plugin::has_woo() ) {
			$items['sales'] = sprintf( /* translators: %s: sales */ __( 'Sales today: %s', 'laghavi-performance-insight-for-woocommerce' ), LGPI_Admin::money( $t['sales'] ) );
			$items['jour']  = __( 'Customer journeys →', 'laghavi-performance-insight-for-woocommerce' );
		}
		foreach ( $items as $k => $l ) {
			$bar->add_node(
				array(
					'parent' => 'lgpi-today',
					'id'     => 'lgpi-today-' . $k,
					'title'  => esc_html( $l ),
					'href'   => admin_url( 'admin.php?page=' . ( 'jour' === $k ? 'lgpi-journeys' : ( 'visitors' === $k || 'live' === $k ? 'lgpi-visitors' : 'lgpi' ) ) ),
				)
			);
		}
	}

	/**
	 * Register the Dashboard widget.
	 */
	public static function dashboard_setup() {
		if ( current_user_can( LGPI_Admin::VIEW ) ) {
			wp_add_dashboard_widget( 'lgpi_dashboard', __( 'Performance Insight — last 7 days', 'laghavi-performance-insight-for-woocommerce' ), array( __CLASS__, 'dashboard_widget' ) );
		}
	}

	/**
	 * Dashboard widget: the week, today and the latest customer journeys.
	 */
	public static function dashboard_widget() {
		$tz    = wp_timezone();
		$until = current_time( 'Y-m-d' );
		$since = ( new DateTime( '-6 days', $tz ) )->format( 'Y-m-d' );
		$n     = self::numbers( $since, $until );
		$p     = self::numbers( ( new DateTime( '-13 days', $tz ) )->format( 'Y-m-d' ), ( new DateTime( '-7 days', $tz ) )->format( 'Y-m-d' ) );
		$t     = self::today();
		$cells = array( array( __( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $n['visitors'] ), self::change( $n['visitors'], $p['visitors'] ) ) );
		if ( LGPI_Plugin::has_woo() ) {
			$cells[] = array( __( 'Orders', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $n['orders'] ), self::change( $n['orders'], $p['orders'] ) );
			$cells[] = array( __( 'Sales', 'laghavi-performance-insight-for-woocommerce' ), LGPI_Admin::money( $n['sales'] ), self::change( $n['sales'], $p['sales'] ) );
			$cells[] = array( __( 'Conversion', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $n['conversion'], 2 ) . '%', self::change( $n['conversion'], $p['conversion'] ) );
		}
		echo '<div class="lgpi-dw"><div class="lgpi-dw-kpis">';
		foreach ( $cells as $c ) {
			echo '<div><span>' . esc_html( $c[0] ) . '</span><strong>' . esc_html( $c[1] ) . '</strong><em class="' . esc_attr( 0 === strpos( $c[2], '↓' ) ? 'down' : 'up' ) . '">' . esc_html( $c[2] ) . '</em></div>';
		}
		echo '</div><p>' . esc_html( sprintf( /* translators: 1: visitors now, 2: visitors today */ __( '%1$s on the site now · %2$s visitors today', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $t['live'] ), number_format_i18n( $t['visitors'] ) ) ) . '</p>';
		if ( LGPI_Plugin::has_woo() ) {
			$j = array_slice( LGPI_Journey::rows( $since, $until, 5 ), 0, 5 );
			if ( $j ) {
				$outs = LGPI_Admin::outcome_labels();
				echo '<h4>' . esc_html__( 'Latest customer journeys', 'laghavi-performance-insight-for-woocommerce' ) . '</h4><ul class="lgpi-dw-j">';
				foreach ( $j as $r ) {
					echo '<li><span class="lgpi-pill jo-' . esc_attr( $r['status'] ) . '">' . esc_html( $outs[ $r['status'] ] ?? $r['status'] ) . '</span> ' . esc_html( LGPI_Admin::journey_source( $r ) . ' · ' . sprintf( /* translators: 1: pages, 2: time */ _n( '%1$s page, %2$s', '%1$s pages, %2$s', (int) $r['pages_n'], 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( (int) $r['pages_n'] ), LGPI_Admin::duration( (int) $r['seconds'] ) ) ) . ( $r['order_id'] ? ' · ' . esc_html( LGPI_Admin::money( (float) $r['value'] ) ) : '' ) . '</li>';
				}
				echo '</ul>';
			}
		}
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=lgpi' ) ) . '">' . esc_html__( 'Open Performance Insight', 'laghavi-performance-insight-for-woocommerce' ) . '</a>' . ( LGPI_Plugin::has_woo() ? ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=lgpi-journeys' ) ) . '">' . esc_html__( 'Customer journeys', 'laghavi-performance-insight-for-woocommerce' ) . '</a>' : '' ) . '</p></div>';
	}
}
