<?php
/**
 * Contact clicks (Pro): every click on a WhatsApp, call, SMS or email link or button anywhere on the
 * site, found automatically by its address. Each click is saved with its time, IP address, page,
 * button text and source, and shown with the pages the visitor viewed before it and whether the
 * same visitor also sent an enquiry on the site.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contact clicks.
 */
class AARYEPRO_Clicks {

	const PAGE = 'aarye-leads-clicks';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'aarye_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'aarye_settings_rows', array( __CLASS__, 'settings_rows' ), 11 );
		add_filter( 'aarye_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'aarye_admin_menu', array( __CLASS__, 'menu' ), 6, 2 );
		add_action( 'aarye_entry_cards', array( __CLASS__, 'entry_card' ), 11 );
		add_action( 'aarye_overview_cards', array( __CLASS__, 'overview_card' ) );
		add_action( 'admin_post_aaryepro_clicks_csv', array( __CLASS__, 'csv' ) );
		add_action( 'aaryepro_daily', array( __CLASS__, 'prune' ) );
	}

	/**
	 * Kinds of contact links.
	 *
	 * @return array
	 */
	public static function kinds() {
		return array(
			'whatsapp' => __( 'WhatsApp', 'aarye-marketing-addon-for-forms-pro' ),
			'call'     => __( 'Call', 'aarye-marketing-addon-for-forms-pro' ),
			'email'    => __( 'Email', 'aarye-marketing-addon-for-forms-pro' ),
			'sms'      => __( 'SMS', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * Setting defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$defaults['pro_clicks']    = 'yes';
		$defaults['pro_clicks_ip'] = 'yes';
		return $defaults;
	}

	/**
	 * Settings rows.
	 *
	 * @param array $s Settings.
	 */
	public static function settings_rows( $s ) {
		?>
		<tr>
			<th><?php esc_html_e( 'Contact clicks (Pro)', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
			<td>
				<label><input type="checkbox" name="pro_clicks" value="yes" <?php checked( isset( $s['pro_clicks'] ) ? $s['pro_clicks'] : 'yes', 'yes' ); ?> /> <?php esc_html_e( 'Record clicks on WhatsApp, call, SMS and email links and buttons (found automatically)', 'aarye-marketing-addon-for-forms-pro' ); ?></label><br />
				<label><input type="checkbox" name="pro_clicks_ip" value="yes" <?php checked( isset( $s['pro_clicks_ip'] ) ? $s['pro_clicks_ip'] : 'yes', 'yes' ); ?> /> <?php esc_html_e( 'Save the IP address with each click', 'aarye-marketing-addon-for-forms-pro' ); ?></label>
				<p class="description"><?php esc_html_e( 'Clicks are kept as long as page views. No names are saved. Mention click tracking and IP addresses in your privacy policy.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the rows.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save_settings( $values ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked by the free plugin before this filter runs.
		$values['pro_clicks']    = isset( $_POST['pro_clicks'] ) ? 'yes' : 'no';
		$values['pro_clicks_ip'] = isset( $_POST['pro_clicks_ip'] ) ? 'yes' : 'no';
		// phpcs:enable
		return $values;
	}

	/**
	 * Kind of a contact link, from its address (the same rules as the browser script).
	 *
	 * @param string $href Address.
	 * @return string
	 */
	public static function kind_of( $href ) {
		$h = strtolower( trim( (string) $href ) );
		if ( 0 === strpos( $h, 'tel:' ) || 0 === strpos( $h, 'callto:' ) ) {
			return 'call';
		}
		if ( 0 === strpos( $h, 'mailto:' ) ) {
			return 'email';
		}
		if ( 0 === strpos( $h, 'sms:' ) ) {
			return 'sms';
		}
		if ( 0 === strpos( $h, 'whatsapp:' ) || preg_match( '#^https?://(www\.)?(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com|chat\.whatsapp\.com|whatsapp\.com/send|wa\.link)#', $h ) ) {
			return 'whatsapp';
		}
		return '';
	}

	/**
	 * Readable target: the phone number, email address or WhatsApp number.
	 *
	 * @param string $href Address.
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function target( $href, $kind ) {
		$href = rawurldecode( (string) $href );
		if ( 'whatsapp' === $kind ) {
			if ( preg_match( '#(?:phone=|wa\.me/)\+?([0-9]{6,15})#i', $href, $m ) ) {
				return '+' . $m[1];
			}
			return 'WhatsApp';
		}
		$value = preg_replace( '#^(tel|callto|mailto|sms):#i', '', $href );
		$value = strtok( (string) $value, '?' );
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Save a click.
	 *
	 * @param string     $visitor Visitor ID ('' without cookies).
	 * @param array      $c       Report: k (kind), h (address), x (text), u (page).
	 * @param string     $ip      IP address.
	 * @param array|null $touch   Visit source.
	 */
	public static function record( $visitor, $c, $ip, $touch ) {
		global $wpdb;
		$href = isset( $c['h'] ) ? (string) $c['h'] : '';
		$kind = self::kind_of( $href );
		// Only real contact links are saved, whatever the browser says.
		if ( '' === $kind ) {
			return;
		}
		$page = isset( $c['u'] ) ? (string) $c['u'] : '';
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$p    = wp_parse_url( $page );
		$path = $p && ( empty( $p['host'] ) || strtolower( $p['host'] ) === strtolower( (string) $host ) ) ? ( isset( $p['path'] ) ? $p['path'] : '/' ) . ( ! empty( $p['query'] ) ? '?' . $p['query'] : '' ) : '';
		// The same click reported twice within a few seconds is saved once.
		$once = 'aaryepro_click_' . md5( $ip . $visitor . $href . $path );
		if ( get_transient( $once ) ) {
			return;
		}
		set_transient( $once, 1, 5 );

		$source = AARYE_Sources::classify( $touch ? $touch : array() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			AARYEPRO_DB::table( 'clicks' ),
			array(
				'visitor_id'   => substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $visitor ) ), 0, 32 ),
				'clicked_at'   => current_time( 'mysql', true ),
				'kind'         => $kind,
				'target'       => mb_substr( self::target( $href, $kind ), 0, 300 ),
				'label'        => isset( $c['x'] ) ? mb_substr( sanitize_text_field( (string) $c['x'] ), 0, 100 ) : '',
				'page'         => mb_substr( $path, 0, 500 ),
				'ip'           => AARYE_Settings::on( 'pro_clicks_ip' ) ? $ip : '',
				'channel'      => mb_substr( (string) $source['channel'], 0, 30 ),
				'source_label' => mb_substr( (string) $source['label'], 0, 150 ),
				'campaign'     => mb_substr( (string) $source['campaign'], 0, 255 ),
			)
		);
	}

	/**
	 * The lead the same visitor sent, if any: from a day before the click to 30 days after. Visitors
	 * without the cookie are matched by IP address on the same day.
	 *
	 * @param object $click Click.
	 * @return object|null Entry (id, created_at, form_name).
	 */
	public static function enquiry( $click ) {
		global $wpdb;
		$t    = AARYE_Entries::table();
		$time = strtotime( $click->clicked_at . ' UTC' );
		if ( '' !== (string) $click->visitor_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $wpdb->get_row( $wpdb->prepare( "SELECT id, created_at, form_name FROM %i WHERE visitor_id = %s AND status <> 'spam' AND created_at BETWEEN %s AND %s ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, %s)) LIMIT 1", $t, $click->visitor_id, gmdate( 'Y-m-d H:i:s', $time - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $time + 30 * DAY_IN_SECONDS ), $click->clicked_at ) );
		}
		if ( '' !== (string) $click->ip ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $wpdb->get_row( $wpdb->prepare( "SELECT id, created_at, form_name FROM %i WHERE ip = %s AND status <> 'spam' AND created_at BETWEEN %s AND %s ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, %s)) LIMIT 1", $t, $click->ip, gmdate( 'Y-m-d H:i:s', $time - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $time + DAY_IN_SECONDS ), $click->clicked_at ) );
		}
		return null;
	}

	/**
	 * Filters from the request.
	 *
	 * @return array days, kind, enquired.
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$enq  = isset( $_GET['enquired'] ) ? sanitize_key( wp_unslash( $_GET['enquired'] ) ) : '';
		// phpcs:enable
		return array(
			'days'     => in_array( $days, array( 1, 7, 30, 90, 365 ), true ) ? $days : 30,
			'kind'     => isset( self::kinds()[ $kind ] ) ? $kind : '',
			'enquired' => in_array( $enq, array( 'yes', 'no' ), true ) ? $enq : '',
		);
	}

	/**
	 * Clicks for the filters, each with its enquiry.
	 *
	 * @param array $f     Filters.
	 * @param int   $limit Rows.
	 * @return object[]
	 */
	public static function query( $f, $limit = 500 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $f['days'] * DAY_IN_SECONDS );
		if ( '' !== $f['kind'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE clicked_at >= %s AND kind = %s ORDER BY id DESC LIMIT %d', AARYEPRO_DB::table( 'clicks' ), $since, $f['kind'], $limit ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE clicked_at >= %s ORDER BY id DESC LIMIT %d', AARYEPRO_DB::table( 'clicks' ), $since, $limit ) );
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			$row->enquiry = self::enquiry( $row );
			if ( ( 'yes' === $f['enquired'] && ! $row->enquiry ) || ( 'no' === $f['enquired'] && $row->enquiry ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Menu item.
	 *
	 * @param string $parent Parent.
	 * @param string $cap    Capability.
	 */
	public static function menu( $parent, $cap ) {
		add_submenu_page( $parent, __( 'Contact clicks', 'aarye-marketing-addon-for-forms-pro' ), __( 'Contact clicks', 'aarye-marketing-addon-for-forms-pro' ), $cap, self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Date and time in the site's time zone.
	 *
	 * @param string $gmt MySQL date (UTC).
	 * @return string
	 */
	private static function when( $gmt ) {
		return get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' H:i' );
	}

	/**
	 * The screen.
	 */
	public static function page() {
		$f    = self::filters();
		$rows = self::query( $f );
		$sum  = array();
		$enq  = 0;
		foreach ( $rows as $r ) {
			$sum[ $r->kind ] = isset( $sum[ $r->kind ] ) ? $sum[ $r->kind ] + 1 : 1;
			$enq            += $r->enquiry ? 1 : 0;
		}
		$base = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<div class="wrap aarye aaryepro-clicks">
			<h1><?php esc_html_e( 'Contact clicks', 'aarye-marketing-addon-for-forms-pro' ); ?>
				<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'aaryepro_clicks_csv' ), $f ), admin_url( 'admin-post.php' ) ), 'aaryepro_clicks_csv' ) ); ?>"><?php esc_html_e( 'Export CSV', 'aarye-marketing-addon-for-forms-pro' ); ?></a>
			</h1>
			<p class="aarye-lead"><?php esc_html_e( 'Everyone who clicked a WhatsApp, call, SMS or email button on your site, with the pages they viewed first and whether they also sent an enquiry.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
			<?php if ( ! AARYE_Settings::on( 'pro_clicks' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Contact clicks are switched off in Aarye Leads > Settings.', 'aarye-marketing-addon-for-forms-pro' ); ?></p></div>
			<?php endif; ?>
			<form method="get" class="aarye-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<select name="days">
					<?php
					foreach ( array(
						1   => __( 'Today', 'aarye-marketing-addon-for-forms-pro' ),
						7   => __( 'Last 7 days', 'aarye-marketing-addon-for-forms-pro' ),
						30  => __( 'Last 30 days', 'aarye-marketing-addon-for-forms-pro' ),
						90  => __( 'Last 90 days', 'aarye-marketing-addon-for-forms-pro' ),
						365 => __( 'Last 12 months', 'aarye-marketing-addon-for-forms-pro' ),
					) as $d => $label ) :
						?>
						<option value="<?php echo esc_attr( (string) $d ); ?>" <?php selected( $f['days'], $d ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="kind">
					<option value=""><?php esc_html_e( 'All buttons', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
					<?php foreach ( self::kinds() as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['kind'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="enquired">
					<option value=""><?php esc_html_e( 'Enquired or not', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
					<option value="yes" <?php selected( $f['enquired'], 'yes' ); ?>><?php esc_html_e( 'Also sent an enquiry', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
					<option value="no" <?php selected( $f['enquired'], 'no' ); ?>><?php esc_html_e( 'Clicked only', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
				</select>
				<button class="button"><?php esc_html_e( 'Filter', 'aarye-marketing-addon-for-forms-pro' ); ?></button>
			</form>

			<div class="aarye-kpis">
				<div class="aarye-kpi"><b><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?></b><span><?php esc_html_e( 'Clicks', 'aarye-marketing-addon-for-forms-pro' ); ?></span></div>
				<?php foreach ( self::kinds() as $k => $label ) : ?>
					<?php if ( ! empty( $sum[ $k ] ) ) : ?>
						<div class="aarye-kpi"><b><?php echo esc_html( number_format_i18n( $sum[ $k ] ) ); ?></b><span><?php echo esc_html( $label ); ?></span></div>
					<?php endif; ?>
				<?php endforeach; ?>
				<div class="aarye-kpi aarye-kpi--paid"><b><?php echo esc_html( number_format_i18n( $enq ) ); ?></b><span><?php esc_html_e( 'Also sent an enquiry', 'aarye-marketing-addon-for-forms-pro' ); ?></span></div>
			</div>

			<table class="widefat striped aaryepro-clicks__table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Button', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Clicked on', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Came from', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'IP address', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Enquiry', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Pages viewed before', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No contact clicks in this period yet.', 'aarye-marketing-addon-for-forms-pro' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<?php $pages = AARYEPRO_Journey::pages( (string) $r->visitor_id, $r->clicked_at, 30 ); ?>
						<tr>
							<td><?php echo esc_html( self::when( $r->clicked_at ) ); ?></td>
							<td><span class="aaryepro-kind aaryepro-kind--<?php echo esc_attr( $r->kind ); ?>"><?php echo esc_html( isset( self::kinds()[ $r->kind ] ) ? self::kinds()[ $r->kind ] : $r->kind ); ?></span><br /><span class="aarye-muted"><?php echo esc_html( $r->target . ( '' !== $r->label ? ' · "' . $r->label . '"' : '' ) ); ?></span></td>
							<td>
							<?php
							if ( '' !== $r->page ) :
								?>
								<a href="<?php echo esc_url( home_url( $r->page ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r->page ); ?></a><?php endif; ?></td>
							<td><?php echo esc_html( $r->source_label . ( '' !== $r->campaign ? ' · ' . $r->campaign : '' ) ); ?></td>
							<td><?php echo '' !== $r->ip ? esc_html( $r->ip ) : '<span class="aarye-muted">—</span>'; ?></td>
							<td>
								<?php if ( $r->enquiry ) : ?>
									<a class="aaryepro-yes" href="<?php echo esc_url( admin_url( 'admin.php?page=aarye-leads-entries&entry=' . (int) $r->enquiry->id ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: form name. */ __( 'Yes · %s', 'aarye-marketing-addon-for-forms-pro' ), $r->enquiry->form_name ) ); ?></a>
								<?php else : ?>
									<span class="aarye-muted"><?php esc_html_e( 'Clicked only', 'aarye-marketing-addon-for-forms-pro' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $pages ) : ?>
									<details><summary><?php echo esc_html( sprintf( /* translators: %d: pages. */ _n( '%d page', '%d pages', count( $pages ), 'aarye-marketing-addon-for-forms-pro' ), count( $pages ) ) ); ?></summary>
										<ol class="aaryepro-pages">
											<?php foreach ( $pages as $pv ) : ?>
												<li><span class="aarye-muted"><?php echo esc_html( get_date_from_gmt( $pv->viewed_at, 'H:i' ) ); ?></span> <a href="<?php echo esc_url( home_url( $pv->url ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== $pv->title ? $pv->title : $pv->url ); ?></a></li>
											<?php endforeach; ?>
										</ol>
									</details>
								<?php else : ?>
									<span class="aarye-muted">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( '"Also sent an enquiry" means the same visitor (or, without cookies, the same IP address that day) sent a lead from your site. Whether they finished the WhatsApp chat, call or email can\'t be seen from the website.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Contact clicks on a lead's page.
	 *
	 * @param object $e Entry.
	 */
	public static function entry_card( $e ) {
		global $wpdb;
		if ( '' === (string) $e->visitor_id ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE visitor_id = %s ORDER BY id ASC LIMIT 50', AARYEPRO_DB::table( 'clicks' ), $e->visitor_id ) );
		if ( ! $rows ) {
			return;
		}
		?>
		<div class="aarye-card">
			<h2><?php esc_html_e( 'Contact buttons clicked', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
			<ul>
				<?php foreach ( $rows as $r ) : ?>
					<li><span class="aarye-muted"><?php echo esc_html( self::when( $r->clicked_at ) ); ?></span> · <?php echo esc_html( ( isset( self::kinds()[ $r->kind ] ) ? self::kinds()[ $r->kind ] : $r->kind ) . ' ' . $r->target ); ?> · <?php echo esc_html( $r->page ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Card on the overview.
	 *
	 * @param array $f Overview filters.
	 */
	public static function overview_card( $f ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, COUNT(*) AS n FROM %i WHERE clicked_at >= %s GROUP BY kind', AARYEPRO_DB::table( 'clicks' ), gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		if ( ! $rows ) {
			return;
		}
		?>
		<div class="aarye-card">
			<h2><?php esc_html_e( 'Contact clicks (30 days)', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
			<ul>
				<?php foreach ( $rows as $r ) : ?>
					<li><?php echo esc_html( ( isset( self::kinds()[ $r->kind ] ) ? self::kinds()[ $r->kind ] : $r->kind ) . ': ' . number_format_i18n( (int) $r->n ) ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'See every click', 'aarye-marketing-addon-for-forms-pro' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * CSV export.
	 */
	public static function csv() {
		if ( ! current_user_can( apply_filters( 'aarye_capability', 'manage_options' ) ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The free plugin's filter.
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		check_admin_referer( 'aaryepro_clicks_csv' );
		$rows = self::query( self::filters(), 10000 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=contact-clicks-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the download.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Excel needs the UTF-8 mark.
		fputcsv( $out, array( 'Time', 'Button', 'Number / address', 'Button text', 'Page', 'Source', 'Campaign', 'IP address', 'Enquiry', 'Pages viewed before' ) );
		foreach ( $rows as $r ) {
			$pages = AARYEPRO_Journey::pages( (string) $r->visitor_id, $r->clicked_at, 30 );
			fputcsv( $out, array( self::when( $r->clicked_at ), $r->kind, $r->target, $r->label, $r->page, $r->source_label, $r->campaign, $r->ip, $r->enquiry ? 'Yes (lead #' . $r->enquiry->id . ')' : 'No', implode( ' > ', wp_list_pluck( $pages, 'url' ) ) ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming the download.
		exit;
	}

	/**
	 * Remove old clicks (kept as long as page views).
	 */
	public static function prune() {
		global $wpdb;
		$days = max( 1, (int) AARYE_Settings::get( 'pro_journey_days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE clicked_at < %s', AARYEPRO_DB::table( 'clicks' ), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}
