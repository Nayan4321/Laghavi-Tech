<?php
/**
 * My Account > Licenses: customers see their keys and remove sites.
 *
 * @package LaghaviLicenseManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer license screen.
 */
class LLM_Account {

	const ENDPOINT = 'licenses';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'content' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_remove_site' ) );
	}

	/**
	 * Register /my-account/licenses/.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Let WooCommerce know about the endpoint.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Menu item, placed before "Log out".
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function menu_item( $items ) {
		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = __( 'Licenses', 'laghavi-license-manager' );
		if ( $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public static function title() {
		return __( 'Licenses', 'laghavi-license-manager' );
	}

	/**
	 * Remove a site from one of the customer's licenses.
	 */
	public static function handle_remove_site() {
		if ( empty( $_POST['llm_remove_site'] ) || ! is_user_logged_in() ) {
			return;
		}
		check_admin_referer( 'llm_remove_site' );
		$license = LLM_Licenses::get( isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0 );
		$user    = wp_get_current_user();
		if ( $license && ( (int) $license->user_id === $user->ID || ( '' !== $license->email && $license->email === $user->user_email ) ) ) {
			LLM_Licenses::remove_activation( $license->id, sanitize_text_field( wp_unslash( $_POST['llm_remove_site'] ) ) );
			wc_add_notice( __( 'The site was removed. You can activate the key somewhere else now.', 'laghavi-license-manager' ) );
		}
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	/**
	 * The Licenses page.
	 */
	public static function content() {
		$user     = wp_get_current_user();
		$licenses = LLM_Licenses::for_customer( $user->ID, $user->user_email );
		if ( ! $licenses ) {
			echo '<p>' . esc_html__( 'You have no licenses yet.', 'laghavi-license-manager' ) . '</p>';
			return;
		}
		foreach ( $licenses as $license ) {
			$sites = LLM_Licenses::activations( $license->id );
			?>
			<div class="llm-license" style="margin-bottom:2em">
				<h3><?php echo esc_html( get_the_title( $license->product_id ) ); ?></h3>
				<table class="woocommerce-table shop_table">
					<tr><th><?php esc_html_e( 'License key', 'laghavi-license-manager' ); ?></th><td><code><?php echo esc_html( $license->license_key ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Status', 'laghavi-license-manager' ); ?></th><td><?php echo esc_html( LLM_Licenses::status_label( LLM_Licenses::status( $license ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Updates until', 'laghavi-license-manager' ); ?></th><td><?php echo esc_html( LLM_Licenses::expiry_label( $license ) ); ?></td></tr>
					<tr>
						<th><?php esc_html_e( 'Sites', 'laghavi-license-manager' ); ?></th>
						<td>
							<?php
							/* translators: 1: sites used, 2: sites allowed. */
							echo esc_html( sprintf( __( '%1$d of %2$s used', 'laghavi-license-manager' ), count( $sites ), $license->sites_allowed > 0 ? (string) $license->sites_allowed : __( 'unlimited', 'laghavi-license-manager' ) ) );
							?>
							<?php foreach ( $sites as $site ) : ?>
								<form method="post" style="margin:6px 0">
									<?php wp_nonce_field( 'llm_remove_site' ); ?>
									<input type="hidden" name="license_id" value="<?php echo esc_attr( $license->id ); ?>" />
									<code><?php echo esc_html( $site->site ); ?></code>
									<button type="submit" name="llm_remove_site" value="<?php echo esc_attr( $site->site ); ?>" class="button"><?php esc_html_e( 'Remove', 'laghavi-license-manager' ); ?></button>
								</form>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>
			</div>
			<?php
		}
	}
}
