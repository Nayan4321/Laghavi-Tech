<?php
/**
 * "Connected accounts" section on the user's profile and on WooCommerce's Account details page.
 *
 * @package LaghaviSocialLogin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets logged-in users connect and disconnect social accounts.
 */
class LSL_Account {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_unlink' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_section' ) );
		add_action( 'admin_notices', array( __CLASS__, 'profile_notices' ) );
		add_action( 'woocommerce_after_edit_account_form', array( __CLASS__, 'wc_section' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
	}

	/**
	 * Providers connected to a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, string> Provider ID => email shared by that account.
	 */
	public static function linked( $user_id ) {
		$linked = array();
		foreach ( array_keys( LSL_Providers::all() ) as $provider ) {
			if ( get_user_meta( $user_id, LSL_Auth::meta_key( $provider ), true ) ) {
				$linked[ $provider ] = (string) get_user_meta( $user_id, '_lsl_' . $provider . '_email', true );
			}
		}
		return $linked;
	}

	/**
	 * Disconnect a provider when the user clicks "Disconnect".
	 */
	public static function maybe_unlink() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce checked below.
		if ( empty( $_GET['lsl_unlink'] ) ) {
			return;
		}
		$provider = sanitize_key( wp_unslash( $_GET['lsl_unlink'] ) );
		$user_id  = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : get_current_user_id();
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$from     = isset( $_GET['lsl_from'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['lsl_from'] ) ), home_url( '/' ) ) : home_url( '/' );
		// phpcs:enable

		if ( ! is_user_logged_in() || ! LSL_Providers::exists( $provider ) || ! wp_verify_nonce( $nonce, 'lsl_unlink_' . $provider . '_' . $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'lsl_error', 'expired', $from ) );
			exit;
		}
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_user', $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'lsl_error', 'failed', $from ) );
			exit;
		}

		LSL_Auth::unlink( $user_id, $provider );
		wp_safe_redirect( add_query_arg( 'lsl_notice', 'unlinked', $from ) );
		exit;
	}

	/**
	 * Disconnect URL.
	 *
	 * @param string $provider Provider ID.
	 * @param int    $user_id  User ID.
	 * @param string $from     Page to return to.
	 * @return string
	 */
	private static function unlink_url( $provider, $user_id, $from ) {
		return add_query_arg(
			array(
				'lsl_unlink' => rawurlencode( $provider ),
				'user_id'    => $user_id,
				'lsl_from'   => rawurlencode( $from ),
				'_wpnonce'   => wp_create_nonce( 'lsl_unlink_' . $provider . '_' . $user_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Rows of connected and available providers.
	 *
	 * @param int    $user_id User whose accounts are shown.
	 * @param string $from    Page to return to.
	 * @return array[] Each row: provider, label, email, connected, url.
	 */
	private static function rows( $user_id, $from ) {
		$linked  = self::linked( $user_id );
		$enabled = LSL_Providers::enabled();
		$own     = get_current_user_id() === $user_id;
		$rows    = array();

		foreach ( array_keys( LSL_Providers::all() ) as $provider ) {
			$connected = array_key_exists( $provider, $linked );
			// Only the user can connect their own account; admins can disconnect.
			if ( ! $connected && ( ! $own || ! in_array( $provider, $enabled, true ) ) ) {
				continue;
			}
			$rows[] = array(
				'provider'  => $provider,
				'label'     => LSL_Providers::label( $provider ),
				'email'     => $connected ? $linked[ $provider ] : '',
				'connected' => $connected,
				'url'       => $connected ? self::unlink_url( $provider, $user_id, $from ) : LSL_Auth::link_url( $provider, $from ),
			);
		}
		return $rows;
	}

	/**
	 * Section on Users > Profile.
	 *
	 * @param WP_User $user User being edited.
	 */
	public static function profile_section( $user ) {
		$from = get_current_user_id() === $user->ID
			? admin_url( 'profile.php' )
			: add_query_arg( 'user_id', $user->ID, admin_url( 'user-edit.php' ) );
		$rows = self::rows( $user->ID, $from );
		if ( ! $rows ) {
			return;
		}

		wp_enqueue_style( 'laghavi-social-login', LSL_URL . 'assets/css/lsl.css', array(), LSL_VERSION );
		?>
		<h2><?php esc_html_e( 'Connected accounts', 'laghavi-social-login' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Log in with any connected account instead of a password.', 'laghavi-social-login' ); ?></p>
		<table class="form-table" role="presentation">
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<th scope="row">
						<span class="lsl-inline-icon lsl-button--<?php echo esc_attr( $row['provider'] ); ?>"><?php LSL_Buttons::output( LSL_Buttons::icon( $row['provider'] ) ); ?></span>
						<?php echo esc_html( $row['label'] ); ?>
					</th>
					<td><?php self::row_action( $row ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Section on WooCommerce My Account > Account details.
	 */
	public static function wc_section() {
		$user_id = get_current_user_id();
		$rows    = self::rows( $user_id, wc_get_account_endpoint_url( 'edit-account' ) );
		if ( ! $rows ) {
			return;
		}

		wp_enqueue_style( 'laghavi-social-login' );
		?>
		<fieldset class="lsl-connected">
			<legend><?php esc_html_e( 'Connected accounts', 'laghavi-social-login' ); ?></legend>
			<p><?php esc_html_e( 'Log in with any connected account instead of a password.', 'laghavi-social-login' ); ?></p>
			<ul class="lsl-connected__list">
				<?php foreach ( $rows as $row ) : ?>
					<li>
						<span class="lsl-connected__name">
							<span class="lsl-inline-icon lsl-button--<?php echo esc_attr( $row['provider'] ); ?>"><?php LSL_Buttons::output( LSL_Buttons::icon( $row['provider'] ) ); ?></span>
							<?php echo esc_html( $row['label'] ); ?>
						</span>
						<span><?php self::row_action( $row ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</fieldset>
		<?php
	}

	/**
	 * Status and connect/disconnect link for one provider.
	 *
	 * @param array $row Row from rows().
	 */
	private static function row_action( $row ) {
		if ( $row['connected'] ) {
			if ( '' !== $row['email'] ) {
				/* translators: %s: email address of the connected account. */
				echo esc_html( sprintf( __( 'Connected as %s', 'laghavi-social-login' ), $row['email'] ) ) . ' &middot; ';
			} else {
				echo esc_html__( 'Connected', 'laghavi-social-login' ) . ' &middot; ';
			}
			printf(
				'<a href="%s" class="lsl-disconnect">%s</a>',
				esc_url( $row['url'] ),
				esc_html__( 'Disconnect', 'laghavi-social-login' )
			);
			return;
		}
		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url( $row['url'] ),
			/* translators: %s: provider name, e.g. Google. */
			esc_html( sprintf( __( 'Connect %s', 'laghavi-social-login' ), $row['label'] ) )
		);
	}

	/**
	 * Success and error messages on the profile screen.
	 */
	public static function profile_notices() {
		global $pagenow;
		if ( 'profile.php' !== $pagenow && 'user-edit.php' !== $pagenow ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( ! empty( $_GET['lsl_error'] ) ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( LSL_Auth::error_message( sanitize_key( wp_unslash( $_GET['lsl_error'] ) ) ) ) );
		} elseif ( ! empty( $_GET['lsl_notice'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( LSL_Auth::notice_message( sanitize_key( wp_unslash( $_GET['lsl_notice'] ) ) ) ) );
		}
		// phpcs:enable
	}

	/**
	 * "Social login" column on Users.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function users_column( $columns ) {
		$columns['lsl_social'] = __( 'Social login', 'laghavi-social-login' );
		return $columns;
	}

	/**
	 * Value of the "Social login" column.
	 *
	 * @param string $output  Column output.
	 * @param string $column  Column name.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public static function users_column_value( $output, $column, $user_id ) {
		if ( 'lsl_social' !== $column ) {
			return $output;
		}
		$labels = array_map( array( 'LSL_Providers', 'label' ), array_keys( self::linked( $user_id ) ) );
		return $labels ? esc_html( implode( ', ', $labels ) ) : '&mdash;';
	}
}
