<?php
/**
 * Google reCAPTCHA or Cloudflare Turnstile above the submit button, on every form or on chosen forms.
 * Checked by Aarye's form protection (AARYE_Protect) before the form plugin accepts the message.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Captcha.
 */
class AARYEPRO_Captcha {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'aarye_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'aarye_protect_settings', array( __CLASS__, 'settings' ) );
		add_filter( 'aarye_save_protect_settings', array( __CLASS__, 'save' ) );
		add_filter( 'aarye_refuse_submission', array( __CLASS__, 'check' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 7 );
	}

	/**
	 * Providers.
	 *
	 * @return array<string, array{name: string, script: string, verify: string, field: string}>
	 */
	public static function providers() {
		return array(
			'recaptcha' => array(
				'name'   => 'Google reCAPTCHA v2 ("I\'m not a robot")',
				'script' => 'https://www.google.com/recaptcha/api.js?render=explicit',
				'verify' => 'https://www.google.com/recaptcha/api/siteverify',
				'field'  => 'g-recaptcha-response',
			),
			'turnstile' => array(
				'name'   => 'Cloudflare Turnstile',
				'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
				'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
				'field'  => 'cf-turnstile-response',
			),
		);
	}

	/**
	 * Defaults.
	 *
	 * @param array $d Defaults.
	 * @return array
	 */
	public static function defaults( $d ) {
		return array_merge(
			$d,
			array(
				'pro_captcha'        => 'off',
				'pro_captcha_scope'  => 'all',
				'pro_captcha_forms'  => array(),
				'pro_captcha_site'   => '',
				'pro_captcha_secret' => '',
			)
		);
	}

	/**
	 * The active provider, or null.
	 *
	 * @return array|null
	 */
	private static function provider() {
		$key = AARYE_Settings::get( 'pro_captcha' );
		$all = self::providers();
		if ( ! isset( $all[ $key ] ) || '' === AARYE_Settings::get( 'pro_captcha_site' ) || '' === AARYE_Settings::get( 'pro_captcha_secret' ) || ! AARYEPRO_License::is_valid() ) {
			return null;
		}
		return $all[ $key ];
	}

	/**
	 * Whether a form asks for the captcha.
	 *
	 * @param string $form "plugin:id".
	 * @return bool
	 */
	private static function on_form( $form ) {
		return 'all' === AARYE_Settings::get( 'pro_captcha_scope' ) || in_array( $form, (array) AARYE_Settings::get( 'pro_captcha_forms' ), true );
	}

