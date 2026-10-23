<?php
/**
 * Zoho CRM: add or update a Lead (or Contact), matched by email, with the source of the lead.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zoho CRM.
 */
class AARYEPRO_Dest_Zoho_Crm extends AARYEPRO_Destination {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'zoho_crm';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Zoho CRM';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Adds each entry as a Lead (or Contact) in Zoho CRM, updating the record when the email already exists, with the ad, campaign and every form field in the description.', 'aarye-marketing-addon-for-forms-pro' );
	}

	/**
	 * Zoho data centres.
	 *
	 * @return array<string, string>
	 */
	public static function data_centres() {
		return array(
			'com'    => __( 'United States (zoho.com)', 'aarye-marketing-addon-for-forms-pro' ),
			'eu'     => __( 'Europe (zoho.eu)', 'aarye-marketing-addon-for-forms-pro' ),
			'in'     => __( 'India (zoho.in)', 'aarye-marketing-addon-for-forms-pro' ),
			'com.au' => __( 'Australia (zoho.com.au)', 'aarye-marketing-addon-for-forms-pro' ),
			'jp'     => __( 'Japan (zoho.jp)', 'aarye-marketing-addon-for-forms-pro' ),
			'ca'     => __( 'Canada (zohocloud.ca)', 'aarye-marketing-addon-for-forms-pro' ),
			'sa'     => __( 'Saudi Arabia (zoho.sa)', 'aarye-marketing-addon-for-forms-pro' ),
			'uk'     => __( 'United Kingdom (zoho.uk)', 'aarye-marketing-addon-for-forms-pro' ),
			'com.cn' => __( 'China (zoho.com.cn)', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * Accounts server of the chosen data centre.
	 *
	 * @return string
	 */
	private function accounts() {
		$dc = $this->get( 'dc' );
		$dc = isset( self::data_centres()[ $dc ] ) ? $dc : 'com';
		return 'ca' === $dc ? 'https://accounts.zohocloud.ca' : 'https://accounts.zoho.' . $dc;
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'dc'            => array(
				'label'   => __( 'Data centre', 'aarye-marketing-addon-for-forms-pro' ),
				'type'    => 'select',
				'options' => self::data_centres(),
				'help'    => __( 'Where your Zoho account is: look at the address when you are logged in to Zoho CRM.', 'aarye-marketing-addon-for-forms-pro' ),
			),
			'client_id'     => array(
				'label'    => __( 'Client ID', 'aarye-marketing-addon-for-forms-pro' ),
				'type'     => 'text',
				'required' => true,
			),
			'client_secret' => array(
				'label'    => __( 'Client secret', 'aarye-marketing-addon-for-forms-pro' ),
				'type'     => 'password',
				'required' => true,
			),
			'code'          => array(
				'label' => __( 'Grant code', 'aarye-marketing-addon-for-forms-pro' ),
				'type'  => 'password',
				'help'  => __( 'Paste the code from the Self Client and save within its validity time. It is exchanged for a permanent token at once.', 'aarye-marketing-addon-for-forms-pro' ),
			),
			'module'        => array(
				'label'   => __( 'Add entries as', 'aarye-marketing-addon-for-forms-pro' ),
				'type'    => 'select',
				'options' => array(
					'Leads'    => __( 'Leads', 'aarye-marketing-addon-for-forms-pro' ),
					'Contacts' => __( 'Contacts', 'aarye-marketing-addon-for-forms-pro' ),
				),
			),
			'lead_source'   => array(
				'label'       => __( 'Lead Source value (optional)', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'text',
				'placeholder' => 'Advertisement',
				'help'        => __( 'Must be one of the values of the Lead Source picklist in your CRM. You can use tokens, for example {source}, if you added those values.', 'aarye-marketing-addon-for-forms-pro' ),
			),
			'mapping'       => array(
				'label'       => __( 'More fields (optional)', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'textarea',
				'placeholder' => "Company = {field:Company}\nAd_Campaign = {campaign}\nAd_Name = {ad}",
				'help'        => __( 'One per line: field API name = value with tokens. Find API names in Zoho CRM under Setup → Developer Hub → APIs → API names.', 'aarye-marketing-addon-for-forms-pro' ),
			),
		);
	}

	/**
	 * Configured when a token was obtained.
	 *
	 * @return bool
	 */
	public function configured() {
		return '' !== $this->get( 'client_id' ) && '' !== $this->get( 'refresh_token' );
	}

	/**
	 * Steps.
	 *
	 * @return string[]
	 */
	public function steps() {
		return array(
			__( 'Open the Zoho API Console (<code>api-console.zoho.com</code>, or the address for your data centre) and click <b>Add Client → Self Client</b>.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Copy the Client ID and Client Secret into the fields here.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Open <b>Generate Code</b>, enter the scope <code>ZohoCRM.modules.ALL,ZohoCRM.settings.READ</code>, choose 10 minutes and a description, and click Create.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Paste the grant code here and click <b>Save</b> straight away. Then click <b>Test connection</b>.', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * Swap the grant code for a refresh token.
	 *
	 * @param array $values New values.
	 * @param array $old    Old values.
	 * @return array|WP_Error
	 */
	public function on_save( $values, $old ) {
		$code = isset( $values['code'] ) ? trim( (string) $values['code'] ) : '';
		unset( $values['code'] );
		$values['refresh_token'] = isset( $old['refresh_token'] ) ? $old['refresh_token'] : '';
		$values['api_domain']    = isset( $old['api_domain'] ) ? $old['api_domain'] : '';
		if ( isset( $old['dc'], $values['dc'] ) && $old['dc'] !== $values['dc'] && '' === $code ) {
			$values['refresh_token'] = '';
		}
		if ( '' === $code ) {
			return $values;
		}
		$dc       = isset( $values['dc'] ) && isset( self::data_centres()[ $values['dc'] ] ) ? $values['dc'] : 'com';
		$accounts = 'ca' === $dc ? 'https://accounts.zohocloud.ca' : 'https://accounts.zoho.' . $dc;
		$r        = $this->http(
			'POST',
			$accounts . '/oauth/v2/token',
			http_build_query(
				array(
					'grant_type'    => 'authorization_code',
					'client_id'     => isset( $values['client_id'] ) ? $values['client_id'] : '',
					'client_secret' => isset( $values['client_secret'] ) ? $values['client_secret'] : '',
					'code'          => $code,
				)
			),
			array( 'Content-Type' => 'application/x-www-form-urlencoded' )
		);
		if ( empty( $r['json']['refresh_token'] ) ) {
			$why = ! empty( $r['json']['error'] ) ? sanitize_text_field( $r['json']['error'] ) : ( '' !== $r['error'] ? $r['error'] : 'HTTP ' . $r['status'] );
			/* translators: %s: Zoho's error. */
			return new WP_Error( 'aaryepro_zoho', sprintf( __( 'Zoho did not accept the grant code (%s). Codes work once and only for a few minutes: generate a new one, check the data centre, and save again.', 'aarye-marketing-addon-for-forms-pro' ), $why ) );
		}
		$values['refresh_token'] = sanitize_text_field( $r['json']['refresh_token'] );
		$values['api_domain']    = ! empty( $r['json']['api_domain'] ) ? esc_url_raw( $r['json']['api_domain'] ) : '';
		delete_transient( 'aaryepro_zoho_token' );
		return $values;
	}

	/**
	 * Access token (cached for 55 minutes).
	 *
	 * @return array{token: string, api: string}|WP_Error
	 */
	private function token() {
		$cached = get_transient( 'aaryepro_zoho_token' );
		if ( is_array( $cached ) && ! empty( $cached['token'] ) ) {
			return $cached;
		}
		$r = $this->http(
			'POST',
			$this->accounts() . '/oauth/v2/token',
			http_build_query(
				array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $this->get( 'client_id' ),
					'client_secret' => $this->get( 'client_secret' ),
					'refresh_token' => $this->get( 'refresh_token' ),
				)
			),
			array( 'Content-Type' => 'application/x-www-form-urlencoded' )
		);
		if ( empty( $r['json']['access_token'] ) ) {
			$why = ! empty( $r['json']['error'] ) ? sanitize_text_field( $r['json']['error'] ) : ( '' !== $r['error'] ? $r['error'] : 'HTTP ' . $r['status'] );
			return new WP_Error( 0 === $r['status'] || $r['status'] >= 500 ? 'retry' : 'auth', $why );
		}
		$api   = ! empty( $r['json']['api_domain'] ) ? $r['json']['api_domain'] : ( '' !== $this->get( 'api_domain' ) ? $this->get( 'api_domain' ) : 'https://www.zohoapis.com' );
		$token = array(
			'token' => (string) $r['json']['access_token'],
			'api'   => untrailingslashit( esc_url_raw( $api ) ),
		);
		set_transient( 'aaryepro_zoho_token', $token, 55 * MINUTE_IN_SECONDS );
		return $token;
	}

	/**
	 * Result for a token error.
	 *
	 * @param WP_Error $error Error.
	 * @return array
	 */
	private function token_error( $error ) {
		if ( 'retry' === $error->get_error_code() ) {
			return $this->result( false, $error->get_error_message(), array( 'retry' => true, 'code' => 'no_answer' ) );
		}
		/* translators: %s: Zoho's error. */
		return $this->result( false, sprintf( __( 'Zoho refused the connection (%s). Generate a new grant code and save it.', 'aarye-marketing-addon-for-forms-pro' ), $error->get_error_message() ), array( 'stop' => true, 'code' => 'auth' ) );
	}

	/**
	 * Send.
	 *
	 * @param object $entry Entry.
	 * @param object $job   Job.
	 * @return array
	 */
	public function send( $entry, $job ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $this->token_error( $token );
		}
		$module               = 'Contacts' === $this->get( 'module' ) ? 'Contacts' : 'Leads';
		list( $first, $last ) = AARYEPRO_Payload::split_name( $entry->name );
		if ( '' === $last ) {
			$last  = '' !== $first ? $first : ( '' !== $entry->email ? $entry->email : __( 'Website lead', 'aarye-marketing-addon-for-forms-pro' ) );
			$first = $first === $last ? '' : $first;
		}
		$record = array_filter(
			array(
				'Last_Name'   => $last,
				'First_Name'  => $first,
				'Email'       => $entry->email,
				'Phone'       => $entry->phone,
				'Lead_Source' => AARYEPRO_Payload::tokens( $this->get( 'lead_source' ), $entry ),
				'Description' => mb_substr( $this->description_text( $entry ), 0, 32000 ),
			),
			'strlen'
		);
		if ( 'Leads' === $module && '' === AARYEPRO_Payload::tokens( '{field:Company}', $entry ) ) {
			$record['Company'] = '' !== $entry->name ? $entry->name : '-'; // Required in Leads.
		}
		$record = array_merge( $record, AARYEPRO_Payload::mapping( $this->get( 'mapping' ), $entry ) );

		$body = array(
			'data'    => array( $record ),
			'trigger' => array( 'workflow' ),
		);
		if ( '' !== $entry->email ) {
			$body['duplicate_check_fields'] = array( 'Email' );
		}
		$r    = $this->http( 'POST', $token['api'] . '/crm/v6/' . $module . '/upsert', $body, array( 'Authorization' => 'Zoho-oauthtoken ' . $token['token'] ) );
		$item = is_array( $r['json'] ) && isset( $r['json']['data'][0] ) ? $r['json']['data'][0] : null;
		if ( $r['status'] >= 200 && $r['status'] < 300 && $item && isset( $item['status'] ) && 'success' === $item['status'] ) {
			$id = isset( $item['details']['id'] ) ? (string) $item['details']['id'] : '';
			/* translators: 1: Lead or Contact, 2: record ID. */
			return $this->result( true, sprintf( __( '%1$s %2$s', 'aarye-marketing-addon-for-forms-pro' ), 'Leads' === $module ? __( 'Lead', 'aarye-marketing-addon-for-forms-pro' ) : __( 'Contact', 'aarye-marketing-addon-for-forms-pro' ), $id ), array( 'remote_id' => $id ) );
		}
		if ( 401 === $r['status'] ) {
			delete_transient( 'aaryepro_zoho_token' );
			return $this->result( false, __( 'Zoho token expired; trying again.', 'aarye-marketing-addon-for-forms-pro' ), array( 'retry' => true, 'code' => 'token' ) );
		}
		$msg = '';
		if ( $item && ! empty( $item['message'] ) ) {
			$msg = sanitize_text_field( $item['message'] ) . ( ! empty( $item['details']['api_name'] ) ? ' (' . sanitize_text_field( $item['details']['api_name'] ) . ')' : '' );
		} elseif ( is_array( $r['json'] ) && ! empty( $r['json']['message'] ) ) {
			$msg = sanitize_text_field( $r['json']['message'] );
		}
		$res = $this->http_result( $r, $msg );
		if ( $res['ok'] ) {
			$res = $this->result( false, '' !== $msg ? $msg : __( 'Zoho did not save the record.', 'aarye-marketing-addon-for-forms-pro' ), array( 'code' => 'rejected' ) );
		}
		return $res;
	}

	/**
	 * Description text: source and all fields.
	 *
	 * @param object $e Entry.
	 * @return string
	 */
	private function description_text( $e ) {
		$lines = array(
			/* translators: %s: form name. */
			sprintf( __( 'Form: %s', 'aarye-marketing-addon-for-forms-pro' ), $e->form_name ),
			/* translators: %s: source, e.g. Meta Ads › Summer Sale › Video 1. */
			sprintf( __( 'Came from: %s', 'aarye-marketing-addon-for-forms-pro' ), implode( ' › ', array_filter( array( $e->source_label, $e->campaign, $e->ad_group, $e->ad ) ) ) ),
		);
		if ( '' !== $e->keyword ) {
			/* translators: %s: search keyword. */
			$lines[] = sprintf( __( 'Keyword: %s', 'aarye-marketing-addon-for-forms-pro' ), $e->keyword );
		}
		if ( '' !== (string) $e->landing_page ) {
			/* translators: %s: URL. */
			$lines[] = sprintf( __( 'Landing page: %s', 'aarye-marketing-addon-for-forms-pro' ), $e->landing_page );
		}
		$lines[] = '';
		$lines[] = AARYEPRO_Payload::tokens( '{all_fields}', $e );
		foreach ( AARYEPRO_Payload::files( $e ) as $file ) {
			$lines[] = $file['name'] . ': ' . $file['url'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Test.
	 *
	 * @return array
	 */
	public function test() {
		delete_transient( 'aaryepro_zoho_token' );
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $this->token_error( $token );
		}
		$module = 'Contacts' === $this->get( 'module' ) ? 'Contacts' : 'Leads';
		$r      = $this->http( 'GET', $token['api'] . '/crm/v6/settings/fields?module=' . $module, null, array( 'Authorization' => 'Zoho-oauthtoken ' . $token['token'] ) );
		if ( 200 === $r['status'] ) {
			/* translators: %s: Leads or Contacts. */
			return $this->result( true, sprintf( __( 'Connected to Zoho CRM (%s).', 'aarye-marketing-addon-for-forms-pro' ), $module ) );
		}
		return $this->http_result( $r, is_array( $r['json'] ) && ! empty( $r['json']['message'] ) ? sanitize_text_field( $r['json']['message'] ) : '' );
	}
}
