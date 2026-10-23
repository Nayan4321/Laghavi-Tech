<?php
/**
 * Laghavi Ads Hub: real leads for Profit & tracking (lead-sync API v1). Sends no personal data.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Laghavi Ads Hub.
 */
class AARYEPRO_Dest_Hub extends AARYEPRO_Destination {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'hub';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Laghavi Ads Hub';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Real leads in your marketing dashboard: compare each ad network\'s reported results with real form leads, and see the cost per real lead by campaign and ad. Only the source of each lead is sent, never names, emails, phone numbers or messages.', 'aarye-marketing-addon-for-forms-pro' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'url'        => array(
				'label'       => __( 'Hub address', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'url',
				'placeholder' => 'https://hub.laghavi.site',
				'required'    => true,
			),
			'key'        => array(
				'label'       => __( 'Lead key', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'password',
				'placeholder' => 'lgpl_…',
				'required'    => true,
			),
			'lead_value' => array(
				'label' => __( 'Value of a lead (optional)', 'aarye-marketing-addon-for-forms-pro' ),
				'type'  => 'number',
				'help'  => __( 'What a lead is worth to you on average, used for return on ad spend.', 'aarye-marketing-addon-for-forms-pro' ),
			),
			'currency'   => array(
				'label'       => __( 'Currency', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'text',
				'placeholder' => 'AED',
			),
			'form_stats' => array(
				'label' => __( 'Form analytics', 'aarye-marketing-addon-for-forms-pro' ),
				'type'  => 'checkbox',
				'help'  => __( 'Also send each day\'s form views, starts, submits and abandons.', 'aarye-marketing-addon-for-forms-pro' ),
			),
		);
	}

	/**
	 * Steps.
	 *
	 * @return string[]
	 */
	public function steps() {
		return array(
			__( 'In Laghavi Ads Hub, open <b>Profit &amp; tracking → Real leads</b>.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Create a lead key for this website. The page also shows the hub address.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Paste the hub address and the key here, save, and click <b>Test connection</b>.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'New leads appear in the hub within a minute. When you change a lead\'s status here (for example to Won or Spam), the hub is updated too.', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * Re-send when the status changes.
	 *
	 * @return bool
	 */
	public function resend_on_status() {
		return true;
	}

	/**
	 * Up to 100 leads per request.
	 *
	 * @return int
	 */
	public function batch_size() {
		return 100;
	}

	/**
	 * POST to the hub.
	 *
	 * @param array $body Body.
	 * @return array From http().
	 */
	private function post( $body ) {
		$body = array_merge(
			array(
				'site'           => home_url(),
				'plugin_version' => AARYEPRO_VERSION,
			),
			$body
		);
		return $this->http( 'POST', untrailingslashit( $this->get( 'url' ) ) . '/lead-sync', $body, array( 'X-Laghavi-Key' => $this->get( 'key' ) ) );
	}

	/**
	 * The hub's own answer for an error.
	 *
	 * @param array $r From http().
	 * @return array
	 */
	private function error( $r ) {
		$code    = is_array( $r['json'] ) && ! empty( $r['json']['code'] ) ? sanitize_key( $r['json']['code'] ) : '';
		$message = is_array( $r['json'] ) && ! empty( $r['json']['error'] ) ? sanitize_text_field( $r['json']['error'] ) : '';
		if ( 401 === $r['status'] || 403 === $r['status'] || in_array( $code, array( 'invalid_key', 'no_access' ), true ) ) {
			// Wrong or revoked key, or a plan without lead tracking: retrying cannot help.
			$default = 403 === $r['status'] ? __( 'Lead tracking is not included in this Laghavi Ads Hub plan.', 'aarye-marketing-addon-for-forms-pro' ) : __( 'This lead key is not valid or was revoked in the Hub. Create a new key in the hub and paste it here.', 'aarye-marketing-addon-for-forms-pro' );
			return $this->result(
				false,
				'' !== $message ? $message : $default,
				array(
					'stop' => true,
					'code' => '' !== $code ? $code : ( 403 === $r['status'] ? 'no_access' : 'invalid_key' ),
				)
			);
		}
		$result = $this->http_result( $r, $message );
		if ( '' !== $code ) {
			$result['code'] = $code;
		}
		return $result;
	}

	/**
	 * Send one lead.
	 *
	 * @param object $entry Entry.
	 * @param object $job   Job.
	 * @return array
	 */
	public function send( $entry, $job ) {
		$results = $this->send_batch(
			array(
				array(
					'entry' => $entry,
					'job'   => $job,
				),
			)
		);
		return reset( $results );
	}

	/**
	 * Send up to 100 leads in one request. If the hub rejects one lead (422 with its index), that lead is
	 * marked as failed and the others are sent again without it.
	 *
	 * @param array $items Entries with jobs.
	 * @return array Results by job ID.
	 */
	public function send_batch( $items ) {
		$out   = array();
		$items = array_values( $items );
		for ( $round = 0; $round < 5 && $items; $round++ ) {
			$leads = array();
			foreach ( $items as $item ) {
				$leads[] = AARYEPRO_Payload::hub_lead( $item['entry'] );
			}
			$r = $this->post(
				array(
					'action' => 'leads',
					'leads'  => $leads,
				)
			);
			if ( 200 === $r['status'] && is_array( $r['json'] ) && ! empty( $r['json']['ok'] ) ) {
				/* translators: 1: added, 2: updated. */
				$note = sprintf( __( 'Sent (hub: %1$d added, %2$d updated in this batch)', 'aarye-marketing-addon-for-forms-pro' ), isset( $r['json']['added'] ) ? (int) $r['json']['added'] : 0, isset( $r['json']['updated'] ) ? (int) $r['json']['updated'] : 0 );
				foreach ( $items as $item ) {
					$out[ (int) $item['job']->id ] = $this->result( true, $note );
				}
				return $out;
			}
			if ( 422 === $r['status'] && is_array( $r['json'] ) && isset( $r['json']['index'] ) && isset( $items[ (int) $r['json']['index'] ] ) ) {
				$bad = (int) $r['json']['index'];
				$msg = ! empty( $r['json']['error'] ) ? sanitize_text_field( $r['json']['error'] ) : __( 'The hub did not accept this lead.', 'aarye-marketing-addon-for-forms-pro' );
				$out[ (int) $items[ $bad ]['job']->id ] = $this->result( false, $msg, array( 'code' => 'bad_request' ) );
				array_splice( $items, $bad, 1 );
				continue;
			}
			$error = $this->error( $r );
			foreach ( $items as $item ) {
				$out[ (int) $item['job']->id ] = $error;
			}
			return $out;
		}
		return $out;
	}

	/**
	 * Test: "hello".
	 *
	 * @return array
	 */
	public function test() {
		$r = $this->post( array( 'action' => 'hello' ) );
		if ( 200 === $r['status'] && is_array( $r['json'] ) && ! empty( $r['json']['ok'] ) ) {
			$client = ! empty( $r['json']['client'] ) ? sanitize_text_field( $r['json']['client'] ) : '';
			/* translators: %s: client name in the hub. */
			return $this->result( true, '' !== $client ? sprintf( __( 'Connected to the hub as "%s".', 'aarye-marketing-addon-for-forms-pro' ), $client ) : __( 'Connected to the hub.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		if ( 404 === $r['status'] ) {
			return $this->result( false, __( 'The hub address did not answer. Check it, and that the hub is updated to version 2026.10.22.1 or later.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		return $this->error( $r );
	}

	/**
	 * Send a day's form analytics.
	 *
	 * @param string $date  Y-m-d (site time).
	 * @param array  $forms Rows: form, views, starts, submits, abandons, top_abandon_field.
	 * @return array
	 */
	public function send_form_stats( $date, $forms ) {
		$r = $this->post(
			array(
				'action' => 'form_stats',
				'date'   => $date,
				'forms'  => array_slice( array_values( $forms ), 0, 200 ),
			)
		);
		if ( 200 === $r['status'] && is_array( $r['json'] ) && ! empty( $r['json']['ok'] ) ) {
			return $this->result( true );
		}
		return $this->error( $r );
	}
}
