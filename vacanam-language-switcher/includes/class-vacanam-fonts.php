<?php
/**
 * A font per language: one font for text and one for headings, chosen for each language.
 *
 * Fonts come from the theme, WordPress's Font Library, Elementor's custom fonts, ready-made system
 * fonts, or Google Fonts downloaded once into the site (visitors never connect to Google).
 * With Elementor, the global fonts of the site kit are switched too, through Elementor's own CSS
 * variables, so Elementor and Vacanam never fight over the font.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fonts.
 */
class Vacanam_Fonts {

	const OPTION = 'vacanam_fonts';
	const PAGE   = 'vacanam-fonts';
	const DIR    = 'vacanam-fonts';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 100 );
		add_action( 'wp_head', array( __CLASS__, 'print_css' ), 100 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
			add_filter( 'vacanam_admin_tabs', array( __CLASS__, 'tab' ) );
			foreach ( array( 'save_fonts', 'add_google_font', 'delete_font' ) as $action ) {
				add_action( 'admin_post_vacanam_' . $action, array( __CLASS__, 'handle_' . $action ) );
			}
		}
	}

	/*
	 * Choosing fonts.
	 */

	/**
	 * Clean a font name typed or chosen in the admin.
	 *
	 * @param string $name Font family or "stack:key".
	 * @return string
	 */
	public static function clean_family( $name ) {
		$name = trim( preg_replace( '/[^\p{L}\p{N} :_-]/u', '', (string) $name ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 80 ) : substr( $name, 0, 80 );
	}

	/**
	 * Ready-made font stacks that need no download.
	 *
	 * @return array key => array( label, CSS stack ).
	 */
	public static function stacks() {
		return array(
			'system'  => array( __( 'System font (fast, no download)', 'vacanam-language-switcher' ), 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif' ),
			'arabic'  => array( __( 'System Arabic font (Tahoma, Geeza, Noto)', 'vacanam-language-switcher' ), 'Tahoma, "Segoe UI", "Geeza Pro", "Noto Naskh Arabic", "Noto Sans Arabic", "Arabic Typesetting", sans-serif' ),
			'serif'   => array( __( 'System serif font (Georgia, Times)', 'vacanam-language-switcher' ), 'Georgia, Cambria, "Times New Roman", Times, serif' ),
			'mono'    => array( __( 'System monospace font', 'vacanam-language-switcher' ), 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace' ),
			'indic'   => array( __( 'System Indian scripts font (Nirmala, Noto)', 'vacanam-language-switcher' ), '"Nirmala UI", "Noto Sans Devanagari", "Noto Sans", Mangal, sans-serif' ),
			'cjk'     => array( __( 'System Chinese, Japanese, Korean font', 'vacanam-language-switcher' ), '"PingFang SC", "Hiragino Sans", "Microsoft YaHei", "Noto Sans CJK SC", "Malgun Gothic", sans-serif' ),
		);
	}

	/**
	 * Fonts to choose from, by source.
	 *
	 * @return array group label => array( value => label ).
	 */
	public static function choices() {
		$groups = array();

		$downloaded = array();
		foreach ( self::downloaded() as $family => $info ) {
			$downloaded[ $family ] = $family;
		}
		if ( $downloaded ) {
			$groups[ __( 'Downloaded from Google Fonts', 'vacanam-language-switcher' ) ] = $downloaded;
		}

		$site = array();
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$families = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
			foreach ( is_array( $families ) ? $families : array() as $origin ) {
				foreach ( is_array( $origin ) ? $origin : array() as $font ) {
					if ( ! empty( $font['name'] ) ) {
						$name          = self::first_family( isset( $font['fontFamily'] ) ? $font['fontFamily'] : $font['name'] );
						$site[ $name ] = $font['name'];
					}
				}
			}
		}
		foreach ( get_posts( array( 'post_type' => array( 'wp_font_family', 'elementor_font' ), 'numberposts' => 100, 'post_status' => 'publish' ) ) as $font ) {
			$name = 'wp_font_family' === $font->post_type ? self::font_library_name( $font ) : $font->post_title;
			if ( '' !== $name ) {
				$site[ self::clean_family( $name ) ] = $name;
			}
		}
		if ( $site ) {
			$groups[ __( 'Fonts of your site (theme, Font Library, Elementor)', 'vacanam-language-switcher' ) ] = $site;
		}

		$stacks = array();
		foreach ( self::stacks() as $key => $stack ) {
			$stacks[ 'stack:' . $key ] = $stack[0];
		}
		$groups[ __( 'System fonts', 'vacanam-language-switcher' ) ] = $stacks;

		/**
		 * Fonts offered for each language.
		 *
		 * @param array $groups group label => array( value => label ).
		 */
		return apply_filters( 'vacanam_font_choices', $groups );
	}

	/**
	 * Name of a Font Library font.
	 *
	 * @param WP_Post $font Font family post.
	 * @return string
	 */
	private static function font_library_name( $font ) {
		$settings = json_decode( $font->post_content, true );
		if ( is_array( $settings ) && ! empty( $settings['fontFamily'] ) ) {
			return self::first_family( $settings['fontFamily'] );
		}
		return $font->post_title;
	}

	/**
	 * First family of a CSS font-family value.
	 *
	 * @param string $value CSS value.
	 * @return string
	 */
	private static function first_family( $value ) {
		$first = explode( ',', (string) $value );
		return self::clean_family( trim( $first[0], " \t\"'" ) );
	}

	/**
	 * CSS font-family value of a chosen font.
	 *
	 * @param string $font Chosen font.
	 * @param bool   $rtl  Right-to-left language (better fallback).
	 * @return string '' when none.
	 */
	public static function css_value( $font, $rtl = false ) {
		$font = self::clean_family( $font );
		if ( '' === $font ) {
			return '';
		}
		if ( 0 === strpos( $font, 'stack:' ) ) {
			$stacks = self::stacks();
			$key    = substr( $font, 6 );
			return isset( $stacks[ $key ] ) ? $stacks[ $key ][1] : '';
		}
		return '"' . $font . '", ' . ( $rtl ? 'Tahoma, "Segoe UI", ' : '' ) . 'sans-serif';
	}

	/*
	 * Downloaded Google fonts.
	 */

	/**
	 * Google fonts downloaded into the site.
	 *
	 * @return array family => array( css => file name ).
	 */
	public static function downloaded() {
		$saved = get_option( self::OPTION, array() );
		return isset( $saved['google'] ) && is_array( $saved['google'] ) ? $saved['google'] : array();
	}

	/**
	 * Folder of downloaded fonts.
	 *
	 * @param bool $url Address instead of path.
	 * @return string With a trailing slash.
	 */
	private static function dir( $url = false ) {
		$up = wp_upload_dir( null, false );
		return trailingslashit( ( $url ? set_url_scheme( $up['baseurl'] ) : $up['basedir'] ) . '/' . self::DIR );
	}

	/**
	 * Folder name of a family.
	 *
	 * @param string $family Family.
	 * @return string
	 */
	private static function family_slug( $family ) {
		return sanitize_title( $family );
	}

	/**
	 * Download a Google font into the site.
	 *
	 * @param string $family Family name, as on fonts.google.com.
	 * @return true|WP_Error
	 */
	public static function download_google( $family ) {
		global $wp_filesystem;
		$family = self::clean_family( $family );
		if ( '' === $family || 0 === strpos( $family, 'stack:' ) ) {
			return new WP_Error( 'vacanam_font', __( 'Type the name of a font, as written on fonts.google.com.', 'vacanam-language-switcher' ) );
		}
		$css = '';
		// The widest choice first: every weight from 400 to 700; some fonts only have one weight.
		foreach ( array( ':wght@400;500;600;700', ':wght@400;700', '' ) as $axis ) {
			$response = wp_remote_get(
				'https://fonts.googleapis.com/css2?family=' . rawurlencode( $family ) . $axis . '&display=swap',
				array(
					'timeout'    => 20,
					// Google sends woff2 files (the smallest) only to browsers it knows.
					'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
				)
			);
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$css = (string) wp_remote_retrieve_body( $response );
				break;
			}
		}
		if ( '' === $css || false === strpos( $css, '@font-face' ) ) {
			/* translators: %s: font name */
			return new WP_Error( 'vacanam_font', sprintf( __( 'Google Fonts has no font called "%s". Check the spelling on fonts.google.com.', 'vacanam-language-switcher' ), $family ) );
		}
		if ( ! preg_match_all( '#url\((https://fonts\.gstatic\.com/[^)\s]+\.woff2)\)#', $css, $files ) ) {
			return new WP_Error( 'vacanam_font', __( 'The font files could not be found.', 'vacanam-language-switcher' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'vacanam_font', __( 'WordPress cannot write files on this server.', 'vacanam-language-switcher' ) );
		}
		$slug = self::family_slug( $family );
		$dir  = self::dir() . $slug . '/';
		$url  = self::dir( true ) . $slug . '/';
		wp_mkdir_p( $dir );
		foreach ( array_unique( $files[1] ) as $i => $remote ) {
			$tmp = download_url( $remote, 30 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			$name = $slug . '-' . $i . '.woff2';
			$wp_filesystem->move( $tmp, $dir . $name, true );
			$css = str_replace( $remote, $url . $name, $css );
		}
		$css = "/* {$family} – downloaded from Google Fonts by Vacanam, served from this site. */\n" . $css;
		$wp_filesystem->put_contents( $dir . $slug . '.css', $css, FS_CHMOD_FILE );

		$saved                       = get_option( self::OPTION, array() );
		$saved                       = is_array( $saved ) ? $saved : array();
		$saved['google'][ $family ] = array( 'css' => $slug . '/' . $slug . '.css' );
		update_option( self::OPTION, $saved, false );
		return true;
	}

	/**
	 * Delete a downloaded font.
	 *
	 * @param string $family Family.
	 */
	public static function delete_google( $family ) {
		global $wp_filesystem;
		$saved = get_option( self::OPTION, array() );
		if ( ! isset( $saved['google'][ $family ] ) ) {
			return;
		}
		unset( $saved['google'][ $family ] );
		update_option( self::OPTION, $saved, false );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			$wp_filesystem->delete( self::dir() . self::family_slug( $family ), true );
		}
	}

	/*
	 * On the site.
	 */

	/**
	 * Fonts of the language of the visit.
	 *
	 * @return array|null array( body, headings, lang ) or null when the language has none.
	 */
	private static function current() {
		$lang = Vacanam_Languages::get( Vacanam_Router::current() );
		if ( ! $lang || ( empty( $lang['font'] ) && empty( $lang['font_h'] ) ) ) {
			return null;
		}
		$body = self::css_value( $lang['font'], $lang['rtl'] );
		$head = ! empty( $lang['font_h'] ) ? self::css_value( $lang['font_h'], $lang['rtl'] ) : $body;
		return array( $body, $head, $lang );
	}

	/**
	 * Load the files of downloaded fonts used by the language of the visit.
	 */
	public static function enqueue() {
		$fonts = self::current();
		if ( ! $fonts ) {
			return;
		}
		$downloaded = self::downloaded();
		foreach ( array_unique( array( $fonts[2]['font'], $fonts[2]['font_h'] ) ) as $family ) {
			if ( '' !== $family && isset( $downloaded[ $family ] ) ) {
				wp_enqueue_style( 'vacanam-font-' . self::family_slug( $family ), self::dir( true ) . $downloaded[ $family ]['css'], array(), VACANAM_VERSION );
			}
		}
	}

	/**
	 * The CSS of the language of the visit.
	 *
	 * @return string
	 */
	public static function css() {
		$fonts = self::current();
		if ( ! $fonts ) {
			return '';
		}
		list( $body, $head ) = $fonts;
		$force = Vacanam_Settings::on( 'fonts_force' );
		$imp   = $force ? ' !important' : '';
		$css   = '';
		// html:root raises the weight of the rule above the theme's body and h1 rules without !important.
		if ( '' !== $body ) {
			$css .= 'html:root body,html:root :is(button,input,select,optgroup,textarea){font-family:' . $body . $imp . '}';
		}
		if ( '' !== $head ) {
			$css .= 'html:root :is(h1,h2,h3,h4,h5,h6){font-family:' . $head . $imp . '}';
		}
		if ( Vacanam_Settings::on( 'fonts_elementor' ) ) {
			$css .= self::elementor_css( $body, $head );
		}
		foreach ( array_unique( array_filter( array( $fonts[2]['font'], $fonts[2]['font_h'] ) ) ) as $family ) {
			if ( 0 !== strpos( $family, 'stack:' ) && ! isset( self::downloaded()[ $family ] ) ) {
				$css = self::elementor_font_face( $family ) . $css;
			}
		}
		if ( $force && '' !== $body ) {
			// Elements with a font of their own (widget settings), but never icon fonts.
			$css .= 'html:root body :is(p,a,li,td,th,dd,dt,label,blockquote,figcaption,span,strong,em,b,small,div):not(i):not([class*="icon"]):not([class*="fa-"]):not([class^="fa"]):not(.dashicons):not([class*="eicon"]){font-family:' . $body . ' !important}';
			if ( '' !== $head ) {
				$css .= 'html:root body :is(h1,h2,h3,h4,h5,h6) :is(a,span,strong,em,b):not(i):not([class*="icon"]):not([class*="fa-"]){font-family:' . $head . ' !important}';
			}
		}
		/**
		 * CSS that sets the fonts of the language of the visit.
		 *
		 * @param string $css  CSS.
		 * @param array  $lang Language.
		 */
		return (string) apply_filters( 'vacanam_font_css', $css, $fonts[2] );
	}

	/**
	 * Global fonts of Elementor's active kit: Primary, Secondary, Text, Accent, H1 to H6 (newer
	 * Elementor) and the site's own global fonts.
	 *
	 * Read from the kit's settings and from the CSS Elementor generated for the kit, so fonts that
	 * newer Elementor versions add are found too.
	 *
	 * @param int $kit Kit ID.
	 * @return array ID => true when it is a headings font.
	 */
	public static function elementor_globals( $kit ) {
		$found    = array();
		$titles   = array();
		$settings = get_post_meta( $kit, '_elementor_page_settings', true );
		foreach ( array( 'system_typography', 'custom_typography' ) as $group ) {
			if ( is_array( $settings ) && ! empty( $settings[ $group ] ) && is_array( $settings[ $group ] ) ) {
				foreach ( $settings[ $group ] as $item ) {
					if ( ! empty( $item['_id'] ) ) {
						$id            = preg_replace( '/[^a-z0-9_-]/i', '', $item['_id'] );
						$found[ $id ]  = true;
						$titles[ $id ] = isset( $item['title'] ) ? (string) $item['title'] : '';
					}
				}
			}
		}
		// The kit's generated CSS lists every global font variable in use.
		$up   = wp_upload_dir( null, false );
		$file = trailingslashit( $up['basedir'] ) . 'elementor/css/post-' . (int) $kit . '.css';
		if ( is_readable( $file ) ) {
			$key  = 'vacanam_el_fonts_' . $kit . '_' . filemtime( $file );
			$vars = get_transient( $key );
			if ( false === $vars ) {
				$vars = array();
				if ( preg_match_all( '/--e-global-typography-([a-z0-9_-]+?)-font-family/i', (string) file_get_contents( $file ), $m ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
					$vars = array_values( array_unique( $m[1] ) );
				}
				set_transient( $key, $vars, WEEK_IN_SECONDS );
			}
			foreach ( $vars as $id ) {
				$found[ $id ] = true;
			}
		}
		foreach ( array( 'primary', 'secondary', 'text', 'accent' ) as $id ) {
			$found[ $id ] = true;
		}
		$out = array();
		foreach ( array_keys( $found ) as $id ) {
			$title      = isset( $titles[ $id ] ) ? $titles[ $id ] : '';
			$out[ $id ] = in_array( $id, array( 'primary', 'secondary' ), true )
				|| (bool) preg_match( '/^h[1-6]$/i', $id )
				|| (bool) preg_match( '/\b(h[1-6]|heading|headline|title)\b/i', $title );
		}
		/**
		 * Elementor global fonts and whether each one takes the headings font.
		 *
		 * @param array $out ID => true for headings.
		 * @param int   $kit Kit ID.
		 */
		return apply_filters( 'vacanam_elementor_global_fonts', $out, $kit );
	}

	/**
	 * Elementor: point the global fonts of the active kit to the fonts of the language. Widgets set to
	 * a global font (the globe icon) read it from these variables, so they follow the language too.
	 *
	 * @param string $body Text font (CSS value).
	 * @param string $head Headings font (CSS value).
	 * @return string
	 */
	private static function elementor_css( $body, $head ) {
		$kit = (int) get_option( 'elementor_active_kit' );
		if ( ! $kit || ! defined( 'ELEMENTOR_VERSION' ) ) {
			return '';
		}
		$vars = '';
		foreach ( self::elementor_globals( $kit ) as $id => $is_heading ) {
			$value = $is_heading && '' !== $head ? $head : $body;
			if ( '' !== $value ) {
				$vars .= '--e-global-typography-' . $id . '-font-family:' . $value . ';';
			}
		}
		$css = '';
		if ( '' !== $vars ) {
			// body.elementor-kit-N outranks Elementor's own .elementor-kit-N rule, and :root for newer versions.
			$css .= ':root body.elementor-kit-' . $kit . ',:root body.elementor-kit-' . $kit . ' .elementor{' . $vars . '}';
		}
		if ( '' !== $body ) {
			$css .= 'body.elementor-kit-' . $kit . '{font-family:' . $body . '}';
		}
		if ( '' !== $head ) {
			$css .= 'body.elementor-kit-' . $kit . ' :is(h1,h2,h3,h4,h5,h6){font-family:' . $head . '}';
		}
		return $css;
	}

	/**
	 * @font-face rules of an Elementor custom font (Elementor Pro > Custom Fonts): Elementor only prints
	 * them when the font is used in its own settings, so Vacanam prints them when a language uses it.
	 *
	 * @param string $family Font family.
	 * @return string
	 */
	private static function elementor_font_face( $family ) {
		$posts = get_posts( array( 'post_type' => 'elementor_font', 'title' => $family, 'numberposts' => 1, 'post_status' => 'publish' ) );
		if ( ! $posts ) {
			return '';
		}
		$files = get_post_meta( $posts[0]->ID, 'elementor_font_files', true );
		$css   = '';
		foreach ( is_array( $files ) ? $files : array() as $face ) {
			$src = array();
			foreach ( array( 'woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype', 'otf' => 'opentype' ) as $ext => $format ) {
				if ( ! empty( $face[ $ext ]['url'] ) ) {
					$src[] = 'url("' . esc_url_raw( $face[ $ext ]['url'] ) . '") format("' . $format . '")';
				}
			}
			if ( $src ) {
				$weight = isset( $face['font_weight'] ) ? preg_replace( '/[^a-z0-9 ]/i', '', (string) $face['font_weight'] ) : 'normal';
				$style  = isset( $face['font_style'] ) && 'italic' === $face['font_style'] ? 'italic' : 'normal';
				$css   .= '@font-face{font-family:"' . $family . '";font-style:' . $style . ';font-weight:' . ( '' !== $weight ? $weight : 'normal' ) . ';font-display:swap;src:' . implode( ',', $src ) . '}';
			}
		}
		return $css;
	}

	/**
	 * Print the CSS late in the head, after the theme's and Elementor's styles.
	 */
	public static function print_css() {
		$css = self::css();
		if ( '' !== $css ) {
			echo '<style id="vacanam-fonts">' . wp_strip_all_tags( $css ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from cleaned font names, tags stripped.
		}
	}

	/*
	 * Admin.
	 */

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_submenu_page( Vacanam_Admin::SLUG, __( 'Fonts per language', 'vacanam-language-switcher' ), __( 'Fonts', 'vacanam-language-switcher' ), Vacanam_Admin::cap(), self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Downloaded fonts on the Fonts screen, for the preview.
	 *
	 * @param string $hook Screen.
	 */
	public static function admin_assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		foreach ( self::downloaded() as $family => $info ) {
			wp_enqueue_style( 'vacanam-font-' . self::family_slug( $family ), self::dir( true ) . $info['css'], array(), VACANAM_VERSION );
		}
	}

	/**
	 * Tab on the Languages screens.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$out = array();
		foreach ( $tabs as $page => $label ) {
			if ( 'vacanam-settings' === $page ) {
				$out[ self::PAGE ] = __( 'Fonts', 'vacanam-language-switcher' );
			}
			$out[ $page ] = $label;
		}
		if ( ! isset( $out[ self::PAGE ] ) ) {
			$out[ self::PAGE ] = __( 'Fonts', 'vacanam-language-switcher' );
		}
		return $out;
	}

	/**
	 * Select a font.
	 *
	 * @param string $name    Field name.
	 * @param string $current Current font.
	 * @param string $empty   Label of "no change".
	 */
	private static function select( $name, $current, $empty ) {
		$choices = self::choices();
		$found   = '' === $current;
		echo '<select name="' . esc_attr( $name ) . '" class="vacanam-font-select"><option value="">' . esc_html( $empty ) . '</option>';
		foreach ( $choices as $group => $fonts ) {
			echo '<optgroup label="' . esc_attr( $group ) . '">';
			foreach ( $fonts as $value => $label ) {
				$found = $found || (string) $value === $current;
				echo '<option value="' . esc_attr( $value ) . '" data-css="' . esc_attr( self::css_value( $value ) ) . '" ' . selected( $current, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</optgroup>';
		}
		if ( ! $found ) {
			echo '<option value="' . esc_attr( $current ) . '" selected="selected" data-css="' . esc_attr( self::css_value( $current ) ) . '">' . esc_html( $current ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Popular Google fonts by script, offered while typing.
	 *
	 * @return string[]
	 */
	private static function suggestions() {
		return array( 'Cairo', 'Tajawal', 'Almarai', 'IBM Plex Sans Arabic', 'Noto Kufi Arabic', 'Noto Naskh Arabic', 'Amiri', 'Changa', 'El Messiri', 'Readex Pro', 'Rubik', 'Vazirmatn', 'Noto Nastaliq Urdu', 'Noto Sans Hebrew', 'Heebo', 'Assistant', 'Noto Sans Devanagari', 'Hind', 'Mukta', 'Poppins', 'Noto Sans Bengali', 'Hind Siliguri', 'Noto Sans Tamil', 'Noto Sans Thai', 'Prompt', 'Noto Sans JP', 'Noto Sans KR', 'Noto Sans SC', 'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Plus Jakarta Sans', 'DM Sans', 'Playfair Display', 'Merriweather', 'Lora', 'Nunito', 'Raleway', 'Work Sans', 'Manrope' );
	}

	/**
	 * The Fonts screen.
	 */
	public static function page() {
		Vacanam_Admin::header( __( 'Fonts per language', 'vacanam-language-switcher' ), self::PAGE );
		$langs = Vacanam_Languages::all();
		$s     = Vacanam_Settings::all();
		echo '<p class="vacanam-lead">' . esc_html__( 'Give each language its own font: for example Cairo for Arabic and Inter for English. Visitors see the font of the language they read. Leave a language on "Theme font" to keep your theme\'s or Elementor\'s font.', 'vacanam-language-switcher' ) . '</p>';
		if ( ! $langs ) {
			echo '<p>' . esc_html__( 'Add your languages first.', 'vacanam-language-switcher' ) . '</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="vacanam-card"><input type="hidden" name="action" value="vacanam_save_fonts" />';
		wp_nonce_field( 'vacanam_save_fonts' );
		echo '<table class="widefat striped vacanam-fonts-table"><thead><tr><th>' . esc_html__( 'Language', 'vacanam-language-switcher' ) . '</th><th>' . esc_html__( 'Text font', 'vacanam-language-switcher' ) . '</th><th>' . esc_html__( 'Headings font', 'vacanam-language-switcher' ) . '</th><th>' . esc_html__( 'Preview', 'vacanam-language-switcher' ) . '</th></tr></thead><tbody>';
		foreach ( $langs as $slug => $lang ) {
			$sample = self::sample( $lang );
			echo '<tr><td>' . Vacanam_Admin::flag( $lang ) . ' <strong>' . esc_html( $lang['name'] ) . '</strong></td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag().
			self::select( 'font[' . $slug . ']', $lang['font'], __( 'Theme font', 'vacanam-language-switcher' ) );
			echo '</td><td>';
			self::select( 'font_h[' . $slug . ']', $lang['font_h'], __( 'Same as text', 'vacanam-language-switcher' ) );
			echo '</td><td class="vacanam-font-preview"' . ( $lang['rtl'] ? ' dir="rtl"' : '' ) . '><strong class="vacanam-font-preview-h">' . esc_html( $sample[0] ) . '</strong><br /><span class="vacanam-font-preview-b">' . esc_html( $sample[1] ) . '</span></td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'How the fonts are applied', 'vacanam-language-switcher' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Elementor', 'vacanam-language-switcher' ) . '</th><td><label><input type="checkbox" name="fonts_elementor" value="yes" ' . checked( 'yes', $s['fonts_elementor'], false ) . ' /> ' . esc_html__( 'Switch Elementor\'s global fonts too (Site Settings > Global Fonts): every widget set to a global font follows the language. Primary, Secondary, H1 to H6 and global fonts named like a heading or title take the headings font; Text, Accent and the others take the text font. Elementor custom fonts are loaded when a language uses them.', 'vacanam-language-switcher' ) . '</label><p class="description">' . esc_html__( 'Your Elementor settings are not changed: the fonts are only replaced on pages in that language, so nothing conflicts.', 'vacanam-language-switcher' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Fonts set on single elements', 'vacanam-language-switcher' ) . '</th><td><label><input type="checkbox" name="fonts_force" value="yes" ' . checked( 'yes', $s['fonts_force'], false ) . ' /> ' . esc_html__( 'Also replace fonts set on a single widget or by the theme on single elements', 'vacanam-language-switcher' ) . '</label><p class="description">' . esc_html__( 'Use it when some texts keep the old font. Icon fonts are never changed.', 'vacanam-language-switcher' ) . '</p></td></tr>';
		echo '</table>';
		submit_button( __( 'Save fonts', 'vacanam-language-switcher' ) );
		echo '</form>';

		echo '<div class="vacanam-card"><h2>' . esc_html__( 'Add a font from Google Fonts', 'vacanam-language-switcher' ) . '</h2><p>' . esc_html__( 'The font is downloaded once into your site (wp-content/uploads/vacanam-fonts). Visitors load it from your site: they never connect to Google, which is faster and better for privacy (GDPR).', 'vacanam-language-switcher' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="vacanam_add_google_font" />';
		wp_nonce_field( 'vacanam_add_google_font' );
		echo '<p><input type="text" name="family" list="vacanam-google-fonts" class="regular-text" placeholder="' . esc_attr__( 'Font name, e.g. Cairo', 'vacanam-language-switcher' ) . '" required="required" /> ';
		submit_button( __( 'Download font', 'vacanam-language-switcher' ), 'secondary', 'submit', false );
		echo '</p><datalist id="vacanam-google-fonts">';
		foreach ( self::suggestions() as $name ) {
			echo '<option value="' . esc_attr( $name ) . '"></option>';
		}
		echo '</datalist><p class="description">' . wp_kses_post( sprintf( /* translators: %s: link */ __( 'Find fonts on %s; Arabic fonts: Cairo, Tajawal, Almarai, IBM Plex Sans Arabic, Noto Kufi Arabic, Amiri.', 'vacanam-language-switcher' ), '<a href="https://fonts.google.com/" target="_blank" rel="noopener">fonts.google.com</a>' ) ) . '</p></form>';
		$downloaded = self::downloaded();
		if ( $downloaded ) {
			echo '<h3>' . esc_html__( 'Downloaded fonts', 'vacanam-language-switcher' ) . '</h3><ul class="vacanam-font-list">';
			foreach ( $downloaded as $family => $info ) {
				echo '<li><span>' . esc_html( $family ) . '</span> <a class="button-link-delete" data-confirm="' . esc_attr__( 'Delete this font? Languages using it go back to the theme font.', 'vacanam-language-switcher' ) . '" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vacanam_delete_font&family=' . rawurlencode( $family ) ), 'vacanam_delete_font' ) ) . '">' . esc_html__( 'Delete', 'vacanam-language-switcher' ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</div></div>';
	}

	/**
	 * Preview text of a language.
	 *
	 * @param array $lang Language.
	 * @return string[] Heading, text.
	 */
	private static function sample( $lang ) {
		$code = strtolower( substr( $lang['locale'], 0, 2 ) );
		$map  = array(
			'ar' => array( 'مرحبا بكم في موقعنا', 'نص تجريبي لمعاينة الخط ٠١٢٣٤٥٦٧٨٩' ),
			'fa' => array( 'به سایت ما خوش آمدید', 'متن نمونه برای پیش‌نمایش قلم' ),
			'ur' => array( 'ہماری ویب سائٹ میں خوش آمدید', 'فونٹ کا نمونہ متن' ),
			'he' => array( 'ברוכים הבאים לאתר שלנו', 'טקסט לדוגמה לתצוגת הגופן' ),
			'hi' => array( 'हमारी वेबसाइट पर आपका स्वागत है', 'फ़ॉन्ट देखने के लिए नमूना पाठ' ),
			'ru' => array( 'Добро пожаловать на наш сайт', 'Пример текста для просмотра шрифта' ),
			'zh' => array( '欢迎访问我们的网站', '用于预览字体的示例文字' ),
			'ja' => array( '私たちのサイトへようこそ', 'フォントを確認するためのサンプル文章' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : array( 'Welcome to our website', 'Sample text to preview the font 0123456789' );
	}

	/**
	 * Save the fonts.
	 */
	public static function handle_save_fonts() {
		Vacanam_Admin::check( 'vacanam_save_fonts' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check().
		$body = isset( $_POST['font'] ) ? (array) wp_unslash( $_POST['font'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
		$head = isset( $_POST['font_h'] ) ? (array) wp_unslash( $_POST['font_h'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
		foreach ( Vacanam_Languages::slugs() as $slug ) {
			Vacanam_Languages::update(
				$slug,
				array(
					'font'   => isset( $body[ $slug ] ) ? self::clean_family( $body[ $slug ] ) : '',
					'font_h' => isset( $head[ $slug ] ) ? self::clean_family( $head[ $slug ] ) : '',
				)
			);
		}
		Vacanam_Settings::update(
			array(
				'fonts_elementor' => empty( $_POST['fonts_elementor'] ) ? 'no' : 'yes',
				'fonts_force'     => empty( $_POST['fonts_force'] ) ? 'no' : 'yes',
			)
		);
		// phpcs:enable
		Vacanam_Admin::back( self::PAGE );
	}

	/**
	 * Download a Google font.
	 */
	public static function handle_add_google_font() {
		Vacanam_Admin::check( 'vacanam_add_google_font' );
		$family = isset( $_POST['family'] ) ? sanitize_text_field( wp_unslash( $_POST['family'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in check().
		$result = self::download_google( $family );
		Vacanam_Admin::back( self::PAGE, 'font_added', is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Delete a downloaded font.
	 */
	public static function handle_delete_font() {
		Vacanam_Admin::check( 'vacanam_delete_font' );
		$family = isset( $_GET['family'] ) ? self::clean_family( sanitize_text_field( wp_unslash( $_GET['family'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in check().
		self::delete_google( $family );
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			foreach ( array( 'font', 'font_h' ) as $key ) {
				if ( $lang[ $key ] === $family ) {
					Vacanam_Languages::update( $slug, array( $key => '' ) );
				}
			}
		}
		Vacanam_Admin::back( self::PAGE );
	}
}
