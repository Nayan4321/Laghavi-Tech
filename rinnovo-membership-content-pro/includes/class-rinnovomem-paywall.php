<?php
/**
 * Teasers and paywalls: what non-members see instead of members-only content. An excerpt, the first words
 * or a blurred preview, then a "Subscribe to unlock" box linked to the tier's WooCommerce product, with
 * a log-in link. Content that is dripping says when it unlocks.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paywall.
 */
class RINNOVOMEM_Paywall {

	const TD = 'rinnovo-membership-content-pro';

	/**
	 * Current page address (for the log-in link).
	 *
	 * @return string
	 */
	private static function here() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $uri );
	}

	/**
	 * "From $9.00 / month" for a tier.
	 *
	 * @param string $tier Tier ID.
	 * @return string Plain text.
	 */
	private static function from_price( $tier ) {
		$cheapest = null;
		foreach ( RINNOVOMEM_Tiers::products( $tier ) as $product ) {
			if ( ! $cheapest || (float) $product->get_price() < (float) $cheapest->get_price() ) {
				$cheapest = $product;
			}
		}
		if ( ! $cheapest ) {
			return '';
		}
		$plan  = RINNOVO_Product::plan( $cheapest );
		$price = wp_strip_all_tags( wc_price( wc_get_price_to_display( $cheapest ) ) );
		/* translators: %s: price, e.g. "$9.00 / month". */
		return sprintf( __( 'From %s', self::TD ), $plan ? $price . ' ' . RINNOVO_Subscriptions::period_string( $plan['period'], $plan['interval'] ) : $price );
	}

	/**
	 * The teaser part of a post.
	 *
	 * @param int    $post_id Post.
	 * @param string $content Full content (raw).
	 * @return string HTML.
	 */
	public static function teaser( $post_id, $content ) {
		$mode  = RINNOVOMEM_Settings::get( 'teaser' );
		$words = max( 10, (int) RINNOVOMEM_Settings::get( 'teaser_words' ) );
		if ( 'none' === $mode ) {
			return '';
		}
		$post = get_post( $post_id );
		if ( 'excerpt' === $mode && $post && '' !== trim( $post->post_excerpt ) ) {
			return wpautop( esc_html( $post->post_excerpt ) );
		}
		// Content before a "More" block / <!--more--> tag is the teaser when there is one.
		if ( preg_match( '/<!--more(.*?)?-->/', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			$text = wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( substr( $content, 0, $m[0][1] ) ) ) );
		} else {
			$plain = wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $content ) ) );
			// Never more than a third of the content, so short posts are not given away.
			$total = count( preg_split( '/\s+/', trim( $plain ), -1, PREG_SPLIT_NO_EMPTY ) );
			$words = min( $words, (int) floor( $total / 3 ) );
			$text  = $words >= 5 ? wp_trim_words( $plain, $words, '…' ) : '';
		}
		$html = '' !== trim( $text ) ? '<div class="rinnovomem-teaser">' . wpautop( esc_html( $text ) ) . '</div>' : '';
		if ( 'blur' === $mode ) {
			// Placeholder lines, blurred: the real text never reaches the browser.
			$html .= '<div class="rinnovomem-blur" aria-hidden="true">';
			foreach ( array( 96, 88, 92, 70, 94, 84, 60 ) as $w ) {
				$html .= '<span style="width:' . (int) $w . '%"></span>';
			}
			$html .= '</div>';
		}
		return $html;
	}

	/**
	 * The paywall box.
	 *
	 * @param array  $result Result of RINNOVOMEM_Rules::check().
	 * @param string $kind   post, block, video or file.
	 * @return string HTML.
	 */
	public static function box( $result, $kind = 'post' ) {
		$tier = $result['tier'];
		$name = RINNOVOMEM_Tiers::FREE === $tier ? __( 'registered', self::TD ) : RINNOVOMEM_Tiers::name( $tier );
		if ( 'drip' === $result['reason'] ) {
			$days = max( 1, (int) ceil( ( $result['unlock'] - time() ) / DAY_IN_SECONDS ) );
			return '<div class="rinnovomem-paywall rinnovomem-paywall--drip"><p class="rinnovomem-paywall__title">' . esc_html__( 'Coming soon for you', self::TD ) . '</p><p>' . esc_html(
				sprintf(
					/* translators: 1: date, 2: number of days. */
					_n( 'This unlocks on %1$s (in %2$d day).', 'This unlocks on %1$s (in %2$d days).', $days, self::TD ),
					wp_date( get_option( 'date_format' ), $result['unlock'] ),
					$days
				)
			) . '</p></div>';
		}
		$title = 'video' === $kind ? __( 'This video is for members', self::TD ) : RINNOVOMEM_Settings::text( 'cta_title' );
		$text  = str_replace( '{tier}', $name, RINNOVOMEM_Settings::text( 'cta_text' ) );
		if ( RINNOVOMEM_Tiers::FREE === $tier ) {
			$title = __( 'Log in to see this', self::TD );
			$text  = __( 'This is for registered users. It is free: just log in or create an account.', self::TD );
		}
		$html  = '<div class="rinnovomem-paywall rinnovomem-paywall--' . esc_attr( $kind ) . '">';
		$html .= '<p class="rinnovomem-paywall__title">' . esc_html( $title ) . '</p>';
		$html .= '<p>' . esc_html( $text ) . '</p>';
		if ( RINNOVOMEM_Tiers::FREE !== $tier ) {
			$from  = self::from_price( $tier );
			$html .= '<p><a class="button alt rinnovomem-paywall__button" href="' . esc_url( RINNOVOMEM_Tiers::join_url( $tier ) ) . '">' . esc_html( RINNOVOMEM_Settings::text( 'cta_button' ) ) . '</a>';
			$html .= '' !== $from ? ' <span class="rinnovomem-paywall__price">' . esc_html( $from ) . '</span>' : '';
			$html .= '</p>';
		}
		if ( ! is_user_logged_in() && RINNOVOMEM_Settings::get( 'login_link' ) ) {
			$login = add_query_arg( 'redirect_to', rawurlencode( self::here() ), wc_get_page_permalink( 'myaccount' ) );
			$html .= '<p class="rinnovomem-paywall__login">' . ( RINNOVOMEM_Tiers::FREE === $tier ? '<a class="button alt" href="' . esc_url( $login ) . '">' . esc_html__( 'Log in or register', self::TD ) . '</a>' : esc_html__( 'Already a member?', self::TD ) . ' <a href="' . esc_url( $login ) . '">' . esc_html__( 'Log in', self::TD ) . '</a>' ) . '</p>';
		} elseif ( is_user_logged_in() && 'tier' === $result['reason'] && RINNOVOMEM_Members::top( get_current_user_id() ) ) {
			/* translators: %s: tier name. */
			$html .= '<p class="rinnovomem-paywall__login">' . esc_html( sprintf( __( 'Your %s membership does not include this. Upgrade from "My Content" in your account; you only pay the difference.', self::TD ), RINNOVOMEM_Tiers::name( RINNOVOMEM_Members::top( get_current_user_id() ) ) ) ) . '</p>';
		}
		return $html . '</div>';
	}
}
