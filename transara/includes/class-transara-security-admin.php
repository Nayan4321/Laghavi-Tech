<?php
/**
 * Security screen: the security score, login protection through Sarala Login Page, scan results,
 * the activity log and the security settings.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Security admin.
 */
class Transara_Security_Admin {

	const PAGE = 'transara-security';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_admin_pages', array( __CLASS__, 'register' ), 20 );
		add_action( 'transara_dashboard_cards', array( __CLASS__, 'dashboard_card' ), 20 );
	}

	/**
	 * Add the page.
	 *
	 * @param array $pages Pages.
	 * @return array
	 */
	public static function register( $pages ) {
		$pages[ self::PAGE ] = array( __( 'Security', 'transara' ), array( __CLASS__, 'page' ) );
		return $pages;
	}

	/*
	 * Sarala.
	 */

	/**
	 * Whether Sarala Login Page is active.
	 *
	 * @return bool
	 */
	public static function sarala_active() {
		return class_exists( 'SARALOPA_Settings' );
	}

	/**
	 * Sarala's login protection settings that matter here.
	 *
	 * @return array label => on.
	 */
	public static function sarala_status() {
		if ( ! self::sarala_active() ) {
			return array();
		}
		$slug = (string) SARALOPA_Settings::get( 'login_slug' );
		return array(
			__( 'Limit login attempts', 'transara' )    => (bool) SARALOPA_Settings::get( 'limit_logins' ),
			__( 'Security question on login', 'transara' ) => (bool) SARALOPA_Settings::get( 'captcha_login' ),
			/* translators: %s: login address */
			( '' !== $slug ? sprintf( __( 'Custom login address: /%s', 'transara' ), $slug ) : __( 'Custom login address', 'transara' ) ) => '' !== $slug,
		);
	}

	/**
	 * The login protection card.
	 */
	private static function sarala_card() {
		echo '<section class="transara-card"><h2>' . esc_html__( 'Login protection', 'transara' ) . '</h2>';
		if ( self::sarala_active() ) {
			echo '<p class="transara-card__lead">' . esc_html__( 'Handled by Sarala Login Page. Its lockouts appear in the activity log below.', 'transara' ) . '</p><table class="transara-table" style="max-width:640px"><tbody>';
			foreach ( self::sarala_status() as $label => $on ) {
				echo '<tr><td>' . esc_html( $label ) . '</td><td><span class="transara-pill ' . ( $on ? 'is-ok' : 'is-warn' ) . '">' . esc_html( $on ? __( 'On', 'transara' ) : __( 'Off', 'transara' ) ) . '</span></td></tr>';
			}
			echo '</tbody></table><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=sarala-login-page' ) ) . '">' . esc_html__( 'Change in Sarala', 'transara' ) . '</a> <span class="description">' . esc_html__( 'Sarala Login Page Pro adds CAPTCHA (Turnstile, reCAPTCHA, hCaptcha), a login page designer and more sign-in providers.', 'transara' ) . '</span></p></section>';
			return;
		}
		echo '<p class="transara-card__lead">' . esc_html__( 'Login protection is off. Sarala Login Page (free, by the makers of Transara) limits login attempts, adds a security question, moves the login page to your own address, adds social log-ins and can make passwords expire. Transara then shows its status here and logs its lockouts.', 'transara' ) . '</p><p>';
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = 'sarala-login-page/sarala-login-page.php';
		if ( isset( get_plugins()[ $file ] ) ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file ) ) . '">' . esc_html__( 'Activate Sarala Login Page', 'transara' ) . '</a>';
			}
		} elseif ( current_user_can( 'install_plugins' ) ) {
			echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=sarala-login-page' ), 'install-plugin_sarala-login-page' ) ) . '">' . esc_html__( 'Install Sarala Login Page', 'transara' ) . '</a>';
		}
		echo '</p></section>';
	}

	/*
	 * Score.
	 */

	/**
	 * Security score with the reasons for lost points.
	 *
	 * @return array score, reasons.
	 */
	public static function score() {
		$score   = 100;
		$reasons = array();
		$take    = static function ( $points, $why ) use ( &$score, &$reasons ) {
			$score    -= $points;
			$reasons[] = array( $points, $why );
		};
		$scan = Transara_Scanner::state();
		$high = 0;
		$vuln = 0;
		foreach ( $scan['findings'] as $f ) {
			if ( 'vuln' === $f['type'] ) {
				++$vuln;
			} elseif ( 'high' === $f['severity'] ) {
				++$high;
			}
		}
		if ( 'never' === $scan['status'] ) {
			$take( 10, __( 'No security scan has run yet.', 'transara' ) );
		}
		if ( $high ) {
			/* translators: %d: number */
			$take( min( 40, 15 * $high ), sprintf( _n( '%d serious scan finding.', '%d serious scan findings.', $high, 'transara' ), $high ) );
		}
		if ( $vuln ) {
			/* translators: %d: number */
			$take( min( 30, 10 * $vuln ), sprintf( _n( '%d known vulnerability in your versions.', '%d known vulnerabilities in your versions.', $vuln, 'transara' ), $vuln ) );
		}
		if ( ! self::sarala_active() ) {
			$take( 15, __( 'Login protection is off (install Sarala Login Page).', 'transara' ) );
		} elseif ( ! SARALOPA_Settings::get( 'limit_logins' ) ) {
			$take( 10, __( 'Limit login attempts is off in Sarala.', 'transara' ) );
		}
		if ( 0 !== strpos( home_url(), 'https://' ) ) {
			$take( 10, __( 'The site does not use HTTPS.', 'transara' ) );
		}
		foreach ( array(
			'disable_file_edit' => array( 5, __( 'The file editor is on.', 'transara' ) ),
			'headers_basic'     => array( 5, __( 'Security headers are off.', 'transara' ) ),
			'hide_users'        => array( 3, __( 'Usernames can be read from the REST API.', 'transara' ) ),
			'block_php_uploads' => array( 4, __( 'PHP can run in the uploads folder.', 'transara' ) ),
		) as $key => $p ) {
			if ( ! Transara_Settings::on( $key ) ) {
				$take( $p[0], $p[1] );
			}
		}
		$h = Transara_Health::results();
		if ( isset( $h['results']['updates'][0] ) && 'ok' !== $h['results']['updates'][0] ) {
			$take( 5, __( 'Updates are waiting.', 'transara' ) );
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) ) {
			$take( 5, __( 'PHP errors are shown to visitors.', 'transara' ) );
		}
		return array(
			'score'   => max( 0, $score ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Dashboard card.
	 */
	public static function dashboard_card() {
		$s = self::score();
		echo '<section class="transara-card transara-stat"><h2>' . esc_html__( 'Security', 'transara' ) . '</h2><p class="transara-big ' . ( $s['score'] >= 85 ? 'is-ok' : ( $s['score'] < 60 ? 'is-bad' : '' ) ) . '">' . esc_html( $s['score'] ) . '<span style="font-size:16px">/100</span></p>';
		$fails = Transara_Activity::failed_logins_today();
		echo '<p>' . esc_html( sprintf( /* translators: %d: number */ _n( '%d failed login today', '%d failed logins today', $fails, 'transara' ), $fails ) ) . '</p><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Open security', 'transara' ) . '</a></p></section>';
	}

	/*
	 * Screen.
	 */

	/**
	 * The screen.
	 */
	public static function page() {
		Transara_Activity::maybe_install();
		Transara_Admin::header( self::PAGE );
		$s = self::score();
		echo '<div class="transara-grid transara-grid--wide"><section class="transara-card transara-stat"><h2>' . esc_html__( 'Security score', 'transara' ) . '</h2><p class="transara-big ' . ( $s['score'] >= 85 ? 'is-ok' : ( $s['score'] < 60 ? 'is-bad' : '' ) ) . '">' . esc_html( $s['score'] ) . '<span style="font-size:16px">/100</span></p>';
		if ( $s['reasons'] ) {
			echo '<ul style="margin:8px 0 0 18px;list-style:disc">';
			foreach ( $s['reasons'] as $r ) {
				echo '<li>' . esc_html( $r[1] ) . ' <span class="description">(−' . (int) $r[0] . ')</span></li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'Everything Transara checks is in order.', 'transara' ) . '</p>';
		}
		echo '</section>';
		self::sarala_card();
		echo '</div>';
		self::scan_card();
		self::activity_card();
		Transara_Admin::settings_form( 'security' );
		Transara_Admin::footer();
	}

	/**
	 * Scan results.
	 */
	private static function scan_card() {
		$scan = Transara_Scanner::state();
		echo '<section class="transara-card"><h2>' . esc_html__( 'Security scan', 'transara' ) . ' ' . Transara_Admin::action_button( 'transara_scan', __( 'Scan now', 'transara' ), 'secondary' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		if ( 'never' === $scan['status'] ) {
			echo '<p>' . esc_html__( 'No scan yet. The first automatic scan runs within a few hours, or click "Scan now".', 'transara' ) . '</p></section>';
			return;
		}
		if ( 'running' === $scan['status'] ) {
			echo '<p class="transara-warn">' . esc_html( sprintf( /* translators: 1: step, 2: files done, 3: files total */ __( 'Scanning (%1$s) %2$d of %3$d files… Reload in a minute.', 'transara' ), $scan['step'], (int) $scan['offset'], (int) $scan['files'] ) ) . '</p>';
		} else {
			$c = $scan['counts'];
			echo '<p class="transara-card__lead">' . esc_html( sprintf( /* translators: 1: time ago, 2: core files, 3: plugins, 4: files, 5: items */ __( 'Last scan %1$s ago: %2$s WordPress files, %3$d plugins compared with WordPress.org, %4$d files searched for malware, %5$d items checked for vulnerabilities.', 'transara' ), human_time_diff( (int) $scan['finished'] ), isset( $c['core'] ) && $c['core'] >= 0 ? number_format_i18n( $c['core'] ) : __( 'no', 'transara' ), isset( $c['plugins'] ) ? (int) $c['plugins'] : 0, isset( $c['files'] ) ? (int) $c['files'] : 0, isset( $c['vulns'] ) ? (int) $c['vulns'] : 0 ) ) . '</p>';
		}
		if ( ! $scan['findings'] ) {
			if ( 'done' === $scan['status'] ) {
				echo '<p><span class="transara-pill is-ok">' . esc_html__( 'Nothing found', 'transara' ) . '</span></p>';
			}
			echo '</section>';
			return;
		}
		$types = array(
			'core_modified'   => __( 'Changed WordPress file', 'transara' ),
			'core_unknown'    => __( 'Unknown file in WordPress folders', 'transara' ),
			'plugin_modified' => __( 'Changed plugin file', 'transara' ),
			'malware'         => __( 'Possible malware', 'transara' ),
			'upload_php'      => __( 'PHP in uploads', 'transara' ),
			'vuln'            => __( 'Known vulnerability', 'transara' ),
		);
		echo '<table class="transara-table"><thead><tr><th>' . esc_html__( 'Severity', 'transara' ) . '</th><th>' . esc_html__( 'Type', 'transara' ) . '</th><th>' . esc_html__( 'File or item', 'transara' ) . '</th><th>' . esc_html__( 'What was found', 'transara' ) . '</th><th></th></tr></thead><tbody>';
		$order = array(
			'high'   => 0,
			'medium' => 1,
			'low'    => 2,
		);
		$list  = $scan['findings'];
		usort(
			$list,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a['severity'] ] - $order[ $b['severity'] ];
			}
		);
		foreach ( $list as $f ) {
			$cls = 'high' === $f['severity'] ? 'is-bad' : ( 'medium' === $f['severity'] ? 'is-warn' : '' );
			echo '<tr><td><span class="transara-pill ' . esc_attr( $cls ) . '">' . esc_html( ucfirst( $f['severity'] ) ) . '</span></td><td>' . esc_html( isset( $types[ $f['type'] ] ) ? $types[ $f['type'] ] : $f['type'] ) . '</td><td><code>' . esc_html( $f['item'] ) . '</code></td><td>' . esc_html( $f['message'] ) . '</td><td>';
			if ( 'vuln' !== $f['type'] ) {
				echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=transara_scan_ignore&key=' . $f['key'] ), 'transara_scan_ignore' ) ) . '" data-transara-confirm="' . esc_attr__( 'Mark this file as safe? Only do this if you know why it differs.', 'transara' ) . '">' . esc_html__( 'Mark as safe', 'transara' ) . '</a>';
			} else {
				echo '<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Updates', 'transara' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'A changed file is not always malware (some hosts and plugins change files), but a file you didn\'t change on purpose should be replaced with a fresh copy.', 'transara' ) . '</p></section>';
	}

	/**
	 * Activity log.
	 */
	private static function activity_card() {
		$ok    = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'transara_activity' );
		$page  = $ok && isset( $_GET['log'] ) ? max( 1, absint( $_GET['log'] ) ) : 1;
		$event = $ok && isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
		$data  = Transara_Activity::entries( $page, 30, $event );
		$names = Transara_Activity::labels();
		$link  = static function ( $args ) {
			return wp_nonce_url( add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) ), 'transara_activity' ) . '#transara-activity-log';
		};
		echo '<section class="transara-card" id="transara-activity-log"><h2>' . esc_html__( 'Activity log', 'transara' ) . '</h2><p>';
		echo '<a href="' . esc_url( $link( array() ) ) . '"' . ( '' === $event ? ' style="font-weight:700"' : '' ) . '>' . esc_html__( 'All', 'transara' ) . '</a>';
		foreach ( array( 'login_failed', 'login', 'user_created', 'role_changed', 'plugin_on', 'updated', 'lockout' ) as $e ) {
			echo ' · <a href="' . esc_url( $link( array( 'event' => $e ) ) ) . '"' . ( $e === $event ? ' style="font-weight:700"' : '' ) . '>' . esc_html( $names[ $e ] ) . '</a>';
		}
		echo '</p>';
		if ( ! $data['rows'] ) {
			echo '<p>' . esc_html__( 'Nothing logged yet.', 'transara' ) . '</p></section>';
			return;
		}
		echo '<table class="transara-table"><thead><tr><th>' . esc_html__( 'When', 'transara' ) . '</th><th>' . esc_html__( 'Who', 'transara' ) . '</th><th>' . esc_html__( 'What', 'transara' ) . '</th><th>' . esc_html__( 'Item', 'transara' ) . '</th><th>' . esc_html__( 'IP address', 'transara' ) . '</th></tr></thead><tbody>';
		foreach ( $data['rows'] as $r ) {
			$bad = in_array( $r->event, array( 'login_failed', 'lockout' ), true );
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $r->time ) ) . '</td><td>' . esc_html( '' !== $r->user_login ? $r->user_login : '—' ) . '</td><td><span class="transara-pill ' . ( $bad ? 'is-warn' : '' ) . '">' . esc_html( isset( $names[ $r->event ] ) ? $names[ $r->event ] : $r->event ) . '</span></td><td>' . esc_html( $r->object ) . ( '' !== $r->details ? '<br /><span class="description">' . esc_html( $r->details ) . '</span>' : '' ) . '</td><td>' . esc_html( $r->ip ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $data['total'] / 30 );
		if ( $pages > 1 ) {
			echo '<p>';
			if ( $page > 1 ) {
				echo '<a class="button" href="' . esc_url( $link( array( 'log' => $page - 1, 'event' => $event ) ) ) . '">← ' . esc_html__( 'Newer', 'transara' ) . '</a> ';
			}
			echo '<span class="description">' . esc_html( sprintf( /* translators: 1: page, 2: pages */ __( 'Page %1$d of %2$d', 'transara' ), $page, $pages ) ) . '</span>';
			if ( $page < $pages ) {
				echo ' <a class="button" href="' . esc_url( $link( array( 'log' => $page + 1, 'event' => $event ) ) ) . '">' . esc_html__( 'Older', 'transara' ) . ' →</a>';
			}
			echo '</p>';
		}
		echo '</section>';
	}
}
