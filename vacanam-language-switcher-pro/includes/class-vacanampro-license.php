<?php
/**
 * License key activation and automatic updates from laghavi.site.
 *
 * @package VacanamPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro license.
 */
class VACANAMPRO_License {

	const OPTION = 'vacanampro_license';
	const PAGE   = 'vacanam-language-switcher-pro-license';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_vacanampro_license', array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'vacanampro_daily', array( __CLASS__, 'check' ) );
		if ( ! wp_next_scheduled( 'vacanampro_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'vacanampro_daily' );
		}

		// WordPress 5.8+ asks plugins with an "Update URI" header for their own updates.
		add_filter( 'update_plugins_laghavi.site', array( __CLASS__, 'update_info' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_details' ), 10, 3 );
	}

	/**
	 * Saved license data.
	 *
	 * @return array
	 */
	public static function data() {
		$data = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $data ) ? $data : array(),
			array(
				'key'        => '',
				'status'     => '',
				'expires'    => '',
				'message'    => '',
				'last_check' => 0,
			)
		);
	}

	/**
	 * Whether Pro features are switched on.
	 *
	 * A key must have been activated once. After it expires, features keep working and only updates stop;
	 * a revoked key (for example after a refund) switches them off.
	 *
	 * @return bool
	 */
	public static function is_valid() {
		$status = self::data()['status'];
		return (bool) apply_filters( 'vacanampro_license_is_valid', in_array( $status, array( 'active', 'expired' ), true ) );
	}

	/**
	 * This site's address as saved in Settings > General. home_url() is not used: language and
	 * multisite plugins filter it (for example /ar/), and the license server would then see another site.
	 *
	 * @return string
	 */
	public static function site() {
		return untrailingslashit( (string) get_option( 'home' ) );
	}

