<?php
/**
 * Custom registration fields: text, number, phone, date, dropdown, radio buttons, multiple select,
 * checkboxes and a single checkbox. Shown on the WordPress and WooCommerce registration forms and
 * when an account is created at checkout, saved on the user, and editable later in WooCommerce
 * My Account > Account details and in the WordPress profile.
 *
 * @package SaralaLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registration fields.
 */
class SLPP_Fields {

	const OPTION = 'slpp_fields';
	const PAGE   = 'slpp-fields';
	const NONCE  = 'slpp_fields_nonce';

	/**
	 * Add the browser's "required" attribute (off at checkout, where the account fields can be hidden).
	 *
	 * @var bool
	 */
	private static $html_required = true;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
		add_action( 'admin_post_slpp_fields', array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		if ( ! self::fields() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'front_assets' ) );

		// Registration forms.
		add_action( 'register_form', array( __CLASS__, 'wp_register_fields' ), 15 );
		add_filter( 'registration_errors', array( __CLASS__, 'wp_register_errors' ) );
		add_action( 'woocommerce_register_form', array( __CLASS__, 'wc_register_fields' ), 15 );
		add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'wc_register_errors' ) );
		add_action( 'woocommerce_after_checkout_registration_form', array( __CLASS__, 'checkout_fields' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'checkout_errors' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'save_registration' ), 20 );
		add_action( 'saralopa_user_registered', array( __CLASS__, 'social_registered' ) );

		// Account details.
		add_action( 'woocommerce_edit_account_form', array( __CLASS__, 'wc_account_fields' ) );
		add_action( 'woocommerce_save_account_details_errors', array( __CLASS__, 'wc_account_errors' ), 10, 2 );
		add_action( 'woocommerce_save_account_details', array( __CLASS__, 'wc_account_save' ) );
		add_action( 'woocommerce_account_content', array( __CLASS__, 'incomplete_notice' ), 1 );

		// WordPress profile.
		add_action( 'show_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'profile_errors' ), 10, 3 );
		add_action( 'personal_options_update', array( __CLASS__, 'profile_save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'profile_save' ) );

		// Users list and personal data export.
		add_filter( 'manage_users_columns', array( __CLASS__, 'columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'column_value' ), 10, 3 );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
	}

	/*
	 * Field definitions.
	 */

	/**
	 * Field types.
	 *
	 * @return array<string, string>
	 */
	public static function types() {
		return array(
			'text'        => __( 'Text', 'sarala-login-page-pro' ),
			'textarea'    => __( 'Paragraph text', 'sarala-login-page-pro' ),
			'number'      => __( 'Number', 'sarala-login-page-pro' ),
			'tel'         => __( 'Phone number', 'sarala-login-page-pro' ),
			'date'        => __( 'Date', 'sarala-login-page-pro' ),
			'select'      => __( 'Dropdown', 'sarala-login-page-pro' ),
			'radio'       => __( 'Radio buttons', 'sarala-login-page-pro' ),
			'multiselect' => __( 'Multiple select', 'sarala-login-page-pro' ),
			'checkboxes'  => __( 'Checkboxes (pick several)', 'sarala-login-page-pro' ),
			'checkbox'    => __( 'Single checkbox (yes / no)', 'sarala-login-page-pro' ),
		);
	}

	/**
	 * Types with a list of options.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	private static function has_options( $type ) {
		return in_array( $type, array( 'select', 'radio', 'multiselect', 'checkboxes' ), true );
	}

	/**
	 * Types that store several values.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	private static function is_multi( $type ) {
		return in_array( $type, array( 'multiselect', 'checkboxes' ), true );
	}

	/**
	 * Saved fields, in order.
	 *
	 * @return array[]
	 */
	public static function fields() {
		$fields = get_option( self::OPTION, array() );
		return is_array( $fields ) ? array_values( array_filter( $fields, 'is_array' ) ) : array();
	}

	/**
	 * Meta keys a field may not use (WordPress and WooCommerce account data, roles and passwords).
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function reserved( $key ) {
		global $wpdb;
		$blocked = array( 'user_login', 'user_pass', 'user_email', 'user_url', 'user_nicename', 'display_name', 'nickname', 'first_name', 'last_name', 'description', 'role', 'roles', 'capabilities', 'user_level', 'session_tokens', 'rich_editing', 'syntax_highlighting', 'comment_shortcuts', 'admin_color', 'use_ssl', 'show_admin_bar_front', 'locale', 'dismissed_wp_pointers', 'default_password_nag', 'paying_customer', 'wc_last_active', 'account_email', 'password', 'email', 'username' );
		return in_array( $key, $blocked, true ) || 0 === strpos( $key, $wpdb->prefix ) || 0 === strpos( $key, 'wp_' ) || 0 === strpos( $key, '_' ) || 0 === strpos( $key, 'saralopa' ) || 0 === strpos( $key, 'slpp_' ) && 0 !== strpos( $key, 'slpp_f_' );
	}

	/**
	 * Options of a field: value => label. One per line; "value | Label" keeps a fixed value.
	 *
	 * @param array $field Field.
	 * @return array<string, string>
	 */
	public static function options( $field ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $field['options'] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$value = $parts[0];
			$label = isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : $parts[0];
			$out[ $value ] = $label;
		}
		return $out;
	}

	/**
	 * Fields for a place: register, account (editable by the customer) or admin (everything).
	 *
	 * @param string $where register, account or admin.
	 * @return array[]
	 */
	private static function for_place( $where ) {
		$out = array();
		foreach ( self::fields() as $f ) {
			if ( 'register' === $where && empty( $f['register'] ) ) {
				continue;
			}
			if ( 'account' === $where && 'hidden' === $f['account'] ) {
				continue;
			}
			$out[] = $f;
		}
		return $out;
	}

	/*
	 * Reading, checking and saving values.
	 */

	/**
	 * Whether our nonce came with the form.
	 *
	 * @return bool
	 */
	private static function nonce_ok() {
		return isset( $_POST[ self::NONCE ] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), 'slpp_fields' );
	}

	/**
	 * Posted value of a field, cleaned for its type. Call after nonce_ok().
	 *
	 * @param array $f Field.
	 * @return string|string[]
	 */
	private static function posted( $f ) {
		$name = 'slpp_field_' . $f['key'];
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Callers check nonce_ok() first.
		if ( self::is_multi( $f['type'] ) ) {
			$raw = isset( $_POST[ $name ] ) ? (array) wp_unslash( $_POST[ $name ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value cleaned below.
			return array_values( array_intersect( array_map( 'sanitize_text_field', $raw ), array_map( 'strval', array_keys( self::options( $f ) ) ) ) );
		}
		if ( 'checkbox' === $f['type'] ) {
			return isset( $_POST[ $name ] ) ? 'yes' : '';
		}
		$raw = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below per type.
		// phpcs:enable
		$raw = is_string( $raw ) ? $raw : '';
		switch ( $f['type'] ) {
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'number':
				$raw = trim( $raw );
				return is_numeric( $raw ) ? (string) ( 0 + $raw ) : ( '' === $raw ? '' : 'NaN' );
			case 'tel':
				return preg_replace( '/[^0-9+()\-\s.]/', '', sanitize_text_field( $raw ) );
			case 'date':
				$raw = sanitize_text_field( $raw );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : ( '' === $raw ? '' : 'bad-date' );
			case 'select':
			case 'radio':
				$raw = sanitize_text_field( $raw );
				return isset( self::options( $f )[ $raw ] ) ? $raw : '';
			default:
				return sanitize_text_field( $raw );
		}
	}

	/**
	 * Check the posted values.
	 *
	 * @param array[]  $fields Fields.
	 * @param WP_Error $errors Errors to add to.
	 * @return WP_Error
	 */
	private static function check( $fields, $errors ) {
		if ( ! $fields ) {
			return $errors;
		}
		if ( ! self::nonce_ok() ) {
			$errors->add( 'slpp_fields', __( 'The form expired. Please reload the page and try again.', 'sarala-login-page-pro' ) );
			return $errors;
		}
		foreach ( $fields as $f ) {
			$value = self::posted( $f );
			$label = '<strong>' . esc_html( $f['label'] ) . '</strong>';
			if ( ! empty( $f['required'] ) && ( '' === $value || array() === $value ) ) {
				/* translators: %s: field label. */
				$errors->add( 'slpp_field_' . $f['key'], sprintf( __( '%s is required.', 'sarala-login-page-pro' ), $label ) );
				continue;
			}
			if ( 'number' === $f['type'] && '' !== $value ) {
				if ( 'NaN' === $value ) {
					/* translators: %s: field label. */
					$errors->add( 'slpp_field_' . $f['key'], sprintf( __( '%s must be a number.', 'sarala-login-page-pro' ), $label ) );
				} elseif ( '' !== $f['min'] && (float) $value < (float) $f['min'] ) {
					/* translators: 1: field label, 2: lowest number. */
					$errors->add( 'slpp_field_' . $f['key'], sprintf( __( '%1$s must be at least %2$s.', 'sarala-login-page-pro' ), $label, esc_html( $f['min'] ) ) );
				} elseif ( '' !== $f['max'] && (float) $value > (float) $f['max'] ) {
					/* translators: 1: field label, 2: highest number. */
					$errors->add( 'slpp_field_' . $f['key'], sprintf( __( '%1$s must be at most %2$s.', 'sarala-login-page-pro' ), $label, esc_html( $f['max'] ) ) );
				}
			}
			if ( 'date' === $f['type'] && 'bad-date' === $value ) {
				/* translators: %s: field label. */
				$errors->add( 'slpp_field_' . $f['key'], sprintf( __( '%s is not a valid date.', 'sarala-login-page-pro' ), $label ) );
			}
			if ( 'tel' === $f['type'] && '' !== $value && strlen( preg_replace( '/\D/', '', $value ) ) < 6 ) {
				/* translators: %s: field label. */
				$errors->add( 'slpp_field_' . $f['key'], sprintf( __( '%s is not a valid phone number.', 'sarala-login-page-pro' ), $label ) );
			}
		}
		return $errors;
	}

	/**
	 * Save the posted values of these fields on a user.
	 *
	 * @param int     $user_id User.
	 * @param array[] $fields  Fields.
	 */
	private static function save( $user_id, $fields ) {
		if ( ! $user_id || ! self::nonce_ok() ) {
			return;
		}
		foreach ( $fields as $f ) {
			$value = self::posted( $f );
			if ( in_array( $value, array( 'NaN', 'bad-date' ), true ) ) {
				continue;
			}
			update_user_meta( $user_id, $f['key'], $value );
		}
		if ( ! self::missing_required( $user_id ) ) {
			delete_user_meta( $user_id, '_slpp_fields_incomplete' );
		}
	}

	/**
	 * Required registration fields the user has not filled in.
	 *
	 * @param int $user_id User.
	 * @return array[]
	 */
	private static function missing_required( $user_id ) {
		$out = array();
		foreach ( self::for_place( 'register' ) as $f ) {
			if ( ! empty( $f['required'] ) && 'hidden' !== $f['account'] ) {
				$v = get_user_meta( $user_id, $f['key'], true );
				if ( '' === $v || array() === $v ) {
					$out[] = $f;
				}
			}
		}
		return $out;
	}

	/**
	 * A stored value, readable.
	 *
	 * @param array $f     Field.
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function display( $f, $value ) {
		if ( 'checkbox' === $f['type'] ) {
			return 'yes' === $value ? __( 'Yes', 'sarala-login-page-pro' ) : ( '' === $value ? '' : __( 'No', 'sarala-login-page-pro' ) );
		}
		if ( self::has_options( $f['type'] ) ) {
			$opts   = self::options( $f );
			$labels = array();
			foreach ( (array) $value as $v ) {
				if ( '' !== $v ) {
					$labels[] = isset( $opts[ $v ] ) ? $opts[ $v ] : $v;
				}
			}
			return implode( ', ', $labels );
		}
		if ( 'date' === $f['type'] && '' !== $value && false !== strtotime( (string) $value ) ) {
			return date_i18n( get_option( 'date_format' ), strtotime( (string) $value ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/*
	 * Rendering.
	 */

	/**
	 * The inputs.
	 *
	 * @param array[] $fields  Fields.
	 * @param array   $values  key => value.
	 * @param string  $style   wp, wc or table.
	 * @param bool    $nonce   Print the nonce field.
	 */
	private static function render( $fields, $values, $style, $nonce = true ) {
		if ( ! $fields ) {
			return;
		}
		if ( $nonce ) {
			wp_nonce_field( 'slpp_fields', self::NONCE, false );
		}
		if ( 'wc' === $style || 'wp' === $style ) {
			echo '<div class="slpp-fields slpp-fields--' . esc_attr( $style ) . '">';
		}
		foreach ( $fields as $f ) {
			$id    = 'slpp-field-' . $f['key'];
			$name  = 'slpp_field_' . $f['key'];
			$value = array_key_exists( $f['key'], $values ) ? $values[ $f['key'] ] : '';
			$req   = ! empty( $f['required'] );
			$star  = $req ? ( 'wc' === $style ? ' <span class="required" aria-hidden="true">*</span>' : ' <span class="slpp-req" aria-hidden="true">*</span>' ) : '';
			$label = '<label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . $star . '</label>';
			$input = self::input( $f, $id, $name, $value, $req );
			$help  = '' !== $f['help'] ? '<span class="description slpp-field-help">' . esc_html( $f['help'] ) . '</span>' : '';
			$width = 'half' === $f['width'] ? ' slpp-field--half' : '';
			if ( 'table' === $style ) {
				echo '<tr><th>' . wp_kses( $label, self::kses_label() ) . '</th><td>' . $input . ( $help ? '<p>' . $help . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in input().
			} elseif ( 'wc' === $style ) {
				echo '<p class="woocommerce-form-row form-row form-row-wide slpp-field slpp-field--' . esc_attr( $f['type'] . $width ) . '">' . wp_kses( $label, self::kses_label() ) . $input . $help . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in input().
			} else {
				echo '<p class="slpp-field slpp-field--' . esc_attr( $f['type'] . $width ) . '">' . wp_kses( $label, self::kses_label() ) . $input . $help . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in input().
			}
		}
		if ( 'wc' === $style || 'wp' === $style ) {
			echo '</div>';
		}
	}

	/**
	 * Allowed tags in a label.
	 *
	 * @return array
	 */
	private static function kses_label() {
		return array(
			'label' => array( 'for' => true ),
			'span'  => array(
				'class'       => true,
				'aria-hidden' => true,
			),
		);
	}

	/**
	 * One input (escaped HTML).
	 *
	 * @param array  $f     Field.
	 * @param string $id    ID.
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 * @param bool   $req   Required.
	 * @return string
	 */
	private static function input( $f, $id, $name, $value, $req ) {
		$ph   = '' !== $f['placeholder'] ? ' placeholder="' . esc_attr( $f['placeholder'] ) . '"' : '';
		$reqa = $req ? ( self::$html_required ? ' required' : '' ) . ' aria-required="true"' : '';
		switch ( $f['type'] ) {
			case 'textarea':
				return '<textarea class="input-text input slpp-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3"' . $ph . $reqa . '>' . esc_textarea( (string) $value ) . '</textarea>';
			case 'number':
				$attrs = ( '' !== $f['min'] ? ' min="' . esc_attr( $f['min'] ) . '"' : '' ) . ( '' !== $f['max'] ? ' max="' . esc_attr( $f['max'] ) . '"' : '' ) . ' step="' . esc_attr( '' !== $f['step'] ? $f['step'] : 'any' ) . '"';
				return '<input type="number" class="input-text input slpp-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . $attrs . $ph . $reqa . ' />';
			case 'select':
				$out = '<select class="slpp-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $reqa . '><option value="">' . esc_html( '' !== $f['placeholder'] ? $f['placeholder'] : __( 'Choose…', 'sarala-login-page-pro' ) ) . '</option>';
				foreach ( self::options( $f ) as $v => $l ) {
					$out .= '<option value="' . esc_attr( $v ) . '"' . selected( (string) $value, (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
				}
				return $out . '</select>';
			case 'multiselect':
				$out = '<select class="slpp-input" multiple id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '[]" size="' . esc_attr( (string) min( 6, max( 3, count( self::options( $f ) ) ) ) ) . '">';
				foreach ( self::options( $f ) as $v => $l ) {
					$out .= '<option value="' . esc_attr( $v ) . '"' . ( in_array( (string) $v, array_map( 'strval', (array) $value ), true ) ? ' selected' : '' ) . '>' . esc_html( $l ) . '</option>';
				}
				return $out . '</select><span class="description slpp-field-help">' . esc_html__( 'Hold Ctrl (Cmd on a Mac) to pick several.', 'sarala-login-page-pro' ) . '</span>';
			case 'radio':
			case 'checkboxes':
				$multi = 'checkboxes' === $f['type'];
				$out   = '<span class="slpp-choices" id="' . esc_attr( $id ) . '" role="' . ( $multi ? 'group' : 'radiogroup' ) . '">';
				$i     = 0;
				foreach ( self::options( $f ) as $v => $l ) {
					$checked = $multi ? in_array( (string) $v, array_map( 'strval', (array) $value ), true ) : (string) $value === (string) $v;
					$out    .= '<label class="slpp-choice"><input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" name="' . esc_attr( $name . ( $multi ? '[]' : '' ) ) . '" value="' . esc_attr( $v ) . '"' . ( $checked ? ' checked' : '' ) . ( $req && self::$html_required && ! $multi && 0 === $i ? ' required' : '' ) . ' /> ' . esc_html( $l ) . '</label>';
					++$i;
				}
				return $out . '</span>';
			case 'checkbox':
				return '<label class="slpp-choice"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="yes"' . checked( 'yes', $value, false ) . $reqa . ' /> ' . esc_html( '' !== $f['placeholder'] ? $f['placeholder'] : __( 'Yes', 'sarala-login-page-pro' ) ) . '</label>';
			default:
				$type = in_array( $f['type'], array( 'tel', 'date' ), true ) ? $f['type'] : 'text';
				return '<input type="' . esc_attr( $type ) . '" class="input-text input slpp-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . $ph . $reqa . ( 'tel' === $type ? ' autocomplete="tel"' : '' ) . ' />';
		}
	}

	/**
	 * Values sent with a form that came back with an error, so customers don't type them again.
	 *
	 * @param array[] $fields Fields.
	 * @return array
	 */
	private static function sent_values( $fields ) {
		$out = array();
		if ( ! self::nonce_ok() ) {
			return $out;
		}
		foreach ( $fields as $f ) {
			$v = self::posted( $f );
			if ( ! in_array( $v, array( 'NaN', 'bad-date' ), true ) ) {
				$out[ $f['key'] ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Values stored on a user.
	 *
	 * @param int     $user_id User.
	 * @param array[] $fields  Fields.
	 * @return array
	 */
	private static function user_values( $user_id, $fields ) {
		$out = array();
		foreach ( $fields as $f ) {
			$out[ $f['key'] ] = get_user_meta( $user_id, $f['key'], true );
		}
		return $out;
	}

	/*
	 * Registration.
	 */

	/**
	 * WordPress registration form.
	 */
	public static function wp_register_fields() {
		$fields = self::for_place( 'register' );
		self::render( $fields, self::sent_values( $fields ), 'wp' );
	}

	/**
	 * WordPress registration form: check.
	 *
	 * @param WP_Error $errors Errors.
	 * @return WP_Error
	 */
	public static function wp_register_errors( $errors ) {
		return self::check( self::for_place( 'register' ), $errors );
	}

	/**
	 * WooCommerce My Account registration form.
	 */
	public static function wc_register_fields() {
		$fields = self::for_place( 'register' );
		self::render( $fields, self::sent_values( $fields ), 'wc' );
	}

	/**
	 * WooCommerce registration: check (only for the My Account form; checkout is checked separately,
	 * and accounts made by Sarala's social sign-in complete their details later).
	 *
	 * @param WP_Error $errors Errors.
	 * @return WP_Error
	 */
	public static function wc_register_errors( $errors ) {
		if ( ! isset( $_POST['woocommerce-register-nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce-register-nonce'] ) ), 'woocommerce-register' ) ) {
			return $errors;
		}
		return self::check( self::for_place( 'register' ), $errors );
	}

	/**
	 * Checkout: shown with "Create an account".
	 */
	public static function checkout_fields() {
		self::$html_required = false;
		self::render( self::for_place( 'register' ), array(), 'wc' );
		self::$html_required = true;
	}

	/**
	 * Checkout: check when an account is created.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Errors.
	 */
	public static function checkout_errors( $data, $errors ) {
		if ( is_user_logged_in() ) {
			return;
		}
		$creating = ! empty( $data['createaccount'] ) || ( function_exists( 'WC' ) && WC()->checkout() && WC()->checkout()->is_registration_required() );
		if ( $creating ) {
			self::check( self::for_place( 'register' ), $errors );
		}
	}

	/**
	 * Save after any registration that sent our fields.
	 *
	 * @param int $user_id New user.
	 */
	public static function save_registration( $user_id ) {
		if ( self::nonce_ok() ) {
			self::save( $user_id, self::for_place( 'register' ) );
		}
	}

	/**
	 * Sign-up with Google, Facebook and others: ask for the required details on the account page.
	 *
	 * @param int $user_id User.
	 */
	public static function social_registered( $user_id ) {
		if ( self::missing_required( (int) $user_id ) ) {
			update_user_meta( (int) $user_id, '_slpp_fields_incomplete', 1 );
		}
	}

	/**
	 * My Account: ask to complete missing required details.
	 */
	public static function incomplete_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! function_exists( 'wc_get_account_endpoint_url' ) || is_wc_endpoint_url( 'edit-account' ) || ! self::missing_required( $user_id ) ) {
			return;
		}
		echo '<div class="woocommerce-info slpp-fields-incomplete">' . esc_html__( 'Please complete your account details.', 'sarala-login-page-pro' ) . ' <a class="button" href="' . esc_url( wc_get_account_endpoint_url( 'edit-account' ) ) . '">' . esc_html__( 'Complete now', 'sarala-login-page-pro' ) . '</a></div>';
	}

	/*
	 * Account details (WooCommerce).
	 */

	/**
	 * Fields a customer can change themselves.
	 *
	 * @return array[]
	 */
	private static function editable() {
		return array_values(
			array_filter(
				self::for_place( 'account' ),
				static function ( $f ) {
					return 'edit' === $f['account'];
				}
			)
		);
	}

	/**
	 * Read-only fields shown to the customer.
	 *
	 * @return array[]
	 */
	private static function read_only() {
		return array_values(
			array_filter(
				self::for_place( 'account' ),
				static function ( $f ) {
					return 'view' === $f['account'];
				}
			)
		);
	}

	/**
	 * My Account > Account details.
	 */
	public static function wc_account_fields() {
		$user_id = get_current_user_id();
		$edit    = self::editable();
		$view    = self::read_only();
		if ( ! $edit && ! $view ) {
			return;
		}
		echo '<fieldset class="slpp-account-fields"><legend>' . esc_html__( 'More about you', 'sarala-login-page-pro' ) . '</legend>';
		$values = self::nonce_ok() ? array_merge( self::user_values( $user_id, $edit ), self::sent_values( $edit ) ) : self::user_values( $user_id, $edit );
		self::render( $edit, $values, 'wc' );
		foreach ( $view as $f ) {
			$shown = self::display( $f, get_user_meta( $user_id, $f['key'], true ) );
			echo '<p class="woocommerce-form-row form-row form-row-wide slpp-field slpp-field--readonly"><span class="slpp-readonly-label">' . esc_html( $f['label'] ) . '</span> <strong>' . esc_html( '' !== $shown ? $shown : '—' ) . '</strong></p>';
		}
		echo '</fieldset>';
	}

	/**
	 * Account details: check.
	 *
	 * @param WP_Error $errors Errors.
	 * @param WP_User  $user   User.
	 */
	public static function wc_account_errors( $errors, $user ) {
		unset( $user );
		self::check( self::editable(), $errors );
	}

	/**
	 * Account details: save.
	 *
	 * @param int $user_id User.
	 */
	public static function wc_account_save( $user_id ) {
		if ( (int) $user_id === get_current_user_id() ) {
			self::save( (int) $user_id, self::editable() );
		}
	}

	/*
	 * WordPress profile.
	 */

	/**
	 * Fields on a profile screen: everything for people who can edit other users, else the customer's own editable ones.
	 *
	 * @param WP_User|int $user User.
	 * @return array[]
	 */
	private static function profile_set( $user ) {
		$user_id = $user instanceof WP_User ? $user->ID : (int) $user;
		if ( current_user_can( 'edit_users' ) && ( get_current_user_id() !== $user_id || current_user_can( 'list_users' ) ) ) {
			return self::fields();
		}
		return self::editable();
	}

	/**
	 * Profile fields.
	 *
	 * @param WP_User $user User.
	 */
	public static function profile_fields( $user ) {
		$fields = self::profile_set( $user );
		if ( ! $fields ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Registration details', 'sarala-login-page-pro' ) . '</h2><table class="form-table" role="presentation">';
		self::render( $fields, self::user_values( $user->ID, $fields ), 'table' );
		echo '</table>';
	}

	/**
	 * Profile: check (required fields are not forced on administrators editing other people).
	 *
	 * @param WP_Error $errors Errors.
	 * @param bool     $update Update.
	 * @param object   $user   User data.
	 */
	public static function profile_errors( $errors, $update, $user ) {
		if ( ! $update || empty( $user->ID ) || ! self::nonce_ok() ) {
			return;
		}
		$fields = self::profile_set( (int) $user->ID );
		if ( get_current_user_id() !== (int) $user->ID ) {
			$fields = array_map(
				static function ( $f ) {
					$f['required'] = false;
					return $f;
				},
				$fields
			);
		}
		self::check( $fields, $errors );
	}

	/**
	 * Profile: save.
	 *
	 * @param int $user_id User.
	 */
	public static function profile_save( $user_id ) {
		if ( current_user_can( 'edit_user', $user_id ) ) {
			self::save( (int) $user_id, self::profile_set( (int) $user_id ) );
		}
	}

	/*
	 * Users list and privacy.
	 */

	/**
	 * Users list columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function columns( $cols ) {
		foreach ( self::fields() as $f ) {
			if ( ! empty( $f['column'] ) ) {
				$cols[ 'slpp_f_' . $f['key'] ] = $f['label'];
			}
		}
		return $cols;
	}

	/**
	 * Users list column value.
	 *
	 * @param string $out     Output.
	 * @param string $column  Column.
	 * @param int    $user_id User.
	 * @return string
	 */
	public static function column_value( $out, $column, $user_id ) {
		if ( 0 !== strpos( $column, 'slpp_f_' ) ) {
			return $out;
		}
		$key = substr( $column, 7 );
		foreach ( self::fields() as $f ) {
			if ( $f['key'] === $key ) {
				return esc_html( self::display( $f, get_user_meta( $user_id, $key, true ) ) );
			}
		}
		return $out;
	}

	/**
	 * Personal data export.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['sarala-login-page-pro-fields'] = array(
			'exporter_friendly_name' => __( 'Registration details', 'sarala-login-page-pro' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Export one person's registration details.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function export( $email ) {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user ) {
			foreach ( self::fields() as $f ) {
				$v = self::display( $f, get_user_meta( $user->ID, $f['key'], true ) );
				if ( '' !== $v ) {
					$data[] = array(
						'name'  => $f['label'],
						'value' => $v,
					);
				}
			}
		}
		return array(
			'data' => $data ? array(
				array(
					'group_id'    => 'slpp-fields',
					'group_label' => __( 'Registration details', 'sarala-login-page-pro' ),
					'item_id'     => 'slpp-fields-' . ( $user ? $user->ID : 0 ),
					'data'        => $data,
				),
			) : array(),
			'done' => true,
		);
	}

	/*
	 * Assets.
	 */

	/**
	 * Front styles.
	 */
	public static function front_assets() {
		wp_enqueue_style( 'slpp-fields', SLPP_URL . 'assets/css/fields.css', array(), SLPP_VERSION );
	}

	/**
	 * Admin scripts for the field builder, and styles on profile screens.
	 *
	 * @param string $hook Screen.
	 */
	public static function admin_assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::PAGE ) ) {
			wp_enqueue_style( 'slpp-pro-admin', SLPP_URL . 'assets/css/pro-admin.css', array(), SLPP_VERSION );
			wp_enqueue_script( 'slpp-fields-admin', SLPP_URL . 'assets/js/fields-admin.js', array(), SLPP_VERSION, true );
		}
		if ( in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) && self::fields() ) {
			wp_enqueue_style( 'slpp-fields', SLPP_URL . 'assets/css/fields.css', array(), SLPP_VERSION );
		}
	}

	/*
	 * Settings screen.
	 */

	/**
	 * Menu item.
	 */
	public static function menu() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		add_submenu_page( 'sarala-login-page', __( 'Registration fields', 'sarala-login-page-pro' ), __( 'Registration fields', 'sarala-login-page-pro' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Defaults of a field.
	 *
	 * @return array
	 */
	private static function blank() {
		return array(
			'label'       => '',
			'key'         => '',
			'type'        => 'text',
			'options'     => '',
			'placeholder' => '',
			'help'        => '',
			'required'    => false,
			'register'    => true,
			'account'     => 'edit',
			'column'      => false,
			'width'       => 'full',
			'min'         => '',
			'max'         => '',
			'step'        => '',
		);
	}

	/**
	 * One field's editor.
	 *
	 * @param array      $f Field.
	 * @param int|string $i Index (or __i__ in the template).
	 */
	private static function editor( $f, $i ) {
		$f = wp_parse_args( $f, self::blank() );
		$n = static function ( $k ) use ( $i ) {
			return 'fields[' . $i . '][' . $k . ']';
		};
		?>
		<div class="slpp-field-card" data-type="<?php echo esc_attr( $f['type'] ); ?>">
			<div class="slpp-field-card__head">
				<strong class="slpp-field-card__title"><?php echo esc_html( '' !== $f['label'] ? $f['label'] : __( 'New field', 'sarala-login-page-pro' ) ); ?></strong>
				<span class="slpp-field-card__type"><?php echo esc_html( self::types()[ $f['type'] ] ?? '' ); ?></span>
				<span class="slpp-field-card__tools">
					<button type="button" class="button-link slpp-up" aria-label="<?php esc_attr_e( 'Move up', 'sarala-login-page-pro' ); ?>">↑</button>
					<button type="button" class="button-link slpp-down" aria-label="<?php esc_attr_e( 'Move down', 'sarala-login-page-pro' ); ?>">↓</button>
					<button type="button" class="button-link button-link-delete slpp-remove"><?php esc_html_e( 'Remove', 'sarala-login-page-pro' ); ?></button>
				</span>
			</div>
			<div class="slpp-field-grid">
				<label><?php esc_html_e( 'Label', 'sarala-login-page-pro' ); ?><input type="text" class="regular-text slpp-label" name="<?php echo esc_attr( $n( 'label' ) ); ?>" value="<?php echo esc_attr( $f['label'] ); ?>" required /></label>
				<label><?php esc_html_e( 'Type', 'sarala-login-page-pro' ); ?>
					<select class="slpp-type" name="<?php echo esc_attr( $n( 'type' ) ); ?>">
						<?php foreach ( self::types() as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['type'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Field key', 'sarala-login-page-pro' ); ?><input type="text" class="slpp-key code" name="<?php echo esc_attr( $n( 'key' ) ); ?>" value="<?php echo esc_attr( $f['key'] ); ?>" placeholder="<?php esc_attr_e( 'made from the label', 'sarala-login-page-pro' ); ?>" /><span class="description"><?php esc_html_e( 'Saved on the user under this name. Don\'t change it once people have signed up. Use billing_phone, billing_company… to fill WooCommerce\'s own fields.', 'sarala-login-page-pro' ); ?></span></label>
				<label class="slpp-only-options"><?php esc_html_e( 'Options (one per line)', 'sarala-login-page-pro' ); ?><textarea rows="4" name="<?php echo esc_attr( $n( 'options' ) ); ?>"><?php echo esc_textarea( $f['options'] ); ?></textarea><span class="description"><?php esc_html_e( 'Write "value | Label" to keep a fixed saved value when you rename an option later.', 'sarala-login-page-pro' ); ?></span></label>
				<label><?php esc_html_e( 'Placeholder', 'sarala-login-page-pro' ); ?><input type="text" name="<?php echo esc_attr( $n( 'placeholder' ) ); ?>" value="<?php echo esc_attr( $f['placeholder'] ); ?>" /><span class="description"><?php esc_html_e( 'Dropdown: the first, empty choice. Single checkbox: the text next to it.', 'sarala-login-page-pro' ); ?></span></label>
				<label><?php esc_html_e( 'Help text', 'sarala-login-page-pro' ); ?><input type="text" name="<?php echo esc_attr( $n( 'help' ) ); ?>" value="<?php echo esc_attr( $f['help'] ); ?>" /></label>
				<span class="slpp-only-number slpp-inline">
					<label><?php esc_html_e( 'Lowest', 'sarala-login-page-pro' ); ?><input type="number" step="any" class="small-text" name="<?php echo esc_attr( $n( 'min' ) ); ?>" value="<?php echo esc_attr( $f['min'] ); ?>" /></label>
					<label><?php esc_html_e( 'Highest', 'sarala-login-page-pro' ); ?><input type="number" step="any" class="small-text" name="<?php echo esc_attr( $n( 'max' ) ); ?>" value="<?php echo esc_attr( $f['max'] ); ?>" /></label>
					<label><?php esc_html_e( 'Step', 'sarala-login-page-pro' ); ?><input type="number" step="any" min="0" class="small-text" name="<?php echo esc_attr( $n( 'step' ) ); ?>" value="<?php echo esc_attr( $f['step'] ); ?>" placeholder="any" /></label>
				</span>
				<label><?php esc_html_e( 'Width', 'sarala-login-page-pro' ); ?>
					<select name="<?php echo esc_attr( $n( 'width' ) ); ?>">
						<option value="full" <?php selected( $f['width'], 'full' ); ?>><?php esc_html_e( 'Full width', 'sarala-login-page-pro' ); ?></option>
						<option value="half" <?php selected( $f['width'], 'half' ); ?>><?php esc_html_e( 'Half width', 'sarala-login-page-pro' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'In My Account and the profile', 'sarala-login-page-pro' ); ?>
					<select name="<?php echo esc_attr( $n( 'account' ) ); ?>">
						<option value="edit" <?php selected( $f['account'], 'edit' ); ?>><?php esc_html_e( 'Customers can change it', 'sarala-login-page-pro' ); ?></option>
						<option value="view" <?php selected( $f['account'], 'view' ); ?>><?php esc_html_e( 'Shown, only you can change it', 'sarala-login-page-pro' ); ?></option>
						<option value="hidden" <?php selected( $f['account'], 'hidden' ); ?>><?php esc_html_e( 'Hidden from customers', 'sarala-login-page-pro' ); ?></option>
					</select>
				</label>
			</div>
			<p class="slpp-field-checks">
				<label><input type="checkbox" name="<?php echo esc_attr( $n( 'register' ) ); ?>" value="1" <?php checked( ! empty( $f['register'] ) ); ?> /> <?php esc_html_e( 'Ask when signing up', 'sarala-login-page-pro' ); ?></label>
				<label><input type="checkbox" name="<?php echo esc_attr( $n( 'required' ) ); ?>" value="1" <?php checked( ! empty( $f['required'] ) ); ?> /> <?php esc_html_e( 'Required', 'sarala-login-page-pro' ); ?></label>
				<label><input type="checkbox" name="<?php echo esc_attr( $n( 'column' ) ); ?>" value="1" <?php checked( ! empty( $f['column'] ) ); ?> /> <?php esc_html_e( 'Column in the Users list', 'sarala-login-page-pro' ); ?></label>
			</p>
		</div>
		<?php
	}

	/**
	 * The screen.
	 */
	public static function page() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		$nonce = isset( $_GET['_slppnonce'] ) ? sanitize_key( wp_unslash( $_GET['_slppnonce'] ) ) : '';
		$view  = '' !== $nonce && wp_verify_nonce( $nonce, 'slpp_fields_view' );
		$saved = $view && ! empty( $_GET['saved'] );
		$err   = $view && isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		?>
		<div class="wrap saralopa-admin slpp-fields-admin">
			<h1><?php esc_html_e( 'Registration fields', 'sarala-login-page-pro' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Ask for more details when people sign up: numbers, dropdowns, radio buttons, multiple choices and more. Fields show on the WordPress and WooCommerce registration forms and when an account is created at checkout. Customers see and change their answers later in My Account > Account details (and in their profile); you see them on each user\'s profile and, if you like, as columns in the Users list.', 'sarala-login-page-pro' ); ?></p>
			<p class="description"><?php esc_html_e( 'People who sign up with Google, Facebook or another social log-in skip the form; if a required field is empty they are asked to complete their details in My Account.', 'sarala-login-page-pro' ); ?></p>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'sarala-login-page-pro' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $err ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %d: number of fields */ _n( '%d field was not saved: it needs a label, a key that is not used by WordPress, and options for choice fields.', '%d fields were not saved: each needs a label, a key that is not used by WordPress, and options for choice fields.', $err, 'sarala-login-page-pro' ), $err ) ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="slpp_fields" />
				<?php wp_nonce_field( 'slpp_fields_save' ); ?>
				<div id="slpp-field-list">
					<?php
					foreach ( self::fields() as $i => $f ) {
						self::editor( $f, $i );
					}
					?>
				</div>
				<p><button type="button" class="button" id="slpp-add-field">+ <?php esc_html_e( 'Add a field', 'sarala-login-page-pro' ); ?></button></p>
				<?php submit_button( __( 'Save fields', 'sarala-login-page-pro' ) ); ?>
			</form>
			<template id="slpp-field-template"><?php self::editor( array(), '__i__' ); ?></template>
		</div>
		<?php
	}

	/**
	 * Save the screen.
	 */
	public static function handle_form() {
		check_admin_referer( 'slpp_fields_save' );
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sarala-login-page-pro' ) );
		}
		$in      = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value cleaned below.
		$out     = array();
		$used    = array();
		$skipped = 0;
		foreach ( $in as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$f          = self::blank();
			$f['label'] = isset( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : '';
			$f['type']  = isset( $raw['type'] ) && isset( self::types()[ $raw['type'] ] ) ? (string) $raw['type'] : 'text';
			$key        = isset( $raw['key'] ) ? sanitize_key( $raw['key'] ) : '';
			$key        = '' !== $key ? $key : sanitize_key( str_replace( array( ' ', '-' ), '_', remove_accents( $f['label'] ) ) );
			$key        = '' !== $key && ! preg_match( '/^[a-z]/', $key ) ? 'f_' . $key : $key;
			$f['key']   = substr( $key, 0, 60 );
			foreach ( array( 'placeholder', 'help' ) as $k ) {
				$f[ $k ] = isset( $raw[ $k ] ) ? sanitize_text_field( $raw[ $k ] ) : '';
			}
			$f['options'] = isset( $raw['options'] ) ? sanitize_textarea_field( $raw['options'] ) : '';
			foreach ( array( 'min', 'max', 'step' ) as $k ) {
				$f[ $k ] = isset( $raw[ $k ] ) && is_numeric( $raw[ $k ] ) ? (string) ( 0 + $raw[ $k ] ) : '';
			}
			$f['required'] = ! empty( $raw['required'] );
			$f['register'] = ! empty( $raw['register'] );
			$f['column']   = ! empty( $raw['column'] );
			$f['account']  = isset( $raw['account'] ) && in_array( $raw['account'], array( 'edit', 'view', 'hidden' ), true ) ? (string) $raw['account'] : 'edit';
			$f['width']    = isset( $raw['width'] ) && 'half' === $raw['width'] ? 'half' : 'full';
			if ( '' === $f['label'] && '' === $f['key'] ) {
				continue; // An empty card.
			}
			if ( '' === $f['label'] || '' === $f['key'] || self::reserved( $f['key'] ) || isset( $used[ $f['key'] ] ) || ( self::has_options( $f['type'] ) && ! self::options( $f ) ) ) {
				++$skipped;
				continue;
			}
			$used[ $f['key'] ] = true;
			$out[]             = $f;
		}
		update_option( self::OPTION, $out, false );
		$args = array(
			'page'       => self::PAGE,
			'saved'      => 1,
			'_slppnonce' => wp_create_nonce( 'slpp_fields_view' ),
		);
		if ( $skipped ) {
			$args['skipped'] = $skipped;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
