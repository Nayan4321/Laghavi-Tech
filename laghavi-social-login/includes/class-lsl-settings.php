<?php
/**
 * Settings storage and the admin settings page.
 *
 * @package LaghaviSocialLogin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
class LSL_Settings {

	const OPTION = 'lsl_settings';
	const PAGE   = 'laghavi-social-login';

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
		add_action( 'admin_notices', array( __CLASS__, 'credit_notice' ) );
		add_action( 'admin_post_lsl_credit', array( __CLASS__, 'credit_answer' ) );
	}

	/**
	 * Ask once whether the site owner wants to show the "Powered by Laghavi Digital" link.
	 * WordPress.org only allows front-end credit links the owner has agreed to.
	 */
	public static function credit_notice() {
		if ( ! current_user_can( 'manage_options' ) || get_option( 'lsl_credit_asked' ) || self::get( 'show_credit' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'toplevel_page_' . self::PAGE ), true ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=lsl_credit' ), 'lsl_credit' );
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Thanks for using Laghavi Social Login!', 'laghavi-social-login' ); ?></strong>
				<?php esc_html_e( 'This plugin is free. Would you support it by showing a small "Powered by Laghavi Digital" link under the login buttons? You can change this any time in the settings.', 'laghavi-social-login' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( add_query_arg( 'answer', 'yes', $url ) ); ?>" class="button button-primary"><?php esc_html_e( 'Yes, show it', 'laghavi-social-login' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'answer', 'no', $url ) ); ?>" class="button"><?php esc_html_e( 'No thanks', 'laghavi-social-login' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Save the answer to the credit notice.
	 */
	public static function credit_answer() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'laghavi-social-login' ) );
		}
		check_admin_referer( 'lsl_credit' );

		update_option( 'lsl_credit_asked', 1, false );
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
		foreach ( LSL_Providers::all() as $id => $provider ) {
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
			__( 'Laghavi Social Login', 'laghavi-social-login' ),
			__( 'Social Login', 'laghavi-social-login' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' ),
			LSL_URL . 'assets/images/menu-icon.png',
			71
		);
	}

	/**
	 * Admin styles and script, on this plugin's page only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		// The menu icon is a PNG, so give it the same size as dashicons everywhere in wp-admin.
		wp_add_inline_style( 'wp-admin', '#toplevel_page_laghavi-social-login .wp-menu-image img{width:20px;height:20px;padding:7px 0 0;opacity:1}' );

		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'lsl-admin', LSL_URL . 'assets/css/admin.css', array(), LSL_VERSION );
		wp_enqueue_script( 'lsl-admin', LSL_URL . 'assets/js/admin.js', array(), LSL_VERSION, true );
		wp_localize_script(
			'lsl-admin',
			'lslAdmin',
			array( 'copied' => __( 'Copied!', 'laghavi-social-login' ) )
		);
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			'lsl_settings_group',
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

		foreach ( array( 'allow_registration', 'show_wp_login', 'show_wp_register', 'show_wc_login', 'show_wc_register', 'show_wc_checkout', 'captcha_login', 'captcha_register', 'show_credit' ) as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		$clean['button_text'] = isset( $input['button_text'] ) && in_array( $input['button_text'], array( 'continue', 'signin', 'login' ), true ) ? $input['button_text'] : 'continue';
		$clean['layout']      = isset( $input['layout'] ) && 'icons' === $input['layout'] ? 'icons' : 'full';

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
				add_settings_error( self::OPTION, 'lsl_apple_key', __( 'The Apple private key is not valid. Paste the full contents of the .p8 file, including the BEGIN and END lines.', 'laghavi-social-login' ) );
				$provider['private_key'] = isset( $old['providers']['apple']['private_key'] ) ? $old['providers']['apple']['private_key'] : '';
			}

			if ( $provider['enabled'] ) {
				$missing = false;
				foreach ( LSL_Providers::all()[ $id ]['fields'] as $field ) {
					if ( '' === $provider[ $field ] ) {
						$missing = true;
					}
				}
				if ( $missing ) {
					add_settings_error(
						self::OPTION,
						'lsl_missing_' . $id,
						/* translators: %s: provider name. */
						sprintf( __( '%s is switched on but some keys are missing, so its button stays hidden until they are filled in.', 'laghavi-social-login' ), LSL_Providers::label( $id ) ),
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
			'client_id'     => 'apple' === $provider ? __( 'Services ID', 'laghavi-social-login' ) : ( 'facebook' === $provider ? __( 'App ID', 'laghavi-social-login' ) : __( 'Client ID', 'laghavi-social-login' ) ),
			'client_secret' => 'facebook' === $provider ? __( 'App Secret', 'laghavi-social-login' ) : __( 'Client Secret', 'laghavi-social-login' ),
			'team_id'       => __( 'Team ID', 'laghavi-social-login' ),
			'key_id'        => __( 'Key ID', 'laghavi-social-login' ),
			'private_key'   => __( 'Private key (.p8 file contents)', 'laghavi-social-login' ),
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
					__( 'Open Google Cloud Console > APIs & Services > Credentials.', 'laghavi-social-login' ),
					__( 'Set up the OAuth consent screen (app name, support email, your domain).', 'laghavi-social-login' ),
					__( 'Create credentials > OAuth client ID > Web application.', 'laghavi-social-login' ),
					__( 'Add the Redirect URI below under "Authorized redirect URIs", then copy the Client ID and Client Secret here.', 'laghavi-social-login' ),
				);
			case 'facebook':
				return array(
					__( 'Open Meta for Developers > My Apps > Create App, and add the "Authenticate and request data from users with Facebook Login" use case.', 'laghavi-social-login' ),
					__( 'In Facebook Login > Settings, add the Redirect URI below under "Valid OAuth Redirect URIs".', 'laghavi-social-login' ),
					__( 'Make sure the "email" permission is added, then copy the App ID and App Secret from App settings > Basic.', 'laghavi-social-login' ),
					__( 'Switch the app to Live mode so everyone (not only testers) can log in.', 'laghavi-social-login' ),
				);
			case 'apple':
				return array(
					__( 'Requires a paid Apple Developer account and an HTTPS site.', 'laghavi-social-login' ),
					__( 'Certificates, Identifiers & Profiles > Identifiers: make sure your App ID has "Sign in with Apple" enabled.', 'laghavi-social-login' ),
					__( 'Create a Services ID, enable "Sign in with Apple", add your domain and the Return URL below. The Services ID identifier goes in "Services ID".', 'laghavi-social-login' ),
					__( 'Keys: create a key with "Sign in with Apple", download the .p8 file and paste its contents below with its Key ID. Your Team ID is in the top right of the developer portal.', 'laghavi-social-login' ),
				);
			case 'microsoft':
				return array(
					__( 'Open Azure Portal > Microsoft Entra ID > App registrations > New registration.', 'laghavi-social-login' ),
					__( 'Supported account types: "Accounts in any organizational directory and personal Microsoft accounts".', 'laghavi-social-login' ),
					__( 'Redirect URI: platform "Web", paste the URI below.', 'laghavi-social-login' ),
					__( 'Copy the Application (client) ID, then create a secret in Certificates & secrets and copy its Value. Secrets expire, so note the date and renew it in time.', 'laghavi-social-login' ),
				);
			case 'linkedin':
				return array(
					__( 'Open LinkedIn Developers > Create app (it must be linked to a LinkedIn Company Page).', 'laghavi-social-login' ),
					__( 'Products tab: request "Sign In with LinkedIn using OpenID Connect".', 'laghavi-social-login' ),
					__( 'Auth tab: add the Redirect URI below under "Authorized redirect URLs for your app".', 'laghavi-social-login' ),
					__( 'Copy the Client ID and Primary Client Secret here.', 'laghavi-social-login' ),
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
			'<label class="lsl-check"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
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
		$woo      = lsl_is_woocommerce_active();
		$role     = LSL_Auth::new_user_role();
		$roles    = wp_roles()->get_names();
		?>
		<div class="wrap lsl-admin">
			<div class="lsl-admin__header">
				<img src="<?php echo esc_url( LSL_URL . 'assets/images/laghavi-logo.png' ); ?>" alt="<?php esc_attr_e( 'Laghavi Digital', 'laghavi-social-login' ); ?>" class="lsl-admin__logo" />
				<div>
					<h1><?php esc_html_e( 'Laghavi Social Login', 'laghavi-social-login' ); ?></h1>
					<p><?php esc_html_e( 'Sign up and log in with Google, Facebook, Apple, Microsoft and LinkedIn.', 'laghavi-social-login' ); ?></p>
				</div>
			</div>

			<?php settings_errors( self::OPTION ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'lsl_settings_group' ); ?>

				<div class="lsl-card">
					<h2><?php esc_html_e( 'New sign-ups', 'laghavi-social-login' ); ?></h2>
					<?php self::checkbox( 'allow_registration', __( 'Create a new account when someone logs in with a social account for the first time', 'laghavi-social-login' ) ); ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: role name, e.g. Customer. */
							esc_html__( 'New accounts get the role: %s.', 'laghavi-social-login' ),
							'<strong>' . esc_html( isset( $roles[ $role ] ) ? translate_user_role( $roles[ $role ] ) : $role ) . '</strong>'
						);
						echo ' ';
						if ( $woo ) {
							esc_html_e( 'WooCommerce is active, so every new sign-up is a Customer.', 'laghavi-social-login' );
						} else {
							printf(
								/* translators: %s: link to Settings > General. */
								esc_html__( 'This follows "New User Default Role" in %s.', 'laghavi-social-login' ),
								'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings > General', 'laghavi-social-login' ) . '</a>'
							);
						}
						echo ' ';
						printf(
							/* translators: %s: link to the Users screen. */
							esc_html__( 'You can change any user\'s role later in %s.', 'laghavi-social-login' ),
							'<a href="' . esc_url( admin_url( 'users.php' ) ) . '">' . esc_html__( 'Users', 'laghavi-social-login' ) . '</a>'
						);
						?>
					</p>
					<p class="description"><?php esc_html_e( 'People who already have an account (same email) are logged straight into it and the social account is connected automatically, as long as the provider has verified that email.', 'laghavi-social-login' ); ?></p>
				</div>

				<div class="lsl-card">
					<h2><?php esc_html_e( 'Where to show the buttons', 'laghavi-social-login' ); ?></h2>
					<?php
					self::checkbox( 'show_wp_login', __( 'WordPress login page', 'laghavi-social-login' ) );
					self::checkbox( 'show_wp_register', __( 'WordPress registration page', 'laghavi-social-login' ) );
					self::checkbox( 'show_wc_login', __( 'WooCommerce login form (My Account and checkout)', 'laghavi-social-login' ) );
					self::checkbox( 'show_wc_register', __( 'WooCommerce registration form (My Account)', 'laghavi-social-login' ) );
					self::checkbox( 'show_wc_checkout', __( 'WooCommerce checkout, for guests: sign up or log in and return to checkout with the cart kept', 'laghavi-social-login' ) );
					?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: shortcode. */
							esc_html__( 'Show the buttons anywhere else with the shortcode %s.', 'laghavi-social-login' ),
							'<code>[laghavi_social_login]</code>'
						);
						?>
						<?php esc_html_e( 'Optional attributes: redirect_to="https://…" and heading="…".', 'laghavi-social-login' ); ?>
					</p>
					<?php if ( $woo ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to WooCommerce account settings. */
								esc_html__( 'WooCommerce\'s own "create an account during checkout" option is in %s and keeps working alongside the social buttons.', 'laghavi-social-login' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=account' ) ) . '">' . esc_html__( 'WooCommerce > Settings > Accounts & Privacy', 'laghavi-social-login' ) . '</a>'
							);
							?>
						</p>
					<?php endif; ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="lsl-button-text"><?php esc_html_e( 'Button text', 'laghavi-social-login' ); ?></label></th>
							<td>
								<select id="lsl-button-text" name="<?php echo esc_attr( self::OPTION ); ?>[button_text]">
									<option value="continue" <?php selected( $settings['button_text'], 'continue' ); ?>><?php esc_html_e( 'Continue with Google', 'laghavi-social-login' ); ?></option>
									<option value="signin" <?php selected( $settings['button_text'], 'signin' ); ?>><?php esc_html_e( 'Sign in with Google', 'laghavi-social-login' ); ?></option>
									<option value="login" <?php selected( $settings['button_text'], 'login' ); ?>><?php esc_html_e( 'Log in with Google', 'laghavi-social-login' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="lsl-layout"><?php esc_html_e( 'Layout', 'laghavi-social-login' ); ?></label></th>
							<td>
								<select id="lsl-layout" name="<?php echo esc_attr( self::OPTION ); ?>[layout]">
									<option value="full" <?php selected( $settings['layout'], 'full' ); ?>><?php esc_html_e( 'Full-width buttons with text', 'laghavi-social-login' ); ?></option>
									<option value="icons" <?php selected( $settings['layout'], 'icons' ); ?>><?php esc_html_e( 'Icons only, in a row', 'laghavi-social-login' ); ?></option>
								</select>
							</td>
						</tr>
					</table>
					<?php self::checkbox( 'show_credit', __( 'Show a small "Powered by Laghavi Digital" link under the buttons', 'laghavi-social-login' ) ); ?>
				</div>

				<div class="lsl-card">
					<h2><?php esc_html_e( 'Bot protection', 'laghavi-social-login' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Asks a simple math question (for example "What is 7 + 9?"), picked at random from four, before a password login or registration goes through.', 'laghavi-social-login' ); ?></p>
					<?php
					self::checkbox( 'captcha_login', __( 'On the WordPress and WooCommerce login forms', 'laghavi-social-login' ) );
					self::checkbox( 'captcha_register', __( 'On the WordPress and WooCommerce registration forms', 'laghavi-social-login' ) );
					?>
				</div>

				<h2 class="lsl-section-title"><?php esc_html_e( 'Login providers', 'laghavi-social-login' ); ?></h2>
				<?php if ( ! is_ssl() && 0 !== strpos( home_url(), 'https://' ) ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Your site is not using HTTPS. Most providers (and Apple always) require HTTPS redirect URIs.', 'laghavi-social-login' ); ?></p></div>
				<?php endif; ?>

				<?php foreach ( LSL_Providers::all() as $id => $provider ) : ?>
					<?php
					$config = $settings['providers'][ $id ];
					$ready  = LSL_Providers::is_configured( $id );
					$name   = self::OPTION . '[providers][' . $id . ']';
					?>
					<details class="lsl-card lsl-provider" <?php echo $config['enabled'] ? 'open' : ''; ?>>
						<summary>
							<span class="lsl-provider__icon lsl-button--<?php echo esc_attr( $id ); ?>"><?php LSL_Buttons::output( LSL_Buttons::icon( $id ) ); ?></span>
							<span class="lsl-provider__name"><?php echo esc_html( $provider['label'] ); ?></span>
							<?php if ( $config['enabled'] && $ready ) : ?>
								<span class="lsl-badge lsl-badge--on"><?php esc_html_e( 'Active', 'laghavi-social-login' ); ?></span>
							<?php elseif ( $config['enabled'] ) : ?>
								<span class="lsl-badge lsl-badge--warn"><?php esc_html_e( 'Keys missing', 'laghavi-social-login' ); ?></span>
							<?php else : ?>
								<span class="lsl-badge"><?php esc_html_e( 'Off', 'laghavi-social-login' ); ?></span>
							<?php endif; ?>
						</summary>

						<label class="lsl-check">
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( 1, (int) $config['enabled'] ); ?> />
							<?php
							/* translators: %s: provider name. */
							echo esc_html( sprintf( __( 'Enable %s login', 'laghavi-social-login' ), $provider['label'] ) );
							?>
						</label>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php echo 'apple' === $id ? esc_html__( 'Return URL', 'laghavi-social-login' ) : esc_html__( 'Redirect URI', 'laghavi-social-login' ); ?></th>
								<td>
									<div class="lsl-copy">
										<input type="text" class="regular-text code" readonly value="<?php echo esc_attr( LSL_Providers::callback_url( $id ) ); ?>" />
										<button type="button" class="button lsl-copy__button"><?php esc_html_e( 'Copy', 'laghavi-social-login' ); ?></button>
									</div>
									<p class="description"><?php esc_html_e( 'Paste this exactly into the provider\'s app settings.', 'laghavi-social-login' ); ?></p>
								</td>
							</tr>
							<?php foreach ( $provider['fields'] as $field ) : ?>
								<?php
								$field_id  = 'lsl-' . $id . '-' . $field;
								$is_secret = in_array( $field, array( 'client_secret', 'private_key' ), true );
								$has_value = '' !== $config[ $field ];
								?>
								<tr>
									<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( self::field_label( $id, $field ) ); ?></label></th>
									<td>
										<?php if ( 'private_key' === $field ) : ?>
											<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name . '[' . $field . ']' ); ?>" rows="5" class="large-text code" autocomplete="off" placeholder="<?php echo $has_value ? esc_attr__( 'Saved. Leave empty to keep the current key.', 'laghavi-social-login' ) : '-----BEGIN PRIVATE KEY-----'; ?>"></textarea>
										<?php elseif ( $is_secret ) : ?>
											<input type="password" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name . '[' . $field . ']' ); ?>" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_value ? esc_attr__( 'Saved. Leave empty to keep it.', 'laghavi-social-login' ) : ''; ?>" />
										<?php else : ?>
											<input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name . '[' . $field . ']' ); ?>" class="regular-text" value="<?php echo esc_attr( $config[ $field ] ); ?>" autocomplete="off" />
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>

						<div class="lsl-steps">
							<strong><?php esc_html_e( 'How to get these keys', 'laghavi-social-login' ); ?></strong>
							<ol>
								<?php foreach ( self::setup_steps( $id ) as $step ) : ?>
									<li><?php echo esc_html( $step ); ?></li>
								<?php endforeach; ?>
							</ol>
							<a href="<?php echo esc_url( $provider['console'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php
								/* translators: %s: provider name. */
								echo esc_html( sprintf( __( 'Open the %s developer console', 'laghavi-social-login' ), $provider['label'] ) );
								?>
								<span aria-hidden="true">&#8599;</span>
							</a>
						</div>
					</details>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>

			<p class="lsl-admin__credit">
				<?php
				printf(
					/* translators: %s: link to laghavi.com. */
					esc_html__( 'Made with care by %s.', 'laghavi-social-login' ),
					'<a href="https://laghavi.com/" target="_blank" rel="noopener noreferrer">Laghavi Digital</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
