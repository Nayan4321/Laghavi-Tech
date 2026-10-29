<?php
/**
 * Login page designer: templates, logo, background images, custom text and hidden parts
 * for the login, registration, forgot-password and reset-password screens.
 *
 * @package SaralaLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Custom login page design.
 */
class SLPP_Login_Design {

	const OPTION = 'slpp_login_design';
	const PAGE   = 'sarala-login-page-pro-design';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 23 );
		add_action( 'admin_post_slpp_login_design', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_post_slpp_preview_reset', array( __CLASS__, 'preview_reset' ) );

		if ( ! self::get( 'enabled' ) ) {
			return;
		}
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_filter( 'login_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'login_header', array( __CLASS__, 'open_layout' ) );
		add_action( 'login_footer', array( __CLASS__, 'close_layout' ), 1 );
		add_filter( 'login_message', array( __CLASS__, 'form_heading' ), 1 );
		add_filter( 'login_headerurl', array( __CLASS__, 'logo_url' ) );
		add_filter( 'login_display_language_dropdown', array( __CLASS__, 'show_language_switcher' ) );
		add_filter( 'the_privacy_policy_link', array( __CLASS__, 'privacy_link' ) );
		add_filter( 'register', array( __CLASS__, 'register_link' ) );
	}

	// ---------------------------------------------------------------- Settings.

	/**
	 * Templates.
	 *
	 * @return array<string, array> Label and default colors.
	 */
	public static function templates() {
		return array(
			'glass'    => array(
				'label'     => __( 'Glass', 'sarala-login-page-pro' ),
				'desc'      => __( 'Frosted card over a full-page background.', 'sarala-login-page-pro' ),
				'primary'   => '#1e3a8a',
				'secondary' => '#3b82f6',
				'page_bg'   => '#1d4ed8',
				'panel_bg'  => '#2563eb',
			),
			'split'    => array(
				'label'     => __( 'Split screen', 'sarala-login-page-pro' ),
				'desc'      => __( 'Brand panel on the left, form on the right.', 'sarala-login-page-pro' ),
				'primary'   => '#111827',
				'secondary' => '#3730a3',
				'page_bg'   => '#ffffff',
				'panel_bg'  => '#3730a3',
			),
			'wave'     => array(
				'label'     => __( 'Wave card', 'sarala-login-page-pro' ),
				'desc'      => __( 'Card with a curved brand panel and centred logo.', 'sarala-login-page-pro' ),
				'primary'   => '#1d6fe0',
				'secondary' => '#3ba7ff',
				'page_bg'   => '#e5e7eb',
				'panel_bg'  => '#1557c0',
			),
			'image'    => array(
				'label'     => __( 'Image card', 'sarala-login-page-pro' ),
				'desc'      => __( 'Form on the left, your picture on the right.', 'sarala-login-page-pro' ),
				'primary'   => '#e63946',
				'secondary' => '#e63946',
				'page_bg'   => '#d62828',
				'panel_bg'  => '#e5e5e5',
			),
			'gradient' => array(
				'label'     => __( 'Gradient', 'sarala-login-page-pro' ),
				'desc'      => __( 'Colourful gradient panel and rounded inputs with icons.', 'sarala-login-page-pro' ),
				'primary'   => '#6d5dfc',
				'secondary' => '#f472b6',
				'page_bg'   => '#7b61ff',
				'panel_bg'  => '#8b5cf6',
			),
		);
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'            => 0,
			'template'           => 'split',
			'primary'            => '',
			'secondary'          => '',
			'page_bg'            => '',
			'panel_bg'           => '',
			'panel_text'         => '#ffffff',
			'font'               => 'system',
			'corners'            => 'rounded',
			'logo'               => '',
			'logo_width'         => 140,
			'bg_image'           => '',
			'side_image'         => '',
			'overlay'            => 30,
			'headline'           => __( 'Welcome back', 'sarala-login-page-pro' ),
			'description'        => __( 'Sign in to manage your account, orders and subscriptions.', 'sarala-login-page-pro' ),
			'footer_text'        => '© {year} {site}. ' . __( 'All rights reserved.', 'sarala-login-page-pro' ),
			'login_title'        => __( 'Welcome back!', 'sarala-login-page-pro' ),
			'login_subtitle'     => __( 'Please enter your details to sign in.', 'sarala-login-page-pro' ),
			'register_title'     => __( 'Create your account', 'sarala-login-page-pro' ),
			'register_subtitle'  => __( 'It only takes a minute.', 'sarala-login-page-pro' ),
			'lost_title'         => __( 'Forgot your password?', 'sarala-login-page-pro' ),
			'lost_subtitle'      => __( 'Enter your email and we will send you a link to reset it.', 'sarala-login-page-pro' ),
			'reset_title'        => __( 'Set a new password', 'sarala-login-page-pro' ),
			'reset_subtitle'     => __( 'Choose a strong password you have not used before.', 'sarala-login-page-pro' ),
			'hide_logo'          => 0,
			'hide_headline'      => 0,
			'hide_description'   => 0,
			'hide_footer'        => 0,
			'hide_titles'        => 0,
			'hide_subtitles'     => 0,
			'hide_side_mobile'   => 0,
			'hide_remember'      => 0,
			'hide_lost'          => 0,
			'hide_register'      => 0,
			'hide_back'          => 0,
			'hide_privacy'       => 0,
			'hide_language'      => 1,
			'hide_social_divider' => 0,
		);
	}

	/**
	 * Read settings.
	 *
	 * @param string|null $key Key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$saved    = get_option( self::OPTION, array() );
		$settings = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		return null === $key ? $settings : ( isset( $settings[ $key ] ) ? $settings[ $key ] : null );
	}

	/**
	 * A color setting, falling back to the template's default.
	 *
	 * @param string $key Color key.
	 * @return string
	 */
	public static function color( $key ) {
		$value = (string) self::get( $key );
		if ( '' !== $value ) {
			return $value;
		}
		$templates = self::templates();
		$template  = self::get( 'template' );
		return isset( $templates[ $template ][ $key ] ) ? $templates[ $template ][ $key ] : '#2563eb';
	}

	/**
	 * Fonts on offer.
	 *
	 * @return array<string, string> Key => CSS font stack.
	 */
	public static function fonts() {
		return array(
			'system'     => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
			'Poppins'    => '"Poppins", sans-serif',
			'Inter'      => '"Inter", sans-serif',
			'Montserrat' => '"Montserrat", sans-serif',
			'DM Sans'    => '"DM Sans", sans-serif',
		);
	}

	// ---------------------------------------------------------------- Login screen.

	/**
	 * Which screen is showing: login, register, lost or reset.
	 *
	 * @return string
	 */
	public static function screen() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading which screen wp-login.php shows.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		if ( isset( $_GET['key'] ) && 'login' === $action ) {
			$action = 'resetpass';
		}
		// phpcs:enable
		switch ( $action ) {
			case 'register':
				return 'register';
			case 'lostpassword':
			case 'retrievepassword':
				return 'lost';
			case 'rp':
			case 'resetpass':
				return 'reset';
			default:
				return 'login';
		}
	}

	/**
	 * The "session expired" popup inside wp-admin keeps WordPress's own look.
	 *
	 * @return bool
	 */
	private static function is_interim() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		return isset( $_REQUEST['interim-login'] );
	}

	/**
	 * Replace {year} and {site} in a text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function fill( $text ) {
		return strtr(
			(string) $text,
			array(
				'{year}' => wp_date( 'Y' ),
				'{site}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			)
		);
	}

	/**
	 * Styles, fonts and colors.
	 */
	public static function assets() {
		if ( self::is_interim() ) {
			return;
		}
		$font = self::get( 'font' );
		if ( 'system' !== $font && isset( self::fonts()[ $font ] ) ) {
			wp_enqueue_style( 'slpp-login-font', 'https://fonts.googleapis.com/css2?family=' . rawurlencode( $font ) . ':wght@400;500;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts URL.
		}
		wp_enqueue_style( 'slpp-login-design', SLPP_URL . 'assets/css/login-design.css', array( 'login' ), SLPP_VERSION );
		wp_add_inline_style( 'slpp-login-design', self::css_variables() );
		wp_enqueue_script( 'slpp-login-design', SLPP_URL . 'assets/js/login-design.js', array(), SLPP_VERSION, true );
		wp_localize_script(
			'slpp-login-design',
			'slppLogin',
			array(
				'show' => __( 'Show password', 'sarala-login-page-pro' ),
				'hide' => __( 'Hide password', 'sarala-login-page-pro' ),
			)
		);
	}

	/**
	 * CSS custom properties from the settings.
	 *
	 * @return string
	 */
	public static function css_variables() {
		$radius = array(
			'square'  => '4px',
			'rounded' => '10px',
			'pill'    => '999px',
		);
		$corner = isset( $radius[ self::get( 'corners' ) ] ) ? $radius[ self::get( 'corners' ) ] : '10px';
		$vars   = array(
			'--slpp-primary'    => sanitize_hex_color( self::color( 'primary' ) ),
			'--slpp-secondary'  => sanitize_hex_color( self::color( 'secondary' ) ),
			'--slpp-page-bg'    => sanitize_hex_color( self::color( 'page_bg' ) ),
			'--slpp-panel-bg'   => sanitize_hex_color( self::color( 'panel_bg' ) ),
			'--slpp-panel-text' => sanitize_hex_color( self::get( 'panel_text' ) ) ? sanitize_hex_color( self::get( 'panel_text' ) ) : '#ffffff',
			'--slpp-font'       => self::fonts()[ isset( self::fonts()[ self::get( 'font' ) ] ) ? self::get( 'font' ) : 'system' ],
			'--slpp-radius'     => $corner,
			'--slpp-card-radius' => 'square' === self::get( 'corners' ) ? '6px' : '20px',
			'--slpp-overlay'    => (string) ( max( 0, min( 90, (int) self::get( 'overlay' ) ) ) / 100 ),
			'--slpp-logo-width' => max( 40, min( 400, (int) self::get( 'logo_width' ) ) ) . 'px',
		);
		$bg   = esc_url_raw( (string) self::get( 'bg_image' ) );
		$side = esc_url_raw( (string) self::get( 'side_image' ) );
		if ( '' !== $bg ) {
			$vars['--slpp-bg-image'] = 'url("' . $bg . '")';
		}
		if ( '' !== $side ) {
			$vars['--slpp-side-image'] = 'url("' . $side . '")';
		}
		$css = 'body.login.slpp-design{';
		foreach ( $vars as $name => $value ) {
			if ( null !== $value && false !== $value && '' !== $value ) {
				$css .= $name . ':' . str_replace( array( '<', '>', '{', '}', ';' ), '', $value ) . ';';
			}
		}
		$css .= '}';
		return $css;
	}

	/**
	 * Body classes for the chosen template and options.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( self::is_interim() ) {
			return $classes;
		}
		$classes[] = 'slpp-design';
		$classes[] = 'slpp-tpl-' . sanitize_html_class( self::get( 'template' ) );
		$classes[] = 'slpp-screen-' . self::screen();
		foreach ( array( 'hide_side_mobile', 'hide_remember', 'hide_lost', 'hide_register', 'hide_back', 'hide_social_divider' ) as $flag ) {
			if ( self::get( $flag ) ) {
				$classes[] = 'slpp-' . str_replace( '_', '-', $flag );
			}
		}
		if ( self::get( 'bg_image' ) ) {
			$classes[] = 'slpp-has-bg';
		}
		if ( self::get( 'side_image' ) ) {
			$classes[] = 'slpp-has-side';
		}
		return $classes;
	}

	/**
	 * Logo HTML.
	 *
	 * @param string $where brand or form.
	 * @return string
	 */
	private static function logo( $where ) {
		if ( self::get( 'hide_logo' ) ) {
			return '';
		}
		$logo = (string) self::get( 'logo' );
		$name = get_bloginfo( 'name' );
		$html = '<a class="slpp-logo slpp-logo--' . esc_attr( $where ) . '" href="' . esc_url( home_url( '/' ) ) . '">';
		$html .= '' !== $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '" />'
			: '<span class="slpp-logo__text">' . esc_html( $name ) . '</span>';
		return $html . '</a>';
	}

	/**
	 * Where the logo sits in each template.
	 *
	 * @return string brand or form.
	 */
	private static function logo_place() {
		return in_array( self::get( 'template' ), array( 'split', 'wave' ), true ) ? 'brand' : 'form';
	}

	/**
	 * Open the template's layout around WordPress's login box.
	 */
	public static function open_layout() {
		if ( self::is_interim() ) {
			return;
		}
		$template = self::get( 'template' );
		echo '<div class="slpp-lp slpp-lp--' . esc_attr( $template ) . '"><div class="slpp-lp__card">';

		if ( 'glass' !== $template ) {
			echo '<aside class="slpp-lp__brand">';
			echo '<div class="slpp-lp__brand-inner">';
			if ( 'brand' === self::logo_place() ) {
				echo self::logo( 'brand' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in logo().
			}
			if ( 'image' !== $template ) {
				if ( ! self::get( 'hide_headline' ) && '' !== trim( self::get( 'headline' ) ) ) {
					echo '<h2 class="slpp-lp__headline">' . esc_html( self::fill( self::get( 'headline' ) ) ) . '</h2>';
				}
				if ( ! self::get( 'hide_description' ) && '' !== trim( self::get( 'description' ) ) ) {
					echo '<p class="slpp-lp__description">' . nl2br( esc_html( self::fill( self::get( 'description' ) ) ) ) . '</p>';
				}
			}
			echo '</div>';
			if ( ! self::get( 'hide_footer' ) && '' !== trim( self::get( 'footer_text' ) ) && 'image' !== $template ) {
				echo '<p class="slpp-lp__footer">' . esc_html( self::fill( self::get( 'footer_text' ) ) ) . '</p>';
			}
			if ( 'gradient' === $template ) {
				echo '<span class="slpp-shape slpp-shape--1"></span><span class="slpp-shape slpp-shape--2"></span><span class="slpp-shape slpp-shape--3"></span><span class="slpp-shape slpp-shape--4"></span>';
			}
			echo '</aside>';
		}
		echo '<div class="slpp-lp__form">';
	}

	/**
	 * Close the layout (runs before WordPress closes the page).
	 */
	public static function close_layout() {
		if ( self::is_interim() ) {
			return;
		}
		echo '</div>';
		if ( 'glass' === self::get( 'template' ) && ! self::get( 'hide_footer' ) && '' !== trim( self::get( 'footer_text' ) ) ) {
			echo '<p class="slpp-lp__footer">' . esc_html( self::fill( self::get( 'footer_text' ) ) ) . '</p>';
		}
		echo '</div></div>';
	}

	/**
	 * Logo, title and subtitle above the form, matching the current screen.
	 *
	 * @param string $message Existing login message.
	 * @return string
	 */
	public static function form_heading( $message ) {
		if ( self::is_interim() ) {
			return $message;
		}
		$screen = self::screen();
		$html   = '<div class="slpp-form-head">';
		if ( 'form' === self::logo_place() ) {
			$html .= self::logo( 'form' );
		}
		$title    = (string) self::get( $screen . '_title' );
		$subtitle = (string) self::get( $screen . '_subtitle' );
		if ( ! self::get( 'hide_titles' ) && '' !== trim( $title ) ) {
			$html .= '<h2 class="slpp-form-title">' . esc_html( self::fill( $title ) ) . '</h2>';
		}
		if ( ! self::get( 'hide_subtitles' ) && '' !== trim( $subtitle ) ) {
			$html .= '<p class="slpp-form-subtitle">' . esc_html( self::fill( $subtitle ) ) . '</p>';
		}
		$html .= '</div>';

		// WordPress's own "Enter your username or email…" note on the forgot-password screen repeats our subtitle.
		if ( 'lost' === $screen && ! self::get( 'hide_subtitles' ) && '' !== trim( $subtitle ) ) {
			$message = preg_replace( '#<div[^>]*class="[^"]*\bmessage\b[^"]*"[^>]*>\s*<p>.*?</p>\s*</div>#s', '', (string) $message );
			$message = preg_replace( '#<p class="message">.*?</p>#s', '', (string) $message );
		}
		return $html . $message;
	}

	/**
	 * The WordPress logo link goes to the site.
	 *
	 * @return string
	 */
	public static function logo_url() {
		return home_url( '/' );
	}

	/**
	 * Language switcher under the form.
	 *
	 * @param bool $show Show it.
	 * @return bool
	 */
	public static function show_language_switcher( $show ) {
		return self::get( 'hide_language' ) ? false : $show;
	}

	/**
	 * Privacy policy link under the form.
	 *
	 * @param string $link Link HTML.
	 * @return string
	 */
	public static function privacy_link( $link ) {
		return self::get( 'hide_privacy' ) && did_action( 'login_init' ) ? '' : $link;
	}

	/**
	 * "Register" link under the login form.
	 *
	 * @param string $link Link HTML.
	 * @return string
	 */
	public static function register_link( $link ) {
		return self::get( 'hide_register' ) && did_action( 'login_init' ) ? '' : $link;
	}

	// ---------------------------------------------------------------- Admin screen.

	/**
	 * "Login page design" submenu.
	 */
	public static function menu() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		add_submenu_page(
			'sarala-login-page',
			__( 'Login page design', 'sarala-login-page-pro' ),
			__( 'Login page design', 'sarala-login-page-pro' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Media picker and color pickers.
	 *
	 * @param string $hook Screen.
	 */
	public static function admin_assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'slpp-login-design-admin', SLPP_URL . 'assets/js/login-design-admin.js', array( 'jquery', 'wp-color-picker' ), SLPP_VERSION, true );
		wp_localize_script(
			'slpp-login-design-admin',
			'slppDesign',
			array(
				'choose'    => __( 'Choose image', 'sarala-login-page-pro' ),
				'use'       => __( 'Use this image', 'sarala-login-page-pro' ),
				'templates' => self::templates(),
			)
		);
	}

	/**
	 * Save the settings.
	 */
	public static function save() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			wp_die( esc_html__( 'Only the super admin can do this.', 'sarala-login-page-pro' ) );
		}
		check_admin_referer( 'slpp_login_design' );

		$in    = isset( $_POST['design'] ) && is_array( $_POST['design'] ) ? wp_unslash( $_POST['design'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized below.
		$clean = array();
		foreach ( self::defaults() as $key => $default ) {
			$value = isset( $in[ $key ] ) ? $in[ $key ] : '';
			if ( 0 === strpos( $key, 'hide_' ) || 'enabled' === $key ) {
				$clean[ $key ] = empty( $value ) ? 0 : 1;
			} elseif ( in_array( $key, array( 'primary', 'secondary', 'page_bg', 'panel_bg', 'panel_text' ), true ) ) {
				$clean[ $key ] = (string) sanitize_hex_color( $value );
			} elseif ( in_array( $key, array( 'logo', 'bg_image', 'side_image' ), true ) ) {
				$clean[ $key ] = esc_url_raw( $value );
			} elseif ( 'logo_width' === $key ) {
				$clean[ $key ] = max( 40, min( 400, absint( $value ) ) );
			} elseif ( 'overlay' === $key ) {
				$clean[ $key ] = max( 0, min( 90, absint( $value ) ) );
			} elseif ( 'template' === $key ) {
				$clean[ $key ] = isset( self::templates()[ $value ] ) ? $value : 'split';
			} elseif ( 'font' === $key ) {
				$clean[ $key ] = isset( self::fonts()[ $value ] ) ? $value : 'system';
			} elseif ( 'corners' === $key ) {
				$clean[ $key ] = in_array( $value, array( 'square', 'rounded', 'pill' ), true ) ? $value : 'rounded';
			} elseif ( 'description' === $key ) {
				$clean[ $key ] = sanitize_textarea_field( $value );
			} else {
				$clean[ $key ] = sanitize_text_field( $value );
			}
		}
		update_option( self::OPTION, $clean );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&saved=1' ) );
		exit;
	}

	/**
	 * Open the reset-password screen for previewing. WordPress only shows it with a valid key,
	 * so a fresh key is made for the current admin (it is not emailed and changes nothing until used).
	 */
	public static function preview_reset() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			wp_die( esc_html__( 'Only the super admin can do this.', 'sarala-login-page-pro' ) );
		}
		check_admin_referer( 'slpp_preview_reset' );
		$user = wp_get_current_user();
		$key  = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			wp_die( esc_html( $key->get_error_message() ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'action' => 'rp',
					'key'    => $key,
					'login'  => rawurlencode( $user->user_login ),
				),
				wp_login_url()
			)
		);
		exit;
	}

	/**
	 * Text input row.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @param bool   $area  Textarea.
	 */
	private static function text_row( $key, $label, $help = '', $area = false ) {
		$value = self::get( $key );
		echo '<tr><th scope="row"><label for="slpp-d-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( $area ) {
			echo '<textarea id="slpp-d-' . esc_attr( $key ) . '" name="design[' . esc_attr( $key ) . ']" rows="3" class="large-text">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input type="text" id="slpp-d-' . esc_attr( $key ) . '" name="design[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="regular-text" />';
		}
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Image picker row.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 */
	private static function image_row( $key, $label, $help = '' ) {
		$value = (string) self::get( $key );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<div class="slpp-image-field">
					<span class="slpp-image-field__preview"<?php echo '' === $value ? ' hidden' : ''; ?>><img src="<?php echo esc_url( $value ); ?>" alt="" /></span>
					<input type="url" name="design[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="regular-text slpp-image-field__url" placeholder="https://" />
					<button type="button" class="button slpp-image-field__choose"><?php esc_html_e( 'Choose image', 'sarala-login-page-pro' ); ?></button>
					<button type="button" class="button-link slpp-image-field__clear"><?php esc_html_e( 'Remove', 'sarala-login-page-pro' ); ?></button>
				</div>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Color row.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 */
	private static function color_row( $key, $label ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><input type="text" class="slpp-color" data-key="' . esc_attr( $key ) . '" name="design[' . esc_attr( $key ) . ']" value="' . esc_attr( self::get( $key ) ) . '" placeholder="' . esc_attr( self::color( $key ) ) . '" /></td></tr>';
	}

	/**
	 * Checkbox.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 */
	private static function check( $key, $label ) {
		printf(
			'<label class="saralopa-check"><input type="checkbox" name="design[%1$s]" value="1" %2$s /> %3$s</label>',
			esc_attr( $key ),
			checked( 1, (int) self::get( $key ), false ),
			esc_html( $label )
		);
	}

	/**
	 * The design page.
	 */
	public static function page() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		$current = self::get( 'template' );
		$login   = wp_login_url();
		?>
		<div class="wrap saralopa-admin slpp-design">
			<h1><?php esc_html_e( 'Login page design', 'sarala-login-page-pro' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Applies to the login, registration, forgot-password and reset-password screens (including your custom login address).', 'sarala-login-page-pro' ); ?></p>
			<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved. The previews below show the new design.', 'sarala-login-page-pro' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="slpp_login_design" />
				<?php wp_nonce_field( 'slpp_login_design' ); ?>

				<div class="saralopa-card">
					<?php self::check( 'enabled', __( 'Use a custom design for the login page', 'sarala-login-page-pro' ) ); ?>
				</div>

				<div class="saralopa-card">
					<h2><?php esc_html_e( 'Template', 'sarala-login-page-pro' ); ?></h2>
					<div class="slpp-templates">
						<?php foreach ( self::templates() as $key => $template ) : ?>
							<label class="slpp-template<?php echo $current === $key ? ' is-selected' : ''; ?>">
								<input type="radio" name="design[template]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $current, $key ); ?> />
								<span class="slpp-mini slpp-mini--<?php echo esc_attr( $key ); ?>" style="--a:<?php echo esc_attr( $template['panel_bg'] ); ?>;--b:<?php echo esc_attr( $template['secondary'] ); ?>;--p:<?php echo esc_attr( $template['page_bg'] ); ?>;--c:<?php echo esc_attr( $template['primary'] ); ?>">
									<span class="slpp-mini__brand"></span>
									<span class="slpp-mini__form"><i></i><i></i><i></i><b></b></span>
								</span>
								<strong><?php echo esc_html( $template['label'] ); ?></strong>
								<span class="description"><?php echo esc_html( $template['desc'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="saralopa-card">
					<h2><?php esc_html_e( 'Logo and images', 'sarala-login-page-pro' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php self::image_row( 'logo', __( 'Logo', 'sarala-login-page-pro' ), __( 'PNG or SVG with a transparent background works best. Without a logo, the site name is shown.', 'sarala-login-page-pro' ) ); ?>
						<tr>
							<th scope="row"><label for="slpp-d-logo_width"><?php esc_html_e( 'Logo width', 'sarala-login-page-pro' ); ?></label></th>
							<td><input type="number" id="slpp-d-logo_width" name="design[logo_width]" min="40" max="400" class="small-text" value="<?php echo esc_attr( self::get( 'logo_width' ) ); ?>" /> px</td>
						</tr>
						<?php self::image_row( 'bg_image', __( 'Page background image', 'sarala-login-page-pro' ), __( 'Fills the whole screen behind the card (Glass, Wave card, Image card, Gradient) or the brand panel (Split screen).', 'sarala-login-page-pro' ) ); ?>
						<?php self::image_row( 'side_image', __( 'Panel image', 'sarala-login-page-pro' ), __( 'Shown in the brand panel (Split screen, Wave card, Gradient) or as the picture of the Image card template.', 'sarala-login-page-pro' ) ); ?>
						<tr>
							<th scope="row"><label for="slpp-d-overlay"><?php esc_html_e( 'Darken images', 'sarala-login-page-pro' ); ?></label></th>
							<td><input type="range" id="slpp-d-overlay" name="design[overlay]" min="0" max="90" step="5" value="<?php echo esc_attr( self::get( 'overlay' ) ); ?>" oninput="this.nextElementSibling.textContent=this.value+'%'" /> <span><?php echo esc_html( self::get( 'overlay' ) ); ?>%</span>
							<p class="description"><?php esc_html_e( 'Keeps white text readable on top of photos.', 'sarala-login-page-pro' ); ?></p></td>
						</tr>
					</table>
				</div>

				<div class="saralopa-card">
					<h2><?php esc_html_e( 'Colours and style', 'sarala-login-page-pro' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Leave a colour empty to use the template\'s own colour.', 'sarala-login-page-pro' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						self::color_row( 'primary', __( 'Button and links', 'sarala-login-page-pro' ) );
						self::color_row( 'secondary', __( 'Second colour (gradients)', 'sarala-login-page-pro' ) );
						self::color_row( 'page_bg', __( 'Page background', 'sarala-login-page-pro' ) );
						self::color_row( 'panel_bg', __( 'Brand panel', 'sarala-login-page-pro' ) );
						self::color_row( 'panel_text', __( 'Text on the brand panel', 'sarala-login-page-pro' ) );
						?>
						<tr>
							<th scope="row"><label for="slpp-d-font"><?php esc_html_e( 'Font', 'sarala-login-page-pro' ); ?></label></th>
							<td>
								<select id="slpp-d-font" name="design[font]">
									<?php foreach ( array_keys( self::fonts() ) as $font ) : ?>
										<option value="<?php echo esc_attr( $font ); ?>" <?php selected( self::get( 'font' ), $font ); ?>><?php echo esc_html( 'system' === $font ? __( 'System font (fastest)', 'sarala-login-page-pro' ) : $font . ' (Google Fonts)' ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="slpp-d-corners"><?php esc_html_e( 'Corners', 'sarala-login-page-pro' ); ?></label></th>
							<td>
								<select id="slpp-d-corners" name="design[corners]">
									<option value="square" <?php selected( self::get( 'corners' ), 'square' ); ?>><?php esc_html_e( 'Square', 'sarala-login-page-pro' ); ?></option>
									<option value="rounded" <?php selected( self::get( 'corners' ), 'rounded' ); ?>><?php esc_html_e( 'Rounded', 'sarala-login-page-pro' ); ?></option>
									<option value="pill" <?php selected( self::get( 'corners' ), 'pill' ); ?>><?php esc_html_e( 'Pill', 'sarala-login-page-pro' ); ?></option>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<div class="saralopa-card">
					<h2><?php esc_html_e( 'Text', 'sarala-login-page-pro' ); ?></h2>
					<p class="description"><?php esc_html_e( '{year} and {site} are replaced with the current year and your site name.', 'sarala-login-page-pro' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						self::text_row( 'headline', __( 'Panel headline', 'sarala-login-page-pro' ) );
						self::text_row( 'description', __( 'Panel text', 'sarala-login-page-pro' ), '', true );
						self::text_row( 'footer_text', __( 'Footer text', 'sarala-login-page-pro' ) );
						self::text_row( 'login_title', __( 'Login: title', 'sarala-login-page-pro' ) );
						self::text_row( 'login_subtitle', __( 'Login: subtitle', 'sarala-login-page-pro' ) );
						self::text_row( 'register_title', __( 'Register: title', 'sarala-login-page-pro' ) );
						self::text_row( 'register_subtitle', __( 'Register: subtitle', 'sarala-login-page-pro' ) );
						self::text_row( 'lost_title', __( 'Forgot password: title', 'sarala-login-page-pro' ) );
						self::text_row( 'lost_subtitle', __( 'Forgot password: subtitle', 'sarala-login-page-pro' ) );
						self::text_row( 'reset_title', __( 'Reset password: title', 'sarala-login-page-pro' ) );
						self::text_row( 'reset_subtitle', __( 'Reset password: subtitle', 'sarala-login-page-pro' ) );
						?>
					</table>
				</div>

				<div class="saralopa-card">
					<h2><?php esc_html_e( 'Hide', 'sarala-login-page-pro' ); ?></h2>
					<div class="slpp-grid">
						<?php
						self::check( 'hide_logo', __( 'Logo', 'sarala-login-page-pro' ) );
						self::check( 'hide_headline', __( 'Panel headline', 'sarala-login-page-pro' ) );
						self::check( 'hide_description', __( 'Panel text', 'sarala-login-page-pro' ) );
						self::check( 'hide_footer', __( 'Footer text', 'sarala-login-page-pro' ) );
						self::check( 'hide_titles', __( 'Form titles', 'sarala-login-page-pro' ) );
						self::check( 'hide_subtitles', __( 'Form subtitles', 'sarala-login-page-pro' ) );
						self::check( 'hide_remember', __( '"Remember me"', 'sarala-login-page-pro' ) );
						self::check( 'hide_lost', __( '"Lost your password?" link', 'sarala-login-page-pro' ) );
						self::check( 'hide_register', __( '"Register" link', 'sarala-login-page-pro' ) );
						self::check( 'hide_back', __( '"← Go to site" link', 'sarala-login-page-pro' ) );
						self::check( 'hide_privacy', __( 'Privacy policy link', 'sarala-login-page-pro' ) );
						self::check( 'hide_language', __( 'Language switcher', 'sarala-login-page-pro' ) );
						self::check( 'hide_social_divider', __( '"or" divider above social buttons', 'sarala-login-page-pro' ) );
						self::check( 'hide_side_mobile', __( 'Brand panel / picture on phones', 'sarala-login-page-pro' ) );
						?>
					</div>
				</div>

				<?php submit_button( __( 'Save design', 'sarala-login-page-pro' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Preview', 'sarala-login-page-pro' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Save to update. Click the links to switch screens.', 'sarala-login-page-pro' ); ?>
				<a href="#" class="slpp-preview-link" data-url="<?php echo esc_url( $login ); ?>"><?php esc_html_e( 'Login', 'sarala-login-page-pro' ); ?></a> ·
				<a href="#" class="slpp-preview-link" data-url="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot password', 'sarala-login-page-pro' ); ?></a> ·
				<a href="#" class="slpp-preview-link" data-url="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=slpp_preview_reset' ), 'slpp_preview_reset' ) ); ?>"><?php esc_html_e( 'Reset password', 'sarala-login-page-pro' ); ?></a>
				<?php if ( get_option( 'users_can_register' ) ) : ?>
					· <a href="#" class="slpp-preview-link" data-url="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'Register', 'sarala-login-page-pro' ); ?></a>
				<?php endif; ?>
				· <a href="<?php echo esc_url( $login ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in a new tab', 'sarala-login-page-pro' ); ?></a>
			</p>
			<div class="slpp-previews">
				<div class="slpp-preview slpp-preview--desktop"><span><?php esc_html_e( 'Desktop', 'sarala-login-page-pro' ); ?></span><iframe src="<?php echo esc_url( add_query_arg( 'slpp_preview', 1, $login ) ); ?>" title="<?php esc_attr_e( 'Desktop preview', 'sarala-login-page-pro' ); ?>" loading="lazy"></iframe></div>
				<div class="slpp-preview slpp-preview--mobile"><span><?php esc_html_e( 'Phone', 'sarala-login-page-pro' ); ?></span><iframe src="<?php echo esc_url( add_query_arg( 'slpp_preview', 1, $login ) ); ?>" title="<?php esc_attr_e( 'Phone preview', 'sarala-login-page-pro' ); ?>" loading="lazy"></iframe></div>
			</div>
		</div>
		<?php
	}
}
