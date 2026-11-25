<?php
/**
 * Tables and queries.
 *
 * Data stays in this WordPress database. Visitors are counted without cookies:
 * a daily anonymous fingerprint (salted hash of IP + browser that changes every day) — no IP address is stored.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database helper.
 */
class LGPI_DB {

	const VERSION = '1.6.0';

	/** Traffic source columns. */
	const SOURCES = array( 'direct', 'search', 'social', 'paid', 'email', 'referral', 'ai' );

	/**
	 * Report dimensions kept per day (dim => label). Values are counted per page view and per new visitor.
	 */
	const DIMS = array(
		'country'  => 'Country',
		'browser'  => 'Browser',
		'os'       => 'Operating system',
		'screen'   => 'Screen size',
		'lang'     => 'Language',
		'engine'   => 'Search engine',
		'social'   => 'Social network',
		'ai'       => 'AI assistant',
		'site'     => 'Website',
		'campaign' => 'Campaign',
		'utm'      => 'Source / medium',
		'entry'    => 'Entry page',
		'exit'     => 'Exit page',
		'search'   => 'Site search',
		'notfound' => 'Page not found',
		'aibot'    => 'AI crawler',
		'aibotpg'  => 'Page read by AI crawlers',
		'srchbot'  => 'Search engine crawler',
	);

	/** Device columns. */
	const DEVICES = array( 'mobile', 'tablet', 'desktop' );

