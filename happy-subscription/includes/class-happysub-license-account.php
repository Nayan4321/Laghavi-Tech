<?php
/**
 * My Account > Licenses: customers see their keys and remove sites.
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer license screen.
 */
class HAPPYSUB_License_Account {

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
		$items[ self::ENDPOINT ] = __( 'Licenses', 'happy-subscription' );
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
		return __( 'Licenses', 'happy-subscription' );
	}

	/**
	 * Remove a site from one of the customer's licenses.
	 */
	public static function handle_remove_site() {
		if ( empty( $_POST['happysub_license_remove_site'] ) || ! is_user_logged_in() ) {
			return;
		}
		check_admin_referer( 'happysub_license_remove_site' );
		$license = HAPPYSUB_Licenses::get( isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0 );
		$user    = wp_get_current_user();
		if ( $license && ( (int) $license->user_id === $user->ID || ( '' !== $license->email && $license->email === $user->user_email ) ) ) {
			HAPPYSUB_Licenses::remove_activation( $license->id, sanitize_text_field( wp_unslash( $_POST['happysub_license_remove_site'] ) ) );
			wc_add_notice( __( 'The site was removed. You can activate the key somewhere else now.', 'happy-subscription' ) );
		}
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	/**
	 * Send the plugin zip to the logged-in owner of an active license.
	 */
	public static function handle_download() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce checked below.
		if ( empty( $_GET['happysub_license_download'] ) ) {
			return;
		}
		$id    = absint( $_GET['happysub_license_download'] );
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable
		$back = wc_get_account_endpoint_url( self::ENDPOINT );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $back ), wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}
		$license = HAPPYSUB_Licenses::get( $id );
		if ( ! wp_verify_nonce( $nonce, 'happysub_license_download_' . $id ) || ! $license || ! HAPPYSUB_Licenses::owned_by( $license, wp_get_current_user() ) ) {
			wc_add_notice( __( 'That download link is not valid. Please try again from this page.', 'happy-subscription' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		if ( 'active' !== HAPPYSUB_Licenses::status( $license ) ) {
			wc_add_notice( __( 'This license is no longer active, so the download is not available. Renew to download the latest version.', 'happy-subscription' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$path = HAPPYSUB_Licenses::file_path( $license );
		if ( '' === $path ) {
			wc_add_notice( __( 'The download is being prepared. Please try again a little later or contact us.', 'happy-subscription' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		$settings = HAPPYSUB_License_Product::settings( $license->product_id );
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
			wp_enqueue_style( 'happysub-lic-account', HAPPYSUB_URL . 'assets/css/licenses.css', array(), HAPPYSUB_VERSION . '.' . (int) filemtime( HAPPYSUB_DIR . 'assets/css/licenses.css' ) );
			wp_enqueue_script( 'happysub-lic-account', HAPPYSUB_URL . 'assets/js/licenses.js', array(), HAPPYSUB_VERSION . '.' . (int) filemtime( HAPPYSUB_DIR . 'assets/js/licenses.js' ), true );
			wp_localize_script( 'happysub-lic-account', 'happysubLicenses', array( 'copied' => __( 'Copied!', 'happy-subscription' ) ) );
		}
	}

	/**
	 * Subscription box on a license: status, next renewal, cancel or resume.
	 *
	 * @param object $license License.
	 */
	private static function subscription_box( $license ) {
		$sub = HAPPYSUB_Licenses::subscription( $license );
		if ( ! $sub || ! class_exists( 'HAPPYSUB_Account' ) || (int) $sub->user_id !== get_current_user_id() ) {
			return;
		}
		$return = wc_get_account_endpoint_url( self::ENDPOINT );
		$date   = static function ( $mysql ) {
			return $mysql ? wp_date( get_option( 'date_format' ), strtotime( $mysql . ' UTC' ) ) : '';
		};
		?>
		<div class="happysub-lic-sub happysub-lic-sub--<?php echo esc_attr( $sub->status ); ?>">
			<div class="happysub-lic-sub__text">
				<strong><?php echo esc_html( HAPPYSUB_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ) ); ?></strong>
				<span>
					<?php
					switch ( $sub->status ) {
						case 'pending-cancel':
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Cancelled. Your key keeps working until %s and you will not be charged again.', 'happy-subscription' ), $date( $sub->next_payment ) ) );
							break;
						case 'on-hold':
							esc_html_e( 'Your last payment did not go through. Please update your card in Subscriptions to keep your key active.', 'happy-subscription' );
							break;
						case 'cancelled':
						case 'expired':
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Subscription ended on %s.', 'happy-subscription' ), $date( $sub->end_date ) ) );
							break;
						default:
							/* translators: %s: date. */
							echo esc_html( $sub->next_payment ? sprintf( __( 'Renews automatically on %s.', 'happy-subscription' ), $date( $sub->next_payment ) ) : __( 'Active subscription.', 'happy-subscription' ) );
					}
					?>
				</span>
			</div>
			<div class="happysub-lic-sub__actions">
				<?php
				if ( 'pending-cancel' === $sub->status ) {
					HAPPYSUB_Account::action_button( $sub, 'resume', __( 'Keep my subscription', 'happy-subscription' ), 'alt', '', $return );
				} elseif ( in_array( $sub->status, array( 'trial', 'active', 'on-hold' ), true ) && class_exists( 'HAPPYSUB_Settings' ) && HAPPYSUB_Settings::on( 'happysub_customer_cancel' ) ) {
					HAPPYSUB_Account::action_button(
						$sub,
						'cancel',
						__( 'Cancel subscription', 'happy-subscription' ),
						'happysub-lic-cancel',
						/* translators: %s: date. */
						sprintf( __( 'Cancel? You will not be charged again, and your key keeps working until %s.', 'happy-subscription' ), $date( $sub->next_payment ) ),
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
		$licenses = HAPPYSUB_Licenses::for_customer( $user->ID, $user->user_email );
		if ( ! $licenses ) {
			echo '<div class="happysub-lic-empty"><p>' . esc_html__( 'You have no licenses yet.', 'happy-subscription' ) . '</p><a class="button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'Browse plugins', 'happy-subscription' ) . '</a></div>';
			return;
		}
		echo '<div class="happysub-licenses">';
		foreach ( $licenses as $license ) {
			$sites    = HAPPYSUB_Licenses::activations( $license->id );
			$status   = HAPPYSUB_Licenses::status( $license );
			$settings = HAPPYSUB_License_Product::settings( $license->product_id );
			$has_file = '' !== HAPPYSUB_Licenses::file_path( $license );
			?>
			<article class="happysub-lic-license happysub-lic-license--<?php echo esc_attr( $status ); ?>">
				<header class="happysub-lic-license__head">
					<div>
						<h3><?php echo esc_html( get_the_title( $license->product_id ) ); ?></h3>
						<?php if ( '' !== $settings['version'] ) : ?>
							<span class="happysub-lic-version">
								<?php
								/* translators: %s: version number. */
								echo esc_html( sprintf( __( 'Latest version %s', 'happy-subscription' ), $settings['version'] ) );
								?>
							</span>
						<?php endif; ?>
					</div>
					<span class="happysub-lic-badge happysub-lic-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( HAPPYSUB_Licenses::status_label( $status ) ); ?></span>
				</header>

				<div class="happysub-lic-key">
					<code class="happysub-lic-key__value"><?php echo esc_html( $license->license_key ); ?></code>
					<button type="button" class="button happysub-lic-copy" data-copy="<?php echo esc_attr( $license->license_key ); ?>"><?php esc_html_e( 'Copy', 'happy-subscription' ); ?></button>
					<?php if ( 'active' === $status && $has_file ) : ?>
						<a class="button alt happysub-lic-download" href="<?php echo esc_url( HAPPYSUB_Licenses::download_url( $license ) ); ?>"><?php esc_html_e( 'Download', 'happy-subscription' ); ?></a>
					<?php endif; ?>
				</div>

				<dl class="happysub-lic-facts">
					<div><dt><?php esc_html_e( 'Valid', 'happy-subscription' ); ?></dt><dd><?php echo esc_html( HAPPYSUB_Licenses::expiry_label( $license ) ); ?></dd></div>
					<div>
						<dt><?php esc_html_e( 'Sites', 'happy-subscription' ); ?></dt>
						<dd>
							<?php
							/* translators: 1: sites used, 2: sites allowed. */
							echo esc_html( sprintf( __( '%1$d of %2$s used', 'happy-subscription' ), count( $sites ), $license->sites_allowed > 0 ? (string) $license->sites_allowed : __( 'unlimited', 'happy-subscription' ) ) );
							?>
						</dd>
					</div>
				</dl>

				<?php self::subscription_box( $license ); ?>

				<?php if ( $sites ) : ?>
					<ul class="happysub-lic-sites">
						<?php foreach ( $sites as $site ) : ?>
							<li>
								<code><?php echo esc_html( $site->site ); ?></code>
								<form method="post">
									<?php wp_nonce_field( 'happysub_license_remove_site' ); ?>
									<input type="hidden" name="license_id" value="<?php echo esc_attr( $license->id ); ?>" />
									<button type="submit" name="happysub_license_remove_site" value="<?php echo esc_attr( $site->site ); ?>" class="happysub-lic-link"><?php esc_html_e( 'Remove site', 'happy-subscription' ); ?></button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( 'active' === $status ) : ?>
					<details class="happysub-lic-help">
						<summary><?php esc_html_e( 'How to install', 'happy-subscription' ); ?></summary>
						<ol>
							<li><?php esc_html_e( 'Click Download to get the plugin zip.', 'happy-subscription' ); ?></li>
							<li><?php esc_html_e( 'In your WordPress admin go to Plugins > Add New > Upload Plugin, choose the zip and activate it.', 'happy-subscription' ); ?></li>
							<li><?php esc_html_e( 'Open the plugin\'s license screen, paste the key above and click Activate. Updates then arrive automatically.', 'happy-subscription' ); ?></li>
						</ol>
					</details>
				<?php endif; ?>
			</article>
			<?php
		}
		echo '</div>';
	}
}
