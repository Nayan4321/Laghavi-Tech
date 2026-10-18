<?php
/**
 * Settings storage and the admin settings page.
 *
 * @package SimpleLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
class SLP_Settings {

	const OPTION = 'slp_settings';
	const PAGE   = 'simple-login-page';

	/**
	 * Cached settings for this request.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
		add_action( 'update_option_' . self::OPTION, array( 'SLP_Login_Url', 'notify_change' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'credit_notice' ) );
		add_action( 'admin_post_slp_credit', array( __CLASS__, 'credit_answer' ) );
	}

	/**
	 * Ask once whether the site owner wants to show the "Powered by Laghavi Digital" link.
	 * WordPress.org only allows front-end credit links the owner has agreed to.
	 */
	public static function credit_notice() {
		if ( ! current_user_can( 'manage_options' ) || get_option( 'slp_credit_asked' ) || self::get( 'show_credit' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'toplevel_page_' . self::PAGE ), true ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=slp_credit' ), 'slp_credit' );
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Thanks for using Simple Login Page!', 'simple-login-page' ); ?></strong>
				<?php esc_html_e( 'This plugin is free. Would you support it by showing a small "Powered by Laghavi Digital" link under the login buttons? You can change this any time in the settings.', 'simple-login-page' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( add_query_arg( 'answer', 'yes', $url ) ); ?>" class="button button-primary"><?php esc_html_e( 'Yes, show it', 'simple-login-page' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'answer', 'no', $url ) ); ?>" class="button"><?php esc_html_e( 'No thanks', 'simple-login-page' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Save the answer to the credit notice.
	 */
	public static function credit_answer() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'simple-login-page' ) );
		}
		check_admin_referer( 'slp_credit' );

		update_option( 'slp_credit_asked', 1, false );
		if ( isset( $_GET['answer'] ) && 'yes' === $_GET['answer'] ) {
			$settings                = self::get();
			$settings['show_credit'] = 1;
			update_option( self::OPTION, $settings );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		$providers = array();
		foreach ( SLP_Providers::all() as $id => $provider ) {
			$providers[ $id ] = array( 'enabled' => 0 );
			foreach ( $provider['fields'] as $field ) {
				$providers[ $id ][ $field ] = '';
			}
		}

		return array(
			'allow_registration' => 1,
			'show_wp_login'      => 1,
			'show_wp_register'   => 1,
			'show_wc_login'      => 1,
			'show_wc_register'   => 1,
			'show_wc_checkout'   => 1,
			'captcha_login'      => 1,
			'captcha_register'   => 1,
			// WordPress.org guideline 10: a front-end credit link must be opted into by the site owner.
			'show_credit'        => 0,
			'login_slug'         => '',
			'log_failed_logins'  => 1,
			'log_retention_days' => 30,
			'ip_source'          => 'remote_addr',
			'geo_lookup'         => 0,
			'geo_token'          => '',
			'button_text'        => 'continue',
			'layout'             => 'full',
			'providers'          => $providers,
		);
	}

	/**
	 * Read a setting, or all settings.
	 *
	 * @param string|null $key Setting name.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$settings ) {
			$saved    = get_option( self::OPTION, array() );
			$saved    = is_array( $saved ) ? $saved : array();
			$defaults = self::defaults();
			$settings = array_merge( $defaults, $saved );

			foreach ( $defaults['providers'] as $id => $provider_defaults ) {
				$saved_provider               = isset( $saved['providers'][ $id ] ) && is_array( $saved['providers'][ $id ] ) ? $saved['providers'][ $id ] : array();
				$settings['providers'][ $id ] = array_merge( $provider_defaults, $saved_provider );
			}
			self::$settings = $settings;
		}

		if ( null === $key ) {
			return self::$settings;
		}
		return isset( self::$settings[ $key ] ) ? self::$settings[ $key ] : null;
	}

	/**
	 * Forget cached settings after they are saved.
	 */
	public static function flush_cache() {
		self::$settings = null;
	}

	/**
	 * Admin menu entry with the Laghavi icon.
	 */
	public static function menu() {
		add_menu_page(
			__( 'Simple Login Page', 'simple-login-page' ),
			__( 'Simple Login', 'simple-login-page' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' ),
			SLP_URL . 'assets/images/menu-icon.png',
			71
		);
		// Name the first submenu item "Settings" instead of repeating the menu title.
		add_submenu_page( self::PAGE, __( 'Simple Login Page', 'simple-login-page' ), __( 'Settings', 'simple-login-page' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Admin styles and script, on this plugin's page only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		// The menu icon is a PNG, so give it the same size as dashicons everywhere in wp-admin.
		wp_add_inline_style( 'wp-admin', '#toplevel_page_simple-login-page .wp-menu-image img{width:20px;height:20px;padding:7px 0 0;opacity:1}' );

		// The settings page and the Failed logins page.
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'slp-admin', SLP_URL . 'assets/css/admin.css', array(), SLP_VERSION );
		wp_enqueue_script( 'slp-admin', SLP_URL . 'assets/js/admin.js', array(), SLP_VERSION, true );
		wp_localize_script(
			'slp-admin',
			'slpAdmin',
			array( 'copied' => __( 'Copied!', 'simple-login-page' ) )
		);
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			'slp_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Clean submitted settings. Secrets left blank keep their saved value.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$old      = self::get();
		$defaults = self::defaults();
		$clean    = array();

		foreach ( array( 'allow_registration', 'show_wp_login', 'show_wp_register', 'show_wc_login', 'show_wc_register', 'show_wc_checkout', 'captcha_login', 'captcha_register', 'show_credit', 'log_failed_logins', 'geo_lookup' ) as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		$clean['button_text'] = isset( $input['button_text'] ) && in_array( $input['button_text'], array( 'continue', 'signin', 'login' ), true ) ? $input['button_text'] : 'continue';
		$clean['layout']      = isset( $input['layout'] ) && 'icons' === $input['layout'] ? 'icons' : 'full';

		$clean['log_retention_days'] = isset( $input['log_retention_days'] ) ? min( 365, max( 1, absint( $input['log_retention_days'] ) ) ) : 30;
		$clean['ip_source']          = isset( $input['ip_source'] ) && in_array( $input['ip_source'], array( 'remote_addr', 'cloudflare', 'forwarded', 'real_ip' ), true ) ? $input['ip_source'] : 'remote_addr';
		$clean['geo_token']          = isset( $input['geo_token'] ) && '' !== trim( $input['geo_token'] ) ? sanitize_text_field( $input['geo_token'] ) : ( isset( $old['geo_token'] ) ? $old['geo_token'] : '' );

		$slug = SLP_Login_Url::validate( isset( $input['login_slug'] ) ? (string) $input['login_slug'] : '' );
		if ( is_wp_error( $slug ) ) {
			add_settings_error( self::OPTION, $slug->get_error_code(), $slug->get_error_message() );
			$slug = isset( $old['login_slug'] ) ? $old['login_slug'] : '';
		}
		$clean['login_slug'] = $slug;

		$secrets = array( 'client_secret', 'private_key' );
		foreach ( $defaults['providers'] as $id => $provider_defaults ) {
			$submitted = isset( $input['providers'][ $id ] ) && is_array( $input['providers'][ $id ] ) ? $input['providers'][ $id ] : array();
			$provider  = array( 'enabled' => empty( $submitted['enabled'] ) ? 0 : 1 );

			foreach ( array_keys( $provider_defaults ) as $field ) {
				if ( 'enabled' === $field ) {
					continue;
				}
				$value = isset( $submitted[ $field ] ) ? (string) $submitted[ $field ] : '';
				$value = 'private_key' === $field ? trim( sanitize_textarea_field( $value ) ) : trim( sanitize_text_field( $value ) );

				if ( '' === $value && in_array( $field, $secrets, true ) ) {
					$value = isset( $old['providers'][ $id ][ $field ] ) ? $old['providers'][ $id ][ $field ] : '';
				}
				$provider[ $field ] = $value;
			}

			if ( 'apple' === $id && '' !== $provider['private_key'] && false === openssl_pkey_get_private( $provider['private_key'] ) ) {
				add_settings_error( self::OPTION, 'slp_apple_key', __( 'The Apple private key is not valid. Paste the full contents of the .p8 file, including the BEGIN and END lines.', 'simple-login-page' ) );
				$provider['private_key'] = isset( $old['providers']['apple']['private_key'] ) ? $old['providers']['apple']['private_key'] : '';
			}

			if ( $provider['enabled'] ) {
				$missing = false;
				foreach ( SLP_Providers::all()[ $id ]['fields'] as $field ) {
					if ( '' === $provider[ $field ] ) {
						$missing = true;
					}
				}
				if ( $missing ) {
					add_settings_error(
						self::OPTION,
						'slp_missing_' . $id,
						/* translators: %s: provider name. */
						sprintf( __( '%s is switched on but some keys are missing, so its button stays hidden until they are filled in.', 'simple-login-page' ), SLP_Providers::label( $id ) ),
						'warning'
					);
				}
			}

			$clean['providers'][ $id ] = $provider;
		}

		return $clean;
	}

	/**
	 * Field labels.
	 *
	 * @param string $provider Provider ID.
	 * @param string $field    Field name.
	 * @return string
	 */
	private static function field_label( $provider, $field ) {
		$labels = array(
			'client_id'     => 'apple' === $provider ? __( 'Services ID', 'simple-login-page' ) : ( 'facebook' === $provider ? __( 'App ID', 'simple-login-page' ) : __( 'Client ID', 'simple-login-page' ) ),
			'client_secret' => 'facebook' === $provider ? __( 'App Secret', 'simple-login-page' ) : __( 'Client Secret', 'simple-login-page' ),
			'team_id'       => __( 'Team ID', 'simple-login-page' ),
			'key_id'        => __( 'Key ID', 'simple-login-page' ),
			'private_key'   => __( 'Private key (.p8 file contents)', 'simple-login-page' ),
		);
		return $labels[ $field ];
	}

	/**
	 * Setup steps shown under each provider.
	 *
	 * @param string $provider Provider ID.
	 * @return string[]
	 */
	private static function setup_steps( $provider ) {
		switch ( $provider ) {
			case 'google':
				return array(
					__( 'Open Google Cloud Console > APIs & Services > Credentials.', 'simple-login-page' ),
					__( 'Set up the OAuth consent screen (app name, support email, your domain).', 'simple-login-page' ),
					__( 'Create credentials > OAuth client ID > Web application.', 'simple-login-page' ),
					__( 'Add the Redirect URI below under "Authorized redirect URIs", then copy the Client ID and Client Secret here.', 'simple-login-page' ),
				);
			case 'facebook':
				return array(
					__( 'Open Meta for Developers > My Apps > Create App, and add the "Authenticate and request data from users with Facebook Login" use case.', 'simple-login-page' ),
					__( 'In Facebook Login > Settings, add the Redirect URI below under "Valid OAuth Redirect URIs".', 'simple-login-page' ),
					__( 'Make sure the "email" permission is added, then copy the App ID and App Secret from App settings > Basic.', 'simple-login-page' ),
					__( 'Switch the app to Live mode so everyone (not only testers) can log in.', 'simple-login-page' ),
				);
			case 'apple':
				return array(
					__( 'Requires a paid Apple Developer account and an HTTPS site.', 'simple-login-page' ),
					__( 'Certificates, Identifiers & Profiles > Identifiers: make sure your App ID has "Sign in with Apple" enabled.', 'simple-login-page' ),
					__( 'Create a Services ID, enable "Sign in with Apple", add your domain and the Return URL below. The Services ID identifier goes in "Services ID".', 'simple-login-page' ),
					__( 'Keys: create a key with "Sign in with Apple", download the .p8 file and paste its contents below with its Key ID. Your Team ID is in the top right of the developer portal.', 'simple-login-page' ),
				);
			case 'microsoft':
				return array(
					__( 'Open Azure Portal > Microsoft Entra ID > App registrations > New registration.', 'simple-login-page' ),
					__( 'Supported account types: "Accounts in any organizational directory and personal Microsoft accounts".', 'simple-login-page' ),
					__( 'Redirect URI: platform "Web", paste the URI below.', 'simple-login-page' ),
					__( 'Copy the Application (client) ID, then create a secret in Certificates & secrets and copy its Value. Secrets expire, so note the date and renew it in time.', 'simple-login-page' ),
				);
			case 'linkedin':
				return array(
					__( 'Open LinkedIn Developers > Create app (it must be linked to a LinkedIn Company Page).', 'simple-login-page' ),
					__( 'Products tab: request "Sign In with LinkedIn using OpenID Connect".', 'simple-login-page' ),
					__( 'Auth tab: add the Redirect URI below under "Authorized redirect URLs for your app".', 'simple-login-page' ),
					__( 'Copy the Client ID and Primary Client Secret here.', 'simple-login-page' ),
				);
		}
		return array();
	}

	/**
	 * Render a checkbox row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private static function checkbox( $key, $label ) {
		printf(
			'<label class="slp-check"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( 1, (int) self::get( $key ), false ),
			esc_html( $label )
		);
	}

	/**
	 * The settings page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get();
		$woo      = slp_is_woocommerce_active();
		$role     = SLP_Auth::new_user_role();
		$roles    = wp_roles()->get_names();
		?>
		<div class="wrap slp-admin">
			<div class="slp-admin__header">
				<img src="<?php echo esc_url( SLP_URL . 'assets/images/laghavi-logo.png' ); ?>" alt="<?php esc_attr_e( 'Laghavi Digital', 'simple-login-page' ); ?>" class="slp-admin__logo" />
				<div>
					<h1><?php esc_html_e( 'Simple Login Page', 'simple-login-page' ); ?></h1>
					<p><?php esc_html_e( 'Sign up and log in with Google, Facebook, Apple, Microsoft and LinkedIn.', 'simple-login-page' ); ?></p>
				</div>
			</div>

			<?php settings_errors( self::OPTION ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'slp_settings_group' ); ?>

				<div class="slp-card">
					<h2><?php esc_html_e( 'New sign-ups', 'simple-login-page' ); ?></h2>
					<?php self::checkbox( 'allow_registration', __( 'Create a new account when someone logs in with a social account for the first time', 'simple-login-page' ) ); ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: role name, e.g. Customer. */
							esc_html__( 'New accounts get the role: %s.', 'simple-login-page' ),
							'<strong>' . esc_html( isset( $roles[ $role ] ) ? translate_user_role( $roles[ $role ] ) : $role ) . '</strong>'
						);
						echo ' ';
						if ( $woo ) {
							esc_html_e( 'WooCommerce is active, so every new sign-up is a Customer.', 'simple-login-page' );
						} else {
							printf(
								/* translators: %s: link to Settings > General. */
								esc_html__( 'This follows "New User Default Role" in %s.', 'simple-login-page' ),
								'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings > General', 'simple-login-page' ) . '</a>'
							);
						}
						echo ' ';
						printf(
							/* translators: %s: link to the Users screen. */
							esc_html__( 'You can change any user\'s role later in %s.', 'simple-login-page' ),
							'<a href="' . esc_url( admin_url( 'users.php' ) ) . '">' . esc_html__( 'Users', 'simple-login-page' ) . '</a>'
						);
						?>
					</p>
					<p class="description"><?php esc_html_e( 'People who already have an account (same email) are logged straight into it and the social account is connected automatically, as long as the provider has verified that email.', 'simple-login-page' ); ?></p>
				</div>

				<div class="slp-card">
					<h2><?php esc_html_e( 'Where to show the buttons', 'simple-login-page' ); ?></h2>
					<?php
					self::checkbox( 'show_wp_login', __( 'WordPress login page', 'simple-login-page' ) );
					self::checkbox( 'show_wp_register', __( 'WordPress registration page', 'simple-login-page' ) );
					self::checkbox( 'show_wc_login', __( 'WooCommerce login form (My Account and checkout)', 'simple-login-page' ) );
					self::checkbox( 'show_wc_register', __( 'WooCommerce registration form (My Account)', 'simple-login-page' ) );
					self::checkbox( 'show_wc_checkout', __( 'WooCommerce checkout, for guests: sign up or log in and return to checkout with the cart kept', 'simple-login-page' ) );
					?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: shortcode. */
							esc_html__( 'Show the buttons anywhere else with the shortcode %s.', 'simple-login-page' ),
							'<code>[simple_login_page]</code>'
						);
						?>
						<?php esc_html_e( 'Optional attributes: redirect_to="https://…" and heading="…".', 'simple-login-page' ); ?>
					</p>
					<?php if ( $woo ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to WooCommerce account settings. */
								esc_html__( 'WooCommerce\'s own "create an account during checkout" option is in %s and keeps working alongside the social buttons.', 'simple-login-page' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=account' ) ) . '">' . esc_html__( 'WooCommerce > Settings > Accounts & Privacy', 'simple-login-page' ) . '</a>'
							);
							?>
						</p>
					<?php endif; ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="slp-button-text"><?php esc_html_e( 'Button text', 'simple-login-page' ); ?></label></th>
							<td>
								<select id="slp-button-text" name="<?php echo esc_attr( self::OPTION ); ?>[button_text]">
									<option value="continue" <?php selected( $settings['button_text'], 'continue' ); ?>><?php esc_html_e( 'Continue with Google', 'simple-login-page' ); ?></option>
									<option value="signin" <?php selected( $settings['button_text'], 'signin' ); ?>><?php esc_html_e( 'Sign in with Google', 'simple-login-page' ); ?></option>
									<option value="login" <?php selected( $settings['button_text'], 'login' ); ?>><?php esc_html_e( 'Log in with Google', 'simple-login-page' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="slp-layout"><?php esc_html_e( 'Layout', 'simple-login-page' ); ?></label></th>
							<td>
								<select id="slp-layout" name="<?php echo esc_attr( self::OPTION ); ?>[layout]">
									<option value="full" <?php selected( $settings['layout'], 'full' ); ?>><?php esc_html_e( 'Full-width buttons with text', 'simple-login-page' ); ?></option>
									<option value="icons" <?php selected( $settings['layout'], 'icons' ); ?>><?php esc_html_e( 'Icons only, in a row', 'simple-login-page' ); ?></option>
								</select>
							</td>
						</tr>
					</table>
					<?php self::checkbox( 'show_credit', __( 'Show a small "Powered by Laghavi Digital" link under the buttons', 'simple-login-page' ) ); ?>
				</div>

				<div class="slp-card">
					<h2><?php esc_html_e( 'Login page URL', 'simple-login-page' ); ?></h2>
					<?php if ( is_multisite() ) : ?>
						<p class="description"><?php esc_html_e( 'Changing the login URL is not available on multisite networks.', 'simple-login-page' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Move the login page away from wp-login.php so bots cannot find it. Logged-out visitors who open wp-login.php or wp-admin are sent to your 404 page. Leave empty to keep the normal address.', 'simple-login-page' ); ?></p>
						<p class="slp-slug">
							<label for="slp-login-slug" class="slp-slug__prefix"><?php echo esc_html( get_option( 'permalink_structure' ) ? trailingslashit( home_url() ) : trailingslashit( home_url() ) . '?' ); ?></label>
							<input type="text" id="slp-login-slug" name="<?php echo esc_attr( self::OPTION ); ?>[login_slug]" value="<?php echo esc_attr( $settings['login_slug'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. my-login', 'simple-login-page' ); ?>" autocomplete="off" />
						</p>
						<?php if ( '' !== $settings['login_slug'] ) : ?>
							<div class="notice notice-info inline"><p>
								<?php esc_html_e( 'Your login page is now:', 'simple-login-page' ); ?>
								<a href="<?php echo esc_url( SLP_Login_Url::url() ); ?>"><strong><?php echo esc_html( SLP_Login_Url::url() ); ?></strong></a>
								&mdash; <?php esc_html_e( 'bookmark it. A copy was emailed to the site admin when it was set.', 'simple-login-page' ); ?>
							</p></div>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Locked out? Deactivate this plugin (for example by renaming its folder over FTP) and wp-login.php works again. WooCommerce\'s My Account login is not affected.', 'simple-login-page' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="slp-card">
					<h2><?php esc_html_e( 'Bot protection', 'simple-login-page' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Asks a simple math question (for example "What is 7 + 9?"), picked at random from four, before a password login or registration goes through.', 'simple-login-page' ); ?></p>
					<?php
					self::checkbox( 'captcha_login', __( 'On the WordPress and WooCommerce login forms', 'simple-login-page' ) );
					self::checkbox( 'captcha_register', __( 'On the WordPress and WooCommerce registration forms', 'simple-login-page' ) );
					?>
				</div>

				<div class="slp-card" id="slp-log-settings">
					<h2><?php esc_html_e( 'Failed login log', 'simple-login-page' ); ?></h2>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the Failed logins page. */
							esc_html__( 'Records every failed login with the username tried, IP address, device and location. See them under %s.', 'simple-login-page' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=' . SLP_Login_Log::PAGE ) ) . '">' . esc_html__( 'Simple Login > Failed logins', 'simple-login-page' ) . '</a>'
						);
						?>
					</p>
					<?php self::checkbox( 'log_failed_logins', __( 'Record failed login attempts', 'simple-login-page' ) ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="slp-retention"><?php esc_html_e( 'Keep entries for', 'simple-login-page' ); ?></label></th>
							<td><input type="number" id="slp-retention" min="1" max="365" class="small-text" name="<?php echo esc_attr( self::OPTION ); ?>[log_retention_days]" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>" /> <?php esc_html_e( 'days', 'simple-login-page' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="slp-ip-source"><?php esc_html_e( 'IP address source', 'simple-login-page' ); ?></label></th>
							<td>
								<select id="slp-ip-source" name="<?php echo esc_attr( self::OPTION ); ?>[ip_source]">
									<option value="remote_addr" <?php selected( $settings['ip_source'], 'remote_addr' ); ?>><?php esc_html_e( 'Direct connection (recommended)', 'simple-login-page' ); ?></option>
									<option value="cloudflare" <?php selected( $settings['ip_source'], 'cloudflare' ); ?>><?php esc_html_e( 'Cloudflare (CF-Connecting-IP)', 'simple-login-page' ); ?></option>
									<option value="forwarded" <?php selected( $settings['ip_source'], 'forwarded' ); ?>><?php esc_html_e( 'Proxy / load balancer (X-Forwarded-For)', 'simple-login-page' ); ?></option>
									<option value="real_ip" <?php selected( $settings['ip_source'], 'real_ip' ); ?>><?php esc_html_e( 'Nginx proxy (X-Real-IP)', 'simple-login-page' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Only change this if every attempt shows the same IP (your proxy\'s). Visitors can fake these headers when the site is not really behind that proxy.', 'simple-login-page' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Location', 'simple-login-page' ); ?></th>
							<td>
								<?php self::checkbox( 'geo_lookup', __( 'Look up city and country for each IP (uses the free IPinfo.io service)', 'simple-login-page' ) ); ?>
								<p class="description"><?php esc_html_e( 'IP addresses are sent to ipinfo.io only when you open the Failed logins page. Sites behind Cloudflare get the country without this.', 'simple-login-page' ); ?></p>
								<label for="slp-geo-token" class="slp-check"><?php esc_html_e( 'IPinfo token (optional, for higher limits)', 'simple-login-page' ); ?></label>
								<input type="password" id="slp-geo-token" name="<?php echo esc_attr( self::OPTION ); ?>[geo_token]" class="regular-text" autocomplete="new-password" placeholder="<?php echo '' !== $settings['geo_token'] ? esc_attr__( 'Saved. Leave empty to keep it.', 'simple-login-page' ) : ''; ?>" />
							</td>
						</tr>
					</table>
				</div>

				<h2 class="slp-section-title"><?php esc_html_e( 'Login providers', 'simple-login-page' ); ?></h2>
				<?php if ( ! is_ssl() && 0 !== strpos( home_url(), 'https://' ) ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Your site is not using HTTPS. Most providers (and Apple always) require HTTPS redirect URIs.', 'simple-login-page' ); ?></p></div>
				<?php endif; ?>

				<?php foreach ( SLP_Providers::all() as $id => $provider ) : ?>
					<?php
					$config = $settings['providers'][ $id ];
					$ready  = SLP_Providers::is_configured( $id );
					$name   = self::OPTION . '[providers][' . $id . ']';
					?>
					<details class="slp-card slp-provider" <?php echo $config['enabled'] ? 'open' : ''; ?>>
						<summary>
							<span class="slp-provider__icon slp-button--<?php echo esc_attr( $id ); ?>"><?php SLP_Buttons::output( SLP_Buttons::icon( $id ) ); ?></span>
							<span class="slp-provider__name"><?php echo esc_html( $provider['label'] ); ?></span>
							<?php if ( $config['enabled'] && $ready ) : ?>
								<span class="slp-badge slp-badge--on"><?php esc_html_e( 'Active', 'simple-login-page' ); ?></span>
							<?php elseif ( $config['enabled'] ) : ?>
								<span class="slp-badge slp-badge--warn"><?php esc_html_e( 'Keys missing', 'simple-login-page' ); ?></span>
							<?php else : ?>
								<span class="slp-badge"><?php esc_html_e( 'Off', 'simple-login-page' ); ?></span>
							<?php endif; ?>
						</summary>

						<label class="slp-check">
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( 1, (int) $config['enabled'] ); ?> />
							<?php
							/* translators: %s: provider name. */
							echo esc_html( sprintf( __( 'Enable %s login', 'simple-login-page' ), $provider['label'] ) );
							?>
						</label>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php echo 'apple' === $id ? esc_html__( 'Return URL', 'simple-login-page' ) : esc_html__( 'Redirect URI', 'simple-login-page' ); ?></th>
								<td>
									<div class="slp-copy">
										<input type="text" class="regular-text code" readonly value="<?php echo esc_attr( SLP_Providers::callback_url( $id ) ); ?>" />
										<button type="button" class="button slp-copy__button"><?php esc_html_e( 'Copy', 'simple-login-page' ); ?></button>
									</div>
									<p class="description"><?php esc_html_e( 'Paste this exactly into the provider\'s app settings.', 'simple-login-page' ); ?></p>
								</td>
							</tr>
							<?php foreach ( $provider['fields'] as $field ) : ?>
								<?php
								$field_id  = 'slp-' . $id . '-' . $field;
								$is_secret = in_array( $field, array( 'client_secret', 'private_key' ), true );
								$has_value = '' !== $config[ $field ];
								?>
								<tr>
									<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( self::field_label( $id, $field ) ); ?></label></th>
									<td>
										<?php if ( 'private_key' === $field ) : ?>
											<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name . '[' . $field . ']' ); ?>" rows="5" class="large-text code" autocomplete="off" placeholder="<?php echo $has_value ? esc_attr__( 'Saved. Leave empty to keep the current key.', 'simple-login-page' ) : '-----BEGIN PRIVATE KEY-----'; ?>"></textarea>
										<?php elseif ( $is_secret ) : ?>
											<input type="password" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name . '[' . $field . ']' ); ?>" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_value ? esc_attr__( 'Saved. Leave empty to keep it.', 'simple-login-page' ) : ''; ?>" />
										<?php else : ?>
											<input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name . '[' . $field . ']' ); ?>" class="regular-text" value="<?php echo esc_attr( $config[ $field ] ); ?>" autocomplete="off" />
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>

						<div class="slp-steps">
							<strong><?php esc_html_e( 'How to get these keys', 'simple-login-page' ); ?></strong>
							<ol>
								<?php foreach ( self::setup_steps( $id ) as $step ) : ?>
									<li><?php echo esc_html( $step ); ?></li>
								<?php endforeach; ?>
							</ol>
							<a href="<?php echo esc_url( $provider['console'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php
								/* translators: %s: provider name. */
								echo esc_html( sprintf( __( 'Open the %s developer console', 'simple-login-page' ), $provider['label'] ) );
								?>
								<span aria-hidden="true">&#8599;</span>
							</a>
						</div>
					</details>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>

			<p class="slp-admin__credit">
				<?php
				printf(
					/* translators: %s: link to laghavi.com. */
					esc_html__( 'Made with care by %s.', 'simple-login-page' ),
					'<a href="https://laghavi.com/" target="_blank" rel="noopener noreferrer">Laghavi Digital</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
