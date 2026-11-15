<?php
/**
 * "Works great with" box on Sarala Login Page screens: recommends other Laghavi plugins from WordPress.org.
 * Shown only to people who can install plugins, only on Sarala's own screens, and only for plugins that are
 * not active yet. Each card can be dismissed. Installing uses WordPress's own plugin installer.
 *
 * @package SaralaLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recommendations.
 */
class SARALOPA_Recommend {

	const META = 'saralopa_dismissed_recommendations';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'show' ) );
		add_action( 'admin_post_saralopa_dismiss_recommendation', array( __CLASS__, 'dismiss' ) );
	}

	/**
	 * The plugins: slug => name, text.
	 *
	 * @return array
	 */
	public static function plugins() {
		return array(
			'rinnovo-subscriptions-licenses'              => array(
				'name' => 'Rinnovo Subscriptions & Licenses',
				'text' => __( 'Sell subscriptions, memberships and software licenses with WooCommerce. Members sign up and log in with Sarala.', 'sarala-login-page' ),
			),
			'laghavi-performance-insight-for-woocommerce' => array(
				'name' => 'Performance Insight for WooCommerce',
				'text' => __( 'Cookieless analytics and SEO for your WooCommerce store: visitors, sales and what to improve, in one dashboard.', 'sarala-login-page' ),
			),
		);
	}

	/**
	 * Is this one of Sarala's screens.
	 *
	 * @return bool
	 */
	private static function on_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 0 === strpos( $page, 'sarala' ) || 0 === strpos( $page, 'slpp' );
	}

	/**
	 * Installed plugin file of a slug, or ''.
	 *
	 * @param string $slug Slug.
	 * @param array  $all  get_plugins().
	 * @return string
	 */
	private static function installed( $slug, $all ) {
		foreach ( array_keys( $all ) as $file ) {
			if ( dirname( $file ) === $slug ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * The box.
	 */
	public static function show() {
		if ( ! self::on_screen() || ! current_user_can( 'install_plugins' ) ) {
			return;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all   = get_plugins();
		$gone  = (array) get_user_meta( get_current_user_id(), self::META, true );
		$cards = '';
		foreach ( self::plugins() as $slug => $p ) {
			$file = self::installed( $slug, $all );
			if ( in_array( $slug, $gone, true ) || ( '' !== $file && is_plugin_active( $file ) ) ) {
				continue;
			}
			if ( '' !== $file ) {
				$button = current_user_can( 'activate_plugins' ) ? '<a class="button button-primary" href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file ) ) . '">' . esc_html__( 'Activate', 'sarala-login-page' ) . '</a>' : '';
			} else {
				$button = '<a class="button button-primary" href="' . esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $slug ) ), 'install-plugin_' . $slug ) ) . '">' . esc_html__( 'Install now', 'sarala-login-page' ) . '</a>';
			}
			$details = '<a href="' . esc_url( self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $slug ) . '&TB_iframe=true&width=772&height=700' ) ) . '" class="thickbox open-plugin-details-modal">' . esc_html__( 'Details', 'sarala-login-page' ) . '</a>';
			$dismiss = '<a style="color:#646970" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=saralopa_dismiss_recommendation&plugin=' . rawurlencode( $slug ) ), 'saralopa_dismiss_recommendation' ) ) . '">' . esc_html__( 'Not now', 'sarala-login-page' ) . '</a>';
			$cards  .= '<div style="flex:1 1 260px;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;background:#fff"><strong>' . esc_html( $p['name'] ) . '</strong><p style="margin:6px 0 10px">' . esc_html( $p['text'] ) . '</p><p style="margin:0;display:flex;gap:12px;align-items:center">' . $button . ' ' . $details . ' ' . $dismiss . '</p></div>';
		}
		if ( '' === $cards ) {
			return;
		}
		add_thickbox();
		echo '<div class="notice saralopa-recommend" style="padding:12px 14px;background:#f6f7f7;border-left:4px solid #2271b1"><p style="margin:0 0 10px;font-weight:600">' . esc_html__( 'Works great with Sarala Login Page', 'sarala-login-page' ) . '</p><div style="display:flex;flex-wrap:wrap;gap:12px">' . $cards . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
	}

	/**
	 * Hide one card for this user.
	 */
	public static function dismiss() {
		check_admin_referer( 'saralopa_dismiss_recommendation' );
		$slug = isset( $_GET['plugin'] ) ? sanitize_key( wp_unslash( $_GET['plugin'] ) ) : '';
		$gone = (array) get_user_meta( get_current_user_id(), self::META, true );
		if ( isset( self::plugins()[ $slug ] ) && ! in_array( $slug, $gone, true ) ) {
			$gone[] = $slug;
			update_user_meta( get_current_user_id(), self::META, array_values( array_filter( $gone ) ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . SARALOPA_Settings::PAGE ) );
		exit;
	}
}
