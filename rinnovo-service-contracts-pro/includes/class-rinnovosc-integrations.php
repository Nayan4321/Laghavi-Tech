<?php
/**
 * Client workspace and monthly tasks: when a contract starts and on every renewal, the client is added to
 * FluentCRM (with a tag) or Jetpack CRM, the product's monthly task list is created as Trello cards or
 * ClickUp tasks, and a webhook (Zapier, Make, your own system) gets the client, contract and tasks.
 *
 * @package RinnovoContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integrations.
 */
class RINNOVOSC_Integrations {

	const TD = 'rinnovo-service-contracts-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rinnovosc_contract_started', array( __CLASS__, 'started' ), 10, 3 );
		add_action( 'rinnovosc_contract_renewed', array( __CLASS__, 'renewed' ), 10, 3 );
		add_action( 'rinnovosc_hourly', array( __CLASS__, 'retry' ) );
	}

	/**
	 * Contract started.
	 *
	 * @param object   $sub      Subscription.
	 * @param array    $contract Contract.
	 * @param WC_Order $order    Order.
	 */
	public static function started( $sub, $contract, $order ) {
		self::crm( $sub, $contract, $order );
		self::run( 'contract.started', $sub, $contract, $order );
	}

	/**
	 * Contract renewed.
	 *
	 * @param object   $sub      Subscription.
	 * @param array    $contract Contract.
	 * @param WC_Order $order    Order.
	 */
	public static function renewed( $sub, $contract, $order ) {
		self::run( 'contract.renewed', $sub, $contract, $order );
	}

	/**
	 * Send a signed webhook (timestamp + event ID against replays and duplicates); failures are retried.
	 *
	 * @param string $url   Address.
	 * @param string $body  JSON.
	 * @param string $id    Event ID.
	 * @param int    $tries Tries so far.
	 * @return array|WP_Error
	 */
	public static function deliver( $url, $body, $id, $tries = 0 ) {
		$time   = time();
		$secret = (string) RINNOVOSC_Settings::get( 'webhook_secret' );
		$secret = '' !== $secret ? $secret : wp_salt( 'auth' );
		$r      = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'        => 'application/json',
					'X-Rinnovo-Event-Id'  => $id,
					'X-Rinnovo-Timestamp' => (string) $time,
					'X-Rinnovo-Signature' => hash_hmac( 'sha256', $time . '.' . $body, $secret ),
				),
				'body'    => $body,
			)
		);
		$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		if ( ( $code < 200 || $code >= 300 ) && $tries < 3 ) {
			$queue   = (array) get_option( 'rinnovosc_webhook_queue', array() );
			$queue[] = array( $url, $body, $id, $tries + 1, time() + ( 2 ** $tries ) * 15 * MINUTE_IN_SECONDS );
			update_option( 'rinnovosc_webhook_queue', array_slice( $queue, -200 ), false );
		}
		return $r;
	}

	/**
	 * Hourly: send failed webhooks again (15 min, 30 min, 1 hour).
	 */
	public static function retry() {
		$queue = (array) get_option( 'rinnovosc_webhook_queue', array() );
		if ( ! $queue ) {
			return;
		}
		update_option( 'rinnovosc_webhook_queue', array(), false );
		foreach ( $queue as $row ) {
			if ( (int) $row[4] > time() ) {
				$keep   = (array) get_option( 'rinnovosc_webhook_queue', array() );
				$keep[] = $row;
				update_option( 'rinnovosc_webhook_queue', $keep, false );
				continue;
			}
			self::deliver( $row[0], $row[1], $row[2], (int) $row[3] );
		}
	}

	/**
	 * Client name for task titles.
	 *
	 * @param object   $sub      Subscription.
	 * @param array    $contract Contract.
	 * @param WC_Order $order    Order.
	 * @return string
	 */
	private static function client( $sub, $contract, $order ) {
		if ( '' !== $contract['company']['name'] ) {
			return $contract['company']['name'];
		}
		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	/**
	 * Add the client to the CRM.
	 *
	 * @param object   $sub      Subscription.
	 * @param array    $contract Contract.
	 * @param WC_Order $order    Order.
	 */
	private static function crm( $sub, $contract, $order ) {
		$email = $order->get_billing_email();
		try {
			if ( function_exists( 'FluentCrmApi' ) ) {
				$contact = FluentCrmApi( 'contacts' )->createOrUpdate(
					array(
						'email'      => $email,
						'first_name' => $order->get_billing_first_name(),
						'last_name'  => $order->get_billing_last_name(),
						'phone'      => $order->get_billing_phone(),
						'status'     => 'subscribed',
					)
				);
				$tag_name = trim( (string) RINNOVOSC_Settings::get( 'fluent_tag' ) );
				if ( $contact && '' !== $tag_name && class_exists( '\FluentCrm\App\Models\Tag' ) ) {
					$tag = \FluentCrm\App\Models\Tag::where( 'title', $tag_name )->first();
					if ( ! $tag ) {
						$tag = \FluentCrm\App\Models\Tag::create( array( 'title' => $tag_name, 'slug' => sanitize_title( $tag_name ) ) );
					}
					$contact->attachTags( array( $tag->id ) );
				}
			}
			if ( RINNOVOSC_Settings::get( 'jetpack_crm' ) && function_exists( 'zeroBS_integrations_addOrUpdateCustomer' ) ) {
				zeroBS_integrations_addOrUpdateCustomer(
					'rinnovosc',
					$email,
					array(
						'zbsc_email'  => $email,
						'zbsc_fname'  => $order->get_billing_first_name(),
						'zbsc_lname'  => $order->get_billing_last_name(),
						'zbsc_status' => 'Customer',
						'zbsc_hometel' => $order->get_billing_phone(),
					)
				);
			}
		} catch ( Throwable $e ) {
			$order->add_order_note( 'CRM: ' . $e->getMessage() );
		}
	}

	/**
	 * Create this month's tasks and send the webhook.
	 *
	 * @param string   $event    Event.
	 * @param object   $sub      Subscription.
	 * @param array    $contract Contract.
	 * @param WC_Order $order    Order.
	 */
	private static function run( $event, $sub, $contract, $order ) {
		// Once per contract and billing cycle: a retried renewal never creates the month's tasks twice.
		$key  = 'rinnovosc_tasks_' . (int) $sub->id . '_' . ( 'contract.started' === $event ? 'start' : (int) $order->get_id() );
		$done_cycles = (array) get_option( 'rinnovosc_tasks_done', array() );
		if ( isset( $done_cycles[ $key ] ) ) {
			return;
		}
		$done_cycles[ $key ] = time();
		update_option( 'rinnovosc_tasks_done', array_slice( $done_cycles, -2000, null, true ), false );
		$terms  = RINNOVOSC_Product::terms( (int) $sub->product_id );
		$tasks  = RINNOVOSC_Product::lines( $terms['tasks'] );
		$client = self::client( $sub, $contract, $order );
		$month  = wp_date( 'F Y' );
		$desc   = sprintf(
			/* translators: 1: package, 2: order number, 3: deliverables. */
			__( "Package: %1\$s\nOrder #%2\$s\n\nDeliverables:\n%3\$s", self::TD ),
			$sub->name,
			$order->get_order_number(),
			implode( "\n", RINNOVOSC_Product::lines( $contract['deliverables'] ) )
		);
		$done   = array();
		foreach ( $tasks as $task ) {
			$title = $client . ' — ' . $task . ' — ' . $month;
			$s     = RINNOVOSC_Settings::all();
			if ( '' !== $s['trello_key'] && '' !== $s['trello_token'] && '' !== $s['trello_list'] ) {
				$r = wp_remote_post(
					'https://api.trello.com/1/cards',
					array(
						'timeout' => 10,
						'body'    => array(
							'idList' => $s['trello_list'],
							'name'   => $title,
							'desc'   => $desc,
							'key'    => $s['trello_key'],
							'token'  => $s['trello_token'],
						),
					)
				);
				$done[] = 'Trello: ' . ( is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_response_code( $r ) );
			}
			if ( '' !== $s['clickup_token'] && '' !== $s['clickup_list'] ) {
				$r = wp_remote_post(
					'https://api.clickup.com/api/v2/list/' . rawurlencode( $s['clickup_list'] ) . '/task',
					array(
						'timeout' => 10,
						'headers' => array(
							'Authorization' => $s['clickup_token'],
							'Content-Type'  => 'application/json',
						),
						'body'    => wp_json_encode(
							array(
								'name'        => $title,
								'description' => $desc,
							)
						),
					)
				);
				$done[] = 'ClickUp: ' . ( is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_response_code( $r ) );
			}
		}
		$hook = (string) RINNOVOSC_Settings::get( 'webhook' );
		if ( '' !== $hook ) {
			$body = wp_json_encode(
				array(
					'event'    => $event,
					'client'   => array(
						'name'    => $client,
						'email'   => $order->get_billing_email(),
						'phone'   => $order->get_billing_phone(),
						'company' => $contract['company'],
					),
					'contract' => array(
						'id'           => (int) $sub->id,
						'package'      => $sub->name,
						'status'       => $sub->status,
						'amount'       => (float) $sub->recurring_amount,
						'currency'     => $sub->currency,
						'next_payment' => $sub->next_payment,
						'po'           => $contract['po'],
						'deliverables' => RINNOVOSC_Product::lines( $contract['deliverables'] ),
						'quota'        => $contract['quota'],
					),
					'tasks'    => $tasks,
					'month'    => $month,
					'order'    => $order->get_id(),
				)
			);
			$r      = self::deliver( $hook, $body, $key );
			$done[] = 'Webhook: ' . ( is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_response_code( $r ) );
		}
		if ( $done ) {
			/* translators: %s: results. */
			$order->add_order_note( sprintf( __( 'Monthly tasks sent: %s', self::TD ), implode( ', ', array_unique( $done ) ) ) );
		}
	}
}
