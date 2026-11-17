<?php
/**
 * Images and videos: lazy loading for images and iframes, YouTube previews that load the player on
 * click, missing width and height added, and the main image of the page loaded first.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Media.
 */
class Transara_Media {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ) );
		add_filter( 'transara_buffer', array( __CLASS__, 'process' ), 30 );
		add_action( 'init', array( __CLASS__, 'setup' ) );
	}

	/**
	 * Settings.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		return Transara_Settings::insert_sections(
			$schema,
			'speed',
			array(
				'media' => array(
					'title'       => __( 'Images and videos', 'transara' ),
					'description' => __( 'Load images and videos only when they come into view, keep the layout from jumping, and show the main image first.', 'transara' ),
					'fields'      => array(
						'lazy_images'     => array(
							'type'    => 'checkbox',
							'label'   => __( 'Lazy load images', 'transara' ),
							'default' => 1,
						),
						'lazy_skip'       => array(
							'type'        => 'number',
							'label'       => __( 'Load the first images at once', 'transara' ),
							'unit'        => __( 'images (the top of the page)', 'transara' ),
							'min'         => 0,
							'max'         => 20,
							'default'     => 2,
						),
						'lazy_iframes'    => array(
							'type'    => 'checkbox',
							'label'   => __( 'Lazy load iframes and videos', 'transara' ),
							'default' => 1,
						),
						'youtube_preview' => array(
							'type'        => 'checkbox',
							'label'       => __( 'Show a YouTube preview image until the visitor clicks play', 'transara' ),
							'description' => __( 'The YouTube player (about 1 MB) loads only when a visitor wants to watch.', 'transara' ),
							'default'     => 0,
						),
						'lazy_exclude'    => array(
							'type'        => 'lines',
							'label'       => __( 'Never lazy load', 'transara' ),
							'description' => __( 'Part of an image address or class name, one per line, for example logo or hero.', 'transara' ),
							'default'     => array(),
						),
						'image_dimensions' => array(
							'type'        => 'checkbox',
							'label'       => __( 'Add missing image width and height', 'transara' ),
							'description' => __( 'Stops the page from jumping while images load (better CLS).', 'transara' ),
							'default'     => 1,
						),
						'lcp_preload'     => array(
							'type'        => 'checkbox',
							'label'       => __( 'Load the main image first', 'transara' ),
							'description' => __( 'The first large image of the page is preloaded with high priority (better LCP).', 'transara' ),
							'default'     => 1,
						),
					),
				),
			),
			'preload'
		);
	}

	/**
	 * Assets for YouTube previews.
	 */
	public static function setup() {
		if ( Transara_Settings::on( 'youtube_preview' ) ) {
			add_action(
				'wp_enqueue_scripts',
				static function () {
					wp_enqueue_style( 'transara-youtube', TRANSARA_URL . 'assets/css/youtube.css', array(), TRANSARA_VERSION );
					wp_enqueue_script( 'transara-youtube', TRANSARA_URL . 'assets/js/youtube.js', array(), TRANSARA_VERSION, array( 'in_footer' => true ) );
				}
			);
		}
		if ( Transara_Settings::on( 'lazy_images' ) ) {
			// Transara decides which images load at once; WordPress's own guess would compete with it.
			add_filter( 'wp_img_tag_add_loading_optimization_attrs', '__return_false' );
		}
	}

	/**
	 * Change images and iframes.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function process( $html ) {
		$lazy    = Transara_Settings::on( 'lazy_images' );
		$iframes = Transara_Settings::on( 'lazy_iframes' );
		$yt      = Transara_Settings::on( 'youtube_preview' );
		$dims    = Transara_Settings::on( 'image_dimensions' );
		$lcp     = Transara_Settings::on( 'lcp_preload' );
		if ( ! $lazy && ! $iframes && ! $yt && ! $dims && ! $lcp ) {
			return $html;
		}
		$body = stripos( $html, '<body' );
		if ( false === $body ) {
			return $html;
		}
		$head    = substr( $html, 0, $body );
		$content = Transara_Html::protect( substr( $html, $body ) );
		$skip    = (int) Transara_Settings::get( 'lazy_skip' );
		$exclude = Transara_Settings::lines( 'lazy_exclude' );
		$index   = 0;
		$preload = '';
		$has_hi  = (bool) preg_match( '#<img\b[^>]*fetchpriority\s*=\s*["\']?high#i', $content );

		$content = (string) preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( $m ) use ( $lazy, $dims, $lcp, $skip, $exclude, &$index, &$preload, &$has_hi ) {
				$tag = $m[0];
				$src = (string) Transara_Html::attr( $tag, 'src' );
				if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
					return $tag;
				}
				if ( $dims && ( ! Transara_Html::has( $tag, 'width' ) || ! Transara_Html::has( $tag, 'height' ) ) ) {
					$size = self::image_size( $tag, $src );
					if ( $size ) {
						$tag = Transara_Html::set( Transara_Html::set( $tag, 'width', (string) $size[0] ), 'height', (string) $size[1] );
					}
				}
				$excluded = Transara_Html::has( $tag, 'data-no-lazy' ) || Transara_Html::matches( $tag, array_merge( array( 'no-lazy', 'skip-lazy' ), $exclude ) );
				$position = $index++;
				if ( $lcp && ! $has_hi && 0 === $position && ! self::is_small( $tag ) ) {
					$tag    = Transara_Html::set( Transara_Html::remove( $tag, 'loading' ), 'fetchpriority', 'high' );
					$has_hi = true;
				}
				if ( $lcp && '' === $preload && 'high' === strtolower( (string) Transara_Html::attr( $tag, 'fetchpriority' ) ) ) {
					$preload = '<link rel="preload" as="image" href="' . esc_url( $src ) . '"'
						. ( Transara_Html::has( $tag, 'srcset' ) ? ' imagesrcset="' . esc_attr( (string) Transara_Html::attr( $tag, 'srcset' ) ) . '"' : '' )
						. ( Transara_Html::has( $tag, 'sizes' ) ? ' imagesizes="' . esc_attr( (string) Transara_Html::attr( $tag, 'sizes' ) ) . '"' : '' )
						. ' fetchpriority="high" />';
					$tag     = Transara_Html::remove( $tag, 'loading' );
					return $tag;
				}
				if ( ! $lazy || $excluded ) {
					return $tag;
				}
				if ( $position < $skip ) {
					return 'lazy' === Transara_Html::attr( $tag, 'loading' ) ? Transara_Html::remove( $tag, 'loading' ) : $tag;
				}
				if ( ! Transara_Html::has( $tag, 'loading' ) ) {
					$tag = Transara_Html::set( $tag, 'loading', 'lazy' );
				}
				if ( ! Transara_Html::has( $tag, 'decoding' ) ) {
					$tag = Transara_Html::set( $tag, 'decoding', 'async' );
				}
				return $tag;
			},
			$content
		);

		if ( $iframes || $yt ) {
			$content = (string) preg_replace_callback(
				'#<iframe\b[^>]*>\s*</iframe>#i',
				static function ( $m ) use ( $iframes, $yt, $exclude ) {
					$tag = $m[0];
					$src = (string) Transara_Html::attr( $tag, 'src' );
					if ( '' === $src || Transara_Html::matches( $tag, array_merge( array( 'no-lazy', 'skip-lazy' ), $exclude ) ) ) {
						return $tag;
					}
					if ( $yt && preg_match( '#(?:youtube(?:-nocookie)?\.com/embed/)([A-Za-z0-9_\-]{11})#', $src, $v ) ) {
						return self::youtube( $tag, $src, $v[1] );
					}
					if ( $iframes && ! Transara_Html::has( $tag, 'loading' ) ) {
						$tag = Transara_Html::set( $tag, 'loading', 'lazy' );
					}
					return $tag;
				},
				$content
			);
		}
		$content = Transara_Html::restore( $content );
		if ( '' !== $preload ) {
			$head = (string) preg_replace( '#</head>#i', $preload . "\n</head>", $head, 1 );
		}
		return $head . $content;
	}

	/**
	 * Icons, avatars and other small images are never the main image.
	 *
	 * @param string $tag Tag.
	 * @return bool
	 */
	private static function is_small( $tag ) {
		$w = (int) Transara_Html::attr( $tag, 'width' );
		$h = (int) Transara_Html::attr( $tag, 'height' );
		return ( $w && $w < 150 ) || ( $h && $h < 100 ) || (bool) preg_match( '#avatar|logo|icon|emoji|\.svg#i', $tag );
	}

	/**
	 * Width and height of an image.
	 *
	 * @param string $tag Tag.
	 * @param string $src Address.
	 * @return int[]|null
	 */
	private static function image_size( $tag, $src ) {
		if ( preg_match( '#\.svg($|\?)#i', $src ) ) {
			return null;
		}
		// Resized copies carry their size in the name (photo-300x200.jpg).
		if ( preg_match( '#-(\d{2,5})x(\d{2,5})\.(?:jpe?g|png|gif|webp|avif)(?:$|\?)#i', $src, $m ) ) {
			return array( (int) $m[1], (int) $m[2] );
		}
		if ( preg_match( '#wp-image-(\d+)#', $tag, $m ) ) {
			$meta = wp_get_attachment_metadata( (int) $m[1] );
			if ( is_array( $meta ) && ! empty( $meta['width'] ) && basename( (string) $meta['file'] ) === basename( strtok( $src, '?' ) ) ) {
				return array( (int) $meta['width'], (int) $meta['height'] );
			}
		}
		$path = Transara_Html::local_path( $src );
		if ( '' === $path ) {
			return null;
		}
		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not every file is an image.
		return $size ? array( (int) $size[0], (int) $size[1] ) : null;
	}

	/**
	 * A YouTube preview that loads the player on click.
	 *
	 * @param string $tag Iframe.
	 * @param string $src Embed address.
	 * @param string $id  Video ID.
	 * @return string
	 */
	private static function youtube( $tag, $src, $id ) {
		$title = (string) Transara_Html::attr( $tag, 'title' );
		$w     = (int) Transara_Html::attr( $tag, 'width' );
		$h     = (int) Transara_Html::attr( $tag, 'height' );
		$ratio = $w && $h ? $w . ' / ' . $h : '16 / 9';
		$play  = add_query_arg( 'autoplay', '1', html_entity_decode( $src, ENT_QUOTES ) );
		return '<div class="transara-yt" data-src="' . esc_url( $play ) . '" data-title="' . esc_attr( $title ) . '" style="aspect-ratio:' . esc_attr( $ratio ) . ( $w ? ';max-width:' . (int) $w . 'px' : '' ) . '">'
			. '<img src="' . esc_url( 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg' ) . '" alt="' . esc_attr( '' !== $title ? $title : __( 'Video', 'transara' ) ) . '" loading="lazy" decoding="async" width="480" height="360" />'
			. '<button type="button" class="transara-yt__play" aria-label="' . esc_attr__( 'Play video', 'transara' ) . '"></button>'
			. '<noscript>' . $tag . '</noscript></div>';
	}
}
