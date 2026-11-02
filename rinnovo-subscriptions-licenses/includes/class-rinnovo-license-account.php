<?php
/**
 * My Account > Licenses: customers see their keys and remove sites.
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer license screen.
 */
class RINNOVO_License_Account {

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
		$items[ self::ENDPOINT ] = __( 'Licenses', 'rinnovo-subscriptions-licenses' );
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
		return __( 'Licenses', 'rinnovo-subscriptions-licenses' );
	}

	/**
	 * Remove a site from one of the customer's licenses.
	 */
	public static function handle_remove_site() {
		if ( empty( $_POST['rinnovo_license_remove_site'] ) || ! is_user_logged_in() ) {
			return;
		}
		check_admin_referer( 'rinnovo_license_remove_site' );
		$license = RINNOVO_Licenses::get( isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0 );
		$user    = wp_get_current_user();
		if ( $license && ( (int) $license->user_id === $user->ID || ( '' !== $license->email && $license->email === $user->user_email ) ) ) {
			RINNOVO_Licenses::remove_activation( $license->id, sanitize_text_field( wp_unslash( $_POST['rinnovo_license_remove_site'] ) ) );
			wc_add_notice( __( 'The site was removed. You can activate the key somewhere else now.', 'rinnovo-subscriptions-licenses' ) );
		}
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	/**
	 * Send the plugin zip to the logged-in owner of an active license.
	 */
	public static function handle_download() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce checked below.
		if ( empty( $_GET['rinnovo_license_download'] ) ) {
			return;
		}
		$id    = absint( $_GET['rinnovo_license_download'] );
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable
		$back = wc_get_account_endpoint_url( self::ENDPOINT );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $back ), wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}
		$license = RINNOVO_Licenses::get( $id );
		if ( ! wp_verify_nonce( $nonce, 'rinnovo_license_download_' . $id ) || ! $license || ! RINNOVO_Licenses::owned_by( $license, wp_get_current_user() ) ) {
			wc_add_notice( __( 'That download link is not valid. Please try again from this page.', 'rinnovo-subscriptions-licenses' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		if ( 'active' !== RINNOVO_Licenses::status( $license ) ) {
			wc_add_notice( __( 'This license is no longer active, so the download is not available. Renew to download the latest version.', 'rinnovo-subscriptions-licenses' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$path = RINNOVO_Licenses::file_path( $license );
		if ( '' === $path ) {
			wc_add_notice( __( 'The download is being prepared. Please try again a little later or contact us.', 'rinnovo-subscriptions-licenses' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		$settings = RINNOVO_License_Product::settings( $license->product_id );
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
			wp_enqueue_style( 'rinnovo-lic-account', RINNOVO_URL . 'assets/css/licenses.css', array(), RINNOVO_VERSION . '.' . (int) filemtime( RINNOVO_DIR . 'assets/css/licenses.css' ) );
			wp_enqueue_script( 'rinnovo-lic-account', RINNOVO_URL . 'assets/js/licenses.js', array(), RINNOVO_VERSION . '.' . (int) filemtime( RINNOVO_DIR . 'assets/js/licenses.js' ), true );
			wp_localize_script( 'rinnovo-lic-account', 'rinnovoLicenses', array( 'copied' => __( 'Copied!', 'rinnovo-subscriptions-licenses' ) ) );
		}
	}

	/**
	 * Subscription box on a license: status, next renewal, cancel or resume.
	 *
	 * @param object $license License.
	 */
	private static function subscription_box( $license ) {
		$sub = RINNOVO_Licenses::subscription( $license );
		if ( ! $sub || ! class_exists( 'RINNOVO_Account' ) || (int) $sub->user_id !== get_current_user_id() ) {
			return;
		}
		$return = wc_get_account_endpoint_url( self::ENDPOINT );
		$date   = static function ( $mysql ) {
			return $mysql ? wp_date( get_option( 'date_format' ), strtotime( $mysql . ' UTC' ) ) : '';
		};
		?>
		<div class="rinnovo-lic-sub rinnovo-lic-sub--<?php echo esc_attr( $sub->status ); ?>">
			<div class="rinnovo-lic-sub__text">
				<strong><?php echo esc_html( RINNOVO_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ) ); ?></strong>
				<span>
					<?php
					switch ( $sub->status ) {
						case 'pending-cancel':
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Cancelled. Your key keeps working until %s and you will not be charged again.', 'rinnovo-subscriptions-licenses' ), $date( $sub->next_payment ) ) );
							break;
						case 'on-hold':
							esc_html_e( 'Your last payment did not go through. Please update your card in Subscriptions to keep your key active.', 'rinnovo-subscriptions-licenses' );
							break;
						case 'cancelled':
						case 'expired':
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Subscription ended on %s.', 'rinnovo-subscriptions-licenses' ), $date( $sub->end_date ) ) );
							break;
						default:
							/* translators: %s: date. */
							echo esc_html( $sub->next_payment ? sprintf( __( 'Renews automatically on %s.', 'rinnovo-subscriptions-licenses' ), $date( $sub->next_payment ) ) : __( 'Active subscription.', 'rinnovo-subscriptions-licenses' ) );
					}
					?>
				</span>
			</div>
			<div class="rinnovo-lic-sub__actions">
				<?php
				if ( 'pending-cancel' === $sub->status ) {
					RINNOVO_Account::action_button( $sub, 'resume', __( 'Keep my subscription', 'rinnovo-subscriptions-licenses' ), 'alt', '', $return );
				} elseif ( in_array( $sub->status, array( 'trial', 'active', 'on-hold' ), true ) && class_exists( 'RINNOVO_Settings' ) && RINNOVO_Settings::on( 'rinnovo_customer_cancel' ) ) {
					RINNOVO_Account::action_button(
						$sub,
						'cancel',
						__( 'Cancel subscription', 'rinnovo-subscriptions-licenses' ),
						'rinnovo-lic-cancel',
						/* translators: %s: date. */
						sprintf( __( 'Cancel? You will not be charged again, and your key keeps working until %s.', 'rinnovo-subscriptions-licenses' ), $date( $sub->next_payment ) ),
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
		$licenses = RINNOVO_Licenses::for_customer( $user->ID, $user->user_email );
		if ( ! $licenses ) {
			echo '<div class="rinnovo-lic-empty"><p>' . esc_html__( 'You have no licenses yet.', 'rinnovo-subscriptions-licenses' ) . '</p><a class="button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'Browse plugins', 'rinnovo-subscriptions-licenses' ) . '</a></div>';
			return;
		}
		echo '<div class="rinnovo-licenses">';
		foreach ( $licenses as $license ) {
			$sites    = RINNOVO_Licenses::activations( $license->id );
			$status   = RINNOVO_Licenses::status( $license );
			$settings = RINNOVO_License_Product::settings( $license->product_id );
			$has_file = '' !== RINNOVO_Licenses::file_path( $license );
			?>
			<article class="rinnovo-lic-license rinnovo-lic-license--<?php echo esc_attr( $status ); ?>">
				<header class="rinnovo-lic-license__head">
					<div>
						<h3><?php echo esc_html( get_the_title( $license->product_id ) ); ?></h3>
						<?php if ( '' !== $settings['version'] ) : ?>
							<span class="rinnovo-lic-version">
								<?php
								/* translators: %s: version number. */
								echo esc_html( sprintf( __( 'Latest version %s', 'rinnovo-subscriptions-licenses' ), $settings['version'] ) );
								?>
							</span>
						<?php endif; ?>
					</div>
					<span class="rinnovo-lic-badge rinnovo-lic-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( RINNOVO_Licenses::status_label( $status ) ); ?></span>
				</header>

				<div class="rinnovo-lic-key">
					<code class="rinnovo-lic-key__value"><?php echo esc_html( $license->license_key ); ?></code>
					<button type="button" class="button rinnovo-lic-copy" data-copy="<?php echo esc_attr( $license->license_key ); ?>"><?php esc_html_e( 'Copy', 'rinnovo-subscriptions-licenses' ); ?></button>
					<?php if ( 'active' === $status && $has_file ) : ?>
						<a class="button alt rinnovo-lic-download" href="<?php echo esc_url( RINNOVO_Licenses::download_url( $license ) ); ?>"><?php esc_html_e( 'Download', 'rinnovo-subscriptions-licenses' ); ?></a>
					<?php endif; ?>
				</div>

				<dl class="rinnovo-lic-facts">
					<div><dt><?php esc_html_e( 'Valid', 'rinnovo-subscriptions-licenses' ); ?></dt><dd><?php echo esc_html( RINNOVO_Licenses::expiry_label( $license ) ); ?></dd></div>
					<div>
						<dt><?php esc_html_e( 'Sites', 'rinnovo-subscriptions-licenses' ); ?></dt>
						<dd>
							<?php
							/* translators: 1: sites used, 2: sites allowed. */
							echo esc_html( sprintf( __( '%1$d of %2$s used', 'rinnovo-subscriptions-licenses' ), count( $sites ), $license->sites_allowed > 0 ? (string) $license->sites_allowed : __( 'unlimited', 'rinnovo-subscriptions-licenses' ) ) );
							?>
						</dd>
					</div>
				</dl>

				<?php self::subscription_box( $license ); ?>

				<?php if ( $sites ) : ?>
					<ul class="rinnovo-lic-sites">
						<?php foreach ( $sites as $site ) : ?>
							<li>
								<code><?php echo esc_html( $site->site ); ?></code>
								<form method="post">
									<?php wp_nonce_field( 'rinnovo_license_remove_site' ); ?>
									<input type="hidden" name="license_id" value="<?php echo esc_attr( $license->id ); ?>" />
									<button type="submit" name="rinnovo_license_remove_site" value="<?php echo esc_attr( $site->site ); ?>" class="rinnovo-lic-link"><?php esc_html_e( 'Remove site', 'rinnovo-subscriptions-licenses' ); ?></button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( 'active' === $status ) : ?>
					<details class="rinnovo-lic-help">
						<summary><?php esc_html_e( 'How to install', 'rinnovo-subscriptions-licenses' ); ?></summary>
						<ol>
							<li><?php esc_html_e( 'Click Download to get the plugin zip.', 'rinnovo-subscriptions-licenses' ); ?></li>
							<li><?php esc_html_e( 'In your WordPress admin go to Plugins > Add New > Upload Plugin, choose the zip and activate it.', 'rinnovo-subscriptions-licenses' ); ?></li>
							<li><?php esc_html_e( 'Open the plugin\'s license screen, paste the key above and click Activate. Updates then arrive automatically.', 'rinnovo-subscriptions-licenses' ); ?></li>
						</ol>
					</details>
				<?php endif; ?>
			</article>
			<?php
		}
		echo '</div>';
	}
}