	/**
	 * Table name.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'lgpi_' . $name;
	}

	/**
	 * Create / upgrade tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c     = $wpdb->get_charset_collate();
		$daily = self::table( 'daily' );
		$seen  = self::table( 'seen' );
		$src   = '';
		foreach ( self::SOURCES as $s ) {
			$src .= "  src_{$s} int unsigned NOT NULL DEFAULT 0,\n";
		}
		foreach ( self::DEVICES as $d ) {
			$src .= "  dev_{$d} int unsigned NOT NULL DEFAULT 0,\n";
		}
		dbDelta(
			"CREATE TABLE {$daily} (
  day date NOT NULL,
  obj_type varchar(20) NOT NULL,
  obj_id bigint(20) unsigned NOT NULL DEFAULT 0,
  views int unsigned NOT NULL DEFAULT 0,
  visitors int unsigned NOT NULL DEFAULT 0,
  add_to_cart int unsigned NOT NULL DEFAULT 0,
{$src}  PRIMARY KEY  (day,obj_type,obj_id),
  KEY obj (obj_type,obj_id)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$seen} (
  day date NOT NULL,
  hash char(32) NOT NULL,
  PRIMARY KEY  (day,hash)
) {$c};"
		);
		$dims   = self::table( 'dims' );
		$hits   = self::table( 'hits' );
		$visits = self::table( 'visits' );
		dbDelta(
			"CREATE TABLE {$dims} (
  day date NOT NULL,
  dim varchar(12) NOT NULL,
  k varchar(190) NOT NULL,
  views int unsigned NOT NULL DEFAULT 0,
  visitors int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,dim,k),
  KEY dimday (dim,day)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$hits} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts int unsigned NOT NULL,
  v char(16) NOT NULL,
  pv char(12) NOT NULL DEFAULT '',
  obj varchar(40) NOT NULL,
  src varchar(10) NOT NULL DEFAULT '',
  dev varchar(8) NOT NULL DEFAULT '',
  cc char(2) NOT NULL DEFAULT '',
  ref varchar(100) NOT NULL DEFAULT '',
  dur smallint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY ts (ts),
  KEY pv (pv)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$visits} (
  day date NOT NULL,
  visits int unsigned NOT NULL DEFAULT 0,
  bounces int unsigned NOT NULL DEFAULT 0,
  pages int unsigned NOT NULL DEFAULT 0,
  seconds int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day)
) {$c};"
		);
		$journeys = self::table( 'journeys' );
		dbDelta(
			"CREATE TABLE {$journeys} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts_start int unsigned NOT NULL,
  ts_end int unsigned NOT NULL,
  outcome varchar(10) NOT NULL,
  v char(16) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned DEFAULT NULL,
  value decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  src varchar(10) NOT NULL DEFAULT '',
  ref varchar(100) NOT NULL DEFAULT '',
  utm varchar(120) NOT NULL DEFAULT '',
  dev varchar(8) NOT NULL DEFAULT '',
  cc char(2) NOT NULL DEFAULT '',
  pages_n smallint unsigned NOT NULL DEFAULT 0,
  seconds int unsigned NOT NULL DEFAULT 0,
  path text NOT NULL,
  cart text NOT NULL,
  acct tinyint(1) unsigned NOT NULL DEFAULT 0,
  imp tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY ts_end (ts_end),
  KEY v (v),
  KEY order_id (order_id)
) {$c};"
		);
		update_option( 'lgpi_db_version', self::VERSION );
	}

	/**
	 * Count one view.
	 *
	 * @param string $type     post, page, product, product_cat, category, home, archive, search, other.
	 * @param int    $id       Object ID (0 for whole-site rows).
	 * @param bool   $is_new   First view of this visitor today on this object.
	 * @param string $source   One of SOURCES.
	 * @param string $device   One of DEVICES.
	 */
	public static function add_view( $type, $id, $is_new, $source, $device ) {
		global $wpdb;
		$t   = self::table( 'daily' );
		$day = current_time( 'Y-m-d' );
		$s   = in_array( $source, self::SOURCES, true ) ? 'src_' . $source : 'src_direct';
		$d   = in_array( $device, self::DEVICES, true ) ? 'dev_' . $device : 'dev_desktop';
		$v   = $is_new ? 1 : 0;
		// Table and column names are passed as %i identifiers (column names come from the fixed lists above).
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO %i (day, obj_type, obj_id, views, visitors, %i, %i) VALUES (%s, %s, %d, 1, %d, %d, 1)
				ON DUPLICATE KEY UPDATE views = views + 1, visitors = visitors + %d, %i = %i + %d, %i = %i + 1',
				$t,
				$s,
				$d,
				$day,
				$type,
				$id,
				$v,
				$v,
				$v,
				$s,
				$s,
				$v,
				$d,
				$d
			)
		);
	}

	/**
	 * Count an add-to-cart for a product.
	 *
	 * @param int $product_id Product.
	 */
	public static function add_to_cart( $product_id ) {
		global $wpdb;
		$t = self::table( 'daily' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO %i (day, obj_type, obj_id, add_to_cart) VALUES (%s, 'product', %d, 1) ON DUPLICATE KEY UPDATE add_to_cart = add_to_cart + 1",
				$t,
				current_time( 'Y-m-d' ),
				$product_id
			)
		);
	}

	/**
	 * First time today for this anonymous visitor (+ object)?
	 *
	 * @param string $hash Daily fingerprint.
	 * @return bool
	 */
	public static function first_seen( $hash ) {
		global $wpdb;
		$t = self::table( 'seen' );
		$r = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (day, hash) VALUES (%s, %s)', $t, current_time( 'Y-m-d' ), $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return 1 === (int) $r;
	}

	/**
	 * Totals per object for a date range.
	 *
	 * @param string      $since Y-m-d.
	 * @param string      $until Y-m-d.
	 * @param string|null $type  Only this object type, or null for all.
	 * @return array[] rows: obj_type, obj_id, views, visitors, add_to_cart, src_*, dev_*
	 */
	public static function totals( $since, $until, $type = null ) {
		global $wpdb;
		$t = self::table( 'daily' );
		if ( $type ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT * FROM %i WHERE day BETWEEN %s AND %s AND obj_type = %s', $t, $since, $until, $type ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT * FROM %i WHERE day BETWEEN %s AND %s AND obj_type <> 'site'", $t, $since, $until ),
				ARRAY_A
			);
		}
		// Add up the days per object here (keeps the SQL free of built column lists).
		$cols = array( 'views', 'visitors', 'add_to_cart' );
		foreach ( self::SOURCES as $s ) {
			$cols[] = 'src_' . $s;
		}
		foreach ( self::DEVICES as $d ) {
			$cols[] = 'dev_' . $d;
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$k = $r['obj_type'] . ':' . $r['obj_id'];
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array(
					'obj_type' => $r['obj_type'],
					'obj_id'   => $r['obj_id'],
				) + array_fill_keys( $cols, 0 );
			}
			foreach ( $cols as $c ) {
				$out[ $k ][ $c ] += (int) ( $r[ $c ] ?? 0 );
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['views'] <=> $a['views'];
			}
		);
		return array_values( $out );
	}

	/**
	 * Whole-site numbers per day (views, visitors, sources, devices).
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array[]
	 */
	public static function site_daily( $since, $until ) {
		global $wpdb;
		$t = self::table( 'daily' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE obj_type = 'site' AND day BETWEEN %s AND %s ORDER BY day", $t, $since, $until ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Daily rows for one object.
	 *
	 * @param string $type  Object type.
	 * @param int    $id    Object ID.
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array[]
	 */
	public static function object_daily( $type, $id, $since, $until ) {
		global $wpdb;
		$t = self::table( 'daily' );

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE obj_type = %s AND obj_id = %d AND day BETWEEN %s AND %s ORDER BY day', $t, $type, $id, $since, $until ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Rows changed since a day (for the Laghavi sync).
	 *
	 * @param string $since Y-m-d.
	 * @return array[]
	 */
	public static function rows_since( $since ) {
		global $wpdb;
		$t = self::table( 'daily' );

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE day >= %s ORDER BY day LIMIT 20000', $t, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Count report dimensions for one page view.
	 *
	 * @param array $pairs  [ [dim, value], … ] — dims from DIMS, values are trimmed to 190 characters.
	 * @param bool  $is_new First view of this visitor today (also counts a visitor).
	 */
	public static function add_dims( $pairs, $is_new ) {
		global $wpdb;
		$t   = self::table( 'dims' );
		$day = current_time( 'Y-m-d' );
		$v   = $is_new ? 1 : 0;
		foreach ( $pairs as $pair ) {
			list( $dim, $k ) = $pair;
			$k = mb_substr( trim( (string) $k ), 0, 190 );
			if ( '' === $k || ! isset( self::DIMS[ $dim ] ) ) {
				continue;
			}
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'INSERT INTO %i (day, dim, k, views, visitors) VALUES (%s, %s, %s, 1, %d) ON DUPLICATE KEY UPDATE views = views + 1, visitors = visitors + %d',
					$t,
					$day,
					$dim,
					$k,
					$v,
					$v
				)
			);
		}
	}

	/**
	 * Keep one raw page view for 3 days (real-time view, visits, bounce rate, time on site, entry and exit pages).
	 *
	 * @param array $h v (anonymous daily visitor hash), pv (page view id), obj (type:id), src, dev, cc, ref (referring host).
	 */
	public static function add_hit( $h ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table( 'hits' ),
			array(
				'ts'  => time(),
				'v'   => substr( (string) $h['v'], 0, 16 ),
				'pv'  => substr( (string) $h['pv'], 0, 12 ),
				'obj' => substr( (string) $h['obj'], 0, 40 ),
				'src' => substr( (string) $h['src'], 0, 10 ),
				'dev' => substr( (string) $h['dev'], 0, 8 ),
				'cc'  => substr( (string) $h['cc'], 0, 2 ),
				'ref' => mb_substr( (string) $h['ref'], 0, 100 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Time spent on a page (sent when the visitor leaves it).
	 *
	 * @param string $pv      Page view id.
	 * @param int    $seconds Seconds the page was visible.
	 */
	public static function set_duration( $pv, $seconds ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET dur = %d WHERE pv = %s AND ts > %d', self::table( 'hits' ), min( 3600, max( 0, (int) $seconds ) ), $pv, time() - 7200 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Raw page views since a time.
	 *
	 * @param int $since Unix time.
	 * @param int $limit Max rows.
	 * @return array[]
	 */
	public static function hits_since( $since, $limit = 5000 ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ts >= %d ORDER BY ts DESC LIMIT %d', self::table( 'hits' ), $since, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Group raw page views into visits (a new visit after 30 minutes without a page view).
	 *
	 * @param array[] $hits Rows from the hits table.
	 * @return array[] visits: day, entry, exit, pages, seconds, bounce.
	 */
	public static function visits_from_hits( $hits ) {
		$by = array();
		foreach ( $hits as $h ) {
			$by[ $h['v'] ][] = $h;
		}
		$tz  = wp_timezone();
		$out = array();
		foreach ( $by as $rows ) {
			usort(
				$rows,
				function ( $a, $b ) {
					return (int) $a['ts'] <=> (int) $b['ts'];
				}
			);
			$visit = array();
			$last  = 0;
			foreach ( $rows as $r ) {
				if ( $visit && (int) $r['ts'] - $last > 1800 ) {
					$out[] = self::close_visit( $visit, $tz );
					$visit = array();
				}
				$visit[] = $r;
				$last    = (int) $r['ts'];
			}
			if ( $visit ) {
				$out[] = self::close_visit( $visit, $tz );
			}
		}
		return $out;
	}

	/**
	 * One visit's summary.
	 *
	 * @param array[]      $rows Page views in order.
	 * @param DateTimeZone $tz   Site time zone.
	 * @return array
	 */
	private static function close_visit( $rows, $tz ) {
		$first = $rows[0];
		$end   = end( $rows );
		$secs  = (int) $end['ts'] - (int) $first['ts'] + (int) $end['dur'];
		$day   = ( new DateTime( '@' . (int) $first['ts'] ) )->setTimezone( $tz )->format( 'Y-m-d' );

		return array(
			'day'     => $day,
			'entry'   => $first['obj'],
			'exit'    => $end['obj'],
			'pages'   => count( $rows ),
			'seconds' => min( 7200, max( 0, $secs ) ),
			'bounce'  => 1 === count( $rows ),
			'src'     => $first['src'],
			'ts'      => (int) $first['ts'],
		);
	}

	/**
	 * Turn finished days of raw page views into daily visit totals and entry / exit pages.
	 * Runs from the daily schedule and when a report needs it; each day is done once.
	 */
	public static function aggregate_visits() {
		global $wpdb;
		$today     = current_time( 'Y-m-d' );
		$yesterday = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$oldest    = wp_date( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ); // Raw page views are kept 4 days: older days may be incomplete.
		$done      = (string) get_option( 'lgpi_visits_done', '' );
		if ( $done >= $yesterday ) {
			return;
		}
		$hits = self::hits_since( time() - 4 * DAY_IN_SECONDS, 200000 );
		if ( ! $hits ) {
			return;
		}
		$days = array();
		foreach ( self::visits_from_hits( $hits ) as $v ) {
			if ( $v['day'] >= $today || $v['day'] < $oldest || ( '' !== $done && $v['day'] <= $done ) ) {
				continue;
			}
			$days[ $v['day'] ][] = $v;
		}
		ksort( $days );
		foreach ( $days as $day => $list ) {
			$t     = array(
				'visits'  => 0,
				'bounces' => 0,
				'pages'   => 0,
				'seconds' => 0,
			);
			$pairs = array();
			foreach ( $list as $v ) {
				++$t['visits'];
				$t['bounces'] += $v['bounce'] ? 1 : 0;
				$t['pages']   += $v['pages'];
				$t['seconds'] += $v['seconds'];
				$pairs[]       = array( 'entry', $v['entry'] );
				$pairs[]       = array( 'exit', $v['exit'] );
			}
			$wpdb->replace( self::table( 'visits' ), array( 'day' => $day ) + $t, array( '%s', '%d', '%d', '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$counts = array();
			foreach ( $pairs as $p ) {
				$key            = $p[0] . '|' . $p[1];
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
			foreach ( $counts as $key => $n ) {
				list( $dim, $k ) = explode( '|', $key, 2 );
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						'INSERT INTO %i (day, dim, k, views, visitors) VALUES (%s, %s, %s, %d, %d) ON DUPLICATE KEY UPDATE views = %d, visitors = %d',
						self::table( 'dims' ),
						$day,
						$dim,
						$k,
						$n,
						$n,
						$n,
						$n
					)
				);
			}
			update_option( 'lgpi_visits_done', $day, false );
		}
	}

	/**
	 * Visits, bounces, pages and seconds per day (finished days; today is worked out live from raw page views).
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array day => [visits, bounces, pages, seconds]
	 */
	public static function visits_daily( $since, $until ) {
		global $wpdb;
		self::aggregate_visits();
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE day BETWEEN %s AND %s ORDER BY day', self::table( 'visits' ), $since, $until ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out[ $r['day'] ] = array_map( 'intval', array_diff_key( $r, array( 'day' => 1 ) ) );
		}
		$today = current_time( 'Y-m-d' );
		if ( $today >= $since && $today <= $until ) {
			$t = array(
				'visits'  => 0,
				'bounces' => 0,
				'pages'   => 0,
				'seconds' => 0,
			);
			foreach ( self::visits_from_hits( self::hits_since( (int) strtotime( $today . ' 00:00:00 ' . wp_timezone_string() ), 100000 ) ) as $v ) {
				if ( $v['day'] !== $today ) {
					continue;
				}
				++$t['visits'];
				$t['bounces'] += $v['bounce'] ? 1 : 0;
				$t['pages']   += $v['pages'];
				$t['seconds'] += $v['seconds'];
			}
			$out[ $today ] = $t;
		}
		return $out;
	}

	/**
	 * Top values of one dimension for a date range.
	 *
	 * @param string $dim   One of DIMS.
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @param int    $limit Max rows.
	 * @return array[] k, views, visitors
	 */
	public static function dims( $dim, $since, $until, $limit = 50 ) {
		global $wpdb;
		if ( 'entry' === $dim || 'exit' === $dim ) {
			self::aggregate_visits();
		}
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT k, SUM(views) AS views, SUM(visitors) AS visitors FROM %i WHERE dim = %s AND day BETWEEN %s AND %s GROUP BY k ORDER BY views DESC LIMIT %d',
				self::table( 'dims' ),
				$dim,
				$since,
				$until,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Daily housekeeping: old fingerprints and old rows.
	 */
	public static function cleanup() {
		global $wpdb;
		$keep = max( 30, (int) LGPI_Plugin::settings()['keep_days'] );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::table( 'seen' ), gmdate( 'Y-m-d', strtotime( '-2 days' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::table( 'daily' ), gmdate( 'Y-m-d', strtotime( '-' . $keep . ' days' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::aggregate_visits();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::table( 'dims' ), gmdate( 'Y-m-d', strtotime( '-' . $keep . ' days' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::table( 'visits' ), gmdate( 'Y-m-d', strtotime( '-' . $keep . ' days' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ts < %d', self::table( 'hits' ), time() - 4 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_option( 'lgpi_salt_day', '' );
	}
}
