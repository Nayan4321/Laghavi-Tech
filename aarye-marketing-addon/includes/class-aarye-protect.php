<?php
/**
 * Form protection: a security question above the submit button, blocked IP addresses (for one form
 * or all forms, the rest of the website stays open to them), and recognising the site's own team so
 * their tests are not counted as leads or as spam.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Protection.
 */
class AARYE_Protect {

	const BLOCKS = 'aarye_blocks';
	const LOG    = 'aarye_block_log';

	/**
	 * How long a question stays valid.
	 */
	const TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Why Contact Form 7's current submission was refused.
	 *
	 * @var string
	 */
	private static $cf7_refused = '';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'aarye_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 6 );

		// Checked before the form plugin accepts the entry (no email, no saved entry when refused).
		add_filter( 'wpcf7_spam', array( __CLASS__, 'cf7' ), 20, 2 );
		add_filter( 'wpcf7_display_message', array( __CLASS__, 'cf7_message' ), 20, 2 );
		add_action( 'wpforms_process_before', array( __CLASS__, 'wpforms' ), 5, 2 );
		add_action( 'elementor_pro/forms/validation', array( __CLASS__, 'elementor' ), 5, 2 );
		add_filter( 'gform_validation', array( __CLASS__, 'gravity' ), 5 );
		add_filter( 'fluentform/validation_errors', array( __CLASS__, 'fluentform' ), 5, 4 );
		add_filter( 'frm_validate_entry', array( __CLASS__, 'formidable' ), 5, 2 );
		add_filter( 'forminator_custom_form_submit_errors', array( __CLASS__, 'forminator' ), 5, 3 );
	}

	/**
	 * Default settings.
	 *
	 * @param array $d Defaults.
	 * @return array
	 */
	public static function defaults( $d ) {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return array_merge(
			$d,
			array(
				'question'         => 'off',
				'question_forms'   => array(),
				'block_automation' => 'yes',
				'team_domains'     => preg_replace( '/^www\./', '', strtolower( $host ) ),
				'team_ips'         => '',
			)
		);
	}

	/* ------------------------------------------------------------------ Team */

	/**
	 * Lines of a text setting.
	 *
	 * @param string $key Setting.
	 * @return string[]
	 */
	private static function lines( $key ) {
		$value = AARYE_Settings::get( $key );
		$lines = is_array( $value ) ? $value : preg_split( '/[\r\n,]+/', (string) $value );
		return array_values( array_filter( array_map( 'trim', (array) $lines ), 'strlen' ) );
	}

	/**
	 * Whether an entry was sent by the site's own team: a logged-in editor, an email address of a team
	 * domain, or a team IP address.
	 *
	 * @param string $email Email address of the entry.
	 * @param string $ip    IP address of the request.
	 * @param bool   $login Also count the logged-in user (not when re-checking old entries).
	 * @return bool
	 */
	public static function is_team( $email, $ip, $login = true ) {
		if ( $login && is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return true;
		}
		$email = strtolower( trim( (string) $email ) );
		if ( false !== strpos( $email, '@' ) ) {
			$domain = substr( $email, strpos( $email, '@' ) + 1 );
			foreach ( self::lines( 'team_domains' ) as $team ) {
				$team = ltrim( strtolower( $team ), '@' );
				if ( '' !== $team && ( $domain === $team || substr( $domain, -strlen( '.' . $team ) ) === '.' . $team ) ) {
					return true;
				}
			}
		}
		if ( '' !== $ip ) {
			foreach ( self::lines( 'team_ips' ) as $rule ) {
				if ( self::ip_matches( $ip, $rule ) ) {
					return true;
				}
			}
		}
		/**
		 * Whether an entry comes from the site's own team.
		 *
		 * @param bool   $team  Result.
		 * @param string $email Email address.
		 * @param string $ip    IP address.
		 */
		return (bool) apply_filters( 'aarye_is_team', false, $email, $ip );
	}

	/**
	 * After an update to 1.3: re-check saved entries with the new rules (visits from ad managers are
	 * your team's previews, team email addresses are tests, fewer real people marked doubtful).
	 */
	public static function upgrade() {
		global $wpdb;
		if ( version_compare( (string) get_option( 'aarye_protect_version', '0' ), '1.3.0', '>=' ) ) {
			return;
		}
		update_option( 'aarye_protect_version', AARYE_VERSION, false );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', AARYE_Entries::table(), 5000 ) );
		foreach ( (array) $rows as $row ) {
			$attr = json_decode( (string) $row->attribution, true );
			if ( ! is_array( $attr ) || ! empty( $attr['imported'] ) ) {
				continue;
			}
			$change = array();
			$touch  = ! empty( $attr['last'] ) ? $attr['last'] : ( ! empty( $attr['first'] ) ? $attr['first'] : null );
			if ( $touch ) {
				$source = AARYE_Sources::classify( $touch );
				if ( 'internal' === $source['channel'] || ( 'paid' === $source['channel'] && '' === $row->campaign && '' !== $source['campaign'] ) ) {
					$change['channel']      = $source['channel'];
					$change['source']       = $source['source'];
					$change['source_label'] = mb_substr( $source['label'], 0, 150 );
					$change['campaign']     = mb_substr( $source['campaign'], 0, 255 );
					$row->channel           = $source['channel'];
				}
			}
			if ( self::is_team( (string) $row->email, (string) $row->ip, false ) ) {
				$row->quality = 'team';
			} elseif ( 'team' === $row->quality ) {
				$row->quality = '';
			}
			$check                = AARYE_Evidence::analyse( $row );
			$change['quality']    = $check['verdict'];
			$change['spam_score'] = $check['score'];
			$wpdb->update( AARYE_Entries::table(), $change, array( 'id' => (int) $row->id ) );
		}
		// phpcs:enable
	}

	/* ------------------------------------------------------------------ IP rules */

	/**
	 * Whether an IP address matches a rule: an address, a range in CIDR notation (192.0.2.0/24,
	 * 2001:db8::/32) or a pattern with * (192.0.2.*).
	 *
	 * @param string $ip   Address.
	 * @param string $rule Rule.
	 * @return bool
	 */
	public static function ip_matches( $ip, $rule ) {
		$ip   = trim( strtolower( (string) $ip ) );
		$rule = trim( strtolower( (string) $rule ) );
		if ( '' === $ip || '' === $rule ) {
			return false;
		}
		if ( $ip === $rule ) {
			return true;
		}
		if ( false !== strpos( $rule, '*' ) ) {
			return (bool) preg_match( '/^' . str_replace( '\*', '[0-9a-f:.]*', preg_quote( $rule, '/' ) ) . '$/', $ip );
		}
		if ( false !== strpos( $rule, '/' ) ) {
			list( $net, $bits ) = explode( '/', $rule, 2 );
			$ip_bin             = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input returns false.
			$net_bin            = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input returns false.
			if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) || ! ctype_digit( $bits ) ) {
				return false;
			}
			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			if ( $bits > strlen( $ip_bin ) * 8 ) {
				return false;
			}
			if ( substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
				return false;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
			return ( $ip_bin[ $bytes ] & $mask ) === ( $net_bin[ $bytes ] & $mask );
		}
		return false;
	}

	/**
	 * Whether a rule is a valid address, range or pattern.
	 *
	 * @param string $rule Rule.
	 * @return bool
	 */
	public static function valid_rule( $rule ) {
		$rule = trim( (string) $rule );
		if ( filter_var( $rule, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		if ( preg_match( '#^([0-9a-f:.]+)/(\d{1,3})$#i', $rule, $m ) ) {
			return (bool) filter_var( $m[1], FILTER_VALIDATE_IP ) && (int) $m[2] <= ( false !== strpos( $m[1], ':' ) ? 128 : 32 );
		}
		return (bool) preg_match( '/^[0-9]{1,3}(\.([0-9]{1,3}|\*)){0,3}\.\*$/', $rule );
	}

	/**
	 * Blocking rules.
	 *
	 * @return array<int, array{ip: string, form: string, form_name: string, note: string, added: int}>
	 */
	public static function blocks() {
		$rules = get_option( self::BLOCKS, array() );
		return is_array( $rules ) ? array_values( $rules ) : array();
	}

	/**
	 * Add a blocking rule.
	 *
	 * @param string $ip        Address, range or pattern.
	 * @param string $form      "plugin:id", or "" for all forms.
	 * @param string $form_name Name of the form, for the list.
	 * @param string $note      Why.
	 * @return bool
	 */
	public static function add_block( $ip, $form = '', $form_name = '', $note = '' ) {
		$ip = trim( (string) $ip );
		if ( ! self::valid_rule( $ip ) ) {
			return false;
		}
		$rules = self::blocks();
		foreach ( $rules as $rule ) {
			if ( $rule['ip'] === $ip && $rule['form'] === $form ) {
				return true;
			}
		}
		$rules[] = array(
			'ip'        => $ip,
			'form'      => sanitize_text_field( $form ),
			'form_name' => mb_substr( sanitize_text_field( $form_name ), 0, 120 ),
			'note'      => mb_substr( sanitize_text_field( $note ), 0, 160 ),
			'added'     => time(),
		);
		update_option( self::BLOCKS, array_slice( $rules, -2000 ), false );
		return true;
	}

	/**
	 * Remove a blocking rule.
	 *
	 * @param int $index Position in the list.
	 */
	public static function remove_block( $index ) {
		$rules = self::blocks();
		unset( $rules[ $index ] );
		update_option( self::BLOCKS, array_values( $rules ), false );
	}

	/**
	 * The rule that blocks an address on a form, if any.
	 *
	 * @param string $ip   Address.
	 * @param string $form "plugin:id".
	 * @return array|null
	 */
	public static function blocked_by( $ip, $form ) {
		foreach ( self::blocks() as $rule ) {
			if ( ( '' === $rule['form'] || $rule['form'] === $form ) && self::ip_matches( $ip, $rule['ip'] ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Remember a refused submission (the last 100 are kept).
	 *
	 * @param string $form   "plugin:id".
	 * @param string $reason Why.
	 */
	private static function log( $form, $reason ) {
		$log   = get_option( self::LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'   => time(),
			'ip'     => AARYE_Entries::ip(),
			'form'   => $form,
			'reason' => $reason,
			'ua'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 160 ) : '',
		);
		update_option( self::LOG, array_slice( $log, -100 ), false );
		$count = (int) get_option( 'aarye_blocked_count', 0 );
		update_option( 'aarye_blocked_count', $count + 1, false );
	}

	/**
	 * Refused submissions, newest first.
	 *
	 * @return array
	 */
	public static function log_entries() {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	/* ------------------------------------------------------------------ Question */

	/**
	 * Whether the question is asked on a form.
	 *
	 * @param string $form "plugin:id".
	 * @return bool
	 */
	public static function asks( $form ) {
		$mode = AARYE_Settings::get( 'question' );
		if ( 'all' === $mode ) {
			return true;
		}
		return 'chosen' === $mode && in_array( $form, (array) AARYE_Settings::get( 'question_forms' ), true );
	}

	/**
	 * Signature of a question.
	 *
	 * @param int    $sum  Answer.
	 * @param int    $time When it was asked.
	 * @param string $salt Random part.
	 * @return string
	 */
	private static function sign( $sum, $time, $salt ) {
		return substr( hash_hmac( 'sha256', 'aarye_q|' . $sum . '|' . $time . '|' . $salt, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * A new question and its signed token (nothing is stored until it is answered).
	 *
	 * @return array{text: string, token: string}
	 */
	public static function new_question() {
		$a    = random_int( 2, 12 );
		$b    = random_int( 1, 9 );
		$time = time();
		$salt = wp_generate_password( 12, false );
		return array(
			/* translators: 1: first number, 2: second number. */
			'text'  => sprintf( __( 'What is %1$d + %2$d?', 'aarye-marketing-addon' ), $a, $b ),
			'token' => $time . '.' . $salt . '.' . self::sign( $a + $b, $time, $salt ),
		);
	}

	/**
	 * Check an answer. Each question can be used once.
	 *
	 * @param string $token  Token.
	 * @param string $answer Answer.
	 * @return bool
	 */
	public static function verify( $token, $answer ) {
		$parts = explode( '.', (string) $token );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! preg_match( '/^[A-Za-z0-9]{12}$/', $parts[1] ) ) {
			return false;
		}
		$time   = (int) $parts[0];
		$answer = trim( (string) $answer );
		if ( $time > time() + MINUTE_IN_SECONDS || $time < time() - self::TTL || '' === $answer || ! ctype_digit( $answer ) ) {
			return false;
		}
		if ( ! hash_equals( self::sign( (int) $answer, $time, $parts[1] ), $parts[2] ) ) {
			return false;
		}
		$used = 'aarye_q_' . $parts[1];
		if ( get_transient( $used ) ) {
			return false;
		}
		set_transient( $used, 1, self::TTL );
		return true;
	}

	/**
	 * REST route that hands out questions (pages may be cached, questions must not be).
	 */
	public static function routes() {
		register_rest_route(
			'aarye/v1',
			'/question',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$response = new WP_REST_Response( self::new_question() );
					$response->header( 'Cache-Control', 'no-store, max-age=0' );
					return $response;
				},
			)
		);
	}

	/**
	 * Script that adds the question above the submit button.
	 */
	public static function enqueue() {
		if ( 'off' === AARYE_Settings::get( 'question' ) || ( is_user_logged_in() && current_user_can( 'edit_posts' ) && ! apply_filters( 'aarye_question_for_editors', false ) ) ) {
			return;
		}
		wp_enqueue_style( 'aarye-protect', AARYE_URL . 'assets/css/protect.css', array(), aarye_asset_version( 'assets/css/protect.css' ) );
		wp_enqueue_script(
			'aarye-protect',
			AARYE_URL . 'assets/js/protect.js',
			array(),
			aarye_asset_version( 'assets/js/protect.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'aarye-protect',
			'aaryeProtect',
			array(
				'url'   => esc_url_raw( rest_url( 'aarye/v1/question' ) ),
				'all'   => 'all' === AARYE_Settings::get( 'question' ),
				'forms' => array_values( (array) AARYE_Settings::get( 'question_forms' ) ),
				'label' => __( 'Security question', 'aarye-marketing-addon' ),
				'retry' => __( 'New question', 'aarye-marketing-addon' ),
			)
		);
	}

	/* ------------------------------------------------------------------ The check */

	/**
	 * Whether a submission may go through.
	 *
	 * @param string $plugin  Form plugin key.
	 * @param string $form_id Form ID.
	 * @return string Message for the visitor, or "" when the submission may go through.
	 */
	public static function refuse( $plugin, $form_id ) {
		static $done = array();
		$form = $plugin . ':' . $form_id;
		if ( isset( $done[ $form ] ) ) {
			return $done[ $form ];
		}
		$reason  = '';
		$message = '';
		$ip      = AARYE_Entries::ip();
		$rule    = '' !== $ip ? self::blocked_by( $ip, $form ) : null;
		if ( $rule ) {
			$reason  = '' === $rule['form'] ? __( 'Blocked IP (all forms)', 'aarye-marketing-addon' ) : __( 'Blocked IP (this form)', 'aarye-marketing-addon' );
			$message = __( 'Sorry, this form cannot be sent from your connection. Please contact us another way.', 'aarye-marketing-addon' );
		} elseif ( AARYE_Settings::on( 'block_automation' ) ) {
			$visit = AARYE_Tracker::read();
			if ( ! empty( $visit['extra']['wd'] ) ) {
				$reason  = __( 'Browser controlled by automation software', 'aarye-marketing-addon' );
				$message = __( 'Sorry, this form cannot be sent by automated browsers.', 'aarye-marketing-addon' );
			}
		}
		if ( '' === $reason && self::asks( $form ) && ! ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Public form; the signed one-time question is the check.
			$token  = isset( $_POST['aarye_q_token'] ) ? sanitize_text_field( wp_unslash( $_POST['aarye_q_token'] ) ) : '';
			$answer = isset( $_POST['aarye_q_answer'] ) ? sanitize_text_field( wp_unslash( $_POST['aarye_q_answer'] ) ) : '';
			// phpcs:enable
			if ( ! self::verify( $token, $answer ) ) {
				$reason  = '' === $token ? __( 'Security question missing (sent without the page)', 'aarye-marketing-addon' ) : __( 'Wrong answer to the security question', 'aarye-marketing-addon' );
				$message = __( 'Please answer the security question correctly.', 'aarye-marketing-addon' );
			}
		}
		if ( '' === $message ) {
			/**
			 * Refuse a form submission: return a message for the visitor (or an array with "message" and
			 * "reason" for the list of refused submissions), or "" to let it through.
			 *
			 * @param string $message Message.
			 * @param string $plugin  Form plugin.
			 * @param string $form_id Form ID.
			 */
			$result = apply_filters( 'aarye_refuse_submission', '', $plugin, $form_id );
			if ( is_array( $result ) ) {
				$message = isset( $result['message'] ) ? (string) $result['message'] : '';
				$reason  = isset( $result['reason'] ) ? (string) $result['reason'] : '';
			} else {
				$message = (string) $result;
			}
		}
		if ( '' !== $message ) {
			self::log( $form, '' !== $reason ? $reason : __( 'Refused by an add-on', 'aarye-marketing-addon' ) );
		}
		$done[ $form ] = $message;
		return $message;
	}

	/**
	 * Contact Form 7.
	 *
	 * @param bool   $spam       Already spam.
	 * @param object $submission Submission.
	 * @return bool
	 */
	public static function cf7( $spam, $submission = null ) {
		if ( $spam || ! $submission || ! method_exists( $submission, 'get_contact_form' ) ) {
			return $spam;
		}
		$message = self::refuse( 'cf7', (string) $submission->get_contact_form()->id() );
		if ( '' !== $message ) {
			self::$cf7_refused = $message;
			if ( method_exists( $submission, 'add_spam_log' ) ) {
				$submission->add_spam_log( array( 'agent' => 'aarye', 'reason' => $message ) );
			}
			return true;
		}
		return $spam;
	}

	/**
	 * Contact Form 7 shows its own spam message; show why instead.
	 *
	 * @param string $message Message.
	 * @param string $status  Message type.
	 * @return string
	 */
	public static function cf7_message( $message, $status ) {
		if ( 'spam' === $status && '' !== self::$cf7_refused ) {
			return self::$cf7_refused;
		}
		return $message;
	}

	/**
	 * WPForms.
	 *
	 * @param array $entry     Entry.
	 * @param array $form_data Form.
	 */
	public static function wpforms( $entry, $form_data ) {
		$id      = isset( $form_data['id'] ) ? (string) $form_data['id'] : '';
		$message = self::refuse( 'wpforms', $id );
		if ( '' !== $message && function_exists( 'wpforms' ) ) {
			wpforms()->get( 'process' )->errors[ $id ]['header'] = esc_html( $message );
		}
	}

	/**
	 * Elementor Pro forms.
	 *
	 * @param object $record  Record.
	 * @param object $handler Ajax handler.
	 */
	public static function elementor( $record, $handler ) {
		$id      = method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'id' ) : '';
		$message = self::refuse( 'elementor', $id );
		if ( '' === $message ) {
			return;
		}
		$fields = method_exists( $record, 'get' ) ? (array) $record->get( 'fields' ) : array();
		$first  = $fields ? (string) key( $fields ) : '';
		if ( '' !== $first && method_exists( $handler, 'add_error' ) ) {
			$handler->add_error( $first, $message );
		}
		if ( method_exists( $handler, 'add_error_message' ) ) {
			$handler->add_error_message( $message );
		}
	}

	/**
	 * Gravity Forms.
	 *
	 * @param array $result Validation result.
	 * @return array
	 */
	public static function gravity( $result ) {
		$form    = isset( $result['form'] ) ? $result['form'] : array();
		$message = self::refuse( 'gravity', isset( $form['id'] ) ? (string) $form['id'] : '' );
		if ( '' !== $message && ! empty( $form['fields'] ) ) {
			$result['is_valid'] = false;
			$field              = $form['fields'][0];
			if ( is_object( $field ) ) {
				$field->failed_validation  = true;
				$field->validation_message = $message;
			}
			$result['form'] = $form;
		}
		return $result;
	}

	/**
	 * Fluent Forms.
	 *
	 * @param array  $errors Errors.
	 * @param array  $data   Data.
	 * @param object $form   Form.
	 * @param array  $fields Fields.
	 * @return array
	 */
	public static function fluentform( $errors, $data = array(), $form = null, $fields = array() ) {
		$message = self::refuse( 'fluentform', is_object( $form ) && isset( $form->id ) ? (string) $form->id : '' );
		if ( '' !== $message ) {
			$errors['aarye'] = array( $message );
		}
		return $errors;
	}

	/**
	 * Formidable Forms.
	 *
	 * @param array $errors Errors.
	 * @param array $values Values.
	 * @return array
	 */
	public static function formidable( $errors, $values ) {
		$message = self::refuse( 'formidable', isset( $values['form_id'] ) ? (string) $values['form_id'] : '' );
		if ( '' !== $message ) {
			$errors['aarye'] = $message;
		}
		return $errors;
	}

	/**
	 * Forminator.
	 *
	 * @param array $errors  Errors.
	 * @param int   $form_id Form.
	 * @param array $data    Data.
	 * @return array
	 */
	public static function forminator( $errors, $form_id = 0, $data = array() ) {
		$message = self::refuse( 'forminator', (string) $form_id );
		if ( '' !== $message ) {
			$errors   = is_array( $errors ) ? $errors : array();
			$errors[] = array( 'aarye' => $message );
		}
		return $errors;
	}
}
