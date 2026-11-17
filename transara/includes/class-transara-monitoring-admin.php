<?php
/**
 * Monitoring screen: monitors with uptime and response times, adding and editing monitors, a detail
 * view with charts and incidents, WordPress health checks, speed reports and alert settings.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Monitoring admin.
 */
class Transara_Monitoring_Admin {

	const PAGE = 'transara-monitoring';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_admin_pages', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_transara_monitor_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_transara_monitor_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_transara_monitor_pause', array( __CLASS__, 'handle_pause' ) );
		add_action( 'admin_post_transara_monitor_check', array( __CLASS__, 'handle_check' ) );
		add_action( 'transara_dashboard_cards', array( __CLASS__, 'dashboard_card' ) );
	}

	/**
	 * Add the page.
	 *
	 * @param array $pages Pages.
	 * @return array
	 */
	public static function register( $pages ) {
		$pages[ self::PAGE ] = array( __( 'Monitoring', 'transara' ), array( __CLASS__, 'page' ) );
		return $pages;
	}

	/**
	 * Link to a view of this screen (with a nonce, as the view reads the address).
	 *
	 * @param string $view View.
	 * @param int    $id   Monitor.
	 * @return string
	 */
	public static function view_url( $view, $id = 0 ) {
		return wp_nonce_url( add_query_arg( array_filter( array( 'page' => self::PAGE, 'view' => $view, 'id' => $id ) ), admin_url( 'admin.php' ) ), 'transara_view' );
	}

	/**
	 * The screen.
	 */
	public static function page() {
		Transara_Monitors::maybe_install();
		$ok   = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'transara_view' );
		$view = $ok && isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$id   = $ok && isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		Transara_Admin::header( self::PAGE );
		if ( 'edit' === $view || 'add' === $view ) {
			self::edit( $id );
		} elseif ( 'detail' === $view && $id ) {
			self::detail( $id );
		} else {
			self::overview();
		}
		Transara_Admin::footer();
	}

	/**
	 * Status pill.
	 *
	 * @param object $m Monitor.
	 * @return string
	 */
	private static function pill( $m ) {
		if ( $m->paused ) {
			return '<span class="transara-pill">' . esc_html__( 'Paused', 'transara' ) . '</span>';
		}
		$labels = array(
			'up'      => __( 'Up', 'transara' ),
			'down'    => __( 'Down', 'transara' ),
			'pending' => __( 'Waiting for first check', 'transara' ),
		);
		return '<span class="transara-pill is-' . esc_attr( $m->status ) . '">' . esc_html( isset( $labels[ $m->status ] ) ? $labels[ $m->status ] : $m->status ) . '</span>';
	}

	/**
	 * Uptime as text.
	 *
	 * @param float|null $u Uptime.
	 * @return string
	 */
	private static function pct( $u ) {
		return null === $u ? '—' : number_format_i18n( $u, $u >= 99.995 ? 0 : 2 ) . '%';
	}

	/**
	 * Overview.
	 */
	private static function overview() {
		$monitors = Transara_Monitors::all();
		echo '<section class="transara-card"><h2>' . esc_html__( 'Monitors', 'transara' ) . ' <a class="page-title-action" href="' . esc_url( self::view_url( 'add' ) ) . '">' . esc_html__( 'Add monitor', 'transara' ) . '</a> ' . Transara_Admin::action_button( 'transara_monitor_check', __( 'Check all now', 'transara' ), 'secondary' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		echo '<p class="transara-card__lead">' . esc_html__( 'Checks run from this site every minute in the background. A site cannot report its own complete outage, so pair it with a check from another server for this site itself.', 'transara' ) . '</p>';
		if ( ! $monitors ) {
			echo '<p>' . esc_html__( 'No monitors yet.', 'transara' ) . '</p>';
		} else {
			echo '<table class="transara-table"><thead><tr><th>' . esc_html__( 'Monitor', 'transara' ) . '</th><th>' . esc_html__( 'Status', 'transara' ) . '</th><th>24h</th><th>7d</th><th>30d</th><th>' . esc_html__( 'Response', 'transara' ) . '</th><th>' . esc_html__( 'Last check', 'transara' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $monitors as $m ) {
				$d1  = Transara_Monitors::uptime( $m->id, time() - DAY_IN_SECONDS );
				$d7  = Transara_Monitors::uptime( $m->id, time() - 7 * DAY_IN_SECONDS );
				$d30 = Transara_Monitors::uptime( $m->id, time() - 30 * DAY_IN_SECONDS );
				echo '<tr><td><a href="' . esc_url( self::view_url( 'detail', $m->id ) ) . '"><strong>' . esc_html( $m->name ) . '</strong></a><br /><span class="description">' . esc_html( Transara_Monitors::types()[ $m->type ] . ( '' !== $m->target ? ' · ' . $m->target : '' ) ) . '</span></td>';
				echo '<td>' . self::pill( $m ) . '</td><td>' . esc_html( self::pct( $d1['uptime'] ) ) . '</td><td>' . esc_html( self::pct( $d7['uptime'] ) ) . '</td><td>' . esc_html( self::pct( $d30['uptime'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in pill().
				echo '<td>' . ( $d1['avg_ms'] ? esc_html( number_format_i18n( $d1['avg_ms'] ) . ' ms' ) : '—' ) . '</td>';
				echo '<td>' . ( $m->last_check ? esc_html( sprintf( /* translators: %s: time */ __( '%s ago', 'transara' ), human_time_diff( $m->last_check ) ) ) . ( '' !== $m->last_message ? '<br /><span class="description">' . esc_html( $m->last_message ) . '</span>' : '' ) : '—' ) . '</td>';
				echo '<td><a href="' . esc_url( self::view_url( 'edit', $m->id ) ) . '">' . esc_html__( 'Edit', 'transara' ) . '</a> · ' . '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=transara_monitor_pause&id=' . $m->id ), 'transara_monitor_pause' ) ) . '">' . esc_html( $m->paused ? __( 'Resume', 'transara' ) : __( 'Pause', 'transara' ) ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';

		self::health_card();
		self::speed_card();

		$incidents = Transara_Monitors::incidents( 0, 10 );
		if ( $incidents ) {
			self::incidents_card( $incidents );
		}
		Transara_Admin::settings_form( 'monitoring' );
	}

	/**
	 * Health checks.
	 */
	private static function health_card() {
		$h = Transara_Health::results();
		echo '<section class="transara-card"><h2>' . esc_html__( 'WordPress health', 'transara' ) . ' ' . Transara_Admin::action_button( 'transara_health', __( 'Check now', 'transara' ), 'secondary' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		if ( ! $h['results'] ) {
			echo '<p>' . esc_html__( 'The first health check runs within the hour.', 'transara' ) . '</p></section>';
			return;
		}
		$names = array(
			'ok'   => __( 'OK', 'transara' ),
			'warn' => __( 'Check', 'transara' ),
			'bad'  => __( 'Problem', 'transara' ),
			'info' => __( 'Info', 'transara' ),
		);
		echo '<table class="transara-table"><tbody>';
		foreach ( $h['results'] as $r ) {
			echo '<tr><td style="width:180px"><strong>' . esc_html( $r[1] ) . '</strong></td><td style="width:90px"><span class="transara-pill is-' . esc_attr( $r[0] ) . '">' . esc_html( isset( $names[ $r[0] ] ) ? $names[ $r[0] ] : $r[0] ) . '</span></td><td>' . esc_html( $r[2] ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html( sprintf( /* translators: %s: time */ __( 'Checked %s ago. Runs every hour.', 'transara' ), human_time_diff( (int) $h['time'] ) ) ) . '</p></section>';
	}

	/**
	 * Speed reports.
	 */
	private static function speed_card() {
		$latest = Transara_Pagespeed::latest();
		echo '<section class="transara-card"><h2>' . esc_html__( 'Speed reports (Core Web Vitals)', 'transara' ) . ' ' . Transara_Admin::action_button( 'transara_speed_test', __( 'Test now', 'transara' ), 'secondary' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		$err = (string) get_option( 'transara_speed_error' );
		if ( '' !== $err ) {
			echo '<p class="transara-warn">' . esc_html( $err ) . '</p>';
		}
		if ( ! $latest ) {
			echo '<p>' . esc_html__( 'No speed tests yet. Click "Test now" or wait for the schedule.', 'transara' ) . '</p></section>';
			return;
		}
		$cols = array(
			'score' => __( 'Score', 'transara' ),
			'lcp'   => 'LCP',
			'cls'   => 'CLS',
			'tbt'   => 'TBT',
			'inp'   => 'INP',
			'fcp'   => 'FCP',
			'ttfb'  => 'TTFB',
		);
		echo '<table class="transara-table"><thead><tr><th>' . esc_html__( 'Page', 'transara' ) . '</th><th>' . esc_html__( 'Device', 'transara' ) . '</th>';
		foreach ( $cols as $c ) {
			echo '<th>' . esc_html( $c ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Tested', 'transara' ) . '</th></tr></thead><tbody>';
		foreach ( $latest as $url => $by ) {
			foreach ( array( 'mobile', 'desktop' ) as $s ) {
				if ( empty( $by[ $s ] ) ) {
					continue;
				}
				$r = $by[ $s ];
				echo '<tr><td>' . esc_html( (string) wp_parse_url( $url, PHP_URL_PATH ) ) . '</td><td>' . esc_html( 'mobile' === $s ? __( 'Phone', 'transara' ) : __( 'Computer', 'transara' ) ) . '</td>';
				foreach ( array_keys( $cols ) as $k ) {
					$v    = isset( $r[ $k ] ) ? $r[ $k ] : null;
					$text = null === $v ? '—' : ( in_array( $k, array( 'score', 'cls' ), true ) ? (string) $v : number_format_i18n( $v / 1000, 2 ) . ' s' );
					if ( in_array( $k, array( 'tbt', 'inp', 'ttfb' ), true ) && null !== $v ) {
						$text = number_format_i18n( $v ) . ' ms';
					}
					echo '<td><span class="transara-pill ' . esc_attr( Transara_Pagespeed::grade( $k, $v ) ) . '">' . esc_html( $text ) . '</span></td>';
				}
				echo '<td>' . esc_html( human_time_diff( (int) $r['time'] ) ) . '</td></tr>';
			}
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Green, orange and red follow Google\'s limits for good, needs improvement and poor. INP comes from real visitors (Chrome data) and appears once your site has enough traffic.', 'transara' ) . '</p></section>';
	}

	/**
	 * Incidents.
	 *
	 * @param object[] $incidents Incidents.
	 */
	private static function incidents_card( $incidents ) {
		$names = array();
		foreach ( Transara_Monitors::all() as $m ) {
			$names[ $m->id ] = $m->name;
		}
		echo '<section class="transara-card"><h2>' . esc_html__( 'Incidents', 'transara' ) . '</h2><table class="transara-table"><thead><tr><th>' . esc_html__( 'Monitor', 'transara' ) . '</th><th>' . esc_html__( 'Started', 'transara' ) . '</th><th>' . esc_html__( 'Duration', 'transara' ) . '</th><th>' . esc_html__( 'Cause', 'transara' ) . '</th></tr></thead><tbody>';
		foreach ( $incidents as $i ) {
			$end = (int) $i->ended;
			echo '<tr><td>' . esc_html( isset( $names[ (int) $i->monitor_id ] ) ? $names[ (int) $i->monitor_id ] : '#' . (int) $i->monitor_id ) . '</td><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $i->started ) ) . '</td><td>' . ( $end ? esc_html( human_time_diff( (int) $i->started, $end ) ) : '<span class="transara-pill is-down">' . esc_html__( 'Ongoing', 'transara' ) . '</span>' ) . '</td><td>' . esc_html( $i->cause ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * Add or edit a monitor.
	 *
	 * @param int $id Monitor (0 = new).
	 */
	private static function edit( $id ) {
		$m    = $id ? Transara_Monitors::get( $id ) : null;
		$o    = $m ? $m->options : Transara_Monitors::option_defaults();
		$type = $m ? $m->type : 'http';
		echo '<form class="transara-card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><h2>' . esc_html( $m ? __( 'Edit monitor', 'transara' ) : __( 'Add monitor', 'transara' ) ) . '</h2>';
		echo '<input type="hidden" name="action" value="transara_monitor_save" /><input type="hidden" name="id" value="' . (int) $id . '" />';
		wp_nonce_field( 'transara_monitor_save' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="tm-type">' . esc_html__( 'Type', 'transara' ) . '</label></th><td><select id="tm-type" name="type">';
		foreach ( Transara_Monitors::types() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $type, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="tm-name">' . esc_html__( 'Name', 'transara' ) . '</label></th><td><input type="text" class="regular-text" id="tm-name" name="name" required value="' . esc_attr( $m ? $m->name : '' ) . '" /></td></tr>';
		echo '<tr class="tm-not-heartbeat"><th><label for="tm-target">' . esc_html__( 'Address', 'transara' ) . '</label></th><td><input type="text" class="regular-text code" id="tm-target" name="target" value="' . esc_attr( $m ? $m->target : '' ) . '" placeholder="https://example.com" /><p class="description">' . esc_html__( 'Website: a full address. Port: host:port, for example mail.example.com:587. SSL: a domain.', 'transara' ) . '</p></td></tr>';
		echo '<tr class="tm-keyword"><th><label for="tm-keyword">' . esc_html__( 'Word or text', 'transara' ) . '</label></th><td><input type="text" class="regular-text" id="tm-keyword" name="keyword" value="' . esc_attr( $o['keyword'] ) . '" /> <label><input type="checkbox" name="keyword_absent" value="1"' . checked( ! empty( $o['keyword_absent'] ), true, false ) . ' /> ' . esc_html__( 'Alert when the text IS on the page (for example "Error" or "Out of stock")', 'transara' ) . '</label></td></tr>';
		echo '<tr class="tm-http"><th><label for="tm-codes">' . esc_html__( 'Accepted status codes', 'transara' ) . '</label></th><td><input type="text" class="regular-text code" id="tm-codes" name="codes" value="' . esc_attr( $o['codes'] ) . '" /><p class="description">' . esc_html__( 'Ranges and codes separated by commas, for example 200-399, 401.', 'transara' ) . '</p></td></tr>';
		echo '<tr class="tm-ssl"><th><label for="tm-ssl">' . esc_html__( 'Alert when the certificate expires within', 'transara' ) . '</label></th><td><input type="number" class="small-text" id="tm-ssl" name="ssl_days" min="1" max="90" value="' . esc_attr( (string) $o['ssl_days'] ) . '" /> ' . esc_html__( 'days', 'transara' ) . '</td></tr>';
		echo '<tr><th><label for="tm-every">' . esc_html__( 'Check every', 'transara' ) . '</label></th><td><select id="tm-every" name="check_every">';
		foreach ( array( 1, 2, 5, 10, 15, 30, 60, 180, 720, 1440 ) as $min ) {
			$label = $min < 60 ? sprintf( /* translators: %d: minutes */ _n( '%d minute', '%d minutes', $min, 'transara' ), $min ) : sprintf( /* translators: %d: hours */ _n( '%d hour', '%d hours', $min / 60, 'transara' ), $min / 60 );
			echo '<option value="' . (int) $min . '"' . selected( $m ? $m->check_every : 5, $min, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description tm-heartbeat">' . esc_html__( 'For a heartbeat: how often your job calls the address. Transara alerts when a call is more than 5 minutes late.', 'transara' ) . '</p></td></tr>';
		echo '<tr class="tm-not-heartbeat"><th><label for="tm-retries">' . esc_html__( 'Down after', 'transara' ) . '</label></th><td><input type="number" class="small-text" id="tm-retries" name="retries" min="1" max="10" value="' . esc_attr( (string) ( $m ? $m->retries : 2 ) ) . '" /> ' . esc_html__( 'failed checks in a row (avoids alerts for a single slow answer)', 'transara' ) . '</td></tr>';
		echo '<tr class="tm-not-heartbeat"><th><label for="tm-timeout">' . esc_html__( 'Timeout', 'transara' ) . '</label></th><td><input type="number" class="small-text" id="tm-timeout" name="timeout" min="2" max="60" value="' . esc_attr( (string) $o['timeout'] ) . '" /> ' . esc_html__( 'seconds', 'transara' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status page', 'transara' ) . '</th><td><label><input type="checkbox" name="public" value="1"' . checked( $m && $m->public, true, false ) . ' /> ' . esc_html__( 'Show on the public status page', 'transara' ) . '</label></td></tr>';
		if ( $m && 'heartbeat' === $m->type ) {
			echo '<tr><th>' . esc_html__( 'Address to call', 'transara' ) . '</th><td><input type="text" class="large-text code" readonly value="' . esc_attr( Transara_Monitors::heartbeat_url( $m ) ) . '" /><p class="description">' . esc_html__( 'Add to the end of your cron job, for example:', 'transara' ) . ' <code>&amp;&amp; curl -fsS ' . esc_html( Transara_Monitors::heartbeat_url( $m ) ) . ' &gt; /dev/null</code></p></td></tr>';
		}
		echo '</table><p>';
		submit_button( __( 'Save monitor', 'transara' ), 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Cancel', 'transara' ) . '</a>';
		if ( $m ) {
			echo ' <a class="button-link button-link-delete" style="margin-left:12px" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=transara_monitor_delete&id=' . $m->id ), 'transara_monitor_delete' ) ) . '" data-transara-confirm="' . esc_attr__( 'Delete this monitor and its history?', 'transara' ) . '">' . esc_html__( 'Delete', 'transara' ) . '</a>';
		}
		echo '</p></form>';
	}

	/**
	 * One monitor in detail.
	 *
	 * @param int $id Monitor.
	 */
	private static function detail( $id ) {
		$m = Transara_Monitors::get( $id );
		if ( ! $m ) {
			echo '<p>' . esc_html__( 'Monitor not found.', 'transara' ) . '</p>';
			return;
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">← ' . esc_html__( 'All monitors', 'transara' ) . '</a></p>';
		echo '<section class="transara-card"><h2>' . esc_html( $m->name ) . ' ' . self::pill( $m ) . ' <a class="page-title-action" href="' . esc_url( self::view_url( 'edit', $m->id ) ) . '">' . esc_html__( 'Edit', 'transara' ) . '</a></h2><p class="transara-card__lead">' . esc_html( Transara_Monitors::types()[ $m->type ] . ( '' !== $m->target ? ' · ' . $m->target : '' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in pill().
		echo '<div class="transara-grid">';
		foreach ( array( __( 'Last 24 hours', 'transara' ) => DAY_IN_SECONDS, __( 'Last 7 days', 'transara' ) => 7 * DAY_IN_SECONDS, __( 'Last 30 days', 'transara' ) => 30 * DAY_IN_SECONDS, __( 'Last 90 days', 'transara' ) => 90 * DAY_IN_SECONDS ) as $label => $secs ) {
			$u = Transara_Monitors::uptime( $m->id, time() - $secs );
			echo '<div class="transara-stat"><p class="description">' . esc_html( $label ) . '</p><p class="transara-big">' . esc_html( self::pct( $u['uptime'] ) ) . '</p><p class="description">' . esc_html( $u['avg_ms'] ? sprintf( /* translators: %s: milliseconds */ __( '%s ms average', 'transara' ), number_format_i18n( $u['avg_ms'] ) ) : '' ) . '</p></div>';
		}
		echo '</div><h3>' . esc_html__( 'Response time, last 24 hours', 'transara' ) . '</h3>' . self::chart( Transara_Monitors::recent( $m->id, time() - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from numbers.
		echo '<h3>' . esc_html__( 'Last 90 days', 'transara' ) . '</h3>' . self::bars( Transara_Monitors::daily( $m->id, 90 ) ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		$incidents = Transara_Monitors::incidents( $m->id, 20 );
		if ( $incidents ) {
			self::incidents_card( $incidents );
		}
	}

	/**
	 * Response-time chart (SVG).
	 *
	 * @param object[] $rows Checks.
	 * @return string
	 */
	public static function chart( $rows ) {
		if ( count( $rows ) < 2 ) {
			return '<p class="description">' . esc_html__( 'The chart appears after a few checks.', 'transara' ) . '</p>';
		}
		$w    = 900;
		$h    = 160;
		$max  = max( 100, max( array_map( static function ( $r ) {
			return (int) $r->ms;
		}, $rows ) ) );
		$t0   = (int) $rows[0]->time;
		$span = max( 1, (int) end( $rows )->time - $t0 );
		$pts  = array();
		$bad  = '';
		foreach ( $rows as $r ) {
			$x = round( ( (int) $r->time - $t0 ) / $span * $w, 1 );
			if ( (int) $r->ok ) {
				$pts[] = $x . ',' . round( $h - (int) $r->ms / $max * ( $h - 10 ), 1 );
			} else {
				$bad .= '<line x1="' . $x . '" x2="' . $x . '" y1="0" y2="' . $h . '" stroke="#dc2626" stroke-width="2" opacity=".6" />';
			}
		}
		return '<svg viewBox="0 0 ' . $w . ' ' . ( $h + 18 ) . '" style="width:100%;height:auto;max-height:220px" role="img" aria-label="' . esc_attr__( 'Response time chart', 'transara' ) . '">'
			. '<polyline fill="none" stroke="#4f46e5" stroke-width="2" points="' . esc_attr( implode( ' ', $pts ) ) . '" />' . $bad
			. '<text x="0" y="' . ( $h + 15 ) . '" font-size="11" fill="#5b6476">' . esc_html( wp_date( get_option( 'time_format' ), $t0 ) ) . '</text>'
			. '<text x="' . $w . '" y="' . ( $h + 15 ) . '" font-size="11" fill="#5b6476" text-anchor="end">' . esc_html( wp_date( get_option( 'time_format' ), (int) end( $rows )->time ) ) . '</text>'
			. '<text x="4" y="12" font-size="11" fill="#5b6476">' . esc_html( number_format_i18n( $max ) . ' ms' ) . '</text></svg>';
	}

	/**
	 * Daily uptime bars.
	 *
	 * @param array $days date => uptime|null.
	 * @return string
	 */
	public static function bars( $days ) {
		$out = '<div class="transara-bars" style="display:flex;gap:2px;height:34px;align-items:stretch">';
		foreach ( $days as $date => $u ) {
			$color = null === $u ? '#e3e6ee' : ( $u >= 99.5 ? '#16a34a' : ( $u >= 95 ? '#d97706' : '#dc2626' ) );
			/* translators: 1: date, 2: uptime */
			$title = null === $u ? $date . ': ' . __( 'no data', 'transara' ) : sprintf( __( '%1$s: %2$s up', 'transara' ), $date, self::pct( $u ) );
			$out  .= '<span title="' . esc_attr( $title ) . '" style="flex:1;border-radius:2px;background:' . esc_attr( $color ) . '"></span>';
		}
		return $out . '</div>';
	}

	/**
	 * Dashboard card.
	 */
	public static function dashboard_card() {
		$monitors = get_option( 'transara_monitor_db' ) ? Transara_Monitors::all() : array();
		$down     = count(
			array_filter(
				$monitors,
				static function ( $m ) {
					return 'down' === $m->status && ! $m->paused;
				}
			)
		);
		echo '<section class="transara-card transara-stat"><h2>' . esc_html__( 'Monitoring', 'transara' ) . '</h2>';
		if ( ! $monitors ) {
			echo '<p>' . esc_html__( 'No monitors yet.', 'transara' ) . '</p>';
		} else {
			echo '<p class="transara-big ' . ( $down ? 'is-bad' : 'is-ok' ) . '">' . esc_html( $down ? sprintf( /* translators: %d: number */ _n( '%d down', '%d down', $down, 'transara' ), $down ) : __( 'All up', 'transara' ) ) . '</p><p>' . esc_html( sprintf( /* translators: %d: number */ _n( '%d monitor', '%d monitors', count( $monitors ), 'transara' ), count( $monitors ) ) ) . '</p>';
		}
		$h    = Transara_Health::results();
		$bad  = 0;
		foreach ( $h['results'] as $r ) {
			$bad += 'bad' === $r[0] ? 1 : 0;
		}
		if ( $bad ) {
			echo '<p class="transara-warn">' . esc_html( sprintf( /* translators: %d: number */ _n( '%d health problem', '%d health problems', $bad, 'transara' ), $bad ) ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Open monitoring', 'transara' ) . '</a></p></section>';
	}

	/*
	 * Actions.
	 */


	/**
	 * Save a monitor.
	 */
	public static function handle_save() {
		check_admin_referer( 'transara_monitor_save' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'http';
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		if ( 'heartbeat' !== $type && '' === Transara_Monitors::clean_target( $type, $target ) ) {
			Transara_Admin::flash( __( 'Please enter an address.', 'transara' ), 'error' );
			Transara_Admin::back();
		}
		$id = Transara_Monitors::save(
			array(
				'id'          => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
				'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'type'        => $type,
				'target'      => $target,
				'check_every' => isset( $_POST['check_every'] ) ? absint( $_POST['check_every'] ) : 5,
				'retries'     => isset( $_POST['retries'] ) ? absint( $_POST['retries'] ) : 2,
				'public'      => ! empty( $_POST['public'] ),
				'options'     => array(
					'keyword'        => isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '',
					'keyword_absent' => empty( $_POST['keyword_absent'] ) ? 0 : 1,
					'codes'          => isset( $_POST['codes'] ) ? preg_replace( '/[^0-9,\-\s]/', '', sanitize_text_field( wp_unslash( $_POST['codes'] ) ) ) : '200-399',
					'timeout'        => isset( $_POST['timeout'] ) ? max( 2, min( 60, absint( $_POST['timeout'] ) ) ) : 15,
					'ssl_days'       => isset( $_POST['ssl_days'] ) ? max( 1, min( 90, absint( $_POST['ssl_days'] ) ) ) : 14,
				),
			)
		);
		Transara_Admin::flash( __( 'Monitor saved. Its first check runs within a minute.', 'transara' ) );
		wp_safe_redirect( 'heartbeat' === $type ? self::view_url( 'edit', $id ) : admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Delete a monitor.
	 */
	public static function handle_delete() {
		check_admin_referer( 'transara_monitor_delete' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		Transara_Monitors::delete( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		Transara_Admin::flash( __( 'Monitor deleted.', 'transara' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Pause or resume.
	 */
	public static function handle_pause() {
		check_admin_referer( 'transara_monitor_pause' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		$m = Transara_Monitors::get( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		if ( $m ) {
			Transara_Monitors::save(
				array(
					'id'     => $m->id,
					'paused' => ! $m->paused,
				)
			);
		}
		Transara_Admin::back();
	}

	/**
	 * Check all now.
	 */
	public static function handle_check() {
		check_admin_referer( 'transara_monitor_check' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		Transara_Monitors::run( true );
		Transara_Admin::flash( __( 'All monitors checked.', 'transara' ) );
		Transara_Admin::back();
	}
}
