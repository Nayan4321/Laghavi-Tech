<?php
/**
 * Two small admin touches:
 * 1. A review request, shown 3 days after install. "Maybe later" (or ×) hides it for 7 days; "I already did" / "Don't ask again" hides it for good.
 * 2. A short, optional "why are you deactivating?" form on the Plugins screen. Nothing is sent unless the person presses
 *    "Submit & Deactivate"; "Skip & Deactivate" sends nothing. The form says exactly what is sent.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Review notice and deactivation feedback.
 */
final class LGPI_Notices {

	const REVIEW_URL = 'https://wordpress.org/support/plugin/laghavi-performance-insight-for-woocommerce/reviews/#new-post';
	const FEEDBACK   = 'https://sh.laghavi.site/feedback';
	const FIRST      = 3 * DAY_IN_SECONDS;
	const AGAIN      = 7 * DAY_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'review_notice' ) );
		add_action( 'wp_ajax_lgpi_review', array( __CLASS__, 'review_ajax' ) );
		add_action( 'wp_ajax_lgpi_deactivate_feedback', array( __CLASS__, 'feedback_ajax' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_footer', array( __CLASS__, 'modal' ) );   // runs before the footer scripts are printed
	}

	/**
	 * Whether the review request is due now.
	 *
	 * @return bool
	 */
	private static function review_due() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$uid = get_current_user_id();
		if ( get_user_meta( $uid, 'lgpi_review_done', true ) ) {
			return false;
		}
		$installed = (int) get_option( 'lgpi_installed_at' );
		if ( ! $installed ) {
			add_option( 'lgpi_installed_at', time() );
			return false;
		}
		return time() >= max( $installed + self::FIRST, (int) get_user_meta( $uid, 'lgpi_review_later', true ) );
	}

	/**
	 * Screens where the request may show: the Dashboard, Plugins, and this plugin's own pages.
	 *
	 * @return bool
	 */
	private static function review_screen() {
		$s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $s && in_array( $s->id, array( 'dashboard', 'plugins' ), true ) ) {
			return true;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 0 === strpos( $page, 'lgpi' );
	}

	/**
	 * The review request.
	 */
	public static function review_notice() {
		if ( ! self::review_due() || ! self::review_screen() ) {
			return;
		}
		$days = max( 1, (int) floor( ( time() - (int) get_option( 'lgpi_installed_at' ) ) / DAY_IN_SECONDS ) );
		echo '<div class="notice notice-info is-dismissible lgpi-review" data-nonce="' . esc_attr( wp_create_nonce( 'lgpi_review' ) ) . '">';
		echo '<p><strong>' . esc_html__( 'Enjoying Performance Insight?', 'laghavi-performance-insight-for-woocommerce' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: %d: number of days */
				_n( 'You have been using it for %d day.', 'You have been using it for %d days.', $days, 'laghavi-performance-insight-for-woocommerce' ),
				$days
			)
		);
		echo ' ' . esc_html__( 'A short review on WordPress.org helps other store owners find it and keeps the plugin free. Thank you!', 'laghavi-performance-insight-for-woocommerce' ) . '</p>';
		echo '<p class="lgpi-review-actions">';
		echo '<a class="button button-primary" data-act="done" href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Leave a review', 'laghavi-performance-insight-for-woocommerce' ) . '</a> ';
		echo '<button type="button" class="button" data-act="later">' . esc_html__( 'Maybe later', 'laghavi-performance-insight-for-woocommerce' ) . '</button> ';
		echo '<button type="button" class="button-link" data-act="done">' . esc_html__( 'I already did', 'laghavi-performance-insight-for-woocommerce' ) . '</button> &nbsp;';
		echo '<button type="button" class="button-link" data-act="never">' . esc_html__( "Don't ask again", 'laghavi-performance-insight-for-woocommerce' ) . '</button>';
		echo '</p></div>';
	}

	/**
	 * Remember the answer for this administrator: later = again in 7 days, done / never = not again.
	 */
	public static function review_ajax() {
		check_ajax_referer( 'lgpi_review', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$act = isset( $_POST['act'] ) ? sanitize_key( wp_unslash( $_POST['act'] ) ) : 'later';
		if ( in_array( $act, array( 'done', 'never' ), true ) ) {
			update_user_meta( get_current_user_id(), 'lgpi_review_done', $act );
		} else {
			update_user_meta( get_current_user_id(), 'lgpi_review_later', time() + self::AGAIN );
		}
		wp_send_json_success();
	}

	/**
	 * Script and style: the review buttons wherever the notice can show, the form on the Plugins screen.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		$plugins = 'plugins.php' === $hook && current_user_can( 'activate_plugins' );
		if ( ! $plugins && ! ( self::review_due() && self::review_screen() ) ) {
			return;
		}
		wp_enqueue_style( 'lgpi-notices', LGPI_URL . 'assets/css/notices.css', array(), LGPI_VERSION );
		wp_enqueue_script( 'lgpi-notices', LGPI_URL . 'assets/js/notices.js', array(), LGPI_VERSION, true );
		wp_localize_script(
			'lgpi-notices',
			'LGPI_NOTICES',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'plugin' => plugin_basename( LGPI_FILE ),
				'nonce'  => $plugins ? wp_create_nonce( 'lgpi_feedback' ) : '',
			)
		);
	}

	/**
	 * The deactivation form (hidden until this plugin's Deactivate link is clicked).
	 */
	public static function modal() {
		$s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $s || 'plugins' !== $s->id || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$journey = admin_url( 'admin.php?page=lgpi-journeys' );
		$reasons = array(
			'no_data'   => array( __( 'It shows no data or only zeros', 'laghavi-performance-insight-for-woocommerce' ), __( 'What did you expect to see?', 'laghavi-performance-insight-for-woocommerce' ), sprintf( /* translators: %s: link */ __( 'Visits are counted from the moment the plugin is switched on, and past orders and unfinished checkouts are brought in from WooCommerce in the background — have a look at %s.', 'laghavi-performance-insight-for-woocommerce' ), '<a href="' . esc_url( $journey ) . '">' . esc_html__( 'Customer journeys', 'laghavi-performance-insight-for-woocommerce' ) . '</a>' ) ),
			'not_found' => array( __( "I couldn't find the feature I need", 'laghavi-performance-insight-for-woocommerce' ), __( 'Which feature were you looking for?', 'laghavi-performance-insight-for-woocommerce' ), '' ),
			'confusing' => array( __( 'It is hard to understand or use', 'laghavi-performance-insight-for-woocommerce' ), __( 'What was confusing?', 'laghavi-performance-insight-for-woocommerce' ), '' ),
			'slow'      => array( __( 'It slows down my site', 'laghavi-performance-insight-for-woocommerce' ), __( 'Which page felt slow?', 'laghavi-performance-insight-for-woocommerce' ), '' ),
			'conflict'  => array( __( 'It conflicts with another plugin or my theme', 'laghavi-performance-insight-for-woocommerce' ), __( 'Which plugin or theme?', 'laghavi-performance-insight-for-woocommerce' ), '' ),
			'other_pi'  => array( __( 'I found a better plugin', 'laghavi-performance-insight-for-woocommerce' ), __( 'Which one? (helps us improve)', 'laghavi-performance-insight-for-woocommerce' ), '' ),
			'temporary' => array( __( "It's a temporary deactivation — I'm troubleshooting", 'laghavi-performance-insight-for-woocommerce' ), '', '' ),
			'other'     => array( __( 'Other', 'laghavi-performance-insight-for-woocommerce' ), __( 'Please tell us a little more', 'laghavi-performance-insight-for-woocommerce' ), '' ),
		);
		?>
		<div id="lgpi-deact" class="lgpi-deact" hidden>
			<div class="lgpi-deact-box" role="dialog" aria-modal="true" aria-labelledby="lgpi-deact-title">
				<form>
					<div class="lgpi-deact-head">
						<h2 id="lgpi-deact-title"><?php esc_html_e( 'Quick feedback', 'laghavi-performance-insight-for-woocommerce' ); ?></h2>
						<button type="button" class="lgpi-deact-x" data-act="close" aria-label="<?php esc_attr_e( 'Close', 'laghavi-performance-insight-for-woocommerce' ); ?>">&times;</button>
					</div>
					<div class="lgpi-deact-body">
						<p><strong><?php esc_html_e( 'If you have a moment, please let us know why you are deactivating Performance Insight:', 'laghavi-performance-insight-for-woocommerce' ); ?></strong></p>
						<ul>
							<?php foreach ( $reasons as $key => $r ) : ?>
								<li>
									<label><input type="radio" name="reason" value="<?php echo esc_attr( $key ); ?>"> <?php echo esc_html( $r[0] ); ?></label>
									<?php if ( $r[2] ) : ?>
										<p class="lgpi-deact-tip" hidden><?php echo wp_kses( $r[2], array( 'a' => array( 'href' => array() ) ) ); ?></p>
									<?php endif; ?>
									<?php if ( $r[1] ) : ?>
										<textarea name="details_<?php echo esc_attr( $key ); ?>" rows="2" maxlength="1000" placeholder="<?php echo esc_attr( $r[1] ); ?>" hidden></textarea>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
						<p>
							<label for="lgpi-deact-email"><?php esc_html_e( 'Your email (optional — only if you would like a reply):', 'laghavi-performance-insight-for-woocommerce' ); ?></label><br>
							<input type="email" id="lgpi-deact-email" name="email" maxlength="190" placeholder="you@example.com">
						</p>
						<p><label><input type="checkbox" name="site" value="1"> <?php esc_html_e( 'Include my site address', 'laghavi-performance-insight-for-woocommerce' ); ?></label></p>
						<p class="lgpi-deact-privacy"><?php esc_html_e( '“Submit & Deactivate” sends the reason, what you typed, your email and site address only if you gave them, and the plugin, WordPress, WooCommerce and PHP versions to Laghavi Digital. Nothing else, and nothing at all with “Skip & Deactivate”.', 'laghavi-performance-insight-for-woocommerce' ); ?></p>
					</div>
					<div class="lgpi-deact-foot">
						<button type="submit" class="button button-primary" data-act="submit" disabled><?php esc_html_e( 'Submit & Deactivate', 'laghavi-performance-insight-for-woocommerce' ); ?></button>
						<button type="button" class="button" data-act="skip"><?php esc_html_e( 'Skip & Deactivate', 'laghavi-performance-insight-for-woocommerce' ); ?></button>
						<button type="button" class="button-link" data-act="close"><?php esc_html_e( 'Cancel', 'laghavi-performance-insight-for-woocommerce' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Send the feedback the person chose to give (never blocks deactivation).
	 */
	public static function feedback_ajax() {
		check_ajax_referer( 'lgpi_feedback', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( null, 403 );
		}
		$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		if ( '' === $reason ) {
			wp_send_json_error();
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$body  = array(
			'p'       => 'lgpi-wp',
			'reason'  => substr( $reason, 0, 40 ),
			'details' => isset( $_POST['details'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['details'] ) ), 0, 1000 ) : '',
			'email'   => is_email( $email ) ? $email : '',
			'site'    => ! empty( $_POST['site'] ) ? home_url( '/' ) : '',
			'v'       => LGPI_VERSION,
			'wp'      => get_bloginfo( 'version' ),
			'wc'      => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'php'     => PHP_VERSION,
		);
		wp_remote_post(
			self::FEEDBACK,
			array(
				'timeout'  => 3,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $body ),
			)
		);
		wp_send_json_success();
	}
}
