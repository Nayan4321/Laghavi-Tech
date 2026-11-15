<?php
/**
 * Insights data source: registry, cache and REST route. Read-only.
 *
 * @package RinnovoKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Insights.
 */
class RINNOVO_Kit_Insights {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Sections: name => [label, callback( $from_ts, $to_ts )].
	 *
	 * @return array
	 */
	public static function sections() {
		/**
		 * Insights sections. Add your own: $sections['my_section'] = array( 'label' => '…', 'callback' => fn( $from, $to ) => array( 'totals' => … ) ).
		 *
		 * @param array $sections Sections.
		 */
		return (array) apply_filters( 'rinnovo_insights_sections', array() );
	}

	/**
	 * One section.
	 *
	 * @param string $section Section.
	 * @param array  $args    Args.
	 * @return array|WP_Error
	 */
	public static function get( $section, $args = array() ) {
		$section = sanitize_key( $section );
		$all     = self::sections();
		$to      = isset( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'] ) ? strtotime( $args['to'] . ' 23:59:59 UTC' ) : time();
		$from    = isset( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'] ) ? strtotime( $args['from'] . ' 00:00:00 UTC' ) : $to - 30 * DAY_IN_SECONDS;
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		$to = min( $to, $from + 366 * DAY_IN_SECONDS );
		if ( 'sections' === $section ) {
			$out = array();
			foreach ( $all as $k => $s ) {
				$out[ $k ] = isset( $s['label'] ) ? $s['label'] : $k;
			}
			return array(
				'section'  => 'sections',
				'sections' => $out,
				'version'  => RINNOVO_KIT_VERSION,
			);
		}
		if ( ! isset( $all[ $section ] ) || ! is_callable( $all[ $section ]['callback'] ) ) {
			return new WP_Error( 'rinnovo_insights_section', 'Unknown or inactive section.', array( 'status' => 404 ) );
		}
		$key = 'rinnovo_ins_' . md5( $section . '|' . gmdate( 'Y-m-d', $from ) . '|' . gmdate( 'Y-m-d', $to ) . '|' . RINNOVO_KIT_VERSION );
		if ( empty( $args['fresh'] ) ) {
			$hit = get_transient( $key );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}
		$data = (array) call_user_func( $all[ $section ]['callback'], $from, $to );
		$data = array_merge(
			array(
				'section'   => $section,
				'from'      => gmdate( 'Y-m-d', $from ),
				'to'        => gmdate( 'Y-m-d', $to ),
				'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'totals'    => array(),
				'daily'     => array(),
				'breakdown' => array(),
			),
			$data
		);
		set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * GET /wp-json/rinnovo/v1/insights?section=…&from=…&to=… (shop managers only).
	 */
	public static function routes() {
		register_rest_route(
			'rinnovo/v1',
			'/insights',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => array(
					'section' => array(
						'type'    => 'string',
						'default' => 'sections',
					),
					'from'    => array( 'type' => 'string' ),
					'to'      => array( 'type' => 'string' ),
				),
				'callback'            => static function ( $req ) {
					$r = self::get(
						(string) $req['section'],
						array(
							'from' => (string) $req['from'],
							'to'   => (string) $req['to'],
						)
					);
					return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
				},
			)
		);
	}

	// ---- Helpers for sections. ----

	/**
	 * Does a table exist.
	 *
	 * @param string $table Full name.
	 * @return bool
	 */
	public static function has_table( $table ) {
		global $wpdb;
		static $seen = array();
		if ( ! isset( $seen[ $table ] ) ) {
			$seen[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $seen[ $table ];
	}

	/**
	 * A count / sum.
	 *
	 * @param string $sql  SQL with placeholders (%i for tables).
	 * @param mixed  ...$a Values.
	 * @return float
	 */
	public static function num( $sql, ...$a ) {
		global $wpdb;
		return (float) $wpdb->get_var( $wpdb->prepare( $sql, ...$a ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Rows.
	 *
	 * @param string $sql  SQL.
	 * @param mixed  ...$a Values.
	 * @return array[]
	 */
	public static function rows( $sql, ...$a ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$a ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * MySQL datetime of a timestamp.
	 *
	 * @param int $ts Timestamp.
	 * @return string
	 */
	public static function dt( $ts ) {
		return gmdate( 'Y-m-d H:i:s', (int) $ts );
	}

	/**
	 * Daily counts: [ 'Y-m-d' => n ] for every day in the range.
	 *
	 * @param string $table  Table.
	 * @param string $column Date column.
	 * @param int    $from   From.
	 * @param int    $to     To.
	 * @param string $where  Extra condition (trusted SQL).
	 * @return array
	 */
	public static function per_day( $table, $column, $from, $to, $where = '1=1' ) {
		$out = array();
		for ( $d = $from; $d <= $to; $d += DAY_IN_SECONDS ) {
			$out[ gmdate( 'Y-m-d', $d ) ] = 0;
		}
		foreach ( self::rows( "SELECT DATE(%i) d, COUNT(*) n FROM %i WHERE %i BETWEEN %s AND %s AND {$where} GROUP BY d", $column, $table, $column, self::dt( $from ), self::dt( $to ) ) as $r ) {
			$out[ $r['d'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Monthly amount of a recurring price.
	 *
	 * @param float  $amount   Amount.
	 * @param string $period   day, week, month, year.
	 * @param int    $interval Interval.
	 * @return float
	 */
	public static function monthly( $amount, $period, $interval ) {
		$per = array(
			'day'   => 30.4375,
			'week'  => 4.348,
			'month' => 1,
			'year'  => 1 / 12,
		);
		return (float) $amount * ( isset( $per[ $period ] ) ? $per[ $period ] : 1 ) / max( 1, (int) $interval );
	}
}
