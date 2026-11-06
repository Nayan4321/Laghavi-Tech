<?php
/**
 * Login widget: an AJAX login box for sidebars, footers and pages (classic widget and shortcode).
 *
 * @package SaralaLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rendering, AJAX login and assets for the login box.
 */
class SLPP_Login_Box {

	/**
	 * Whether the stylesheet and script are needed on this page.
	 *
	 * @var bool
	 */
	private static $used = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );
		add_shortcode( 'sarala_login_widget', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_ajax_nopriv_slpp_lw_login', array( __CLASS__, 'ajax_login' ) );
		add_action( 'wp_ajax_slpp_lw_login', array( __CLASS__, 'ajax_login' ) );
		add_action( 'wp_ajax_nopriv_slpp_lw_fresh', array( __CLASS__, 'ajax_fresh' ) );
		add_action( 'wp_ajax_slpp_lw_fresh', array( __CLASS__, 'ajax_fresh' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register the classic widget.
	 */
	public static function register_widget() {
		register_widget( 'SLPP_Login_Widget' );
	}

	/**
	 * Register (not enqueue) the assets; they load only where a login box is shown.
	 */
	public static function register_assets() {
		wp_register_style( 'slpp-login-widget', SLPP_URL . 'assets/css/login-widget.css', array(), SLPP_VERSION );
		wp_register_script( 'slpp-login-widget', SLPP_URL . 'assets/js/login-widget.js', array(), SLPP_VERSION, true );
		wp_localize_script(
			'slpp-login-widget',
			'slppLoginWidget',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'checking' => __( 'Logging in…', 'sarala-login-page-pro' ),
				'failed'   => __( 'Something went wrong. Please try again.', 'sarala-login-page-pro' ),
			)
		);
	}

	/**
	 * Default settings of a login box.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'title'          => __( 'Log in', 'sarala-login-page-pro' ),
			'social'         => 1,
			'remember'       => 1,
			'links'          => 1,
			'after_login'    => 'rules',
			'redirect'       => '',
			'logged_in_html' => self::default_template(),
			'css'            => '',
		);
	}

	/**
	 * Default HTML shown to logged-in users.
	 *
	 * @return string
	 */
	public static function default_template() {
		return '<div class="slpp-lw__user">{avatar}<div class="slpp-lw__hello"><strong>' .
			/* translators: {display_name} is replaced with the user's name; keep it as is. */
			esc_html__( 'Hello, {display_name}', 'sarala-login-page-pro' ) . '</strong><br /><a href="{profile_url}">' .
			esc_html__( 'Your profile', 'sarala-login-page-pro' ) . '</a> &middot; <a href="{logout_url}">' .
			esc_html__( 'Log out', 'sarala-login-page-pro' ) . '</a></div></div>';
	}

	/**
	 * The address of the page being viewed.
	 *
	 * @return string
	 */
	private static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $uri );
	}

	/**
	 * Fill the logged-in template's placeholders.
	 *
	 * @param string  $template Template HTML.
	 * @param WP_User $user     User.
	 * @return string
	 */
	public static function fill_template( $template, $user ) {
		$account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : get_edit_profile_url( $user->ID );
		$map     = array(
			'{avatar}'       => get_avatar( $user->ID, 48, '', '', array( 'class' => 'slpp-lw__avatar' ) ),
			'{display_name}' => esc_html( $user->display_name ),
			'{first_name}'   => esc_html( '' !== $user->first_name ? $user->first_name : $user->display_name ),
			'{user_login}'   => esc_html( $user->user_login ),
			'{email}'        => esc_html( $user->user_email ),
			'{profile_url}'  => esc_url( get_edit_profile_url( $user->ID ) ),
			'{account_url}'  => esc_url( $account ),
			'{admin_url}'    => esc_url( admin_url() ),
			'{logout_url}'   => esc_url( wp_logout_url( self::current_url() ) ),
		);
		return strtr( wp_kses_post( $template ), $map );
	}

	/**
	 * Build a login box.
	 *
	 * @param array $args Settings (see defaults()).
	 * @return string HTML.
	 */
	public static function render( $args ) {
		$args = wp_parse_args( $args, self::defaults() );
		wp_enqueue_style( 'slpp-login-widget' );
		$css = trim( wp_strip_all_tags( (string) $args['css'] ) );
		if ( '' !== $css ) {
			wp_add_inline_style( 'slpp-login-widget', $css );
		}

		if ( is_user_logged_in() ) {
			$template = '' !== trim( (string) $args['logged_in_html'] ) ? $args['logged_in_html'] : self::default_template();
			return '<div class="slpp-lw slpp-lw--in">' . self::fill_template( $template, wp_get_current_user() ) . '</div>';
		}

		wp_enqueue_script( 'slpp-login-widget' );
		$uid      = 'slpp-lw-' . wp_rand( 1000, 999999 );
		$redirect = 'custom' === $args['after_login'] && '' !== $args['redirect'] ? $args['redirect'] : '';
		$captcha  = class_exists( 'SARALOPA_Captcha' ) && SARALOPA_Settings::get( 'captcha_login' );

		ob_start();
		?>
		<div class="slpp-lw slpp-lw--out">
			<form class="slpp-lw__form" method="post" action="<?php echo esc_url( wp_login_url( self::current_url() ) ); ?>" novalidate>
				<div class="slpp-lw__message" role="alert" aria-live="polite" hidden></div>
				<p class="slpp-lw__row">
					<label for="<?php echo esc_attr( $uid ); ?>-log"><?php esc_html_e( 'Username or email', 'sarala-login-page-pro' ); ?></label>
					<input type="text" name="log" id="<?php echo esc_attr( $uid ); ?>-log" autocomplete="username" required />
				</p>
				<p class="slpp-lw__row">
					<label for="<?php echo esc_attr( $uid ); ?>-pwd"><?php esc_html_e( 'Password', 'sarala-login-page-pro' ); ?></label>
					<input type="password" name="pwd" id="<?php echo esc_attr( $uid ); ?>-pwd" autocomplete="current-password" required />
				</p>
				<?php if ( $captcha ) : ?>
					<?php list( $question, $token ) = SARALOPA_Captcha::new_question(); ?>
					<p class="slpp-lw__row slpp-lw__captcha">
						<label for="<?php echo esc_attr( $uid ); ?>-captcha"><?php esc_html_e( 'Security question:', 'sarala-login-page-pro' ); ?> <span class="slpp-lw__question"><?php echo esc_html( $question ); ?></span></label>
						<input type="text" name="saralopa_captcha_answer" id="<?php echo esc_attr( $uid ); ?>-captcha" inputmode="numeric" pattern="[0-9]*" autocomplete="off" required />
						<input type="hidden" name="saralopa_captcha_token" value="<?php echo esc_attr( $token ); ?>" />
					</p>
				<?php endif; ?>
				<?php if ( $args['remember'] ) : ?>
					<p class="slpp-lw__remember"><label><input type="checkbox" name="rememberme" value="forever" /> <?php esc_html_e( 'Remember me', 'sarala-login-page-pro' ); ?></label></p>
				<?php endif; ?>
				<input type="hidden" name="action" value="slpp_lw_login" />
				<input type="hidden" name="slpp_lw_nonce" value="<?php echo esc_attr( wp_create_nonce( 'slpp_lw_login' ) ); ?>" />
				<input type="hidden" name="slpp_lw_page" value="<?php echo esc_attr( self::current_url() ); ?>" />
				<input type="hidden" name="slpp_lw_redirect" value="<?php echo esc_attr( $redirect ); ?>" />
				<input type="hidden" name="slpp_lw_mode" value="<?php echo esc_attr( $args['after_login'] ); ?>" />
				<p class="slpp-lw__submit"><button type="submit" class="slpp-lw__button"><?php esc_html_e( 'Log in', 'sarala-login-page-pro' ); ?></button></p>
				<?php if ( $args['links'] ) : ?>
					<p class="slpp-lw__links">
						<a href="<?php echo esc_url( wp_lostpassword_url( self::current_url() ) ); ?>"><?php esc_html_e( 'Lost your password?', 'sarala-login-page-pro' ); ?></a>
						<?php if ( get_option( 'users_can_register' ) ) : ?>
							&middot; <a href="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'Register', 'sarala-login-page-pro' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</form>
			<?php
			if ( $args['social'] && class_exists( 'SARALOPA_Buttons' ) ) {
				SARALOPA_Buttons::output( SARALOPA_Buttons::render( '' !== $redirect ? $redirect : self::current_url(), 'slpp-lw__social' ) );
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * [sarala_login_widget title="Log in" social="yes" remember="yes" links="yes" redirect=""]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'    => '',
				'social'   => 'yes',
				'remember' => 'yes',
				'links'    => 'yes',
				'redirect' => '',
			),
			$atts,
			'sarala_login_widget'
		);
		$yes  = static function ( $value ) {
			return in_array( strtolower( (string) $value ), array( 'yes', '1', 'true', 'on' ), true ) ? 1 : 0;
		};
		$html = self::render(
			array(
				'social'      => $yes( $atts['social'] ),
				'remember'    => $yes( $atts['remember'] ),
				'links'       => $yes( $atts['links'] ),
				'after_login' => '' !== $atts['redirect'] ? 'custom' : 'rules',
				'redirect'    => esc_url_raw( $atts['redirect'] ),
			)
		);
		if ( '' !== $atts['title'] ) {
			$html = '<h3 class="slpp-lw__title">' . esc_html( $atts['title'] ) . '</h3>' . $html;
		}
		return $html;
	}

	/**
	 * A fresh nonce and security question, for pages served from a cache.
	 */
	public static function ajax_fresh() {
		$data = array( 'nonce' => wp_create_nonce( 'slpp_lw_login' ) );
		if ( class_exists( 'SARALOPA_Captcha' ) && SARALOPA_Settings::get( 'captcha_login' ) ) {
			list( $data['question'], $data['token'] ) = SARALOPA_Captcha::new_question();
		}
		nocache_headers();
		wp_send_json_success( $data );
	}

	/**
	 * Error reply, with a new security question so the visitor can try again.
	 *
	 * @param string $message HTML message.
	 */
	private static function fail( $message ) {
		$data = array( 'message' => wp_kses_post( $message ) );
		if ( class_exists( 'SARALOPA_Captcha' ) && SARALOPA_Settings::get( 'captcha_login' ) ) {
			list( $data['question'], $data['token'] ) = SARALOPA_Captcha::new_question();
		}
		wp_send_json_error( $data );
	}

	/**
	 * Log in through AJAX.
	 */
	public static function ajax_login() {
		nocache_headers();
		if ( ! check_ajax_referer( 'slpp_lw_login', 'slpp_lw_nonce', false ) ) {
			self::fail( __( 'Your session has expired. Please try again.', 'sarala-login-page-pro' ) );
		}
		if ( is_user_logged_in() ) {
			wp_send_json_success( array( 'redirect' => home_url( '/' ) ) );
		}

		$login    = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '';
		$password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be changed.
		$remember = ! empty( $_POST['rememberme'] );

		if ( class_exists( 'SARALOPA_Captcha' ) && SARALOPA_Settings::get( 'captcha_login' ) ) {
			$token  = isset( $_POST['saralopa_captcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['saralopa_captcha_token'] ) ) : '';
			$answer = isset( $_POST['saralopa_captcha_answer'] ) ? sanitize_text_field( wp_unslash( $_POST['saralopa_captcha_answer'] ) ) : '';
			if ( ! SARALOPA_Captcha::verify( $token, $answer ) ) {
				/** This action is documented in wp-includes/user.php. Counts toward login limits and the failed-login log. */
				do_action( 'wp_login_failed', $login, new WP_Error( 'saralopa_captcha' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
				self::fail( '<strong>' . __( 'Error:', 'sarala-login-page-pro' ) . '</strong> ' . __( 'Please answer the security question correctly.', 'sarala-login-page-pro' ) );
			}
		}

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			$message = $user->get_error_message();
			self::fail( '' !== $message ? $message : __( 'The username or password is incorrect.', 'sarala-login-page-pro' ) );
		}

		$page     = isset( $_POST['slpp_lw_page'] ) ? esc_url_raw( wp_unslash( $_POST['slpp_lw_page'] ) ) : '';
		$custom   = isset( $_POST['slpp_lw_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['slpp_lw_redirect'] ) ) : '';
		$mode     = isset( $_POST['slpp_lw_mode'] ) ? sanitize_key( wp_unslash( $_POST['slpp_lw_mode'] ) ) : 'rules';
		$fallback = wp_validate_redirect( $page, home_url( '/' ) );

		if ( 'custom' === $mode && '' !== $custom ) {
			$redirect = wp_validate_redirect( $custom, $fallback );
		} elseif ( 'stay' === $mode ) {
			$redirect = $fallback;
		} else {
			/** This filter is documented in wp-login.php. The Login redirects rules run here. */
			$redirect = apply_filters( 'login_redirect', $fallback, '', $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
			$redirect = wp_validate_redirect( $redirect, $fallback );
		}

		wp_send_json_success( array( 'redirect' => $redirect ) );
	}
}

/**
 * The classic widget.
 */
class SLPP_Login_Widget extends WP_Widget {

	/**
	 * Set up the widget.
	 */
	public function __construct() {
		parent::__construct(
			'slpp_login_widget',
			__( 'Sarala Login', 'sarala-login-page-pro' ),
			array(
				'description'                 => __( 'A login box with AJAX login and social buttons. Logged-in users see your own HTML.', 'sarala-login-page-pro' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * Front end.
	 *
	 * @param array $args     Sidebar arguments.
	 * @param array $instance Settings.
	 */
	public function widget( $args, $instance ) {
		$instance = wp_parse_args( (array) $instance, SLPP_Login_Box::defaults() );
		$title    = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
		if ( is_user_logged_in() && '' !== trim( (string) $title ) ) {
			$title = __( 'Your account', 'sarala-login-page-pro' );
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup from the theme's sidebar.
		if ( '' !== trim( (string) $title ) ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup from the theme's sidebar.
		}
		echo SLPP_Login_Box::render( $instance ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in render().
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup from the theme's sidebar.
	}

	/**
	 * Settings form.
	 *
	 * @param array $instance Settings.
	 * @return string
	 */
	public function form( $instance ) {
		$i = wp_parse_args( (array) $instance, SLPP_Login_Box::defaults() );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title', 'sarala-login-page-pro' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $i['title'] ); ?>" />
		</p>
		<p>
			<?php foreach ( array( 'social' => __( 'Show social login buttons', 'sarala-login-page-pro' ), 'remember' => __( 'Show "Remember me"', 'sarala-login-page-pro' ), 'links' => __( 'Show lost password and register links', 'sarala-login-page-pro' ) ) as $key => $label ) : ?>
				<label style="display:block;margin:4px 0"><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>" value="1" <?php checked( 1, (int) $i[ $key ] ); ?> /> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'after_login' ) ); ?>"><?php esc_html_e( 'After login', 'sarala-login-page-pro' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'after_login' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'after_login' ) ); ?>">
				<option value="rules" <?php selected( $i['after_login'], 'rules' ); ?>><?php esc_html_e( 'Follow the Login redirects rules (else stay on the page)', 'sarala-login-page-pro' ); ?></option>
				<option value="stay" <?php selected( $i['after_login'], 'stay' ); ?>><?php esc_html_e( 'Stay on the same page', 'sarala-login-page-pro' ); ?></option>
				<option value="custom" <?php selected( $i['after_login'], 'custom' ); ?>><?php esc_html_e( 'Go to this address:', 'sarala-login-page-pro' ); ?></option>
			</select>
			<input class="widefat" style="margin-top:4px" name="<?php echo esc_attr( $this->get_field_name( 'redirect' ) ); ?>" type="url" value="<?php echo esc_attr( $i['redirect'] ); ?>" placeholder="https://" aria-label="<?php esc_attr_e( 'Address after login', 'sarala-login-page-pro' ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'logged_in_html' ) ); ?>"><?php esc_html_e( 'HTML for logged-in users', 'sarala-login-page-pro' ); ?></label>
			<textarea class="widefat code" rows="5" id="<?php echo esc_attr( $this->get_field_id( 'logged_in_html' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'logged_in_html' ) ); ?>"><?php echo esc_textarea( $i['logged_in_html'] ); ?></textarea>
			<small><?php esc_html_e( 'Placeholders: {avatar} {display_name} {first_name} {user_login} {email} {profile_url} {account_url} {admin_url} {logout_url}', 'sarala-login-page-pro' ); ?></small>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'css' ) ); ?>"><?php esc_html_e( 'Custom CSS', 'sarala-login-page-pro' ); ?></label>
			<textarea class="widefat code" rows="4" id="<?php echo esc_attr( $this->get_field_id( 'css' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'css' ) ); ?>" placeholder=".slpp-lw__button { background: #111; }"><?php echo esc_textarea( $i['css'] ); ?></textarea>
			<small><?php esc_html_e( 'Classes: .slpp-lw, .slpp-lw__form, .slpp-lw__row, .slpp-lw__button, .slpp-lw__links, .slpp-lw__message, .slpp-lw__user', 'sarala-login-page-pro' ); ?></small>
		</p>
		<?php
		return '';
	}

	/**
	 * Save settings.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Old settings.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$css = isset( $new_instance['css'] ) ? wp_strip_all_tags( (string) $new_instance['css'] ) : '';
		return array(
			'title'          => isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '',
			'social'         => empty( $new_instance['social'] ) ? 0 : 1,
			'remember'       => empty( $new_instance['remember'] ) ? 0 : 1,
			'links'          => empty( $new_instance['links'] ) ? 0 : 1,
			'after_login'    => isset( $new_instance['after_login'] ) && in_array( $new_instance['after_login'], array( 'rules', 'stay', 'custom' ), true ) ? $new_instance['after_login'] : 'rules',
			'redirect'       => isset( $new_instance['redirect'] ) ? esc_url_raw( $new_instance['redirect'] ) : '',
			'logged_in_html' => isset( $new_instance['logged_in_html'] ) ? wp_kses_post( $new_instance['logged_in_html'] ) : '',
			// Only people trusted with unfiltered HTML may add CSS, which can change how the whole page looks.
			'css'            => current_user_can( 'unfiltered_html' ) ? $css : ( isset( $old_instance['css'] ) ? $old_instance['css'] : '' ),
		);
	}
}
