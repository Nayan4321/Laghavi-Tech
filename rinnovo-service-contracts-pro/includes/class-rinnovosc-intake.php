<?php
/**
 * Intake forms right after payment (thank-you page) and in My Account: the product's own questions
 * (logins are stored encrypted, files in a private folder) or your Gravity Forms / WPForms / Contact Form 7
 * form, which marks the brief done when the client sends it. Clients get reminders until it is done.
 *
 * @package RinnovoContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Intake.
 */
class RINNOVOSC_Intake {

	const TD     = 'rinnovo-service-contracts-pro';
	const FOLDER = 'rinnovosc-briefs';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 6 );
		add_action( 'admin_post_rinnovosc_file', array( __CLASS__, 'download' ) );
		add_action( 'gform_after_submission', array( __CLASS__, 'gravity' ), 10, 2 );
		add_action( 'wpforms_process_complete', array( __CLASS__, 'wpforms' ), 10, 4 );
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'cf7' ) );
		add_action( 'rinnovosc_daily', array( __CLASS__, 'reminders' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'rinnovosc_daily' ) ) {
					wp_schedule_event( time() + 3600, 'daily', 'rinnovosc_daily' );
				}
			}
		);
	}

	/**
	 * Questions of a product.
	 *
	 * @param string $text Lines "label|type|required".
	 * @return array[]
	 */
	public static function questions( $text ) {
		$out = array();
		foreach ( RINNOVOSC_Product::lines( $text ) as $i => $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			$type  = isset( $parts[1] ) && in_array( $parts[1], array( 'text', 'textarea', 'url', 'email', 'secret', 'file' ), true ) ? $parts[1] : 'text';
			$out[] = array(
				'key'      => 'q' . $i,
				'label'    => $parts[0],
				'type'     => $type,
				'required' => isset( $parts[2] ) && 'required' === strtolower( $parts[2] ),
			);
		}
		return $out;
	}

	/**
	 * Encryption key.
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'rinnovosc', true );
	}

	/**
	 * Encrypt a secret.
	 *
	 * @param string $plain Text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$iv = random_bytes( 16 );
		return 'enc:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Storage encoding.
	}

	/**
	 * Decrypt a secret.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		if ( 0 !== strpos( (string) $stored, 'enc:' ) ) {
			return (string) $stored;
		}
		$raw = (string) base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Storage encoding.
		return (string) openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
	}

	/**
	 * Contracts of the current user that still need a brief.
	 *
	 * @param int $user_id User.
	 * @return array[] Each: sub, contract.
	 */
	public static function pending( $user_id ) {
		$out = array();
		foreach ( RINNOVOSC_Store::ids_for_user( $user_id ) as $id ) {
			$sub = RINNOVO_Subscriptions::get( $id );
			$c   = RINNOVOSC_Store::get( $id );
			if ( $sub && $c && $c['intake']['required'] && ! $c['intake']['done'] && in_array( $sub->status, RINNOVO_Subscriptions::live_statuses(), true ) ) {
				$out[] = array(
					'sub'      => $sub,
					'contract' => $c,
				);
			}
		}
		return $out;
	}

	/**
	 * The form for one contract.
	 *
	 * @param object $sub      Subscription.
	 * @param array  $contract Contract.
	 * @return string
	 */
	public static function form( $sub, $contract ) {
		$terms = RINNOVOSC_Product::terms( (int) $sub->product_id );
		$html  = '<div class="rinnovosc-intake"><h3>' . esc_html__( 'Your project brief', self::TD ) . '</h3>';
		if ( '' !== trim( $terms['intake_form'] ) ) {
			return $html . do_shortcode( $terms['intake_form'] ) . '</div>';
		}
		$answers = $contract['intake']['answers'];
		$html   .= '<p>' . esc_html__( 'A few details so we can start right away. You can change them later in your account.', self::TD ) . '</p><form method="post" enctype="multipart/form-data">' . wp_nonce_field( 'rinnovosc_intake_' . $sub->id, 'rinnovosc_intake_nonce', true, false ) . '<input type="hidden" name="rinnovosc_intake" value="' . (int) $sub->id . '" />';
		foreach ( self::questions( $terms['intake'] ) as $q ) {
			$id    = 'rinnovosc-' . $q['key'];
			$value = isset( $answers[ $q['key'] ]['value'] ) ? (string) $answers[ $q['key'] ]['value'] : '';
			$req   = $q['required'] && ( 'file' !== $q['type'] || '' === $value ) ? ' required' : '';
			$html .= '<p class="form-row form-row-wide"><label for="' . esc_attr( $id ) . '">' . esc_html( $q['label'] ) . ( $q['required'] ? ' <span class="required">*</span>' : '' ) . '</label>';
			if ( 'textarea' === $q['type'] ) {
				$html .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $q['key'] ) . '" rows="4"' . $req . '>' . esc_textarea( $value ) . '</textarea>';
			} elseif ( 'file' === $q['type'] ) {
				$html .= '<input type="file" id="' . esc_attr( $id ) . '" name="' . esc_attr( $q['key'] ) . '"' . $req . ' />' . ( '' !== $value ? ' <small>' . esc_html__( 'Uploaded', self::TD ) . '</small>' : '' );
			} elseif ( 'secret' === $q['type'] ) {
				$html .= '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $q['key'] ) . '" autocomplete="off" placeholder="' . esc_attr( '' !== $value ? __( '(saved, encrypted — type to replace)', self::TD ) : __( 'User name and password', self::TD ) ) . '"' . ( '' === $value ? $req : '' ) . ' /><small>' . esc_html__( 'Stored encrypted, only visible to our team.', self::TD ) . '</small>';
			} else {
				$html .= '<input type="' . esc_attr( 'url' === $q['type'] || 'email' === $q['type'] ? $q['type'] : 'text' ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $q['key'] ) . '" value="' . esc_attr( $value ) . '"' . $req . ' />';
			}
			$html .= '</p>';
		}
		return $html . '<p><button type="submit" class="button alt">' . esc_html__( 'Send the brief', self::TD ) . '</button></p></form></div>';
	}

	/**
	 * On the thank-you page.
	 *
	 * @param int $order_id Order.
	 */
	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_customer_id() || get_current_user_id() !== (int) $order->get_customer_id() ) {
			return;
		}
		foreach ( RINNOVO_Subscriptions::for_order( $order_id ) as $sub ) {
			$c = RINNOVOSC_Store::get( $sub->id );
			if ( $c && $c['intake']['required'] && ! $c['intake']['done'] ) {
				echo self::form( $sub, $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in form().
			}
		}
	}

	/**
	 * Save the built-in form.
	 */
	public static function handle() {
		if ( empty( $_POST['rinnovosc_intake'] ) || ! is_user_logged_in() ) {
			return;
		}
		$id = absint( $_POST['rinnovosc_intake'] );
		check_admin_referer( 'rinnovosc_intake_' . $id, 'rinnovosc_intake_nonce' );
		$sub = RINNOVO_Subscriptions::get( $id );
		$c   = RINNOVOSC_Store::get( $id );
		if ( ! $sub || ! $c || (int) $sub->user_id !== get_current_user_id() ) {
			return;
		}
		$terms = RINNOVOSC_Product::terms( (int) $sub->product_id );
		foreach ( self::questions( $terms['intake'] ) as $q ) {
			$key = $q['key'];
			if ( 'file' === $q['type'] ) {
				if ( ! empty( $_FILES[ $key ]['name'] ) ) {
					$path = self::store_upload( $key );
					if ( is_wp_error( $path ) ) {
						wc_add_notice( $path->get_error_message(), 'error' );
						continue;
					}
					$c['intake']['answers'][ $key ] = array(
						'label' => $q['label'],
						'type'  => 'file',
						'value' => $path,
					);
				}
				continue;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned next.
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$val = 'textarea' === $q['type'] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
			if ( 'secret' === $q['type'] && '' === $val ) {
				continue;
			}
			$c['intake']['answers'][ $key ] = array(
				'label' => $q['label'],
				'type'  => $q['type'],
				'value' => 'secret' === $q['type'] ? self::encrypt( $val ) : $val,
			);
		}
		$c['intake']['done'] = true;
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, RINNOVOSC_Store::log( $c, __( 'Brief received', self::TD ) ) );
		$mailer = WC()->mailer();
		$mailer->send( get_option( 'admin_email' ), __( 'New project brief received', self::TD ), $mailer->wrap_message( __( 'Project brief', self::TD ), '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . RINNOVOSC_Admin::PAGE . '&sub=' . (int) $sub->id ) ) . '">' . esc_html__( 'Open the contract', self::TD ) . '</a></p>' ) );
		wc_add_notice( __( 'Thank you! We have your brief.', self::TD ) );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : wc_get_account_endpoint_url( RINNOVOSC_Account::ENDPOINT ) );
		exit;
	}

	/**
	 * Keep an uploaded file in the private briefs folder.
	 *
	 * @param string $key Field.
	 * @return string|WP_Error Relative path.
	 */
	private static function store_upload( $key ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . self::FOLDER;
		if ( wp_mkdir_p( $dir ) && ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Server rule.
			file_put_contents( $dir . '/index.php', "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Silence.
		}
		$filter = static function ( $dirs ) use ( $dir, $up ) {
			$dirs['path']   = $dir;
			$dirs['url']    = trailingslashit( $up['baseurl'] ) . self::FOLDER;
			$dirs['subdir'] = '/' . self::FOLDER;
			return $dirs;
		};
		add_filter( 'upload_dir', $filter );
		$file = wp_handle_upload( $_FILES[ $key ], array( 'test_form' => false ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in handle(); wp_handle_upload validates.
		remove_filter( 'upload_dir', $filter );
		if ( isset( $file['error'] ) ) {
			return new WP_Error( 'rinnovosc_upload', $file['error'] );
		}
		return self::FOLDER . '/' . basename( $file['file'] );
	}

	/**
	 * Download a brief file (staff only).
	 */
	public static function download() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', self::TD ) );
		}
		check_admin_referer( 'rinnovosc_file' );
		$rel  = isset( $_GET['f'] ) ? sanitize_text_field( wp_unslash( $_GET['f'] ) ) : '';
		$up   = wp_upload_dir();
		$path = trailingslashit( $up['basedir'] ) . self::FOLDER . '/' . basename( $rel );
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'File not found.', self::TD ) );
		}
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Download.
		exit;
	}

	/**
	 * Mark the current client's brief done when their form plugin entry comes in.
	 *
	 * @param string $plugin Form plugin.
	 * @param int    $form   Form ID.
	 * @param string $entry  Entry reference.
	 */
	private static function external_done( $plugin, $form, $entry ) {
		foreach ( self::pending( get_current_user_id() ) as $row ) {
			$sc = (string) $row['contract']['intake']['form'];
			if ( '' === $sc || ! preg_match( '/id=["\']?' . preg_quote( (string) $form, '/' ) . '["\'\s\]]/', $sc . ' ' ) ) {
				continue;
			}
			$c                              = $row['contract'];
			$c['intake']['done']            = true;
			$c['intake']['answers']['form'] = array(
				'label' => $plugin,
				'type'  => 'text',
				'value' => $entry,
			);
			RINNOVOSC_Store::save( $row['sub']->id, $row['sub']->user_id, RINNOVOSC_Store::log( $c, __( 'Brief received', self::TD ) . ' (' . $plugin . ')' ) );
		}
	}

	/**
	 * Gravity Forms.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 */
	public static function gravity( $entry, $form ) {
		self::external_done( 'Gravity Forms', isset( $form['id'] ) ? (int) $form['id'] : 0, 'entry #' . ( isset( $entry['id'] ) ? (int) $entry['id'] : 0 ) );
	}

	/**
	 * WPForms.
	 *
	 * @param array $fields    Fields.
	 * @param array $entry     Entry.
	 * @param array $form_data Form.
	 * @param int   $entry_id  Entry.
	 */
	public static function wpforms( $fields, $entry, $form_data, $entry_id ) {
		self::external_done( 'WPForms', isset( $form_data['id'] ) ? (int) $form_data['id'] : 0, 'entry #' . (int) $entry_id );
	}

	/**
	 * Contact Form 7.
	 *
	 * @param object $form Form.
	 */
	public static function cf7( $form ) {
		self::external_done( 'Contact Form 7', is_object( $form ) && method_exists( $form, 'id' ) ? (int) $form->id() : 0, 'sent ' . gmdate( 'Y-m-d H:i' ) );
	}

	/**
	 * Daily: remind clients of a missing brief (after 1, 3 and 7 days).
	 */
	public static function reminders() {
		foreach ( RINNOVOSC_Store::all_ids( 1000 ) as $id ) {
			$sub = RINNOVO_Subscriptions::get( $id );
			$c   = RINNOVOSC_Store::get( $id );
			if ( ! $sub || ! $c || ! $c['intake']['required'] || $c['intake']['done'] || ! in_array( $sub->status, RINNOVO_Subscriptions::live_statuses(), true ) ) {
				continue;
			}
			$age  = (int) floor( ( time() - strtotime( $sub->start_date . ' UTC' ) ) / DAY_IN_SECONDS );
			$sent = isset( $c['intake']['reminded'] ) ? (int) $c['intake']['reminded'] : 0;
			$due  = array( 1, 3, 7 );
			if ( $sent >= 3 || $age < $due[ $sent ] ) {
				continue;
			}
			$user = get_userdata( (int) $sub->user_id );
			if ( $user ) {
				$mailer = WC()->mailer();
				$mailer->send( $user->user_email, __( 'We need your project brief to get started', self::TD ), $mailer->wrap_message( __( 'Your project brief', self::TD ), '<p>' . esc_html__( 'Thanks for choosing us! Please send your project brief so we can start on this month\'s work.', self::TD ) . '</p><p><a href="' . esc_url( wc_get_account_endpoint_url( RINNOVOSC_Account::ENDPOINT ) ) . '">' . esc_html__( 'Fill in the brief', self::TD ) . '</a></p>' ) );
			}
			$c['intake']['reminded'] = $sent + 1;
			RINNOVOSC_Store::save( $sub->id, $sub->user_id, $c );
		}
	}
}
