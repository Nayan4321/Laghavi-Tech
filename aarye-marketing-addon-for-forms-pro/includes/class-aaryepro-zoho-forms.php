<?php
/**
 * Zoho Forms: pass each visitor's source into embedded Zoho Forms (hidden fields), and receive their
 * entries back through a webhook, so they appear in Aarye with the ad that brought them.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zoho Forms.
 */
class AARYEPRO_Zoho_Forms {

	const KEY = 'zoho_forms';

	/**
	 * Hidden fields filled from the visitor's source.
	 */
	const HIDDEN = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'campaign_id', 'adset_id', 'ad_id', 'aarye_landing', 'aarye_referrer', 'aarye_page', 'aarye_vid' );

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter(
			'aarye_form_plugins',
			static function ( $plugins ) {
				$plugins[ self::KEY ] = array( 'name' => 'Zoho Forms' );
				return $plugins;
			}
		);
		add_filter(
			'aarye_form_plugin_active',
			static function ( $active, $key ) {
				return self::KEY === $key ? self::on() : $active;
			},
			10,
			2
		);
		add_action( 'admin_post_aaryepro_zoho_forms', array( __CLASS__, 'save' ) );
		if ( ! self::on() ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ), 20 );
	}

	/**
	 * Whether receiving Zoho Forms entries is switched on.
	 *
	 * @return bool
	 */
	public static function on() {
		return 'yes' === AARYEPRO_Connections::get( self::KEY, 'enabled' );
	}

	/**
	 * Secret part of the webhook address (created once).
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = AARYEPRO_Connections::get( self::KEY, 'secret' );
		if ( '' === $secret ) {
			$secret           = wp_generate_password( 24, false, false );
			$all              = (array) get_option( AARYEPRO_Connections::OPTION, array() );
			$all[ self::KEY ] = array_merge( AARYEPRO_Connections::settings( self::KEY ), array( 'secret' => $secret ) );
			update_option( AARYEPRO_Connections::OPTION, $all, false );
		}
		return $secret;
	}

	/**
	 * Webhook address to paste into Zoho Forms.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( 'aarye-pro/v1/zoho-forms/' . self::secret() );
	}

	/**
	 * REST route.
	 */
	public static function routes() {
		register_rest_route(
			'aarye-pro/v1',
			'/zoho-forms/(?P<secret>[A-Za-z0-9]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => '__return_true', // Checked with the secret in the address.
			)
		);
	}

	/**
	 * An entry from Zoho Forms.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function receive( $request ) {
		if ( ! hash_equals( self::secret(), (string) $request['secret'] ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || ! $data ) {
			$data = $request->get_body_params();
		}
		// Zoho may wrap the values in one key.
		if ( 1 === count( $data ) && is_array( reset( $data ) ) ) {
			$data = reset( $data );
		}
		$hidden = array();
		$fields = array();
		$skip   = array( 'secret', 'form_name', 'form_id', 'formname', 'formid' );
		foreach ( $data as $name => $value ) {
			$name = (string) $name;
			$key  = strtolower( $name );
			if ( in_array( $key, self::HIDDEN, true ) ) {
				$hidden[ $key ] = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
				continue;
			}
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			}
			$fields[] = array(
				'name'  => $name,
				'label' => AARYE_Entries::humanize( $name ),
				'type'  => '',
				'value' => (string) $value,
			);
		}
		$params = array();
		foreach ( self::HIDDEN as $k ) {
			if ( 0 !== strpos( $k, 'aarye_' ) && ! empty( $hidden[ $k ] ) ) {
				$params[ $k ] = $hidden[ $k ];
			}
		}
		$landing = isset( $hidden['aarye_landing'] ) ? $hidden['aarye_landing'] : '';
		$path    = '' !== $landing ? (string) wp_parse_url( $landing, PHP_URL_PATH ) . ( wp_parse_url( $landing, PHP_URL_QUERY ) ? '?' . wp_parse_url( $landing, PHP_URL_QUERY ) : '' ) : '';
		$touch   = array(
			'time'   => time(),
			'url'    => '' !== $path ? home_url( $path ) : '',
			'ref'    => isset( $hidden['aarye_referrer'] ) ? esc_url_raw( $hidden['aarye_referrer'] ) : '',
			'params' => $params,
		);
		$page    = isset( $hidden['aarye_page'] ) ? esc_url_raw( $hidden['aarye_page'] ) : '';
		$name    = isset( $data['form_name'] ) ? sanitize_text_field( (string) $data['form_name'] ) : ( isset( $data['formname'] ) ? sanitize_text_field( (string) $data['formname'] ) : AARYEPRO_Connections::get( self::KEY, 'form_name' ) );
		$id      = AARYE_Entries::capture(
			array(
				'plugin'     => self::KEY,
				'form_id'    => isset( $data['form_id'] ) ? sanitize_text_field( (string) $data['form_id'] ) : sanitize_title( '' !== $name ? $name : 'zoho' ),
				'form_name'  => '' !== $name ? $name : 'Zoho Forms',
				'fields'     => $fields,
				'visit'      => array(
					'first'   => $params || '' !== $touch['ref'] || '' !== $touch['url'] ? $touch : null,
					'last'    => null,
					'visits'  => 1,
					'page'    => $page,
					'visitor' => isset( $hidden['aarye_vid'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( $hidden['aarye_vid'] ) ) : '',
				),
				'user_agent' => 'Zoho Forms webhook',
			)
		);
		return new WP_REST_Response( array( 'ok' => (bool) $id ), $id ? 200 : 500 );
	}

	/**
	 * Script that fills the hidden fields of embedded Zoho Forms with the visitor's source.
	 */
	public static function script() {
		wp_register_script( 'aaryepro-zoho-forms', '', array(), AARYEPRO_VERSION, true );
		wp_enqueue_script( 'aaryepro-zoho-forms' );
		$js = '(function(){var K=' . wp_json_encode( self::HIDDEN ) . ';' .
			'function attr(){var m=document.cookie.match(/(?:^|; )aarye_attr=([^;]*)/);if(!m)return null;try{return JSON.parse(decodeURIComponent(m[1]));}catch(e){return null;}}' .
			'function vid(){var m=document.cookie.match(/(?:^|; )aarye_vid=([^;]*)/);return m?m[1]:"";}' .
			'function fill(f){if(f.getAttribute("data-aarye"))return;var s=f.getAttribute("src")||"";if(!/forms\.zoho(public)?\./.test(s))return;var a=attr()||{};var t=a.l||a.f||{};var p=t.p||{};var q=[];' .
			'K.forEach(function(k){var v="";if(k==="aarye_landing")v=t.u||"";else if(k==="aarye_referrer")v=t.r||"";else if(k==="aarye_page")v=location.pathname;else if(k==="aarye_vid")v=vid();else v=p[k]||"";if(v)q.push(k+"="+encodeURIComponent(v));});' .
			'f.setAttribute("data-aarye","1");if(q.length)f.setAttribute("src",s+(s.indexOf("?")>-1?"&":"?")+q.join("&"));}' .
			'function scan(){Array.prototype.forEach.call(document.querySelectorAll("iframe"),fill);}' .
			'scan();if(window.MutationObserver){var o=new MutationObserver(scan);o.observe(document.documentElement,{childList:true,subtree:true});setTimeout(function(){o.disconnect();},15000);}})();';
		wp_add_inline_script( 'aaryepro-zoho-forms', $js );
	}

	/**
	 * Settings screen (inside Connections).
	 */
	public static function page() {
		$s = AARYEPRO_Connections::settings( self::KEY );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Message after redirect.
		$msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
		?>
		<div class="wrap aarye">
			<p><a href="<?php echo esc_url( AARYEPRO_Connections::url() ); ?>">← <?php esc_html_e( 'All connections', 'aarye-marketing-addon-for-forms-pro' ); ?></a></p>
			<h1>Zoho Forms</h1>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<div class="aarye-grid aarye-grid--entry">
				<div class="aarye-card">
					<h2><?php esc_html_e( 'Settings', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="aaryepro_zoho_forms" />
						<?php wp_nonce_field( 'aaryepro_zoho_forms' ); ?>
						<p><label><input type="checkbox" name="enabled" value="yes" <?php checked( isset( $s['enabled'] ) ? $s['enabled'] : '', 'yes' ); ?> /> <?php esc_html_e( 'Receive Zoho Forms entries and fill their hidden source fields', 'aarye-marketing-addon-for-forms-pro' ); ?></label></p>
						<p><label><?php esc_html_e( 'Form name when Zoho does not send one', 'aarye-marketing-addon-for-forms-pro' ); ?><br /><input type="text" class="regular-text" name="form_name" value="<?php echo esc_attr( isset( $s['form_name'] ) ? $s['form_name'] : 'Zoho Forms' ); ?>" /></label></p>
						<p><button class="button button-primary" type="submit"><?php esc_html_e( 'Save', 'aarye-marketing-addon-for-forms-pro' ); ?></button></p>
					</form>
					<?php if ( self::on() ) : ?>
						<h3><?php esc_html_e( 'Webhook address', 'aarye-marketing-addon-for-forms-pro' ); ?></h3>
						<div class="aarye-copy"><code><?php echo esc_html( self::webhook_url() ); ?></code> <button type="button" class="button aarye-copy__btn" data-copy="<?php echo esc_attr( self::webhook_url() ); ?>"><?php esc_html_e( 'Copy', 'aarye-marketing-addon-for-forms-pro' ); ?></button></div>
						<p class="aarye-muted"><?php esc_html_e( 'Keep it private: anyone with this address can add entries.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="aarye-card">
					<h2><?php esc_html_e( 'How to connect', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
					<ol class="aarye-steps">
						<li><?php esc_html_e( 'Tick the box and save to get your webhook address.', 'aarye-marketing-addon-for-forms-pro' ); ?></li>
						<li><?php esc_html_e( 'In Zoho Forms, edit your form and add Hidden fields. Set each one\'s Field Alias (link name) to one of these:', 'aarye-marketing-addon-for-forms-pro' ); ?> <code><?php echo esc_html( implode( ', ', self::HIDDEN ) ); ?></code>. <?php esc_html_e( 'At least utm_source, utm_medium, utm_campaign, utm_content, gclid, fbclid and aarye_landing.', 'aarye-marketing-addon-for-forms-pro' ); ?></li>
						<li><?php esc_html_e( 'Embed the form on your page with the iframe code (Share → Embed → iFrame). Aarye fills the hidden fields with the visitor\'s source automatically.', 'aarye-marketing-addon-for-forms-pro' ); ?></li>
						<li><?php esc_html_e( 'In Zoho Forms open Integrations → Webhooks, paste the webhook address, choose JSON, and add every field to the payload with its alias as the parameter name. Add form_name with the form\'s name if you have several forms.', 'aarye-marketing-addon-for-forms-pro' ); ?></li>
						<li><?php esc_html_e( 'Send a test entry from the live page: it appears in Aarye Leads → All entries with its source.', 'aarye-marketing-addon-for-forms-pro' ); ?></li>
					</ol>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save the settings.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'aaryepro_zoho_forms' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		$s                = AARYEPRO_Connections::settings( self::KEY );
		$s['enabled']     = isset( $_POST['enabled'] ) && 'yes' === $_POST['enabled'] ? 'yes' : '';
		$s['form_name']   = isset( $_POST['form_name'] ) ? sanitize_text_field( wp_unslash( $_POST['form_name'] ) ) : 'Zoho Forms';
		$all              = (array) get_option( AARYEPRO_Connections::OPTION, array() );
		$all[ self::KEY ] = $s;
		update_option( AARYEPRO_Connections::OPTION, $all, false );
		self::secret();
		wp_safe_redirect( AARYEPRO_Connections::url( array( 'dest' => self::KEY, 'msg' => rawurlencode( __( 'Settings saved.', 'aarye-marketing-addon-for-forms-pro' ) ) ) ) );
		exit;
	}
}
