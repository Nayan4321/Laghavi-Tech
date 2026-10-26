<?php
/**
 * Setup: languages (the main one detected from the home page) → content → copy of the site →
 * language switcher → ready.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Setup wizard.
 */
class Vacanam_Wizard {

	const PAGE = 'vacanam-setup';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_after_activation' ) );
		add_action( 'admin_post_vacanam_setup', array( __CLASS__, 'save' ) );
	}

	/**
	 * Hidden page.
	 */
	public static function menu() {
		add_submenu_page( Vacanam_Admin::SLUG, __( 'Language setup', 'vacanam-language-switcher' ), __( 'Setup', 'vacanam-language-switcher' ), Vacanam_Admin::cap(), self::PAGE, array( __CLASS__, 'page' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_menu' ) );
	}

	/**
	 * The setup is opened from the Languages screen, not from the menu.
	 */
	public static function hide_menu() {
		remove_submenu_page( Vacanam_Admin::SLUG, self::PAGE );
	}

	/**
	 * Open the setup once, right after the plugin is activated.
	 */
	public static function redirect_after_activation() {
		if ( ! get_option( 'vacanam_show_wizard' ) || wp_doing_ajax() || is_network_admin() || ! current_user_can( Vacanam_Admin::cap() ) ) {
			return;
		}
		delete_option( 'vacanam_show_wizard' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Several plugins activated at once: no redirect.
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Steps.
	 *
	 * @return string[]
	 */
	private static function steps() {
		return array(
			'languages' => __( 'Languages', 'vacanam-language-switcher' ),
			'content'   => __( 'Content', 'vacanam-language-switcher' ),
			'copy'      => __( 'Copy your site', 'vacanam-language-switcher' ),
			'switcher'  => __( 'Switcher', 'vacanam-language-switcher' ),
			'ready'     => __( 'Ready', 'vacanam-language-switcher' ),
		);
	}

	/*
	 * Detecting the main language.
	 */

	/**
	 * Text of the home page (or of the latest posts).
	 *
	 * @return string
	 */
	public static function home_text() {
		$text  = get_option( 'blogname' ) . ' ' . get_option( 'blogdescription' ) . ' ';
		$posts = array();
		$front = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front ) {
			$posts[] = get_post( $front );
		}
		if ( ! $posts ) {
			$posts = get_posts(
				array(
					'numberposts' => 5,
					'post_type'   => array( 'page', 'post' ),
					'lang'        => 'all',
				)
			);
		}
		foreach ( $posts as $post ) {
			if ( ! $post ) {
				continue;
			}
			$text .= ' ' . $post->post_title . ' ' . $post->post_content;
			$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
			if ( is_array( $data ) ) {
				array_walk_recursive(
					$data,
					static function ( $value, $key ) use ( &$text ) {
						if ( is_string( $value ) && in_array( $key, array( 'title', 'editor', 'text', 'description', 'button_text', 'tab_title', 'tab_content', 'heading', 'content' ), true ) ) {
							$text .= ' ' . $value;
						}
					}
				);
			}
		}
		$text = wp_strip_all_tags( strip_shortcodes( $text ) );
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
		return mb_substr( (string) preg_replace( '/\s+/u', ' ', $text ), 0, 20000 );
	}

	/**
	 * Language of a text, as a WordPress locale ('' when unsure).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function detect( $text ) {
		$scripts = array(
			'ar'    => '\x{0600}-\x{06FF}\x{0750}-\x{077F}',
			'he_IL' => '\x{0590}-\x{05FF}',
			'ru_RU' => '\x{0400}-\x{04FF}',
			'el'    => '\x{0370}-\x{03FF}',
			'hi_IN' => '\x{0900}-\x{097F}',
			'bn_BD' => '\x{0980}-\x{09FF}',
			'pa_IN' => '\x{0A00}-\x{0A7F}',
			'gu'    => '\x{0A80}-\x{0AFF}',
			'ta_IN' => '\x{0B80}-\x{0BFF}',
			'te'    => '\x{0C00}-\x{0C7F}',
			'kn'    => '\x{0C80}-\x{0CFF}',
			'ml_IN' => '\x{0D00}-\x{0D7F}',
			'si_LK' => '\x{0D80}-\x{0DFF}',
			'th'    => '\x{0E00}-\x{0E7F}',
			'ka_GE' => '\x{10A0}-\x{10FF}',
			'hy'    => '\x{0530}-\x{058F}',
			'ko_KR' => '\x{AC00}-\x{D7AF}',
			'ja'    => '\x{3040}-\x{30FF}',
			'zh_CN' => '\x{4E00}-\x{9FFF}',
			'km'    => '\x{1780}-\x{17FF}',
			'my_MM' => '\x{1000}-\x{109F}',
			'am'    => '\x{1200}-\x{137F}',
		);
		$latin  = preg_match_all( '/[a-zA-Z\x{00C0}-\x{024F}]/u', $text );
		$best   = '';
		$most   = 0;
		foreach ( $scripts as $locale => $range ) {
			$n = preg_match_all( '/[' . $range . ']/u', $text );
			if ( $n > $most ) {
				$most = $n;
				$best = $locale;
			}
		}
		if ( 'ja' === $best || ( 'zh_CN' === $best && preg_match_all( '/[\x{3040}-\x{30FF}]/u', $text ) > 20 ) ) {
			$best = 'ja';
		}
		if ( $most > $latin && $most > 20 ) {
			if ( 'ar' === $best ) {
				$urdu    = preg_match_all( '/[ٹڈڑںہےۓ]/u', $text );
				$persian = preg_match_all( '/[پچژگ]|می|است/u', $text );
				if ( $urdu > 15 && $urdu * 50 > $most ) {
					return 'ur';
				}
				if ( $persian > 15 && $persian * 30 > $most ) {
					return 'fa_IR';
				}
			}
			if ( 'ru_RU' === $best && preg_match_all( '/[іїєґ]/u', $text ) > 10 ) {
				return 'uk';
			}
			return $best;
		}
		if ( $latin < 20 ) {
			return '';
		}
		$words = array(
			'en_US' => array( 'the', 'and', 'of', 'to', 'is', 'in', 'for', 'with', 'you', 'your', 'our', 'we', 'are', 'that', 'this' ),
			'fr_FR' => array( 'le', 'la', 'les', 'et', 'des', 'de', 'est', 'pour', 'vous', 'nous', 'une', 'du', 'avec', 'sur', 'votre' ),
			'es_ES' => array( 'el', 'la', 'los', 'las', 'y', 'de', 'que', 'para', 'con', 'una', 'por', 'es', 'su', 'del', 'nuestro' ),
			'de_DE' => array( 'der', 'die', 'das', 'und', 'ist', 'mit', 'für', 'sie', 'wir', 'ein', 'eine', 'nicht', 'auf', 'den', 'ihre' ),
			'it_IT' => array( 'il', 'la', 'e', 'di', 'che', 'per', 'con', 'una', 'sono', 'del', 'della', 'nostro', 'gli', 'le', 'è' ),
			'pt_BR' => array( 'o', 'a', 'os', 'e', 'de', 'que', 'para', 'com', 'uma', 'não', 'do', 'da', 'nossa', 'você', 'em' ),
			'nl_NL' => array( 'de', 'het', 'een', 'en', 'van', 'is', 'voor', 'met', 'wij', 'onze', 'niet', 'op', 'uw', 'zijn', 'dat' ),
			'tr_TR' => array( 've', 'bir', 'bu', 'için', 'ile', 'da', 'de', 'çok', 'daha', 'olarak', 'size', 'bizim', 'hizmet', 'en', 'mi' ),
			'id_ID' => array( 'dan', 'yang', 'di', 'untuk', 'dengan', 'ini', 'dari', 'kami', 'anda', 'ke', 'tidak', 'akan', 'dalam', 'ada', 'juga' ),
			'pl_PL' => array( 'i', 'w', 'na', 'się', 'z', 'do', 'nie', 'jest', 'dla', 'że', 'oraz', 'naszej', 'jak', 'od', 'po' ),
			'sv_SE' => array( 'och', 'att', 'det', 'som', 'en', 'för', 'med', 'är', 'vi', 'på', 'av', 'till', 'din', 'inte', 'har' ),
			'ro_RO' => array( 'și', 'în', 'de', 'la', 'cu', 'pentru', 'este', 'un', 'o', 'din', 'care', 'noastre', 'pe', 'mai', 'vă' ),
			'ms_MY' => array( 'dan', 'yang', 'untuk', 'dengan', 'ini', 'kami', 'anda', 'adalah', 'dalam', 'akan', 'boleh', 'kepada', 'tidak', 'telah', 'oleh' ),
			'vi'    => array( 'và', 'của', 'các', 'là', 'cho', 'chúng', 'tôi', 'với', 'được', 'không', 'những', 'một', 'có', 'này', 'bạn' ),
			'sw'    => array( 'na', 'ya', 'wa', 'kwa', 'ni', 'za', 'katika', 'la', 'kama', 'huduma', 'yetu', 'hii', 'pia', 'au', 'sisi' ),
		);
		$tokens = array_count_values( preg_split( '/[^\p{L}]+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
		$score  = array();
		foreach ( $words as $locale => $list ) {
			$score[ $locale ] = 0;
			foreach ( $list as $word ) {
				$score[ $locale ] += isset( $tokens[ $word ] ) ? $tokens[ $word ] : 0;
			}
		}
		arsort( $score );
		$top = (string) key( $score );
		return reset( $score ) > 3 ? $top : '';
	}

	/**
	 * Suggested main language: what the home page is written in, with the site's own locale when it is
	 * the same language (keeps the region: en_GB, ar_AE…).
	 *
	 * @return array locale, detected (bool), sample.
	 */
	public static function suggestion() {
		$site     = get_locale();
		$detected = self::detect( self::home_text() );
		$catalog  = Vacanam_Languages::catalog();
		$locale   = $detected;
		if ( '' === $detected || strtok( $detected, '_' ) === strtok( $site, '_' ) ) {
			$locale = $site;
		}
		if ( ! isset( $catalog[ $locale ] ) ) {
			$base = strtok( $locale, '_' );
			foreach ( array_keys( $catalog ) as $known ) {
				if ( strtok( $known, '_' ) === $base ) {
					$locale = $known;
					break;
				}
			}
		}
		return array(
			'locale'   => isset( $catalog[ $locale ] ) ? $locale : 'en_US',
			'detected' => '' !== $detected,
		);
	}

	/*
	 * Screens.
	 */

	/**
	 * The setup.
	 */
	public static function page() {
		$steps = self::steps();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'languages';
		$step = isset( $steps[ $step ] ) ? $step : 'languages';
		if ( 'languages' !== $step && ! Vacanam_Languages::all() ) {
			$step = 'languages';
		}
		echo '<div class="wrap vacanam-wizard"><h1 class="vacanam-title"><span class="dashicons dashicons-translation"></span> ' . esc_html__( 'Vacanam Language Switcher – setup', 'vacanam-language-switcher' ) . '</h1><ol class="vacanam-steps">';
		$reached = true;
		foreach ( $steps as $key => $label ) {
			$class = $key === $step ? 'current' : ( $reached ? 'done' : '' );
			if ( $key === $step ) {
				$reached = false;
			}
			echo '<li class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</li>';
		}
		echo '</ol><div class="vacanam-card vacanam-wizard-card">';
		call_user_func( array( __CLASS__, 'step_' . $step ) );
		echo '</div><p class="vacanam-skip"><a href="' . esc_url( admin_url( 'admin.php?page=vacanam' ) ) . '">' . esc_html__( 'Leave the setup', 'vacanam-language-switcher' ) . '</a></p></div>';
	}

	/**
	 * Start of a step form.
	 *
	 * @param string $step Step.
	 */
	private static function form_start( $step ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="vacanam_setup" /><input type="hidden" name="step" value="' . esc_attr( $step ) . '" />';
		wp_nonce_field( 'vacanam_setup' );
	}

	/**
	 * Address of a step.
	 *
	 * @param string $step Step.
	 * @return string
	 */
	private static function url( $step ) {
		return admin_url( 'admin.php?page=' . self::PAGE . '&step=' . $step );
	}

	/**
	 * Step 1: languages.
	 */
	private static function step_languages() {
		$catalog = Vacanam_Languages::catalog();
		$langs   = Vacanam_Languages::all();
		$main    = Vacanam_Languages::get( Vacanam_Languages::default_slug() );
		$suggest = self::suggestion();
		$locale  = $main ? $main['locale'] : $suggest['locale'];
		$popular = array( 'ar', 'en_US', 'fr_FR', 'es_ES', 'de_DE', 'ur', 'hi_IN', 'fa_IR', 'tr_TR', 'ru_RU', 'zh_CN', 'pt_BR', 'it_IT', 'bn_BD', 'id_ID', 'he_IL' );
		self::form_start( 'languages' );
		echo '<h2>' . esc_html__( 'Main language', 'vacanam-language-switcher' ) . '</h2>';
		if ( ! $main ) {
			$info = $catalog[ $suggest['locale'] ];
			if ( $suggest['detected'] ) {
				/* translators: %s: language name */
				echo '<p class="vacanam-detected"><span class="dashicons dashicons-search"></span> ' . esc_html( sprintf( __( 'We read your home page: it is written in %s. We suggest it as the main language of your site.', 'vacanam-language-switcher' ), $info[2] . ' (' . $info[1] . ')' ) ) . '</p>';
			}
			echo '<p>' . esc_html__( 'The language your site is written in now. Its pages keep their addresses.', 'vacanam-language-switcher' ) . '</p>';
			echo '<p><select name="main" class="vacanam-big-select">';
			foreach ( $catalog as $key => $item ) {
				echo '<option value="' . esc_attr( $key ) . '" ' . selected( $locale, $key, false ) . '>' . esc_html( $item[2] . ' – ' . $item[1] ) . '</option>';
			}
			echo '</select></p>';
		} else {
			echo '<p>' . Vacanam_Admin::flag( $main['flag'] ) . ' <strong>' . esc_html( $main['name'] ) . '</strong> – <a href="' . esc_url( admin_url( 'admin.php?page=vacanam' ) ) . '">' . esc_html__( 'change', 'vacanam-language-switcher' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag().
		}
		echo '<h2>' . esc_html__( 'Other languages', 'vacanam-language-switcher' ) . '</h2><p>' . esc_html__( 'Choose the languages to translate your site into. You can add more later.', 'vacanam-language-switcher' ) . '</p><div class="vacanam-lang-grid">';
		$have = wp_list_pluck( $langs, 'locale' );
		foreach ( $popular as $key ) {
			if ( ! isset( $catalog[ $key ] ) || ( $main && $main['locale'] === $key ) ) {
				continue;
			}
			$item = $catalog[ $key ];
			$on   = in_array( $key, $have, true );
			echo '<label class="vacanam-lang-tile' . ( $on ? ' is-added' : '' ) . '"><input type="checkbox" name="add[]" value="' . esc_attr( $key ) . '" ' . checked( $on, true, false ) . ( $on ? ' disabled="disabled"' : '' ) . ' /> ' . Vacanam_Admin::flag( $item[3] ) . ' <span><strong' . ( $item[4] ? ' dir="rtl"' : '' ) . '>' . esc_html( $item[1] ) . '</strong><small>' . esc_html( $item[2] ) . ( $item[4] ? ' · ' . esc_html__( 'right to left', 'vacanam-language-switcher' ) : '' ) . '</small></span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag().
		}
		echo '</div><p><label>' . esc_html__( 'Another language:', 'vacanam-language-switcher' ) . ' <select name="add[]"><option value="">' . esc_html__( '— Choose —', 'vacanam-language-switcher' ) . '</option>';
		foreach ( $catalog as $key => $item ) {
			if ( ! in_array( $key, $popular, true ) && ! in_array( $key, $have, true ) ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $item[2] . ' – ' . $item[1] ) . '</option>';
			}
		}
		echo '</select></label></p>';
		if ( $langs && count( $langs ) > 1 ) {
			echo '<p>' . esc_html__( 'Already added:', 'vacanam-language-switcher' ) . ' ';
			foreach ( $langs as $lang ) {
				echo Vacanam_Admin::flag( $lang['flag'] ) . ' ' . esc_html( $lang['name'] ) . ' &nbsp; '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag().
			}
			echo '</p>';
		}
		submit_button( __( 'Continue', 'vacanam-language-switcher' ), 'primary button-hero' );
		echo '</form>';
	}

	/**
	 * Step 2: content without language, media, addresses.
	 */
	private static function step_content() {
		$missing = Vacanam_Model::count_without_language();
		$main    = Vacanam_Languages::get( Vacanam_Languages::default_slug() );
		self::form_start( 'content' );
		echo '<h2>' . esc_html__( 'Your existing content', 'vacanam-language-switcher' ) . '</h2>';
		if ( $missing ) {
			/* translators: 1: number, 2: language */
			echo '<p>' . esc_html( sprintf( _n( '%1$d page, post or category will be marked as %2$s.', '%1$d pages, posts and categories will be marked as %2$s.', $missing, 'vacanam-language-switcher' ), $missing, $main['name'] ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'All your content already has a language.', 'vacanam-language-switcher' ) . '</p>';
		}
		echo '<h2>' . esc_html__( 'Media', 'vacanam-language-switcher' ) . '</h2><p><label><input type="checkbox" name="media" value="yes" ' . checked( Vacanam_Settings::on( 'media' ), true, false ) . ' /> ' . esc_html__( 'Translate the alt text and caption of images (good for SEO of image search). Each translation uses the same file.', 'vacanam-language-switcher' ) . '</label></p>';
		echo '<h2>' . esc_html__( 'Addresses', 'vacanam-language-switcher' ) . '</h2><p>' . esc_html( sprintf( /* translators: 1: example, 2: example */ __( 'Your current pages keep their addresses (%1$s). Translations get a language folder (%2$s).', 'vacanam-language-switcher' ), Vacanam_Router::raw_home() . '/about/', Vacanam_Router::raw_home() . '/ar/about-ar/' ) ) . '</p>';
		echo '<p><label><input type="checkbox" name="browser" value="yes" ' . checked( Vacanam_Settings::on( 'browser' ), true, false ) . ' /> ' . esc_html__( 'Show the home page in the visitor\'s browser language on the first visit', 'vacanam-language-switcher' ) . '</label></p>';
		submit_button( __( 'Continue', 'vacanam-language-switcher' ), 'primary button-hero' );
		echo '</form>';
	}

	/**
	 * Step 3: copy the site.
	 */
	private static function step_copy() {
		echo '<h2>' . esc_html__( 'Copy your site into the new languages', 'vacanam-language-switcher' ) . '</h2>';
		$front = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front ) {
			/* translators: %s: page title */
			echo '<p><span class="dashicons dashicons-admin-home"></span> ' . esc_html( sprintf( __( 'Your home page "%s" is copied too and becomes the home page of each language automatically.', 'vacanam-language-switcher' ), get_the_title( $front ) ) ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Every page is copied with its design (blocks, Elementor and other page builders) and connected to its original. Then you only change the text. Already translated pages are skipped.', 'vacanam-language-switcher' ) . '</p>';
		Vacanam_Admin::copy_form( 'draft' );
		echo '<p class="vacanam-next"><a class="button button-primary button-hero" href="' . esc_url( self::url( 'switcher' ) ) . '">' . esc_html__( 'Continue', 'vacanam-language-switcher' ) . '</a> &nbsp; <a href="' . esc_url( self::url( 'switcher' ) ) . '">' . esc_html__( 'Skip, I will translate page by page', 'vacanam-language-switcher' ) . '</a></p>';
	}

	/**
	 * Step 4: language switcher.
	 */
	private static function step_switcher() {
		$locations = array_filter(
			get_registered_nav_menus(),
			static function ( $key ) {
				return false === strpos( $key, Vacanam_Menus::SEP );
			},
			ARRAY_FILTER_USE_KEY
		);
		$assigned  = get_nav_menu_locations();
		self::form_start( 'switcher' );
		echo '<h2>' . esc_html__( 'Let visitors choose their language', 'vacanam-language-switcher' ) . '</h2>';
		if ( $locations ) {
			echo '<p><label><input type="checkbox" name="menu_switcher" value="1" checked="checked" /> ' . esc_html__( 'Add a language switcher to the menu:', 'vacanam-language-switcher' ) . '</label> <select name="location">';
			foreach ( $locations as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '"' . ( empty( $assigned[ $key ] ) ? ' disabled="disabled"' : '' ) . '>' . esc_html( $label . ( empty( $assigned[ $key ] ) ? ' – ' . __( 'no menu', 'vacanam-language-switcher' ) : '' ) ) . '</option>';
			}
			echo '</select> <select name="display"><option value="dropdown">' . esc_html__( 'as a dropdown', 'vacanam-language-switcher' ) . '</option><option value="inline">' . esc_html__( 'side by side', 'vacanam-language-switcher' ) . '</option></select></p>';
		} else {
			echo '<p>' . esc_html__( 'Your theme has no menu locations. With Elementor, add the "Language switcher" widget to your header.', 'vacanam-language-switcher' ) . '</p>';
		}
		echo '<p><label><input type="checkbox" name="floating" value="1" ' . checked( ! $locations, true, false ) . ' /> ' . esc_html__( 'Show a floating switcher in a corner of every page', 'vacanam-language-switcher' ) . '</label></p>';
		echo '<div class="vacanam-preview-stage">' . Vacanam_Switcher::render( array( 'style' => 'dropdown' ) ) . ' ' . Vacanam_Switcher::render( array( 'style' => 'pills', 'names' => 'code' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render().
		echo '<p class="description">' . esc_html__( 'More styles, colours and places (Elementor, blocks, widgets) in Languages > Switcher.', 'vacanam-language-switcher' ) . '</p>';
		submit_button( __( 'Continue', 'vacanam-language-switcher' ), 'primary button-hero' );
		echo '</form>';
	}

	/**
	 * Step 5: ready.
	 */
	private static function step_ready() {
		echo '<h2>' . esc_html__( 'Your site is multilingual', 'vacanam-language-switcher' ) . '</h2><ul class="vacanam-ready">';
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			echo '<li>' . Vacanam_Admin::flag( $lang['flag'] ) . ' <a href="' . esc_url( Vacanam_Router::home_for( $slug ) ) . '" target="_blank" rel="noopener">' . esc_html( sprintf( /* translators: %s: language */ __( 'View your site in %s', 'vacanam-language-switcher' ), $lang['name'] ) ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag().
		}
		echo '</ul><h3>' . esc_html__( 'Next', 'vacanam-language-switcher' ) . '</h3><ol>';
		echo '<li>' . wp_kses_post( sprintf( /* translators: %s: link */ __( 'Open %s: the flags show each translation. Click the pencil to edit it (pages built with Elementor open in Elementor), change the text and publish.', 'vacanam-language-switcher' ), '<a href="' . esc_url( admin_url( 'edit.php?post_type=page' ) ) . '">' . esc_html__( 'Pages', 'vacanam-language-switcher' ) . '</a>' ) ) . '</li>';
		echo '<li>' . wp_kses_post( sprintf( /* translators: %s: link */ __( 'Translate the menu labels, the site title and widget texts in %s.', 'vacanam-language-switcher' ), '<a href="' . esc_url( admin_url( 'admin.php?page=vacanam-strings' ) ) . '">' . esc_html__( 'Languages > Texts', 'vacanam-language-switcher' ) . '</a>' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Give each translation its own SEO title and description in Yoast SEO or Rank Math: search engines show the right language to each visitor.', 'vacanam-language-switcher' ) . '</li></ol>';
		echo '<p><a class="button button-primary button-hero" href="' . esc_url( admin_url( 'admin.php?page=vacanam' ) ) . '">' . esc_html__( 'Go to Languages', 'vacanam-language-switcher' ) . '</a></p>';
	}

	/**
	 * Save a step.
	 */
	public static function save() {
		if ( ! current_user_can( Vacanam_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'vacanam-language-switcher' ), 403 );
		}
		check_admin_referer( 'vacanam_setup' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$next = 'languages';
		if ( 'languages' === $step ) {
			if ( ! Vacanam_Languages::all() ) {
				$main   = isset( $_POST['main'] ) ? sanitize_text_field( wp_unslash( $_POST['main'] ) ) : '';
				$result = Vacanam_Languages::add( array( 'locale' => $main ) );
				if ( is_wp_error( $result ) ) {
					wp_safe_redirect( add_query_arg( 'vacanam_err', rawurlencode( $result->get_error_message() ), self::url( 'languages' ) ) );
					exit;
				}
				Vacanam_Settings::update( array( 'default' => $result['slug'] ) );
			}
			$add = isset( $_POST['add'] ) ? array_filter( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['add'] ) ) ) : array();
			foreach ( array_unique( $add ) as $locale ) {
				if ( ! Vacanam_Languages::by_locale( $locale ) ) {
					Vacanam_Languages::add( array( 'locale' => $locale ) );
				}
			}
			$next = count( Vacanam_Languages::all() ) > 1 ? 'content' : 'languages';
			if ( 'languages' === $next ) {
				wp_safe_redirect( add_query_arg( 'vacanam_err', rawurlencode( __( 'Choose at least one other language.', 'vacanam-language-switcher' ) ), self::url( 'languages' ) ) );
				exit;
			}
		} elseif ( 'content' === $step ) {
			Vacanam_Settings::update(
				array(
					'media'   => empty( $_POST['media'] ) ? 'no' : 'yes',
					'browser' => empty( $_POST['browser'] ) ? 'no' : 'yes',
				)
			);
			Vacanam_Model::assign_all( Vacanam_Languages::default_slug() );
			$next = 'copy';
		} elseif ( 'switcher' === $step ) {
			if ( ! empty( $_POST['menu_switcher'] ) && ! empty( $_POST['location'] ) ) {
				self::add_menu_switcher( sanitize_key( wp_unslash( $_POST['location'] ) ), isset( $_POST['display'] ) && 'inline' === $_POST['display'] ? 'inline' : 'dropdown' );
			}
			$floating            = Vacanam_Switcher::floating_settings();
			$floating['enabled'] = empty( $_POST['floating'] ) ? 0 : 1;
			Vacanam_Settings::update( array( 'floating' => $floating ) );
			$next = 'ready';
		}
		// phpcs:enable
		wp_safe_redirect( self::url( $next ) );
		exit;
	}

	/**
	 * Add the switcher item to the menu of a location, and to its copies in other languages.
	 *
	 * @param string $location Location.
	 * @param string $display  dropdown or inline.
	 */
	public static function add_menu_switcher( $location, $display ) {
		$locations = get_nav_menu_locations();
		$menus     = array();
		if ( ! empty( $locations[ $location ] ) ) {
			$menus[] = (int) $locations[ $location ];
		}
		foreach ( Vacanam_Languages::slugs() as $slug ) {
			$menu = Vacanam_Menus::assigned( $location, $slug );
			if ( $menu ) {
				$menus[] = $menu;
			}
			if ( ! empty( $locations[ $location ] ) ) {
				$tr = Vacanam_Menus::translation( (int) $locations[ $location ], $slug );
				if ( $tr ) {
					$menus[] = $tr;
				}
			}
		}
		foreach ( array_unique( $menus ) as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
				if ( Vacanam_Menus::URL === $item->url ) {
					continue 2; // Already there.
				}
			}
			$item = wp_update_nav_menu_item(
				$menu,
				0,
				array(
					'menu-item-title'   => __( 'Languages', 'vacanam-language-switcher' ),
					'menu-item-url'     => Vacanam_Menus::URL,
					'menu-item-type'    => 'custom',
					'menu-item-classes' => 'vacanam-menu-switcher',
					'menu-item-status'  => 'publish',
				)
			);
			if ( ! is_wp_error( $item ) ) {
				update_post_meta(
					$item,
					Vacanam_Menus::META,
					array(
						'display'      => $display,
						'flags'        => 1,
						'names'        => 'dropdown' === $display ? 'name' : 'code',
						'hide_current' => 0,
					)
				);
			}
		}
	}
}
