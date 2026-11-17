<?php
/**
 * Public status page: the [transara_status] shortcode and the "Status page" block show the monitors
 * you mark as public, with 90-day history, current problems and your own notice.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status page.
 */
class Transara_Status_Page {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ), 15 );
		add_shortcode( 'transara_status', array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'block' ) );
	}

	/**
	 * Settings (Monitoring tab).
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		return Transara_Settings::insert_sections(
			$schema,
			'monitoring',
			array(
				'status_page' => array(
					'title'       => __( 'Status page', 'transara' ),
					'description' => __( 'Put the [transara_status] shortcode or the "Status page" block on any page. It shows the monitors marked "Show on the public status page".', 'transara' ),
					'fields'      => array(
						'status_title'  => array(
							'type'    => 'text',
							'label'   => __( 'Title', 'transara' ),
							'default' => '',
						),
						'status_notice' => array(
							'type'        => 'text',
							'label'       => __( 'Notice for visitors', 'transara' ),
							'description' => __( 'For example: "Planned maintenance on Sunday 02:00–03:00." Empty = no notice.', 'transara' ),
							'default'     => '',
						),
					),
				),
			)
		);
	}

	/**
	 * The block (rendered on the server).
	 */
	public static function block() {
		wp_register_script( 'transara-status-block', TRANSARA_URL . 'assets/js/status-block.js', array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-i18n' ), TRANSARA_VERSION, true );
		register_block_type(
			'transara/status',
			array(
				'api_version'     => 3,
				'title'           => __( 'Status page', 'transara' ),
				'category'        => 'widgets',
				'icon'            => 'performance',
				'editor_script'   => 'transara-status-block',
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);
	}

	/**
	 * The status page.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! get_option( 'transara_monitor_db' ) ) {
			return '';
		}
		wp_enqueue_style( 'transara-status', TRANSARA_URL . 'assets/css/status.css', array(), TRANSARA_VERSION );
		$monitors = array_values(
			array_filter(
				Transara_Monitors::all(),
				static function ( $m ) {
					return $m->public && ! $m->paused;
				}
			)
		);
		$down     = array_filter(
			$monitors,
			static function ( $m ) {
				return 'down' === $m->status;
			}
		);
		$title    = (string) Transara_Settings::get( 'status_title' );
		$notice   = (string) Transara_Settings::get( 'status_notice' );
		$out      = '<div class="transara-status">';
		if ( '' !== $title ) {
			$out .= '<h2 class="transara-status__title">' . esc_html( $title ) . '</h2>';
		}
		$out .= '<div class="transara-status__banner ' . ( $down ? 'is-down' : 'is-up' ) . '">' . esc_html( $down ? ( count( $down ) === count( $monitors ) ? __( 'Major outage', 'transara' ) : __( 'Some systems have problems', 'transara' ) ) : __( 'All systems operational', 'transara' ) ) . '</div>';
		if ( '' !== $notice ) {
			$out .= '<div class="transara-status__notice">' . esc_html( $notice ) . '</div>';
		}
		foreach ( $monitors as $m ) {
			$u    = Transara_Monitors::uptime( $m->id, time() - 90 * DAY_IN_SECONDS );
			$out .= '<div class="transara-status__row"><div class="transara-status__head"><strong>' . esc_html( $m->name ) . '</strong><span class="transara-status__state is-' . esc_attr( $m->status ) . '">' . esc_html( 'down' === $m->status ? __( 'Down', 'transara' ) : ( 'up' === $m->status ? __( 'Operational', 'transara' ) : __( 'Checking', 'transara' ) ) ) . '</span></div>';
			$out .= Transara_Monitoring_Admin::bars( Transara_Monitors::daily( $m->id, 90 ) );
			$out .= '<div class="transara-status__foot"><span>' . esc_html__( '90 days ago', 'transara' ) . '</span><span>' . esc_html( null === $u['uptime'] ? '' : sprintf( /* translators: %s: uptime */ __( '%s uptime', 'transara' ), number_format_i18n( $u['uptime'], 2 ) . '%' ) ) . '</span><span>' . esc_html__( 'Today', 'transara' ) . '</span></div></div>';
		}
		$ids       = wp_list_pluck( $monitors, 'name', 'id' );
		$incidents = array_filter(
			Transara_Monitors::incidents( 0, 30 ),
			static function ( $i ) use ( $ids ) {
				return isset( $ids[ (int) $i->monitor_id ] ) && (int) $i->started > time() - 14 * DAY_IN_SECONDS;
			}
		);
		if ( $incidents ) {
			$out .= '<h3 class="transara-status__sub">' . esc_html__( 'Recent incidents', 'transara' ) . '</h3><ul class="transara-status__incidents">';
			foreach ( $incidents as $i ) {
				$end  = (int) $i->ended;
				$out .= '<li><strong>' . esc_html( $ids[ (int) $i->monitor_id ] ) . '</strong> · ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $i->started ) ) . ' · ' . esc_html( $end ? sprintf( /* translators: %s: duration */ __( 'resolved after %s', 'transara' ), human_time_diff( (int) $i->started, $end ) ) : __( 'ongoing', 'transara' ) ) . '</li>';
			}
			$out .= '</ul>';
		}
		$out .= '<p class="transara-status__updated">' . esc_html( sprintf( /* translators: %s: time */ __( 'Updated %s', 'transara' ), wp_date( get_option( 'time_format' ) ) ) ) . '</p></div>';
		return $out;
	}
}
