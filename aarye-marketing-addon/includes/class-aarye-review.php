<?php
/**
 * Review request: a small card asking for a review on WordPress.org, shown 7 days after install.
 * "Maybe later" brings it back 7 days later; "Rate Aarye" or "I already did" hide it for good.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Review request.
 */
class AARYE_Review {

	const INSTALLED = 'aarye_installed';
	const META      = 'aarye_review';
	const WAIT      = 7 * DAY_IN_SECONDS;
	const URL       = 'https://wordpress.org/support/plugin/aarye-marketing-addon/reviews/#new-post';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( ! get_option( self::INSTALLED ) ) {
			update_option( self::INSTALLED, time(), false );
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_aarye_review', array( __CLASS__, 'answer' ) );
	}

	/**
	 * Screens where the card may appear: Dashboard, Plugins and Aarye's own screens.
	 *
	 * @return bool
	 */
	private static function right_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || false !== strpos( (string) $screen->id, AARYE_Admin::SLUG );
	}

	/**
	 * Should the card show for this user now?
	 *
	 * @return bool
	 */
	public static function due() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$state = get_user_meta( get_current_user_id(), self::META, true );
		if ( 'done' === $state ) {
			return false;
		}
		$installed = (int) get_option( self::INSTALLED );
		$next      = is_numeric( $state ) ? (int) $state : $installed + self::WAIT;
		/**
		 * Show the review request (return false to never show it).
		 *
		 * @param bool $show Whether it is due.
		 */
		return (bool) apply_filters( 'aarye_show_review_request', $installed && time() >= $next );
	}

	/**
	 * Number of leads saved, for the message.
	 *
	 * @return int
	 */
	private static function leads() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', AARYE_Entries::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, counted once in a while for the message.
	}

	/**
	 * Style and script, only when the card shows.
	 */
	public static function assets() {
		if ( ! self::right_screen() || ! self::due() ) {
			return;
		}
		wp_enqueue_style( 'aarye-review', AARYE_URL . 'assets/css/review.css', array(), aarye_asset_version( 'assets/css/review.css' ) );
		wp_enqueue_script( 'aarye-review', AARYE_URL . 'assets/js/review.js', array(), aarye_asset_version( 'assets/js/review.js' ), true );
	}

	/**
	 * The card.
	 */
	public static function render() {
		if ( ! wp_script_is( 'aarye-review', 'enqueued' ) ) {
			return;
		}
		$leads = self::leads();
		?>
		<div class="aarye-review" id="aarye-review" role="dialog" aria-labelledby="aarye-review-title" data-nonce="<?php echo esc_attr( wp_create_nonce( 'aarye_review' ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<button type="button" class="aarye-review__close" data-answer="later" aria-label="<?php esc_attr_e( 'Close', 'aarye-marketing-addon' ); ?>">&times;</button>
			<div class="aarye-review__stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
			<h2 id="aarye-review-title"><?php esc_html_e( 'Enjoying Aarye Marketing Addon?', 'aarye-marketing-addon' ); ?></h2>
			<p>
				<?php
				if ( $leads > 0 ) {
					/* translators: %s: number of leads. */
					echo esc_html( sprintf( _n( 'Aarye has saved %s lead with its source for you so far.', 'Aarye has saved %s leads with their source for you so far.', $leads, 'aarye-marketing-addon' ), number_format_i18n( $leads ) ) ) . ' ';
				}
				esc_html_e( 'A 5-star review on WordPress.org takes a minute and helps other businesses find out which ads really bring leads. Thank you!', 'aarye-marketing-addon' );
				?>
			</p>
			<p class="aarye-review__actions">
				<a class="button button-primary" href="<?php echo esc_url( self::URL ); ?>" target="_blank" rel="noopener" data-answer="done"><?php esc_html_e( 'Rate Aarye on WordPress.org', 'aarye-marketing-addon' ); ?></a>
				<button type="button" class="button" data-answer="later"><?php esc_html_e( 'Maybe later', 'aarye-marketing-addon' ); ?></button>
				<button type="button" class="button-link" data-answer="done"><?php esc_html_e( 'I already did', 'aarye-marketing-addon' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Save the answer.
	 */
	public static function answer() {
		check_ajax_referer( 'aarye_review' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$answer = isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( $_POST['answer'] ) ) : '';
		update_user_meta( get_current_user_id(), self::META, 'done' === $answer ? 'done' : (string) ( time() + self::WAIT ) );
		wp_send_json_success();
	}
}
