<?php
/**
 * HTML of a feed: header, posts (grid, masonry, list or carousel), "Load more" and "Follow".
 *
 * All spacing in the stylesheet uses logical properties (padding-inline, inset-inline…), so feeds look
 * the same in right-to-left languages; texts carry dir="auto" so mixed languages read correctly.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render.
 */
class NYASAH_Render {

	/**
	 * Whole feed.
	 *
	 * @param array $s       Settings.
	 * @param array $options preview (bool): editor preview, skips the cache.
	 * @return string
	 */
	public static function feed( $s, $options = array() ) {
		$preview = ! empty( $options['preview'] );
		$data    = NYASAH_Cache::fetch( $s, $preview );
		$items   = $data['items'];
		NYASAH_Front::assets();

		if ( ! $items ) {
			if ( '' !== $data['error'] && ( $preview || current_user_can( 'manage_options' ) ) ) {
				return '<div class="nyasah-error" role="alert"><strong>' . esc_html__( 'Nyasah Social Feed', 'nyasah-social-feed' ) . ':</strong> ' . esc_html( $data['error'] ) . '<br /><small>' . esc_html__( 'Only administrators see this message.', 'nyasah-social-feed' ) . '</small></div>';
			}
			return $preview ? '<div class="nyasah-error">' . esc_html__( 'Nothing to show yet.', 'nyasah-social-feed' ) . '</div>' : '';
		}

		if ( ! in_array( $s['layout'], NYASAH_Feeds::layouts(), true ) ) {
			$s['layout'] = 'grid'; // A Pro layout without Nyasah Pro.
		}
		$count  = 'carousel' === $s['layout'] ? max( (int) $s['count'], 3 ) : (int) $s['count'];
		$net    = NYASAH_Networks::get( $s['network'] );
		$accent = '' !== $s['accent'] ? $s['accent'] : $net['color'];
		$style  = array(
			'--ny-cols:' . (int) $s['cols'],
			'--ny-cols-t:' . (int) $s['cols_tablet'],
			'--ny-cols-m:' . (int) $s['cols_mobile'],
			'--ny-gap:' . (int) $s['gap'] . 'px',
			'--ny-radius:' . (int) $s['radius'] . 'px',
			'--ny-accent:' . $accent,
			'--ny-ratio:' . self::ratio( 'masonry' === $s['layout'] ? 'original' : $s['ratio'], $s['network'] ),
		);
		if ( '' !== $s['bg'] ) {
			$style[] = '--ny-bg:' . $s['bg'];
		}
		if ( '' !== $s['text_color'] ) {
			$style[] = '--ny-text:' . $s['text_color'];
		}
		$classes = array(
			'nyasah',
			'nyasah--' . $s['layout'],
			'nyasah--' . $s['card'],
			'nyasah--net-' . $s['network'],
		);
		$attrs   = array(
			'class'      => implode( ' ', $classes ),
			'style'      => implode( ';', $style ),
			'data-click' => $s['click'],
		);
		if ( ! $preview && ! empty( $s['id'] ) ) {
			$attrs['data-feed'] = (int) $s['id'];
		}
		$html = '<div';
		foreach ( $attrs as $k => $v ) {
			$html .= ' ' . $k . '="' . esc_attr( $v ) . '"';
		}
		$html .= '>';
		if ( $s['show_header'] ) {
			$html .= self::header( $s, $data['profile'] );
		}
		if ( 'carousel' === $s['layout'] ) {
			$html .= '<div class="nyasah__carousel">';
			$html .= '<button type="button" class="nyasah__nav nyasah__nav--prev" aria-label="' . esc_attr__( 'Previous', 'nyasah-social-feed' ) . '"><span aria-hidden="true">&#8249;</span></button>';
		}
		$html .= '<div class="nyasah__items" role="list">' . self::items( $s, array_slice( $items, 0, $count ) ) . '</div>';
		if ( 'carousel' === $s['layout'] ) {
			$html .= '<button type="button" class="nyasah__nav nyasah__nav--next" aria-label="' . esc_attr__( 'Next', 'nyasah-social-feed' ) . '"><span aria-hidden="true">&#8250;</span></button></div>';
		}
		$footer = '';
		if ( $s['load_more'] && 'carousel' !== $s['layout'] && count( $items ) > $count && ! $preview && ! empty( $s['id'] ) ) {
			$text    = '' !== $s['load_more_text'] ? $s['load_more_text'] : __( 'Load more', 'nyasah-social-feed' );
			$footer .= '<button type="button" class="nyasah__more" data-offset="' . esc_attr( $count ) . '">' . esc_html( $text ) . '</button>';
		}
		// Below the posts only when there is no header (the header has its own button).
		if ( $s['show_follow'] && ! empty( $data['profile']['url'] ) && ( ! $s['show_header'] || empty( $data['profile']['name'] ) ) ) {
			$footer .= self::follow( $s, $data['profile']['url'] );
		}
		if ( '' !== $footer ) {
			$html .= '<div class="nyasah__footer">' . $footer . '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Next page of posts (for "Load more").
	 *
	 * @param array $s      Settings.
	 * @param int   $offset Posts already shown.
	 * @return array { html, more }
	 */
	public static function page( $s, $offset ) {
		$data  = NYASAH_Cache::fetch( $s );
		$items = array_slice( $data['items'], $offset, (int) $s['count'] );
		return array(
			'html' => self::items( $s, $items ),
			'more' => count( $data['items'] ) > $offset + (int) $s['count'],
		);
	}

	/**
	 * CSS aspect ratio.
	 *
	 * @param string $ratio   Setting.
	 * @param string $network Network (for "auto").
	 * @return string
	 */
	private static function ratio( $ratio, $network = '' ) {
		$map = NYASAH_Feeds::ratios();
		if ( 'auto' === $ratio || ! isset( $map[ $ratio ] ) ) {
			return NYASAH_Feeds::network_ratio( $network );
		}
		return $map[ $ratio ];
	}

	/**
	 * Profile header.
	 *
	 * @param array $s       Settings.
	 * @param array $profile Profile.
	 * @return string
	 */
	private static function header( $s, $profile ) {
		if ( empty( $profile['name'] ) ) {
			return '';
		}
		$url  = ! empty( $profile['url'] ) ? $profile['url'] : '';
		$html = '<div class="nyasah__header">';
		if ( $s['show_avatar'] ) {
			$html .= '<span class="nyasah__avatar">';
			$html .= ! empty( $profile['avatar'] ) ? '<img src="' . esc_url( $profile['avatar'] ) . '" alt="" width="64" height="64" loading="lazy" decoding="async" />' : wp_kses( NYASAH_Networks::icon( $s['network'], 32 ), NYASAH_Networks::svg_tags() );
			$html .= '</span>';
		}
		$html .= '<div class="nyasah__who">';
		$name  = esc_html( $profile['name'] );
		$html .= '<div class="nyasah__name" dir="auto">' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow">' . $name . '</a>' : $name ) . '</div>';
		$meta  = array();
		if ( ! empty( $profile['username'] ) && '@' . ltrim( $profile['username'], '@' ) !== $profile['name'] && $profile['username'] !== $profile['name'] ) {
			$meta[] = '<span dir="ltr">@' . esc_html( ltrim( $profile['username'], '@' ) ) . '</span>';
		}
		if ( isset( $profile['followers'] ) && null !== $profile['followers'] ) {
			/* translators: %s: number of followers */
			$meta[] = esc_html( sprintf( _n( '%s follower', '%s followers', (int) $profile['followers'], 'nyasah-social-feed' ), self::number( (int) $profile['followers'] ) ) );
		}
		if ( $meta ) {
			$html .= '<div class="nyasah__meta">' . implode( ' <span aria-hidden="true">·</span> ', $meta ) . '</div>';
		}
		if ( $s['show_bio'] && ! empty( $profile['bio'] ) ) {
			$html .= '<p class="nyasah__bio" dir="auto">' . esc_html( wp_html_excerpt( $profile['bio'], 220, '…' ) ) . '</p>';
		}
		$html .= '</div>';
		if ( $s['show_follow'] && '' !== $url ) {
			$html .= self::follow( $s, $url, true );
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Follow button.
	 *
	 * @param array  $s     Settings.
	 * @param string $url   Profile address.
	 * @param bool   $small In the header.
	 * @return string
	 */
	private static function follow( $s, $url, $small = false ) {
		$net  = NYASAH_Networks::get( $s['network'] );
		/* translators: %s: network name */
		$text = '' !== $s['follow_text'] ? $s['follow_text'] : sprintf( __( 'Follow on %s', 'nyasah-social-feed' ), $net['label'] );
		return '<a class="nyasah__follow' . ( $small ? ' nyasah__follow--head' : '' ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow">' . wp_kses( NYASAH_Networks::icon( $s['network'], 16 ), NYASAH_Networks::svg_tags() ) . '<span>' . esc_html( $text ) . '</span></a>';
	}

	/**
	 * Posts.
	 *
	 * @param array $s     Settings.
	 * @param array $items Items.
	 * @return string
	 */
	public static function items( $s, $items ) {
		$html = '';
		foreach ( $items as $item ) {
			$html .= self::item( $s, $item );
		}
		return $html;
	}

	/**
	 * One post.
	 *
	 * @param array $s    Settings.
	 * @param array $item Item.
	 * @return string
	 */
	private static function item( $s, $item ) {
		$has_media = '' !== $item['image'];
		$is_embed  = 'embed' === $item['type'] && ! $has_media && '' !== $item['embed'];
		$label     = '' !== $item['title'] ? $item['title'] : wp_html_excerpt( (string) $item['text'], 100, '…' );
		$popup     = array(
			'type'  => $item['type'],
			'image' => $item['image'],
			'video' => $item['video'],
			'embed' => $item['embed'],
			'url'   => $item['url'],
			'title' => $item['title'],
			'text'  => wp_html_excerpt( (string) $item['text'], 2000, '…' ),
			'date'  => $item['date'] ? wp_date( get_option( 'date_format' ), (int) $item['date'] ) : '',
			'net'   => $item['network'],
			'more'  => self::view_on( $item['network'] ),
			'pics'  => array_slice( (array) $item['gallery'], 0, 10 ),
		);
		$classes   = 'nyasah-item nyasah-item--' . sanitize_html_class( $item['type'] ) . ( $has_media || $is_embed ? '' : ' nyasah-item--nomedia' );
		$html      = '<article class="' . esc_attr( $classes ) . '" role="listitem">';

		if ( $is_embed ) {
			$html .= '<div class="nyasah-item__embed"><iframe src="' . esc_url( $item['embed'] ) . '" title="' . esc_attr( self::view_on( $item['network'] ) ) . '" loading="lazy" allow="encrypted-media; picture-in-picture; fullscreen" referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
		} elseif ( $has_media ) {
			$attrs = 'class="nyasah-item__media" href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener nofollow"';
			if ( 'popup' === $s['click'] ) {
				$attrs .= ' data-nyasah-popup="' . esc_attr( wp_json_encode( $popup ) ) . '"';
			}
			$html .= '<a ' . $attrs . '>';
			$html .= '<img src="' . esc_url( $item['image'] ) . '" alt="' . esc_attr( $label ) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer" />';
			if ( 'video' === $item['type'] ) {
				$html .= '<span class="nyasah-item__play" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M8 5v14l11-7z"/></svg></span>';
			} elseif ( 'gallery' === $item['type'] ) {
				$html .= '<span class="nyasah-item__multi" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M7 3h11a3 3 0 0 1 3 3v11h-2V6a1 1 0 0 0-1-1H7z"/><rect x="3" y="7" width="14" height="14" rx="2" fill="currentColor"/></svg></span>';
			}
			if ( $s['show_icon'] ) {
				$html .= '<span class="nyasah-item__net">' . wp_kses( NYASAH_Networks::icon( $item['network'], 18 ), NYASAH_Networks::svg_tags() ) . '</span>';
			}
			$html .= '</a>';
		}

		if ( ! $is_embed ) {
			$html .= self::body( $s, $item, $has_media, $popup );
		}
		$html .= '</article>';
		return $html;
	}

	/**
	 * Text part of a post.
	 *
	 * @param array $s         Settings.
	 * @param array $item      Item.
	 * @param bool  $has_media The post has a picture.
	 * @param array $popup     Popup data.
	 * @return string
	 */
	private static function body( $s, $item, $has_media, $popup ) {
		$parts = '';
		if ( $s['show_author'] && ! empty( $item['author']['name'] ) ) {
			$parts .= '<div class="nyasah-item__author" dir="auto">' . esc_html( $item['author']['name'] ) . '</div>';
		}
		if ( ! $has_media && $s['show_icon'] ) {
			$parts .= '<span class="nyasah-item__net nyasah-item__net--inline">' . wp_kses( NYASAH_Networks::icon( $item['network'], 18 ), NYASAH_Networks::svg_tags() ) . '</span>';
		}
		if ( '' !== $item['title'] ) {
			$parts .= '<h3 class="nyasah-item__title" dir="auto">' . esc_html( wp_html_excerpt( $item['title'], 140, '…' ) ) . '</h3>';
		}
		$length = (int) $s['caption_length'];
		if ( $s['show_caption'] && '' !== trim( (string) $item['text'] ) && $length > 0 && ( '' === $item['title'] || ! $has_media || 'list' === $s['layout'] ) ) {
			$parts .= '<p class="nyasah-item__text" dir="auto">' . nl2br( esc_html( wp_html_excerpt( (string) $item['text'], $length, '…' ) ) ) . '</p>';
		}
		$meta = array();
		if ( $s['show_date'] && $item['date'] ) {
			$meta[] = '<time datetime="' . esc_attr( gmdate( 'c', (int) $item['date'] ) ) . '">' . esc_html( wp_date( get_option( 'date_format' ), (int) $item['date'] ) ) . '</time>';
		}
		if ( $s['show_stats'] ) {
			$icons = array(
				'views'    => 'M12 5C6.5 5 2.7 9.2 1.5 12c1.2 2.8 5 7 10.5 7s9.3-4.2 10.5-7C21.3 9.2 17.5 5 12 5zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z',
				'likes'    => 'M12 21s-7.5-4.6-9.6-9.3C.9 8.3 3 4.5 6.8 4.5c2.1 0 3.5 1.1 4.2 2.3.7-1.2 2.1-2.3 4.2-2.3 3.8 0 5.9 3.8 4.4 7.2C19.5 16.4 12 21 12 21z',
				'comments' => 'M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
			);
			$names = array(
				'views'    => __( 'Views', 'nyasah-social-feed' ),
				'likes'    => __( 'Likes', 'nyasah-social-feed' ),
				'comments' => __( 'Comments', 'nyasah-social-feed' ),
			);
			foreach ( $icons as $key => $d ) {
				if ( isset( $item['stats'][ $key ] ) && null !== $item['stats'][ $key ] ) {
					$meta[] = '<span class="nyasah-item__stat" title="' . esc_attr( $names[ $key ] ) . '"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="' . esc_attr( $d ) . '"/></svg><span class="screen-reader-text">' . esc_html( $names[ $key ] ) . ': </span>' . esc_html( self::number( (int) $item['stats'][ $key ] ) ) . '</span>';
				}
			}
		}
		if ( $meta ) {
			$parts .= '<div class="nyasah-item__meta">' . implode( '', $meta ) . '</div>';
		}
		if ( ! $has_media && '' !== $item['url'] ) {
			$attrs = '';
			if ( 'popup' === $s['click'] && '' !== $item['video'] ) {
				$attrs = ' data-nyasah-popup="' . esc_attr( wp_json_encode( $popup ) ) . '"';
			}
			$parts .= '<a class="nyasah-item__link" href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener nofollow"' . $attrs . '>' . esc_html( self::view_on( $item['network'] ) ) . '</a>';
		}
		if ( '' === $parts ) {
			return '';
		}
		return '<div class="nyasah-item__body">' . $parts . '</div>';
	}

	/**
	 * "View on Instagram" and so on.
	 *
	 * @param string $network Network.
	 * @return string
	 */
	public static function view_on( $network ) {
		$net = NYASAH_Networks::get( $network );
		/* translators: %s: network name */
		return sprintf( __( 'View on %s', 'nyasah-social-feed' ), $net ? $net['label'] : '' );
	}

	/**
	 * Short numbers: 1,234 / 12.3K / 4.5M in the site's language.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	public static function number( $n ) {
		if ( $n >= 1000000 ) {
			/* translators: %s: number of millions */
			return sprintf( __( '%sM', 'nyasah-social-feed' ), number_format_i18n( $n / 1000000, $n >= 10000000 ? 0 : 1 ) );
		}
		if ( $n >= 10000 ) {
			/* translators: %s: number of thousands */
			return sprintf( __( '%sK', 'nyasah-social-feed' ), number_format_i18n( $n / 1000, $n >= 100000 ? 0 : 1 ) );
		}
		return number_format_i18n( $n );
	}
}
