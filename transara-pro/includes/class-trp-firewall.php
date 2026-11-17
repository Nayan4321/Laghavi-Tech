<?php
/**
 * Firewall: blocks requests that carry attack patterns (SQL injection, script injection, path
 * traversal, PHP files in uploads), known attack tools, blocked IP addresses, visitors sending too
 * many requests a minute, and visitors scanning for pages (many "not found" pages). Runs as soon as
 * plugins load, before themes and most plugins. Pages served from the page cache are not affected.
 *
 * Settings are kept in their own option so they can be read before WordPress is fully loaded.
 *
 * @package TransaraPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Firewall.
 */
class TRP_Firewall {

	const OPTION = 'trp_firewall';
	const LOG    = 'trp_firewall_log';

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'on'       => 0,
				'patterns' => 1,
				'bots'     => 1,
				'uploads'  => 1,
				'rate'     => 0,
				'scan'     => 20,
				'ban'      => 60,
				'block'    => array(),
				'allow'    => array(),
			),
			is_array( $s ) ? $s : array()
		);
	}

	/**
	 * Visitor IP (the same one Transara's activity log uses, so proxies set there work here too).
	 *
	 * @return string
	 */
	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Transara's own filter.
		$ip = (string) apply_filters( 'transara_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Whether an IP is in a list of addresses and ranges (1.2.3.4, 1.2.3.0/24, 2001:db8::/32).
	 *
	 * @param string   $ip   IP.
	 * @param string[] $list List.
	 * @return bool
	 */
	public static function in_list( $ip, $list ) {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input returns false.
		if ( false === $bin ) {
			return false;
		}
		foreach ( (array) $list as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}
			if ( false === strpos( $entry, '/' ) ) {
				if ( $entry === $ip ) {
					return true;
				}
				continue;
			}
			list( $net, $bits ) = explode( '/', $entry, 2 );
			$net_bin            = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input returns false.
			$bits               = (int) $bits;
			if ( false === $net_bin || strlen( $net_bin ) !== strlen( $bin ) || $bits < 0 || $bits > strlen( $bin ) * 8 ) {
				continue;
			}
			$bytes = intdiv( $bits, 8 );
			$rest  = $bits % 8;
			if ( substr( $bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $rest || ( ( ord( $bin[ $bytes ] ) ^ ord( $net_bin[ $bytes ] ) ) & ( 0xFF << ( 8 - $rest ) ) & 0xFF ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * First checks, as soon as plugins load.
	 */
	public static function early() {
		$s = self::settings();
		if ( empty( $s['on'] ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		$ip = self::ip();
		if ( '' === $ip || self::in_list( $ip, $s['allow'] ) ) {
			return;
		}
		if ( self::in_list( $ip, $s['block'] ) ) {
			self::stop( 'blocked_ip', '' );
		}
		if ( get_transient( 'trp_fw_ban_' . md5( $ip ) ) ) {
			self::stop( 'banned', '' );
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only matched against patterns, never output or stored.
		$uri = (string) $uri;
		if ( ! empty( $s['uploads'] ) && preg_match( '#/wp-content/uploads/.*\.(php\d?|phtml|phar|pht)(\?|$|/)#i', rawurldecode( $uri ) ) ) {
			self::stop( 'uploads_php', substr( $uri, 0, 200 ) );
		}
		if ( ! empty( $s['bots'] ) ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
			if ( preg_match( '/sqlmap|nikto|acunetix|nessus|masscan|zgrab|wpscan|nmap scripting|dirbuster|gobuster|fimap|havij|w3af|openvas|netsparker|jorgee/', $ua ) ) {
				self::stop( 'bad_bot', substr( $ua, 0, 120 ) );
			}
		}
		if ( ! empty( $s['patterns'] ) ) {
			$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only matched against patterns.
			$rule  = self::match( strtolower( rawurldecode( rawurldecode( $query . ' ' . (string) wp_parse_url( $uri, PHP_URL_PATH ) ) ) ) );
			if ( '' !== $rule ) {
				self::stop( $rule, substr( $uri, 0, 200 ) );
			}
		}
	}

	/**
	 * Attack pattern in a decoded address.
	 *
	 * @param string $text Lower-case text.
	 * @return string Rule name or ''.
	 */
	public static function match( $text ) {
		$rules = array(
			'traversal' => '#(\.\./|\.\.\\\\){2,}|/etc/passwd|/proc/self/environ|(php|zip|phar|expect|data)://|\bboot\.ini\b#',
			'sql'       => '#union(\s|/\*.*?\*/|\+)+(all(\s|\+)+)?select\b|\bselect\b.{1,80}\bfrom\b.{1,40}\binformation_schema\b|\bsleep\s*\(\s*\d+\s*\)|\bbenchmark\s*\(|\bload_file\s*\(|\binto\s+(out|dump)file\b|\bwaitfor\s+delay\b#',
			'script'    => '#<\s*script\b|<\s*/\s*script|javascript\s*:|<[^>]+\bon(error|load|mouseover|focus)\s*=|<\s*(iframe|object|embed)\b|document\.cookie#',
			'php'       => '#<\?php|\b(base64_decode|eval|assert|system|passthru|shell_exec|proc_open)\s*\(#',
		);
		foreach ( $rules as $name => $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Requests a minute from one visitor (logged-in users are not counted).
	 */
	public static function rate() {
		$s = self::settings();
		if ( empty( $s['on'] ) || empty( $s['rate'] ) || is_user_logged_in() || wp_doing_cron() ) {
			return;
		}
		$ip = self::ip();
		if ( '' === $ip || self::in_list( $ip, $s['allow'] ) ) {
			return;
		}
		$key   = 'trp_fw_r_' . md5( $ip . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 2 * MINUTE_IN_SECONDS );
		if ( $count > (int) $s['rate'] ) {
			self::ban( $ip );
			self::stop( 'rate', $count . ' / min' );
		}
	}

	/**
	 * Count "not found" pages; many in a minute means someone is scanning the site.
	 */
	public static function not_found() {
		$s = self::settings();
		if ( empty( $s['on'] ) || empty( $s['scan'] ) || ! is_404() || is_user_logged_in() ) {
			return;
		}
		$ip = self::ip();
		if ( '' === $ip || self::in_list( $ip, $s['allow'] ) ) {
			return;
		}
		$key   = 'trp_fw_404_' . md5( $ip . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 2 * MINUTE_IN_SECONDS );
		if ( $count > (int) $s['scan'] ) {
			self::ban( $ip );
			self::stop( 'scan', $count . ' × 404 / min' );
		}
	}

	/**
	 * Block an IP for a while.
	 *
	 * @param string $ip IP.
	 */
	private static function ban( $ip ) {
		$s = self::settings();
		set_transient( 'trp_fw_ban_' . md5( $ip ), 1, max( 1, (int) $s['ban'] ) * MINUTE_IN_SECONDS );
	}

	/**
	 * Refuse the request and note it.
	 *
	 * @param string $rule   Rule.
	 * @param string $detail Detail.
	 */
	private static function stop( $rule, $detail ) {
		$log = get_transient( self::LOG );
		$log = is_array( $log ) ? $log : array( 'count' => array(), 'last' => array() );
		$day = gmdate( 'Y-m-d' );
		$log['count'][ $day ] = ( isset( $log['count'][ $day ] ) ? (int) $log['count'][ $day ] : 0 ) + 1;
		$log['count']         = array_slice( $log['count'], -30, null, true );
		array_unshift(
			$log['last'],
			array(
				'time'   => time(),
				'ip'     => self::ip(),
				'rule'   => $rule,
				'detail' => sanitize_text_field( $detail ),
			)
		);
		$log['last'] = array_slice( $log['last'], 0, 50 );
		set_transient( self::LOG, $log, 31 * DAY_IN_SECONDS );
		nocache_headers();
		status_header( 'rate' === $rule || 'scan' === $rule || 'banned' === $rule ? 429 : 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'This request was blocked for security reasons.';
		exit;
	}

	/**
	 * Rule names.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'blocked_ip'  => __( 'Blocked address', 'transara-pro' ),
			'banned'      => __( 'Temporarily blocked', 'transara-pro' ),
			'uploads_php' => __( 'PHP file in uploads', 'transara-pro' ),
			'bad_bot'     => __( 'Attack tool', 'transara-pro' ),
			'traversal'   => __( 'Path traversal', 'transara-pro' ),
			'sql'         => __( 'SQL injection', 'transara-pro' ),
			'script'      => __( 'Script injection', 'transara-pro' ),
			'php'         => __( 'PHP code injection', 'transara-pro' ),
			'rate'        => __( 'Too many requests', 'transara-pro' ),
			'scan'        => __( 'Scanning for pages', 'transara-pro' ),
		);
	}

	/*
	 * Admin (Transara › Security).
	 */

	/**
	 * Hook up the admin side.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'not_found' ), 1 );
		add_action( 'init', array( __CLASS__, 'rate' ), 0 );
		add_action( 'admin_post_trp_firewall', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_trp_firewall_unban', array( __CLASS__, 'unban' ) );
		add_filter( 'transara_admin_pages', array( __CLASS__, 'pages' ) );
	}

	/**
	 * Firewall screen.
	 *
	 * @param array $pages Pages.
	 * @return array
	 */
	public static function pages( $pages ) {
		$pages['transara-firewall'] = array( __( 'Firewall', 'transara-pro' ), array( __CLASS__, 'page' ) );
		return $pages;
	}

	/**
	 * Save.
	 */
	public static function save() {
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara-pro' ), 403 );
		}
		check_admin_referer( 'trp_firewall' );
		$in    = isset( $_POST['fw'] ) && is_array( $_POST['fw'] ) ? wp_unslash( $_POST['fw'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value cleaned below.
		$lines = static function ( $key ) use ( $in ) {
			$out = array();
			foreach ( preg_split( '/[\s,]+/', isset( $in[ $key ] ) ? (string) $in[ $key ] : '' ) as $entry ) {
				$entry = trim( sanitize_text_field( $entry ) );
				$ip    = strtok( $entry, '/' );
				if ( '' !== $entry && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					$out[] = $entry;
				}
			}
			return array_values( array_unique( array_slice( $out, 0, 500 ) ) );
		};
		$s = array(
			'on'       => empty( $in['on'] ) ? 0 : 1,
			'patterns' => empty( $in['patterns'] ) ? 0 : 1,
			'bots'     => empty( $in['bots'] ) ? 0 : 1,
			'uploads'  => empty( $in['uploads'] ) ? 0 : 1,
			'rate'     => isset( $in['rate'] ) ? min( 10000, absint( $in['rate'] ) ) : 0,
			'scan'     => isset( $in['scan'] ) ? min( 1000, absint( $in['scan'] ) ) : 20,
			'ban'      => isset( $in['ban'] ) ? max( 1, min( 10080, absint( $in['ban'] ) ) ) : 60,
			'block'    => $lines( 'block' ),
			'allow'    => $lines( 'allow' ),
		);
		// Never lock out the person saving.
		$me = self::ip();
		if ( '' !== $me && self::in_list( $me, $s['block'] ) ) {
			$s['block'] = array_values( array_diff( $s['block'], array( $me ) ) );
			Transara_Admin::flash( __( 'Your own address was removed from the blocked list.', 'transara-pro' ), 'warning' );
		}
		update_option( self::OPTION, $s );
		Transara_Admin::flash( __( 'Firewall saved.', 'transara-pro' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=transara-firewall' ) );
		exit;
	}

	/**
	 * Lift a temporary block.
	 */
	public static function unban() {
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara-pro' ), 403 );
		}
		check_admin_referer( 'trp_firewall_unban' );
		$ip = isset( $_GET['ip'] ) ? sanitize_text_field( wp_unslash( $_GET['ip'] ) ) : '';
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			delete_transient( 'trp_fw_ban_' . md5( $ip ) );
			delete_transient( 'trp_fw_r_' . md5( $ip . gmdate( 'YmdHi' ) ) );
			delete_transient( 'trp_fw_404_' . md5( $ip . gmdate( 'YmdHi' ) ) );
			/* translators: %s: IP address. */
			Transara_Admin::flash( sprintf( __( '%s can visit the site again.', 'transara-pro' ), $ip ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=transara-firewall' ) );
		exit;
	}

	/**
	 * Screen.
	 */
	public static function page() {
		Transara_Admin::header( 'transara-firewall' );
		$s      = self::settings();
		$log    = get_transient( self::LOG );
		$log    = is_array( $log ) ? $log : array( 'count' => array(), 'last' => array() );
		$week   = 0;
		$labels = self::labels();
		foreach ( $log['count'] as $day => $n ) {
			if ( strtotime( $day ) >= strtotime( '-7 days' ) ) {
				$week += (int) $n;
			}
		}
		echo '<div class="transara-grid"><section class="transara-card transara-stat"><h2>' . esc_html__( 'Blocked in the last 7 days', 'transara-pro' ) . '</h2><p class="transara-big">' . esc_html( number_format_i18n( $week ) ) . '</p><p>' . esc_html( $s['on'] ? __( 'The firewall is on.', 'transara-pro' ) : __( 'The firewall is off.', 'transara-pro' ) ) . '</p></section></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="trp_firewall" />';
		wp_nonce_field( 'trp_firewall' );
		echo '<section class="transara-card"><h2>' . esc_html__( 'Firewall', 'transara-pro' ) . '</h2><p class="transara-card__lead">' . esc_html__( 'Blocks attacks before WordPress loads your theme and other plugins. Pages served from the page cache are plain files and cannot be attacked this way. Logged-in users are never rate-limited.', 'transara-pro' ) . '</p><table class="form-table" role="presentation">';
		$check = static function ( $key, $label, $desc = '' ) use ( $s ) {
			echo '<tr><th scope="row"></th><td><input type="hidden" name="fw[' . esc_attr( $key ) . ']" value="0" /><label class="transara-switch"><input type="checkbox" name="fw[' . esc_attr( $key ) . ']" value="1"' . checked( ! empty( $s[ $key ] ), true, false ) . ' /><span class="transara-switch__ui" aria-hidden="true"></span> ' . esc_html( $label ) . '</label>' . ( '' !== $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' ) . '</td></tr>';
		};
		$check( 'on', __( 'Turn on the firewall', 'transara-pro' ) );
		$check( 'patterns', __( 'Block attack patterns in addresses', 'transara-pro' ), __( 'SQL injection, script injection, PHP code and path traversal in the address or its parameters.', 'transara-pro' ) );
		$check( 'bots', __( 'Block known attack tools', 'transara-pro' ), __( 'Scanners such as sqlmap, Nikto, WPScan and Acunetix.', 'transara-pro' ) );
		$check( 'uploads', __( 'Block PHP files in the uploads folder', 'transara-pro' ), __( 'Uploads are images and documents; a PHP file there is almost always a backdoor.', 'transara-pro' ) );
		echo '<tr><th scope="row"><label for="trp-rate">' . esc_html__( 'Requests a minute', 'transara-pro' ) . '</label></th><td><input type="number" class="small-text" id="trp-rate" name="fw[rate]" min="0" value="' . esc_attr( (string) $s['rate'] ) . '" /> <span>' . esc_html__( 'per visitor (0 = no limit; 120 suits most sites)', 'transara-pro' ) . '</span></td></tr>';
		echo '<tr><th scope="row"><label for="trp-scan">' . esc_html__( '"Not found" pages a minute', 'transara-pro' ) . '</label></th><td><input type="number" class="small-text" id="trp-scan" name="fw[scan]" min="0" value="' . esc_attr( (string) $s['scan'] ) . '" /> <span>' . esc_html__( 'per visitor before they are blocked (0 = off)', 'transara-pro' ) . '</span></td></tr>';
		echo '<tr><th scope="row"><label for="trp-ban">' . esc_html__( 'Block for', 'transara-pro' ) . '</label></th><td><input type="number" class="small-text" id="trp-ban" name="fw[ban]" min="1" value="' . esc_attr( (string) $s['ban'] ) . '" /> <span>' . esc_html__( 'minutes', 'transara-pro' ) . '</span></td></tr>';
		echo '<tr><th scope="row"><label for="trp-block">' . esc_html__( 'Always block', 'transara-pro' ) . '</label></th><td><textarea class="large-text code" rows="3" id="trp-block" name="fw[block]">' . esc_textarea( implode( "\n", $s['block'] ) ) . '</textarea><p class="description">' . esc_html__( 'IP addresses or ranges, one per line (203.0.113.7, 203.0.113.0/24).', 'transara-pro' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="trp-allow">' . esc_html__( 'Never block', 'transara-pro' ) . '</label></th><td><textarea class="large-text code" rows="3" id="trp-allow" name="fw[allow]">' . esc_textarea( implode( "\n", $s['allow'] ) ) . '</textarea>';
		/* translators: %s: IP address. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Your office or monitoring services. Your address now: %s', 'transara-pro' ), self::ip() ) ) . '</p></td></tr>';
		echo '</table><div class="transara-savebar">';
		submit_button( __( 'Save changes', 'transara-pro' ), 'primary', 'submit', false );
		echo '</div></section></form>';

		echo '<section class="transara-card"><h2>' . esc_html__( 'Last blocked requests', 'transara-pro' ) . '</h2>';
		if ( ! $log['last'] ) {
			echo '<p>' . esc_html__( 'Nothing blocked yet.', 'transara-pro' ) . '</p></section>';
			Transara_Admin::footer();
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'transara-pro' ) . '</th><th>' . esc_html__( 'Address', 'transara-pro' ) . '</th><th>' . esc_html__( 'Reason', 'transara-pro' ) . '</th><th>' . esc_html__( 'Request', 'transara-pro' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $log['last'] as $row ) {
			$banned = '' !== $row['ip'] && get_transient( 'trp_fw_ban_' . md5( $row['ip'] ) );
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $row['time'] ) ) . '</td><td><code>' . esc_html( $row['ip'] ) . '</code></td><td>' . esc_html( isset( $labels[ $row['rule'] ] ) ? $labels[ $row['rule'] ] : $row['rule'] ) . '</td><td class="description">' . esc_html( $row['detail'] ) . '</td><td>';
			if ( $banned ) {
				echo '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=trp_firewall_unban&ip=' . rawurlencode( $row['ip'] ) ), 'trp_firewall_unban' ) ) . '">' . esc_html__( 'Unblock', 'transara-pro' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></section>';
		Transara_Admin::footer();
	}
}
