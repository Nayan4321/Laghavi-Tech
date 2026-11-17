<?php
/**
 * Housekeeping: database cleanup (now or on a schedule), WordPress Heartbeat control, development
 * mode, and settings import / export.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Housekeeping.
 */
class Transara_Housekeeping {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ) );
		add_action( 'init', array( __CLASS__, 'setup' ) );
		add_action( 'transara_section_after', array( __CLASS__, 'database_box' ) );
		add_action( 'admin_post_transara_db_clean', array( __CLASS__, 'handle_clean' ) );
		add_action( 'transara_db_cleanup', array( __CLASS__, 'scheduled_clean' ) );
		add_action( 'transara_settings_saved', array( __CLASS__, 'schedule' ) );
		add_action( 'transara_tools_cards', array( __CLASS__, 'tools_cards' ), 5 );
		add_action( 'admin_post_transara_dev_mode', array( __CLASS__, 'handle_dev_mode' ) );
		add_action( 'admin_post_transara_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_transara_import', array( __CLASS__, 'handle_import' ) );
		add_filter( 'transara_skip_optimise', array( __CLASS__, 'dev_mode_skip' ) );
		add_filter( 'transara_may_cache', array( __CLASS__, 'dev_mode_cache' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dev_mode_notice' ) );
	}

	/**
	 * Settings.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		$beat = array(
			'default' => __( 'WordPress default (every 15–60 seconds)', 'transara' ),
			'reduce'  => __( 'Every 2 minutes', 'transara' ),
			'off'     => __( 'Off', 'transara' ),
		);
		return Transara_Settings::insert_sections(
			$schema,
			'speed',
			array(
				'database'  => array(
					'title'       => __( 'Database', 'transara' ),
					'description' => __( 'Remove what piles up over time. Make a backup first if you have never cleaned your database.', 'transara' ),
					'fields'      => array(
						'db_revisions'        => array(
							'type'    => 'checkbox',
							'label'   => __( 'Old revisions of posts and pages', 'transara' ),
							'default' => 1,
						),
						'db_keep_revisions'   => array(
							'type'    => 'number',
							'label'   => __( 'Keep the newest revisions', 'transara' ),
							'unit'    => __( 'per post', 'transara' ),
							'min'     => 0,
							'max'     => 100,
							'default' => 3,
						),
						'db_auto_drafts'      => array(
							'type'    => 'checkbox',
							'label'   => __( 'Automatic drafts', 'transara' ),
							'default' => 1,
						),
						'db_trashed_posts'    => array(
							'type'    => 'checkbox',
							'label'   => __( 'Posts in the trash', 'transara' ),
							'default' => 0,
						),
						'db_spam_comments'    => array(
							'type'    => 'checkbox',
							'label'   => __( 'Spam comments', 'transara' ),
							'default' => 1,
						),
						'db_trashed_comments' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Comments in the trash', 'transara' ),
							'default' => 1,
						),
						'db_transients'       => array(
							'type'    => 'checkbox',
							'label'   => __( 'Expired transients (temporary data)', 'transara' ),
							'default' => 1,
						),
						'db_optimize'         => array(
							'type'        => 'checkbox',
							'label'       => __( 'Optimise tables', 'transara' ),
							'description' => __( 'Frees space left by deleted rows. Can take a while on large sites.', 'transara' ),
							'default'     => 0,
						),
						'db_schedule'         => array(
							'type'    => 'select',
							'label'   => __( 'Clean automatically', 'transara' ),
							'options' => array(
								'off'     => __( 'No', 'transara' ),
								'daily'   => __( 'Every day', 'transara' ),
								'weekly'  => __( 'Every week', 'transara' ),
								'monthly' => __( 'Every month', 'transara' ),
							),
							'default' => 'weekly',
						),
					),
				),
				'heartbeat' => array(
					'title'       => __( 'Background requests (Heartbeat)', 'transara' ),
					'description' => __( 'WordPress\'s Heartbeat keeps sessions and post locks alive. Fewer requests mean less server load, especially with several editors.', 'transara' ),
					'fields'      => array(
						'heartbeat_admin'  => array(
							'type'    => 'select',
							'label'   => __( 'In the dashboard', 'transara' ),
							'options' => $beat,
							'default' => 'reduce',
						),
						'heartbeat_editor' => array(
							'type'        => 'select',
							'label'       => __( 'In the post editor', 'transara' ),
							'description' => __( 'Kept at least every 2 minutes in the editor: it saves drafts and warns when someone else edits.', 'transara' ),
							'options'     => array_slice( $beat, 0, 2, true ),
							'default'     => 'default',
						),
						'heartbeat_front'  => array(
							'type'    => 'select',
							'label'   => __( 'On the website', 'transara' ),
							'options' => $beat,
							'default' => 'off',
						),
					),
				),
			),
			'preload'
		);
	}

	/**
	 * Heartbeat.
	 */
	public static function setup() {
		add_filter( 'heartbeat_settings', array( __CLASS__, 'heartbeat_interval' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'heartbeat_off' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'heartbeat_off' ), 99 );
	}

	/**
	 * Which Heartbeat setting applies to this screen.
	 *
	 * @return string
	 */
	private static function heartbeat_mode() {
		if ( ! is_admin() ) {
			return (string) Transara_Settings::get( 'heartbeat_front' );
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
			return (string) Transara_Settings::get( 'heartbeat_editor' );
		}
		return (string) Transara_Settings::get( 'heartbeat_admin' );
	}

	/**
	 * Slower Heartbeat.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function heartbeat_interval( $settings ) {
		if ( 'reduce' === self::heartbeat_mode() ) {
			$settings['interval'] = 120;
		}
		return $settings;
	}

	/**
	 * No Heartbeat.
	 */
	public static function heartbeat_off() {
		if ( 'off' === self::heartbeat_mode() ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	/*
	 * Database.
	 */

	/**
	 * What would be removed: key => [ label, count ].
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Counts for the cleanup screen, always fresh.
		$keep = (int) Transara_Settings::get( 'db_keep_revisions' );
		return array(
			'db_revisions'        => array( __( 'Revisions', 'transara' ), count( self::old_revisions( $keep ) ) ),
			'db_auto_drafts'      => array( __( 'Automatic drafts', 'transara' ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s", 'auto-draft' ) ) ),
			'db_trashed_posts'    => array( __( 'Trashed posts', 'transara' ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s", 'trash' ) ) ),
			'db_spam_comments'    => array( __( 'Spam comments', 'transara' ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s", 'spam' ) ) ),
			'db_trashed_comments' => array( __( 'Trashed comments', 'transara' ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s", 'trash' ) ) ),
			'db_transients'       => array( __( 'Expired transients', 'transara' ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) ) ),
		);
		// phpcs:enable
	}

	/**
	 * Revision IDs beyond the newest $keep of each post.
	 *
	 * @param int $keep Revisions to keep per post.
	 * @return int[]
	 */
	private static function old_revisions( $keep ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = %s ORDER BY post_parent, post_date DESC, ID DESC", 'revision' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup query.
		$seen = array();
		$out  = array();
		foreach ( $rows as $r ) {
			$p          = (int) $r->post_parent;
			$seen[ $p ] = isset( $seen[ $p ] ) ? $seen[ $p ] + 1 : 1;
			if ( $seen[ $p ] > $keep ) {
				$out[] = (int) $r->ID;
			}
		}
		return $out;
	}

	/**
	 * Clean what is ticked.
	 *
	 * @return int Rows removed.
	 */
	public static function clean() {
		global $wpdb;
		$done = 0;
		if ( Transara_Settings::on( 'db_revisions' ) ) {
			foreach ( self::old_revisions( (int) Transara_Settings::get( 'db_keep_revisions' ) ) as $id ) {
				$done += wp_delete_post_revision( $id ) ? 1 : 0;
			}
		}
		$posts = array();
		if ( Transara_Settings::on( 'db_auto_drafts' ) ) {
			$posts[] = 'auto-draft';
		}
		if ( Transara_Settings::on( 'db_trashed_posts' ) ) {
			$posts[] = 'trash';
		}
		foreach ( $posts as $status ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s LIMIT 2000", $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup query.
			foreach ( $ids as $id ) {
				$done += wp_delete_post( (int) $id, true ) ? 1 : 0;
			}
		}
		$comments = array();
		if ( Transara_Settings::on( 'db_spam_comments' ) ) {
			$comments[] = 'spam';
		}
		if ( Transara_Settings::on( 'db_trashed_comments' ) ) {
			$comments[] = 'trash';
		}
		foreach ( $comments as $status ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s LIMIT 5000", $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup query.
			foreach ( $ids as $id ) {
				$done += wp_delete_comment( (int) $id, true ) ? 1 : 0;
			}
		}
		if ( Transara_Settings::on( 'db_transients' ) ) {
			delete_expired_transients( true );
			++$done;
		}
		if ( Transara_Settings::on( 'db_optimize' ) ) {
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Table maintenance.
			foreach ( $tables as $table ) {
				$wpdb->query( $wpdb->prepare( 'OPTIMIZE TABLE %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Table maintenance.
			}
		}
		update_option( 'transara_db_last_clean', time(), false );
		/**
		 * Database cleaned.
		 *
		 * @param int $done Items removed.
		 */
		do_action( 'transara_db_cleaned', $done );
		return $done;
	}

	/**
	 * Counts and the "Clean now" button under the Database section.
	 *
	 * @param string $section Section.
	 */
	public static function database_box( $section ) {
		if ( 'database' !== $section ) {
			return;
		}
		echo '<table class="transara-table" style="max-width:520px"><tbody>';
		foreach ( self::counts() as $row ) {
			echo '<tr><td>' . esc_html( $row[0] ) . '</td><td><strong>' . esc_html( number_format_i18n( $row[1] ) ) . '</strong></td></tr>';
		}
		echo '</tbody></table>';
		$last = (int) get_option( 'transara_db_last_clean' );
		echo '<p>' . Transara_Admin::action_button( 'transara_db_clean', __( 'Clean now (ticked items)', 'transara' ), 'secondary' ) . ' <span class="description">' . esc_html( $last ? sprintf( /* translators: %s: time ago */ __( 'Last cleaned %s ago.', 'transara' ), human_time_diff( $last ) ) : __( 'Not cleaned yet.', 'transara' ) ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
	}

	/**
	 * Admin action: clean now.
	 */
	public static function handle_clean() {
		check_admin_referer( 'transara_db_clean' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		$done = self::clean();
		Transara_Admin::flash( sprintf( /* translators: %d: number of items */ __( 'Database cleaned: %d items removed.', 'transara' ), $done ) );
		Transara_Admin::back();
	}

	/**
	 * Schedule the automatic cleanup.
	 */
	public static function schedule() {
		$when = (string) Transara_Settings::get( 'db_schedule' );
		wp_clear_scheduled_hook( 'transara_db_cleanup' );
		if ( in_array( $when, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'transara_db_cleanup' );
		}
	}

	/**
	 * Daily check: clean when the chosen period has passed.
	 */
	public static function scheduled_clean() {
		$days = array(
			'daily'   => 1,
			'weekly'  => 7,
			'monthly' => 30,
		);
		$when = (string) Transara_Settings::get( 'db_schedule' );
		if ( isset( $days[ $when ] ) && time() - (int) get_option( 'transara_db_last_clean' ) >= $days[ $when ] * DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			self::clean();
		}
	}

	/*
	 * Development mode.
	 */

	/**
	 * Whether development mode is on.
	 *
	 * @return bool
	 */
	public static function dev_mode() {
		return (int) get_option( 'transara_dev_until' ) > time();
	}

	/**
	 * Development mode: no optimisations.
	 *
	 * @param bool $skip Skip.
	 * @return bool
	 */
	public static function dev_mode_skip( $skip ) {
		return $skip || self::dev_mode();
	}

	/**
	 * Development mode: nothing is cached.
	 *
	 * @param bool $save Save.
	 * @return bool
	 */
	public static function dev_mode_cache( $save ) {
		return $save && ! self::dev_mode();
	}

	/**
	 * Notice while development mode is on.
	 */
	public static function dev_mode_notice() {
		$screen = get_current_screen();
		if ( ! $screen || ( ! in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) && false === strpos( $screen->id, 'transara' ) ) ) {
			return;
		}
		if ( self::dev_mode() && current_user_can( Transara_Admin::cap() ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( /* translators: %s: time left */ __( 'Transara development mode is on for %s more: pages are not cached or optimised.', 'transara' ), human_time_diff( (int) get_option( 'transara_dev_until' ) ) ) ) . ' ' . Transara_Admin::action_button( 'transara_dev_mode', __( 'Turn off', 'transara' ), 'secondary', array( 'hours' => 0 ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		}
	}

	/**
	 * Admin action: development mode on or off.
	 */
	public static function handle_dev_mode() {
		check_admin_referer( 'transara_dev_mode' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		$hours = isset( $_GET['hours'] ) ? absint( $_GET['hours'] ) : 0;
		if ( $hours ) {
			update_option( 'transara_dev_until', time() + min( 48, $hours ) * HOUR_IN_SECONDS, false );
			Transara_Cache::purge_all();
		} else {
			delete_option( 'transara_dev_until' );
		}
		Transara_Admin::back();
	}

	/*
	 * Tools.
	 */

	/**
	 * Tools cards: development mode, import and export.
	 */
	public static function tools_cards() {
		echo '<section class="transara-card"><h2>' . esc_html__( 'Development mode', 'transara' ) . '</h2><p>' . esc_html__( 'While you work on the design, pages are neither cached nor optimised, so every change shows at once. It turns itself off.', 'transara' ) . '</p><p>';
		if ( self::dev_mode() ) {
			echo Transara_Admin::action_button( 'transara_dev_mode', __( 'Turn off now', 'transara' ), 'primary', array( 'hours' => 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		} else {
			echo Transara_Admin::action_button( 'transara_dev_mode', __( 'On for 3 hours', 'transara' ), 'secondary', array( 'hours' => 3 ) ) . ' ' . Transara_Admin::action_button( 'transara_dev_mode', __( 'On for 24 hours', 'transara' ), 'secondary', array( 'hours' => 24 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		}
		echo '</p></section>';

		echo '<section class="transara-card"><h2>' . esc_html__( 'Export and import settings', 'transara' ) . '</h2><p>' . esc_html__( 'Copy your settings to another site. Passwords and keys are not exported.', 'transara' ) . '</p><p>' . Transara_Admin::action_button( 'transara_export', __( 'Download settings', 'transara' ), 'secondary' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="transara_import" />';
		wp_nonce_field( 'transara_import' );
		echo '<input type="file" name="transara_file" accept=".json,application/json" required /> ';
		submit_button( __( 'Import', 'transara' ), 'secondary', 'submit', false );
		echo '</form></section>';
	}

	/**
	 * Admin action: export.
	 */
	public static function handle_export() {
		check_admin_referer( 'transara_export' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		$out = array();
		foreach ( Transara_Settings::fields() as $key => $field ) {
			if ( 'secret' !== $field['type'] ) {
				$out[ $key ] = Transara_Settings::get( $key );
			}
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="transara-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode(
			array(
				'transara' => TRANSARA_VERSION,
				'settings' => $out,
			),
			JSON_PRETTY_PRINT
		);
		exit;
	}

	/**
	 * Admin action: import.
	 */
	public static function handle_import() {
		check_admin_referer( 'transara_import' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		$tmp  = isset( $_FILES['transara_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['transara_file']['tmp_name'] ) ) : '';
		$data = '' !== $tmp && is_uploaded_file( $tmp ) ? json_decode( (string) Transara_Cache::fs()->get_contents( $tmp ), true ) : null;
		if ( ! is_array( $data ) || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			Transara_Admin::flash( __( 'That file is not a Transara settings file.', 'transara' ), 'error' );
			Transara_Admin::back();
		}
		$values = array();
		foreach ( Transara_Settings::fields() as $key => $field ) {
			if ( 'secret' === $field['type'] || ! array_key_exists( $key, $data['settings'] ) ) {
				continue;
			}
			$raw            = $data['settings'][ $key ];
			$values[ $key ] = Transara_Settings::clean( $field, 'lines' === $field['type'] && is_array( $raw ) ? implode( "\n", $raw ) : $raw );
		}
		Transara_Settings::update( $values );
		Transara_Cache::purge_all();
		Transara_Admin::flash( sprintf( /* translators: %d: number of settings */ __( '%d settings imported.', 'transara' ), count( $values ) ) );
		Transara_Admin::back();
	}
}
