<?php
/**
 * Member directory, profiles and badges.
 *
 * [member_directory tier="" per_page="24"] lists members who chose to be listed (or all members, as you
 * set it), with search; a click opens their profile on the same page. Badges show the tier and how long
 * someone has been a member, next to their name in comments, bbPress and BuddyPress / BuddyBoss profiles.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Directory.
 */
class RINNOVOMEM_Directory {

	const TD = 'rinnovo-membership-content-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_shortcode( 'member_directory', array( __CLASS__, 'shortcode' ) );
		add_filter( 'get_comment_author_link', array( __CLASS__, 'comment_badge' ), 20, 3 );
		add_filter( 'bbp_get_reply_author_link', array( __CLASS__, 'bbp_badge' ), 20, 2 );
		add_filter( 'bbp_get_topic_author_link', array( __CLASS__, 'bbp_badge' ), 20, 2 );
		add_action( 'bp_before_member_header_meta', array( __CLASS__, 'bp_badge' ) );
	}

	/**
	 * Badges of a user: their tier and how long they've been a member.
	 *
	 * @param int $user_id User.
	 * @return string HTML.
	 */
	public static function badges( $user_id ) {
		$top = RINNOVOMEM_Members::top( $user_id );
		if ( '' === $top ) {
			return '';
		}
		$since = RINNOVOMEM_Members::since( $user_id, $top );
		$years = $since ? (int) floor( ( time() - $since ) / YEAR_IN_SECONDS ) : 0;
		$html  = RINNOVOMEM_Tiers::badge( $top );
		if ( $years >= 1 ) {
			/* translators: %d: years. */
			$html .= ' <span class="rinnovomem-badge rinnovomem-badge--loyal">' . esc_html( sprintf( _n( '%d year', '%d years', $years, self::TD ), $years ) ) . '</span>';
		} elseif ( $since && time() - $since < 30 * DAY_IN_SECONDS ) {
			$html .= ' <span class="rinnovomem-badge rinnovomem-badge--new">' . esc_html__( 'New member', self::TD ) . '</span>';
		}
		return '<span class="rinnovomem-badges">' . $html . '</span>';
	}

	/**
	 * Whether a member is in the directory.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function listed( $user_id ) {
		$mode   = RINNOVOMEM_Settings::get( 'directory' );
		$choice = (string) get_user_meta( $user_id, 'rinnovomem_listed', true );
		if ( 'off' === $mode ) {
			return false;
		}
		return 'all' === $mode ? 'no' !== $choice : 'yes' === $choice;
	}

	/**
	 * [member_directory].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'tier'     => '',
				'per_page' => 24,
				'members'  => 'yes',
			),
			$atts,
			'member_directory'
		);
		if ( 'off' === RINNOVOMEM_Settings::get( 'directory' ) ) {
			return '';
		}
		// The directory itself is for members unless members="no".
		if ( 'no' !== $atts['members'] && RINNOVOMEM_Members::rank( get_current_user_id() ) < 1 && ! current_user_can( 'edit_others_posts' ) ) {
			$all = RINNOVOMEM_Tiers::all();
			return RINNOVOMEM_Paywall::box(
				array(
					'ok'     => false,
					'reason' => is_user_logged_in() ? 'tier' : 'login',
					'tier'   => $all ? (string) key( $all ) : RINNOVOMEM_Tiers::FREE,
					'unlock' => 0,
				),
				'block'
			);
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only browsing.
		$slug   = isset( $_GET['member'] ) ? sanitize_title( wp_unslash( $_GET['member'] ) ) : '';
		$search = isset( $_GET['member_search'] ) ? sanitize_text_field( wp_unslash( $_GET['member_search'] ) ) : '';
		$page   = isset( $_GET['member_page'] ) ? max( 1, absint( $_GET['member_page'] ) ) : 1;
		// phpcs:enable
		if ( '' !== $slug ) {
			return self::profile( $slug );
		}
		$min   = '' !== $atts['tier'] ? max( 1, RINNOVOMEM_Tiers::rank( sanitize_key( $atts['tier'] ) ) ) : 1;
		$meta  = array(
			array(
				'key'     => '_rinnovomem_rank',
				'value'   => $min,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			),
		);
		if ( 'optin' === RINNOVOMEM_Settings::get( 'directory' ) ) {
			$meta[] = array(
				'key'   => 'rinnovomem_listed',
				'value' => 'yes',
			);
		} else {
			$meta[] = array(
				'relation' => 'OR',
				array(
					'key'     => 'rinnovomem_listed',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'rinnovomem_listed',
					'value'   => 'no',
					'compare' => '!=',
				),
			);
		}
		$per   = max( 1, min( 100, (int) $atts['per_page'] ) );
		$query = new WP_User_Query(
			array(
				'meta_query'  => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'search'      => '' !== $search ? '*' . $search . '*' : '',
				'number'      => $per,
				'paged'       => $page,
				'orderby'     => 'display_name',
				'count_total' => true,
			)
		);
		$base  = remove_query_arg( array( 'member', 'member_page' ) );
		$html  = '<div class="rinnovomem-directory"><form method="get" class="rinnovomem-directory__search"><input type="search" name="member_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search members', self::TD ) . '" /> <button type="submit" class="button">' . esc_html__( 'Search', self::TD ) . '</button></form><ul class="rinnovomem-directory__grid">';
		foreach ( $query->get_results() as $user ) {
			if ( RINNOVOMEM_Members::rank( $user->ID ) < $min ) {
				continue;
			}
			$html .= '<li><a href="' . esc_url( add_query_arg( 'member', $user->user_nicename, $base ) ) . '">' . get_avatar( $user->ID, 72 ) . '<strong>' . esc_html( $user->display_name ) . '</strong></a>' . self::badges( $user->ID );
			$headline = (string) get_user_meta( $user->ID, 'rinnovomem_headline', true );
			$html    .= '' !== $headline ? '<span class="rinnovomem-directory__headline">' . esc_html( $headline ) . '</span>' : '';
			$html    .= '</li>';
		}
		$html .= '</ul>';
		$pages = (int) ceil( $query->get_total() / $per );
		if ( $pages > 1 ) {
			$html .= '<nav class="rinnovomem-directory__pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$html .= $i === $page ? '<span>' . (int) $i . '</span> ' : '<a href="' . esc_url( add_query_arg( 'member_page', $i, $base ) ) . '">' . (int) $i . '</a> ';
			}
			$html .= '</nav>';
		}
		if ( ! $query->get_results() ) {
			$html .= '<p>' . esc_html__( 'No members found.', self::TD ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * One member's profile.
	 *
	 * @param string $slug Nicename.
	 * @return string
	 */
	private static function profile( $slug ) {
		$user = get_user_by( 'slug', $slug );
		if ( ! $user || RINNOVOMEM_Members::rank( $user->ID ) < 1 || ( ! self::listed( $user->ID ) && get_current_user_id() !== $user->ID ) ) {
			return '<p>' . esc_html__( 'This member is not listed.', self::TD ) . '</p>';
		}
		$top   = RINNOVOMEM_Members::top( $user->ID );
		$since = RINNOVOMEM_Members::since( $user->ID, $top );
		$html  = '<div class="rinnovomem-profile"><p><a href="' . esc_url( remove_query_arg( 'member' ) ) . '">&larr; ' . esc_html__( 'All members', self::TD ) . '</a></p>';
		$html .= '<div class="rinnovomem-profile__head">' . get_avatar( $user->ID, 120 ) . '<div><h2>' . esc_html( $user->display_name ) . '</h2>' . self::badges( $user->ID );
		$headline = (string) get_user_meta( $user->ID, 'rinnovomem_headline', true );
		$html    .= '' !== $headline ? '<p class="rinnovomem-profile__headline">' . esc_html( $headline ) . '</p>' : '';
		if ( $since ) {
			/* translators: %s: date. */
			$html .= '<p class="rinnovomem-profile__since">' . esc_html( sprintf( __( 'Member since %s', self::TD ), wp_date( 'F Y', $since ) ) ) . '</p>';
		}
		$html .= '</div></div>';
		if ( '' !== trim( (string) $user->description ) ) {
			$html .= '<div class="rinnovomem-profile__bio">' . wpautop( esc_html( $user->description ) ) . '</div>';
		}
		if ( '' !== $user->user_url ) {
			$html .= '<p><a href="' . esc_url( $user->user_url ) . '" rel="nofollow ugc noopener" target="_blank">' . esc_html( wp_parse_url( $user->user_url, PHP_URL_HOST ) ) . '</a></p>';
		}
		if ( function_exists( 'bp_core_get_user_domain' ) ) {
			$html .= '<p><a class="button" href="' . esc_url( bp_core_get_user_domain( $user->ID ) ) . '">' . esc_html__( 'Community profile', self::TD ) . '</a></p>';
		}
		return $html . '</div>';
	}

	/**
	 * Badge next to comment authors.
	 *
	 * @param string $link       Author link.
	 * @param string $author     Author.
	 * @param int    $comment_id Comment.
	 * @return string
	 */
	public static function comment_badge( $link, $author = '', $comment_id = 0 ) {
		$comment = get_comment( $comment_id );
		return $comment && $comment->user_id && ! is_admin() ? $link . ' ' . self::badges( (int) $comment->user_id ) : $link;
	}

	/**
	 * Badge next to bbPress authors.
	 *
	 * @param string $link Author link.
	 * @param array  $args Arguments.
	 * @return string
	 */
	public static function bbp_badge( $link, $args = array() ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$author  = $post_id ? (int) get_post_field( 'post_author', $post_id ) : 0;
		return $author ? $link . ' ' . self::badges( $author ) : $link;
	}

	/**
	 * Badge on BuddyPress / BuddyBoss profiles.
	 */
	public static function bp_badge() {
		if ( function_exists( 'bp_displayed_user_id' ) && bp_displayed_user_id() ) {
			echo wp_kses_post( self::badges( bp_displayed_user_id() ) );
		}
	}
}
