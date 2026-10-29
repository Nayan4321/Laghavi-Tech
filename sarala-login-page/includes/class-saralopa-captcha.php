<?php
/**
 * Simple math question on the login and registration forms to stop bots.
 *
 * @package SaralaLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Math security question.
 */
class SARALOPA_Captcha {

	/**
	 * How long a question shown on a page stays valid.
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * The questions; one is picked at random each time the form is shown.
	 *
	 * @return array<int, int[]> Pairs of numbers to add.
	 */
	public static function questions() {
		return array(
			array( 11, 2 ),
			array( 7, 9 ),
			array( 2, 6 ),
			array( 4, 13 ),
		);
	}

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( SARALOPA_Settings::get( 'captcha_login' ) ) {
			add_action( 'login_form', array( __CLASS__, 'field_wp' ), 10 );
			add_action( 'woocommerce_login_form', array( __CLASS__, 'field_wc' ) );
			add_filter( 'authenticate', array( __CLASS__, 'check_login' ), 99 );
		}
		if ( SARALOPA_Settings::get( 'captcha_register' ) ) {
			add_action( 'register_form', array( __CLASS__, 'field_wp' ), 10 );
			add_filter( 'registration_errors', array( __CLASS__, 'check_wp_register' ) );
			add_action( 'woocommerce_register_form', array( __CLASS__, 'field_wc' ) );
			add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'check_wc_register' ) );
		}
	}

	/**
	 * Pick a random question and sign it, so the answer can be checked without storing anything.
	 *
	 * @return array{0: string, 1: string} Question text and signed token.
	 */
	public static function new_question() {
		$questions = self::questions();
		$index     = random_int( 0, count( $questions ) - 1 );
		$time      = time();
		$token     = $index . '|' . $time . '|' . self::sign( $index, $time );

		/* translators: 1: first number, 2: second number. */
		$text = sprintf( __( 'What is %1$d + %2$d?', 'sarala-login-page' ), $questions[ $index ][0], $questions[ $index ][1] );

		return array( $text, $token );
	}

	/**
	 * Signature for a question index and time.
	 *
	 * @param int $index Question index.
	 * @param int $time  When the question was shown.
	 * @return string
	 */
	private static function sign( $index, $time ) {
		return substr( hash_hmac( 'sha256', 'saralopa_captcha|' . $index . '|' . $time, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Check a submitted answer against its token.
	 *
	 * @param string $token  Token from the hidden field.
	 * @param string $answer What the visitor typed.
	 * @return bool
	 */
	public static function verify( $token, $answer ) {
		$parts = explode( '|', $token );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) ) {
			return false;
		}

		$index     = (int) $parts[0];
		$time      = (int) $parts[1];
		$questions = self::questions();

		if ( ! isset( $questions[ $index ] ) || $time > time() + MINUTE_IN_SECONDS || $time < time() - self::TTL ) {
			return false;
		}
		if ( ! hash_equals( self::sign( $index, $time ), $parts[2] ) ) {
			return false;
		}

		$answer = trim( $answer );
		return '' !== $answer && ctype_digit( $answer ) && (int) $answer === $questions[ $index ][0] + $questions[ $index ][1];
	}

	/**
	 * Verify the answer posted with the current request.
	 *
	 * @return bool
	 */
	private static function posted_answer_is_correct() {
		$nonce = isset( $_POST['saralopa_captcha_nonce'] ) ? sanitize_key( wp_unslash( $_POST['saralopa_captcha_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'saralopa_captcha' ) ) {
			return false;
		}
		$token  = isset( $_POST['saralopa_captcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['saralopa_captcha_token'] ) ) : '';
		$answer = isset( $_POST['saralopa_captcha_answer'] ) ? sanitize_text_field( wp_unslash( $_POST['saralopa_captcha_answer'] ) ) : '';
		return self::verify( $token, $answer );
	}

	/**
	 * Was WooCommerce's own form sent (checked with WooCommerce's nonce)?
	 *
	 * @param string $form login or register.
	 * @return bool
	 */
	private static function is_wc_form( $form ) {
		$field = 'woocommerce-' . $form . '-nonce';
		$nonce = isset( $_POST[ $field ] ) ? sanitize_key( wp_unslash( $_POST[ $field ] ) ) : '';
		return '' !== $nonce && (bool) wp_verify_nonce( $nonce, 'woocommerce-' . $form );
	}

	/**
	 * Error shown for a wrong or missing answer.
	 *
	 * @return WP_Error
	 */
	private static function error() {
		return new WP_Error(
			'saralopa_captcha',
			'<strong>' . esc_html__( 'Error:', 'sarala-login-page' ) . '</strong> ' . esc_html__( 'Please answer the security question correctly.', 'sarala-login-page' )
		);
	}

	/**
	 * Field on wp-login.php.
	 */
	public static function field_wp() {
		list( $text, $token ) = self::new_question();
		?>
		<p class="saralopa-captcha">
			<label for="saralopa_captcha_answer"><?php esc_html_e( 'Security question:', 'sarala-login-page' ); ?> <?php echo esc_html( $text ); ?></label>
			<input type="text" name="saralopa_captcha_answer" id="saralopa_captcha_answer" class="input" inputmode="numeric" pattern="[0-9]*" autocomplete="off" size="5" required />
			<input type="hidden" name="saralopa_captcha_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( 'saralopa_captcha', 'saralopa_captcha_nonce', false ); ?>
		</p>
		<?php
	}

	/**
	 * Field on WooCommerce's login and registration forms.
	 */
	public static function field_wc() {
		list( $text, $token ) = self::new_question();
		$id                   = 'saralopa_captcha_answer_' . wp_rand( 1000, 9999 );
		?>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide saralopa-captcha">
			<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Security question:', 'sarala-login-page' ); ?> <?php echo esc_html( $text ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
			<input type="text" name="saralopa_captcha_answer" id="<?php echo esc_attr( $id ); ?>" class="woocommerce-Input input-text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" required aria-required="true" />
			<input type="hidden" name="saralopa_captcha_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( 'saralopa_captcha', 'saralopa_captcha_nonce', false ); ?>
		</p>
		<?php
	}

	/**
	 * Check the answer on wp-login.php and WooCommerce logins.
	 *
	 * Only form logins are checked, so the REST API, XML-RPC and application passwords keep working.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function check_login( $user ) {
		global $pagenow;

		$method     = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$is_wp_form = 'wp-login.php' === $pagenow && 'POST' === $method;
		$is_wc_form = self::is_wc_form( 'login' );

		if ( ! $is_wp_form && ! $is_wc_form ) {
			return $user;
		}
		if ( ! self::posted_answer_is_correct() ) {
			return self::error();
		}
		return $user;
	}

	/**
	 * Check the answer on wp-login.php?action=register.
	 *
	 * @param WP_Error $errors Registration errors.
	 * @return WP_Error
	 */
	public static function check_wp_register( $errors ) {
		if ( ! self::posted_answer_is_correct() ) {
			$errors->add( 'saralopa_captcha', self::error()->get_error_message() );
		}
		return $errors;
	}

	/**
	 * Check the answer on the WooCommerce registration form.
	 *
	 * Accounts created at checkout or by social login don't post this form, so they are left alone.
	 *
	 * @param WP_Error $errors Registration errors.
	 * @return WP_Error
	 */
	public static function check_wc_register( $errors ) {
		if ( self::is_wc_form( 'register' ) && ! self::posted_answer_is_correct() ) {
			$errors->add( 'saralopa_captcha', self::error()->get_error_message() );
		}
		return $errors;
	}
}
