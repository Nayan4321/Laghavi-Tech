<?php
/**
 * More login providers: Slack, TikTok, Twitch, VK (VKontakte), Patreon, Amazon and Disqus.
 *
 * They use the free plugin's provider engine (the saralopa_providers filter), so they appear in
 * Sarala Login Page > Settings with the other providers and work everywhere the buttons are shown.
 *
 * @package SaralaLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extra providers.
 */
class SLPP_Providers {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'saralopa_providers', array( __CLASS__, 'add' ) );
		add_filter( 'saralopa_button_icon', array( __CLASS__, 'icon' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_colors' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_colors' ), 20 );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'front_colors' ), 20 );
		if ( class_exists( 'SARALOPA_Settings' ) ) {
			// The free plugin may have read its settings before these providers were added.
			SARALOPA_Settings::flush_cache();
		}
	}

	/**
	 * Brand colours: background, text.
	 *
	 * @return array
	 */
	public static function colors() {
		return array(
			'slack'     => array( '#ffffff', '#1d1c1d', '#dddddd' ),
			'tiktok'    => array( '#000000', '#ffffff', '#000000' ),
			'twitch'    => array( '#9146ff', '#ffffff', '#9146ff' ),
			'vkontakte' => array( '#0077ff', '#ffffff', '#0077ff' ),
			'patreon'   => array( '#000000', '#ffffff', '#000000' ),
			'amazon'    => array( '#232f3e', '#ffffff', '#232f3e' ),
			'disqus'    => array( '#2e9fff', '#ffffff', '#2e9fff' ),
		);
	}

	/**
	 * Add the providers.
	 *
	 * @param array $all Providers.
	 * @return array
	 */
	public static function add( $all ) {
		// Setup steps are translated, which WordPress only allows from "init" on.
		$t   = did_action( 'init' );
		$all = array_merge(
			$all,
			array(
				'slack'     => array(
					'label'     => 'Slack',
					'console'   => 'https://api.slack.com/apps',
					'fields'    => array( 'client_id', 'client_secret' ),
					'authorize' => 'https://slack.com/openid/connect/authorize',
					'token'     => 'https://slack.com/api/openid.connect.token',
					'scope'     => 'openid email profile',
					'profile'   => array( __CLASS__, 'slack_profile' ),
					'steps'     => $t ? array(
						__( 'api.slack.com/apps > Create New App > From scratch.', 'sarala-login-page-pro' ),
						__( 'OAuth & Permissions: add the Redirect URI below. Under "User Token Scopes" add openid, email and profile.', 'sarala-login-page-pro' ),
						__( 'Basic Information: copy the Client ID and Client Secret here.', 'sarala-login-page-pro' ),
					) : array(),
				),
				'tiktok'    => array(
					'label'        => 'TikTok',
					'console'      => 'https://developers.tiktok.com/apps/',
					'fields'       => array( 'client_id', 'client_secret' ),
					'labels'       => $t ? array(
						'client_id'     => __( 'Client key', 'sarala-login-page-pro' ),
						'client_secret' => __( 'Client secret', 'sarala-login-page-pro' ),
					) : array(),
					'client_param' => 'client_key',
					'authorize'    => 'https://www.tiktok.com/v2/auth/authorize/',
					'token'        => 'https://open.tiktokapis.com/v2/oauth/token/',
					'scope'        => 'user.info.basic',
					'profile'      => array( __CLASS__, 'tiktok_profile' ),
					'no_email'     => true,
					'steps'        => $t ? array(
						__( 'TikTok for Developers > Manage apps > Connect an app, and add the "Login Kit" product for Web.', 'sarala-login-page-pro' ),
						__( 'In Login Kit, add the Redirect URI below. Submit the app for review so everyone can use it.', 'sarala-login-page-pro' ),
						__( 'Copy the Client key and Client secret here.', 'sarala-login-page-pro' ),
						__( 'TikTok does not share email addresses: people connect TikTok to their account from their profile and then log in with it.', 'sarala-login-page-pro' ),
					) : array(),
				),
				'twitch'    => array(
					'label'       => 'Twitch',
					'console'     => 'https://dev.twitch.tv/console/apps',
					'fields'      => array( 'client_id', 'client_secret' ),
					'authorize'   => 'https://id.twitch.tv/oauth2/authorize',
					'token'       => 'https://id.twitch.tv/oauth2/token',
					'scope'       => 'openid user:read:email',
					'auth_params' => array( 'claims' => '{"id_token":{"email":null,"email_verified":null,"preferred_username":null}}' ),
					'profile'     => array( __CLASS__, 'twitch_profile' ),
					'steps'       => $t ? array(
						__( 'Twitch Developer Console > Applications > Register Your Application.', 'sarala-login-page-pro' ),
						__( 'OAuth Redirect URLs: the Redirect URI below. Category: Website Integration. Client type: Confidential.', 'sarala-login-page-pro' ),
						__( 'Copy the Client ID, create a new secret, and paste both here.', 'sarala-login-page-pro' ),
					) : array(),
				),
				'vkontakte' => array(
					'label'        => 'VK',
					'console'      => 'https://id.vk.com/about/business/go',
					'fields'       => array( 'client_id' ),
					'labels'       => $t ? array( 'client_id' => __( 'App ID', 'sarala-login-page-pro' ) ) : array(),
					'authorize'    => 'https://id.vk.com/authorize',
					'token'        => 'https://id.vk.com/oauth2/auth',
					'scope'        => 'email',
					'pkce'         => true,
					'token_params' => array( __CLASS__, 'vk_token_params' ),
					'profile'      => array( __CLASS__, 'vk_profile' ),
					'steps'        => $t ? array(
						__( 'VK ID for business: create a Web application.', 'sarala-login-page-pro' ),
						__( 'Add your site\'s domain and the Redirect URI below as a trusted redirect URL, and allow access to email.', 'sarala-login-page-pro' ),
						__( 'Copy the application ID (App ID) here.', 'sarala-login-page-pro' ),
					) : array(),
				),
				'patreon'   => array(
					'label'     => 'Patreon',
					'console'   => 'https://www.patreon.com/portal/registration/register-clients',
					'fields'    => array( 'client_id', 'client_secret' ),
					'authorize' => 'https://www.patreon.com/oauth2/authorize',
					'token'     => 'https://www.patreon.com/api/oauth2/token',
					'scope'     => 'identity identity[email]',
					'profile'   => array( __CLASS__, 'patreon_profile' ),
					'steps'     => $t ? array(
						__( 'Patreon > Clients & API Keys > Create Client.', 'sarala-login-page-pro' ),
						__( 'Redirect URIs: the Redirect URI below. Client API version: 2.', 'sarala-login-page-pro' ),
						__( 'Copy the Client ID and Client Secret here.', 'sarala-login-page-pro' ),
					) : array(),
				),
				'amazon'    => array(
					'label'     => 'Amazon',
					'console'   => 'https://developer.amazon.com/loginwithamazon/console/site/lwa/overview.html',
					'fields'    => array( 'client_id', 'client_secret' ),
					'authorize' => 'https://www.amazon.com/ap/oa',
					'token'     => 'https://api.amazon.com/auth/o2/token',
					'scope'     => 'profile',
					'profile'   => array( __CLASS__, 'amazon_profile' ),
					'steps'     => $t ? array(
						__( 'Login with Amazon Console > Create a New Security Profile.', 'sarala-login-page-pro' ),
						__( 'Web Settings > Edit: add your site under Allowed Origins and the Redirect URI below under Allowed Return URLs.', 'sarala-login-page-pro' ),
						__( 'Copy the Client ID and Client Secret here.', 'sarala-login-page-pro' ),
					) : array(),
				),
				'disqus'    => array(
					'label'     => 'Disqus',
					'console'   => 'https://disqus.com/api/applications/',
					'fields'    => array( 'client_id', 'client_secret' ),
					'labels'    => $t ? array(
						'client_id'     => __( 'API Key', 'sarala-login-page-pro' ),
						'client_secret' => __( 'API Secret', 'sarala-login-page-pro' ),
					) : array(),
					'authorize' => 'https://disqus.com/api/oauth/2.0/authorize/',
					'token'     => 'https://disqus.com/api/oauth/2.0/access_token/',
					'scope'     => 'read,email',
					'profile'   => array( __CLASS__, 'disqus_profile' ),
					'steps'     => $t ? array(
						__( 'disqus.com/api/applications > Register new application.', 'sarala-login-page-pro' ),
						__( 'Settings > Authentication: Callback URL = the Redirect URI below; Default Access: Read only. Turn on "email" permissions.', 'sarala-login-page-pro' ),
						__( 'Details: copy the API Key and API Secret here.', 'sarala-login-page-pro' ),
					) : array(),
				),
			)
		);
		return $all;
	}

	/**
	 * Slack: OpenID Connect, signature checked against Slack's public keys.
	 *
	 * @param array $token  Token response.
	 * @param array $config Settings.
	 * @return array|WP_Error
	 */
	public static function slack_profile( $token, $config ) {
		$claims = SARALOPA_Providers::id_token_claims( $token, 'https://slack.com', $config['client_id'], 'https://slack.com/openid/connect/keys' );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}
		return array(
			'id'             => isset( $claims['sub'] ) ? $claims['sub'] : '',
			'email'          => isset( $claims['email'] ) ? $claims['email'] : '',
			'email_verified' => ! empty( $claims['email_verified'] ),
			'first_name'     => isset( $claims['given_name'] ) ? $claims['given_name'] : '',
			'last_name'      => isset( $claims['family_name'] ) ? $claims['family_name'] : '',
			'display_name'   => isset( $claims['name'] ) ? $claims['name'] : '',
		);
	}

	/**
	 * TikTok: display name only (TikTok shares no email).
	 *
	 * @param array $token Token response.
	 * @return array|WP_Error
	 */
	public static function tiktok_profile( $token ) {
		$info = SARALOPA_Providers::get_json( 'https://open.tiktokapis.com/v2/user/info/?fields=open_id,union_id,display_name', $token['access_token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$user = isset( $info['data']['user'] ) ? $info['data']['user'] : array();
		return array(
			'id'           => isset( $user['open_id'] ) ? $user['open_id'] : ( isset( $token['open_id'] ) ? $token['open_id'] : '' ),
			'display_name' => isset( $user['display_name'] ) ? $user['display_name'] : '',
		);
	}

	/**
	 * Twitch: OpenID Connect, signature checked against Twitch's public keys.
	 *
	 * @param array $token  Token response.
	 * @param array $config Settings.
	 * @return array|WP_Error
	 */
	public static function twitch_profile( $token, $config ) {
		$claims = SARALOPA_Providers::id_token_claims( $token, 'https://id.twitch.tv/oauth2', $config['client_id'], 'https://id.twitch.tv/oauth2/keys' );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}
		$name = isset( $claims['preferred_username'] ) ? (string) $claims['preferred_username'] : '';
		return array(
			'id'             => isset( $claims['sub'] ) ? $claims['sub'] : '',
			'email'          => isset( $claims['email'] ) ? $claims['email'] : '',
			'email_verified' => ! empty( $claims['email_verified'] ),
			'display_name'   => $name,
		);
	}

	/**
	 * VK ID needs the device ID and state it sent back with the code.
	 *
	 * @param array $extra Callback data.
	 * @return array
	 */
	public static function vk_token_params( $extra ) {
		$params = isset( $extra['params'] ) ? (array) $extra['params'] : array();
		return array(
			'device_id' => isset( $params['device_id'] ) ? (string) $params['device_id'] : '',
			'state'     => isset( $params['state'] ) ? (string) $params['state'] : '',
		);
	}

	/**
	 * VK ID profile.
	 *
	 * @param array $token  Token response.
	 * @param array $config Settings.
	 * @return array|WP_Error
	 */
	public static function vk_profile( $token, $config ) {
		$info = SARALOPA_Providers::post_json(
			'https://id.vk.com/oauth2/user_info',
			array(
				'client_id'    => $config['client_id'],
				'access_token' => $token['access_token'],
			),
			array(),
			false
		);
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$user = isset( $info['user'] ) ? $info['user'] : array();
		return array(
			'id'             => isset( $user['user_id'] ) ? $user['user_id'] : ( isset( $token['user_id'] ) ? $token['user_id'] : '' ),
			'email'          => isset( $user['email'] ) ? $user['email'] : '',
			// VK does not say whether the address was confirmed, so it is never used to match an existing account.
			'email_verified' => false,
			'first_name'     => isset( $user['first_name'] ) ? $user['first_name'] : '',
			'last_name'      => isset( $user['last_name'] ) ? $user['last_name'] : '',
		);
	}

	/**
	 * Patreon profile, with Patreon's own "email verified" flag.
	 *
	 * @param array $token Token response.
	 * @return array|WP_Error
	 */
	public static function patreon_profile( $token ) {
		$info = SARALOPA_Providers::get_json( 'https://www.patreon.com/api/oauth2/v2/identity?fields%5Buser%5D=email,first_name,last_name,full_name,is_email_verified', $token['access_token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$data = isset( $info['data'] ) ? $info['data'] : array();
		$attr = isset( $data['attributes'] ) ? $data['attributes'] : array();
		return array(
			'id'             => isset( $data['id'] ) ? $data['id'] : '',
			'email'          => isset( $attr['email'] ) ? $attr['email'] : '',
			'email_verified' => ! empty( $attr['is_email_verified'] ),
			'first_name'     => isset( $attr['first_name'] ) ? $attr['first_name'] : '',
			'last_name'      => isset( $attr['last_name'] ) ? $attr['last_name'] : '',
			'display_name'   => isset( $attr['full_name'] ) ? $attr['full_name'] : '',
		);
	}

	/**
	 * Login with Amazon profile.
	 *
	 * @param array $token Token response.
	 * @return array|WP_Error
	 */
	public static function amazon_profile( $token ) {
		$info = SARALOPA_Providers::get_json( 'https://api.amazon.com/user/profile', $token['access_token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		list( $first, $last ) = SARALOPA_Providers::split_name( isset( $info['name'] ) ? $info['name'] : '' );
		return array(
			'id'             => isset( $info['user_id'] ) ? $info['user_id'] : '',
			'email'          => isset( $info['email'] ) ? $info['email'] : '',
			// Amazon gives no "verified" flag, so the address never matches an existing account on its own.
			'email_verified' => false,
			'first_name'     => $first,
			'last_name'      => $last,
			'display_name'   => isset( $info['name'] ) ? $info['name'] : '',
		);
	}

	/**
	 * Disqus profile.
	 *
	 * @param array $token  Token response.
	 * @param array $config Settings.
	 * @return array|WP_Error
	 */
	public static function disqus_profile( $token, $config ) {
		$url  = add_query_arg(
			array(
				'access_token' => rawurlencode( $token['access_token'] ),
				'api_key'      => rawurlencode( $config['client_id'] ),
				'api_secret'   => rawurlencode( $config['client_secret'] ),
			),
			'https://disqus.com/api/3.0/users/details.json'
		);
		$info = SARALOPA_Providers::get_json( $url );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$user                 = isset( $info['response'] ) ? $info['response'] : array();
		list( $first, $last ) = SARALOPA_Providers::split_name( isset( $user['name'] ) ? $user['name'] : '' );
		return array(
			'id'             => isset( $user['id'] ) ? $user['id'] : ( isset( $token['user_id'] ) ? $token['user_id'] : '' ),
			'email'          => isset( $user['email'] ) ? $user['email'] : '',
			'email_verified' => false,
			'first_name'     => $first,
			'last_name'      => $last,
			'display_name'   => isset( $user['name'] ) ? $user['name'] : ( isset( $user['username'] ) ? $user['username'] : '' ),
		);
	}

	/**
	 * Icons.
	 *
	 * @param string $svg      SVG so far.
	 * @param string $provider Provider ID.
	 * @return string
	 */
	public static function icon( $svg, $provider ) {
		$icons = array(
			'tiktok'    => '<path fill="currentColor" d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>',
			'twitch'    => '<path fill="currentColor" d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714Z"/>',
			'vkontakte' => '<path fill="currentColor" d="m9.489.004.729-.003h3.564l.73.003.914.01.433.007.418.011.403.014.388.016.374.021.36.025.345.03.333.033c1.74.196 2.933.616 3.833 1.516.9.9 1.32 2.092 1.516 3.833l.034.333.029.346.025.36.02.373.025.588.012.41.013.644.009.915.004.98-.001 3.313-.003.73-.01.914-.007.433-.011.418-.014.403-.016.388-.021.374-.025.36-.03.345-.033.333c-.196 1.74-.616 2.933-1.516 3.833-.9.9-2.092 1.32-3.833 1.516l-.333.034-.346.029-.36.025-.373.02-.588.025-.41.012-.644.013-.915.009-.98.004-3.313-.001-.73-.003-.914-.01-.433-.007-.418-.011-.403-.014-.388-.016-.374-.021-.36-.025-.345-.03-.333-.033c-1.74-.196-2.933-.616-3.833-1.516-.9-.9-1.32-2.092-1.516-3.833l-.034-.333-.029-.346-.025-.36-.02-.373-.025-.588-.012-.41-.013-.644-.009-.915-.004-.98.001-3.313.003-.73.01-.914.007-.433.011-.418.014-.403.016-.388.021-.374.025-.36.03-.345.033-.333c.196-1.74.616-2.933 1.516-3.833.9-.9 2.092-1.32 3.833-1.516l.333-.034.346-.029.36-.025.373-.02.588-.025.41-.012.644-.013.915-.009ZM6.79 7.3H4.05c.13 6.24 3.25 9.99 8.72 9.99h.31v-3.57c2.01.2 3.53 1.67 4.14 3.57h2.84c-.78-2.84-2.83-4.41-4.11-5.01 1.28-.74 3.08-2.54 3.51-4.98h-2.58c-.56 1.98-2.22 3.78-3.8 3.95V7.3H10.5v6.92c-1.6-.4-3.62-2.34-3.71-6.92Z"/>',
			'patreon'   => '<path fill="currentColor" d="M22.957 7.21c-.004-3.064-2.391-5.576-5.191-6.482-3.478-1.125-8.064-.962-11.384.604C2.357 3.231 1.093 7.391 1.046 11.54c-.039 3.411.302 12.396 5.369 12.46 3.765.047 4.326-4.804 6.068-7.141 1.24-1.662 2.836-2.132 4.801-2.618 3.376-.836 5.678-3.501 5.673-7.031Z"/>',
			'disqus'    => '<path fill="currentColor" d="M12.438 23.654c-2.853 0-5.46-1.04-7.476-2.766L0 21.568l1.917-4.733C1.25 15.36.875 13.725.875 12 .875 5.564 6.05.346 12.44.346 18.82.346 24 5.564 24 12c0 6.438-5.176 11.654-11.562 11.654zm6.315-11.687v-.033c0-3.363-2.373-5.76-6.462-5.76H7.877V17.83h4.35c4.12 0 6.525-2.5 6.525-5.863h.004zm-6.415 2.998h-1.29V9.04h1.29c1.897 0 3.157 1.08 3.157 2.945v.03c0 1.884-1.26 2.95-3.157 2.95z"/>',
			'slack'     => '<rect x="1" y="6.6" width="11.6" height="3.6" rx="1.8" fill="#36C5F0"/><rect x="6.6" y="1" width="3.6" height="3.6" rx="1.8" fill="#36C5F0"/><rect x="13.8" y="1" width="3.6" height="11.6" rx="1.8" fill="#2EB67D"/><rect x="19.4" y="6.6" width="3.6" height="3.6" rx="1.8" fill="#2EB67D"/><rect x="11.4" y="13.8" width="11.6" height="3.6" rx="1.8" fill="#ECB22E"/><rect x="13.8" y="19.4" width="3.6" height="3.6" rx="1.8" fill="#ECB22E"/><rect x="6.6" y="11.4" width="3.6" height="11.6" rx="1.8" fill="#E01E5A"/><rect x="1" y="13.8" width="3.6" height="3.6" rx="1.8" fill="#E01E5A"/>',
			'amazon'    => '<text x="12" y="14.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="15" font-weight="700" fill="currentColor">a</text><path d="M5 17.2c4.2 2.7 9.8 2.7 14 0" fill="none" stroke="#FF9900" stroke-width="1.9" stroke-linecap="round"/>',
		);
		if ( ! isset( $icons[ $provider ] ) ) {
			return $svg;
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" focusable="false">' . $icons[ $provider ] . '</svg>';
	}

	/**
	 * Button colours as CSS.
	 *
	 * @param string $prefix Selector prefix.
	 * @return string
	 */
	private static function css( $prefix ) {
		$css = '';
		foreach ( self::colors() as $id => $c ) {
			$css .= $prefix . '.saralopa-button--' . $id . '{background:' . $c[0] . ';color:' . $c[1] . ' !important;border-color:' . $c[2] . '}';
		}
		return $css;
	}

	/**
	 * Colours on the site and login page.
	 */
	public static function front_colors() {
		if ( wp_style_is( 'sarala-login-page', 'registered' ) ) {
			wp_add_inline_style( 'sarala-login-page', self::css( '' ) );
		}
	}

	/**
	 * Colours of the icons in the settings.
	 */
	public static function admin_colors() {
		if ( wp_style_is( 'saralopa-admin', 'enqueued' ) || wp_style_is( 'saralopa-admin', 'registered' ) ) {
			wp_add_inline_style( 'saralopa-admin', self::css( '.saralopa-provider__icon' ) );
		}
	}
}
