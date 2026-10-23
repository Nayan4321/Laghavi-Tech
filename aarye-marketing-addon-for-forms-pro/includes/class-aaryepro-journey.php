<?php
/**
 * Visitor journey: the pages each visitor viewed (with time on page and, when switched on, IP address),
 * shown on every entry.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Journey.
 */
class AARYEPRO_Journey {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'aarye_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'aarye_settings_rows', array( __CLASS__, 'settings_rows' ) );
		add_filter( 'aarye_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'aarye_entry_cards', array( __CLASS__, 'entry_card' ), 10 );
		add_action( 'aaryepro_daily', array( __CLASS__, 'prune' ) );
	}

	/**
	 * Pro setting defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$defaults['pro_journey']      = 'yes';
		$defaults['pro_journey_days'] = 90;
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
			<th><?php esc_html_e( 'Pages viewed (Pro)', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
			<td>
				<label><input type="checkbox" name="pro_journey" value="yes" <?php checked( isset( $s['pro_journey'] ) ? $s['pro_journey'] : '', 'yes' ); ?> /> <?php esc_html_e( 'Remember the pages each visitor views, to show them on their entry', 'aarye-marketing-addon-for-forms-pro' ); ?></label><br />
				<label><?php esc_html_e( 'Keep page views for', 'aarye-marketing-addon-for-forms-pro' ); ?> <input type="number" min="1" max="730" class="small-text" name="pro_journey_days" value="<?php echo esc_attr( (string) ( isset( $s['pro_journey_days'] ) ? $s['pro_journey_days'] : 90 ) ); ?>" /> <?php esc_html_e( 'days', 'aarye-marketing-addon-for-forms-pro' ); ?></label>
				<p class="description"><?php esc_html_e( 'IP addresses are saved with page views and entries when "Save visitor IP addresses" is on. Mention this in your privacy policy.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
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
		$values['pro_journey']      = isset( $_POST['pro_journey'] ) ? 'yes' : 'no';
		$values['pro_journey_days'] = isset( $_POST['pro_journey_days'] ) ? max( 1, min( 730, absint( $_POST['pro_journey_days'] ) ) ) : 90;
		// phpcs:enable
		return $values;
	}

	/**
	 * Record a page view or the time spent on it.
	 *
	 * @param string $visitor Visitor ID.
	 * @param array  $body    Report: pv {u, t, r} for a new view, lv {u, s} when leaving.
	 * @param string $ip      IP address.
	 */
	public static function record( $visitor, $body, $ip ) {
		global $wpdb;
		$t = AARYEPRO_DB::table( 'pageviews' );
		if ( isset( $body['pv'] ) && is_array( $body['pv'] ) && ! empty( $body['pv']['u'] ) ) {
			$path = self::path( (string) $body['pv']['u'] );
			if ( '' === $path ) {
				return;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$t,
				array(
					'visitor_id' => substr( $visitor, 0, 32 ),
					'viewed_at'  => current_time( 'mysql', true ),
					'url'        => mb_substr( $path, 0, 500 ),
					'title'      => isset( $body['pv']['t'] ) ? mb_substr( sanitize_text_field( (string) $body['pv']['t'] ), 0, 255 ) : '',
					'referrer'   => isset( $body['pv']['r'] ) ? mb_substr( esc_url_raw( (string) $body['pv']['r'] ), 0, 500 ) : '',
					'ip'         => AARYE_Settings::on( 'store_ip' ) ? $ip : '',
				)
			);
		}
		if ( isset( $body['lv'] ) && is_array( $body['lv'] ) && ! empty( $body['lv']['u'] ) && isset( $body['lv']['s'] ) ) {
			$path = self::path( (string) $body['lv']['u'] );
			$secs = min( 86400, absint( $body['lv']['s'] ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE visitor_id = %s AND url = %s ORDER BY id DESC LIMIT 1', $t, $visitor, $path ) );
			if ( $id ) {
				$wpdb->update( $t, array( 'seconds' => $secs ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * Path and query of an address on this site (other sites are ignored).
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function path( $url ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$p    = wp_parse_url( $url );
		if ( ! $p || ( ! empty( $p['host'] ) && strtolower( $p['host'] ) !== strtolower( (string) $host ) ) ) {
			return '';
		}
		return ( isset( $p['path'] ) ? $p['path'] : '/' ) . ( ! empty( $p['query'] ) ? '?' . $p['query'] : '' );
	}

	/**
	 * Pages a visitor viewed up to a moment.
	 *
	 * @param string $visitor Visitor ID.
	 * @param string $until   MySQL date (UTC).
	 * @param int    $limit   Rows.
	 * @return object[]
	 */
	public static function pages( $visitor, $until, $limit = 50 ) {
		global $wpdb;
		if ( '' === $visitor ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE visitor_id = %s AND viewed_at <= %s ORDER BY id DESC LIMIT %d', AARYEPRO_DB::table( 'pageviews' ), $visitor, gmdate( 'Y-m-d H:i:s', strtotime( $until . ' UTC' ) + 60 ), $limit ) );
		return array_reverse( (array) $rows );
	}

	/**
	 * Card on an entry.
	 *
	 * @param object $e Entry.
	 */
	public static function entry_card( $e ) {
		$pages = self::pages( (string) $e->visitor_id, $e->created_at );
		$ips   = array_unique( array_filter( array_merge( array( (string) $e->ip ), wp_list_pluck( $pages, 'ip' ) ) ) );
		?>
		<div class="aarye-card aaryepro-journey">
			<h2><?php esc_html_e( 'Pages viewed before sending', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
			<?php if ( $ips ) : ?>
				<p><b><?php esc_html_e( 'IP address:', 'aarye-marketing-addon-for-forms-pro' ); ?></b> <?php echo esc_html( implode( ', ', $ips ) ); ?></p>
			<?php endif; ?>
			<?php if ( ! $pages ) : ?>
				<p class="aarye-muted"><?php echo AARYE_Settings::on( 'pro_journey' ) ? esc_html__( 'No page views recorded for this visitor (they may have blocked cookies, or visited before Pro was active).', 'aarye-marketing-addon-for-forms-pro' ) : esc_html__( 'Page views are switched off in Settings.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
			<?php else : ?>
				<ol class="aaryepro-pages">
					<?php
					$prev = '';
					foreach ( $pages as $pv ) :
						$day = get_date_from_gmt( $pv->viewed_at, get_option( 'date_format' ) );
						?>
						<li>
							<span class="aarye-muted"><?php echo esc_html( ( $day !== $prev ? $day . ' ' : '' ) . get_date_from_gmt( $pv->viewed_at, 'H:i' ) ); ?></span>
							<a href="<?php echo esc_url( home_url( $pv->url ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== $pv->title ? $pv->title : $pv->url ); ?></a>
							<?php if ( (int) $pv->seconds > 0 ) : ?>
								<span class="aarye-muted">· <?php echo esc_html( (int) $pv->seconds >= 60 ? sprintf( /* translators: %d: minutes. */ __( '%d min', 'aarye-marketing-addon-for-forms-pro' ), round( $pv->seconds / 60 ) ) : sprintf( /* translators: %d: seconds. */ __( '%d s', 'aarye-marketing-addon-for-forms-pro' ), $pv->seconds ) ); ?></span>
							<?php endif; ?>
						</li>
						<?php
						$prev = $day;
					endforeach;
					?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Remove old page views.
	 */
	public static function prune() {
		global $wpdb;
		$days = max( 1, (int) AARYE_Settings::get( 'pro_journey_days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE viewed_at < %s', AARYEPRO_DB::table( 'pageviews' ), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}
