<?php
/**
 * SEO check for any page, post, product or product category — reads Yoast, Rank Math, All in One SEO and SEOPress when present.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page checks.
 */
class LGPI_SEO {

	/**
	 * Which SEO plugin is active (label) or ''.
	 *
	 * @return string
	 */
	public static function seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'Rank Math';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'SEOPress';
		}
		return '';
	}

	/**
	 * SEO title, description and focus keyword set in the SEO plugin.
	 *
	 * @param int $id Post ID.
	 * @return array [title, description, focus]
	 */
	private static function meta( $id ) {
		$title = '';
		$desc  = '';
		$focus = '';
		if ( defined( 'WPSEO_VERSION' ) ) {
			$title = (string) get_post_meta( $id, '_yoast_wpseo_title', true );
			$desc  = (string) get_post_meta( $id, '_yoast_wpseo_metadesc', true );
			$focus = (string) get_post_meta( $id, '_yoast_wpseo_focuskw', true );
			if ( function_exists( 'wpseo_replace_vars' ) ) {
				$post  = get_post( $id );
				$title = $title ? wpseo_replace_vars( $title, $post ) : '';
				$desc  = $desc ? wpseo_replace_vars( $desc, $post ) : '';
			}
		} elseif ( defined( 'RANK_MATH_VERSION' ) ) {
			$title = (string) get_post_meta( $id, 'rank_math_title', true );
			$desc  = (string) get_post_meta( $id, 'rank_math_description', true );
			$focus = (string) strtok( (string) get_post_meta( $id, 'rank_math_focus_keyword', true ), ',' );
		} elseif ( defined( 'AIOSEO_VERSION' ) ) {
			global $wpdb;
			$t   = $wpdb->prefix . 'aioseo_posts';
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT title, description, keyphrases FROM %i WHERE post_id = %d', $t, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $row ) {
				$title = (string) $row->title;
				$desc  = (string) $row->description;
				$k     = json_decode( (string) $row->keyphrases, true );
				$focus = (string) ( $k['focus']['keyphrase'] ?? '' );
			}
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$title = (string) get_post_meta( $id, '_seopress_titles_title', true );
			$desc  = (string) get_post_meta( $id, '_seopress_titles_desc', true );
			$focus = (string) strtok( (string) get_post_meta( $id, '_seopress_analysis_target_kw', true ), ',' );
		}
		// Template variables we can't expand count as "not set by hand".
		if ( preg_match( '/%%|%[a-z_]+%|\{\{/', $title ) ) {
			$title = '';
		}
		if ( preg_match( '/%%|%[a-z_]+%|\{\{/', $desc ) ) {
			$desc = '';
		}
		return array( trim( wp_strip_all_tags( $title ) ), trim( wp_strip_all_tags( $desc ) ), trim( $focus ) );
	}

	/**
	 * Main content as HTML (Elementor pages included).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function content_html( $post ) {
		$html = (string) $post->post_content;
		$el   = get_post_meta( $post->ID, '_elementor_data', true );
		if ( $el && strlen( wp_strip_all_tags( $html ) ) < 200 ) {
			// Elementor keeps the text in its own data: collect the text-like fields.
			$data = is_string( $el ) ? json_decode( $el, true ) : $el;
			$bits = array();
			array_walk_recursive(
				$data,
				function ( $v, $k ) use ( &$bits ) {
					if ( is_string( $v ) && in_array( $k, array( 'title', 'editor', 'text', 'description_text', 'title_text', 'html', 'testimonial_content', 'tab_content', 'item_description' ), true ) ) {
						$bits[] = $v;
					}
				}
			);
			$html .= ' ' . implode( ' ', $bits );
		}
		return strip_shortcodes( $html );
	}

	/**
	 * Check one post / page / product.
	 *
	 * @param int $id Post ID.
	 * @return array|null
	 */
	public static function check_post( $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		list( $seo_title, $desc, $focus ) = self::meta( $id );
		$title                            = $seo_title ? $seo_title : get_the_title( $id );
		$html                             = self::content_html( $post );
		$text                             = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) );
		$words                            = $text ? count( preg_split( '/\s+/u', $text ) ) : 0;
		$imgs                             = preg_match_all( '/<img\s[^>]*>/i', $html, $m );
		$no_alt                           = 0;
		foreach ( $m[0] as $img ) {
			if ( ! preg_match( '/\salt\s*=\s*"[^"]+"/i', $img ) && ! preg_match( "/\\salt\\s*=\\s*'[^']+'/i", $img ) ) {
				++$no_alt;
			}
		}
		$videos   = preg_match_all( '/<video\b|<iframe[^>]+(youtube|vimeo|wistia|tiktok|instagram)|wp:embed|\[video|youtube\.com\/watch|youtu\.be\//i', $html );
		$h1       = preg_match_all( '/<h1\b|wp:heading \{"level":1/i', $html );
		$h2       = preg_match_all( '/<h2\b|wp:heading(?! \{"level":[13456])/i', $html );
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal = preg_match_all( '#<a\s[^>]*href=["\'](/[^/"\']|https?://(www\.)?' . preg_quote( (string) $host, '#' ) . ')#i', $html );
		$r        = array(
			'id'          => $id,
			'type'        => $post->post_type,
			'url'         => get_permalink( $id ),
			'title'       => $title,
			'title_len'   => mb_strlen( $title ),
			'description' => $desc ? $desc : ( $post->post_excerpt ? wp_strip_all_tags( $post->post_excerpt ) : '' ),
			'desc_source' => $desc ? 'seo' : ( $post->post_excerpt ? 'excerpt' : '' ),
			'focus'       => $focus,
			'words'       => $words,
			'images'      => (int) $imgs,
			'img_no_alt'  => $no_alt,
			'videos'      => (int) $videos,
			'h1'          => (int) $h1,
			'h2'          => (int) $h2,
			'internal'    => (int) $internal,
			'featured'    => has_post_thumbnail( $id ),
			'noindex'     => self::noindex( $id ),
			'modified'    => get_post_modified_time( 'Y-m-d', false, $id ),
		);
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$r['product'] = self::product_facts( wc_get_product( $id ) );
		}
		$r['issues'] = self::issues( $r, $text );
		$r['score']  = self::score( $r['issues'] );
		return $r;
	}

	/**
	 * Product page facts.
	 *
	 * @param WC_Product|false $p Product.
	 * @return array
	 */
	private static function product_facts( $p ) {
		if ( ! $p ) {
			return array();
		}
		$gallery = array_filter( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) );
		$no_alt  = 0;
		$sizes   = array();
		foreach ( $gallery as $img ) {
			if ( '' === trim( (string) get_post_meta( $img, '_wp_attachment_image_alt', true ) ) ) {
				++$no_alt;
			}
			$meta    = wp_get_attachment_metadata( $img );
			$file    = get_attached_file( $img );
			$sizes[] = array(
				'w'   => (int) ( $meta['width'] ?? 0 ),
				'h'   => (int) ( $meta['height'] ?? 0 ),
				'kb'  => $file && file_exists( $file ) ? (int) round( filesize( $file ) / 1024 ) : null,
				'ext' => $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '',
			);
		}
		$short = wp_strip_all_tags( (string) $p->get_short_description() );
		$long  = wp_strip_all_tags( (string) $p->get_description() );
		return array(
			'kind'          => LGPI_Store::kind( $p ),
			'price'         => (float) $p->get_price(),
			'on_sale'       => $p->is_on_sale(),
			'sku'           => (bool) $p->get_sku(),
			'stock'         => $p->get_stock_status(),
			'images'        => count( $gallery ),
			'images_no_alt' => $no_alt,
			'image_sizes'   => $sizes,
			'short_words'   => $short ? count( preg_split( '/\s+/u', trim( $short ) ) ) : 0,
			'desc_words'    => $long ? count( preg_split( '/\s+/u', trim( $long ) ) ) : 0,
			'reviews'       => (int) $p->get_review_count(),
			'rating'        => (float) $p->get_average_rating(),
			'categories'    => count( $p->get_category_ids() ),
			'attributes'    => count( $p->get_attributes() ),
			'weight'        => (bool) $p->get_weight(),
		);
	}

	/**
	 * Is the page hidden from Google?
	 *
	 * @param int $id Post.
	 * @return bool
	 */
	private static function noindex( $id ) {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return true;
		}
		if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}
		$rm = get_post_meta( $id, 'rank_math_robots', true );
		return is_array( $rm ) && in_array( 'noindex', $rm, true );
	}

	/**
	 * Problems and tips: [level, text].
	 *
	 * @param array  $r    Facts.
	 * @param string $text Plain text.
	 * @return array[]
	 */
	private static function issues( $r, $text ) {
		$i = array();
		$p = $r['product'] ?? null;
		if ( $r['noindex'] ) {
			$i[] = array( 'high', __( 'Hidden from search engines (noindex). Remove it if this page should get Google visitors.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		if ( $r['title_len'] < 20 ) {
			$i[] = array( 'medium', __( 'The SEO title is very short — add the main keyword and a reason to click.', 'laghavi-performance-insight-for-woocommerce' ) );
		} elseif ( $r['title_len'] > 62 ) {
			$i[] = array( 'low', __( 'The SEO title is long — Google shows about 60 characters.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		if ( '' === $r['description'] ) {
			$i[] = array( 'medium', __( 'No meta description — Google will pick random text for the search result.', 'laghavi-performance-insight-for-woocommerce' ) );
		} elseif ( mb_strlen( $r['description'] ) > 165 ) {
			$i[] = array( 'low', __( 'The meta description is long — keep it under about 155 characters.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		if ( $r['focus'] && false === mb_stripos( $r['title'], $r['focus'] ) ) {
			/* translators: %s: focus keyword */
			$i[] = array( 'medium', sprintf( __( 'The focus keyword “%s” is not in the SEO title.', 'laghavi-performance-insight-for-woocommerce' ), $r['focus'] ) );
		}
		if ( $r['focus'] && false === mb_stripos( mb_substr( $text, 0, 600 ), $r['focus'] ) ) {
			/* translators: %s: focus keyword */
			$i[] = array( 'low', sprintf( __( 'Use “%s” in the first paragraph.', 'laghavi-performance-insight-for-woocommerce' ), $r['focus'] ) );
		}
		if ( $r['img_no_alt'] ) {
			/* translators: %d: number of images */
			$i[] = array( 'low', sprintf( _n( '%d image in the content has no alt text.', '%d images in the content have no alt text.', $r['img_no_alt'], 'laghavi-performance-insight-for-woocommerce' ), $r['img_no_alt'] ) );
		}
		if ( $r['h1'] > 1 ) {
			$i[] = array( 'low', __( 'More than one H1 heading in the content — the page title is usually the only H1.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		if ( ! $r['featured'] ) {
			$i[] = array( 'low', __( 'No featured image — shared links and some themes look empty.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		if ( $p ) {
			if ( $p['desc_words'] < 120 ) {
				/* translators: %d: words */
				$i[] = array( 'medium', sprintf( __( 'Short product description (%d words). Explain who it is for, the benefits, sizes / specs and answer common questions.', 'laghavi-performance-insight-for-woocommerce' ), $p['desc_words'] ) );
			}
			if ( ! $p['short_words'] ) {
				$i[] = array( 'low', __( 'No short description — the text next to the price that helps people decide.', 'laghavi-performance-insight-for-woocommerce' ) );
			}
			if ( $p['images'] < 3 ) {
				/* translators: %d: images */
				$i[] = array( 'medium', sprintf( __( 'Only %d product image(s). Show the product from several angles, in use, and with size reference.', 'laghavi-performance-insight-for-woocommerce' ), $p['images'] ) );
			}
			if ( $p['images_no_alt'] ) {
				/* translators: %d: images */
				$i[] = array( 'low', sprintf( __( '%d product image(s) without alt text — describe them (helps Google Images).', 'laghavi-performance-insight-for-woocommerce' ), $p['images_no_alt'] ) );
			}
			$heavy = count( array_filter( $p['image_sizes'], fn( $s ) => ( $s['kb'] ?? 0 ) > 400 ) );
			if ( $heavy ) {
				/* translators: %d: images */
				$i[] = array( 'low', sprintf( __( '%d product image(s) are over 400 KB — compress them or use WebP so the page loads faster.', 'laghavi-performance-insight-for-woocommerce' ), $heavy ) );
			}
			if ( ! $p['reviews'] ) {
				$i[] = array( 'medium', __( 'No reviews yet — ask buyers for a review; reviews raise trust and conversion.', 'laghavi-performance-insight-for-woocommerce' ) );
			}
			if ( ! $r['videos'] ) {
				$i[] = array( 'low', __( 'No video — a short video of the product in use often lifts sales.', 'laghavi-performance-insight-for-woocommerce' ) );
			}
			if ( ! $p['sku'] ) {
				$i[] = array( 'low', __( 'No SKU — Google Shopping and Meta catalogs work better with one.', 'laghavi-performance-insight-for-woocommerce' ) );
			}
			if ( 'outofstock' === $p['stock'] ) {
				$i[] = array( 'high', __( 'Out of stock — visitors can’t buy. Restock, or suggest alternatives on the page.', 'laghavi-performance-insight-for-woocommerce' ) );
			}
		} elseif ( $r['words'] < 300 && ! in_array( $r['type'], array( 'attachment' ), true ) ) {
			/* translators: %d: words */
			$i[] = array( 'medium', sprintf( __( 'Thin content (%d words). Pages that rank usually answer the visitor’s question fully.', 'laghavi-performance-insight-for-woocommerce' ), $r['words'] ) );
		}
		if ( ! $r['internal'] ) {
			$i[] = array( 'low', __( 'No links to other pages of your site in the content.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		return $i;
	}

	/**
	 * 0–100.
	 *
	 * @param array[] $issues Issues.
	 * @return int
	 */
	private static function score( $issues ) {
		$s = 100;
		foreach ( $issues as $i ) {
			$s -= array(
				'high'   => 25,
				'medium' => 10,
				'low'    => 4,
			)[ $i[0] ];
		}
		return max( 0, $s );
	}

	/**
	 * Check a product category / post category page.
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy.
	 * @return array|null
	 */
	public static function check_term( $term_id, $taxonomy ) {
		$t = get_term( $term_id, $taxonomy );
		if ( ! $t || is_wp_error( $t ) ) {
			return null;
		}
		$desc   = wp_strip_all_tags( (string) $t->description );
		$words  = $desc ? count( preg_split( '/\s+/u', trim( $desc ) ) ) : 0;
		$issues = array();
		if ( $words < 50 ) {
			/* translators: %d: words */
			$issues[] = array( 'medium', sprintf( __( 'Category description is short (%d words). A helpful intro with the main keyword helps the category rank.', 'laghavi-performance-insight-for-woocommerce' ), $words ) );
		}
		if ( ! $t->count ) {
			$issues[] = array( 'medium', __( 'Empty category — hide it or add products.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		if ( 'product_cat' === $taxonomy && ! get_term_meta( $term_id, 'thumbnail_id', true ) ) {
			$issues[] = array( 'low', __( 'No category image.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		return array(
			'id'     => $term_id,
			'type'   => $taxonomy,
			'url'    => get_term_link( $t ),
			'title'  => $t->name,
			'words'  => $words,
			'count'  => $t->count,
			'issues' => $issues,
			'score'  => self::score( $issues ),
		);
	}
}
