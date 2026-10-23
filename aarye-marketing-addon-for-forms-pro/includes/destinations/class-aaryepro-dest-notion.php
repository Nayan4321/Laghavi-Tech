<?php
/**
 * Notion: one page per entry in a database, with every field in the page.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Notion.
 */
class AARYEPRO_Dest_Notion extends AARYEPRO_Destination {

	const API     = 'https://api.notion.com/v1/';
	const VERSION = '2022-06-28';

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'notion';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Notion';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Adds each entry as a page in a Notion database. Database properties named like the columns (Source, Campaign, Ad, Email…) are filled in, and the page holds every form field.', 'aarye-marketing-addon-for-forms-pro' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'token'    => array(
				'label'       => __( 'Integration secret', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'password',
				'placeholder' => 'ntn_…',
				'required'    => true,
			),
			'database' => array(
				'label'    => __( 'Database ID or link', 'aarye-marketing-addon-for-forms-pro' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'Paste the database\'s link (Share → Copy link); the ID is taken from it.', 'aarye-marketing-addon-for-forms-pro' ),
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
			__( 'Open <b>notion.so/profile/integrations</b>, create an internal integration for your workspace and copy its secret.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Create a database (full page table). Optional: add properties named <code>Email</code> (email), <code>Phone</code> (phone), <code>Source</code>, <code>Channel</code> (select), <code>Campaign</code>, <code>Ad</code>, <code>Keyword</code>, <code>Form</code>, <code>Status</code> (select), <code>Date</code> (date), <code>Landing page</code> (URL).', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'In the database, open ••• → <b>Connections</b> and add your integration.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Paste the secret and the database link here.', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * Database ID from an ID or a link.
	 *
	 * @return string
	 */
	private function database_id() {
		$value = $this->get( 'database' );
		if ( preg_match( '/([0-9a-f]{32})/i', str_replace( '-', '', wp_parse_url( $value, PHP_URL_PATH ) ? wp_parse_url( $value, PHP_URL_PATH ) : $value ), $m ) ) {
			return strtolower( $m[1] );
		}
		return preg_replace( '/[^0-9a-f-]/i', '', $value );
	}

	/**
	 * API call.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path.
	 * @param array|null $body   Body.
	 * @return array
	 */
	private function api( $method, $path, $body = null ) {
		return $this->http(
			$method,
			self::API . $path,
			$body,
			array(
				'Authorization'  => 'Bearer ' . $this->get( 'token' ),
				'Notion-Version' => self::VERSION,
			)
		);
	}

	/**
	 * Database properties (name => type).
	 *
	 * @return array<string, string>|array Error result.
	 */
	private function schema() {
		$r = $this->api( 'GET', 'databases/' . $this->database_id() );
		if ( 200 !== $r['status'] || empty( $r['json']['properties'] ) ) {
			return array( 'error' => $r );
		}
		$out = array();
		foreach ( $r['json']['properties'] as $name => $prop ) {
			$out[ $name ] = $prop['type'];
		}
		return array( 'props' => $out );
	}

	/**
	 * Notion's error.
	 *
	 * @param array $r Reply.
	 * @return array
	 */
	private function fail( $r ) {
		$res = $this->http_result( $r, is_array( $r['json'] ) && ! empty( $r['json']['message'] ) ? sanitize_text_field( $r['json']['message'] ) : '' );
		if ( 401 === $r['status'] ) {
			$res['stop'] = true;
		}
		if ( 404 === $r['status'] ) {
			$res['message'] = __( 'Notion could not find the database. Check the link, and that the integration is added under the database\'s ••• → Connections.', 'aarye-marketing-addon-for-forms-pro' );
		}
		return $res;
	}

	/**
	 * Text in Notion's rich text format.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	private static function rich( $text ) {
		return array(
			array(
				'type' => 'text',
				'text' => array( 'content' => mb_substr( (string) $text, 0, 2000 ) ),
			),
		);
	}

	/**
	 * Send.
	 *
	 * @param object $entry Entry.
	 * @param object $job   Job.
	 * @return array
	 */
	public function send( $entry, $job ) {
		$schema = $this->schema();
		if ( isset( $schema['error'] ) ) {
			return $this->fail( $schema['error'] );
		}
		$values = array(
			'Name'         => '' !== $entry->name ? $entry->name : ( '' !== $entry->email ? $entry->email : $entry->form_name . ' #' . $entry->id ),
			'Email'        => $entry->email,
			'Phone'        => $entry->phone,
			'Form'         => $entry->form_name,
			'Source'       => $entry->source_label,
			'Channel'      => AARYE_Sources::channel_label( $entry->channel ),
			'Campaign'     => $entry->campaign,
			'Ad set'       => $entry->ad_group,
			'Ad group'     => $entry->ad_group,
			'Ad'           => $entry->ad,
			'Keyword'      => $entry->keyword,
			'Click ID'     => $entry->click_id,
			'Landing page' => (string) $entry->landing_page,
			'Form page'    => (string) $entry->page_url,
			'Language'     => $entry->language,
			'Device'       => $entry->device,
			'Status'       => $entry->status,
			'Date'         => AARYEPRO_Payload::iso( $entry->created_at ),
			'Entry ID'     => (string) $entry->id,
		);
		foreach ( AARYE_Entries::json( $entry, 'fields' ) as $field ) {
			$label = '' !== $field['label'] ? $field['label'] : $field['name'];
			if ( ! isset( $values[ $label ] ) ) {
				$values[ $label ] = (string) $field['value'];
			}
		}
		$props = array();
		$title = '';
		foreach ( $schema['props'] as $name => $type ) {
			if ( 'title' === $type ) {
				$title = $name;
				continue;
			}
			$value = isset( $values[ $name ] ) ? (string) $values[ $name ] : '';
			if ( '' === $value ) {
				continue;
			}
			switch ( $type ) {
				case 'rich_text':
					$props[ $name ] = array( 'rich_text' => self::rich( $value ) );
					break;
				case 'email':
				case 'phone_number':
					$props[ $name ] = array( $type => mb_substr( $value, 0, 200 ) );
					break;
				case 'url':
					$props[ $name ] = array( 'url' => esc_url_raw( $value ) );
					break;
				case 'select':
				case 'status':
					if ( 'select' === $type ) {
						$props[ $name ] = array( 'select' => array( 'name' => mb_substr( str_replace( ',', ' ', $value ), 0, 100 ) ) );
					}
					break;
				case 'multi_select':
					$props[ $name ] = array( 'multi_select' => array( array( 'name' => mb_substr( str_replace( ',', ' ', $value ), 0, 100 ) ) ) );
					break;
				case 'date':
					$props[ $name ] = array( 'date' => array( 'start' => 'Date' === $name ? $value : $values['Date'] ) );
					break;
				case 'number':
					if ( is_numeric( $value ) ) {
						$props[ $name ] = array( 'number' => (float) $value );
					}
					break;
			}
		}
		$props[ $title ] = array( 'title' => self::rich( $values['Name'] ) );

		$blocks   = array();
		$source   = array_filter( array( $entry->source_label, $entry->campaign, $entry->ad_group, $entry->ad ) );
		$blocks[] = array(
			'object'    => 'block',
			'type'      => 'callout',
			'callout'   => array(
				'rich_text' => self::rich( implode( ' › ', $source ) . ( '' !== $entry->keyword ? ' · "' . $entry->keyword . '"' : '' ) ),
				'icon'      => array( 'emoji' => 'paid' === $entry->channel ? '📣' : '🌐' ),
			),
		);
		foreach ( AARYE_Entries::json( $entry, 'fields' ) as $field ) {
			$blocks[] = array(
				'object'    => 'block',
				'type'      => 'paragraph',
				'paragraph' => array(
					'rich_text' => array(
						array(
							'type'        => 'text',
							'text'        => array( 'content' => mb_substr( ( '' !== $field['label'] ? $field['label'] : $field['name'] ) . ': ', 0, 200 ) ),
							'annotations' => array( 'bold' => true ),
						),
						array(
							'type' => 'text',
							'text' => array( 'content' => mb_substr( (string) $field['value'], 0, 1800 ) ),
						),
					),
				),
			);
		}
		foreach ( AARYEPRO_Payload::files( $entry ) as $file ) {
			$blocks[] = array(
				'object'    => 'block',
				'type'      => 'paragraph',
				'paragraph' => array(
					'rich_text' => array(
						array(
							'type' => 'text',
							'text' => array(
								'content' => '📎 ' . $file['name'],
								'link'    => array( 'url' => $file['url'] ),
							),
						),
					),
				),
			);
		}
		$r = $this->api(
			'POST',
			'pages',
			array(
				'parent'     => array( 'database_id' => $this->database_id() ),
				'properties' => (object) $props,
				'children'   => array_slice( $blocks, 0, 100 ),
			)
		);
		if ( 200 === $r['status'] && ! empty( $r['json']['id'] ) ) {
			return $this->result( true, __( 'Page created', 'aarye-marketing-addon-for-forms-pro' ), array( 'remote_id' => (string) $r['json']['id'] ) );
		}
		return $this->fail( $r );
	}

	/**
	 * Test.
	 *
	 * @return array
	 */
	public function test() {
		$schema = $this->schema();
		if ( isset( $schema['error'] ) ) {
			return $this->fail( $schema['error'] );
		}
		/* translators: %d: number of properties. */
		return $this->result( true, sprintf( __( 'Connected. The database has %d properties.', 'aarye-marketing-addon-for-forms-pro' ), count( $schema['props'] ) ) );
	}
}
