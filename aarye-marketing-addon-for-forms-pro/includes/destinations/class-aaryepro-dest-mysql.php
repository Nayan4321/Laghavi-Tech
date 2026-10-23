<?php
/**
 * Your own database: every entry as a row in a MySQL or MariaDB table (created automatically).
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * A database connection that reports errors instead of stopping the site.
 */
class AARYEPRO_External_DB extends wpdb {

	/**
	 * Connect without the "error establishing a database connection" page.
	 *
	 * @param string $user     User.
	 * @param string $password Password.
	 * @param string $name     Database.
	 * @param string $host     Host[:port].
	 */
	public function __construct( $user, $password, $name, $host ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Deliberately does not call the parent, which stops the site on failure.
		$this->dbuser     = $user;
		$this->dbpassword = $password;
		$this->dbname     = $name;
		$this->dbhost     = $host;
		$this->show_errors( false );
		$this->suppress_errors( true );
		$this->db_connect( false );
	}

	/**
	 * Whether the connection works.
	 *
	 * @return bool
	 */
	public function connected() {
		return (bool) $this->ready;
	}
}

/**
 * Own database.
 */
class AARYEPRO_Dest_Mysql extends AARYEPRO_Destination {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'mysql';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return __( 'Your own database', 'aarye-marketing-addon-for-forms-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Copies every entry into a MySQL or MariaDB database of your own (for example your CRM\'s or reporting database), in a table created automatically. Status changes update the row.', 'aarye-marketing-addon-for-forms-pro' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'host'     => array(
				'label'       => __( 'Host', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'text',
				'placeholder' => 'db.example.com:3306',
				'required'    => true,
			),
			'database' => array(
				'label'    => __( 'Database name', 'aarye-marketing-addon-for-forms-pro' ),
				'type'     => 'text',
				'required' => true,
			),
			'user'     => array(
				'label'    => __( 'User', 'aarye-marketing-addon-for-forms-pro' ),
				'type'     => 'text',
				'required' => true,
			),
			'password' => array(
				'label' => __( 'Password', 'aarye-marketing-addon-for-forms-pro' ),
				'type'  => 'password',
			),
			'table'    => array(
				'label'       => __( 'Table', 'aarye-marketing-addon-for-forms-pro' ),
				'type'        => 'text',
				'placeholder' => 'aarye_leads',
				'help'        => __( 'Created if it does not exist. Letters, numbers and underscores only.', 'aarye-marketing-addon-for-forms-pro' ),
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
			__( 'Create a database user that can CREATE, INSERT and UPDATE in the target database.', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'If the database is on another server, allow connections from this website\'s server IP address (on Hostinger: Databases → Remote MySQL).', 'aarye-marketing-addon-for-forms-pro' ),
			__( 'Fill in the details and click <b>Test connection</b>: the table is created then.', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * Status changes update the row.
	 *
	 * @return bool
	 */
	public function resend_on_status() {
		return true;
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table() {
		$t = preg_replace( '/[^A-Za-z0-9_]/', '', $this->get( 'table' ) );
		return '' !== $t ? substr( $t, 0, 64 ) : 'aarye_leads';
	}

	/**
	 * Connect and make sure the table exists.
	 *
	 * @return AARYEPRO_External_DB|string Connection, or an error message.
	 */
	private function connect() {
		if ( ! class_exists( 'mysqli' ) ) {
			return __( 'This server has no MySQL support for PHP (mysqli).', 'aarye-marketing-addon-for-forms-pro' );
		}
		$db = new AARYEPRO_External_DB( $this->get( 'user' ), $this->get( 'password' ), $this->get( 'database' ), $this->get( 'host' ) );
		if ( ! $db->connected() ) {
			/* translators: %s: database error. */
			return sprintf( __( 'Could not connect to the database: %s', 'aarye-marketing-addon-for-forms-pro' ), '' !== $db->last_error ? $db->last_error : __( 'check host, name, user and password, and that this server may connect.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		$db->query(
			$db->prepare(
				'CREATE TABLE IF NOT EXISTS %i (
				entry_id bigint unsigned NOT NULL,
				site varchar(190) NOT NULL,
				lead_id varchar(120) NOT NULL,
				created_at datetime NOT NULL,
				status varchar(20) NOT NULL,
				form varchar(255) NOT NULL,
				name varchar(190) NOT NULL,
				email varchar(190) NOT NULL,
				phone varchar(60) NOT NULL,
				channel varchar(32) NOT NULL,
				source varchar(150) NOT NULL,
				campaign varchar(255) NOT NULL,
				ad_group varchar(255) NOT NULL,
				ad varchar(255) NOT NULL,
				keyword varchar(255) NOT NULL,
				click_id varchar(255) NOT NULL,
				landing_page text NOT NULL,
				referrer text NOT NULL,
				page_url text NOT NULL,
				language varchar(12) NOT NULL,
				device varchar(10) NOT NULL,
				fields longtext NOT NULL,
				files text NOT NULL,
				attribution longtext NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (site, entry_id),
				KEY created_at (created_at),
				KEY campaign (campaign(100))
				) DEFAULT CHARSET=utf8mb4',
				$this->table()
			)
		);
		if ( '' !== $db->last_error ) {
			/* translators: %s: database error. */
			return sprintf( __( 'Could not create the table: %s', 'aarye-marketing-addon-for-forms-pro' ), $db->last_error );
		}
		return $db;
	}

	/**
	 * Send.
	 *
	 * @param object $entry Entry.
	 * @param object $job   Job.
	 * @return array
	 */
	public function send( $entry, $job ) {
		$db = $this->connect();
		if ( is_string( $db ) ) {
			return $this->result( false, $db, array( 'retry' => true, 'code' => 'db' ) );
		}
		$payload = AARYEPRO_Payload::entry( $entry );
		$row     = array(
			'entry_id'     => (int) $entry->id,
			'site'         => mb_substr( home_url(), 0, 190 ),
			'lead_id'      => AARYEPRO_Payload::lead_id( $entry ),
			'created_at'   => $entry->created_at,
			'status'       => $entry->status,
			'form'         => $entry->form_name,
			'name'         => $entry->name,
			'email'        => $entry->email,
			'phone'        => $entry->phone,
			'channel'      => $entry->channel,
			'source'       => $entry->source_label,
			'campaign'     => $entry->campaign,
			'ad_group'     => $entry->ad_group,
			'ad'           => $entry->ad,
			'keyword'      => $entry->keyword,
			'click_id'     => '' !== $entry->click_id ? $entry->click_param . '=' . $entry->click_id : '',
			'landing_page' => (string) $entry->landing_page,
			'referrer'     => (string) $entry->referrer,
			'page_url'     => (string) $entry->page_url,
			'language'     => $entry->language,
			'device'       => $entry->device,
			'fields'       => wp_json_encode( $payload['entry']['fields'] ),
			'files'        => wp_json_encode( $payload['entry']['files'] ),
			'attribution'  => wp_json_encode( array( 'first_touch' => $payload['entry']['first_touch'], 'last_touch' => $payload['entry']['last_touch'] ) ),
			'updated_at'   => current_time( 'mysql', true ),
		);
		$ok = $db->replace( $this->table(), $row );
		$db->close();
		if ( false === $ok ) {
			return $this->result( false, $db->last_error, array( 'retry' => true, 'code' => 'db' ) );
		}
		/* translators: %s: table name. */
		return $this->result( true, sprintf( __( 'Saved in %s', 'aarye-marketing-addon-for-forms-pro' ), $this->table() ), array( 'remote_id' => (string) $entry->id ) );
	}

	/**
	 * Test.
	 *
	 * @return array
	 */
	public function test() {
		$db = $this->connect();
		if ( is_string( $db ) ) {
			return $this->result( false, $db );
		}
		$db->close();
		/* translators: %s: table name. */
		return $this->result( true, sprintf( __( 'Connected. Entries will be saved in the table %s.', 'aarye-marketing-addon-for-forms-pro' ), $this->table() ) );
	}
}
