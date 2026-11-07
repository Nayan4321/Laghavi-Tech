<?php
/**
 * View-only archives: art, photos and files marked "Subscribers only" in the Media Library move to a
 * protected folder. Images are shown through [media_gallery] at screen size only (never the full-resolution
 * file), with the subscriber's email drawn into the image, through links that expire and only work on
 * your own pages; right-click and dragging are off. Audio and video files play through [media_audio] /
 * [media_video provider="file"].
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Galleries and protected files.
 */
class RINNOVOMA_Gallery {

	const TD     = 'rinnovo-media-archives-pro';
	const FOLDER = 'rinnovoma-protected';
	const META   = '_rinnovoma_plan';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_field' ), 10, 2 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'url' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'image_src' ), 20, 3 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'no_srcset' ), 20, 5 );
		add_action( 'init', array( __CLASS__, 'serve_art' ), 20 );
		add_shortcode( 'media_gallery', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Protected folder.
	 *
	 * @return string
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::FOLDER;
	}

	/**
	 * Create it with its "no direct access" files.
	 *
	 * @return bool
	 */
	public static function ensure_dir() {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		foreach ( array(
			'.htaccess'  => "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		) as $name => $body ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Server rule files.
			}
		}
		return true;
	}

	/**
	 * Whether an attachment is protected.
	 *
	 * @param int $id Attachment.
	 * @return bool
	 */
	public static function is_protected( $id ) {
		return '' !== (string) get_post_meta( $id, self::META, true ) && false !== strpos( (string) get_post_meta( $id, '_wp_attached_file', true ), self::FOLDER . '/' );
	}

