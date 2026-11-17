<?php
/**
 * WebP images: a WebP copy is made next to every JPEG and PNG upload (and each of its sizes), and pages
 * offer it to browsers that support it with <picture>, so cached pages work for every browser.
 * Existing images are converted in the background. Originals are never changed.
 *
 * @package TransaraPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * WebP.
 */
class TRP_Webp {

	const QUEUE = 'trp_webp_queue';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ) );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 20, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
		add_filter( 'transara_buffer', array( __CLASS__, 'rewrite' ), 40 );
		add_action( 'trp_webp_batch', array( __CLASS__, 'batch' ) );
		add_action( 'transara_tools_cards', array( __CLASS__, 'card' ) );
		add_action( 'admin_post_trp_webp_all', array( __CLASS__, 'handle_all' ) );
	}

	/**
	 * Whether this server can write WebP.
	 *
	 * @return bool
	 */
	public static function supported() {
		return wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	}

	/**
	 * Settings in Transara › Speed.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		return Transara_Settings::insert_sections(
			$schema,
			'speed',
			array(
				'trp_webp' => array(
					'title'       => __( 'WebP images (Pro)', 'transara-pro' ),
					'description' => __( 'WebP images are usually 25–35% smaller than JPEG and PNG. Originals stay as they are, so turning this off changes nothing.', 'transara-pro' ),
					'fields'      => array(
						'trp_webp_make'    => array(
							'type'    => 'checkbox',
							'label'   => __( 'Make a WebP copy of new uploads', 'transara-pro' ),
							'default' => 1,
						),
						'trp_webp_serve'   => array(
							'type'        => 'checkbox',
							'label'       => __( 'Show WebP to browsers that support it', 'transara-pro' ),
							'description' => __( 'Images are wrapped in <picture>, so older browsers still get the original.', 'transara-pro' ),
							'default'     => 1,
						),
						'trp_webp_quality' => array(
							'type'    => 'number',
							'label'   => __( 'Quality', 'transara-pro' ),
							'unit'    => '(50–100)',
							'min'     => 50,
							'max'     => 100,
							'default' => 80,
						),
					),
				),
			)
		);
	}

	/**
	 * Files of an attachment (original and sizes).
	 *
	 * @param int        $id   Attachment.
	 * @param array|null $meta Metadata.
	 * @return string[]
	 */
	public static function files( $id, $meta = null ) {
		$file = get_attached_file( $id );
		if ( ! $file || ! preg_match( '/\.(jpe?g|png)$/i', $file ) ) {
			return array();
		}
		$meta  = is_array( $meta ) ? $meta : wp_get_attachment_metadata( $id );
		$dir   = trailingslashit( dirname( $file ) );
		$files = array( $file );
		if ( ! empty( $meta['original_image'] ) ) {
			$files[] = $dir . $meta['original_image'];
		}
		foreach ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $dir . $size['file'];
			}
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * Make the WebP copy of one file (file.jpg → file.jpg.webp).
	 *
	 * @param string $file File.
	 * @return bool
	 */
	public static function convert( $file ) {
		$dest = $file . '.webp';
		if ( ! is_file( $file ) || ( is_file( $dest ) && filemtime( $dest ) >= filemtime( $file ) ) ) {
			return is_file( $dest );
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return false;
		}
		$editor->set_quality( max( 50, min( 100, (int) Transara_Settings::get( 'trp_webp_quality' ) ) ) );
		$saved = $editor->save( $dest, 'image/webp' );
		if ( is_wp_error( $saved ) || ! is_file( $dest ) ) {
			return false;
		}
		// Keep the WebP only when it is smaller.
		if ( filesize( $dest ) >= filesize( $file ) ) {
			wp_delete_file( $dest );
			return false;
		}
		return true;
	}

	/**
	 * New upload.
	 *
	 * @param array $meta Metadata.
	 * @param int   $id   Attachment.
	 * @return array
	 */
	public static function on_upload( $meta, $id ) {
		if ( Transara_Settings::on( 'trp_webp_make' ) && self::supported() ) {
			foreach ( self::files( $id, $meta ) as $file ) {
				self::convert( $file );
			}
		}
		return $meta;
	}

	/**
	 * Attachment deleted: remove its WebP copies.
	 *
	 * @param int $id Attachment.
	 */
	public static function on_delete( $id ) {
		foreach ( self::files( $id ) as $file ) {
			if ( is_file( $file . '.webp' ) ) {
				wp_delete_file( $file . '.webp' );
			}
		}
	}

	/*
	 * Existing images.
	 */

	/**
	 * Start converting every existing image.
	 */
	public static function handle_all() {
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara-pro' ), 403 );
		}
		check_admin_referer( 'trp_webp_all' );
		if ( ! self::supported() ) {
			Transara_Admin::flash( __( 'This server cannot make WebP images (GD or Imagick with WebP support is needed). Ask your host to enable it.', 'transara-pro' ), 'error' );
			Transara_Admin::back( 'transara-tools' );
		}
		update_option(
			self::QUEUE,
			array(
				'last'  => 0,
				'done'  => 0,
				'made'  => 0,
				'total' => self::count(),
			),
			false
		);
		wp_schedule_single_event( time(), 'trp_webp_batch' );
		Transara_Admin::flash( __( 'Converting your images in the background. You can leave this page.', 'transara-pro' ) );
		Transara_Admin::back( 'transara-tools' );
	}

	/**
	 * JPEG and PNG images in the media library.
	 *
	 * @return int
	 */
	private static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (%s, %s)", 'image/jpeg', 'image/png' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One count when the bulk conversion starts.
	}

	/**
	 * Convert the next 15 images.
	 */
	public static function batch() {
		$q = get_option( self::QUEUE );
		if ( ! is_array( $q ) || ! self::supported() ) {
			return;
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (%s, %s) AND ID > %d ORDER BY ID LIMIT 15", 'image/jpeg', 'image/png', (int) $q['last'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Batches of the media library.
		if ( ! $ids ) {
			$q['finished'] = time();
			update_option( self::QUEUE, $q, false );
			return;
		}
		foreach ( $ids as $id ) {
			foreach ( self::files( (int) $id ) as $file ) {
				$q['made'] += self::convert( $file ) ? 1 : 0;
			}
			$q['last'] = (int) $id;
			++$q['done'];
		}
		update_option( self::QUEUE, $q, false );
		wp_schedule_single_event( time() + 5, 'trp_webp_batch' );
	}

	/**
	 * Card in Transara › Tools.
	 */
	public static function card() {
		$q = get_option( self::QUEUE );
		echo '<section class="transara-card"><h2>' . esc_html__( 'WebP images', 'transara-pro' ) . '</h2>';
		if ( ! self::supported() ) {
			echo '<p class="transara-warn">' . esc_html__( 'This server cannot make WebP images. Ask your host to enable WebP in GD or Imagick.', 'transara-pro' ) . '</p></section>';
			return;
		}
		if ( is_array( $q ) ) {
			/* translators: 1: images done, 2: total, 3: WebP files made. */
			echo '<p>' . esc_html( sprintf( __( '%1$d of %2$d images checked, %3$d WebP files made.', 'transara-pro' ), (int) $q['done'], (int) $q['total'], (int) $q['made'] ) ) . ( empty( $q['finished'] ) ? ' ' . esc_html__( 'Still working…', 'transara-pro' ) : '' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'New uploads get a WebP copy automatically. Convert the images already in your media library once.', 'transara-pro' ) . '</p>';
		}
		echo '<p>' . Transara_Admin::action_button( 'trp_webp_all', __( 'Convert existing images', 'transara-pro' ), 'secondary' ) . '</p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
	}

	/*
	 * Pages.
	 */

	/**
	 * WebP address of an uploads image, when the copy exists.
	 *
	 * @param string $url Image address.
	 * @return string
	 */
	private static function webp_url( $url ) {
		static $base = null;
		if ( null === $base ) {
			$up   = wp_get_upload_dir();
			$base = array( set_url_scheme( $up['baseurl'], 'https' ), set_url_scheme( $up['baseurl'], 'http' ), $up['basedir'] );
		}
		$clean = strtok( html_entity_decode( $url ), '?' );
		if ( ! preg_match( '/\.(jpe?g|png)$/i', $clean ) ) {
			return '';
		}
		foreach ( array( $base[0], $base[1] ) as $prefix ) {
			if ( 0 === strpos( $clean, $prefix ) ) {
				$file = $base[2] . substr( $clean, strlen( $prefix ) );
				return is_file( $file . '.webp' ) ? $url . '.webp' : '';
			}
		}
		return '';
	}

	/**
	 * Wrap images that have WebP copies in <picture>.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function rewrite( $html ) {
		if ( ! Transara_Settings::on( 'trp_webp_serve' ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}
		// Leave images already inside <picture> alone.
		$parts = preg_split( '#(<picture\b.*?</picture>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( 0 === stripos( $part, '<picture' ) ) {
				continue;
			}
			$parts[ $i ] = preg_replace_callback(
				'#<img\b[^>]*>#i',
				static function ( $m ) {
					$tag = $m[0];
					if ( ! preg_match( '#\ssrc=(["\'])([^"\']+)\1#i', $tag, $src ) ) {
						return $tag;
					}
					$webp = self::webp_url( $src[2] );
					if ( '' === $webp ) {
						return $tag;
					}
					$srcset = $webp;
					if ( preg_match( '#\ssrcset=(["\'])([^"\']+)\1#i', $tag, $set ) ) {
						$items = array();
						foreach ( explode( ',', $set[2] ) as $item ) {
							$bits = preg_split( '/\s+/', trim( $item ), 2 );
							$w    = self::webp_url( $bits[0] );
							if ( '' === $w ) {
								return '<picture><source type="image/webp" srcset="' . esc_attr( $webp ) . '">' . $tag . '</picture>';
							}
							$items[] = $w . ( isset( $bits[1] ) ? ' ' . $bits[1] : '' );
						}
						$srcset = implode( ', ', $items );
					}
					$sizes = preg_match( '#\ssizes=(["\'])([^"\']+)\1#i', $tag, $sz ) ? ' sizes="' . esc_attr( $sz[2] ) . '"' : '';
					return '<picture><source type="image/webp" srcset="' . esc_attr( $srcset ) . '"' . $sizes . '>' . $tag . '</picture>';
				},
				$part
			);
		}
		$html = implode( '', $parts );
		// The main image's preload (Transara › Speed) asks for the WebP too, so it is not downloaded twice.
		return (string) preg_replace_callback(
			'#<link\b[^>]*\brel=(["\'])preload\1[^>]*\bas=(["\'])image\2[^>]*>#i',
			static function ( $m ) {
				$tag = $m[0];
				if ( false !== stripos( $tag, 'type=' ) || ! preg_match( '#\shref=(["\'])([^"\']+)\1#i', $tag, $href ) ) {
					return $tag;
				}
				$webp = self::webp_url( $href[2] );
				if ( '' === $webp ) {
					return $tag;
				}
				if ( preg_match( '#\simagesrcset=(["\'])([^"\']+)\1#i', $tag, $set ) ) {
					$items = array();
					foreach ( explode( ',', $set[2] ) as $item ) {
						$bits = preg_split( '/\s+/', trim( $item ), 2 );
						$w    = self::webp_url( $bits[0] );
						if ( '' === $w ) {
							return $tag;
						}
						$items[] = $w . ( isset( $bits[1] ) ? ' ' . $bits[1] : '' );
					}
					$tag = str_replace( $set[0], ' imagesrcset="' . esc_attr( implode( ', ', $items ) ) . '"', $tag );
				}
				$tag = str_replace( $href[0], ' href="' . esc_attr( $webp ) . '" type="image/webp"', $tag );
				return $tag;
			},
			$html
		);
	}
}
