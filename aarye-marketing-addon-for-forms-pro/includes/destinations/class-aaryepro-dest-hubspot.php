<?php
/**
 * HubSpot: create or update the contact, and add a note with the entry and where it came from.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * HubSpot.
 */
class AARYEPRO_Dest_Hubspot extends AARYEPRO_Destination {

	const API = 'https://api.hubapi.com';

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'hubspot';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'HubSpot';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Creates or updates the contact (matched by email) and adds a note with every form field and the ad, campaign or website the lead came from.', 'aarye-marketing-addon-for-forms-pro' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'token'   => array(
				'label'       => __( 'Private app access token', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'password',
				'placeholder' => 'pat-…',
				'required'    => true,
			),
			'note'    => array(
				'label' => __( 'Note', 'aarye-marketing-addon-for-forms-pro' ),
				'type'  => 'checkbox',
				'help'  => __( 'Add a note to the contact with the form entry and its source.', 'aarye-marketing-addon-for-forms-pro' ),
			),
			'mapping' => array(
				'label'       => __( 'More contact properties (optional)', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'textarea',
				'placeholder' => "lifecyclestage = lead\nhs_lead_status = NEW\ncompany = {field:Company}\nlead_source_campaign = {campaign}",
				'help'        => __( 'One per line: HubSpot internal property name = value. Values can use tokens such as {campaign}, {ad}, {source}, {channel}, {keyword}, {landing_page} or {field:Your field label}. Custom properties must exist in HubSpot first.', 'aarye-marketing-addon-for-forms-pro' ),
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
			__( 'In HubSpot, open <b>Settings → Integrations → Private Apps</b> (or <b>Development → Legacy apps</b>) and create an app.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Under Scopes, tick <code>crm.objects.contacts.read</code> and <code>crm.objects.contacts.write</code>.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Create the app, copy its access token and paste it here.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Optional: add custom contact properties in HubSpot (for example "Ad campaign") and map them below with tokens.', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * Call the API.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path.
	 * @param array|null $body   Body.
	 * @return array
	 */
	private function api( $method, $path, $body = null ) {
		return $this->http( $method, self::API . $path, $body, array( 'Authorization' => 'Bearer ' . $this->get( 'token' ) ) );
	}

	/**
	 * HubSpot's error text.
	 *
	 * @param array $r Reply.
	 * @return array
	 */
	private function fail( $r ) {
		$msg = is_array( $r['json'] ) && ! empty( $r['json']['message'] ) ? sanitize_text_field( $r['json']['message'] ) : '';
		$res = $this->http_result( $r, $msg );
		if ( 401 === $r['status'] ) {
			$res['stop']    = true;
			$res['message'] = __( 'HubSpot did not accept the access token. Paste a new one.', 'aarye-marketing-addon-for-forms-pro' );
		}
		return $res;
	}

	/**
	 * Send.
	 *
	 * @param object $entry Entry.
	 * @param object $job   Job.
	 * @return array
	 */
	public function send( $entry, $job ) {
		list( $first, $last ) = AARYEPRO_Payload::split_name( $entry->name );
		$props                = array_filter(
			array(
				'email'     => $entry->email,
				'firstname' => $first,
				'lastname'  => $last,
				'phone'     => $entry->phone,
			),
			'strlen'
		);
		$props = array_merge( $props, AARYEPRO_Payload::mapping( $this->get( 'mapping' ), $entry ) );
		if ( ! $props ) {
			return $this->result( false, __( 'This entry has no email, name or phone to create a contact with.', 'aarye-marketing-addon-for-forms-pro' ), array( 'code' => 'no_contact' ) );
		}

		$id = (string) $job->remote_id;
		if ( '' === $id && '' !== $entry->email ) {
			$found = $this->api(
				'POST',
				'/crm/v3/objects/contacts/search',
				array(
					'filterGroups' => array( array( 'filters' => array( array( 'propertyName' => 'email', 'operator' => 'EQ', 'value' => $entry->email ) ) ) ),
					'limit'        => 1,
				)
			);
			if ( 200 !== $found['status'] ) {
				return $this->fail( $found );
			}
			$id = ! empty( $found['json']['results'][0]['id'] ) ? (string) $found['json']['results'][0]['id'] : '';
		}
		$r = '' !== $id
			? $this->api( 'PATCH', '/crm/v3/objects/contacts/' . rawurlencode( $id ), array( 'properties' => $props ) )
			: $this->api( 'POST', '/crm/v3/objects/contacts', array( 'properties' => $props ) );
		if ( $r['status'] < 200 || $r['status'] >= 300 ) {
			return $this->fail( $r );
		}
		$id = ! empty( $r['json']['id'] ) ? (string) $r['json']['id'] : $id;

		if ( '' !== $this->get( 'note' ) && '' === (string) $job->remote_id ) {
			$n = $this->api(
				'POST',
				'/crm/v3/objects/notes',
				array(
					'properties'   => array(
						'hs_timestamp' => (string) ( strtotime( $entry->created_at . ' UTC' ) * 1000 ),
						'hs_note_body' => $this->note( $entry ),
					),
					'associations' => array(
						array(
							'to'    => array( 'id' => $id ),
							'types' => array(
								array(
									'associationCategory' => 'HUBSPOT_DEFINED',
									'associationTypeId'   => 202,
								),
							),
						),
					),
				)
			);
			if ( $n['status'] < 200 || $n['status'] >= 300 ) {
				$res              = $this->fail( $n );
				$res['remote_id'] = $id; // The contact exists: next try only adds the note.
				$res['message']   = __( 'Contact saved, but the note was not: ', 'aarye-marketing-addon-for-forms-pro' ) . $res['message'];
				return $res;
			}
		}
		/* translators: %s: HubSpot contact ID. */
		return $this->result( true, sprintf( __( 'Contact %s', 'aarye-marketing-addon-for-forms-pro' ), $id ), array( 'remote_id' => $id ) );
	}

	/**
	 * Note text (HTML).
	 *
	 * @param object $e Entry.
	 * @return string
	 */
	private function note( $e ) {
		$source = array_filter( array( $e->source_label, $e->campaign, $e->ad_group, $e->ad ) );
		$html   = '<p><strong>' . esc_html( $e->form_name ) . '</strong> · ' . esc_html( implode( ' › ', $source ) ) . ( '' !== $e->keyword ? ' · "' . esc_html( $e->keyword ) . '"' : '' ) . '</p><ul>';
		foreach ( AARYE_Entries::json( $e, 'fields' ) as $field ) {
			$html .= '<li><strong>' . esc_html( '' !== $field['label'] ? $field['label'] : $field['name'] ) . ':</strong> ' . esc_html( $field['value'] ) . '</li>';
		}
		foreach ( AARYEPRO_Payload::files( $e ) as $file ) {
			$html .= '<li><a href="' . esc_url( $file['url'] ) . '">' . esc_html( $file['name'] ) . '</a></li>';
		}
		$html .= '</ul>';
		if ( '' !== (string) $e->landing_page ) {
			$html .= '<p>' . esc_html__( 'Landing page:', 'aarye-marketing-addon-for-forms-pro' ) . ' ' . esc_html( $e->landing_page ) . '</p>';
		}
		return $html;
	}

	/**
	 * Test.
	 *
	 * @return array
	 */
	public function test() {
		$r = $this->api( 'GET', '/crm/v3/objects/contacts?limit=1' );
		if ( 200 === $r['status'] ) {
			return $this->result( true, __( 'Connected to HubSpot.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		if ( 403 === $r['status'] ) {
			return $this->result( false, __( 'The token works but is missing the contacts scopes (crm.objects.contacts.read and .write).', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		return $this->fail( $r );
	}
}