	/**
	 * Media Library field.
	 *
	 * @param array   $fields Fields.
	 * @param WP_Post $post   Attachment.
	 * @return array
	 */
	public static function field( $fields, $post ) {
		$current = (string) get_post_meta( $post->ID, self::META, true );
		$html    = '<select name="attachments[' . (int) $post->ID . '][rinnovoma_plan]"><option value="">' . esc_html__( '— Public —', self::TD ) . '</option>';
		foreach ( RINNOVOMA_Plans::choices() as $id => $label ) {
			$html .= '<option value="' . esc_attr( $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$fields['rinnovoma_plan'] = array(
			'label' => __( 'Subscribers only', self::TD ),
			'input' => 'html',
			'html'  => $html . '</select>',
			'helps' => __( 'Moves the file to a protected folder. Images are only shown watermarked at screen size; the original is never sent.', self::TD ),
		);
		return $fields;
	}

	/**
	 * Save it, moving the file.
	 *
	 * @param array $post       Attachment.
	 * @param array $attachment Fields.
	 * @return array
	 */
	public static function save_field( $post, $attachment ) {
		if ( ! isset( $attachment['rinnovoma_plan'] ) || ! current_user_can( 'upload_files' ) ) {
			return $post;
		}
		$plan = sanitize_key( $attachment['rinnovoma_plan'] );
		$id   = (int) $post['ID'];
		if ( '' !== $plan && isset( RINNOVOMA_Plans::choices()[ $plan ] ) ) {
			update_post_meta( $id, self::META, $plan );
			self::move( $id, true );
		} else {
			self::move( $id, false );
			delete_post_meta( $id, self::META );
		}
		return $post;
	}

	/**
	 * Move a file and its sizes into or out of the protected folder.
	 *
	 * @param int  $id      Attachment.
	 * @param bool $protect Protect.
	 * @return bool
	 */
	public static function move( $id, $protect ) {
		$file = get_attached_file( $id, true );
		if ( ! $file || ! file_exists( $file ) ) {
			return false;
		}
		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( $uploads['basedir'] );
		$rel     = ltrim( str_replace( $base, '', $file ), '/' );
		$inside  = 0 === strpos( $rel, self::FOLDER . '/' );
		if ( $protect === $inside || ( $protect && ! self::ensure_dir() ) ) {
			return $protect === $inside;
		}
		$new_rel = $protect ? self::FOLDER . '/' . $rel : substr( $rel, strlen( self::FOLDER ) + 1 );
		$new     = $base . $new_rel;
		wp_mkdir_p( dirname( $new ) );
		if ( ! @rename( $file, $new ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Within uploads.
			return false;
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) ) {
			foreach ( isset( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
				if ( file_exists( dirname( $file ) . '/' . $size['file'] ) ) {
					@rename( dirname( $file ) . '/' . $size['file'], dirname( $new ) . '/' . $size['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Within uploads.
				}
			}
			if ( ! empty( $meta['file'] ) ) {
				$meta['file'] = $new_rel;
				wp_update_attachment_metadata( $id, $meta );
			}
		}
		update_attached_file( $id, $new );
		return true;
	}

	/**
	 * Signed, expiring image address for the current subscriber.
	 *
	 * @param int $id    Attachment.
	 * @param int $width Width.
	 * @return string
	 */
	public static function art_link( $id, $width ) {
		$user  = get_current_user_id();
		$width = max( 100, min( (int) RINNOVOMA_Plans::get( 'art_width' ), (int) $width ) );
		$exp   = time() + 15 * MINUTE_IN_SECONDS;
		$data  = $id . '.' . $width . '.' . $user . '.' . $exp;
		return add_query_arg(
			array(
				'rinnovoma_art' => (int) $id,
				'w'             => $width,
				'e'             => $exp,
				's'             => RINNOVOMA_Player::sign( $data ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Protected files never show their real address.
	 *
	 * @param string $url URL.
	 * @param int    $id  Attachment.
	 * @return string
	 */
	public static function url( $url, $id ) {
		if ( ! self::is_protected( $id ) ) {
			return $url;
		}
		if ( ! RINNOVOMA_Access::has( get_current_user_id(), (string) get_post_meta( $id, self::META, true ) ) ) {
			return '';
		}
		return wp_attachment_is_image( $id ) ? self::art_link( $id, (int) RINNOVOMA_Plans::get( 'art_width' ) ) : RINNOVOMA_Player::media_link( 'file', (string) $id, get_current_user_id(), HOUR_IN_SECONDS );
	}

	/**
	 * Images too.
	 *
	 * @param array|false  $image Image.
	 * @param int          $id    Attachment.
	 * @param string|array $size  Size.
	 * @return array|false
	 */
	public static function image_src( $image, $id, $size ) {
		if ( $image && self::is_protected( $id ) ) {
			$image[0] = RINNOVOMA_Access::has( get_current_user_id(), (string) get_post_meta( $id, self::META, true ) ) ? self::art_link( $id, (int) $image[1] ) : '';
		}
		return $image;
	}

	/**
	 * No responsive sizes for protected images.
	 *
	 * @param array  $sources Sources.
	 * @param array  $size    Size.
	 * @param string $src     Src.
	 * @param array  $meta    Meta.
	 * @param int    $id      Attachment.
	 * @return array
	 */
	public static function no_srcset( $sources, $size, $src, $meta, $id = 0 ) {
		return $id && self::is_protected( $id ) ? array() : $sources;
	}

	/**
	 * Send a watermarked, screen-size copy of a protected image.
	 */
	public static function serve_art() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed link.
		if ( empty( $_GET['rinnovoma_art'] ) ) {
			return;
		}
		$id    = absint( $_GET['rinnovoma_art'] );
		$width = isset( $_GET['w'] ) ? absint( $_GET['w'] ) : 0;
		$exp   = isset( $_GET['e'] ) ? absint( $_GET['e'] ) : 0;
		$sig   = isset( $_GET['s'] ) ? sanitize_key( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$user = get_current_user_id();
		if ( $exp < time() || ! hash_equals( RINNOVOMA_Player::sign( $id . '.' . $width . '.' . $user . '.' . $exp ), $sig ) || ! RINNOVOMA_Player::from_own_site() || ! RINNOVOMA_Access::has( $user, (string) get_post_meta( $id, self::META, true ) ) || ! wp_attachment_is_image( $id ) ) {
			status_header( 403 );
			exit;
		}
		$file = get_attached_file( $id, true );
		$img  = $file && is_readable( $file ) && function_exists( 'imagecreatefromstring' ) ? @imagecreatefromstring( (string) file_get_contents( $file ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( ! $img ) {
			status_header( 404 );
			exit;
		}
		$w = imagesx( $img );
		$h = imagesy( $img );
		if ( $w > $width ) {
			$nh  = (int) round( $h * $width / $w );
			$dst = imagecreatetruecolor( $width, $nh );
			imagecopyresampled( $dst, $img, 0, 0, 0, 0, $width, $nh, $w, $h );
			imagedestroy( $img );
			$img = $dst;
			$w   = $width;
			$h   = $nh;
		}
		if ( RINNOVOMA_Plans::get( 'watermark' ) ) {
			$text  = RINNOVOMA_Player::watermark_text();
			$light = imagecolorallocatealpha( $img, 255, 255, 255, 88 );
			$dark  = imagecolorallocatealpha( $img, 0, 0, 0, 96 );
			$step  = max( 120, (int) ( strlen( $text ) * imagefontwidth( 3 ) ) + 60 );
			for ( $y = 20, $row = 0; $y < $h; $y += 70, $row++ ) {
				for ( $x = ( $row % 2 ) * (int) ( $step / 2 ) - $step; $x < $w; $x += $step ) {
					imagestring( $img, 3, $x + 1, $y + 1, $text, $dark );
					imagestring( $img, 3, $x, $y, $text, $light );
				}
			}
		}
		nocache_headers();
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Disposition: inline; filename="preview.jpg"' );
		header( 'X-Robots-Tag: noindex, noimageindex' );
		header( 'Cross-Origin-Resource-Policy: same-origin' );
		imagejpeg( $img, null, 82 );
		imagedestroy( $img );
		exit;
	}

	/**
	 * Stream a protected audio / video / document file (with byte ranges for seeking).
	 *
	 * @param int $id Attachment.
	 */
	public static function stream_attachment( $id ) {
		$file = get_attached_file( $id, true );
		if ( ! $file || ! is_readable( $file ) ) {
			status_header( 404 );
			exit;
		}
		$type  = wp_check_filetype( $file );
		$size  = filesize( $file );
		$start = 0;
		$end   = $size - 1;
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: inline' );
		header( 'Accept-Ranges: bytes' );
		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ), $m ) ) {
			$start = '' === $m[1] ? max( 0, $size - (int) $m[2] ) : (int) $m[1];
			$end   = '' !== $m[2] && '' !== $m[1] ? min( (int) $m[2], $size - 1 ) : $end;
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming.
		fseek( $handle, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming.
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File bytes.
			$left -= strlen( $chunk );
			flush();
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming.
		exit;
	}

	/**
	 * [media_gallery ids="1,2,3" plan="" columns="3"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'ids'     => '',
				'plan'    => 'any',
				'columns' => 3,
			),
			$atts,
			'media_gallery'
		);
		$plan = sanitize_key( $atts['plan'] );
		if ( ! RINNOVOMA_Access::has( get_current_user_id(), $plan ) ) {
			return RINNOVOMA_Player::locked( $plan, 'art' );
		}
		$ids  = array_filter( array_map( 'absint', explode( ',', (string) $atts['ids'] ) ) );
		$html = '<div class="rinnovoma-gallery" style="--rinnovoma-cols:' . max( 1, min( 6, (int) $atts['columns'] ) ) . '">';
		foreach ( $ids as $id ) {
			if ( ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			$thumb = self::is_protected( $id ) ? self::art_link( $id, 600 ) : wp_get_attachment_image_url( $id, 'medium_large' );
			$full  = self::is_protected( $id ) ? self::art_link( $id, (int) RINNOVOMA_Plans::get( 'art_width' ) ) : wp_get_attachment_image_url( $id, 'large' );
			$html .= '<figure><img src="' . esc_url( $thumb ) . '" data-full="' . esc_url( $full ) . '" alt="' . esc_attr( get_post_meta( $id, '_wp_attachment_image_alt', true ) ) . '" loading="lazy" draggable="false" />';
			$cap   = wp_get_attachment_caption( $id );
			$html .= $cap ? '<figcaption>' . esc_html( $cap ) . '</figcaption>' : '';
			$html .= '</figure>';
		}
		return RINNOVOMA_Player::wrap( $html . '</div>', 'art' );
	}
}
