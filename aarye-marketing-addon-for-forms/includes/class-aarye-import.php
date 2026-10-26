<?php
/**
 * Import past entries that form plugins stored before Aarye was installed.
 *
 * Imported entries keep their original date. Their source is read from the page address stored with the
 * entry when it carries ad tags (gclid, utm_…); otherwise they are marked "Not tracked (imported)". They are
 * not sent to Google Sheets or any connection, and entries already in Aarye are skipped.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import.
 */
class AARYE_Import {

	const BATCH = 100;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'wp_ajax_aarye_import', array( __CLASS__, 'ajax' ) );
		add_filter( 'aarye_form_plugins', array( __CLASS__, 'add_sources' ) );
		add_filter( 'aarye_form_plugin_active', array( __CLASS__, 'source_active' ), 10, 2 );
	}

	/**
	 * Houzez CRM appears as a source when its tables exist.
	 *
	 * @param array $plugins Sources.
	 * @return array
	 */
	public static function add_sources( $plugins ) {
		if ( self::houzez_tables() ) {
			$plugins['houzez'] = array( 'name' => 'Houzez CRM' );
		}
		return $plugins;
	}

	/**
	 * Whether Houzez CRM is "active".
	 *
	 * @param bool   $active Active.
	 * @param string $key    Source.
	 * @return bool
	 */
	public static function source_active( $active, $key ) {
		return 'houzez' === $key ? (bool) self::houzez_tables() : $active;
	}

	/**
	 * Whether a database table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Houzez CRM tables that hold leads and enquiries.
	 *
	 * @return array<string, string> Form ID => table.
	 */
	private static function houzez_tables() {
		static $found = null;
		if ( null === $found ) {
			global $wpdb;
			$found = array();
			foreach ( array( 'leads' => 'houzez_crm_leads', 'enquiries' => 'houzez_crm_enquiries' ) as $id => $name ) {
				if ( self::table_exists( $wpdb->prefix . $name ) ) {
					$found[ $id ] = $wpdb->prefix . $name;
				}
			}
		}
		return $found;
	}

	/**
	 * What can be imported on this site.
	 *
	 * @return array<string, array{name: string, total: int, note: string}>
	 */
	public static function sources() {
		global $wpdb;
		$out = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Counting rows of the form plugins' own tables.
		if ( class_exists( 'GFAPI' ) ) {
			$out['gravity'] = array( 'name' => 'Gravity Forms', 'total' => (int) GFAPI::count_entries( 0, array( 'status' => 'active' ) ), 'note' => '' );
		}
		if ( self::table_exists( $wpdb->prefix . 'fluentform_submissions' ) ) {
			$out['fluentform'] = array( 'name' => 'Fluent Forms', 'total' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status NOT IN ('trashed','spam')", $wpdb->prefix . 'fluentform_submissions' ) ), 'note' => '' );
		}
		if ( function_exists( 'wpforms' ) ) {
			$has = self::table_exists( $wpdb->prefix . 'wpforms_entries' );
			$out['wpforms'] = array(
				'name'  => 'WPForms',
				'total' => $has ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status NOT IN ('trash','spam')", $wpdb->prefix . 'wpforms_entries' ) ) : 0,
				'note'  => $has ? '' : __( 'WPForms Lite does not save entries, so there is nothing to import.', 'aarye-marketing-addon-for-forms' ),
			);
		}
		if ( self::table_exists( $wpdb->prefix . 'frmt_form_entry' ) ) {
			$out['forminator'] = array( 'name' => 'Forminator', 'total' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE entry_type = 'custom-forms' AND is_spam = 0", $wpdb->prefix . 'frmt_form_entry' ) ), 'note' => '' );
		}
		if ( function_exists( 'Ninja_Forms' ) ) {
			$out['ninja'] = array( 'name' => 'Ninja Forms', 'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nf_sub' AND post_status = 'publish'" ), 'note' => '' );
		}
		if ( class_exists( 'FrmEntry' ) && self::table_exists( $wpdb->prefix . 'frm_items' ) ) {
			$out['formidable'] = array( 'name' => 'Formidable Forms', 'total' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_draft = 0 AND parent_item_id = 0', $wpdb->prefix . 'frm_items' ) ), 'note' => '' );
		}
		if ( self::table_exists( $wpdb->prefix . 'jet_fb_records' ) ) {
			$out['jetformbuilder'] = array( 'name' => 'JetFormBuilder', 'total' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'success' OR status = '' OR status IS NULL", $wpdb->prefix . 'jet_fb_records' ) ), 'note' => '' );
		}
		if ( post_type_exists( 'metform-entry' ) || $wpdb->get_var( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = 'metform-entry' LIMIT 1" ) ) {
			$out['metform'] = array( 'name' => 'MetForm', 'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'metform-entry' AND post_status = 'publish'" ), 'note' => '' );
		}
		if ( self::table_exists( $wpdb->prefix . 'e_submissions' ) ) {
			$out['elementor'] = array( 'name' => 'Elementor Pro Forms', 'total' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status <> 'trash' AND type = 'submission'", $wpdb->prefix . 'e_submissions' ) ), 'note' => '' );
		}
		if ( defined( 'WPCF7_VERSION' ) ) {
			$flamingo = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'flamingo_inbound' AND post_status = 'publish'" );
			$out['cf7'] = array(
				'name'  => 'Contact Form 7',
				'total' => $flamingo,
				'note'  => $flamingo ? __( 'From the Flamingo plugin, which stores Contact Form 7 messages.', 'aarye-marketing-addon-for-forms' ) : __( 'Contact Form 7 does not save entries. If you used the Flamingo plugin, its messages are imported from here.', 'aarye-marketing-addon-for-forms' ),
			);
		}
		foreach ( self::houzez_tables() as $id => $table ) {
			/* translators: %s: leads or enquiries. */
			$out[ 'houzez:' . $id ] = array( 'name' => sprintf( __( 'Houzez CRM – %s', 'aarye-marketing-addon-for-forms' ), 'leads' === $id ? __( 'leads', 'aarye-marketing-addon-for-forms' ) : __( 'enquiries', 'aarye-marketing-addon-for-forms' ) ), 'total' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'note' => '' );
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Attribution of an imported entry, from the page address and referrer stored with it.
	 *
	 * @param string $url      Page the form was sent from.
	 * @param string $referrer Referrer, if stored.
	 * @param string $created  UTC date.
	 * @return array visit for capture().
	 */
	public static function visit( $url, $referrer = '', $created = '' ) {
		$url    = esc_url_raw( (string) $url );
		$params = array();
		$query  = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $raw );
			foreach ( (array) $raw as $k => $v ) {
				if ( is_scalar( $v ) ) {
					$params[ sanitize_key( $k ) ] = sanitize_text_field( (string) $v );
				}
			}
		}
		$ref   = esc_url_raw( (string) $referrer );
		$touch = $params || ( '' !== $ref && ! AARYE_Sources::is_own( $ref ) ) ? array(
			'time'   => $created ? (int) strtotime( $created . ' UTC' ) : 0,
			'url'    => $url,
			'ref'    => $ref,
			'params' => $params,
		) : null;
		return array(
			'first'   => $touch,
			'last'    => null,
			'visits'  => 1,
			'page'    => $url,
			'lang'    => '',
			'visitor' => '',
		);
	}

	/**
	 * Arguments every imported entry gets.
	 *
	 * @param string $created_gmt UTC date.
	 * @param string $url         Page URL.
	 * @param string $ip          IP.
	 * @param string $ua          User agent.
	 * @param string $referrer    Referrer.
	 * @return array
	 */
	private static function base( $created_gmt, $url = '', $ip = '', $ua = '', $referrer = '' ) {
		return array(
			'imported'   => true,
			'created_at' => (string) $created_gmt,
			'visit'      => self::visit( $url, $referrer, $created_gmt ),
			'user_agent' => (string) $ua,
			'ip'         => (string) $ip,
		);
	}

	/**
	 * Run a captured handler of AARYE_Integrations in import mode.
	 *
	 * @param array    $base Import arguments.
	 * @param callable $call Handler call.
	 * @return int Entry ID, -1 if already there, 0 if skipped.
	 */
	private static function through( $base, $call ) {
		AARYE_Integrations::$import  = $base;
		AARYE_Integrations::$last_id = 0;
		call_user_func( $call );
		AARYE_Integrations::$import = null;
		return AARYE_Integrations::$last_id;
	}

	/**
	 * Import one batch.
	 *
	 * @param string $key    Source key.
	 * @param int    $offset Rows already handled.
	 * @return array{handled: int, imported: int, existing: int}
	 */
	public static function batch( $key, $offset ) {
		global $wpdb;
		$results = array();
		$limit   = self::BATCH;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reading the form plugins' own tables.
		switch ( $key ) {
			case 'gravity':
				$forms   = array();
				$entries = GFAPI::get_entries( 0, array( 'status' => 'active' ), array( 'key' => 'id', 'direction' => 'ASC' ), array( 'offset' => $offset, 'page_size' => $limit ) );
				foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
					$fid = (int) $entry['form_id'];
					if ( ! isset( $forms[ $fid ] ) ) {
						$forms[ $fid ] = GFAPI::get_form( $fid );
					}
					if ( ! $forms[ $fid ] ) {
						$results[] = 0;
						continue;
					}
					$results[] = self::through(
						self::base( $entry['date_created'], rgar( $entry, 'source_url' ), rgar( $entry, 'ip' ), rgar( $entry, 'user_agent' ) ),
						static function () use ( $entry, $forms, $fid ) {
							AARYE_Integrations::gravity( $entry, $forms[ $fid ] );
						}
					);
				}
				break;

			case 'fluentform':
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status NOT IN ('trashed','spam') ORDER BY id LIMIT %d OFFSET %d", $wpdb->prefix . 'fluentform_submissions', $limit, $offset ) );
				$forms = array();
				foreach ( (array) $rows as $row ) {
					if ( ! isset( $forms[ $row->form_id ] ) ) {
						$forms[ $row->form_id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'fluentform_forms', $row->form_id ) );
					}
					$form = $forms[ $row->form_id ];
					$data = json_decode( (string) $row->response, true );
					if ( ! $form || ! is_array( $data ) ) {
						$results[] = 0;
						continue;
					}
					$results[] = self::through(
						self::base( get_gmt_from_date( $row->created_at ), $row->source_url, $row->ip, '' ),
						static function () use ( $row, $data, $form ) {
							AARYE_Integrations::fluentform( (int) $row->id, $data, $form );
						}
					);
				}
				break;

			case 'wpforms':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status NOT IN ('trash','spam') ORDER BY entry_id LIMIT %d OFFSET %d", $wpdb->prefix . 'wpforms_entries', $limit, $offset ) );
				foreach ( (array) $rows as $row ) {
					$form_data = wpforms()->obj( 'form' )->get( $row->form_id, array( 'content_only' => true ) );
					$fields    = json_decode( (string) $row->fields, true );
					if ( ! is_array( $form_data ) || ! is_array( $fields ) ) {
						$results[] = 0;
						continue;
					}
					$results[] = self::through(
						self::base( $row->date, '', isset( $row->ip_address ) ? $row->ip_address : '', isset( $row->user_agent ) ? $row->user_agent : '' ),
						static function () use ( $fields, $form_data, $row ) {
							AARYE_Integrations::wpforms( $fields, array(), $form_data, (int) $row->entry_id );
						}
					);
				}
				break;

			case 'forminator':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT entry_id, form_id, date_created FROM %i WHERE entry_type = 'custom-forms' AND is_spam = 0 ORDER BY entry_id LIMIT %d OFFSET %d", $wpdb->prefix . 'frmt_form_entry', $limit, $offset ) );
				foreach ( (array) $rows as $row ) {
					$entry = class_exists( 'Forminator_API' ) ? Forminator_API::get_entry( (int) $row->form_id, (int) $row->entry_id ) : null;
					if ( ! is_object( $entry ) || is_wp_error( $entry ) ) {
						$results[] = 0;
						continue;
					}
					$meta      = is_array( $entry->meta_data ) ? $entry->meta_data : array();
					$ip        = isset( $meta['_forminator_user_ip']['value'] ) ? (string) $meta['_forminator_user_ip']['value'] : '';
					$results[] = self::through(
						self::base( get_gmt_from_date( $row->date_created ), '', $ip, '' ),
						static function () use ( $entry ) {
							AARYE_Integrations::forminator( $entry );
						}
					);
				}
				break;

			case 'ninja':
				$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'nf_sub' AND post_status = 'publish' ORDER BY ID LIMIT %d OFFSET %d", $limit, $offset ) );
				$forms = array();
				foreach ( (array) $posts as $post ) {
					$form_id = (int) get_post_meta( $post->ID, '_form_id', true );
					if ( ! isset( $forms[ $form_id ] ) ) {
						$model             = Ninja_Forms()->form( $form_id );
						$forms[ $form_id ] = array(
							'title'  => (string) $model->get()->get_setting( 'title' ),
							'fields' => $model->get_fields(),
						);
					}
					$fields = array();
					foreach ( $forms[ $form_id ]['fields'] as $field ) {
						$fields[] = array(
							'key'         => $field->get_setting( 'key' ),
							'label'       => $field->get_setting( 'label' ),
							'admin_label' => $field->get_setting( 'admin_label' ),
							'type'        => $field->get_setting( 'type' ),
							'value'       => get_post_meta( $post->ID, '_field_' . $field->get_id(), true ),
						);
					}
					$data      = array(
						'form_id'  => $form_id,
						'settings' => array( 'title' => $forms[ $form_id ]['title'] ),
						'fields'   => $fields,
						'actions'  => array( 'save' => array( 'sub_id' => (int) $post->ID ) ),
					);
					$results[] = self::through(
						self::base( $post->post_date_gmt ),
						static function () use ( $data ) {
							AARYE_Integrations::ninja( $data );
						}
					);
				}
				break;

			case 'formidable':
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, form_id, created_at, ip, description FROM %i WHERE is_draft = 0 AND parent_item_id = 0 ORDER BY id LIMIT %d OFFSET %d', $wpdb->prefix . 'frm_items', $limit, $offset ) );
				foreach ( (array) $rows as $row ) {
					$desc      = maybe_unserialize( $row->description );
					$results[] = self::through(
						self::base( $row->created_at, is_array( $desc ) && isset( $desc['referrer'] ) ? self::first_url( $desc['referrer'] ) : '', $row->ip, is_array( $desc ) && isset( $desc['browser'] ) ? $desc['browser'] : '' ),
						static function () use ( $row ) {
							AARYE_Integrations::formidable( (int) $row->id, (int) $row->form_id, array() );
						}
					);
				}
				break;

			case 'jetformbuilder':
				// Only successful submissions (JetFormBuilder also records ones that failed validation).
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'success' OR status = '' OR status IS NULL ORDER BY id LIMIT %d OFFSET %d", $wpdb->prefix . 'jet_fb_records', $limit, $offset ) );
				foreach ( (array) $rows as $row ) {
					$values = $wpdb->get_results( $wpdb->prepare( 'SELECT field_name, field_type, field_value, field_attrs FROM %i WHERE record_id = %d ORDER BY id', $wpdb->prefix . 'jet_fb_records_fields', $row->id ) );
					$fields = array();
					$files  = array();
					foreach ( (array) $values as $v ) {
						if ( '' === (string) $v->field_name || '_' === $v->field_name[0] || in_array( $v->field_type, array( 'submit-field', 'form-break-field', 'heading-field', 'group-break-field' ), true ) ) {
							continue;
						}
						$attrs = json_decode( (string) $v->field_attrs, true );
						$label = is_array( $attrs ) && ! empty( $attrs['label'] ) ? (string) $attrs['label'] : AARYE_Entries::humanize( $v->field_name );
						if ( 'media-field' === $v->field_type ) {
							foreach ( self::urls( $v->field_value ) as $url ) {
								$files[] = array( 'label' => $label, 'url' => $url );
							}
							continue;
						}
						$fields[] = array( 'name' => $v->field_name, 'label' => $label, 'type' => $v->field_type, 'value' => self::flat( $v->field_value ) );
					}
					$results[] = AARYE_Entries::capture(
						array_merge(
							self::base( get_gmt_from_date( $row->created_at ), (string) $row->referrer, (string) $row->ip_address, (string) $row->user_agent ),
							array(
								'plugin'          => 'jetformbuilder',
								'form_id'         => (string) $row->form_id,
								'form_name'       => get_the_title( (int) $row->form_id ),
								'source_entry_id' => (string) $row->id,
								'fields'          => $fields,
								'files'           => $files,
							)
						)
					);
				}
				break;

			case 'metform':
				$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'metform-entry' AND post_status = 'publish' ORDER BY ID LIMIT %d OFFSET %d", $limit, $offset ) );
				foreach ( (array) $posts as $post ) {
					$form_id = (int) get_post_meta( $post->ID, 'metform_entries__form_id', true );
					$data    = get_post_meta( $post->ID, 'metform_entries__form_data', true );
					$upload  = get_post_meta( $post->ID, 'metform_entries__file_upload_new', true );
					$upload  = $upload ? $upload : get_post_meta( $post->ID, 'metform_entries__file_upload', true );
					$page    = (string) get_post_meta( $post->ID, 'mf_page_id', true );
					$results[] = self::through(
						array_merge( self::base( $post->post_date_gmt, is_numeric( $page ) ? (string) get_permalink( (int) $page ) : '' ), array( 'source_entry_id' => (string) $post->ID ) ),
						static function () use ( $form_id, $data, $upload ) {
							AARYE_Integrations::metform( $form_id, is_array( $data ) ? $data : array(), array(), array( 'file_upload_info' => is_array( $upload ) ? $upload : array() ) );
						}
					);
				}
				break;

			case 'elementor':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status <> 'trash' AND type = 'submission' ORDER BY id LIMIT %d OFFSET %d", $wpdb->prefix . 'e_submissions', $limit, $offset ) );
				foreach ( (array) $rows as $row ) {
					$labels = self::elementor_labels( (int) $row->post_id, (string) $row->element_id );
					$values = $wpdb->get_results( $wpdb->prepare( 'SELECT `key`, value FROM %i WHERE submission_id = %d ORDER BY id', $wpdb->prefix . 'e_submissions_values', $row->id ) );
					$fields = array();
					$files  = array();
					foreach ( (array) $values as $v ) {
						$def = isset( $labels[ $v->key ] ) ? $labels[ $v->key ] : array( 'label' => AARYE_Entries::humanize( $v->key ), 'type' => '' );
						if ( 'upload' === $def['type'] ) {
							foreach ( self::urls( $v->value ) as $url ) {
								$files[] = array( 'label' => $def['label'], 'url' => $url );
							}
							continue;
						}
						$fields[] = array( 'name' => $v->key, 'label' => $def['label'], 'type' => $def['type'], 'value' => (string) $v->value );
					}
					$results[] = AARYE_Entries::capture(
						array_merge(
							self::base( $row->created_at_gmt, (string) $row->referer, (string) $row->user_ip, (string) $row->user_agent ),
							array(
								'plugin'          => 'elementor',
								'form_id'         => (string) $row->element_id,
								'form_name'       => (string) $row->form_name,
								'source_entry_id' => (string) $row->id,
								'fields'          => $fields,
								'files'           => $files,
							)
						)
					);
				}
				break;

			case 'cf7':
				$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'flamingo_inbound' AND post_status = 'publish' ORDER BY ID LIMIT %d OFFSET %d", $limit, $offset ) );
				foreach ( (array) $posts as $post ) {
					$meta   = get_post_meta( $post->ID, '_meta', true );
					$meta   = is_array( $meta ) ? $meta : array();
					$names  = get_post_meta( $post->ID, '_fields', true );
					$fields = array();
					foreach ( is_array( $names ) ? array_keys( $names ) : array() as $name ) {
						if ( '_' === substr( (string) $name, 0, 1 ) ) {
							continue;
						}
						$fields[] = array( 'name' => (string) $name, 'label' => '', 'type' => '', 'value' => self::flat( get_post_meta( $post->ID, '_field_' . $name, true ) ) );
					}
					$terms     = wp_get_object_terms( $post->ID, 'flamingo_inbound_channel' );
					$channel   = is_array( $terms ) && $terms ? $terms[0] : null;
					$match     = $channel ? get_posts( array( 'post_type' => 'wpcf7_contact_form', 'title' => $channel->name, 'numberposts' => 1, 'post_status' => 'any' ) ) : array();
					$form      = $match ? $match[0] : null; // Flamingo files messages under the form's title.
					$results[] = AARYE_Entries::capture(
						array_merge(
							self::base( $post->post_date_gmt, isset( $meta['url'] ) ? $meta['url'] : '', isset( $meta['remote_ip'] ) ? $meta['remote_ip'] : '', isset( $meta['user_agent'] ) ? $meta['user_agent'] : '' ),
							array(
								'plugin'          => 'cf7',
								'form_id'         => $form ? (string) $form->ID : ( $channel ? $channel->slug : 'flamingo' ),
								'form_name'       => $channel ? $channel->name : 'Contact Form 7',
								'source_entry_id' => 'flamingo-' . $post->ID,
								'fields'          => $fields,
							)
						)
					);
				}
				break;

			default:
				if ( 0 === strpos( $key, 'houzez:' ) ) {
					$results = self::houzez( substr( $key, 7 ), $offset, $limit );
				}
		}
		// phpcs:enable
		$imported = 0;
		$existing = 0;
		foreach ( $results as $r ) {
			if ( $r > 0 ) {
				++$imported;
			} elseif ( -1 === (int) $r ) {
				++$existing;
			}
		}
		return array(
			'handled'  => count( $results ),
			'imported' => $imported,
			'existing' => $existing,
		);
	}

	/**
	 * Houzez CRM rows: every filled column becomes a field (the table layout can differ between Houzez versions).
	 *
	 * @param string $id     leads or enquiries.
	 * @param int    $offset Offset.
	 * @param int    $limit  Rows.
	 * @return int[] Results.
	 */
	private static function houzez( $id, $offset, $limit ) {
		global $wpdb;
		$tables = self::houzez_tables();
		if ( ! isset( $tables[ $id ] ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1 LIMIT %d OFFSET %d', $tables[ $id ], $limit, $offset ), ARRAY_A );
		$results = array();
		foreach ( (array) $rows as $row ) {
			$keys    = array_keys( $row );
			$pk      = (string) $row[ $keys[0] ];
			$created = '';
			foreach ( array( 'time', 'created_at', 'date', 'datetime', 'created', 'added_on', 'enquiry_date' ) as $c ) {
				if ( ! empty( $row[ $c ] ) ) {
					$created = is_numeric( $row[ $c ] ) ? gmdate( 'Y-m-d H:i:s', (int) $row[ $c ] ) : get_gmt_from_date( (string) $row[ $c ] );
					break;
				}
			}
			$fields = array();
			foreach ( $row as $col => $value ) {
				if ( $col === $keys[0] || '' === trim( (string) $value ) || preg_match( '/(^|_)(id|time|date|created|updated|modified)$/', $col ) ) {
					continue;
				}
				$value    = maybe_unserialize( $value );
				$fields[] = array( 'name' => $col, 'label' => AARYE_Entries::humanize( $col ), 'type' => '', 'value' => self::flat( $value ) );
			}
			$url       = isset( $row['source_link'] ) ? (string) $row['source_link'] : ( isset( $row['page_url'] ) ? (string) $row['page_url'] : '' );
			$results[] = AARYE_Entries::capture(
				array_merge(
					self::base( $created, $url ),
					array(
						'plugin'          => 'houzez',
						'form_id'         => $id,
						'form_name'       => 'leads' === $id ? __( 'Houzez CRM leads', 'aarye-marketing-addon-for-forms' ) : __( 'Houzez CRM enquiries', 'aarye-marketing-addon-for-forms' ),
						'source_entry_id' => $pk,
						'fields'          => $fields,
					)
				)
			);
		}
		return $results;
	}

	/**
	 * Labels and types of an Elementor form's fields, from the page design.
	 *
	 * @param int    $post_id    Page.
	 * @param string $element_id Form widget ID.
	 * @return array<string, array{label: string, type: string}>
	 */
	private static function elementor_labels( $post_id, $element_id ) {
		static $cache = array();
		$key = $post_id . ':' . $element_id;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$out  = array();
		$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
		$walk = static function ( $elements ) use ( &$walk, &$out, $element_id ) {
			foreach ( (array) $elements as $el ) {
				if ( is_array( $el ) && isset( $el['id'] ) && $element_id === (string) $el['id'] && ! empty( $el['settings']['form_fields'] ) ) {
					foreach ( (array) $el['settings']['form_fields'] as $f ) {
						if ( ! empty( $f['custom_id'] ) ) {
							$out[ $f['custom_id'] ] = array(
								'label' => ! empty( $f['field_label'] ) ? (string) $f['field_label'] : AARYE_Entries::humanize( $f['custom_id'] ),
								'type'  => isset( $f['field_type'] ) ? (string) $f['field_type'] : 'text',
							);
						}
					}
				}
				if ( is_array( $el ) && ! empty( $el['elements'] ) ) {
					$walk( $el['elements'] );
				}
			}
		};
		$walk( $data );
		$cache[ $key ] = $out;
		return $out;
	}

	/**
	 * Addresses in a stored value (one URL, a list, or JSON).
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function urls( $value ) {
		$value = is_string( $value ) && in_array( substr( trim( $value ), 0, 1 ), array( '[', '{' ), true ) ? json_decode( $value, true ) : $value;
		$out   = array();
		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}
		array_walk_recursive(
			$value,
			static function ( $v ) use ( &$out ) {
				if ( is_string( $v ) && preg_match( '#^https?://#', $v ) ) {
					$out[] = esc_url_raw( $v );
				}
			}
		);
		foreach ( $value as $item ) {
			if ( is_string( $item ) && preg_match( '/[\s,]/', $item ) ) {
				foreach ( preg_split( '/[\s,]+/', $item ) as $v ) {
					if ( preg_match( '#^https?://#', $v ) ) {
						$out[] = esc_url_raw( $v );
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A stored value as text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function flat( $value ) {
		if ( is_string( $value ) && in_array( substr( trim( $value ), 0, 1 ), array( '[', '{' ), true ) ) {
			$decoded = json_decode( $value, true );
			$value   = null !== $decoded ? $decoded : $value;
		}
		if ( is_array( $value ) ) {
			$parts = array();
			array_walk_recursive(
				$value,
				static function ( $v ) use ( &$parts ) {
					if ( is_scalar( $v ) && '' !== (string) $v ) {
						$parts[] = (string) $v;
					}
				}
			);
			return implode( ', ', $parts );
		}
		return (string) $value;
	}

	/**
	 * First address in a text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function first_url( $text ) {
		return preg_match( '#https?://\S+#', (string) $text, $m ) ? $m[0] : '';
	}

	/**
	 * Run a batch from the Import screen.
	 */
	public static function ajax() {
		if ( ! current_user_can( AARYE_Admin::cap() ) || ! check_ajax_referer( 'aarye_import', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms' ) ), 403 );
		}
		$key     = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$sources = self::sources();
		if ( ! isset( $sources[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown source.', 'aarye-marketing-addon-for-forms' ) ), 400 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large imports.
		}
		try {
			$r = self::batch( $key, $offset );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
		AARYE_Placements::forget();
		wp_send_json_success(
			array_merge(
				$r,
				array(
					'next'  => $offset + $r['handled'],
					'total' => $sources[ $key ]['total'],
					'done'  => $r['handled'] < self::BATCH,
				)
			)
		);
	}
}
