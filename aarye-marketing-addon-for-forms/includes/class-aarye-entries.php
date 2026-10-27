<?php
/**
 * The leads table: every form entry from every form plugin, with where the visitor came from.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Entries.
 */
class AARYE_Entries {

	const DB_VERSION = '2';

	/**
	 * Entries captured in this request, so a form that fires two hooks is stored once.
	 *
	 * @var array<string, int>
	 */
	private static $seen = array();

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aarye_entries';
	}

	/**
	 * Create or update the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				form_plugin varchar(30) NOT NULL DEFAULT '',
				form_id varchar(64) NOT NULL DEFAULT '',
				form_name varchar(255) NOT NULL DEFAULT '',
				source_entry_id varchar(64) NOT NULL DEFAULT '',
				name varchar(190) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(60) NOT NULL DEFAULT '',
				channel varchar(30) NOT NULL DEFAULT '',
				source varchar(40) NOT NULL DEFAULT '',
				source_label varchar(150) NOT NULL DEFAULT '',
				campaign varchar(255) NOT NULL DEFAULT '',
				ad_group varchar(255) NOT NULL DEFAULT '',
				ad varchar(255) NOT NULL DEFAULT '',
				keyword varchar(255) NOT NULL DEFAULT '',
				click_param varchar(30) NOT NULL DEFAULT '',
				click_id varchar(255) NOT NULL DEFAULT '',
				confirmed tinyint(1) NOT NULL DEFAULT 1,
				landing_page text NULL,
				referrer text NULL,
				page_url text NULL,
				language varchar(12) NOT NULL DEFAULT '',
				device varchar(12) NOT NULL DEFAULT '',
				browser varchar(40) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				visitor_id varchar(40) NOT NULL DEFAULT '',
				visits int(11) NOT NULL DEFAULT 0,
				first_seen datetime NULL,
				is_bot tinyint(1) NOT NULL DEFAULT 0,
				spam_score tinyint(3) unsigned NOT NULL DEFAULT 0,
				quality varchar(10) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'new',
				fields longtext NULL,
				files longtext NULL,
				attribution longtext NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY channel (channel),
				KEY source (source),
				KEY form (form_plugin, form_id),
				KEY email (email),
				KEY visitor_id (visitor_id),
				KEY quality (quality)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'aarye_db_version', self::DB_VERSION );
		self::protect_dir();
		update_option( 'aarye_rescore', 1, false ); // Spam check of older entries, on the next admin page.
	}

	/**
	 * Run the spam check on entries saved before it existed.
	 *
	 * @param int $limit Entries per run.
	 */
	public static function score_unchecked( $limit = 2000 ) {
		global $wpdb;
		delete_option( 'aarye_rescore' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE quality = '' ORDER BY id DESC LIMIT %d", self::table(), $limit ) );
		foreach ( (array) $rows as $row ) {
			$result = AARYE_Evidence::analyse( $row );
			$wpdb->update(
				self::table(),
				array(
					'spam_score' => $result['score'],
					'quality'    => $result['verdict'],
				),
				array( 'id' => (int) $row->id )
			);
		}
		// phpcs:enable
	}

	/**
	 * Folder for copies of uploaded files, closed to direct access.
	 *
	 * @return string Path with trailing slash.
	 */
	public static function files_dir() {
		$upload = wp_upload_dir( null, false );
		return trailingslashit( $upload['basedir'] ) . 'aarye-forms/';
	}

	/**
	 * Create the files folder with "no direct access" files.
	 */
	public static function protect_dir() {
		$dir = self::files_dir();
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny static file in our own uploads folder.
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Store a form entry. Called by each form plugin integration right after a successful submission.
	 *
	 * @param array $args {
	 *     @type string $plugin          Form plugin key (cf7, wpforms, elementor, ...).
	 *     @type string $form_id         Form ID in that plugin.
	 *     @type string $form_name       Form title.
	 *     @type string $source_entry_id The form plugin's own entry ID, if it stores entries.
	 *     @type array  $fields          List of {label, name, type, value}; value may be an array.
	 *     @type array  $files           List of {label, name, path} (path = file on disk) or {label, name, url}.
	 * }
	 * @return int Entry ID, or 0 if nothing was stored.
	 */
	public static function capture( $args ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'plugin'          => '',
				'form_id'         => '',
				'form_name'       => '',
				'source_entry_id' => '',
				'fields'          => array(),
				'files'           => array(),
				'visit'           => null, // Attribution from elsewhere (an incoming webhook), else the visitor's cookie.
				'user_agent'      => null,
				'imported'        => false, // An entry from before Aarye was installed (see AARYE_Import).
				'created_at'      => '', // UTC date of an imported entry.
				'ip'              => '',
				'page'            => '', // Page the form was sent from, when the caller knows it.
			)
		);
		$key  = $args['plugin'] . '|' . $args['form_id'] . '|' . ( '' !== (string) $args['source_entry_id'] ? $args['source_entry_id'] : md5( wp_json_encode( $args['fields'] ) ) );
		if ( isset( self::$seen[ $key ] ) ) {
			return self::$seen[ $key ];
		}

		if ( $args['imported'] && self::exists( $args ) ) {
			return -1; // Already in Aarye (captured live or imported before).
		}

		$ua     = null !== $args['user_agent'] ? sanitize_text_field( (string) $args['user_agent'] ) : ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' );
		$is_bot = ( '' !== $ua || ! $args['imported'] ) && AARYE_Tracker::is_bot( $ua ); // Old entries often have no browser info.
		if ( $is_bot && AARYE_Settings::on( 'skip_bots' ) ) {
			return 0;
		}
		$visit  = is_array( $args['visit'] ) ? wp_parse_args(
			$args['visit'],
			array(
				'first'   => null,
				'last'    => null,
				'visits'  => 1,
				'page'    => '',
				'lang'    => '',
				'visitor' => '',
			)
		) : AARYE_Tracker::read();
		$touch  = $visit['last'] ? $visit['last'] : $visit['first'];
		$source = AARYE_Sources::classify( $touch ? $touch : array() );
		if ( $args['imported'] && 'direct' === $source['channel'] ) {
			// Sent before Aarye was installed and its page address carries no ad tags: the source is unknown.
			$source = array_merge(
				$source,
				array(
					'channel' => 'unknown',
					'source'  => 'unknown',
					'label'   => __( 'Not tracked (imported)', 'aarye-marketing-addon-for-forms' ),
				)
			);
		}
		$first   = $visit['first'] ? AARYE_Sources::classify( $visit['first'] ) : $source;
		$fields  = self::clean_fields( $args['fields'] );
		$contact = self::contact( $fields );
		$files   = self::prepare_files( $args['files'] );
		$page    = '' !== $args['page'] ? esc_url_raw( $args['page'] ) : ( '' !== $visit['page'] ? $visit['page'] : ( isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '' ) );

		$row = array(
			'created_at'      => '' !== $args['created_at'] ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $args['created_at'] . ' UTC' ) ) : current_time( 'mysql', true ),
			'form_plugin'     => sanitize_key( $args['plugin'] ),
			'form_id'         => mb_substr( sanitize_text_field( (string) $args['form_id'] ), 0, 64 ),
			'form_name'       => mb_substr( sanitize_text_field( (string) $args['form_name'] ), 0, 255 ),
			'source_entry_id' => mb_substr( sanitize_text_field( (string) $args['source_entry_id'] ), 0, 64 ),
			'name'            => mb_substr( $contact['name'], 0, 190 ),
			'email'           => mb_substr( $contact['email'], 0, 190 ),
			'phone'           => mb_substr( $contact['phone'], 0, 60 ),
			'channel'         => $source['channel'],
			'source'          => $source['source'],
			'source_label'    => mb_substr( $source['label'], 0, 150 ),
			'campaign'        => mb_substr( $source['campaign'], 0, 255 ),
			'ad_group'        => mb_substr( $source['ad_group'], 0, 255 ),
			'ad'              => mb_substr( $source['ad'], 0, 255 ),
			'keyword'         => mb_substr( $source['keyword'], 0, 255 ),
			'click_param'     => $source['click_param'],
			'click_id'        => mb_substr( $source['click_id'], 0, 255 ),
			'confirmed'       => $source['confirmed'] ? 1 : 0,
			'landing_page'    => $touch ? $touch['url'] : '',
			'referrer'        => $touch ? $touch['ref'] : '',
			'page_url'        => $page,
			'language'        => AARYE_Tracker::language( $visit['lang'] ),
			'device'          => AARYE_Tracker::device( $ua ),
			'browser'         => AARYE_Tracker::browser( $ua ),
			'ip'              => AARYE_Settings::on( 'store_ip' ) ? ( $args['imported'] || is_array( $args['visit'] ) ? ( filter_var( $args['ip'], FILTER_VALIDATE_IP ) ? $args['ip'] : '' ) : self::ip() ) : '',
			'visitor_id'      => $visit['visitor'],
			'visits'          => max( 1, $visit['visits'] ),
			'first_seen'      => $visit['first'] && $visit['first']['time'] ? gmdate( 'Y-m-d H:i:s', $visit['first']['time'] ) : null,
			'is_bot'          => $is_bot ? 1 : 0,
			'fields'          => wp_json_encode( $fields ),
			'files'           => wp_json_encode( $files ),
			'attribution'     => wp_json_encode(
				array(
					'first'        => $visit['first'],
					'last'         => $visit['last'],
					'first_source' => $first,
					'user_agent'   => mb_substr( $ua, 0, 300 ),
					'imported'     => (bool) $args['imported'],
				)
			),
		);
		/**
		 * Change an entry before it is saved.
		 *
		 * @param array $row  Database row.
		 * @param array $args What the form integration passed.
		 */
		// Visit evidence: signals of this request, and the spam check.
		if ( ! $args['imported'] && ! is_array( $args['visit'] ) ) {
			$attr               = json_decode( $row['attribution'], true );
			$attr['evidence']   = AARYE_Evidence::collect( $visit, $row );
			$row['attribution'] = wp_json_encode( $attr );
		}
		$check             = AARYE_Evidence::analyse( (object) $row );
		$row['spam_score'] = $check['score'];
		$row['quality']    = $check['verdict'];
		$row               = (array) apply_filters( 'aarye_entry_before_save', $row, $args );
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$id                 = (int) $wpdb->insert_id;
		self::$seen[ $key ] = $id;
		if ( $id && $args['imported'] ) {
			/**
			 * A past entry was imported (not sent to Google Sheets or connections).
			 *
			 * @param int   $id  Entry ID.
			 * @param array $row Entry.
			 */
			do_action( 'aarye_entry_imported', $id, $row );
		} elseif ( $id ) {
			/**
			 * A new entry was saved (Google Sheets and add-ons send it on from here).
			 *
			 * @param int   $id  Entry ID.
			 * @param array $row Entry.
			 */
			do_action( 'aarye_entry_saved', $id, $row );
		}
		return $id;
	}

	/**
	 * Whether an imported entry is already saved: same form entry ID, or (for form plugins without entry
	 * IDs) the same form within two minutes of the same time.
	 *
	 * @param array $args capture() arguments.
	 * @return bool
	 */
	private static function exists( $args ) {
		global $wpdb;
		$plugin = sanitize_key( $args['plugin'] );
		$form   = (string) $args['form_id'];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Our own table.
		if ( '' !== (string) $args['source_entry_id'] ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE form_plugin = %s AND form_id = %s AND source_entry_id = %s LIMIT 1', self::table(), $plugin, $form, (string) $args['source_entry_id'] ) );
			if ( $found ) {
				return true;
			}
		}
		if ( '' === (string) $args['created_at'] ) {
			return false;
		}
		$time  = (int) strtotime( $args['created_at'] . ' UTC' );
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE form_plugin = %s AND form_id = %s AND created_at BETWEEN %s AND %s AND ( source_entry_id = '' OR source_entry_id = %s ) LIMIT 1", self::table(), $plugin, $form, gmdate( 'Y-m-d H:i:s', $time - 120 ), gmdate( 'Y-m-d H:i:s', $time + 120 ), (string) $args['source_entry_id'] ) );
		// phpcs:enable
		return (bool) $found;
	}

	/**
	 * Visitor's IP address.
	 *
	 * @return string
	 */
	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Clean the field list.
	 *
	 * @param array $fields Raw fields.
	 * @return array
	 */
	private static function clean_fields( $fields ) {
		$out = array();
		foreach ( (array) $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( 'strval', self::flatten( $value ) ), 'strlen' ) );
			}
			$value = sanitize_textarea_field( (string) $value );
			$name  = isset( $field['name'] ) ? sanitize_text_field( (string) $field['name'] ) : '';
			$label = isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : self::humanize( $name );
			$type  = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';
			if ( in_array( $type, array( 'submit', 'html', 'captcha', 'recaptcha', 'hcaptcha', 'turnstile', 'honeypot', 'divider', 'section', 'page', 'pagebreak', 'step' ), true ) ) {
				continue;
			}
			$out[] = array(
				'label' => $label,
				'name'  => $name,
				'type'  => $type,
				'value' => $value,
			);
		}
		return $out;
	}

	/**
	 * Flatten nested arrays (checkbox groups, name parts).
	 *
	 * @param array $value Value.
	 * @return array
	 */
	private static function flatten( $value ) {
		$out = array();
		array_walk_recursive(
			$value,
			static function ( $v ) use ( &$out ) {
				if ( is_scalar( $v ) ) {
					$out[] = $v;
				}
			}
		);
		return $out;
	}

	/**
	 * "your-name" -> "Your name".
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	public static function humanize( $name ) {
		$name = preg_replace( '/([a-z])([A-Z])/', '$1 $2', (string) $name ); // PhoneNumber → Phone Number.
		$name = trim( preg_replace( '/[_\-\[\]]+/', ' ', $name ) );
		$name = preg_replace( '/^(your|input|field|form)\s+/i', '', $name );
		return ucfirst( strtolower( $name ) );
	}

	/**
	 * Find the name, email and phone among the fields, whatever language the labels are in.
	 *
	 * @param array $fields Clean fields.
	 * @return array{name: string, email: string, phone: string}
	 */
	public static function contact( $fields ) {
		$out   = array(
			'name'  => '',
			'email' => '',
			'phone' => '',
		);
		$first = '';
		$last  = '';
		foreach ( $fields as $f ) {
			$v = trim( $f['value'] );
			if ( '' === $v ) {
				continue;
			}
			$hay = strtolower( $f['label'] . ' ' . $f['name'] . ' ' . $f['type'] );
			if ( '' === $out['email'] && ( 'email' === $f['type'] || is_email( $v ) ) && is_email( $v ) ) {
				$out['email'] = sanitize_email( $v );
				continue;
			}
			if ( '' === $out['phone'] && ( in_array( $f['type'], array( 'tel', 'phone' ), true ) || preg_match( '/phone|mobile|tel|whatsapp|contact number|cell|هاتف|جوال|телефон|teléfono|telefon|téléphone|電話/u', $hay ) ) && preg_match( '/\d{6,}/', preg_replace( '/[\s\-().]/', '', $v ) ) ) {
				$out['phone'] = $v;
				continue;
			}
			if ( preg_match( '/first.?name|given|prénom|vorname|nombre(?!.*apellido)|الاسم الأول/u', $hay ) && '' === $first ) {
				$first = $v;
			} elseif ( preg_match( '/last.?name|surname|family|nom de famille|nachname|apellido|اسم العائلة/u', $hay ) && '' === $last ) {
				$last = $v;
			} elseif ( '' === $out['name'] && preg_match( '/\bname\b|full.?name|your.?name|nom|name|nombre|الاسم|имя|名前|姓名/u', $hay ) && ! preg_match( '/company|business|organi|firma|empresa|société|user.?name/', $hay ) && strlen( $v ) < 120 ) {
				$out['name'] = $v;
			}
		}
		if ( '' === $out['name'] ) {
			$out['name'] = trim( $first . ' ' . $last );
		}
		$out['name']  = sanitize_text_field( $out['name'] );
		$out['phone'] = sanitize_text_field( $out['phone'] );
		return $out;
	}

	/**
	 * Keep uploaded files: copy files that exist on disk into our protected folder (some form
	 * plugins delete their uploads right after the email is sent), keep URLs as they are.
	 *
	 * @param array $files Raw files (already prepared ones pass through).
	 * @return array List of {label, name, url, stored}.
	 */
	public static function prepare_files( $files ) {
		$out  = array();
		$keep = AARYE_Settings::on( 'keep_files' );
		foreach ( (array) $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			if ( isset( $file['stored'] ) ) {
				$out[] = $file;
				continue;
			}
			$label  = isset( $file['label'] ) ? sanitize_text_field( (string) $file['label'] ) : '';
			$url    = isset( $file['url'] ) ? esc_url_raw( (string) $file['url'] ) : '';
			$path   = isset( $file['path'] ) ? (string) $file['path'] : '';
			$name   = isset( $file['name'] ) && '' !== $file['name'] ? sanitize_file_name( (string) $file['name'] ) : sanitize_file_name( wp_basename( '' !== $path ? $path : (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			$stored = '';
			if ( $keep && '' !== $path && is_file( $path ) && is_readable( $path ) && filesize( $path ) <= 50 * MB_IN_BYTES ) {
				$dir = self::files_dir() . gmdate( 'Y/m/' ) . wp_generate_password( 12, false, false ) . '/';
				if ( wp_mkdir_p( $dir ) && copy( $path, $dir . $name ) ) {
					$stored = substr( $dir . $name, strlen( self::files_dir() ) );
				}
			}
			if ( '' === $url && '' === $stored ) {
				continue;
			}
			$out[] = array(
				'label'  => $label,
				'name'   => $name,
				'url'    => $url,
				'stored' => $stored,
			);
		}
		return $out;
	}

	/**
	 * One entry.
	 *
	 * @param int $id Entry ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
	}

	/**
	 * Decoded JSON column.
	 *
	 * @param object $entry  Entry.
	 * @param string $column fields, files or attribution.
	 * @return array
	 */
	public static function json( $entry, $column ) {
		$data = isset( $entry->$column ) ? json_decode( (string) $entry->$column, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * WHERE clause for the dashboard filters, with placeholders.
	 *
	 * @param array $f Filters: channel, not_channel, source, campaign, form (plugin:id), lang, status, s, from, to (Y-m-d), bots.
	 * @return array{0: string, 1: array} SQL with %s placeholders, and their values.
	 */
	public static function where( $f ) {
		global $wpdb;
		$sql  = array( '1=1' );
		$args = array();
		$map  = array(
			'channel'     => 'channel = %s',
			'not_channel' => 'channel <> %s',
			'source'      => 'source = %s',
			'campaign'    => 'campaign = %s',
			'lang'        => 'language = %s',
		);
		foreach ( $map as $key => $clause ) {
			if ( ! empty( $f[ $key ] ) ) {
				$sql[]  = $clause;
				$args[] = (string) $f[ $key ];
			}
		}
		if ( ! empty( $f['form'] ) && false !== strpos( $f['form'], ':' ) ) {
			list( $plugin, $form_id ) = explode( ':', $f['form'], 2 );
			$sql[]                    = 'form_plugin = %s AND form_id = %s';
			array_push( $args, $plugin, $form_id );
		}
		if ( ! empty( $f['from'] ) ) {
			$sql[]  = 'created_at >= %s';
			$args[] = get_gmt_from_date( $f['from'] . ' 00:00:00' );
		}
		if ( ! empty( $f['to'] ) ) {
			$sql[]  = 'created_at <= %s';
			$args[] = get_gmt_from_date( $f['to'] . ' 23:59:59' );
		}
		if ( empty( $f['bots'] ) ) {
			$sql[] = 'is_bot = 0';
		}
		if ( ! empty( $f['quality'] ) && in_array( $f['quality'], array( 'ok', 'doubt', 'spam' ), true ) ) {
			$sql[]  = 'quality = %s';
			$args[] = (string) $f['quality'];
		}
		if ( ! empty( $f['status'] ) ) {
			$sql[]  = 'status = %s';
			$args[] = (string) $f['status'];
		} elseif ( empty( $f['bots'] ) ) {
			$sql[] = "status <> 'spam'"; // Spam is kept but not counted as a lead.
		}
		if ( ! empty( $f['s'] ) ) {
			$like  = '%' . $wpdb->esc_like( $f['s'] ) . '%';
			$sql[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR campaign LIKE %s OR ad LIKE %s OR form_name LIKE %s OR fields LIKE %s)';
			$args  = array_merge( $args, array_fill( 0, 7, $like ) );
		}
		return array( implode( ' AND ', $sql ), $args );
	}

	/**
	 * Entries for a list.
	 *
	 * @param array $f      Filters.
	 * @param int   $limit  Rows.
	 * @param int   $offset Offset.
	 * @return array{rows: object[], total: int}
	 */
	public static function query( $f, $limit = 50, $offset = 0 ) {
		global $wpdb;
		list( $where, $args ) = self::where( $f );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Our own table; $where only holds fixed SQL with placeholders, filled by prepare().
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", array_merge( array( self::table() ), $args ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( array( self::table() ), $args, array( $limit, $offset ) ) ) );
		// phpcs:enable
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Counts grouped by a column, for the dashboard.
	 *
	 * @param string $column channel, source_label, campaign, ad, form_name, language.
	 * @param array  $f      Filters.
	 * @param int    $limit  Rows.
	 * @return array<int, array{key: string, label: string, total: int}>
	 */
	public static function grouped( $column, $f, $limit = 20 ) {
		global $wpdb;
		$allowed = array( 'channel', 'source', 'source_label', 'campaign', 'ad_group', 'ad', 'form_name', 'language', 'device' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}
		list( $where, $args ) = self::where( $f );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Our own table; column from the allow-list above; $where only holds fixed SQL with placeholders, filled by prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i AS k, COUNT(*) AS total FROM %i WHERE {$where} GROUP BY %i ORDER BY total DESC LIMIT %d", array_merge( array( $column, self::table() ), $args, array( $column, $limit ) ) ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'key'   => (string) $row->k,
				'label' => 'channel' === $column ? AARYE_Sources::channel_label( (string) $row->k ) : (string) $row->k,
				'total' => (int) $row->total,
			);
		}
		return $out;
	}

	/**
	 * Update an entry's lead status.
	 *
	 * @param int    $id     Entry ID.
	 * @param string $status Status key.
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => sanitize_key( $status ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete an entry and its stored files.
	 *
	 * @param int $id Entry ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$entry = self::get( $id );
		if ( ! $entry ) {
			return;
		}
		foreach ( self::json( $entry, 'files' ) as $file ) {
			if ( ! empty( $file['stored'] ) ) {
				$path = self::files_dir() . $file['stored'];
				if ( is_file( $path ) && 0 === strpos( realpath( $path ), realpath( self::files_dir() ) ) ) {
					wp_delete_file( $path );
					@rmdir( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty random folder.
				}
			}
		}
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		/**
		 * An entry was deleted.
		 *
		 * @param int $id Entry ID.
		 */
		do_action( 'aarye_entry_deleted', (int) $id );
	}

	/**
	 * Lead statuses.
	 *
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			'new'       => __( 'New', 'aarye-marketing-addon-for-forms' ),
			'contacted' => __( 'Contacted', 'aarye-marketing-addon-for-forms' ),
			'qualified' => __( 'Qualified', 'aarye-marketing-addon-for-forms' ),
			'won'       => __( 'Won', 'aarye-marketing-addon-for-forms' ),
			'lost'      => __( 'Lost', 'aarye-marketing-addon-for-forms' ),
			'spam'      => __( 'Spam', 'aarye-marketing-addon-for-forms' ),
		);
	}
}
