<?php
/**
 * Transara › Pro: connection to the service, results of the checks from outside, incidents, outside-only
 * monitors, test alert, monthly report, and the Pro settings.
 *
 * @package TransaraPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro screen.
 */
class TRP_Admin {

	const PAGE = 'transara-pro';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_admin_pages', array( __CLASS__, 'pages' ) );
		add_action( 'transara_dashboard_cards', array( __CLASS__, 'dashboard_card' ) );
		foreach ( array( 'connect', 'disconnect', 'sync', 'test', 'monitor', 'delete_monitor', 'incident' ) as $action ) {
			add_action( 'admin_post_trp_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Add the screen.
	 *
	 * @param array $pages Pages.
	 * @return array
	 */
	public static function pages( $pages ) {
		$pages[ self::PAGE ] = array( __( 'Pro', 'transara-pro' ), array( __CLASS__, 'page' ) );
		return $pages;
	}

	/**
	 * Check a form or button.
	 *
	 * @param string $action Nonce action.
	 */
	private static function check( $action ) {
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara-pro' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the screen with a message.
	 *
	 * @param true|WP_Error $result Result.
	 * @param string        $ok     Message when it worked.
	 */
	private static function done( $result, $ok ) {
		if ( is_wp_error( $result ) ) {
			Transara_Admin::flash( $result->get_error_message(), 'error' );
		} else {
			Transara_Admin::flash( $ok );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/*
	 * Actions.
	 */

	/**
	 * Connect.
	 */
	public static function handle_connect() {
		self::check( 'trp_connect' );
		self::done( TRP_Service::connect(), __( 'Connected. Your monitors are now also checked from outside your server.', 'transara-pro' ) );
	}

	/**
	 * Disconnect.
	 */
	public static function handle_disconnect() {
		self::check( 'trp_disconnect' );
		self::done( TRP_Service::disconnect(), __( 'Disconnected. The service deleted this site\'s checks, history and status page.', 'transara-pro' ) );
	}

	/**
	 * Sync now.
	 */
	public static function handle_sync() {
		self::check( 'trp_sync' );
		self::done( TRP_Service::sync(), __( 'Monitors and alert settings were sent to the service.', 'transara-pro' ) );
	}

	/**
	 * Test alert.
	 */
	public static function handle_test() {
		self::check( 'trp_test' );
		TRP_Service::sync();
		$reply = TRP_Service::request( 'POST', 'test-alert', array() );
		if ( is_wp_error( $reply ) ) {
			self::done( $reply, '' );
		}
		$r    = (array) $reply['results'];
		$sent = array();
		if ( ! empty( $r['email'] ) ) {
			$sent[] = __( 'email', 'transara-pro' );
		}
		if ( ! empty( $r['telegram'] ) ) {
			$sent[] = 'Telegram';
		}
		if ( array_filter( (array) $r['webhooks'] ) ) {
			$sent[] = __( 'webhooks', 'transara-pro' );
		}
		if ( array_filter( (array) $r['sms'] ) ) {
			$sent[] = __( 'SMS', 'transara-pro' );
		}
		/* translators: %s: channels. */
		self::done( true, $sent ? sprintf( __( 'Test alert sent by: %s. Check that it arrived.', 'transara-pro' ), implode( ', ', $sent ) ) : __( 'The test alert could not be sent by any channel. Check the addresses and numbers below.', 'transara-pro' ) );
	}

	/**
	 * Add or change an outside-only monitor.
	 */
	public static function handle_monitor() {
		self::check( 'trp_monitor' );
		$in = isset( $_POST['trp'] ) && is_array( $_POST['trp'] ) ? wp_unslash( $_POST['trp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cleaned in save_extra().
		if ( empty( $in['target'] ) ) {
			self::done( new WP_Error( 'trp_target', __( 'Enter the address to check.', 'transara-pro' ) ), '' );
		}
		TRP_Service::save_extra( $in );
		$sync = TRP_Service::connected() ? TRP_Service::sync() : true;
		self::done( $sync, __( 'Monitor saved.', 'transara-pro' ) );
	}

	/**
	 * Delete an outside-only monitor.
	 */
	public static function handle_delete_monitor() {
		self::check( 'trp_delete_monitor' );
		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		TRP_Service::delete_extra( $id );
		$sync = TRP_Service::connected() ? TRP_Service::sync() : true;
		self::done( $sync, __( 'Monitor deleted.', 'transara-pro' ) );
	}

	/**
	 * Acknowledge an incident or add a public note.
	 */
	public static function handle_incident() {
		self::check( 'trp_incident' );
		$body = array( 'id' => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		if ( ! empty( $_POST['ack'] ) ) {
			$body['ack'] = 1;
		}
		if ( isset( $_POST['note'] ) ) {
			$body['note'] = sanitize_textarea_field( wp_unslash( $_POST['note'] ) );
		}
		$reply = TRP_Service::request( 'POST', 'incident', $body );
		delete_transient( 'trp_summary' );
		self::done( is_wp_error( $reply ) ? $reply : true, __( 'Incident updated.', 'transara-pro' ) );
	}

	/*
	 * Screen.
	 */

	/**
	 * A button running an admin-post action.
	 *
	 * @param string $action Action.
	 * @param string $label  Label.
	 * @param string $class  Button class.
	 * @param string $confirm Confirmation question.
	 * @return string
	 */
	private static function button( $action, $label, $class = 'secondary', $confirm = '' ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
		return '<a class="button button-' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"' . ( '' !== $confirm ? ' data-transara-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}

	/**
	 * Status word with colour.
	 *
	 * @param string $status up, down, pending or paused.
	 * @return string
	 */
	private static function status( $status ) {
		$labels = array(
			'up'      => __( 'Up', 'transara-pro' ),
			'down'    => __( 'Down', 'transara-pro' ),
			'paused'  => __( 'Paused', 'transara-pro' ),
			'pending' => __( 'Waiting', 'transara-pro' ),
		);
		$class  = 'up' === $status ? 'is-ok' : ( 'down' === $status ? 'is-bad' : '' );
		return '<strong class="' . esc_attr( $class ) . '">' . esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) . '</strong>';
	}

	/**
	 * Uptime percentage.
	 *
	 * @param array $u Uptime data.
	 * @return string
	 */
	private static function pct( $u ) {
		return isset( $u['uptime'] ) && null !== $u['uptime'] ? number_format_i18n( (float) $u['uptime'], 2 ) . '%' : '—';
	}

	/**
	 * The screen.
	 */
	public static function page() {
		Transara_Admin::header( self::PAGE );
		$data = TRP_Service::data();
		echo '<div class="transara-grid transara-grid--wide"><section class="transara-card"><h2>' . esc_html__( 'Checks from outside your server', 'transara-pro' ) . '</h2>';
		if ( ! TRP_Service::connected() ) {
			echo '<p>' . esc_html__( 'Connect this site to the Transara service. Your monitors are then also checked from servers in other regions, every minute if you like, so you are alerted even when this whole server is down. No account is needed: the service uses your Transara Pro license.', 'transara-pro' ) . '</p>';
			if ( '' !== $data['error'] ) {
				echo '<p class="transara-warn">' . esc_html( $data['error'] ) . '</p>';
			}
			echo '<p>' . self::button( 'trp_connect', __( 'Connect to the Transara service', 'transara-pro' ), 'primary' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in button().
			echo '<p class="description">' . esc_html__( 'Sent to transara.laghavi.site: your license key, this site\'s address, your monitors (names, addresses, check settings) and your alert settings.', 'transara-pro' ) . '</p></section></div>';
			Transara_Admin::settings_form( 'pro' );
			Transara_Admin::footer();
			return;
		}
		echo '<p>' . esc_html__( 'Connected.', 'transara-pro' ) . ' ';
		if ( $data['regions'] ) {
			/* translators: %s: region names. */
			echo esc_html( sprintf( __( 'Checked from: %s.', 'transara-pro' ), implode( ', ', $data['regions'] ) ) ) . ' ';
		}
		if ( $data['last_sync'] ) {
			/* translators: %s: time ago. */
			echo esc_html( sprintf( __( 'Last sent %s ago.', 'transara-pro' ), human_time_diff( (int) $data['last_sync'] ) ) );
		}
		echo '</p>';
		if ( '' !== $data['error'] ) {
			echo '<p class="transara-warn">' . esc_html( $data['error'] ) . '</p>';
		}
		if ( '' !== $data['status_url'] ) {
			echo '<p>' . esc_html__( 'Hosted status page:', 'transara-pro' ) . ' <a href="' . esc_url( $data['status_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $data['status_url'] ) . '</a></p>';
		}
		echo '<p>' . self::button( 'trp_sync', __( 'Send changes now', 'transara-pro' ) ) . ' ' . self::button( 'trp_test', __( 'Send a test alert', 'transara-pro' ) ) . ' ' . self::button( 'trp_disconnect', __( 'Disconnect', 'transara-pro' ), 'link-delete', __( 'Disconnect this site? The service deletes its checks, history and status page.', 'transara-pro' ) ) . '</p></section></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in button().

		$summary = TRP_Service::summary();
		if ( is_wp_error( $summary ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $summary->get_error_message() ) . '</p></div>';
		} else {
			self::monitors_card( $summary );
			self::incidents_card( $summary );
			self::alerts_card( $summary );
		}
		self::extras_card();
		self::report_card();
		Transara_Admin::settings_form( 'pro' );
		Transara_Admin::footer();
	}

	/**
	 * Results per monitor and region.
	 *
	 * @param array $summary Summary.
	 */
	private static function monitors_card( $summary ) {
		echo '<section class="transara-card"><h2>' . esc_html__( 'Results from outside', 'transara-pro' ) . '</h2>';
		if ( empty( $summary['monitors'] ) ) {
			echo '<p>' . esc_html__( 'No monitors yet. Add monitors in Transara › Monitoring, or outside-only checks below.', 'transara-pro' ) . '</p></section>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Monitor', 'transara-pro' ) . '</th><th>' . esc_html__( 'Status', 'transara-pro' ) . '</th><th>' . esc_html__( 'Regions', 'transara-pro' ) . '</th><th>24h</th><th>7d</th><th>30d</th><th>' . esc_html__( 'Response', 'transara-pro' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $summary['monitors'] as $m ) {
			$regions = array();
			foreach ( (array) $m['regions'] as $r ) {
				$regions[] = '<span title="' . esc_attr( (string) $r['message'] ) . '">' . ( (int) $r['ok'] ? '✓' : '✗' ) . ' ' . esc_html( (string) $r['region'] ) . ( (int) $r['ok'] ? ' ' . (int) $r['ms'] . ' ms' : '' ) . '</span>';
			}
			echo '<tr><td><strong>' . esc_html( (string) $m['name'] ) . '</strong><br><span class="description">' . esc_html( (string) $m['message'] ) . '</span></td><td>' . self::status( (string) $m['status'] ) . '</td><td>' . wp_kses( implode( '<br>', $regions ), array( 'span' => array( 'title' => true ), 'br' => array() ) ) . '</td><td>' . esc_html( self::pct( $m['day'] ) ) . '</td><td>' . esc_html( self::pct( $m['week'] ) ) . '</td><td>' . esc_html( self::pct( $m['month'] ) ) . '</td><td>' . ( (int) $m['month']['avg_ms'] ? esc_html( (int) $m['month']['avg_ms'] . ' ms' ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- status() escapes.
		}
		echo '</tbody></table></section>';
	}

	/**
	 * Incidents with acknowledge and note.
	 *
	 * @param array $summary Summary.
	 */
	private static function incidents_card( $summary ) {
		echo '<section class="transara-card"><h2>' . esc_html__( 'Incidents', 'transara-pro' ) . '</h2>';
		if ( empty( $summary['incidents'] ) ) {
			echo '<p>' . esc_html__( 'No incidents. Everything has been up.', 'transara-pro' ) . '</p></section>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Monitor', 'transara-pro' ) . '</th><th>' . esc_html__( 'Started', 'transara-pro' ) . '</th><th>' . esc_html__( 'Lasted', 'transara-pro' ) . '</th><th>' . esc_html__( 'Cause', 'transara-pro' ) . '</th><th>' . esc_html__( 'Note on the status page', 'transara-pro' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $summary['incidents'] as $i ) {
			$ended = (int) $i['ended'];
			echo '<tr><td>' . esc_html( (string) $i['name'] ) . '</td><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $i['started'] ) ) . '</td><td>' . ( $ended ? esc_html( human_time_diff( (int) $i['started'], $ended ) ) : '<strong class="is-bad">' . esc_html__( 'Ongoing', 'transara-pro' ) . '</strong>' ) . '</td><td>' . esc_html( (string) $i['cause'] ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="trp_incident" /><input type="hidden" name="id" value="' . (int) $i['id'] . '" />';
			wp_nonce_field( 'trp_incident' );
			echo '<input type="text" class="regular-text" name="note" value="' . esc_attr( (string) $i['note'] ) . '" placeholder="' . esc_attr__( 'We are looking into it…', 'transara-pro' ) . '" /> ';
			if ( ! $ended && ! (int) $i['acked'] ) {
				echo '<label><input type="checkbox" name="ack" value="1" /> ' . esc_html__( 'I\'m on it (stops escalation)', 'transara-pro' ) . '</label> ';
			}
			echo '<button class="button">' . esc_html__( 'Save', 'transara-pro' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * Recent alerts and SMS use.
	 *
	 * @param array $summary Summary.
	 */
	private static function alerts_card( $summary ) {
		echo '<section class="transara-card"><h2>' . esc_html__( 'Alerts sent', 'transara-pro' ) . '</h2>';
		if ( ! empty( $summary['sms_limit'] ) ) {
			/* translators: 1: used, 2: limit. */
			echo '<p>' . esc_html( sprintf( __( 'SMS and WhatsApp this month: %1$d of %2$d.', 'transara-pro' ), (int) $summary['sms_used'], (int) $summary['sms_limit'] ) ) . '</p>';
		}
		if ( empty( $summary['alerts'] ) ) {
			echo '<p>' . esc_html__( 'No alerts yet.', 'transara-pro' ) . '</p></section>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( (array) $summary['alerts'] as $a ) {
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $a['time'] ) ) . '</td><td>' . esc_html( (string) $a['channel'] ) . '</td><td>' . ( (int) $a['ok'] ? '<span class="is-ok">' . esc_html__( 'Sent', 'transara-pro' ) . '</span>' : '<span class="is-bad">' . esc_html__( 'Failed', 'transara-pro' ) . '</span>' ) . '</td><td class="description">' . esc_html( (string) $a['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * Outside-only monitors.
	 */
	private static function extras_card() {
		$types = TRP_Service::extra_types();
		echo '<section class="transara-card" id="trp-extra"><h2>' . esc_html__( 'Outside-only checks', 'transara-pro' ) . '</h2><p class="transara-card__lead">' . esc_html__( 'Checks only the service runs: other websites and APIs, values in API answers, slow pages, DNS records and domain expiry.', 'transara-pro' ) . '</p>';
		$extras = TRP_Service::extras();
		if ( $extras ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $extras as $m ) {
				$del = wp_nonce_url( admin_url( 'admin-post.php?action=trp_delete_monitor&id=' . rawurlencode( $m['id'] ) ), 'trp_delete_monitor' );
				echo '<tr><td><strong>' . esc_html( $m['name'] ) . '</strong></td><td>' . esc_html( isset( $types[ $m['type'] ] ) ? $types[ $m['type'] ] : $m['type'] ) . '</td><td><code>' . esc_html( $m['target'] ) . '</code></td><td>' . esc_html( sprintf( /* translators: %d: minutes. */ _n( 'every %d minute', 'every %d minutes', (int) $m['interval'], 'transara-pro' ), (int) $m['interval'] ) ) . '</td><td><a class="button-link-delete" href="' . esc_url( $del ) . '" data-transara-confirm="' . esc_attr__( 'Delete this check?', 'transara-pro' ) . '">' . esc_html__( 'Delete', 'transara-pro' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="trp_monitor" />';
		wp_nonce_field( 'trp_monitor' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="trp-type">' . esc_html__( 'Check', 'transara-pro' ) . '</label></th><td><select id="trp-type" name="trp[type]">';
		foreach ( $types as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="trp-target">' . esc_html__( 'Address', 'transara-pro' ) . '</label></th><td><input type="text" class="regular-text" id="trp-target" name="trp[target]" placeholder="https://example.com/api/health · example.com · mail.example.com:587" required /></td></tr>';
		echo '<tr><th><label for="trp-name">' . esc_html__( 'Name', 'transara-pro' ) . '</label></th><td><input type="text" class="regular-text" id="trp-name" name="trp[name]" /></td></tr>';
		echo '<tr><th><label for="trp-interval">' . esc_html__( 'Check every', 'transara-pro' ) . '</label></th><td><input type="number" class="small-text" id="trp-interval" name="trp[interval]" value="5" min="1" max="1440" /> ' . esc_html__( 'minutes', 'transara-pro' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Details', 'transara-pro' ) . '</th><td><p><label>' . esc_html__( 'Word on the page (contains a word)', 'transara-pro' ) . ' <input type="text" name="trp[options][keyword]" /></label> <label><input type="checkbox" name="trp[options][keyword_absent]" value="1" /> ' . esc_html__( 'alert when the word IS there', 'transara-pro' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'JSON value', 'transara-pro' ) . ' <input type="text" name="trp[options][json_path]" placeholder="status" class="small-text" style="width:140px" /></label> <select name="trp[options][json_op]"><option>=</option><option>!=</option><option>&gt;</option><option>&lt;</option><option value="contains">' . esc_html__( 'contains', 'transara-pro' ) . '</option></select> <input type="text" name="trp[options][json_value]" placeholder="ok" style="width:140px" /></p>';
		echo '<p><label>' . esc_html__( 'Too slow above', 'transara-pro' ) . ' <input type="number" class="small-text" name="trp[options][slow_ms]" value="2000" min="100" /> ms</label></p>';
		echo '<p><label>' . esc_html__( 'DNS record', 'transara-pro' ) . ' <select name="trp[options][dns_type]"><option>A</option><option>AAAA</option><option>CNAME</option><option>MX</option><option>TXT</option><option>NS</option></select></label> <label>' . esc_html__( 'must contain', 'transara-pro' ) . ' <input type="text" name="trp[options][dns_value]" /></label></p>';
		echo '<p><label>' . esc_html__( 'Alert when the domain expires within', 'transara-pro' ) . ' <input type="number" class="small-text" name="trp[options][domain_days]" value="30" min="1" /> ' . esc_html__( 'days', 'transara-pro' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="trp[public]" value="1" /> ' . esc_html__( 'Show on the hosted status page', 'transara-pro' ) . '</label></p></td></tr>';
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Add check', 'transara-pro' ) . '</button></p></form></section>';
	}

	/**
	 * Last month's report.
	 */
	private static function report_card() {
		if ( ! TRP_Service::connected() ) {
			return;
		}
		$report = get_transient( 'trp_report' );
		if ( ! is_array( $report ) ) {
			$report = TRP_Service::request( 'GET', 'report' );
			if ( is_wp_error( $report ) ) {
				return;
			}
			set_transient( 'trp_report', $report, 6 * HOUR_IN_SECONDS );
		}
		echo '<section class="transara-card"><h2>' . esc_html( sprintf( /* translators: %s: month. */ __( 'Report for %s', 'transara-pro' ), (string) $report['month'] ) ) . '</h2>';
		if ( empty( $report['monitors'] ) ) {
			echo '<p>' . esc_html__( 'No checks last month yet.', 'transara-pro' ) . '</p></section>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Monitor', 'transara-pro' ) . '</th><th>' . esc_html__( 'Uptime', 'transara-pro' ) . '</th><th>' . esc_html__( 'Down for', 'transara-pro' ) . '</th><th>' . esc_html__( 'Incidents', 'transara-pro' ) . '</th><th>' . esc_html__( 'Average response', 'transara-pro' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $report['monitors'] as $m ) {
			echo '<tr><td>' . esc_html( (string) $m['name'] ) . '</td><td>' . esc_html( null !== $m['uptime'] ? number_format_i18n( (float) $m['uptime'], 3 ) . '%' : '—' ) . '</td><td>' . esc_html( sprintf( /* translators: %d: minutes. */ _n( '%d minute', '%d minutes', (int) $m['down_min'], 'transara-pro' ), (int) $m['down_min'] ) ) . '</td><td>' . (int) $m['incidents'] . '</td><td>' . ( (int) $m['avg_ms'] ? esc_html( (int) $m['avg_ms'] . ' ms' ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'The service also emails this report on the 1st of each month when the monthly report is on (below).', 'transara-pro' ) . '</p></section>';
	}

	/**
	 * Dashboard card.
	 */
	public static function dashboard_card() {
		echo '<section class="transara-card transara-stat"><h2>' . esc_html__( 'Checks from outside', 'transara-pro' ) . '</h2>';
		if ( ! TRP_Service::connected() ) {
			echo '<p>' . esc_html__( 'Not connected yet.', 'transara-pro' ) . '</p><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Connect', 'transara-pro' ) . '</a></p></section>';
			return;
		}
		$summary = TRP_Service::summary();
		$down    = 0;
		$total   = 0;
		if ( ! is_wp_error( $summary ) ) {
			foreach ( (array) $summary['monitors'] as $m ) {
				++$total;
				$down += 'down' === $m['status'] ? 1 : 0;
			}
		}
		echo '<p class="transara-big ' . ( $down ? 'is-bad' : 'is-ok' ) . '">' . esc_html( $down ? (string) $down : '✓' ) . '</p><p>' . esc_html( $down ? _n( 'monitor is down', 'monitors are down', $down, 'transara-pro' ) : sprintf( /* translators: %d: number. */ _n( '%d monitor up from every region', '%d monitors up from every region', $total, 'transara-pro' ), $total ) ) . '</p><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Details', 'transara-pro' ) . '</a></p></section>';
	}
}
