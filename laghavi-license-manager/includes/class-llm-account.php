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
		add_action( 'template_redirect', array( __CLASS__, 'handle_download' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
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
	 * Send the plugin zip to the logged-in owner of an active license.
	 */
	public static function handle_download() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce checked below.
		if ( empty( $_GET['llm_download'] ) ) {
			return;
		}
		$id    = absint( $_GET['llm_download'] );
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable
		$back = wc_get_account_endpoint_url( self::ENDPOINT );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $back ), wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}
		$license = LLM_Licenses::get( $id );
		if ( ! wp_verify_nonce( $nonce, 'llm_download_' . $id ) || ! $license || ! LLM_Licenses::owned_by( $license, wp_get_current_user() ) ) {
			wc_add_notice( __( 'That download link is not valid. Please try again from this page.', 'laghavi-license-manager' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		if ( 'active' !== LLM_Licenses::status( $license ) ) {
			wc_add_notice( __( 'This license is no longer active, so the download is not available. Renew to download the latest version.', 'laghavi-license-manager' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$path = LLM_Licenses::file_path( $license );
		if ( '' === $path ) {
			wc_add_notice( __( 'The download is being prepared. Please try again a little later or contact us.', 'laghavi-license-manager' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		$settings = LLM_Product::settings( $license->product_id );
		$name     = ( '' !== $settings['slug'] ? $settings['slug'] : sanitize_title( get_the_title( $license->product_id ) ) ) . '.zip';
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Styles on the account pages.
	 */
	public static function assets() {
		if ( function_exists( 'is_account_page' ) && ( is_account_page() || is_wc_endpoint_url( 'order-received' ) ) ) {
			wp_enqueue_style( 'llm-account', LLM_URL . 'assets/css/llm.css', array(), LLM_VERSION . '.' . (int) filemtime( LLM_DIR . 'assets/css/llm.css' ) );
			wp_enqueue_script( 'llm-account', LLM_URL . 'assets/js/llm.js', array(), LLM_VERSION . '.' . (int) filemtime( LLM_DIR . 'assets/js/llm.js' ), true );
			wp_localize_script( 'llm-account', 'llmAccount', array( 'copied' => __( 'Copied!', 'laghavi-license-manager' ) ) );
		}
	}

	/**
	 * Subscription box on a license: status, next renewal, cancel or resume.
	 *
	 * @param object $license License.
	 */
	private static function subscription_box( $license ) {
		$sub = LLM_Licenses::subscription( $license );
		if ( ! $sub || ! class_exists( 'LSUB_Account' ) || (int) $sub->user_id !== get_current_user_id() ) {
			return;
		}
		$return = wc_get_account_endpoint_url( self::ENDPOINT );
		$date   = static function ( $mysql ) {
			return $mysql ? wp_date( get_option( 'date_format' ), strtotime( $mysql . ' UTC' ) ) : '';
		};
		?>
		<div class="llm-sub llm-sub--<?php echo esc_attr( $sub->status ); ?>">
			<div class="llm-sub__text">
				<strong><?php echo esc_html( LSUB_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ) ); ?></strong>
				<span>
					<?php
					switch ( $sub->status ) {
						case 'pending-cancel':
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Cancelled. Your key keeps working until %s and you will not be charged again.', 'laghavi-license-manager' ), $date( $sub->next_payment ) ) );
							break;
						case 'on-hold':
							esc_html_e( 'Your last payment did not go through. Please update your card in Subscriptions to keep your key active.', 'laghavi-license-manager' );
							break;
						case 'cancelled':
						case 'expired':
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Subscription ended on %s.', 'laghavi-license-manager' ), $date( $sub->end_date ) ) );
							break;
						default:
							/* translators: %s: date. */
							echo esc_html( $sub->next_payment ? sprintf( __( 'Renews automatically on %s.', 'laghavi-license-manager' ), $date( $sub->next_payment ) ) : __( 'Active subscription.', 'laghavi-license-manager' ) );
					}
					?>
				</span>
			</div>
			<div class="llm-sub__actions">
				<?php
				if ( 'pending-cancel' === $sub->status ) {
					LSUB_Account::action_button( $sub, 'resume', __( 'Keep my subscription', 'laghavi-license-manager' ), 'alt', '', $return );
				} elseif ( in_array( $sub->status, array( 'trial', 'active', 'on-hold' ), true ) && class_exists( 'LSUB_Settings' ) && LSUB_Settings::on( 'lsub_customer_cancel' ) ) {
					LSUB_Account::action_button(
						$sub,
						'cancel',
						__( 'Cancel subscription', 'laghavi-license-manager' ),
						'llm-cancel',
						/* translators: %s: date. */
						sprintf( __( 'Cancel? You will not be charged again, and your key keeps working until %s.', 'laghavi-license-manager' ), $date( $sub->next_payment ) ),
						$return
					);
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * The Licenses page.
	 */
	public static function content() {
		$user     = wp_get_current_user();
		$licenses = LLM_Licenses::for_customer( $user->ID, $user->user_email );
		if ( ! $licenses ) {
			echo '<div class="llm-empty"><p>' . esc_html__( 'You have no licenses yet.', 'laghavi-license-manager' ) . '</p><a class="button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'Browse plugins', 'laghavi-license-manager' ) . '</a></div>';
			return;
		}
		echo '<div class="llm-licenses">';
		foreach ( $licenses as $license ) {
			$sites    = LLM_Licenses::activations( $license->id );
			$status   = LLM_Licenses::status( $license );
			$settings = LLM_Product::settings( $license->product_id );
			$has_file = '' !== LLM_Licenses::file_path( $license );
			?>
			<article class="llm-license llm-license--<?php echo esc_attr( $status ); ?>">
				<header class="llm-license__head">
					<div>
						<h3><?php echo esc_html( get_the_title( $license->product_id ) ); ?></h3>
						<?php if ( '' !== $settings['version'] ) : ?>
							<span class="llm-version">
								<?php
								/* translators: %s: version number. */
								echo esc_html( sprintf( __( 'Latest version %s', 'laghavi-license-manager' ), $settings['version'] ) );
								?>
							</span>
						<?php endif; ?>
					</div>
					<span class="llm-badge llm-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( LLM_Licenses::status_label( $status ) ); ?></span>
				</header>

				<div class="llm-key">
					<code class="llm-key__value"><?php echo esc_html( $license->license_key ); ?></code>
					<button type="button" class="button llm-copy" data-copy="<?php echo esc_attr( $license->license_key ); ?>"><?php esc_html_e( 'Copy', 'laghavi-license-manager' ); ?></button>
					<?php if ( 'active' === $status && $has_file ) : ?>
						<a class="button alt llm-download" href="<?php echo esc_url( LLM_Licenses::download_url( $license ) ); ?>"><?php esc_html_e( 'Download', 'laghavi-license-manager' ); ?></a>
					<?php endif; ?>
				</div>

				<dl class="llm-facts">
					<div><dt><?php esc_html_e( 'Valid', 'laghavi-license-manager' ); ?></dt><dd><?php echo esc_html( LLM_Licenses::expiry_label( $license ) ); ?></dd></div>
					<div>
						<dt><?php esc_html_e( 'Sites', 'laghavi-license-manager' ); ?></dt>
						<dd>
							<?php
							/* translators: 1: sites used, 2: sites allowed. */
							echo esc_html( sprintf( __( '%1$d of %2$s used', 'laghavi-license-manager' ), count( $sites ), $license->sites_allowed > 0 ? (string) $license->sites_allowed : __( 'unlimited', 'laghavi-license-manager' ) ) );
							?>
						</dd>
					</div>
				</dl>

				<?php self::subscription_box( $license ); ?>

				<?php if ( $sites ) : ?>
					<ul class="llm-sites">
						<?php foreach ( $sites as $site ) : ?>
							<li>
								<code><?php echo esc_html( $site->site ); ?></code>
								<form method="post">
									<?php wp_nonce_field( 'llm_remove_site' ); ?>
									<input type="hidden" name="license_id" value="<?php echo esc_attr( $license->id ); ?>" />
									<button type="submit" name="llm_remove_site" value="<?php echo esc_attr( $site->site ); ?>" class="llm-link"><?php esc_html_e( 'Remove site', 'laghavi-license-manager' ); ?></button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( 'active' === $status ) : ?>
					<details class="llm-help">
						<summary><?php esc_html_e( 'How to install', 'laghavi-license-manager' ); ?></summary>
						<ol>
							<li><?php esc_html_e( 'Click Download to get the plugin zip.', 'laghavi-license-manager' ); ?></li>
							<li><?php esc_html_e( 'In your WordPress admin go to Plugins > Add New > Upload Plugin, choose the zip and activate it.', 'laghavi-license-manager' ); ?></li>
							<li><?php esc_html_e( 'Open the plugin\'s license screen, paste the key above and click Activate. Updates then arrive automatically.', 'laghavi-license-manager' ); ?></li>
						</ol>
					</details>
				<?php endif; ?>
			</article>
			<?php
		}
		echo '</div>';
	}
}
