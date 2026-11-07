<?php
/**
 * WooCommerce > Media Archives: plans and features, media hosts, protection, app access (API keys,
 * webhooks, single sign-on), security and the portal.
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class RINNOVOMA_Admin {

	const TD   = 'rinnovo-media-archives-pro';
	const PAGE = 'rinnovo-media-archives';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 62 );
		add_action( 'admin_post_rinnovoma_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Media Archives', self::TD ), __( 'Media Archives', self::TD ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Scripts.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::PAGE ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'rinnovoma-admin', RINNOVOMA_URL . 'assets/js/admin.js', array( 'jquery', 'wc-enhanced-select' ), RINNOVOMA_VERSION, true );
		}
	}

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	private static function tabs() {
		return array(
			'plans'    => __( 'Plans and features', self::TD ),
			'hosts'    => __( 'Media hosts', self::TD ),
			'protect'  => __( 'Protection', self::TD ),
			'app'      => __( 'Web app access', self::TD ),
			'security' => __( 'Security', self::TD ),
		);
	}

	/**
	 * A text / number / textarea / checkbox / select row.
	 *
	 * @param string $key   Setting.
	 * @param string $label Label.
	 * @param string $type  Type.
	 * @param string $help  Help.
	 * @param array  $opts  Options for selects.
	 */
	private static function row( $key, $label, $type = 'text', $help = '', $opts = array() ) {
		$v = RINNOVOMA_Plans::get( $key );
		echo '<tr><th><label for="rma-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'checkbox' === $type ) {
			echo '<label><input type="checkbox" id="rma-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" value="1" ' . checked( (bool) $v, true, false ) . ' /> ' . esc_html( $help ) . '</label>';
			$help = '';
		} elseif ( 'textarea' === $type ) {
			echo '<textarea id="rma-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" rows="5" class="large-text code">' . esc_textarea( (string) $v ) . '</textarea>';
		} elseif ( 'select' === $type ) {
			echo '<select id="rma-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']">';
			foreach ( $opts as $k => $l ) {
				echo '<option value="' . esc_attr( $k ) . '" ' . selected( (string) $v, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" id="rma-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $v ) . '" class="' . ( 'number' === $type ? 'small-text' : 'regular-text' ) . '" autocomplete="off" />';
		}
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * The page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'plans';
		$tab = isset( self::tabs()[ $tab ] ) ? $tab : 'plans';
		echo '<div class="wrap"><h1>' . esc_html__( 'Media Archives', self::TD ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Notice only.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', self::TD ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $key => $label ) {
			echo '<a class="nav-tab ' . ( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rinnovoma_save" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		wp_nonce_field( 'rinnovoma_save' );
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * One plan's fields.
	 *
	 * @param array      $plan Plan.
	 * @param int|string $i    Index.
	 */
	private static function plan_fields( $plan, $i ) {
		$n = 'plans[' . $i . ']';
		echo '<div class="rinnovoma-plan-edit" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;margin:0 0 14px"><p><input type="text" name="' . esc_attr( $n ) . '[name]" value="' . esc_attr( $plan['name'] ) . '" placeholder="' . esc_attr__( 'Plan name, e.g. Pro', self::TD ) . '" class="regular-text" /> <input type="hidden" name="' . esc_attr( $n ) . '[id]" value="' . esc_attr( $plan['id'] ) . '" /> <button type="button" class="button-link-delete rinnovoma-remove">' . esc_html__( 'Remove', self::TD ) . '</button></p><table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Products', self::TD ) . '</th><td><select class="wc-product-search" multiple="multiple" style="width:100%" name="' . esc_attr( $n ) . '[products][]" data-action="woocommerce_json_search_products_and_variations">';
		foreach ( $plan['products'] as $pid ) {
			$p = wc_get_product( $pid );
			if ( $p ) {
				echo '<option value="' . (int) $pid . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select><p class="description">' . esc_html__( 'Subscription products or variations (Basic Monthly, Basic Yearly…) that give this plan.', self::TD ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Features and quotas', self::TD ) . '</th><td><textarea name="' . esc_attr( $n ) . '[features]" rows="4" class="large-text code" placeholder="ai_credits=500&#10;exports=yes&#10;projects=10">' . esc_textarea( $plan['features'] ) . '</textarea><p class="description">' . esc_html__( 'One per line. Numbers are quotas per billing period (count usage with POST /usage); yes/no are feature flags. Sent to your app in the API, tokens and webhooks.', self::TD ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Limits', self::TD ) . '</th><td><input type="number" min="0" name="' . esc_attr( $n ) . '[devices]" value="' . esc_attr( $plan['devices'] ) . '" class="small-text" /> ' . esc_html__( 'devices signed in at once', self::TD ) . ' &nbsp; <input type="number" min="0" name="' . esc_attr( $n ) . '[streams]" value="' . esc_attr( $plan['streams'] ) . '" class="small-text" /> ' . esc_html__( 'streams at once (0 = no limit)', self::TD ) . '</td></tr></table></div>';
	}

	/**
	 * Plans tab.
	 */
	private static function tab_plans() {
		echo '<p>' . esc_html__( 'Lowest plan first; content for a plan is open to the plans below it in this list.', self::TD ) . '</p><div class="rinnovoma-plans">';
		$i = 0;
		foreach ( RINNOVOMA_Plans::all() as $plan ) {
			self::plan_fields( $plan, $i++ );
		}
		echo '</div><script type="text/html" id="tmpl-rinnovoma-plan">';
		ob_start();
		self::plan_fields(
			array(
				'id'       => '',
				'name'     => '',
				'products' => array(),
				'features' => '',
				'devices'  => 0,
				'streams'  => 0,
			),
			'__i__'
		);
		echo str_replace( 'wc-product-search', 'wc-product-search-new', ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		echo '</script><p><button type="button" class="button rinnovoma-add">' . esc_html__( '+ Add a plan', self::TD ) . '</button></p>';
		echo '<table class="form-table" role="presentation">';
		self::row( 'grace_days', __( 'Failed payments', self::TD ), 'number', __( 'Days of access after a renewal payment fails, before everything is cut off.', self::TD ) );
		self::row( 'switch_credit', __( 'Plan changes', self::TD ), 'checkbox', __( 'Credit unused time when upgrading (for example monthly to yearly) — in the portal, through the normal checkout', self::TD ) );
		self::row( 'pause_months', __( 'Pausing', self::TD ), 'number', __( 'Months a subscriber can pause at most (0 = no pausing).', self::TD ) );
		echo '</table><p class="description">' . esc_html__( 'Free trials of 7 or 14 days: set "Free trial (days)" on the product; tick "Trial without a card" there to ask for no payment method at sign-up.', self::TD ) . '</p>';
	}

	/**
	 * Media hosts tab.
	 */
	private static function tab_hosts() {
		echo '<p>' . esc_html__( 'Fill in the hosts you use. Links are made here for subscribers only and expire, so they cannot be copied from the page source and reused.', self::TD ) . '</p><table class="form-table" role="presentation">';
		self::row( 'link_minutes', __( 'Links expire after', self::TD ), 'number', __( 'Minutes (players refresh their link when the page loads).', self::TD ) );
		echo '<tr><th colspan="2"><h2>Bunny.net</h2></th></tr>';
		self::row( 'bunny_library', __( 'Stream library ID', self::TD ) );
		self::row( 'bunny_key', __( 'Stream token authentication key', self::TD ), 'password', __( 'Library > Security > "Embed view token authentication" (turn it on, and add your domain under Allowed domains).', self::TD ) );
		self::row( 'bunny_cdn_host', __( 'CDN pull zone hostname', self::TD ), 'text', 'media.example.b-cdn.net' );
		self::row( 'bunny_cdn_key', __( 'CDN token authentication key', self::TD ), 'password', __( 'Pull zone > Security > Token authentication (for audio, video files and high-res art).', self::TD ) );
		echo '<tr><th colspan="2"><h2>Cloudflare Stream</h2></th></tr>';
		self::row( 'cf_code', __( 'Customer code', self::TD ), 'text', __( 'From your Stream embed address: customer-CODE.cloudflarestream.com', self::TD ) );
		self::row( 'cf_key_id', __( 'Signing key ID', self::TD ) );
		self::row( 'cf_pem', __( 'Signing key (pem)', self::TD ), 'textarea', __( 'Create it with the Stream "keys" API and turn on "Require signed URLs" on your videos.', self::TD ) );
		echo '<tr><th colspan="2"><h2>Amazon CloudFront / S3</h2></th></tr>';
		self::row( 'cfr_domain', __( 'CloudFront domain', self::TD ), 'text', 'd1234abcd.cloudfront.net' );
		self::row( 'cfr_key_pair', __( 'Key pair / public key ID', self::TD ) );
		self::row( 'cfr_private_key', __( 'Private key (PEM)', self::TD ), 'textarea' );
		self::row( 's3_bucket', __( 'S3 bucket', self::TD ) );
		self::row( 's3_region', __( 'S3 region', self::TD ), 'text', 'eu-central-1' );
		self::row( 's3_key', __( 'Access key ID', self::TD ) );
		self::row( 's3_secret', __( 'Secret access key', self::TD ), 'password', __( 'Use an IAM user that can only read this bucket. Keep the bucket private.', self::TD ) );
		echo '</table><h2>' . esc_html__( 'Using them', self::TD ) . '</h2><p><code>[media_video provider="bunny" id="VIDEO_ID" plan="pro"]</code> · <code>[media_video provider="cloudflare" id="UID"]</code> · <code>[media_video provider="vimeo" id="123456/hash"]</code> · <code>[media_video provider="cloudfront" id="/courses/lesson-1.mp4"]</code> · <code>[media_video provider="presto" id="12"]</code><br /><code>[media_audio provider="bunnycdn" id="/albums/track-01.mp3"]</code> · <code>[media_audio provider="s3" id="podcast/ep1.mp3"]</code> · <code>[media_gallery ids="10,11,12" plan="pro"]</code> · <code>[media_account]</code></p>';
	}

	/**
	 * Protection tab.
	 */
	private static function tab_protect() {
		echo '<table class="form-table" role="presentation">';
		self::row( 'watermark', __( 'Watermark', self::TD ), 'checkbox', __( 'Show a moving watermark over players and draw it into gallery images', self::TD ) );
		self::row( 'watermark_text', __( 'Watermark text', self::TD ), 'text', __( '{email}, {name}, {ip}, {date} and {id} are replaced for each subscriber.', self::TD ) );
		self::row( 'protect', __( 'Copy protection', self::TD ), 'checkbox', __( 'No right-click, dragging, text selection or download buttons on players and galleries', self::TD ) );
		self::row( 'referer_lock', __( 'Only on this site', self::TD ), 'checkbox', __( 'Media and art links only work on your own pages (no hotlinking)', self::TD ) );
		self::row( 'art_width', __( 'Gallery images', self::TD ), 'number', __( 'Widest size shown, in pixels. The full-resolution original is never sent.', self::TD ) );
		self::row( 'feed_ip_limit', __( 'Shared podcast feeds', self::TD ), 'number', __( 'Flag a private feed used from more than this many IP addresses in a day (0 = off).', self::TD ) );
		echo '</table><p class="description">' . esc_html__( 'In the Media Library, "Subscribers only" moves a file to a protected folder (Apache / LiteSpeed read its .htaccess; on Nginx deny the /rinnovoma-protected/ folder). No website can fully stop screen recording; the watermark makes leaks traceable to the account.', self::TD ) . '</p>';
	}

	/**
	 * App tab.
	 */
	private static function tab_app() {
		$keys    = (array) RINNOVOMA_Plans::get( 'api_keys' );
		$hooks   = (array) RINNOVOMA_Plans::get( 'webhooks' );
		$clients = (array) RINNOVOMA_Plans::get( 'oauth_clients' );
		echo '<h2>' . esc_html__( 'Your app', self::TD ) . '</h2><table class="form-table" role="presentation">';
		self::row( 'app_url', __( 'App address', self::TD ), 'url' );
		self::row( 'app_login_url', __( '"Open the app" link', self::TD ), 'url', __( 'For example your app\'s "Sign in with" button address. Empty = the app address.', self::TD ) );
		self::row( 'frame_origins', __( 'Show the portal inside your app', self::TD ), 'text', __( 'Origins allowed to frame My Account, e.g. https://app.example.com. Or send users to a one-time POST /portal-link.', self::TD ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'API keys (server to server)', self::TD ) . '</h2><p>' . esc_html__( 'Send as the X-Api-Key header. Endpoints:', self::TD ) . ' <code>' . esc_html( rest_url( RINNOVOMA_Api::NS . '/access?email=…' ) ) . '</code>, <code>/usage</code>, <code>/portal-link</code>.</p><table class="widefat striped" style="max-width:900px"><tr><th>' . esc_html__( 'Name', self::TD ) . '</th><th>' . esc_html__( 'Key', self::TD ) . '</th><th>' . esc_html__( 'Remove', self::TD ) . '</th></tr>';
		foreach ( $keys as $i => $k ) {
			echo '<tr><td>' . esc_html( $k['name'] ) . '</td><td><code>' . esc_html( $k['key'] ) . '</code></td><td><input type="checkbox" name="remove_key[]" value="' . (int) $i . '" /></td></tr>';
		}
		echo '<tr><td colspan="3"><input type="text" name="new_key" placeholder="' . esc_attr__( 'New key name, e.g. Production app', self::TD ) . '" class="regular-text" /></td></tr></table>';

		echo '<h2>' . esc_html__( 'Webhooks', self::TD ) . '</h2><p>' . esc_html__( 'POSTed as JSON, signed with the header X-Rinnovo-Signature: t=TIME,v1=HMAC-SHA256(secret, TIME.body). Events: subscription.active, subscription.trial, subscription.payment_failed, subscription.cancelled, subscription.expired, subscription.paused, access.revoked, security.alert. Failed deliveries are retried 5 times.', self::TD ) . '</p>';
		/* translators: %s: secret. */
		echo '<p>' . sprintf( esc_html__( 'Signing secret: %s', self::TD ), '<code>' . esc_html( (string) RINNOVOMA_Plans::get( 'webhook_secret' ) ) . '</code>' ) . '</p><table class="widefat striped" style="max-width:900px"><tr><th>URL</th><th>' . esc_html__( 'Only these events (comma separated, empty = all)', self::TD ) . '</th></tr>';
		foreach ( array_merge( $hooks, array( array( 'url' => '', 'events' => '' ) ) ) as $i => $h ) {
			echo '<tr><td><input type="url" name="hooks[' . (int) $i . '][url]" value="' . esc_attr( $h['url'] ) . '" class="regular-text" placeholder="https://app.example.com/webhooks/rinnovo" /></td><td><input type="text" name="hooks[' . (int) $i . '][events]" value="' . esc_attr( $h['events'] ) . '" class="regular-text" /></td></tr>';
		}
		echo '</table><p><label><input type="checkbox" name="test_hooks" value="1" /> ' . esc_html__( 'Send a test event after saving', self::TD ) . '</label></p>';

		echo '<h2>' . esc_html__( 'Single sign-on (OAuth 2 / OpenID)', self::TD ) . '</h2><p>' . esc_html__( 'Discovery:', self::TD ) . ' <code>' . esc_html( rest_url( RINNOVOMA_Api::NS . '/oauth/openid-configuration' ) ) . '</code> · ' . esc_html__( 'JWT log-in: POST', self::TD ) . ' <code>' . esc_html( rest_url( RINNOVOMA_Api::NS . '/token' ) ) . '</code></p><table class="widefat striped" style="max-width:1100px"><tr><th>' . esc_html__( 'App name', self::TD ) . '</th><th>' . esc_html__( 'Client ID / secret', self::TD ) . '</th><th>' . esc_html__( 'Redirect addresses (one per line)', self::TD ) . '</th><th>' . esc_html__( 'Skip the consent screen', self::TD ) . '</th></tr>';
		foreach ( array_merge( $clients, array( array( 'name' => '', 'id' => '', 'secret' => '', 'redirects' => '', 'trusted' => 0 ) ) ) as $i => $c ) {
			echo '<tr><td><input type="text" name="clients[' . (int) $i . '][name]" value="' . esc_attr( $c['name'] ) . '" placeholder="' . esc_attr__( 'New app', self::TD ) . '" /></td><td>' . ( '' !== $c['id'] ? '<code>' . esc_html( $c['id'] ) . '</code><br /><code>' . esc_html( '' !== $c['secret'] ? $c['secret'] : __( '(public client: PKCE)', self::TD ) ) . '</code><input type="hidden" name="clients[' . (int) $i . '][id]" value="' . esc_attr( $c['id'] ) . '" /><input type="hidden" name="clients[' . (int) $i . '][secret]" value="' . esc_attr( $c['secret'] ) . '" />' : '<label><input type="checkbox" name="clients[' . (int) $i . '][public]" value="1" /> ' . esc_html__( 'Public client (mobile / single-page app, no secret)', self::TD ) . '</label>' ) . '</td><td><textarea name="clients[' . (int) $i . '][redirects]" rows="2" cols="40">' . esc_textarea( $c['redirects'] ) . '</textarea></td><td><input type="checkbox" name="clients[' . (int) $i . '][trusted]" value="1" ' . checked( ! empty( $c['trusted'] ), true, false ) . ' /></td></tr>';
		}
		echo '</table><p class="description">' . esc_html__( 'Empty the name of an app to remove it.', self::TD ) . '</p>';
	}

	/**
	 * Security tab.
	 */
	private static function tab_security() {
		echo '<table class="form-table" role="presentation">';
		self::row(
			'anomaly',
			__( 'Same account in two countries', self::TD ),
			'select',
			'',
			array(
				'flag'   => __( 'Flag it: email you and the customer', self::TD ),
				'logout' => __( 'Flag it and sign the account out everywhere', self::TD ),
				'off'    => __( 'Off', self::TD ),
			)
		);
		self::row( 'anomaly_minutes', __( 'Within', self::TD ), 'number', __( 'Minutes. Needs Cloudflare or MaxMind geolocation (WooCommerce > Settings > Integration).', self::TD ) );
		self::row( 'max_logins', __( 'Site log-ins at once', self::TD ), 'number', __( 'When a plan sets no device limit (0 = no limit). A new log-in signs out the oldest.', self::TD ) );
		self::row( 'cutoff_logout', __( 'When access ends', self::TD ), 'checkbox', __( 'Also sign the customer out of this site (app tokens, feeds and players always stop at once)', self::TD ) );
		echo '</table><h2>' . esc_html__( 'Recent alerts', self::TD ) . '</h2><table class="widefat striped"><tr><th>' . esc_html__( 'When', self::TD ) . '</th><th>' . esc_html__( 'Customer', self::TD ) . '</th><th>' . esc_html__( 'What', self::TD ) . '</th><th>IP</th></tr>';
		$log = array_reverse( (array) get_option( RINNOVOMA_Security::LOG, array() ) );
		if ( ! $log ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No alerts.', self::TD ) . '</td></tr>';
		}
		foreach ( array_slice( $log, 0, 50 ) as $row ) {
			$u = get_userdata( (int) $row['user'] );
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) ) . '</td><td>' . esc_html( $u ? $u->user_email : '#' . $row['user'] ) . '</td><td>' . esc_html( $row['text'] ) . '</td><td>' . esc_html( $row['ip'] ) . '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * Save a tab.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', self::TD ) );
		}
		check_admin_referer( 'rinnovoma_save' );
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'plans';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned per field below.
		$raw    = isset( $_POST['s'] ) ? (array) wp_unslash( $_POST['s'] ) : array();
		$fields = array(
			'plans'    => array( 'grace_days' => 'int', 'switch_credit' => 'bool', 'pause_months' => 'int' ),
			'hosts'    => array( 'link_minutes' => 'int', 'bunny_library' => 'text', 'bunny_key' => 'text', 'bunny_cdn_host' => 'text', 'bunny_cdn_key' => 'text', 'cf_code' => 'text', 'cf_key_id' => 'text', 'cf_pem' => 'raw', 'cfr_domain' => 'text', 'cfr_key_pair' => 'text', 'cfr_private_key' => 'raw', 's3_bucket' => 'text', 's3_region' => 'text', 's3_key' => 'text', 's3_secret' => 'text' ),
			'protect'  => array( 'watermark' => 'bool', 'watermark_text' => 'text', 'protect' => 'bool', 'referer_lock' => 'bool', 'art_width' => 'int', 'feed_ip_limit' => 'int' ),
			'app'      => array( 'app_url' => 'url', 'app_login_url' => 'url', 'frame_origins' => 'text' ),
			'security' => array( 'anomaly' => 'text', 'anomaly_minutes' => 'int', 'max_logins' => 'int', 'cutoff_logout' => 'bool' ),
		);
		$values = array();
		foreach ( isset( $fields[ $tab ] ) ? $fields[ $tab ] : array() as $key => $type ) {
			$v = isset( $raw[ $key ] ) ? $raw[ $key ] : '';
			if ( 'int' === $type ) {
				$values[ $key ] = absint( $v );
			} elseif ( 'bool' === $type ) {
				$values[ $key ] = '' !== $v ? 1 : 0;
			} elseif ( 'url' === $type ) {
				$values[ $key ] = esc_url_raw( $v );
			} elseif ( 'raw' === $type ) {
				$values[ $key ] = trim( sanitize_textarea_field( $v ) );
			} else {
				$values[ $key ] = sanitize_text_field( $v );
			}
		}
		if ( 'plans' === $tab ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned in RINNOVOMA_Plans::save().
			$plans = isset( $_POST['plans'] ) ? (array) wp_unslash( $_POST['plans'] ) : array();
			unset( $plans['__i__'] );
			RINNOVOMA_Plans::save( array_values( $plans ) );
		}
		if ( 'app' === $tab ) {
			$keys = (array) RINNOVOMA_Plans::get( 'api_keys' );
			foreach ( isset( $_POST['remove_key'] ) ? array_map( 'absint', (array) $_POST['remove_key'] ) : array() as $i ) {
				unset( $keys[ $i ] );
			}
			$name = isset( $_POST['new_key'] ) ? sanitize_text_field( wp_unslash( $_POST['new_key'] ) ) : '';
			if ( '' !== $name ) {
				$keys[] = array(
					'name' => $name,
					'key'  => 'rma_' . strtolower( wp_generate_password( 40, false ) ),
				);
			}
			$values['api_keys'] = array_values( $keys );
			$hooks              = array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
			foreach ( isset( $_POST['hooks'] ) ? (array) wp_unslash( $_POST['hooks'] ) : array() as $h ) {
				$url = isset( $h['url'] ) ? esc_url_raw( $h['url'] ) : '';
				if ( '' !== $url ) {
					$hooks[] = array(
						'url'    => $url,
						'events' => isset( $h['events'] ) ? sanitize_text_field( $h['events'] ) : '',
					);
				}
			}
			$values['webhooks'] = $hooks;
			$clients            = array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
			foreach ( isset( $_POST['clients'] ) ? (array) wp_unslash( $_POST['clients'] ) : array() as $c ) {
				$cname = isset( $c['name'] ) ? sanitize_text_field( $c['name'] ) : '';
				if ( '' === $cname ) {
					continue;
				}
				$new       = empty( $c['id'] );
				$clients[] = array(
					'name'      => $cname,
					'id'        => $new ? 'rma_' . strtolower( wp_generate_password( 20, false ) ) : sanitize_text_field( $c['id'] ),
					'secret'    => $new ? ( ! empty( $c['public'] ) ? '' : wp_generate_password( 40, false ) ) : sanitize_text_field( isset( $c['secret'] ) ? $c['secret'] : '' ),
					'redirects' => isset( $c['redirects'] ) ? implode( "\n", array_filter( array_map( 'esc_url_raw', preg_split( '/[\r\n]+/', $c['redirects'] ) ) ) ) : '',
					'trusted'   => ! empty( $c['trusted'] ) ? 1 : 0,
				);
			}
			$values['oauth_clients'] = $clients;
		}
		RINNOVOMA_Plans::save_settings( $values );
		if ( 'app' === $tab && ! empty( $_POST['test_hooks'] ) ) {
			RINNOVOMA_Api::event( 'test', get_current_user_id() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $tab . '&saved=1' ) );
		exit;
	}
}
