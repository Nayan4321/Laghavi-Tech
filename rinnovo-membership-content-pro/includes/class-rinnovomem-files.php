<?php
/**
 * Protected files: PDFs, audio, video, e-books and images set to "Members only" in the Media Library are
 * moved to a protected folder that can't be opened by its address, and are served only to members
 * through the site (with seeking for audio and video). A shared or guessed link shows the paywall.
 *
 * [member_file id="123" text="Download the workbook"] and [member_library] (a members' digital library).
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Files.
 */
class RINNOVOMEM_Files {

	const TD     = 'rinnovo-membership-content-pro';
	const FOLDER = 'rinnovomem-protected';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_field' ), 10, 2 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'url' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'image_src' ), 20, 3 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'no_srcset' ), 20, 5 );
		add_action( 'init', array( __CLASS__, 'serve' ), 20 );
		add_shortcode( 'member_file', array( __CLASS__, 'shortcode_file' ) );
		add_shortcode( 'member_library', array( __CLASS__, 'shortcode_library' ) );
	}

	/**
	 * Protected folder.
	 *
	 * @return string Path.
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::FOLDER;
	}

	/**
	 * Create the folder with its "no direct access" files.
	 *
	 * @return bool
	 */
	public static function ensure_dir() {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$files = array(
			'.htaccess'  => "# Rinnovo Membership Content Pro: files here are served to members only.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $body ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small server rule files in uploads.
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
		return '' !== (string) get_post_meta( $id, RINNOVOMEM_Rules::TIER, true ) && false !== strpos( (string) get_post_meta( $id, '_wp_attached_file', true ), self::FOLDER . '/' );
	}

	/**
	 * Address through which a protected file is served.
	 *
	 * @param int    $id       Attachment.
	 * @param string $size     Image size.
	 * @param bool   $download As a download.
	 * @return string
	 */
	public static function serve_url( $id, $size = '', $download = false ) {
		$args = array( 'rinnovomem_file' => (int) $id );
		if ( '' !== $size ) {
			$args['size'] = $size;
		}
		if ( $download ) {
			$args['dl'] = 1;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * "Members only" in the Media Library.
	 *
	 * @param array   $fields Fields.
	 * @param WP_Post $post   Attachment.
	 * @return array
	 */
	public static function field( $fields, $post ) {
		$current = (string) get_post_meta( $post->ID, RINNOVOMEM_Rules::TIER, true );
		$html    = '<select name="attachments[' . (int) $post->ID . '][rinnovomem_tier]">';
		foreach ( RINNOVOMEM_Tiers::choices( false ) as $id => $label ) {
			$html .= '<option value="' . esc_attr( $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select>';
		$fields['rinnovomem_tier'] = array(
			'label' => __( 'Members only', self::TD ),
			'input' => 'html',
			'html'  => $html,
			'helps' => __( 'Moves the file to a protected folder: only these members can open it, also with a shared link.', self::TD ),
		);
		return $fields;
	}

	/**
	 * Save it, moving the file in or out of the protected folder.
	 *
	 * @param array $post       Attachment data.
	 * @param array $attachment Posted fields.
	 * @return array
	 */
	public static function save_field( $post, $attachment ) {
		if ( ! isset( $attachment['rinnovomem_tier'] ) || ! current_user_can( 'upload_files' ) ) {
			return $post;
		}
		$tier = sanitize_key( $attachment['rinnovomem_tier'] );
		$id   = (int) $post['ID'];
		if ( '' !== $tier && RINNOVOMEM_Tiers::rank( $tier ) >= 0 ) {
			update_post_meta( $id, RINNOVOMEM_Rules::TIER, $tier );
			self::move( $id, true );
		} else {
			self::move( $id, false );
			delete_post_meta( $id, RINNOVOMEM_Rules::TIER );
		}
		return $post;
	}

	/**
	 * Move an attachment's file (and image sizes) into or out of the protected folder.
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
		if ( $protect === $inside ) {
			return true;
		}
		if ( $protect && ! self::ensure_dir() ) {
			return false;
		}
		$new_rel = $protect ? self::FOLDER . '/' . $rel : substr( $rel, strlen( self::FOLDER ) + 1 );
		$new     = $base . $new_rel;
		wp_mkdir_p( dirname( $new ) );
		if ( ! @rename( $file, $new ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Moving within uploads.
			return false;
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				$from = dirname( $file ) . '/' . $size['file'];
				if ( file_exists( $from ) ) {
					@rename( $from, dirname( $new ) . '/' . $size['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Moving within uploads.
				}
			}
		}
		if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
			$meta['file'] = $new_rel;
			wp_update_attachment_metadata( $id, $meta );
		}
		update_attached_file( $id, $new );
		return true;
	}

	/**
	 * Protected files are linked through the site.
	 *
	 * @param string $url URL.
	 * @param int    $id  Attachment.
	 * @return string
	 */
	public static function url( $url, $id ) {
		return self::is_protected( $id ) ? self::serve_url( $id ) : $url;
	}

	/**
	 * Images too, per size.
	 *
	 * @param array|false  $image Image data.
	 * @param int          $id    Attachment.
	 * @param string|array $size  Size.
	 * @return array|false
	 */
	public static function image_src( $image, $id, $size ) {
		if ( $image && self::is_protected( $id ) ) {
			$image[0] = self::serve_url( $id, is_string( $size ) ? $size : '' );
		}
		return $image;
	}

	/**
	 * No responsive sizes for protected images (each would need its own protected address).
	 *
	 * @param array  $sources Sources.
	 * @param array  $size    Size.
	 * @param string $src     Src.
	 * @param array  $meta    Metadata.
	 * @param int    $id      Attachment.
	 * @return array
	 */
	public static function no_srcset( $sources, $size, $src, $meta, $id = 0 ) {
		return $id && self::is_protected( $id ) ? array() : $sources;
	}

	/**
	 * Serve a protected file to members.
	 */
	public static function serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Access is checked against the member's tier.
		if ( empty( $_GET['rinnovomem_file'] ) ) {
			return;
		}
		$id   = absint( $_GET['rinnovomem_file'] );
		$size = isset( $_GET['size'] ) ? sanitize_key( wp_unslash( $_GET['size'] ) ) : '';
		$dl   = ! empty( $_GET['dl'] );
		// phpcs:enable
		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! self::is_protected( $id ) ) {
			status_header( 404 );
			exit;
		}
		$result = RINNOVOMEM_Rules::can_view( $id );
		if ( ! $result['ok'] ) {
			wp_safe_redirect( add_query_arg( 'rinnovomem_locked', rawurlencode( $result['tier'] ), RINNOVOMEM_Tiers::join_url( $result['tier'] ) ) );
			exit;
		}
		$file = get_attached_file( $id, true );
		if ( '' !== $size ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( isset( $meta['sizes'][ $size ]['file'] ) ) {
				$file = dirname( $file ) . '/' . $meta['sizes'][ $size ]['file'];
			}
		}
		if ( ! $file || ! is_readable( $file ) || 0 !== strpos( wp_normalize_path( realpath( $file ) ), wp_normalize_path( realpath( self::dir() ) ) ) ) {
			status_header( 404 );
			exit;
		}
		self::stream( $file, $dl );
	}

	/**
	 * Send a file (with byte ranges, so audio and video can seek).
	 *
	 * @param string $file     Path.
	 * @param bool   $download As a download.
	 */
	private static function stream( $file, $download ) {
		$type = wp_check_filetype( $file );
		$mime = $type['type'] ? $type['type'] : 'application/octet-stream';
		$size = filesize( $file );
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . rawurlencode( basename( $file ) ) . '"' );
		header( 'Accept-Ranges: bytes' );

		$mode = RINNOVOMEM_Settings::get( 'file_mode' );
		if ( 'xsendfile' === $mode ) {
			header( 'X-Sendfile: ' . $file );
			exit;
		}
		if ( 'xaccel' === $mode ) {
			header( 'X-Accel-Redirect: /' . ltrim( str_replace( ABSPATH, '', $file ), '/' ) );
			exit;
		}

		$start = 0;
		$end   = $size - 1;
		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ), $m ) ) {
			$start = '' === $m[1] ? max( 0, $size - (int) $m[2] ) : (int) $m[1];
			$end   = '' !== $m[2] && '' !== $m[1] ? min( (int) $m[2], $size - 1 ) : $end;
			if ( $start > $end ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a file.
		fseek( $handle, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming a file.
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File bytes.
			$left -= strlen( $chunk );
			flush();
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming a file.
		exit;
	}

	/**
	 * Whether the protected folder really blocks direct access (Nginx ignores .htaccess).
	 *
	 * @return bool|null True protected, false open, null unknown.
	 */
	public static function check_direct_access() {
		if ( ! self::ensure_dir() ) {
			return null;
		}
		$probe = self::dir() . '/rinnovomem-check.txt';
		file_put_contents( $probe, 'check' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small probe file.
		$uploads  = wp_upload_dir( null, false );
		$response = wp_remote_get(
			trailingslashit( $uploads['baseurl'] ) . self::FOLDER . '/rinnovomem-check.txt',
			array(
				'timeout'   => 10,
				'sslverify' => false,
			)
		);
		wp_delete_file( $probe );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		return 'check' !== trim( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * [member_file id="123" text="Download"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode_file( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'text' => '',
			),
			$atts,
			'member_file'
		);
		$id   = absint( $atts['id'] );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return '';
		}
		$result = RINNOVOMEM_Rules::can_view( $id );
		if ( ! $result['ok'] ) {
			return RINNOVOMEM_Paywall::box( $result, 'file' );
		}
		$text = '' !== $atts['text'] ? $atts['text'] : get_the_title( $id );
		return '<a class="rinnovomem-file" href="' . esc_url( self::is_protected( $id ) ? self::serve_url( $id, '', true ) : wp_get_attachment_url( $id ) ) . '">' . esc_html( $text ) . '</a>';
	}

	/**
	 * Protected files a user can open.
	 *
	 * @param int    $user_id User.
	 * @param string $tier    Only this tier's files ('' = all).
	 * @return WP_Post[]
	 */
	public static function library( $user_id, $tier = '' ) {
		$tiers = '' !== $tier ? array( $tier ) : array_merge( array( RINNOVOMEM_Tiers::FREE ), array_keys( RINNOVOMEM_Tiers::all() ) );
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 200,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => RINNOVOMEM_Rules::TIER,
						'value'   => $tiers,
						'compare' => 'IN',
					),
				),
			)
		);
		return array_values(
			array_filter(
				$posts,
				static function ( $p ) use ( $user_id ) {
					return RINNOVOMEM_Rules::can_view( $p->ID, $user_id )['ok'];
				}
			)
		);
	}

	/**
	 * [member_library tier=""] The files the visitor can open.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode_library( $atts ) {
		$atts = shortcode_atts( array( 'tier' => '' ), $atts, 'member_library' );
		if ( ! is_user_logged_in() ) {
			return RINNOVOMEM_Paywall::box(
				array(
					'ok'     => false,
					'reason' => 'login',
					'tier'   => RINNOVOMEM_Tiers::all() ? (string) key( RINNOVOMEM_Tiers::all() ) : RINNOVOMEM_Tiers::FREE,
					'unlock' => 0,
				),
				'file'
			);
		}
		return self::library_html( self::library( get_current_user_id(), sanitize_key( $atts['tier'] ) ) );
	}

	/**
	 * A list of files.
	 *
	 * @param WP_Post[] $files Files.
	 * @return string
	 */
	public static function library_html( $files ) {
		if ( ! $files ) {
			return '<p class="rinnovomem-empty">' . esc_html__( 'No files yet.', self::TD ) . '</p>';
		}
		$html = '<ul class="rinnovomem-library">';
		foreach ( $files as $file ) {
			$type  = wp_check_filetype( (string) get_attached_file( $file->ID ) );
			$html .= '<li><a href="' . esc_url( self::serve_url( $file->ID, '', ! in_array( $type['ext'], array( 'mp3', 'mp4', 'm4a', 'webm', 'ogg', 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) ) . '">' . esc_html( get_the_title( $file ) ) . '</a> <span class="rinnovomem-library__type">' . esc_html( strtoupper( (string) $type['ext'] ) ) . '</span></li>';
		}
		return $html . '</ul>';
	}
}
