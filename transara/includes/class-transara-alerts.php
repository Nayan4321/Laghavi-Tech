<?php
/**
 * Alerts: email and webhook (Slack, Discord, Microsoft Teams or any address that accepts JSON) when a
 * monitor goes down or comes back, and for WordPress health problems. Maintenance mode pauses them.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Alerts.
 */
class Transara_Alerts {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ) );
		add_action( 'transara_monitor_changed', array( __CLASS__, 'monitor_changed' ), 10, 3 );
		add_action( 'admin_post_transara_test_alert', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_transara_maintenance', array( __CLASS__, 'handle_maintenance' ) );
		add_action( 'transara_section_after', array( __CLASS__, 'alerts_box' ) );
	}

	/**
	 * Settings (Monitoring tab).
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		$schema['monitoring'] = array(
			'title'    => __( 'Monitoring', 'transara' ),
			'sections' => array(
				'alerts' => array(
					'title'       => __( 'Alerts', 'transara' ),
					'description' => __( 'Who hears about it when a monitor goes down, comes back up or a health check finds a problem.', 'transara' ),
					'fields'      => array(
						'alert_emails'  => array(
							'type'        => 'lines',
							'label'       => __( 'Email addresses', 'transara' ),
							'description' => __( 'One per line. Empty = the site\'s admin email.', 'transara' ),
							'default'     => array(),
						),
						'alert_webhook' => array(
							'type'        => 'text',
							'label'       => __( 'Webhook address', 'transara' ),
							'description' => __( 'A Slack, Discord or Microsoft Teams incoming webhook, or any address that accepts JSON.', 'transara' ),
							'default'     => '',
						),
						'alert_up'      => array(
							'type'    => 'checkbox',
							'label'   => __( 'Also tell me when a monitor is back up', 'transara' ),
							'default' => 1,
						),
						'alert_health'  => array(
							'type'    => 'checkbox',
							'label'   => __( 'Alert on new WordPress health problems', 'transara' ),
							'default' => 1,
						),
					),
				),
			),
		);
		return $schema;
	}

	/**
	 * Whether alerts are paused for maintenance.
	 *
	 * @return bool
	 */
	public static function in_maintenance() {
		return (int) get_option( 'transara_maintenance_until' ) > time();
	}

	/**
	 * A monitor changed.
	 *
	 * @param object $m      Monitor.
	 * @param string $status New status.
	 * @param string $msg    Reason.
	 */
	public static function monitor_changed( $m, $status, $msg ) {
		if ( 'up' === $status && ! Transara_Settings::on( 'alert_up' ) ) {
			return;
		}
		$down  = 'down' === $status;
		$where = '' !== $m->target ? $m->target : __( 'heartbeat', 'transara' );
		/* translators: 1: monitor name, 2: site name */
		$subject = sprintf( $down ? __( 'DOWN: %1$s (%2$s)', 'transara' ) : __( 'UP again: %1$s (%2$s)', 'transara' ), $m->name, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$lines   = array(
			$down ? __( 'A monitor is down.', 'transara' ) : __( 'A monitor is back up.', 'transara' ),
			/* translators: %s: monitor name */
			sprintf( __( 'Monitor: %s', 'transara' ), $m->name ),
			/* translators: %s: address */
			sprintf( __( 'Address: %s', 'transara' ), $where ),
		);
		if ( '' !== (string) $msg ) {
			/* translators: %s: reason */
			$lines[] = sprintf( __( 'Reason: %s', 'transara' ), $msg );
		}
		if ( ! $down && $m->last_change ) {
			/* translators: %s: duration */
			$lines[] = sprintf( __( 'It was down for %s.', 'transara' ), human_time_diff( $m->last_change ) );
		}
		self::send(
			$down ? 'monitor_down' : 'monitor_up',
			$subject,
			$lines,
			array(
				'monitor' => array(
					'id'     => $m->id,
					'name'   => $m->name,
					'type'   => $m->type,
					'target' => $m->target,
				),
				'status'  => $status,
				'reason'  => (string) $msg,
			)
		);
	}

	/**
	 * Send an alert by email and webhook.
	 *
	 * @param string   $event   Event key.
	 * @param string   $subject Subject.
	 * @param string[] $lines   Text lines.
	 * @param array    $data    Data for the webhook.
	 * @param bool     $force   Send even during maintenance.
	 * @return bool Whether anything was sent.
	 */
	public static function send( $event, $subject, $lines, $data = array(), $force = false ) {
		if ( ! $force && self::in_maintenance() ) {
			return false;
		}
		/**
		 * Change or stop an alert (add-ons add more channels here).
		 *
		 * @param array $alert event, subject, lines, data.
		 */
		$alert = apply_filters( 'transara_alert', compact( 'event', 'subject', 'lines', 'data' ) );
		if ( ! is_array( $alert ) ) {
			return false;
		}
		$sent   = false;
		$emails = array_filter( array_map( 'sanitize_email', Transara_Settings::lines( 'alert_emails' ) ) );
		if ( ! $emails ) {
			$emails = array( get_option( 'admin_email' ) );
		}
		$body  = implode( "\n", $alert['lines'] ) . "\n\n" . sprintf( /* translators: %s: link */ __( 'Monitoring: %s', 'transara' ), admin_url( 'admin.php?page=transara-monitoring' ) ) . "\n-- \n" . __( 'Sent by Transara', 'transara' );
		$sent  = wp_mail( $emails, '[Transara] ' . $alert['subject'], $body ) || $sent;
		$hook  = esc_url_raw( (string) Transara_Settings::get( 'alert_webhook' ) );
		if ( '' !== $hook ) {
			$text = $alert['subject'] . "\n" . implode( "\n", array_slice( $alert['lines'], 1 ) );
			if ( false !== strpos( $hook, 'hooks.slack.com' ) ) {
				$payload = array( 'text' => $text );
			} elseif ( false !== strpos( $hook, 'discord.com/api/webhooks' ) || false !== strpos( $hook, 'discordapp.com/api/webhooks' ) ) {
				$payload = array( 'content' => $text );
			} elseif ( false !== strpos( $hook, '.webhook.office.com' ) || false !== strpos( $hook, 'logic.azure.com' ) ) {
				$payload = array( 'text' => nl2br( esc_html( $text ) ) );
			} else {
				$payload = array_merge(
					array(
						'event'   => $alert['event'],
						'subject' => $alert['subject'],
						'message' => implode( "\n", $alert['lines'] ),
						'site'    => home_url(),
						'time'    => gmdate( 'c' ),
					),
					(array) $alert['data']
				);
			}
			$res  = wp_remote_post(
				$hook,
				array(
					'timeout' => 10,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $payload ),
				)
			);
			$sent = ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) < 400 ) || $sent;
		}
		/**
		 * An alert was sent.
		 *
		 * @param array $alert Alert.
		 */
		do_action( 'transara_alert_sent', $alert );
		return $sent;
	}

	/**
	 * Test and maintenance buttons under the Alerts section.
	 *
	 * @param string $section Section.
	 */
	public static function alerts_box( $section ) {
		if ( 'alerts' !== $section ) {
			return;
		}
		echo '<p>' . Transara_Admin::action_button( 'transara_test_alert', __( 'Send a test alert', 'transara' ), 'secondary' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		if ( self::in_maintenance() ) {
			echo '<span class="transara-pill is-warn">' . esc_html( sprintf( /* translators: %s: time left */ __( 'Alerts paused for %s', 'transara' ), human_time_diff( (int) get_option( 'transara_maintenance_until' ) ) ) ) . '</span> ' . Transara_Admin::action_button( 'transara_maintenance', __( 'Resume alerts', 'transara' ), 'secondary', array( 'hours' => 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		} else {
			echo Transara_Admin::action_button( 'transara_maintenance', __( 'Pause alerts for 1 hour (maintenance)', 'transara' ), 'secondary', array( 'hours' => 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		}
		echo '</p>';
	}

	/**
	 * Admin action: test alert.
	 */
	public static function handle_test() {
		check_admin_referer( 'transara_test_alert' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		$ok = self::send( 'test', __( 'Test alert', 'transara' ), array( __( 'This is a test alert from Transara. Alerts reach you here.', 'transara' ) ), array(), true );
		Transara_Admin::flash( $ok ? __( 'Test alert sent. Check your email and webhook.', 'transara' ) : __( 'The test alert could not be sent. Check that your site can send email, and the webhook address.', 'transara' ), $ok ? 'success' : 'error' );
		Transara_Admin::back();
	}

	/**
	 * Admin action: maintenance on or off.
	 */
	public static function handle_maintenance() {
		check_admin_referer( 'transara_maintenance' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		$hours = isset( $_GET['hours'] ) ? absint( $_GET['hours'] ) : 0;
		if ( $hours ) {
			update_option( 'transara_maintenance_until', time() + min( 72, $hours ) * HOUR_IN_SECONDS, false );
		} else {
			delete_option( 'transara_maintenance_until' );
		}
		Transara_Admin::back();
	}
}
