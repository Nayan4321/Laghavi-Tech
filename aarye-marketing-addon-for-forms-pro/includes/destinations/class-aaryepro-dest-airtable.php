<?php
/**
 * Airtable: one record per entry, in a table you choose.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Airtable.
 */
class AARYEPRO_Dest_Airtable extends AARYEPRO_Destination {

	const API = 'https://api.airtable.com/v0/';

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'airtable';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Airtable';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Adds each entry as a record in an Airtable table, with its source, campaign, ad and every form field. Columns are matched by name; the ones your table does not have are skipped.', 'aarye-marketing-addon-for-forms-pro' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'token' => array(
				'label'       => __( 'Personal access token', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'password',
				'placeholder' => 'pat…',
				'required'    => true,
			),
			'base'  => array(
				'label'       => __( 'Base ID', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'text',
				'placeholder' => 'appXXXXXXXXXXXXXX',
				'required'    => true,
				'help'        => __( 'The part of the base\'s address that starts with "app".', 'aarye-marketing-addon-for-forms-pro' ),
			),
			'table' => array(
				'label'       => __( 'Table name or ID', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'text',
				'placeholder' => 'Leads',
				'required'    => true,
			),
		);
	}

	/**
	 * Steps.
	 *
	 * @return string[]
	 */
	public function steps() {
		$cols = implode( ', ', array( 'Entry ID', 'Date', 'Form', 'Name', 'Email', 'Phone', 'Source', 'Channel', 'From an ad', 'Campaign', 'Ad set / ad group', 'Ad', 'Keyword', 'Click ID', 'Landing page', 'Form page', 'Language', 'Device', 'Status', 'Files' ) );
		return array(
			__( 'In Airtable, create a table (for example "Leads") with the columns you want, all as single line or long text. Use these names:', 'aarye-marketing-addon-for-forms-pro' ) . ' <code>' . esc_html( $cols ) . '</code>. ' . __( 'Columns named like your form fields (for example "Message") are filled too.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Open <b>airtable.com/create/tokens</b>, create a token with the scopes <code>data.records:write</code> and <code>schema.bases:read</code>, and give it access to this base.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Paste the token, the base ID (from the base\'s address: <code>app…</code>) and the table name.', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * Headers.
	 *
	 * @return array
	 */
	private function auth() {
		return array( 'Authorization' => 'Bearer ' . $this->get( 'token' ) );
	}

	/**
	 * Column names of the table (cached for an hour), or null if they cannot be read.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return string[]|null
	 */
	private function columns( $fresh = false ) {
		$key    = 'aaryepro_airtable_cols_' . md5( $this->get( 'base' ) . '|' . $this->get( 'table' ) );
		$cached = $fresh ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$r = $this->http( 'GET', 'https://api.airtable.com/v0/meta/bases/' . rawurlencode( $this->get( 'base' ) ) . '/tables', null, $this->auth() );
		if ( 200 !== $r['status'] || empty( $r['json']['tables'] ) ) {
			return null;
		}
		foreach ( $r['json']['tables'] as $table ) {
			if ( $table['name'] === $this->get( 'table' ) || $table['id'] === $this->get( 'table' ) ) {
				$cols = wp_list_pluck( $table['fields'], 'name' );
				set_transient( $key, $cols, HOUR_IN_SECONDS );
				return $cols;
			}
		}
		return array();
	}

	/**
	 * Airtable's error text.
	 *
	 * @param array $r Reply.
	 * @return string
	 */
	private function message( $r ) {
		if ( is_array( $r['json'] ) && isset( $r['json']['error'] ) ) {
			$e = $r['json']['error'];
			return is_array( $e ) ? sanitize_text_field( ( isset( $e['type'] ) ? $e['type'] . ': ' : '' ) . ( isset( $e['message'] ) ? $e['message'] : '' ) ) : sanitize_text_field( (string) $e );
		}
		return '';
	}

	/**
	 * Send.
	 *
	 * @param object $entry Entry.
	 * @param object $job   Job.
	 * @return array
	 */
	public function send( $entry, $job ) {
		$row           = AARYE_Sheets::row( $entry );
		$row['Status'] = $entry->status;
		foreach ( $row as $k => $v ) {
			$row[ $k ] = 0 === strpos( (string) $v, "'" ) ? substr( (string) $v, 1 ) : (string) $v; // Sheets-only formula guard.
		}
		$cols = $this->columns();
		if ( is_array( $cols ) ) {
			$row = array_intersect_key( $row, array_flip( $cols ) );
		}
		$url = self::API . rawurlencode( $this->get( 'base' ) ) . '/' . rawurlencode( $this->get( 'table' ) );
		for ( $i = 0; $i < 15; $i++ ) {
			$r = $this->http(
				'POST',
				$url,
				array(
					'records'  => array( array( 'fields' => (object) array_filter( $row, 'strlen' ) ) ),
					'typecast' => true,
				),
				$this->auth()
			);
			// Without the schema scope, drop unknown columns one by one.
			if ( 422 === $r['status'] && preg_match( '/Unknown field name: "(.+?)"/', $this->message( $r ), $m ) && isset( $row[ $m[1] ] ) ) {
				unset( $row[ $m[1] ] );
				continue;
			}
			break;
		}
		if ( 200 === $r['status'] && ! empty( $r['json']['records'][0]['id'] ) ) {
			$id = (string) $r['json']['records'][0]['id'];
			/* translators: %s: Airtable record ID. */
			return $this->result( true, sprintf( __( 'Record %s', 'aarye-marketing-addon-for-forms-pro' ), $id ), array( 'remote_id' => $id ) );
		}
		$res = $this->http_result( $r, $this->message( $r ) );
		if ( 401 === $r['status'] ) {
			$res['stop'] = true;
		}
		return $res;
	}

	/**
	 * Test.
	 *
	 * @return array
	 */
	public function test() {
		$cols = $this->columns( true );
		if ( is_array( $cols ) && ! $cols ) {
			return $this->result( false, __( 'The base was found, but no table with this name or ID.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		$r = $this->http( 'GET', self::API . rawurlencode( $this->get( 'base' ) ) . '/' . rawurlencode( $this->get( 'table' ) ) . '?maxRecords=1', null, $this->auth() );
		if ( 200 === $r['status'] ) {
			if ( null === $cols ) {
				return $this->result( true, __( 'Connected. Tip: add the schema.bases:read scope so columns your table does not have are skipped at once.', 'aarye-marketing-addon-for-forms-pro' ) );
			}
			/* translators: %d: number of columns. */
			return $this->result( true, sprintf( __( 'Connected. The table has %d columns.', 'aarye-marketing-addon-for-forms-pro' ), count( $cols ) ) );
		}
		if ( 403 === $r['status'] ) {
			// Write-only tokens cannot read records; that is fine for sending.
			return is_array( $cols ) ? $this->result( true, __( 'Connected.', 'aarye-marketing-addon-for-forms-pro' ) ) : $this->result( false, __( 'Airtable refused access: check the token\'s scopes and that it has access to this base.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		return $this->http_result( $r, $this->message( $r ) );
	}
}
