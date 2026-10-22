<?php
/**
 * Product reviews from buyers only, with the month and year the reviewer bought the product.
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Buyer-only reviews.
 */
class LSUB_Reviews {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( LSUB_Settings::on( 'lsub_reviews_buyers_only' ) ) {
			// WooCommerce's own "verified owners only" rule: it hides the review form from everyone else
			// and refuses reviews they send anyway, so we only switch it on. Filtering the value read
			// (not pre_option) keeps WooCommerce's own setting saveable for when this is turned off.
			add_filter( 'option_woocommerce_review_rating_verification_required', array( __CLASS__, 'yes' ) );
			add_filter( 'default_option_woocommerce_review_rating_verification_required', array( __CLASS__, 'yes' ) );
		}
		if ( LSUB_Settings::on( 'lsub_reviews_bought_date' ) ) {
			add_action( 'woocommerce_review_meta', array( __CLASS__, 'bought_date' ), 20 );
		}
		add_action( 'comment_post', array( __CLASS__, 'remember_purchase' ), 20, 2 );
	}

	/**
	 * Option value "yes".
	 *
	 * @return string
	 */
	public static function yes() {
		return 'yes';
	}

	/**
	 * When the review's author first bought the product, as a Unix time, or 0 if they haven't.
	 * The month is saved with the review, so it is only looked up once.
	 *
	 * @param WP_Comment $comment Review.
	 * @return int
	 */
	public static function bought_time( $comment ) {
		$saved = get_comment_meta( $comment->comment_ID, '_lsub_bought', true );
		if ( '' !== $saved ) {
			return (int) $saved;
		}
		$time = self::first_purchase( (int) $comment->comment_post_ID, (int) $comment->user_id, (string) $comment->comment_author_email );
		if ( $time ) {
			update_comment_meta( $comment->comment_ID, '_lsub_bought', $time );
		}
		return $time;
	}

	/**
	 * Earliest paid order of a customer that contains the product (or one of its variations).
	 *
	 * @param int    $product_id Product ID.
	 * @param int    $user_id    Customer's user ID (0 for guests).
	 * @param string $email      Customer's email, used for guest orders.
	 * @return int Unix time, or 0.
	 */
	public static function first_purchase( $product_id, $user_id, $email ) {
		$customers = array_filter( array( $user_id, is_email( $email ) ? $email : '' ) );
		if ( ! $product_id || ! $customers ) {
			return 0;
		}
		$first = 0;
		foreach ( $customers as $customer ) {
			$orders = wc_get_orders(
				array(
					'customer' => $customer,
					'status'   => wc_get_is_paid_statuses(),
					'type'     => 'shop_order',
					'limit'    => 100,
					'orderby'  => 'date',
					'order'    => 'ASC',
				)
			);
			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					if ( (int) $item->get_product_id() !== $product_id ) {
						continue;
					}
					$date = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
					$time = $date ? $date->getTimestamp() : 0;
					if ( $time && ( ! $first || $time < $first ) ) {
						$first = $time;
					}
					break;
				}
			}
		}
		return $first;
	}

	/**
	 * Save the purchase month with a new review.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param int|string $approved   Approval status.
	 */
	public static function remember_purchase( $comment_id, $approved ) {
		$comment = get_comment( $comment_id );
		if ( $comment && 'product' === get_post_type( $comment->comment_post_ID ) && ! $comment->comment_parent ) {
			self::bought_time( $comment );
		}
	}

	/**
	 * "Purchased in March 2026" under the reviewer's name.
	 *
	 * @param WP_Comment $comment Review.
	 */
	public static function bought_date( $comment ) {
		if ( ! $comment instanceof WP_Comment || $comment->comment_parent || 'product' !== get_post_type( $comment->comment_post_ID ) ) {
			return;
		}
		$time = self::bought_time( $comment );
		if ( ! $time ) {
			return;
		}
		printf(
			'<p class="lsub-review-bought">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: month and year, e.g. March 2026. */
					__( 'Purchased in %s', 'laghavi-subscriptions' ),
					wp_date( 'F Y', $time )
				)
			)
		);
	}
}
