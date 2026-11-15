<?php
/**
 * "Works great with" panel on every Rinnovo Pro settings screen: recommends Sarala Login Page, Vacanam Language
 * Switcher and the WooCommerce performance insights dashboard, only when they are not installed. Dismissible per
 * user (per plugin).
 *
 * @package RinnovoKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Panel.
 */
class RINNOVO_Kit_Panel {

	const META = 'rinnovo_kit_dismissed';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show' ) );
		add_action( 'admin_post_rinnovo_kit_dismiss', array( __CLASS__, 'dismiss' ) );
	}

	/**
	 * The recommended plugins.
	 *
	 * @return array[] key => name, text, url, installed.
	 */
	public static function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = array_keys( (array) get_plugins() );
		$has       = static function ( $slugs ) use ( $installed ) {
			foreach ( $installed as $file ) {
				if ( in_array( dirname( $file ), $slugs, true ) ) {
					return true;
				}
			}
			return false;
		};
		$list = array(
			'sarala'   => array(
				'name'      => 'Sarala Login Page',
				'text'      => __( 'Designed log-in, sign-up and account pages with Google, Apple and other social log-ins: members, students and clients sign in in one click.', 'rinnovo-kit' ),
				'url'       => 'https://laghavi.site/product/sarala-login-page-pro/',
				'installed' => defined( 'SARALOPA_VERSION' ) || $has( array( 'sarala-login-page', 'sarala-login-page-pro' ) ),
			),
			'vacanam'  => array(
				'name'      => 'Vacanam Language Switcher',
				'text'      => __( 'A multilingual store: products, emails and invoices in the customer\'s language, with currency per language.', 'rinnovo-kit' ),
				'url'       => 'https://laghavi.site/product/vacanam-language-switcher-pro/',
				'installed' => defined( 'VACANAM_VERSION' ) || $has( array( 'vacanam-language-switcher', 'vacanam-language-switcher-pro' ) ),
			),
			'insights' => array(
				'name'      => __( 'WooCommerce performance insights', 'rinnovo-kit' ),
				'text'      => __( 'One dashboard for your store and every Rinnovo plugin: MRR, churn, renewals, trials, box items, classes, contracts and what to follow up.', 'rinnovo-kit' ),
				'url'       => 'https://laghavi.site/shop/',
				/**
				 * Whether the insights dashboard plugin is installed (that plugin returns true).
				 *
				 * @param bool $installed Installed.
				 */
				'installed' => (bool) apply_filters( 'rinnovo_insights_dashboard_installed', false ),
			),
		);
		/**
		 * The "Works great with" list.
		 *
		 * @param array $list Plugins.
		 */
		return (array) apply_filters( 'rinnovo_kit_recommendations', $list );
	}

	/**
	 * Is this a Rinnovo Pro screen.
	 *
	 * @return bool
	 */
	private static function on_pro_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( (array) $GLOBALS['rinnovo_kit_pros'] as $pro ) {
			foreach ( (array) $pro['pages'] as $p ) {
				$parts = explode( ':', $p );
				if ( $page === $parts[0] && ( ! isset( $parts[1] ) || $tab === $parts[1] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Show.
	 */
	public static function maybe_show() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::on_pro_screen() ) {
			return;
		}
		echo self::html( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
	}

	/**
	 * Panel HTML (also usable on guide pages: $admin = false shows every plugin, no dismiss links).
	 *
	 * @param bool $admin Admin screen.
	 * @return string
	 */
	public static function html( $admin = false ) {
		$gone  = $admin ? (array) get_user_meta( get_current_user_id(), self::META, true ) : array();
		$cards = '';
		foreach ( self::plugins() as $key => $p ) {
			if ( $admin && ( ! empty( $p['installed'] ) || in_array( $key, $gone, true ) ) ) {
				continue;
			}
			$dismiss = $admin ? ' <a style="color:#646970;text-decoration:none" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rinnovo_kit_dismiss&plugin=' . rawurlencode( $key ) ), 'rinnovo_kit_dismiss' ) ) . '">' . esc_html__( 'Not now', 'rinnovo-kit' ) . '</a>' : '';
			$cards  .= '<div style="flex:1 1 220px;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;background:#fff"><strong>' . esc_html( $p['name'] ) . '</strong><p style="margin:6px 0 10px">' . esc_html( $p['text'] ) . '</p><a class="button" href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Learn more', 'rinnovo-kit' ) . '</a>' . $dismiss . '</div>';
		}
		if ( '' === $cards ) {
			return '';
		}
		return '<div class="' . ( $admin ? 'notice ' : '' ) . 'rinnovo-kit-panel" style="padding:12px 14px;background:#f6f7f7;border-left:4px solid #2271b1"><p style="margin:0 0 10px;font-weight:600">' . esc_html__( 'Works great with', 'rinnovo-kit' ) . '</p><div style="display:flex;flex-wrap:wrap;gap:12px">' . $cards . '</div></div>';
	}

	/**
	 * Dismiss one recommendation.
	 */
	public static function dismiss() {
		check_admin_referer( 'rinnovo_kit_dismiss' );
		$key  = isset( $_GET['plugin'] ) ? sanitize_key( wp_unslash( $_GET['plugin'] ) ) : '';
		$gone = (array) get_user_meta( get_current_user_id(), self::META, true );
		if ( '' !== $key && ! in_array( $key, $gone, true ) ) {
			$gone[] = $key;
			update_user_meta( get_current_user_id(), self::META, array_values( array_filter( $gone ) ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
