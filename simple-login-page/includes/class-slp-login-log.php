<?php
/**
 * Records failed login attempts and shows them to admins with IP, device and location.
 *
 * @package SimpleLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Failed login log.
 */
class SLP_Login_Log {

	const DB_VERSION = '1';
	const PAGE       = 'simple-login-page-log';
	const PER_PAGE   = 50;
	const MAX_ROWS   = 20000;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( get_option( 'slp_log_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_text' ) );
		add_action( 'admin_post_slp_clear_log', array( __CLASS__, 'clear' ) );
		add_action( 'slp_prune_log', array( __CLASS__, 'prune' ) );
		if ( ! wp_next_scheduled( 'slp_prune_log' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'slp_prune_log' );
		}

		if ( SLP_Settings::get( 'log_failed_logins' ) ) {
			add_action( 'wp_login_failed', array( __CLASS__, 'record_password_failure' ), 10, 2 );
			add_action( 'slp_social_login_failed', array( __CLASS__, 'record_social_failure' ), 10, 3 );
		}
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'slp_login_log';
	}

	/**
	 * Create or update the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				username varchar(191) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ip varchar(45) NOT NULL DEFAULT '',
				user_agent varchar(255) NOT NULL DEFAULT '',
				reason varchar(50) NOT NULL DEFAULT '',
				source varchar(30) NOT NULL DEFAULT '',
				location varchar(191) DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY ip (ip)
			) {$charset};"
		);
		update_option( 'slp_log_db_version', self::DB_VERSION );
	}

	/**
	 * Record a failed password login (wp-login.php, WooCommerce, XML-RPC, application passwords).
	 *
	 * @param string        $username What was typed as the username or email.
	 * @param WP_Error|null $error    Why it failed (WordPress 5.4+).
	 */
	public static function record_password_failure( $username, $error = null ) {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only detecting which form was used.
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			$source = 'xmlrpc';
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$source = 'rest';
		} elseif ( isset( $_POST['woocommerce-login-nonce'] ) ) {
			$source = 'woocommerce';
		} elseif ( 'wp-login.php' === $pagenow ) {
			$source = 'wp-login';
		} else {
			$source = 'other';
		}
		// phpcs:enable

		$reason = is_wp_error( $error ) ? $error->get_error_code() : 'failed';
		$user   = is_email( $username ) ? get_user_by( 'email', $username ) : get_user_by( 'login', $username );

		self::insert( (string) $username, $user ? $user->ID : 0, $reason, $source );
	}

	/**
	 * Record a refused social login.
	 *
	 * @param string $provider Provider ID.
	 * @param string $code     Error code.
	 * @param string $email    Email the provider shared.
	 */
	public static function record_social_failure( $provider, $code, $email ) {
		$user = $email ? get_user_by( 'email', $email ) : false;
		self::insert( (string) $email, $user ? $user->ID : 0, 'social_' . $code, 'social-' . $provider );
	}

	/**
	 * Save one attempt.
	 *
	 * @param string $username Username or email tried.
	 * @param int    $user_id  Matching user, or 0.
	 * @param string $reason   Error code.
	 * @param string $source   Where the attempt came from.
	 */
	private static function insert( $username, $user_id, $reason, $source ) {
		global $wpdb;

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// Free location from Cloudflare when the site is behind it; otherwise it is looked up later, if enabled.
		$location = null;
		if ( isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $country ) && 'XX' !== $country ) {
				$location = $country;
			}
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom log table.
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'username'   => substr( sanitize_text_field( $username ), 0, 191 ),
				'user_id'    => (int) $user_id,
				'ip'         => self::client_ip(),
				'user_agent' => substr( $user_agent, 0, 255 ),
				'reason'     => substr( sanitize_key( $reason ), 0, 50 ),
				'source'     => substr( sanitize_key( $source ), 0, 30 ),
				'location'   => $location,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		// Keep a bot flood from filling the database.
		if ( 1 === wp_rand( 1, 100 ) ) {
			self::prune();
		}
	}

	/**
	 * The visitor's IP, following the "IP address source" setting.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$headers = array(
			'cloudflare' => 'HTTP_CF_CONNECTING_IP',
			'forwarded'  => 'HTTP_X_FORWARDED_FOR',
			'real_ip'    => 'HTTP_X_REAL_IP',
		);
		$source  = SLP_Settings::get( 'ip_source' );
		$ip      = '';

		if ( isset( $headers[ $source ], $_SERVER[ $headers[ $source ] ] ) ) {
			// X-Forwarded-For can be a list; the first entry is the original client.
			$list = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $headers[ $source ] ] ) ) );
			$ip   = trim( $list[0] );
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Delete entries older than the retention period, and the oldest ones above the row cap.
	 */
	public static function prune() {
		global $wpdb;
		$table = self::table();
		$days  = max( 1, (int) SLP_Settings::get( 'log_retention_days' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom log table; the name is not user input.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );

		$cutoff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) );
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) );
		}
		// phpcs:enable
	}

	/**
	 * Delete every entry (the "Clear log" button).
	 */
	public static function clear() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'simple-login-page' ) );
		}
		check_admin_referer( 'slp_clear_log' );
		$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Custom log table.
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&cleared=1' ) );
		exit;
	}

	/**
	 * Suggested text for the site's privacy policy (Settings > Privacy).
	 */
	public static function privacy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Simple Login Page', 'simple-login-page' ),
			wp_kses_post(
				'<p>' . __( 'When a login attempt on this site fails, we record the username or email that was entered, the IP address, the browser\'s user agent and the time, to protect the site from attacks. These records are kept for a limited time and then deleted automatically.', 'simple-login-page' ) . '</p>' .
				'<p>' . __( 'If you log in with Google, Facebook, Apple, Microsoft or LinkedIn, we receive your name, email address and account ID from that service and store the account ID so you can log in with it again.', 'simple-login-page' ) . '</p>'
			)
		);
	}

	/**
	 * "Failed logins" submenu.
	 */
	public static function menu() {
		add_submenu_page(
			SLP_Settings::PAGE,
			__( 'Failed logins', 'simple-login-page' ),
			__( 'Failed logins', 'simple-login-page' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Turn a user agent into "Chrome on Windows" and a device type.
	 *
	 * @param string $ua User agent.
	 * @return array{0: string, 1: string} Browser and OS, device type.
	 */
	public static function describe_device( $ua ) {
		if ( '' === trim( $ua ) ) {
			return array( __( 'Unknown (no browser info)', 'simple-login-page' ), __( 'Unknown', 'simple-login-page' ) );
		}

		$scripts = array(
			'curl'            => 'curl',
			'wget'            => 'Wget',
			'python'          => 'Python script',
			'go-http-client'  => 'Go script',
			'okhttp'          => 'OkHttp',
			'java/'           => 'Java',
			'libwww-perl'     => 'Perl script',
			'wordpress/'      => 'WordPress',
			'bot'             => 'Bot',
			'spider'          => 'Bot',
			'crawler'         => 'Bot',
			'headlesschrome'  => 'Headless Chrome',
		);
		$lower   = strtolower( $ua );
		foreach ( $scripts as $needle => $label ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return array( $label, __( 'Bot or script', 'simple-login-page' ) );
			}
		}

		$browsers = array(
			'Edg/'           => 'Edge',
			'OPR/'           => 'Opera',
			'SamsungBrowser' => 'Samsung Internet',
			'Firefox/'       => 'Firefox',
			'FxiOS'          => 'Firefox',
			'CriOS'          => 'Chrome',
			'Chrome/'        => 'Chrome',
			'Safari/'        => 'Safari',
			'MSIE'           => 'Internet Explorer',
			'Trident/'       => 'Internet Explorer',
		);
		$browser  = __( 'Unknown browser', 'simple-login-page' );
		foreach ( $browsers as $needle => $label ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$browser = $label;
				break;
			}
		}

		$systems = array(
			'iPhone'     => 'iPhone',
			'iPad'       => 'iPad',
			'Android'    => 'Android',
			'Windows'    => 'Windows',
			'Mac OS X'   => 'macOS',
			'Macintosh'  => 'macOS',
			'CrOS'       => 'ChromeOS',
			'Linux'      => 'Linux',
		);
		$os       = '';
		foreach ( $systems as $needle => $label ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$os = $label;
				break;
			}
		}

		if ( false !== strpos( $ua, 'iPad' ) || ( false !== strpos( $ua, 'Android' ) && false === strpos( $ua, 'Mobile' ) ) ) {
			$type = __( 'Tablet', 'simple-login-page' );
		} elseif ( false !== strpos( $ua, 'Mobi' ) || false !== strpos( $ua, 'iPhone' ) ) {
			$type = __( 'Mobile', 'simple-login-page' );
		} else {
			$type = __( 'Desktop', 'simple-login-page' );
		}

		/* translators: 1: browser name, 2: operating system. */
		$name = '' !== $os ? sprintf( __( '%1$s on %2$s', 'simple-login-page' ), $browser, $os ) : $browser;
		return array( $name, $type );
	}

	/**
	 * Readable reason for an error code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function reason_label( $code ) {
		$labels = array(
			'incorrect_password'          => __( 'Wrong password', 'simple-login-page' ),
			'invalid_username'            => __( 'Unknown username', 'simple-login-page' ),
			'invalid_email'               => __( 'Unknown email', 'simple-login-page' ),
			'empty_username'              => __( 'Username left empty', 'simple-login-page' ),
			'empty_password'              => __( 'Password left empty', 'simple-login-page' ),
			'slp_captcha'                 => __( 'Wrong security answer', 'simple-login-page' ),
			'spammer_account'             => __( 'Account marked as spam', 'simple-login-page' ),
			'social_email_unverified'     => __( 'Social login: email not verified', 'simple-login-page' ),
			'social_registration_disabled' => __( 'Social login: sign-ups off', 'simple-login-page' ),
			'social_no_email'             => __( 'Social login: no email shared', 'simple-login-page' ),
			'social_blocked'              => __( 'Social login: account blocked', 'simple-login-page' ),
			'social_failed'               => __( 'Social login: failed', 'simple-login-page' ),
		);
		return isset( $labels[ $code ] ) ? $labels[ $code ] : $code;
	}

	/**
	 * Readable name for where the attempt came from.
	 *
	 * @param string $source Source code.
	 * @return string
	 */
	public static function source_label( $source ) {
		if ( 0 === strpos( $source, 'social-' ) ) {
			return SLP_Providers::label( substr( $source, 7 ) );
		}
		$labels = array(
			'wp-login'    => __( 'Login page', 'simple-login-page' ),
			'woocommerce' => __( 'WooCommerce', 'simple-login-page' ),
			'xmlrpc'      => 'XML-RPC',
			'rest'        => __( 'REST API', 'simple-login-page' ),
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : __( 'Other', 'simple-login-page' );
	}

	/**
	 * Look up city and country for IPs on the current page that have no location yet.
	 *
	 * Runs only when the admin turned "Look up location" on, and only when the panel is viewed,
	 * so failed logins themselves never wait on an outside service.
	 *
	 * @param array $rows Rows being shown.
	 */
	private static function fill_locations( $rows ) {
		global $wpdb;
		if ( ! SLP_Settings::get( 'geo_lookup' ) ) {
			return;
		}

		$ips = array();
		foreach ( $rows as $row ) {
			if ( null === $row->location && '' !== $row->ip ) {
				$ips[ $row->ip ] = true;
			}
		}

		$token = (string) SLP_Settings::get( 'geo_token' );
		foreach ( array_slice( array_keys( $ips ), 0, 15 ) as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				$location = __( 'Private network', 'simple-login-page' );
			} else {
				$cached = get_transient( 'slp_geo_' . md5( $ip ) );
				if ( false !== $cached ) {
					$location = $cached;
				} else {
					$url      = 'https://ipinfo.io/' . rawurlencode( $ip ) . '/json' . ( '' !== $token ? '?token=' . rawurlencode( $token ) : '' );
					$response = wp_remote_get( $url, array( 'timeout' => 4 ) );
					$data     = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
					if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) ) {
						// Try again on a later page view.
						continue;
					}
					$parts    = array_filter( array( isset( $data['city'] ) ? $data['city'] : '', isset( $data['region'] ) ? $data['region'] : '', isset( $data['country'] ) ? $data['country'] : '' ) );
					$location = sanitize_text_field( implode( ', ', array_unique( $parts ) ) );
					set_transient( 'slp_geo_' . md5( $ip ), $location, WEEK_IN_SECONDS );
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom log table.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET location = %s WHERE ip = %s AND location IS NULL', $location, $ip ) );
			foreach ( $rows as $row ) {
				if ( $row->ip === $ip && null === $row->location ) {
					$row->location = $location;
				}
			}
		}
	}

	/**
	 * Country code to name, when WordPress or PHP knows it.
	 *
	 * @param string $location Stored location.
	 * @return string
	 */
	private static function location_label( $location ) {
		if ( null === $location || '' === $location ) {
			return '—';
		}
		if ( preg_match( '/^[A-Z]{2}$/', $location ) && class_exists( 'Locale' ) ) {
			$name = Locale::getDisplayRegion( '-' . $location, get_user_locale() );
			return $name ? $name : $location;
		}
		return $location;
	}

	/**
	 * The "Failed logins" admin page.
	 */
	public static function page() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$table = self::table();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters and paging.
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$ip    = isset( $_GET['ip'] ) ? sanitize_text_field( wp_unslash( $_GET['ip'] ) ) : '';
		$ip    = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		// phpcs:enable

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom log table; values are prepared.
		$where = '' !== $ip ? $wpdb->prepare( 'WHERE ip = %s', $ip ) : '';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) );
		$day   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$week  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) );
		$top   = $wpdb->get_results( $wpdb->prepare( "SELECT ip, COUNT(*) AS attempts, MAX(location) AS location FROM {$table} WHERE created_at >= %s AND ip <> '' GROUP BY ip ORDER BY attempts DESC LIMIT 5", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) );
		// phpcs:enable

		self::fill_locations( $rows );
		$pages    = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$base_url = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<div class="wrap slp-admin slp-log">
			<h1><?php esc_html_e( 'Failed logins', 'simple-login-page' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of days. */
					esc_html__( 'Every failed login attempt on this site, with where it came from. Entries are kept for %d days.', 'simple-login-page' ),
					(int) SLP_Settings::get( 'log_retention_days' )
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SLP_Settings::PAGE . '#slp-log-settings' ) ); ?>"><?php esc_html_e( 'Log settings', 'simple-login-page' ); ?></a>
			</p>

			<?php if ( ! empty( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The log was cleared.', 'simple-login-page' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! SLP_Settings::get( 'log_failed_logins' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Logging is turned off, so new failed logins are not being recorded.', 'simple-login-page' ); ?></p></div>
			<?php endif; ?>

			<div class="slp-stats">
				<div class="slp-stat"><span class="slp-stat__value"><?php echo esc_html( number_format_i18n( $day ) ); ?></span><span class="slp-stat__label"><?php esc_html_e( 'Last 24 hours', 'simple-login-page' ); ?></span></div>
				<div class="slp-stat"><span class="slp-stat__value"><?php echo esc_html( number_format_i18n( $week ) ); ?></span><span class="slp-stat__label"><?php esc_html_e( 'Last 7 days', 'simple-login-page' ); ?></span></div>
				<div class="slp-stat slp-stat--wide">
					<span class="slp-stat__label"><?php esc_html_e( 'Most attempts this week', 'simple-login-page' ); ?></span>
					<?php if ( $top ) : ?>
						<ol class="slp-top">
							<?php foreach ( $top as $item ) : ?>
								<li>
									<a href="<?php echo esc_url( add_query_arg( 'ip', $item->ip, $base_url ) ); ?>"><code><?php echo esc_html( $item->ip ); ?></code></a>
									<?php echo esc_html( self::location_label( $item->location ) ); ?>
									&middot;
									<?php
									/* translators: %s: number of attempts. */
									echo esc_html( sprintf( _n( '%s attempt', '%s attempts', (int) $item->attempts, 'simple-login-page' ), number_format_i18n( (int) $item->attempts ) ) );
									?>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p>&mdash;</p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( '' !== $ip ) : ?>
				<p>
					<?php
					/* translators: %s: IP address. */
					echo esc_html( sprintf( __( 'Showing attempts from %s.', 'simple-login-page' ), $ip ) );
					?>
					<a href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Show all', 'simple-login-page' ); ?></a>
				</p>
			<?php endif; ?>

			<table class="widefat striped slp-log__table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Time', 'simple-login-page' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Username tried', 'simple-login-page' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'simple-login-page' ); ?></th>
						<th scope="col"><?php esc_html_e( 'IP address', 'simple-login-page' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Device', 'simple-login-page' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Location', 'simple-login-page' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Where', 'simple-login-page' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No failed logins recorded yet.', 'simple-login-page' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						list( $device, $type ) = self::describe_device( $row->user_agent );
						$time                  = strtotime( $row->created_at . ' UTC' );
						?>
						<tr>
							<td>
								<span title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ); ?>">
									<?php
									/* translators: %s: time difference, e.g. 5 mins. */
									echo esc_html( sprintf( __( '%s ago', 'simple-login-page' ), human_time_diff( $time ) ) );
									?>
								</span>
							</td>
							<td>
								<?php echo esc_html( '' !== $row->username ? $row->username : '—' ); ?>
								<?php if ( $row->user_id && get_userdata( $row->user_id ) ) : ?>
									<br /><a href="<?php echo esc_url( get_edit_user_link( $row->user_id ) ); ?>" class="slp-log__user"><?php esc_html_e( 'Real account', 'simple-login-page' ); ?></a>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( self::reason_label( $row->reason ) ); ?></td>
							<td><a href="<?php echo esc_url( add_query_arg( 'ip', $row->ip, $base_url ) ); ?>"><code><?php echo esc_html( $row->ip ); ?></code></a></td>
							<td>
								<span title="<?php echo esc_attr( $row->user_agent ); ?>"><?php echo esc_html( $device ); ?></span>
								<br /><span class="description"><?php echo esc_html( $type ); ?></span>
							</td>
							<td><?php echo esc_html( self::location_label( $row->location ) ); ?></td>
							<td><?php echo esc_html( self::source_label( $row->source ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="slp-log__footer">
				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg( 'paged', '%#%' ),
									'format'  => '',
									'current' => $paged,
									'total'   => $pages,
								)
							)
						);
						?>
					</div>
				<?php endif; ?>
				<?php if ( $total ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="slp_clear_log" />
						<?php wp_nonce_field( 'slp_clear_log' ); ?>
						<button type="submit" class="button" onclick="return confirm( this.dataset.confirm );" data-confirm="<?php esc_attr_e( 'Delete every entry in the log?', 'simple-login-page' ); ?>"><?php esc_html_e( 'Clear log', 'simple-login-page' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( SLP_Settings::get( 'geo_lookup' ) ) : ?>
				<p class="description"><?php echo wp_kses_post( __( 'Location data by <a href="https://ipinfo.io/" target="_blank" rel="noopener noreferrer">IPinfo</a>. Locations are approximate.', 'simple-login-page' ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
