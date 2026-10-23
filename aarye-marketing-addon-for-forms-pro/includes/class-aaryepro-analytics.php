<?php
/**
 * Form analytics: views, starts, submissions, abandonment and the field where people give up, per form and
 * per source. Also the endpoint the front-end script reports to (page views go to AARYEPRO_Journey).
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Analytics.
 */
class AARYEPRO_Analytics {

	const PAGE = 'aarye-leads-analytics';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ) );
		add_action( 'aarye_admin_menu', array( __CLASS__, 'menu' ), 5, 2 );
		add_action( 'aarye_overview_cards', array( __CLASS__, 'overview_card' ) );
		add_action( 'aarye_hourly', array( __CLASS__, 'push_to_hub' ), 20 );
	}

	/**
	 * Front-end script.
	 */
	public static function script() {
		if ( is_admin() || is_feed() || is_robots() ) {
			return;
		}
		wp_enqueue_script( 'aaryepro-track', AARYEPRO_URL . 'assets/js/track.js', array(), aaryepro_asset_version( 'assets/js/track.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
		wp_add_inline_script(
			'aaryepro-track',
			'window.aaryeProTrack=' . wp_json_encode(
				array(
					'url'     => esc_url_raw( rest_url( 'aarye-pro/v1/track' ) ),
					'journey' => AARYE_Settings::on( 'pro_journey' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * REST route.
	 */
	public static function routes() {
		register_rest_route(
			'aarye-pro/v1',
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'track' ),
				'permission_callback' => '__return_true', // Anonymous counters from visitors; rate limited below.
			)
		);
	}

	/**
	 * Today in the site's time zone.
	 *
	 * @return string
	 */
	public static function today() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Report from a visitor's browser.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function track( $request ) {
		$ua = $request->get_header( 'user_agent' );
		if ( AARYE_Tracker::is_bot( (string) $ua ) ) {
			return new WP_REST_Response( null, 204 );
		}
		// At most 120 reports a minute from one address.
		$ip    = AARYE_Entries::ip();
		$limit = 'aaryepro_rl_' . md5( $ip . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $limit );
		if ( $count > 120 ) {
			return new WP_REST_Response( null, 429 );
		}
		set_transient( $limit, $count + 1, 2 * MINUTE_IN_SECONDS );

		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( null, 400 );
		}
		$visit   = AARYE_Tracker::read();
		$touch   = $visit['last'] ? $visit['last'] : $visit['first'];
		$channel = AARYE_Sources::classify( $touch ? $touch : array() )['channel'];

		$events = isset( $body['e'] ) && is_array( $body['e'] ) ? array_slice( $body['e'], 0, 30 ) : array();
		$plugins = AARYE_Integrations::plugins();
		foreach ( $events as $ev ) {
			if ( ! is_array( $ev ) || empty( $ev['t'] ) || empty( $ev['p'] ) || ! isset( $ev['f'] ) || ! isset( $plugins[ $ev['p'] ] ) ) {
				continue;
			}
			$form = mb_substr( sanitize_text_field( (string) $ev['f'] ), 0, 64 );
			if ( '' === $form ) {
				continue;
			}
			$field = isset( $ev['fld'] ) ? mb_substr( sanitize_text_field( (string) $ev['fld'] ), 0, 100 ) : '';
			if ( ! empty( $ev['k'] ) ) {
				// Sent twice (when leaving, and again from the next page): count once.
				$once = 'aaryepro_ev_' . md5( (string) $ev['k'] );
				if ( get_transient( $once ) ) {
					continue;
				}
				set_transient( $once, 1, 2 * HOUR_IN_SECONDS );
			}
			self::count( (string) $ev['t'], sanitize_key( $ev['p'] ), $form, $channel, $field );
		}
		if ( '' !== $visit['visitor'] && AARYE_Settings::on( 'pro_journey' ) ) {
			AARYEPRO_Journey::record( $visit['visitor'], $body, $ip );
		}
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Add one event to today's counters.
	 *
	 * @param string $type    view, start or abandon.
	 * @param string $plugin  Form plugin.
	 * @param string $form    Form ID.
	 * @param string $channel Source channel.
	 * @param string $field   Last field (abandon).
	 */
	public static function count( $type, $plugin, $form, $channel, $field = '' ) {
		global $wpdb;
		$cols = array(
			'view'    => 'views',
			'start'   => 'starts',
			'abandon' => 'abandons',
		);
		if ( ! isset( $cols[ $type ] ) ) {
			return;
		}
		$day = self::today();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (day, form_plugin, form_id, channel, %i) VALUES (%s, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE %i = %i + 1', AARYEPRO_DB::table( 'form_stats' ), $cols[ $type ], $day, $plugin, $form, $channel, $cols[ $type ], $cols[ $type ] ) );
		if ( 'abandon' === $type && '' !== $field ) {
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (day, form_plugin, form_id, field, drops) VALUES (%s, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE drops = drops + 1', AARYEPRO_DB::table( 'field_drops' ), $day, $plugin, $form, $field ) );
		}
		// phpcs:enable
	}

	/**
	 * Numbers per form for a date range.
	 *
	 * @param string $from    Y-m-d.
	 * @param string $to      Y-m-d.
	 * @param string $channel Channel or ''.
	 * @return array<string, array> Keyed plugin:form.
	 */
	public static function report( $from, $to, $channel = '' ) {
		global $wpdb;
		$out = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( '' !== $channel ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT form_plugin, form_id, SUM(views) AS views, SUM(starts) AS starts, SUM(abandons) AS abandons FROM %i WHERE day BETWEEN %s AND %s AND channel = %s GROUP BY form_plugin, form_id', AARYEPRO_DB::table( 'form_stats' ), $from, $to, $channel ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT form_plugin, form_id, SUM(views) AS views, SUM(starts) AS starts, SUM(abandons) AS abandons FROM %i WHERE day BETWEEN %s AND %s GROUP BY form_plugin, form_id', AARYEPRO_DB::table( 'form_stats' ), $from, $to ) );
		}
		foreach ( (array) $rows as $r ) {
			$out[ $r->form_plugin . ':' . $r->form_id ] = array(
				'plugin'   => $r->form_plugin,
				'form'     => (string) $r->form_id,
				'views'    => (int) $r->views,
				'starts'   => (int) $r->starts,
				'abandons' => (int) $r->abandons,
				'submits'  => 0,
				'name'     => '',
			);
		}
		// Submissions are the real entries, counted from the day form views started being counted.
		$since = (string) get_option( 'aaryepro_tracking_since', $from );
		$f     = array( 'from' => max( $from, $since ), 'to' => $to, 'channel' => $channel );
		$subs  = self::submissions( $f );
		foreach ( $subs as $k => $row ) {
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array(
					'plugin'   => $row['plugin'],
					'form'     => $row['form'],
					'views'    => 0,
					'starts'   => 0,
					'abandons' => 0,
					'submits'  => 0,
					'name'     => '',
				);
			}
			$out[ $k ]['submits'] = $row['n'];
			$out[ $k ]['name']    = $row['name'];
		}
		// Top drop-off field.
		$drops = $wpdb->get_results( $wpdb->prepare( 'SELECT form_plugin, form_id, field, SUM(drops) AS n FROM %i WHERE day BETWEEN %s AND %s GROUP BY form_plugin, form_id, field ORDER BY n DESC', AARYEPRO_DB::table( 'field_drops' ), $from, $to ) );
		// phpcs:enable
		foreach ( (array) $drops as $d ) {
			$k = $d->form_plugin . ':' . $d->form_id;
			if ( isset( $out[ $k ] ) && ! isset( $out[ $k ]['top_field'] ) ) {
				$out[ $k ]['top_field'] = $d->field;
			}
		}
		$names = self::form_names();
		foreach ( $out as $k => $row ) {
			if ( '' === $row['name'] ) {
				$out[ $k ]['name'] = isset( $names[ $k ] ) ? $names[ $k ] : $row['plugin'] . ' #' . $row['form'];
			}
			if ( ! isset( $out[ $k ]['top_field'] ) ) {
				$out[ $k ]['top_field'] = '';
			}
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return $b['views'] + $b['submits'] <=> $a['views'] + $a['submits'];
			}
		);
		return $out;
	}

	/**
	 * Entries per form (not bots, not spam).
	 *
	 * @param array $f Filters for AARYE_Entries::where().
	 * @return array<string, array{plugin: string, form: string, name: string, n: int}>
	 */
	private static function submissions( $f ) {
		global $wpdb;
		list( $where, $args ) = AARYE_Entries::where( $f );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds fixed SQL with placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT form_plugin, form_id, MAX(form_name) AS name, COUNT(*) AS n FROM %i WHERE {$where} AND status <> 'spam' GROUP BY form_plugin, form_id", array_merge( array( AARYE_Entries::table() ), $args ) ) );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->form_plugin . ':' . $r->form_id ] = array(
				'plugin' => $r->form_plugin,
				'form'   => (string) $r->form_id,
				'name'   => (string) $r->name,
				'n'      => (int) $r->n,
			);
		}
		return $out;
	}

	/**
	 * Form names by plugin:id.
	 *
	 * @return array<string, string>
	 */
	public static function form_names() {
		$names = get_transient( 'aaryepro_form_names' );
		if ( is_array( $names ) ) {
			return $names;
		}
		$names = array();
		foreach ( AARYE_Integrations::all_forms() as $form ) {
			$names[ $form['plugin'] . ':' . $form['id'] ] = $form['title'];
		}
		set_transient( 'aaryepro_form_names', $names, 10 * MINUTE_IN_SECONDS );
		return $names;
	}

	/**
	 * Fields where people stop, for one form.
	 *
	 * @param string $plugin Plugin.
	 * @param string $form   Form ID.
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 * @return array<int, array{key: string, label: string, total: int}>
	 */
	public static function drops( $plugin, $form, $from, $to ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT field, SUM(drops) AS n FROM %i WHERE form_plugin = %s AND form_id = %s AND day BETWEEN %s AND %s GROUP BY field ORDER BY n DESC LIMIT 20', AARYEPRO_DB::table( 'field_drops' ), $plugin, $form, $from, $to ) );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'key'   => $r->field,
				'label' => $r->field,
				'total' => (int) $r->n,
			);
		}
		return $out;
	}

	/**
	 * Views and entries of one form by source channel.
	 *
	 * @param string $plugin Plugin.
	 * @param string $form   Form ID.
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 * @return array<string, array{views: int, submits: int}>
	 */
	public static function by_channel( $plugin, $form, $from, $to ) {
		global $wpdb;
		$out = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT channel, SUM(views) AS v FROM %i WHERE form_plugin = %s AND form_id = %s AND day BETWEEN %s AND %s GROUP BY channel', AARYEPRO_DB::table( 'form_stats' ), $plugin, $form, $from, $to ) ) as $r ) {
			$out[ $r->channel ] = array(
				'views'   => (int) $r->v,
				'submits' => 0,
			);
		}
		foreach ( AARYE_Entries::grouped( 'channel', array( 'from' => max( $from, (string) get_option( 'aaryepro_tracking_since', $from ) ), 'to' => $to, 'form' => $plugin . ':' . $form ) ) as $row ) {
			if ( ! isset( $out[ $row['key'] ] ) ) {
				$out[ $row['key'] ] = array( 'views' => 0, 'submits' => 0 );
			}
			$out[ $row['key'] ]['submits'] = $row['total'];
		}
		return $out;
	}

	/**
	 * Percentage text.
	 *
	 * @param int $part  Part.
	 * @param int $whole Whole.
	 * @return string
	 */
	public static function pct( $part, $whole ) {
		return $whole > 0 ? number_format_i18n( min( 100, 100 * $part / $whole ), 1 ) . '%' : '—';
	}

	/**
	 * Submenu.
	 *
	 * @param string $parent Parent.
	 * @param string $cap    Capability.
	 */
	public static function menu( $parent, $cap ) {
		add_submenu_page( $parent, __( 'Form analytics', 'aarye-marketing-addon-for-forms-pro' ), __( 'Form analytics', 'aarye-marketing-addon-for-forms-pro' ), $cap, self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Date range from the request.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 */
	private static function range() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		$days = in_array( $days, array( 1, 7, 30, 90, 365 ), true ) ? $days : 30;
		return array( wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS ), self::today(), $days );
	}

	/**
	 * The screen.
	 */
	public static function page() {
		list( $from, $to, $days ) = self::range();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';
		$form    = isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : '';
		// phpcs:enable
		$report = self::report( $from, $to, $channel );
		$totals = array( 'views' => 0, 'starts' => 0, 'submits' => 0, 'abandons' => 0 );
		foreach ( $report as $row ) {
			foreach ( $totals as $k => $v ) {
				$totals[ $k ] += $row[ $k ];
			}
		}
		$ranges = array(
			1   => __( 'Today', 'aarye-marketing-addon-for-forms-pro' ),
			7   => __( 'Last 7 days', 'aarye-marketing-addon-for-forms-pro' ),
			30  => __( 'Last 30 days', 'aarye-marketing-addon-for-forms-pro' ),
			90  => __( 'Last 90 days', 'aarye-marketing-addon-for-forms-pro' ),
			365 => __( 'Last 12 months', 'aarye-marketing-addon-for-forms-pro' ),
		);
		?>
		<div class="wrap aarye">
			<h1><?php esc_html_e( 'Form analytics', 'aarye-marketing-addon-for-forms-pro' ); ?></h1>
			<form method="get" class="aarye-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<?php if ( '' !== $form ) : ?>
					<input type="hidden" name="form" value="<?php echo esc_attr( $form ); ?>" />
				<?php endif; ?>
				<select name="days">
					<?php foreach ( $ranges as $d => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $d ); ?>" <?php selected( $days, $d ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="channel">
					<option value=""><?php esc_html_e( 'All sources', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
					<?php foreach ( AARYE_Sources::channels() as $ck => $cl ) : ?>
						<option value="<?php echo esc_attr( $ck ); ?>" <?php selected( $channel, $ck ); ?>><?php echo esc_html( is_array( $cl ) ? $cl['name'] : $cl ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button"><?php esc_html_e( 'Show', 'aarye-marketing-addon-for-forms-pro' ); ?></button>
			</form>
			<div class="aarye-kpis">
				<div class="aarye-kpi"><b><?php echo esc_html( number_format_i18n( $totals['views'] ) ); ?></b><span><?php esc_html_e( 'Form views', 'aarye-marketing-addon-for-forms-pro' ); ?></span></div>
				<div class="aarye-kpi"><b><?php echo esc_html( number_format_i18n( $totals['starts'] ) ); ?></b><span><?php echo esc_html( sprintf( /* translators: %s: percentage. */ __( 'Started (%s of views)', 'aarye-marketing-addon-for-forms-pro' ), self::pct( $totals['starts'], $totals['views'] ) ) ); ?></span></div>
				<div class="aarye-kpi aarye-kpi--paid"><b><?php echo esc_html( number_format_i18n( $totals['submits'] ) ); ?></b><span><?php echo esc_html( sprintf( /* translators: %s: percentage. */ __( 'Sent (%s conversion)', 'aarye-marketing-addon-for-forms-pro' ), self::pct( $totals['submits'], $totals['views'] ) ) ); ?></span></div>
				<div class="aarye-kpi"><b><?php echo esc_html( number_format_i18n( $totals['abandons'] ) ); ?></b><span><?php echo esc_html( sprintf( /* translators: %s: percentage. */ __( 'Abandoned (%s of starts)', 'aarye-marketing-addon-for-forms-pro' ), self::pct( $totals['abandons'], $totals['starts'] ) ) ); ?></span></div>
			</div>
			<?php if ( ! $report ) : ?>
				<div class="aarye-card"><p><?php esc_html_e( 'No form views yet. Numbers appear as visitors see your forms: views when a form is on screen, starts when someone types in it, abandons when they leave without sending.', 'aarye-marketing-addon-for-forms-pro' ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped aarye-table aaryepro-analytics">
					<thead><tr>
						<th><?php esc_html_e( 'Form', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Views', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Starts', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Sent', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Abandoned', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Conversion', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Completion', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Most left at', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $report as $k => $row ) : ?>
							<tr class="<?php echo $k === $form ? 'aaryepro-current' : ''; ?>">
								<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'days' => $days, 'channel' => $channel, 'form' => $k ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
								<td><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['starts'] ) ); ?> <span class="aarye-muted"><?php echo esc_html( self::pct( $row['starts'], $row['views'] ) ); ?></span></td>
								<td><b><?php echo esc_html( number_format_i18n( $row['submits'] ) ); ?></b></td>
								<td><?php echo esc_html( number_format_i18n( $row['abandons'] ) ); ?></td>
								<td><b><?php echo esc_html( self::pct( $row['submits'], $row['views'] ) ); ?></b></td>
								<td><?php echo esc_html( self::pct( $row['submits'], $row['starts'] ) ); ?></td>
								<td><?php echo esc_html( '' !== $row['top_field'] ? $row['top_field'] : '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="aarye-muted"><?php esc_html_e( 'Conversion = sent ÷ views. Completion = sent ÷ started. "Most left at" is the last field people filled before leaving without sending.', 'aarye-marketing-addon-for-forms-pro' ); ?>
				<?php
				/* translators: %s: date. */
				echo esc_html( sprintf( __( 'Counted since %s.', 'aarye-marketing-addon-for-forms-pro' ), mysql2date( get_option( 'date_format' ), (string) get_option( 'aaryepro_tracking_since' ) ) ) );
				?>
				</p>
			<?php endif; ?>
			<?php
			if ( '' !== $form && isset( $report[ $form ] ) ) {
				self::form_detail( $report[ $form ], $from, $to );
			}
			?>
		</div>
		<?php
	}

	/**
	 * One form: drop-off by field and conversion by source.
	 *
	 * @param array  $row  Report row.
	 * @param string $from From.
	 * @param string $to   To.
	 */
	private static function form_detail( $row, $from, $to ) {
		$drops = self::drops( $row['plugin'], $row['form'], $from, $to );
		$chan  = self::by_channel( $row['plugin'], $row['form'], $from, $to );
		$max   = 0;
		foreach ( $drops as $d ) {
			$max = max( $max, $d['total'] );
		}
		?>
		<h2><?php echo esc_html( $row['name'] ); ?></h2>
		<div class="aarye-grid">
			<div class="aarye-card">
				<h2><?php esc_html_e( 'Where people give up', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
				<?php if ( ! $drops ) : ?>
					<p class="aarye-muted"><?php esc_html_e( 'Nobody abandoned this form yet.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
				<?php else : ?>
					<ul class="aarye-bars">
						<?php foreach ( $drops as $d ) : ?>
							<li><span class="aarye-bars__label"><?php echo esc_html( $d['label'] ); ?></span><span class="aarye-bars__bar"><i style="width:<?php echo esc_attr( (string) round( 100 * $d['total'] / max( 1, $max ) ) ); ?>%"></i></span><b><?php echo esc_html( number_format_i18n( $d['total'] ) ); ?></b></li>
						<?php endforeach; ?>
					</ul>
					<p class="aarye-muted"><?php esc_html_e( 'The last field filled before leaving. A field high on this list may be confusing, too personal or not needed.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
				<?php endif; ?>
			</div>
			<div class="aarye-card">
				<h2><?php esc_html_e( 'Conversion by source', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
				<table class="aarye-details">
					<tr><th><?php esc_html_e( 'Source', 'aarye-marketing-addon-for-forms-pro' ); ?></th><td><b><?php esc_html_e( 'Views → sent', 'aarye-marketing-addon-for-forms-pro' ); ?></b></td></tr>
					<?php foreach ( $chan as $ck => $c ) : ?>
						<tr><th><?php echo esc_html( AARYE_Sources::channel_label( $ck ) ); ?></th><td><?php echo esc_html( number_format_i18n( $c['views'] ) . ' → ' . number_format_i18n( $c['submits'] ) . ' (' . self::pct( $c['submits'], $c['views'] ) . ')' ); ?></td></tr>
					<?php endforeach; ?>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Card on the overview.
	 *
	 * @param array $f Filters.
	 */
	public static function overview_card( $f ) {
		$from   = ! empty( $f['from'] ) ? $f['from'] : wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$to     = ! empty( $f['to'] ) ? $f['to'] : self::today();
		$report = array_slice( self::report( $from, $to ), 0, 6, true );
		?>
		<div class="aarye-card">
			<h2><?php esc_html_e( 'Form conversion', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
			<?php if ( ! $report ) : ?>
				<p class="aarye-muted"><?php esc_html_e( 'No form views yet.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
			<?php else : ?>
				<table class="aarye-details aarye-details--small">
					<?php foreach ( $report as $row ) : ?>
						<tr><th><?php echo esc_html( $row['name'] ); ?></th><td><?php echo esc_html( number_format_i18n( $row['views'] ) . ' → ' . number_format_i18n( $row['submits'] ) . ' · ' . self::pct( $row['submits'], $row['views'] ) ); ?></td></tr>
					<?php endforeach; ?>
				</table>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Form analytics', 'aarye-marketing-addon-for-forms-pro' ); ?> →</a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Send today's (and once, yesterday's final) form numbers to Laghavi Ads Hub.
	 */
	public static function push_to_hub() {
		$active = AARYEPRO_Connections::active();
		if ( ! isset( $active['hub'] ) || 'yes' !== AARYEPRO_Connections::get( 'hub', 'form_stats' ) || '' !== AARYEPRO_Connections::stopped( 'hub' ) ) {
			return;
		}
		$days = array( self::today() );
		$yday = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		if ( get_option( 'aaryepro_hub_stats_final' ) !== $yday ) {
			$days[] = $yday;
		}
		foreach ( $days as $day ) {
			$forms = array();
			foreach ( self::report( $day, $day ) as $row ) {
				$forms[] = array(
					'form'              => mb_substr( $row['name'], 0, 190 ),
					'views'             => $row['views'],
					'starts'            => $row['starts'],
					'submits'           => $row['submits'],
					'abandons'          => $row['abandons'],
					'top_abandon_field' => $row['top_field'],
				);
			}
			if ( ! $forms ) {
				if ( $day === $yday ) {
					update_option( 'aaryepro_hub_stats_final', $yday, false );
				}
				continue;
			}
			$res = $active['hub']->send_form_stats( $day, $forms );
			if ( ! empty( $res['stop'] ) ) {
				AARYEPRO_Connections::stop( 'hub', $res['message'] );
				return;
			}
			if ( $res['ok'] && $day === $yday ) {
				update_option( 'aaryepro_hub_stats_final', $yday, false );
			}
		}
	}
}
