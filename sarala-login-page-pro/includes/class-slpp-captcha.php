<?php
/**
 * CAPTCHA with a backup: Cloudflare Turnstile, Google reCAPTCHA (v2 checkbox) or hCaptcha on the
 * login and registration forms, with the free plugin's security question as the backup.
 *
 * When the CAPTCHA can't load in the visitor's browser (blocked script, network or service
 * problem), or the site can't reach the CAPTCHA service to check the answer, the security question
 * appears instead, so nobody (the site owner included) is locked out by a broken CAPTCHA.
 *
 * Uses the free plugin's choice of forms: Sarala Login Page > Settings > Bot protection.
 *
 * @package SaralaLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * CAPTCHA with security question backup.
 */
class SLPP_Captcha {

	const OPTION = 'slpp_captcha';
	const PAGE   = 'sarala-login-page-captcha';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 24 );
		add_action( 'admin_post_slpp_captcha', array( __CLASS__, 'handle_form' ) );

		if ( ! self::active() || ! class_exists( 'SARALOPA_Captcha' ) ) {
			return;
		}
		// The free plugin's security question stays on the form as the backup; its check is replaced.
		remove_filter( 'authenticate', array( 'SARALOPA_Captcha', 'check_login' ), 99 );
		remove_filter( 'registration_errors', array( 'SARALOPA_Captcha', 'check_wp_register' ) );
		remove_filter( 'woocommerce_registration_errors', array( 'SARALOPA_Captcha', 'check_wc_register' ) );

		if ( SARALOPA_Settings::get( 'captcha_login' ) ) {
			add_action( 'login_form', array( __CLASS__, 'widget' ), 9 );
			add_action( 'woocommerce_login_form', array( __CLASS__, 'widget' ), 9 );
			add_filter( 'authenticate', array( __CLASS__, 'check_login' ), 99 );
		}
		if ( SARALOPA_Settings::get( 'captcha_register' ) ) {
			add_action( 'register_form', array( __CLASS__, 'widget' ), 9 );
			add_action( 'woocommerce_register_form', array( __CLASS__, 'widget' ), 9 );
			add_filter( 'registration_errors', array( __CLASS__, 'check_wp_register' ) );
			add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'check_wc_register' ) );
		}
	}

	/**
	 * Services.
	 *
	 * @return array
	 */
	public static function services() {
		return array(
			'turnstile' => array(
				'label'   => 'Cloudflare Turnstile',
				'script'  => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
				'class'   => 'cf-turnstile',
				'field'   => 'cf-turnstile-response',
				'verify'  => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
				'console' => 'https://dash.cloudflare.com/?to=/:account/turnstile',
			),
			'recaptcha' => array(
				'label'   => 'Google reCAPTCHA v2 (checkbox)',
				'script'  => 'https://www.google.com/recaptcha/api.js',
				'class'   => 'g-recaptcha',
				'field'   => 'g-recaptcha-response',
				'verify'  => 'https://www.google.com/recaptcha/api/siteverify',
				'console' => 'https://www.google.com/recaptcha/admin/create',
			),
			'hcaptcha'  => array(
				'label'   => 'hCaptcha',
				'script'  => 'https://js.hcaptcha.com/1/api.js',
				'class'   => 'h-captcha',
				'field'   => 'h-captcha-response',
				'verify'  => 'https://api.hcaptcha.com/siteverify',
				'console' => 'https://dashboard.hcaptcha.com/sites',
			),
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'service'    => '',
				'site_key'   => '',
				'secret_key' => '',
			)
		);
		// The secret key is stored encrypted.
		$s['secret_key'] = SLPP_Secrets::decrypt( $s['secret_key'] );
		return $s;
	}

	/**
	 * Whether a CAPTCHA service is set up.
	 *
	 * @return bool
	 */
	public static function active() {
		$s = self::settings();
		return isset( self::services()[ $s['service'] ] ) && '' !== $s['site_key'] && '' !== $s['secret_key'];
	}

	/**
	 * The CAPTCHA box (above the free plugin's security question, which it hides while it works).
	 */
	public static function widget() {
		$s       = self::settings();
		$service = self::services()[ $s['service'] ];
		wp_enqueue_script( 'slpp-captcha-api', $service['script'], array(), null, array( 'strategy' => 'async' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The service's own script, versioned by the service.
		wp_enqueue_script( 'slpp-captcha', SLPP_URL . 'assets/js/captcha.js', array(), SLPP_VERSION, true );
		printf(
			'<div class="slpp-captcha" data-service="%1$s"><div class="%2$s" data-sitekey="%3$s" data-callback="slppCaptchaOk" data-error-callback="slppCaptchaFailed" data-expired-callback="slppCaptchaExpired"></div><input type="hidden" name="slpp_captcha_mode" value="captcha" /></div>',
			esc_attr( $s['service'] ),
			esc_attr( $service['class'] ),
			esc_attr( $s['site_key'] )
		);
	}

	/**
	 * Check the CAPTCHA, or the security question when the CAPTCHA could not be used.
	 *
	 * @return true|WP_Error
	 */
	public static function check() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Login and registration forms; the CAPTCHA token is the check.
		$s       = self::settings();
		$service = self::services()[ $s['service'] ];
		$token   = isset( $_POST[ $service['field'] ] ) ? sanitize_text_field( wp_unslash( $_POST[ $service['field'] ] ) ) : '';
		$mode    = isset( $_POST['slpp_captcha_mode'] ) ? sanitize_key( wp_unslash( $_POST['slpp_captcha_mode'] ) ) : '';
		// phpcs:enable

		if ( '' !== $token ) {
			$result = self::verify_token( $token );
			if ( true === $result ) {
				return true;
			}
			if ( 'unreachable' !== $result ) {
				return new WP_Error( 'slpp_captcha', __( '<strong>Error:</strong> the CAPTCHA check failed. Please try again.', 'sarala-login-page-pro' ) );
			}
			// The CAPTCHA service could not be reached: fall back to the security question.
		} elseif ( 'fallback' !== $mode ) {
			return new WP_Error( 'slpp_captcha', __( '<strong>Error:</strong> please complete the CAPTCHA.', 'sarala-login-page-pro' ) );
		}

		if ( self::question_answered() ) {
			return true;
		}
		return new WP_Error( 'slpp_captcha', __( '<strong>Error:</strong> please answer the security question correctly.', 'sarala-login-page-pro' ) );
	}

	/**
	 * Verify a token with the service.
	 *
	 * @param string $token Token from the form.
	 * @return true|string True, 'invalid' or 'unreachable'.
	 */
	public static function verify_token( $token ) {
		$s        = self::settings();
		$service  = self::services()[ $s['service'] ];
		$response = wp_remote_post(
			$service['verify'],
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => $s['secret_key'],
					'response' => $token,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				),
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return 'unreachable';
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return 'unreachable';
		}
		return ! empty( $data['success'] ) ? true : 'invalid';
	}

	/**
	 * The free plugin's security question, answered correctly.
	 *
	 * @return bool
	 */
	private static function question_answered() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked just below.
		$nonce  = isset( $_POST['saralopa_captcha_nonce'] ) ? sanitize_key( wp_unslash( $_POST['saralopa_captcha_nonce'] ) ) : '';
		$token  = isset( $_POST['saralopa_captcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['saralopa_captcha_token'] ) ) : '';
		$answer = isset( $_POST['saralopa_captcha_answer'] ) ? sanitize_text_field( wp_unslash( $_POST['saralopa_captcha_answer'] ) ) : '';
		// phpcs:enable
		return '' !== $nonce && wp_verify_nonce( $nonce, 'saralopa_captcha' ) && SARALOPA_Captcha::verify( $token, $answer );
	}

	/**
	 * Whether this request is a form post the check applies to.
	 *
	 * @param string $form login or register.
	 * @return bool
	 */
	private static function is_form_post( $form ) {
		global $pagenow;
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			return false;
		}
		$field = 'woocommerce-' . $form . '-nonce';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only detects which form was sent.
		return 'wp-login.php' === $pagenow || ! empty( $_POST[ $field ] );
	}

	/**
	 * Login forms.
	 *
	 * @param WP_User|WP_Error|null $user User.
	 * @return WP_User|WP_Error|null
	 */
	public static function check_login( $user ) {
		if ( ! self::is_form_post( 'login' ) ) {
			return $user;
		}
		$ok = self::check();
		return is_wp_error( $ok ) ? $ok : $user;
	}

	/**
	 * WordPress registration form.
	 *
	 * @param WP_Error $errors Errors.
	 * @return WP_Error
	 */
	public static function check_wp_register( $errors ) {
		$ok = self::check();
		if ( is_wp_error( $ok ) ) {
			$errors->add( 'slpp_captcha', $ok->get_error_message() );
		}
		return $errors;
	}

	/**
	 * WooCommerce registration form.
	 *
	 * @param WP_Error $errors Errors.
	 * @return WP_Error
	 */
	public static function check_wc_register( $errors ) {
		if ( self::is_form_post( 'register' ) ) {
			$ok = self::check();
			if ( is_wp_error( $ok ) ) {
				$errors->add( 'slpp_captcha', $ok->get_error_message() );
			}
		}
		return $errors;
	}

	/**
	 * Menu item.
	 */
	public static function menu() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		add_submenu_page( 'sarala-login-page', __( 'CAPTCHA', 'sarala-login-page-pro' ), __( 'CAPTCHA', 'sarala-login-page-pro' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Save.
	 */
	public static function handle_form() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sarala-login-page-pro' ) );
		}
		check_admin_referer( 'slpp_captcha' );
		$old     = self::settings();
		$service = isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '';
		$secret  = isset( $_POST['secret_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) ) : '';
		update_option(
			self::OPTION,
			array(
				'service'    => isset( self::services()[ $service ] ) ? $service : '',
				'site_key'   => isset( $_POST['site_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) ) : '',
				'secret_key' => '' !== $secret ? SLPP_Secrets::encrypt( $secret ) : ( $old['service'] === $service ? self::stored_secret() : '' ),
			),
			false
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE,
					'saved'      => 1,
					'_slppnonce' => wp_create_nonce( 'slpp_captcha_view' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Stored (encrypted) secret, unchanged.
	 *
	 * @return string
	 */
	private static function stored_secret() {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) && isset( $raw['secret_key'] ) ? (string) $raw['secret_key'] : '';
	}

	/**
	 * Settings screen.
	 */
	public static function page() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		$nonce = isset( $_GET['_slppnonce'] ) ? sanitize_key( wp_unslash( $_GET['_slppnonce'] ) ) : '';
		$saved = '' !== $nonce && wp_verify_nonce( $nonce, 'slpp_captcha_view' ) && ! empty( $_GET['saved'] );
		$s     = self::settings();
		?>
		<div class="wrap saralopa-admin">
			<h1><?php esc_html_e( 'CAPTCHA', 'sarala-login-page-pro' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Add Cloudflare Turnstile, Google reCAPTCHA or hCaptcha to the login and registration forms. The security question stays as the backup: if the CAPTCHA can\'t load or can\'t be checked, visitors answer the question instead, so a broken CAPTCHA never locks anyone out.', 'sarala-login-page-pro' ); ?></p>
			<p class="description"><?php esc_html_e( 'The forms it is shown on follow Sarala Login Page > Settings > Bot protection.', 'sarala-login-page-pro' ); ?></p>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'sarala-login-page-pro' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="slpp_captcha" />
				<?php wp_nonce_field( 'slpp_captcha' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="slpp-captcha-service"><?php esc_html_e( 'Service', 'sarala-login-page-pro' ); ?></label></th>
						<td>
							<select id="slpp-captcha-service" name="service">
								<option value=""><?php esc_html_e( 'Off (security question only)', 'sarala-login-page-pro' ); ?></option>
								<?php foreach ( self::services() as $key => $service ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['service'], $key ); ?>><?php echo esc_html( $service['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php foreach ( self::services() as $service ) : ?>
									<a href="<?php echo esc_url( $service['console'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $service['label'] ); ?> &#8599;</a>&nbsp;&nbsp;
								<?php endforeach; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slpp-captcha-site"><?php esc_html_e( 'Site key', 'sarala-login-page-pro' ); ?></label></th>
						<td><input type="text" id="slpp-captcha-site" name="site_key" class="regular-text code" value="<?php echo esc_attr( $s['site_key'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="slpp-captcha-secret"><?php esc_html_e( 'Secret key', 'sarala-login-page-pro' ); ?></label></th>
						<td><input type="password" id="slpp-captcha-secret" name="secret_key" class="regular-text code" value="" autocomplete="new-password" placeholder="<?php echo '' !== $s['secret_key'] ? esc_attr__( 'Saved (encrypted). Leave empty to keep it.', 'sarala-login-page-pro' ) : ''; ?>" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