	/**
	 * Call the license server.
	 *
	 * @param string $endpoint activate, deactivate, check or update.
	 * @param array  $args     Extra fields.
	 * @param string $method   HTTP method.
	 * @return array|WP_Error Decoded reply.
	 */
	public static function request( $endpoint, $args = array(), $method = 'POST' ) {
		$body = array_merge(
			array(
				'license_key' => self::data()['key'],
				'site_url'    => self::site(),
				'slug'        => VACANAMPRO_SLUG,
				'version'     => VACANAMPRO_VERSION,
			),
			$args
		);
		$url  = trailingslashit( VACANAMPRO_LICENSE_SERVER ) . $endpoint;

		$response = 'GET' === $method
			? wp_remote_get( add_query_arg( array_map( 'rawurlencode', $body ), $url ), array( 'timeout' => 15 ) )
			: wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'body'    => $body,
				)
			);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vacanampro_bad_reply', __( 'The license server sent an unexpected reply. Please try again later.', 'vacanam-language-switcher-pro' ) );
		}
		return $data;
	}

	/**
	 * Daily check, so refunds and renewals are picked up.
	 */
	public static function check() {
		$data = self::data();
		if ( '' === $data['key'] ) {
			return;
		}
		$reply = self::request( 'check' );
		$known = array( 'active', 'expired', 'revoked', 'invalid', 'inactive' );
		if ( is_wp_error( $reply ) || empty( $reply['status'] ) || ! in_array( $reply['status'], $known, true ) ) {
			// Server unreachable or busy: keep the last known status.
			return;
		}
		$status = sanitize_key( $reply['status'] );

		// The server does not know this site (its address changed, or the activation was removed):
		// activate it again, so the activity log and other features never stop on their own.
		if ( 'inactive' === $status ) {
			$again = self::request( 'activate' );
			if ( ! is_wp_error( $again ) && ! empty( $again['success'] ) && ! empty( $again['status'] ) ) {
				$reply  = $again;
				$status = sanitize_key( $again['status'] );
			}
		}

		$data['expires']    = isset( $reply['expires'] ) ? sanitize_text_field( (string) $reply['expires'] ) : $data['expires'];
		$data['message']    = isset( $reply['message'] ) ? sanitize_text_field( $reply['message'] ) : '';
		$data['last_check'] = time();
		// Only a revoked key (refund) or a deleted key switches Pro off. Any other answer keeps the
		// last good status and shows a notice, instead of silently stopping the logs.
		if ( in_array( $status, array( 'active', 'expired', 'revoked', 'invalid' ), true ) || ! in_array( $data['status'], array( 'active', 'expired' ), true ) ) {
			$data['status'] = $status;
		} else {
			$data['problem'] = $status;
		}
		if ( in_array( $status, array( 'active', 'expired' ), true ) ) {
			unset( $data['problem'] );
		}
		update_option( self::OPTION, $data );
	}

	/**
	 * Who may manage the license: administrators.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Submenu under Languages.
	 */
	public static function menu() {
		if ( ! self::current_user_can_manage() ) {
			return;
		}
		add_submenu_page(
			'vacanam',
			__( 'Pro license', 'vacanam-language-switcher-pro' ),
			__( 'Pro license', 'vacanam-language-switcher-pro' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Reminder to enter a key.
	 */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, self::PAGE ) ) {
			return;
		}
		$data = self::data();
		if ( self::is_valid() ) {
			if ( ! empty( $data['problem'] ) ) {
				printf(
					'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
					esc_html__( 'Vacanam Pro: the license server could not confirm this site, so Pro keeps working with the last known license. Please check the key.', 'vacanam-language-switcher-pro' ),
					esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
					esc_html__( 'License', 'vacanam-language-switcher-pro' )
				);
			}
			return;
		}
		if ( '' !== $data['key'] ) {
			printf(
				'<div class="notice notice-error"><p>%s %s <a href="%s">%s</a></p></div>',
				esc_html__( 'Vacanam Pro features are switched off:', 'vacanam-language-switcher-pro' ),
				esc_html( '' !== $data['message'] ? $data['message'] : $data['status'] ),
				esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
				esc_html__( 'License', 'vacanam-language-switcher-pro' )
			);
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Vacanam Pro is installed but not activated yet.', 'vacanam-language-switcher-pro' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Enter your license key', 'vacanam-language-switcher-pro' )
		);
	}

	/**
	 * Activate or deactivate the key.
	 */
	public static function handle_form() {
		if ( ! self::current_user_can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'vacanam-language-switcher-pro' ) );
		}
		check_admin_referer( 'vacanampro_license' );

		$data = self::data();
		$todo = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';

		if ( 'activate' === $todo ) {
			$key   = isset( $_POST['license_key'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) ) : '';
			$reply = self::request( 'activate', array( 'license_key' => $key ) );
			if ( is_wp_error( $reply ) ) {
				$data['message'] = $reply->get_error_message();
			} elseif ( empty( $reply['success'] ) ) {
				$data['message'] = isset( $reply['message'] ) ? sanitize_text_field( $reply['message'] ) : __( 'This key could not be activated.', 'vacanam-language-switcher-pro' );
			} else {
				$data = array(
					'key'        => $key,
					'status'     => sanitize_key( $reply['status'] ),
					'expires'    => isset( $reply['expires'] ) ? sanitize_text_field( (string) $reply['expires'] ) : '',
					'message'    => '',
					'last_check' => time(),
				);
				// The person who activates Pro becomes the super admin if none is set yet.
				if ( ! get_option( 'vacanampro_owner' ) ) {
					update_option( 'vacanampro_owner', get_current_user_id() );
				}
			}
		} elseif ( 'deactivate' === $todo ) {
			self::request( 'deactivate' );
			$data = array(
				'key'        => '',
				'status'     => '',
				'expires'    => '',
				'message'    => __( 'The key was removed from this site. You can use it on another site now.', 'vacanam-language-switcher-pro' ),
				'last_check' => 0,
			);
		}

		update_option( self::OPTION, $data );
		delete_site_transient( 'update_plugins' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * The license page.
	 */
	public static function page() {
		if ( ! self::current_user_can_manage() ) {
			return;
		}
		$data   = self::data();
		$labels = array(
			'active'   => __( 'Active', 'vacanam-language-switcher-pro' ),
			'expired'  => __( 'Expired: Pro keeps working, but updates have stopped. Renew on laghavi.site to get updates again.', 'vacanam-language-switcher-pro' ),
			'revoked'  => __( 'Revoked: this key is no longer valid.', 'vacanam-language-switcher-pro' ),
			'invalid'  => __( 'Invalid key.', 'vacanam-language-switcher-pro' ),
			'inactive' => __( 'This site is not activated for this key.', 'vacanam-language-switcher-pro' ),
		);
		?>
		<div class="wrap vacanam-wrap">
			<h1><?php esc_html_e( 'Vacanam Pro license', 'vacanam-language-switcher-pro' ); ?></h1>
			<?php if ( '' !== $data['message'] ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $data['message'] ); ?></p></div>
			<?php endif; ?>
			<div class="vacanam-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="vacanampro_license" />
					<?php wp_nonce_field( 'vacanampro_license' ); ?>
					<?php if ( '' === $data['key'] ) : ?>
						<p><?php esc_html_e( 'Enter the license key from your purchase email or from My Account > Licenses on laghavi.site.', 'vacanam-language-switcher-pro' ); ?></p>
						<p>
							<input type="text" name="license_key" class="regular-text code" placeholder="XXXXX-XXXXX-XXXXX-XXXXX" required autocomplete="off" />
							<button type="submit" name="todo" value="activate" class="button button-primary"><?php esc_html_e( 'Activate', 'vacanam-language-switcher-pro' ); ?></button>
						</p>
						<p><a href="https://laghavi.site/" target="_blank" rel="noopener"><?php esc_html_e( 'Buy a license on laghavi.site', 'vacanam-language-switcher-pro' ); ?></a></p>
					<?php else : ?>
						<table class="form-table" role="presentation">
							<tr><th scope="row"><?php esc_html_e( 'License key', 'vacanam-language-switcher-pro' ); ?></th><td><code><?php echo esc_html( substr( $data['key'], 0, 5 ) . str_repeat( '•', 12 ) . substr( $data['key'], -5 ) ); ?></code></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Status', 'vacanam-language-switcher-pro' ); ?></th><td><?php echo esc_html( isset( $labels[ $data['status'] ] ) ? $labels[ $data['status'] ] : $data['status'] ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Updates until', 'vacanam-language-switcher-pro' ); ?></th><td><?php echo esc_html( '' !== $data['expires'] ? mysql2date( get_option( 'date_format' ), $data['expires'] ) : __( 'Lifetime', 'vacanam-language-switcher-pro' ) ); ?></td></tr>
						</table>
						<p><button type="submit" name="todo" value="deactivate" class="button"><?php esc_html_e( 'Deactivate on this site', 'vacanam-language-switcher-pro' ); ?></button></p>
					<?php endif; ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Tell WordPress about a new version.
	 *
	 * @param array|false $update      Update data so far.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file.
	 * @return array|false
	 */
	public static function update_info( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( VACANAMPRO_FILE ) !== $plugin_file || '' === self::data()['key'] ) {
			return $update;
		}

		$info = get_transient( 'vacanampro_update_info' );
		if ( false === $info ) {
			$info = self::request( 'update', array(), 'GET' );
			$info = is_wp_error( $info ) ? array() : $info;
			set_transient( 'vacanampro_update_info', $info, 12 * HOUR_IN_SECONDS );
		}
		if ( empty( $info['version'] ) ) {
			return $update;
		}

		return array(
			'id'           => 'laghavi.site/' . VACANAMPRO_SLUG,
			'slug'         => VACANAMPRO_SLUG,
			'version'      => $info['version'],
			'url'          => 'https://laghavi.site/',
			'package'      => isset( $info['package'] ) ? $info['package'] : '',
			'tested'       => isset( $info['tested'] ) ? $info['tested'] : '',
			'requires_php' => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'icons'        => array( '1x' => VACANAMPRO_URL . 'assets/icon-256x256.png' ),
		);
	}

	/**
	 * "View details" popup on the Plugins screen.
	 *
	 * @param false|object $result Result so far.
	 * @param string       $action API action.
	 * @param object       $args   Arguments.
	 * @return false|object
	 */
	public static function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || VACANAMPRO_SLUG !== $args->slug ) {
			return $result;
		}
		$info = self::request( 'update', array(), 'GET' );
		if ( is_wp_error( $info ) || empty( $info['version'] ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Vacanam Language Switcher Pro',
			'slug'          => VACANAMPRO_SLUG,
			'version'       => $info['version'],
			'author'        => '<a href="https://laghavi.site/">Laghavi Digital</a>',
			'homepage'      => 'https://laghavi.site/',
			'requires'      => isset( $info['requires'] ) ? $info['requires'] : '',
			'tested'        => isset( $info['tested'] ) ? $info['tested'] : '',
			'requires_php'  => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'download_link' => isset( $info['package'] ) ? $info['package'] : '',
			'sections'      => array(
				'description' => esc_html__( 'AI and DeepL translation, translated addresses, domains per language, WooCommerce, currencies per language and more for Vacanam Language Switcher.', 'vacanam-language-switcher-pro' ),
				'changelog'   => isset( $info['changelog'] ) ? wp_kses_post( $info['changelog'] ) : '',
			),
		);
	}
}
