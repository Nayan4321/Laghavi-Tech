<?php
/**
 * Settings storage and the admin settings page.
 *
 * @package SaralaLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
class SARALOPA_Settings {

	const OPTION = 'saralopa_settings';
	const PAGE   = 'sarala-login-page';

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
		add_action( 'update_option_' . self::OPTION, array( 'SARALOPA_Login_Url', 'notify_change' ), 10, 2 );
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		$providers = array();
		foreach ( SARALOPA_Providers::all() as $id => $provider ) {
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
			'show_credit'        => 1,
			'login_slug'         => '',
			'log_failed_logins'  => 1,
			'log_retention_days' => 30,
			'ip_source'          => 'remote_addr',
			'geo_lookup'         => 0,
			'geo_token'          => '',
			// Limit login attempts: on by default, like most security plugins.
			'limit_logins'          => 1,
			'lockout_attempts'      => 5,
			'lockout_window'        => 15,
			'lockout_minutes'       => 30,
			'lockout_allowlist'     => '',
			'password_expiry'       => 0,
			'password_expiry_days'  => 90,
			'password_expiry_roles' => array(),
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
			__( 'Sarala Login Page', 'sarala-login-page' ),
			__( 'Sarala Login', 'sarala-login-page' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' ),
			SARALOPA_URL . 'assets/images/menu-icon.png',
			71
		);
		// Name the first submenu item "Settings" instead of repeating the menu title.
		add_submenu_page( self::PAGE, __( 'Sarala Login Page', 'sarala-login-page' ), __( 'Settings', 'sarala-login-page' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Admin styles and script, on this plugin's page only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		// The menu icon is a PNG, so give it the same size as dashicons everywhere in wp-admin.
		wp_add_inline_style( 'wp-admin', '#toplevel_page_sarala-login-page .wp-menu-image img{width:20px;height:20px;padding:7px 0 0;opacity:1}' );

		// The settings page and the Failed logins page.
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'saralopa-admin', SARALOPA_URL . 'assets/css/admin.css', array(), SARALOPA_VERSION );
		wp_enqueue_script( 'saralopa-admin', SARALOPA_URL . 'assets/js/admin.js', array(), SARALOPA_VERSION, true );
		wp_localize_script(
			'saralopa-admin',
			'saralopaAdmin',
			array( 'copied' => __( 'Copied!', 'sarala-login-page' ) )
		);
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			'saralopa_settings_group',
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

		foreach ( array( 'allow_registration', 'show_wp_login', 'show_wp_register', 'show_wc_login', 'show_wc_register', 'show_wc_checkout', 'captcha_login', 'captcha_register', 'show_credit', 'log_failed_logins', 'geo_lookup', 'limit_logins', 'password_expiry' ) as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		$clean['button_text'] = isset( $input['button_text'] ) && in_array( $input['button_text'], array( 'continue', 'signin', 'login' ), true ) ? $input['button_text'] : 'continue';
		$clean['layout']      = isset( $input['layout'] ) && 'icons' === $input['layout'] ? 'icons' : 'full';

		$clean['log_retention_days'] = isset( $input['log_retention_days'] ) ? min( 365, max( 1, absint( $input['log_retention_days'] ) ) ) : 30;
		$clean['ip_source']          = isset( $input['ip_source'] ) && in_array( $input['ip_source'], array( 'remote_addr', 'cloudflare', 'forwarded', 'real_ip' ), true ) ? $input['ip_source'] : 'remote_addr';
		$clean['geo_token']          = isset( $input['geo_token'] ) && '' !== trim( $input['geo_token'] ) ? sanitize_text_field( $input['geo_token'] ) : ( isset( $old['geo_token'] ) ? $old['geo_token'] : '' );

		$clean['lockout_attempts']     = isset( $input['lockout_attempts'] ) ? min( 50, max( 2, absint( $input['lockout_attempts'] ) ) ) : 5;
		$clean['lockout_window']       = isset( $input['lockout_window'] ) ? min( 1440, max( 1, absint( $input['lockout_window'] ) ) ) : 15;
		$clean['lockout_minutes']      = isset( $input['lockout_minutes'] ) ? min( 10080, max( 1, absint( $input['lockout_minutes'] ) ) ) : 30;
		$allow                         = preg_split( '/[\s,]+/', isset( $input['lockout_allowlist'] ) ? sanitize_textarea_field( (string) $input['lockout_allowlist'] ) : '' );
		$clean['lockout_allowlist']    = implode( "\n", array_filter( (array) $allow, static function ( $ip ) {
			return (bool) filter_var( $ip, FILTER_VALIDATE_IP );
		} ) );
		$clean['password_expiry_days'] = isset( $input['password_expiry_days'] ) ? min( 3650, max( 1, absint( $input['password_expiry_days'] ) ) ) : 90;
		$clean['password_expiry_roles'] = isset( $input['password_expiry_roles'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) $input['password_expiry_roles'] ), array_keys( wp_roles()->get_names() ) ) ) : array();

		$slug = SARALOPA_Login_Url::validate( isset( $input['login_slug'] ) ? (string) $input['login_slug'] : '' );
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
				add_settings_error( self::OPTION, 'saralopa_apple_key', __( 'The Apple private key is not valid. Paste the full contents of the .p8 file, including the BEGIN and END lines.', 'sarala-login-page' ) );
				$provider['private_key'] = isset( $old['providers']['apple']['private_key'] ) ? $old['providers']['apple']['private_key'] : '';
			}

			if ( $provider['enabled'] ) {
				$missing = false;
				foreach ( SARALOPA_Providers::all()[ $id ]['fields'] as $field ) {
					if ( '' === $provider[ $field ] ) {
						$missing = true;
					}
				}
				if ( $missing ) {
					add_settings_error(
						self::OPTION,
						'saralopa_missing_' . $id,
						/* translators: %s: provider name. */
						sprintf( __( '%s is switched on but some keys are missing, so its button stays hidden until they are filled in.', 'sarala-login-page' ), SARALOPA_Providers::label( $id ) ),
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
			'client_id'     => 'apple' === $provider ? __( 'Services ID', 'sarala-login-page' ) : ( 'facebook' === $provider ? __( 'App ID', 'sarala-login-page' ) : __( 'Client ID', 'sarala-login-page' ) ),
			'client_secret' => 'facebook' === $provider ? __( 'App Secret', 'sarala-login-page' ) : __( 'Client Secret', 'sarala-login-page' ),
			'team_id'       => __( 'Team ID', 'sarala-login-page' ),
			'key_id'        => __( 'Key ID', 'sarala-login-page' ),
			'private_key'   => __( 'Private key (.p8 file contents)', 'sarala-login-page' ),
		);
		$def = SARALOPA_Providers::all();
		if ( isset( $def[ $provider ]['labels'][ $field ] ) ) {
			return $def[ $provider ]['labels'][ $field ];
		}
		return isset( $labels[ $field ] ) ? $labels[ $field ] : ucwords( str_replace( '_', ' ', $field ) );
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
					__( 'Open Google Cloud Console and create or pick a project. No API needs to be enabled for Google login.', 'sarala-login-page' ),
					__( 'APIs & Services > OAuth consent screen (Google Auth Platform) > Get started: app name, support email, Audience "External". Then under Audience click "Publish app", otherwise only test users can log in.', 'sarala-login-page' ),
					__( 'Clients > Create client > Web application. Under "Authorized redirect URIs" add the Redirect URI below.', 'sarala-login-page' ),
					__( 'Copy the Client ID and Client Secret here and save. If Google asks "Which API are you using?", skip that wizard and choose "OAuth client ID".', 'sarala-login-page' ),
				);
			case 'facebook':
				return array(
					__( 'Open Meta for Developers > My Apps > Create App, and add the "Authenticate and request data from users with Facebook Login" use case.', 'sarala-login-page' ),
					__( 'In Facebook Login > Settings, add the Redirect URI below under "Valid OAuth Redirect URIs".', 'sarala-login-page' ),
					__( 'Make sure the "email" permission is added, then copy the App ID and App Secret from App settings > Basic.', 'sarala-login-page' ),
					__( 'Switch the app to Live mode so everyone (not only testers) can log in.', 'sarala-login-page' ),
				);
			case 'apple':
				return array(
					__( 'Requires a paid Apple Developer account and an HTTPS site.', 'sarala-login-page' ),
					__( 'Certificates, Identifiers & Profiles > Identifiers: make sure your App ID has "Sign in with Apple" enabled.', 'sarala-login-page' ),
					__( 'Create a Services ID, enable "Sign in with Apple", add your domain and the Return URL below. The Services ID identifier goes in "Services ID".', 'sarala-login-page' ),
					__( 'Keys: create a key with "Sign in with Apple", download the .p8 file and paste its contents below with its Key ID. Your Team ID is in the top right of the developer portal.', 'sarala-login-page' ),
				);
			case 'microsoft':
				return array(
					__( 'Open Azure Portal > Microsoft Entra ID > App registrations > New registration.', 'sarala-login-page' ),
					__( 'Supported account types: "Accounts in any organizational directory and personal Microsoft accounts".', 'sarala-login-page' ),
					__( 'Redirect URI: platform "Web", paste the URI below.', 'sarala-login-page' ),
					__( 'Copy the Application (client) ID, then create a secret in Certificates & secrets and copy its Value. Secrets expire, so note the date and renew it in time.', 'sarala-login-page' ),
				);
			case 'linkedin':
				return array(
					__( 'Open LinkedIn Developers > Create app (it must be linked to a LinkedIn Company Page).', 'sarala-login-page' ),
					__( 'Products tab: request "Sign In with LinkedIn using OpenID Connect".', 'sarala-login-page' ),
					__( 'Auth tab: add the Redirect URI below under "Authorized redirect URLs for your app".', 'sarala-login-page' ),
					__( 'Copy the Client ID and Primary Client Secret here.', 'sarala-login-page' ),
				);
			case 'twitter':
				return array(
					__( 'Open the X Developer Portal > Projects & Apps, and open (or create) your app.', 'sarala-login-page' ),
					__( 'User authentication settings > Set up: App permissions "Read", Type of App "Web App, Automated App or Bot", and switch on "Request email from users" (it needs your privacy policy and terms URLs).', 'sarala-login-page' ),
					__( 'Add the Callback URI below and your website URL, then save.', 'sarala-login-page' ),
					__( 'Keys and tokens tab: copy the OAuth 2.0 Client ID and Client Secret here.', 'sarala-login-page' ),
				);
			case 'reddit':
				return array(
					__( 'Log in to Reddit and open reddit.com/prefs/apps, then click "create another app".', 'sarala-login-page' ),
					__( 'Choose "web app", enter a name and paste the Redirect URI below into "redirect uri".', 'sarala-login-page' ),
					__( 'Copy the ID shown under the app name (Client ID) and the secret here and save.', 'sarala-login-page' ),
					__( 'Reddit never shares email addresses: people can connect Reddit to their account from their profile and then log in with it, but new accounts can\'t be created with Reddit.', 'sarala-login-page' ),
				);
			case 'github':
				return array(
					__( 'GitHub > Settings > Developer settings > OAuth Apps > New OAuth App.', 'sarala-login-page' ),
					__( 'Homepage URL: your site. Authorization callback URL: the Redirect URI below.', 'sarala-login-page' ),
					__( 'Register the app, generate a client secret, and copy the Client ID and Client Secret here.', 'sarala-login-page' ),
				);
			case 'discord':
				return array(
					__( 'Discord Developer Portal > New Application.', 'sarala-login-page' ),
					__( 'OAuth2 > Redirects: add the Redirect URI below and save.', 'sarala-login-page' ),
					__( 'Copy the Client ID and reset/copy the Client Secret from the OAuth2 page here.', 'sarala-login-page' ),
				);
			case 'yahoo':
				return array(
					__( 'Yahoo Developer Network > My Apps > Create an App.', 'sarala-login-page' ),
					__( 'Redirect URI(s): paste the Redirect URI below. Under OpenID Connect Permissions tick Email and Profile.', 'sarala-login-page' ),
					__( 'Create the app and copy the Client ID (Consumer Key) and Client Secret (Consumer Secret) here.', 'sarala-login-page' ),
				);
			default:
				$def = SARALOPA_Providers::all();
				return isset( $def[ $provider ]['steps'] ) ? (array) $def[ $provider ]['steps'] : array();
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
			'<label class="saralopa-check"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
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
		$woo      = saralopa_is_woocommerce_active();
		$role     = SARALOPA_Auth::new_user_role();
		$roles    = wp_roles()->get_names();
		?>
		<div class="wrap saralopa-admin">
			<div class="saralopa-admin__header">
				<img src="<?php echo esc_url( SARALOPA_URL . 'assets/images/laghavi-logo.png' ); ?>" alt="<?php esc_attr_e( 'Laghavi Digital', 'sarala-login-page' ); ?>" class="saralopa-admin__logo" />
				<div>
					<h1><?php esc_html_e( 'Sarala Login Page', 'sarala-login-page' ); ?></h1>
					<p><?php esc_html_e( 'Sign up and log in with Google, Facebook, Apple, Microsoft, LinkedIn and X (Twitter).', 'sarala-login-page' ); ?></p>
				</div>
			</div>

			<?php settings_errors( self::OPTION ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'saralopa_settings_group' ); ?>

				<div class="saralopa-card">
					<h2><?php esc_html_e( 'New sign-ups', 'sarala-login-page' ); ?></h2>
					<?php self::checkbox( 'allow_registration', __( 'Create a new account when someone logs in with a social account for the first time', 'sarala-login-page' ) ); ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: role name, e.g. Customer. */
							esc_html__( 'New accounts get the role: %s.', 'sarala-login-page' ),
							'<strong>' . esc_html( isset( $roles[ $role ] ) ? translate_user_role( $roles[ $role ] ) : $role ) . '</strong>'
						);
						echo ' ';
						if ( $woo ) {
							esc_html_e( 'WooCommerce is active, so every new sign-up is a Customer.', 'sarala-login-page' );
						} else {
							printf(
								/* translators: %s: link to Settings > General. */
								esc_html__( 'This follows "New User Default Role" in %s.', 'sarala-login-page' ),
								'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings > General', 'sarala-login-page' ) . '</a>'
							);
						}
						echo ' ';
						printf(
							/* translators: %s: link to the Users screen. */
							esc_html__( 'You can change any user\'s role later in %s.', 'sarala-login-page' ),
							'<a href="' . esc_url( admin_url( 'users.php' ) ) . '">' . esc_html__( 'Users', 'sarala-login-page' ) . '</a>'
						);
						?>
					</p>
					<p class="description"><?php esc_html_e( 'People who already have an account (same email) are logged straight into it and the social account is connected automatically, as long as the provider has verified that email.', 'sarala-login-page' ); ?></p>
				</div>

				<div class="saralopa-card">
					<h2><?php esc_html_e( 'Where to show the buttons', 'sarala-login-page' ); ?></h2>
					<?php
					self::checkbox( 'show_wp_login', __( 'WordPress login page', 'sarala-login-page' ) );
					self::checkbox( 'show_wp_register', __( 'WordPress registration page', 'sarala-login-page' ) );
					self::checkbox( 'show_wc_login', __( 'WooCommerce login form (My Account and checkout)', 'sarala-login-page' ) );
					self::checkbox( 'show_wc_register', __( 'WooCommerce registration form (My Account)', 'sarala-login-page' ) );
					self::checkbox( 'show_wc_checkout', __( 'WooCommerce checkout, for guests: sign up or log in and return to checkout with the cart kept', 'sarala-login-page' ) );
					?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: shortcode. */
							esc_html__( 'Show the buttons anywhere else with the shortcode %s.', 'sarala-login-page' ),
							'<code>[sarala_login_page]</code>'
						);
						?>
						<?php esc_html_e( 'Optional attributes: redirect_to="https://…" and heading="…".', 'sarala-login-page' ); ?>
					</p>
					<?php if ( $woo ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to WooCommerce account settings. */
								esc_html__( 'WooCommerce\'s own "create an account during checkout" option is in %s and keeps working alongside the social buttons.', 'sarala-login-page' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=account' ) ) . '">' . esc_html__( 'WooCommerce > Settings > Accounts & Privacy', 'sarala-login-page' ) . '</a>'
							);
							?>
						</p>
					<?php endif; ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="saralopa-button-text"><?php esc_html_e( 'Button text', 'sarala-login-page' ); ?></label></th>
							<td>
								<select id="saralopa-button-text" name="<?php echo esc_attr( self::OPTION ); ?>[button_text]">
									<option value="continue" <?php selected( $settings['button_text'], 'continue' ); ?>><?php esc_html_e( 'Continue with Google', 'sarala-login-page' ); ?></option>
									<option value="signin" <?php selected( $settings['button_text'], 'signin' ); ?>><?php esc_html_e( 'Sign in with Google', 'sarala-login-page' ); ?></option>
									<option value="login" <?php selected( $settings['button_text'], 'login' ); ?>><?php esc_html_e( 'Log in with Google', 'sarala-login-page' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="saralopa-layout"><?php esc_html_e( 'Layout', 'sarala-login-page' ); ?></label></th>
							<td>
								<select id="saralopa-layout" name="<?php echo esc_attr( self::OPTION ); ?>[layout]">
									<option value="full" <?php selected( $settings['layout'], 'full' ); ?>><?php esc_html_e( 'Full-width buttons with text', 'sarala-login-page' ); ?></option>
									<option value="icons" <?php selected( $settings['layout'], 'icons' ); ?>><?php esc_html_e( 'Icons only, in a row', 'sarala-login-page' ); ?></option>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<div class="saralopa-card">
					<h2><?php esc_html_e( 'Login page URL', 'sarala-login-page' ); ?></h2>
					<?php if ( is_multisite() ) : ?>
						<p class="description"><?php esc_html_e( 'Changing the login URL is not available on multisite networks.', 'sarala-login-page' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Move the login page away from wp-login.php so bots cannot find it. Logged-out visitors who open wp-login.php or wp-admin are sent to your 404 page. Leave empty to keep the normal address.', 'sarala-login-page' ); ?></p>
						<p class="saralopa-slug">
							<label for="saralopa-login-slug" class="saralopa-slug__prefix"><?php echo esc_html( get_option( 'permalink_structure' ) ? trailingslashit( home_url() ) : trailingslashit( home_url() ) . '?' ); ?></label>
							<input type="text" id="saralopa-login-slug" name="<?php echo esc_attr( self::OPTION ); ?>[login_slug]" value="<?php echo esc_attr( $settings['login_slug'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. my-login', 'sarala-login-page' ); ?>" autocomplete="off" />
						</p>
						<?php if ( '' !== $settings['login_slug'] ) : ?>
							<div class="notice notice-info inline"><p>
								<?php esc_html_e( 'Your login page is now:', 'sarala-login-page' ); ?>
								<a href="<?php echo esc_url( SARALOPA_Login_Url::url() ); ?>"><strong><?php echo esc_html( SARALOPA_Login_Url::url() ); ?></strong></a>
								&mdash; <?php esc_html_e( 'bookmark it. A copy was emailed to the site admin when it was set.', 'sarala-login-page' ); ?>
							</p></div>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Locked out? Deactivate this plugin (for example by renaming its folder over FTP) and wp-login.php works again. WooCommerce\'s My Account login is not affected.', 'sarala-login-page' ); ?></p>
					<?php endif; ?>
					<?php self::checkbox( 'show_credit', __( 'Show a small "Powered by Laghavi Digital" link under the buttons', 'sarala-login-page' ) ); ?>
				</div>

				<div class="saralopa-card">
					<h2><?php esc_html_e( 'Bot protection', 'sarala-login-page' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Asks a simple math question (for example "What is 7 + 9?"), picked at random from four, before a password login or registration goes through.', 'sarala-login-page' ); ?></p>
					<?php
					self::checkbox( 'captcha_login', __( 'On the WordPress and WooCommerce login forms', 'sarala-login-page' ) );
					self::checkbox( 'captcha_register', __( 'On the WordPress and WooCommerce registration forms', 'sarala-login-page' ) );
					?>
				</div>

				<div class="saralopa-card" id="saralopa-security">
					<h2><?php esc_html_e( 'Login security', 'sarala-login-page' ); ?></h2>
					<?php self::checkbox( 'limit_logins', __( 'Limit login attempts: lock out an IP address after too many failed logins', 'sarala-login-page' ) ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="saralopa-lockout-attempts"><?php esc_html_e( 'Lock out after', 'sarala-login-page' ); ?></label></th>
							<td>
								<input type="number" id="saralopa-lockout-attempts" min="2" max="50" class="small-text" name="<?php echo esc_attr( self::OPTION ); ?>[lockout_attempts]" value="<?php echo esc_attr( $settings['lockout_attempts'] ); ?>" />
								<?php esc_html_e( 'failed logins within', 'sarala-login-page' ); ?>
								<input type="number" id="saralopa-lockout-window" min="1" max="1440" class="small-text" name="<?php echo esc_attr( self::OPTION ); ?>[lockout_window]" value="<?php echo esc_attr( $settings['lockout_window'] ); ?>" aria-label="<?php esc_attr_e( 'Minutes counted', 'sarala-login-page' ); ?>" />
								<?php esc_html_e( 'minutes', 'sarala-login-page' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="saralopa-lockout-minutes"><?php esc_html_e( 'Lockout lasts', 'sarala-login-page' ); ?></label></th>
							<td><input type="number" id="saralopa-lockout-minutes" min="1" max="10080" class="small-text" name="<?php echo esc_attr( self::OPTION ); ?>[lockout_minutes]" value="<?php echo esc_attr( $settings['lockout_minutes'] ); ?>" /> <?php esc_html_e( 'minutes', 'sarala-login-page' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="saralopa-lockout-allowlist"><?php esc_html_e( 'Never lock out', 'sarala-login-page' ); ?></label></th>
							<td>
								<textarea id="saralopa-lockout-allowlist" rows="3" class="regular-text code" name="<?php echo esc_attr( self::OPTION ); ?>[lockout_allowlist]" placeholder="203.0.113.10"><?php echo esc_textarea( $settings['lockout_allowlist'] ); ?></textarea>
								<p class="description">
									<?php
									printf(
										/* translators: %s: the visitor's IP address. */
										esc_html__( 'IP addresses, one per line. Your current address is %s.', 'sarala-login-page' ),
										'<code>' . esc_html( SARALOPA_Login_Log::client_ip() ) . '</code>'
									);
									?>
									<?php esc_html_e( 'Locked-out addresses are listed, and can be unlocked, on the Failed logins screen.', 'sarala-login-page' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php self::checkbox( 'password_expiry', __( 'Force password reset: make passwords expire after a set number of days', 'sarala-login-page' ) ); ?>
					<p class="description"><?php esc_html_e( 'When a password is older than this, the login is refused and the user is emailed a link to choose a new password. Existing accounts start counting from their next login. Social logins are not affected.', 'sarala-login-page' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="saralopa-expiry-days"><?php esc_html_e( 'Passwords expire after', 'sarala-login-page' ); ?></label></th>
							<td><input type="number" id="saralopa-expiry-days" min="1" max="3650" class="small-text" name="<?php echo esc_attr( self::OPTION ); ?>[password_expiry_days]" value="<?php echo esc_attr( $settings['password_expiry_days'] ); ?>" /> <?php esc_html_e( 'days', 'sarala-login-page' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'For these roles', 'sarala-login-page' ); ?></th>
							<td>
								<?php foreach ( $roles as $role_key => $role_name ) : ?>
									<label class="saralopa-check saralopa-check--inline"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[password_expiry_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $settings['password_expiry_roles'], true ) ); ?> /> <?php echo esc_html( translate_user_role( $role_name ) ); ?></label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Tick none to apply it to everyone.', 'sarala-login-page' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="saralopa-card" id="saralopa-log-settings">
					<h2><?php esc_html_e( 'Failed login log', 'sarala-login-page' ); ?></h2>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the Failed logins page. */
							esc_html__( 'Records every failed login with the username tried, IP address, device and location. See them under %s.', 'sarala-login-page' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=' . SARALOPA_Login_Log::PAGE ) ) . '">' . esc_html__( 'Sarala Login > Failed logins', 'sarala-login-page' ) . '</a>'
						);
						?>
					</p>
					<?php self::checkbox( 'log_failed_logins', __( 'Record failed login attempts', 'sarala-login-page' ) ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="saralopa-retention"><?php esc_html_e( 'Keep entries for', 'sarala-login-page' ); ?></label></th>
							<td><input type="number" id="saralopa-retention" min="1" max="365" class="small-text" name="<?php echo esc_attr( self::OPTION ); ?>[log_retention_days]" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>" /> <?php esc_html_e( 'days', 'sarala-login-page' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="saralopa-ip-source"><?php esc_html_e( 'IP address source', 'sarala-login-page' ); ?></label></th>
							<td>
								<select id="saralopa-ip-source" name="<?php echo esc_attr( self::OPTION ); ?>[ip_source]">
									<option value="remote_addr" <?php selected( $settings['ip_source'], 'remote_addr' ); ?>><?php esc_html_e( 'Direct connection (recommended)', 'sarala-login-page' ); ?></option>
									<option value="cloudflare" <?php selected( $settings['ip_source'], 'cloudflare' ); ?>><?php esc_html_e( 'Cloudflare (CF-Connecting-IP)', 'sarala-login-page' ); ?></option>
									<option value="forwarded" <?php selected( $settings['ip_source'], 'forwarded' ); ?>><?php esc_html_e( 'Proxy / load balancer (X-Forwarded-For)', 'sarala-login-page' ); ?></option>
									<option value="real_ip" <?php selected( $settings['ip_source'], 'real_ip' ); ?>><?php esc_html_e( 'Nginx proxy (X-Real-IP)', 'sarala-login-page' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Only change this if every attempt shows the same IP (your proxy\'s). Visitors can fake these headers when the site is not really behind that proxy.', 'sarala-login-page' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Location', 'sarala-login-page' ); ?></th>
							<td>
								<?php self::checkbox( 'geo_lookup', __( 'Look up city and country for each IP (uses the free IPinfo.io service)', 'sarala-login-page' ) ); ?>
								<p class="description"><?php esc_html_e( 'IP addresses are sent to ipinfo.io only when you open the Failed logins page. Sites behind Cloudflare get the country without this.', 'sarala-login-page' ); ?></p>
								<label for="saralopa-geo-token" class="saralopa-check"><?php esc_html_e( 'IPinfo token (optional, for higher limits)', 'sarala-login-page' ); ?></label>
								<input type="password" id="saralopa-geo-token" name="<?php echo esc_attr( self::OPTION ); ?>[geo_token]" class="regular-text" autocomplete="new-password" placeholder="<?php echo '' !== $settings['geo_token'] ? esc_attr__( 'Saved. Leave empty to keep it.', 'sarala-login-page' ) : ''; ?>" />
							</td>
						</tr>
					</table>
				</div>

				<h2 class="saralopa-section-title"><?php esc_html_e( 'Login providers', 'sarala-login-page' ); ?></h2>
				<?php if ( ! is_ssl() && 0 !== strpos( home_url(), 'https://' ) ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Your site is not using HTTPS. Most providers (and Apple always) require HTTPS redirect URIs.', 'sarala-login-page' ); ?></p></div>
				<?php endif; ?>

				<?php foreach ( SARALOPA_Providers::all() as $id => $provider ) : ?>
					<?php
					$config = $settings['providers'][ $id ];
					$ready  = SARALOPA_Providers::is_configured( $id );
					$name   = self::OPTION . '[providers][' . $id . ']';
					?>
					<details class="saralopa-card saralopa-provider" <?php echo $config['enabled'] ? 'open' : ''; ?>>
						<summary>
							<span class="saralopa-provider__icon saralopa-button--<?php echo esc_attr( $id ); ?>"><?php SARALOPA_Buttons::output( SARALOPA_Buttons::icon( $id ) ); ?></span>
							<span class="saralopa-provider__name"><?php echo esc_html( $provider['label'] ); ?></span>
							<?php if ( $config['enabled'] && $ready ) : ?>
								<span class="saralopa-badge saralopa-badge--on"><?php esc_html_e( 'Active', 'sarala-login-page' ); ?></span>
							<?php elseif ( $config['enabled'] ) : ?>
								<span class="saralopa-badge saralopa-badge--warn"><?php esc_html_e( 'Keys missing', 'sarala-login-page' ); ?></span>
							<?php else : ?>
								<span class="saralopa-badge"><?php esc_html_e( 'Off', 'sarala-login-page' ); ?></span>
							<?php endif; ?>
						</summary>

						<label class="saralopa-check">
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( 1, (int) $config['enabled'] ); ?> />
							<?php
							/* translators: %s: provider name. */
							echo esc_html( sprintf( __( 'Enable %s login', 'sarala-login-page' ), $provider['label'] ) );
							?>
						</label>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php echo 'apple' === $id ? esc_html__( 'Return URL', 'sarala-login-page' ) : esc_html__( 'Redirect URI', 'sarala-login-page' ); ?></th>
								<td>
									<div class="saralopa-copy">
										<input type="text" class="regular-text code" readonly value="<?php echo esc_attr( SARALOPA_Providers::callback_url( $id ) ); ?>" />
										<button type="button" class="button saralopa-copy__button"><?php esc_html_e( 'Copy', 'sarala-login-page' ); ?></button>
									</div>
									<p class="description"><?php esc_html_e( 'Paste this exactly into the provider\'s app settings.', 'sarala-login-page' ); ?></p>
								</td>
							</tr>
							<?php foreach ( $provider['fields'] as $field ) : ?>
								<?php
								$field_id  = 'saralopa-' . $id . '-' . $field;
								$is_secret = in_array( $field, array( 'client_secret', 'private_key' ), true );
								$has_value = '' !== $config[ $field ];
								?>
								<tr>
									<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( self::field_label( $id, $field ) ); ?></label></th>
									<td>
										<?php if ( 'private_key' === $field ) : ?>
											<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name . '[' . $field . ']' ); ?>" rows="5" class="large-text code" autocomplete="off" placeholder="<?php echo $has_value ? esc_attr__( 'Saved. Leave empty to keep the current key.', 'sarala-login-page' ) : '-----BEGIN PRIVATE KEY-----'; ?>"></textarea>
										<?php elseif ( $is_secret ) : ?>
											<input type="password" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name . '[' . $field . ']' ); ?>" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_value ? esc_attr__( 'Saved. Leave empty to keep it.', 'sarala-login-page' ) : ''; ?>" />
										<?php else : ?>
											<input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name . '[' . $field . ']' ); ?>" class="regular-text" value="<?php echo esc_attr( $config[ $field ] ); ?>" autocomplete="off" />
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>

						<div class="saralopa-steps">
							<strong><?php esc_html_e( 'How to get these keys', 'sarala-login-page' ); ?></strong>
							<ol>
								<?php foreach ( self::setup_steps( $id ) as $step ) : ?>
									<li><?php echo esc_html( $step ); ?></li>
								<?php endforeach; ?>
							</ol>
							<a href="<?php echo esc_url( $provider['console'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php
								/* translators: %s: provider name. */
								echo esc_html( sprintf( __( 'Open the %s developer console', 'sarala-login-page' ), $provider['label'] ) );
								?>
								<span aria-hidden="true">&#8599;</span>
							</a>
						</div>
					</details>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
			<?php SARALOPA_Pro_Features::settings_note(); ?>

			<p class="saralopa-admin__credit">
				<?php
				printf(
					/* translators: %s: link to laghavi.site. */
					esc_html__( 'Made with care by %s.', 'sarala-login-page' ),
					'<a href="https://laghavi.site/" target="_blank" rel="noopener noreferrer">Laghavi Digital</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
