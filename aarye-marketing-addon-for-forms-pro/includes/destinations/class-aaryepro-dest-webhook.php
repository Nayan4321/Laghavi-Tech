<?php
/**
 * Webhook: any CRM, Zapier, Make, n8n or your own API.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Webhook.
 */
class AARYEPRO_Dest_Webhook extends AARYEPRO_Destination {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'webhook';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return __( 'Webhook / custom CRM', 'aarye-marketing-addon-for-forms-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Send each entry as JSON to any address: your own CRM or API, Zapier, Make, n8n, Pipedrive, Salesforce flows and more. Signed, so the receiver can check it came from your site.', 'aarye-marketing-addon-for-forms-pro' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'url'      => array(
				'label'       => __( 'Address (URL)', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'url',
				'placeholder' => 'https://crm.example.com/api/leads',
				'required'    => true,
			),
			'secret'   => array(
				'label' => __( 'Signing secret (optional)', 'aarye-marketing-addon-for-forms-pro' ),
				'type'  => 'password',
				'help'  => __( 'Adds the header X-Aarye-Signature: sha256=HMAC of the body with this secret.', 'aarye-marketing-addon-for-forms-pro' ),
			),
			'headers'  => array(
				'label'       => __( 'Extra headers (optional)', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'textarea',
				'secret'      => true,
				'placeholder' => "Authorization: Bearer YOUR_TOKEN\nX-Api-Key: …",
				'help'        => __( 'One per line, for example an API key your CRM needs.', 'aarye-marketing-addon-for-forms-pro' ),
			),
			'format'   => array(
				'label'   => __( 'Body', 'aarye-marketing-addon-for-forms-pro' ),
				'type'    => 'select',
				'options' => array(
					'full' => __( 'Full entry (JSON)', 'aarye-marketing-addon-for-forms-pro' ),
					'flat' => __( 'Flat: one key per column, like the Google Sheet (JSON)', 'aarye-marketing-addon-for-forms-pro' ),
				),
			),
			'updates'  => array(
				'label' => __( 'Status changes', 'aarye-marketing-addon-for-forms-pro' ),
				'type'  => 'checkbox',
				'help'  => __( 'Send the entry again (event entry.updated) when its lead status changes.', 'aarye-marketing-addon-for-forms-pro' ),
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
			__( 'Paste the address that should receive new entries (POST, JSON).', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'If your CRM needs an API key, add it as an extra header.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Click <b>Test connection</b>: a sample entry with event <code>test</code> is sent.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Any reply from 200 to 299 counts as received. Other replies are shown in the log; busy or unreachable servers are tried again for up to 7 days.', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * Re-send on status change when switched on.
	 *
	 * @return bool
	 */
	public function resend_on_status() {
		return '' !== $this->get( 'updates' );
	}

	/**
	 * Headers for a body.
	 *
	 * @param string $body JSON.
	 * @param string $event Event name.
	 * @return array
	 */
	private function headers( $body, $event ) {
		$headers = array(
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'Aarye/' . AARYEPRO_VERSION . '; ' . home_url(),
			'X-Aarye-Event' => $event,
		);
		foreach ( preg_split( '/\r\n|\r|\n/', $this->get( 'headers' ) ) as $line ) {
			if ( false !== strpos( $line, ':' ) ) {
				list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
				if ( preg_match( '/^[A-Za-z0-9-]+$/', $name ) ) {
					$headers[ $name ] = $value;
				}
			}
		}
		if ( '' !== $this->get( 'secret' ) ) {
			$headers['X-Aarye-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $this->get( 'secret' ) );
		}
		return $headers;
	}

	/**
	 * Body for an entry.
	 *
	 * @param object $entry Entry.
	 * @param string $event Event.
	 * @return array
	 */
	private function body( $entry, $event ) {
		if ( 'flat' === $this->get( 'format' ) ) {
			return array_merge(
				array(
					'event'   => $event,
					'lead_id' => AARYEPRO_Payload::lead_id( $entry ),
					'status'  => $entry->status,
				),
				AARYE_Sheets::row( $entry )
			);
		}
		return AARYEPRO_Payload::entry( $entry, $event );
	}

	/**
	 * Send.
	 *
	 * @param object $entry Entry.
	 * @param object $job   Job.
	 * @return array
	 */
	public function send( $entry, $job ) {
		$event = '' !== (string) $job->remote_id ? 'entry.updated' : 'entry.created';
		$body  = wp_json_encode( $this->body( $entry, $event ) );
		$r     = $this->http( 'POST', $this->get( 'url' ), $body, $this->headers( $body, $event ) );
		$res   = $this->http_result( $r );
		if ( $res['ok'] ) {
			$res['remote_id'] = 'sent';
		}
		return $res;
	}

	/**
	 * Test with a sample entry.
	 *
	 * @return array
	 */
	public function test() {
		$sample = (object) array(
			'id'              => 0,
			'created_at'      => current_time( 'mysql', true ),
			'status'          => 'new',
			'form_plugin'     => 'test',
			'form_id'         => '0',
			'form_name'       => 'Test form',
			'source_entry_id' => '',
			'name'            => 'Test Lead',
			'email'           => 'test@example.com',
			'phone'           => '',
			'channel'         => 'paid',
			'source'          => 'google_ads',
			'source_label'    => 'Google Ads',
			'campaign'        => 'Test campaign',
			'ad_group'        => '',
			'ad'              => '',
			'keyword'         => '',
			'click_param'     => 'gclid',
			'click_id'        => 'test',
			'confirmed'       => 1,
			'landing_page'    => home_url( '/' ),
			'referrer'        => '',
			'page_url'        => home_url( '/' ),
			'language'        => get_locale(),
			'device'          => 'desktop',
			'browser'         => '',
			'ip'              => '',
			'visitor_id'      => '',
			'visits'          => 1,
			'fields'          => wp_json_encode( array( array( 'label' => 'Message', 'name' => 'message', 'type' => 'textarea', 'value' => 'This is a test from Aarye.' ) ) ),
			'files'           => '[]',
			'attribution'     => '{}',
		);
		$body   = wp_json_encode( $this->body( $sample, 'test' ) );
		return $this->http_result( $this->http( 'POST', $this->get( 'url' ), $body, $this->headers( $body, 'test' ) ) );
	}
}
