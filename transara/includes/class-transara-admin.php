<?php
/**
 * Admin screens: Dashboard, Speed, Tools (more tabs come from the other modules), the settings form,
 * messages after saving and the admin bar menu.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class Transara_Admin {

	const SLUG = 'transara';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_transara_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_filter( 'plugin_action_links_' . plugin_basename( TRANSARA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Who can manage Transara.
	 *
	 * @return string
	 */
	public static function cap() {
		/**
		 * Capability needed for Transara's screens.
		 *
		 * @param string $cap Capability.
		 */
		return (string) apply_filters( 'transara_capability', 'manage_options' );
	}

	/**
	 * Tabs: slug => [ title, callback ].
	 *
	 * @return array
	 */
	public static function pages() {
		$pages = array(
			self::SLUG               => array( __( 'Dashboard', 'transara' ), array( __CLASS__, 'page_dashboard' ) ),
			self::SLUG . '-speed'    => array( __( 'Speed', 'transara' ), array( __CLASS__, 'page_speed' ) ),
		);
		/**
		 * Admin pages (monitoring, security…).
		 *
		 * @param array $pages slug => [ title, callback ].
		 */
		$pages                        = (array) apply_filters( 'transara_admin_pages', $pages );
		$pages[ self::SLUG . '-tools' ] = array( __( 'Tools', 'transara' ), array( __CLASS__, 'page_tools' ) );
		return $pages;
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_menu_page( __( 'Transara', 'transara' ), __( 'Transara', 'transara' ), self::cap(), self::SLUG, array( __CLASS__, 'page_dashboard' ), 'dashicons-performance', 81 );
		foreach ( self::pages() as $slug => $page ) {
			add_submenu_page( self::SLUG, $page[0] . ' ‹ ' . __( 'Transara', 'transara' ), $page[0], self::cap(), $slug, $page[1] );
		}
	}

	/**
	 * Styles and script on Transara screens.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'transara-admin', TRANSARA_URL . 'assets/css/admin.css', array(), TRANSARA_VERSION );
		wp_enqueue_script( 'transara-admin', TRANSARA_URL . 'assets/js/admin.js', array(), TRANSARA_VERSION, true );
	}

	/**
	 * Plugins screen link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Dashboard', 'transara' ) . '</a>' );
		return $links;
	}

	/*
	 * Messages.
	 */

	/**
	 * Keep a message for the next screen.
	 *
	 * @param string $text Message.
	 * @param string $type success, error, warning or info.
	 */
	public static function flash( $text, $type = 'success' ) {
		$all   = get_transient( 'transara_notice_' . get_current_user_id() );
		$all   = is_array( $all ) ? $all : array();
		$all[] = array( (string) $type, (string) $text );
		set_transient( 'transara_notice_' . get_current_user_id(), $all, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Show kept messages.
	 */
	public static function notices() {
		$all = get_transient( 'transara_notice_' . get_current_user_id() );
		if ( ! is_array( $all ) ) {
			return;
		}
		delete_transient( 'transara_notice_' . get_current_user_id() );
		foreach ( $all as $n ) {
			$type = in_array( $n[0], array( 'success', 'error', 'warning', 'info' ), true ) ? $n[0] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $n[1] ) . '</p></div>';
		}
	}

	/**
	 * Back to a screen.
	 *
	 * @param string $page Page slug.
	 */
	public static function back( $page = self::SLUG ) {
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ? $ref : admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	/*
	 * Layout.
	 */

	/**
	 * Header with the tabs.
	 *
	 * @param string $current Current page slug.
	 */
	public static function header( $current ) {
		$pages = self::pages();
		echo '<div class="wrap transara"><div class="transara-head"><h1 class="transara-logo"><span class="dashicons dashicons-performance" aria-hidden="true"></span> ' . esc_html__( 'Transara', 'transara' ) . '</h1>';
		echo '<span class="transara-ver">' . esc_html( TRANSARA_VERSION ) . '</span></div>';
		echo '<nav class="nav-tab-wrapper transara-tabs">';
		foreach ( $pages as $slug => $page ) {
			echo '<a class="nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $page[0] ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Footer.
	 */
	public static function footer() {
		echo '</div>';
	}

	/**
	 * One field.
	 *
	 * @param string $key   Key.
	 * @param array  $field Field.
	 */
	public static function field( $key, $field ) {
		$value = Transara_Settings::get( $key );
		$id    = 'transara-' . $key;
		$name  = 'transara[' . $key . ']';
		echo '<tr><th scope="row">';
		if ( 'checkbox' !== $field['type'] ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
		}
		echo '</th><td>';
		switch ( $field['type'] ) {
			case 'checkbox':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" /><label class="transara-switch"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . ' /><span class="transara-switch__ui" aria-hidden="true"></span> ' . esc_html( $field['label'] ) . '</label>';
				break;
			case 'number':
				echo '<input type="number" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . ( isset( $field['min'] ) ? ' min="' . esc_attr( (string) $field['min'] ) . '"' : '' ) . ( isset( $field['max'] ) ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : '' ) . ' /> ' . ( isset( $field['unit'] ) ? '<span>' . esc_html( $field['unit'] ) . '</span>' : '' );
				break;
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( $field['options'] as $k => $l ) {
					echo '<option value="' . esc_attr( $k ) . '"' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
				}
				echo '</select>';
				break;
			case 'lines':
				echo '<textarea class="large-text code" rows="4" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( implode( "\n", is_array( $value ) ? $value : array() ) ) . '</textarea>';
				break;
			case 'secret':
				echo '<input type="password" class="regular-text" autocomplete="new-password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
				break;
			default:
				echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		if ( ! empty( $field['description'] ) ) {
			echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * A settings tab from the schema, with section links.
	 *
	 * @param string $tab Tab key.
	 */
	public static function settings_form( $tab ) {
		$schema = Transara_Settings::schema();
		if ( empty( $schema[ $tab ] ) ) {
			return;
		}
		$sections = $schema[ $tab ]['sections'];
		echo '<div class="transara-layout"><nav class="transara-side" aria-label="' . esc_attr__( 'Sections', 'transara' ) . '">';
		foreach ( $sections as $id => $section ) {
			echo '<a href="#transara-' . esc_attr( $id ) . '">' . esc_html( $section['title'] ) . '</a>';
		}
		echo '</nav><form class="transara-main" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="transara_save" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		wp_nonce_field( 'transara_save' );
		foreach ( $sections as $id => $section ) {
			echo '<section class="transara-card" id="transara-' . esc_attr( $id ) . '"><h2>' . esc_html( $section['title'] ) . '</h2>';
			if ( ! empty( $section['description'] ) ) {
				echo '<p class="transara-card__lead">' . esc_html( $section['description'] ) . '</p>';
			}
			echo '<table class="form-table" role="presentation">';
			foreach ( $section['fields'] as $key => $field ) {
				self::field( $key, $field );
			}
			echo '</table>';
			/**
			 * End of a settings section (buttons, status).
			 *
			 * @param string $id Section.
			 */
			do_action( 'transara_section_after', $id );
			echo '</section>';
		}
		echo '<div class="transara-savebar">';
		submit_button( __( 'Save changes', 'transara' ), 'primary', 'submit', false );
		echo '</div></form></div>';
	}

	/**
	 * Save a settings tab.
	 */
	public static function save() {
		check_admin_referer( 'transara_save' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$schema = Transara_Settings::schema();
		$posted = isset( $_POST['transara'] ) && is_array( $_POST['transara'] ) ? wp_unslash( $_POST['transara'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value cleaned by its field type below.
		if ( empty( $schema[ $tab ] ) ) {
			self::back();
		}
		$values = array();
		foreach ( $schema[ $tab ]['sections'] as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				if ( array_key_exists( $key, $posted ) ) {
					$values[ $key ] = Transara_Settings::clean( $field, $posted[ $key ] );
				}
			}
		}
		Transara_Settings::update( $values );
		self::flash( __( 'Saved. The cache was cleared so visitors get the new settings.', 'transara' ) );
		Transara_Cache::purge_all();
		self::back();
	}

	/*
	 * Pages.
	 */

	/**
	 * Dashboard.
	 */
	public static function page_dashboard() {
		self::header( self::SLUG );
		$stats = Transara_Cache::stats();
		$queue = Transara_Preload::progress();
		echo '<div class="transara-grid">';
		echo '<section class="transara-card transara-stat"><h2>' . esc_html__( 'Page cache', 'transara' ) . '</h2>';
		if ( Transara_Cache::active() ) {
			echo '<p class="transara-big">' . esc_html( number_format_i18n( $stats['files'] ) ) . '</p><p>' . esc_html( sprintf( /* translators: %s: size */ __( 'cached pages · %s', 'transara' ), size_format( $stats['bytes'] ) ) ) . '</p>';
		} else {
			echo '<p class="transara-warn">' . esc_html( Transara_Cache::problem() ) . '</p>';
		}
		echo '<p>' . self::action_button( 'transara_purge_all', __( 'Clear the cache', 'transara' ) ) . '</p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		echo '<section class="transara-card transara-stat"><h2>' . esc_html__( 'Preload', 'transara' ) . '</h2>';
		if ( Transara_Settings::on( 'preload_enabled' ) ) {
			echo '<p class="transara-big">' . esc_html( $queue['left'] ? number_format_i18n( $queue['left'] ) : '✓' ) . '</p><p>' . esc_html( $queue['left'] ? __( 'pages waiting to be cached', 'transara' ) : __( 'All pages from the sitemap are cached', 'transara' ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Preload is off.', 'transara' ) . '</p>';
		}
		echo '<p>' . self::action_button( 'transara_preload', __( 'Preload now', 'transara' ), 'secondary' ) . '</p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
		/**
		 * More dashboard cards (monitoring, security…).
		 */
		do_action( 'transara_dashboard_cards' );
		echo '</div>';
		self::footer();
	}

	/**
	 * A button that runs an admin-post action.
	 *
	 * @param string $action Action.
	 * @param string $label  Label.
	 * @param string $class  primary or secondary.
	 * @param array  $args   More arguments.
	 * @return string
	 */
	public static function action_button( $action, $label, $class = 'primary', $args = array() ) {
		$url = wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
		return '<a class="button button-' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Speed settings.
	 */
	public static function page_speed() {
		self::header( self::SLUG . '-speed' );
		self::settings_form( 'speed' );
		self::footer();
	}

	/**
	 * Tools.
	 */
	public static function page_tools() {
		self::header( self::SLUG . '-tools' );
		echo '<div class="transara-grid transara-grid--wide">';
		/**
		 * Tools cards.
		 */
		do_action( 'transara_tools_cards' );
		echo '<section class="transara-card"><h2>' . esc_html__( 'Rules for Nginx', 'transara' ) . '</h2><p>' . esc_html__( 'Nginx doesn\'t read .htaccess. Ask your host to add these lines to your site\'s server block for browser caching and compression.', 'transara' ) . '</p><textarea class="large-text code" rows="14" readonly>' . esc_textarea( Transara_Server_Rules::nginx() ) . '</textarea></section>';
		echo '</div>';
		self::footer();
	}

	/**
	 * Admin bar.
	 *
	 * @param WP_Admin_Bar $bar Bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'transara',
				'title' => '<span class="ab-icon dashicons dashicons-performance" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Transara', 'transara' ) . '</span>',
				'href'  => admin_url( 'admin.php?page=' . self::SLUG ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'transara',
				'id'     => 'transara-purge-all',
				'title'  => esc_html__( 'Clear all cache', 'transara' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=transara_purge_all' ), 'transara_purge_all' ),
			)
		);
		if ( ! is_admin() ) {
			$url = home_url( add_query_arg( array() ) );
			$bar->add_node(
				array(
					'parent' => 'transara',
					'id'     => 'transara-purge-page',
					'title'  => esc_html__( 'Clear this page', 'transara' ),
					'href'   => wp_nonce_url( add_query_arg( 'url', rawurlencode( $url ), admin_url( 'admin-post.php?action=transara_purge_url' ) ), 'transara_purge_url' ),
				)
			);
		}
		$bar->add_node(
			array(
				'parent' => 'transara',
				'id'     => 'transara-preload',
				'title'  => esc_html__( 'Preload the cache', 'transara' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=transara_preload' ), 'transara_preload' ),
			)
		);
	}
}