	/**
	 * Settings on Aarye's Spam protection screen.
	 *
	 * @param array $s Settings.
	 */
	public static function settings( $s ) {
		$chosen = (array) $s['pro_captcha_forms'];
		?>
		<h2><?php esc_html_e( 'reCAPTCHA or Turnstile (Pro)', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
		<p><?php esc_html_e( 'Instead of, or as well as, the question: a check by Google or Cloudflare above the submit button.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Service', 'aarye-marketing-addon-for-forms-pro' ); ?></th><td>
				<select name="pro_captcha">
					<option value="off" <?php selected( $s['pro_captcha'], 'off' ); ?>><?php esc_html_e( 'Off', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
					<?php foreach ( self::providers() as $key => $p ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['pro_captcha'], $key ); ?>><?php echo esc_html( $p['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! AARYEPRO_License::is_valid() ) : ?>
					<p class="description"><?php esc_html_e( 'Activate your Aarye Pro license to use it.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
				<?php endif; ?>
			</td></tr>
			<tr><th><label for="aaryepro-cap-site"><?php esc_html_e( 'Site key', 'aarye-marketing-addon-for-forms-pro' ); ?></label></th><td><input id="aaryepro-cap-site" type="text" name="pro_captcha_site" value="<?php echo esc_attr( $s['pro_captcha_site'] ); ?>" class="regular-text code" autocomplete="off" /></td></tr>
			<tr><th><label for="aaryepro-cap-secret"><?php esc_html_e( 'Secret key', 'aarye-marketing-addon-for-forms-pro' ); ?></label></th><td><input id="aaryepro-cap-secret" type="password" name="pro_captcha_secret" value="<?php echo esc_attr( '' !== $s['pro_captcha_secret'] ? '••••••••' : '' ); ?>" class="regular-text code" autocomplete="new-password" /><p class="description"><?php esc_html_e( 'reCAPTCHA: google.com/recaptcha/admin, type "Challenge (v2) – I\'m not a robot". Turnstile: Cloudflare dashboard > Turnstile. Add your website\'s domain there.', 'aarye-marketing-addon-for-forms-pro' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Forms', 'aarye-marketing-addon-for-forms-pro' ); ?></th><td>
				<label><input type="radio" name="pro_captcha_scope" value="all" <?php checked( $s['pro_captcha_scope'], 'all' ); ?> /> <?php esc_html_e( 'Every form', 'aarye-marketing-addon-for-forms-pro' ); ?></label><br />
				<label><input type="radio" name="pro_captcha_scope" value="chosen" <?php checked( $s['pro_captcha_scope'], 'chosen' ); ?> /> <?php esc_html_e( 'Only these forms:', 'aarye-marketing-addon-for-forms-pro' ); ?></label>
				<div class="aarye-form-list">
					<?php foreach ( AARYE_Integrations::all_forms() as $form ) : ?>
						<?php $key = $form['plugin'] . ':' . $form['id']; ?>
						<label class="aarye-form-choice"><input type="checkbox" name="pro_captcha_forms[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $chosen, true ) ); ?> /> <?php echo esc_html( $form['title'] ); ?> <span class="aarye-muted">· <?php echo esc_html( $form['plugin_name'] ); ?></span></label>
					<?php endforeach; ?>
				</div>
			</td></tr>
		</table>
		<?php
	}

	/**
	 * Save the settings (the secret is kept when the masked value comes back).
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save( $values ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Aarye checked the nonce before this filter.
		$provider = isset( $_POST['pro_captcha'] ) ? sanitize_key( wp_unslash( $_POST['pro_captcha'] ) ) : 'off';
		$scope    = isset( $_POST['pro_captcha_scope'] ) ? sanitize_key( wp_unslash( $_POST['pro_captcha_scope'] ) ) : 'all';
		$forms    = isset( $_POST['pro_captcha_forms'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['pro_captcha_forms'] ) ) : array();
		$site     = isset( $_POST['pro_captcha_site'] ) ? sanitize_text_field( wp_unslash( $_POST['pro_captcha_site'] ) ) : '';
		$secret   = isset( $_POST['pro_captcha_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['pro_captcha_secret'] ) ) : '';
		// phpcs:enable
		$values['pro_captcha']        = isset( self::providers()[ $provider ] ) ? $provider : 'off';
		$values['pro_captcha_scope']  = 'chosen' === $scope ? 'chosen' : 'all';
		$values['pro_captcha_forms']  = array_values(
			array_filter(
				$forms,
				static function ( $f ) {
					return (bool) preg_match( '/^[a-z0-9_]+:[A-Za-z0-9_-]+$/', $f );
				}
			)
		);
		$values['pro_captcha_site']   = preg_replace( '/[^A-Za-z0-9_-]/', '', $site );
		$values['pro_captcha_secret'] = '••••••••' === $secret ? (string) AARYE_Settings::get( 'pro_captcha_secret' ) : preg_replace( '/[^A-Za-z0-9_.-]/', '', $secret );
		return $values;
	}

	/**
	 * Load the service's script and ours on the front end.
	 */
	public static function enqueue() {
		$provider = self::provider();
		if ( ! $provider || ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The service's own script, versioned by the service.
		wp_enqueue_script( 'aaryepro-captcha-service', $provider['script'], array(), null, array( 'in_footer' => true ) );
		wp_enqueue_script( 'aaryepro-captcha', AARYEPRO_URL . 'assets/js/captcha.js', array( 'aaryepro-captcha-service' ), aaryepro_asset_version( 'assets/js/captcha.js' ), array( 'in_footer' => true ) );
		wp_localize_script(
			'aaryepro-captcha',
			'aaryeproCaptcha',
			array(
				'type'  => AARYE_Settings::get( 'pro_captcha' ),
				'key'   => AARYE_Settings::get( 'pro_captcha_site' ),
				'all'   => 'all' === AARYE_Settings::get( 'pro_captcha_scope' ),
				'forms' => array_values( (array) AARYE_Settings::get( 'pro_captcha_forms' ) ),
				'lang'  => substr( determine_locale(), 0, 2 ),
			)
		);
	}

	/**
	 * Check the answer before the form plugin accepts the message.
	 *
	 * @param string|array $message Earlier result.
	 * @param string       $plugin  Form plugin.
	 * @param string       $form_id Form ID.
	 * @return string|array
	 */
	public static function check( $message, $plugin, $form_id ) {
		$provider = self::provider();
		if ( ! empty( $message ) || ! $provider || ! self::on_form( $plugin . ':' . $form_id ) || ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) ) {
			return $message;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public form; the service's token is the check.
		$token = isset( $_POST[ $provider['field'] ] ) ? sanitize_text_field( wp_unslash( $_POST[ $provider['field'] ] ) ) : '';
		$ok    = false;
		if ( '' !== $token ) {
			$response = wp_safe_remote_post(
				$provider['verify'],
				array(
					'timeout' => 8,
					'body'    => array(
						'secret'   => AARYE_Settings::get( 'pro_captcha_secret' ),
						'response' => $token,
						'remoteip' => AARYE_Entries::ip(),
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $message; // The service could not be reached: don't lose a real lead because of it.
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$ok   = is_array( $body ) && ! empty( $body['success'] );
		}
		if ( $ok ) {
			return $message;
		}
		return array(
			'message' => __( 'Please confirm that you are not a robot.', 'aarye-marketing-addon-for-forms-pro' ),
			/* translators: %s: reCAPTCHA or Turnstile. */
			'reason'  => sprintf( __( '%s not passed', 'aarye-marketing-addon-for-forms-pro' ), 'recaptcha' === AARYE_Settings::get( 'pro_captcha' ) ? 'reCAPTCHA' : 'Turnstile' ),
		);
	}
}
