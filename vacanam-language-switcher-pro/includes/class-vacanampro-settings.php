<?php
/**
 * Pro settings: one option, and the "Pro" screen under Languages, where each module adds a section.
 *
 * @package VacanamPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro settings.
 */
class VACANAMPRO_Settings {

	const OPTION = 'vacanampro_settings';
	const PAGE   = 'vacanam-pro';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
		add_filter( 'vacanam_admin_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'admin_post_vacanampro_save', array( __CLASS__, 'save' ) );
	}

	/**
	 * Defaults (modules add theirs).
	 *
	 * @return array
	 */
	public static function defaults() {
		/**
		 * Default Pro settings.
		 *
		 * @param array $defaults Defaults.
		 */
		return (array) apply_filters(
			'vacanampro_setting_defaults',
			array(
				'share_slugs' => 'no',
				'bases'       => array(),
				'domains'     => array(),
			)
		);
	}

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$saved = get_option( self::OPTION, array() );
		$all   = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		// Modules add their defaults as they load: keep the result only once all of them have.
		if ( did_action( 'vacanampro_loaded' ) ) {
			self::$cache = $all;
		}
		return $all;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether a yes/no setting is on.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Save some settings.
	 *
	 * @param array $values Values.
	 */
	public static function update( $values ) {
		$saved = get_option( self::OPTION, array() );
		update_option( self::OPTION, array_merge( is_array( $saved ) ? $saved : array(), $values ) );
		self::$cache = null;
	}

	/**
	 * Who may change the settings (the same people as for the free plugin).
	 *
	 * @return string
	 */
	public static function capability() {
		/** This filter is documented in the free plugin. */
		return (string) apply_filters( 'vacanam_capability', 'manage_options' );
	}

	/**
	 * "Pro" screen under Languages.
	 */
	public static function menu() {
		add_submenu_page( 'vacanam', __( 'Vacanam Pro', 'vacanam-language-switcher-pro' ), __( 'Pro', 'vacanam-language-switcher-pro' ), self::capability(), self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Tab in the Languages screens.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs[ self::PAGE ] = __( 'Pro', 'vacanam-language-switcher-pro' );
		return $tabs;
	}

	/**
	 * The screen: each module prints a section inside one form.
	 */
	public static function page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		Vacanam_Admin::header( __( 'Vacanam Pro', 'vacanam-language-switcher-pro' ), self::PAGE );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'vacanam-language-switcher-pro' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="vacanampro_save" />';
		wp_nonce_field( 'vacanampro_save' );
		/**
		 * Sections of the Pro screen.
		 */
		do_action( 'vacanampro_settings_sections' );
		submit_button( __( 'Save Pro settings', 'vacanam-language-switcher-pro' ) );
		echo '</form></div>';
	}

	/**
	 * Save the screen: each module reads its own fields.
	 */
	public static function save() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'vacanam-language-switcher-pro' ) );
		}
		check_admin_referer( 'vacanampro_save' );
		/**
		 * Values to save from the Pro screen ($_POST is checked by the nonce above).
		 *
		 * @param array $values Values.
		 */
		$values = (array) apply_filters( 'vacanampro_save_settings', array() );
		self::update( $values );
		/**
		 * After the Pro settings are saved.
		 */
		do_action( 'vacanampro_settings_saved' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&saved=1' ) );
		exit;
	}
}
