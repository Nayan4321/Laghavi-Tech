<?php
/**
 * Other forms: theme forms (for example Houzez property enquiries), page-builder and hand-made forms that
 * no supported form plugin handles. The browser reports them when they are sent (see other-forms.js).
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Other forms.
 */
class AARYE_Other_Forms {

	const KEY = 'custom';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'aarye_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_filter( 'aarye_form_plugins', array( __CLASS__, 'add_source' ) );
		add_filter( 'aarye_form_plugin_active', array( __CLASS__, 'active' ), 10, 2 );
		add_action( 'aarye_settings_rows', array( __CLASS__, 'settings_rows' ), 5 );
		add_filter( 'aarye_save_settings', array( __CLASS__, 'save_settings' ) );
		if ( AARYE_Settings::on( 'other_forms' ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 5 );
			add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		}
	}

	/**
	 * Defaults.
	 *
	 * @param array $d Defaults.
	 * @return array
	 */
	public static function defaults( $d ) {
		$d['other_forms']         = 'yes';
		$d['other_forms_exclude'] = '';
		return $d;
	}

	/**
	 * "Other forms" on the Forms screen and in filters.
	 *
	 * @param array $plugins Sources.
	 * @return array
	 */
	public static function add_source( $plugins ) {
		$plugins[ self::KEY ] = array( 'name' => __( 'Other forms (theme and custom forms)', 'aarye-marketing-addon-for-forms' ) );
		return $plugins;
	}

	/**
	 * Active when switched on.
	 *
	 * @param bool   $active Active.
	 * @param string $key    Source.
	 * @return bool
	 */
	public static function active( $active, $key ) {
		return self::KEY === $key ? AARYE_Settings::on( 'other_forms' ) : $active;
	}

	/**
	 * Forms that are never captured here: the supported form plugins' own forms (they are caught on the
	 * server), logins, searches, carts, checkouts and comments.
	 *
	 * @return string CSS selector.
	 */
	public static function skip_selector() {
		$known = array(
			'.wpcf7-form', '.wpforms-form', '.elementor-form', '.frm-fluent-form', 'form[id^="gform_"]', '.forminator-custom-form',
			'.nf-form-cont form', '.frm-show-form', '.jet-form-builder', '.metform-form-content', '.mf-form-wrapper form',
			'#commentform', '.comment-form', 'form[role="search"]', '.search-form', '.searchform', '.wp-block-search', 'form[action*="?s="]',
			'#loginform', '#registerform', '#lostpasswordform', '.woocommerce-form-login', '.woocommerce-form-register', '.woocommerce-cart-form',
			'form.cart', 'form.checkout', '.woocommerce-checkout', 'form.woocommerce-ordering', '#adminbarsearch',
		);
		/**
		 * Forms the "Other forms" capture ignores.
		 *
		 * @param string[] $known CSS selectors.
		 */
		return implode( ', ', (array) apply_filters( 'aarye_other_forms_skip', $known ) );
	}

	/**
	 * Load the script.
	 */
	public static function enqueue() {
		wp_enqueue_script( 'aarye-other-forms', AARYE_URL . 'assets/js/other-forms.js', array(), aarye_asset_version( 'assets/js/other-forms.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		$exclude = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) AARYE_Settings::get( 'other_forms_exclude' ) ) ) );
		wp_add_inline_script(
			'aarye-other-forms',
			'window.aaryeOther=' . wp_json_encode(
				array(
					'url'     => esc_url_raw( rest_url( 'aarye/v1/form' ) ),
					'skip'    => self::skip_selector(),
					'exclude' => implode( ', ', $exclude ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * REST route.
	 */
	public static function routes() {
		register_rest_route(
			'aarye/v1',
			'/form',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => '__return_true', // Visitors sending a form on this site; limited below.
			)
		);
	}

	/**
	 * A form sent on the site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function receive( $request ) {
		// At most 20 forms a minute from one address.
		$limit = 'aarye_of_' . md5( AARYE_Entries::ip() . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $limit );
		if ( $count >= 20 ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		set_transient( $limit, $count + 1, 2 * MINUTE_IN_SECONDS );

		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) || empty( $body['fields'] ) || ! is_array( $body['fields'] ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$fields = array();
		foreach ( array_slice( $body['fields'], 0, 60 ) as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['n'], $f['v'] ) || ! is_scalar( $f['v'] ) ) {
				continue;
			}
			if ( isset( $f['t'] ) && 'hidden' === $f['t'] && preg_match( '/^[a-f0-9]{10}$/i', (string) $f['v'] ) ) {
				continue; // A security token, not an answer.
			}
			$fields[] = array(
				'name'  => mb_substr( sanitize_text_field( (string) $f['n'] ), 0, 100 ),
				'label' => isset( $f['l'] ) ? mb_substr( sanitize_text_field( (string) $f['l'] ), 0, 100 ) : '',
				'type'  => isset( $f['t'] ) ? sanitize_key( (string) $f['t'] ) : '',
				'value' => mb_substr( (string) $f['v'], 0, 5000 ),
			);
		}
		$contact = AARYE_Entries::contact( $fields );
		if ( '' === $contact['email'] && '' === $contact['phone'] ) {
			return new WP_REST_Response( array( 'ok' => false ), 422 ); // Only forms that reach someone.
		}
		$form_id = isset( $body['f'] ) ? preg_replace( '/[^\w-]/', '', (string) $body['f'] ) : '';
		$form_id = '' !== $form_id ? substr( $form_id, 0, 60 ) : 'form';
		$name    = isset( $body['n'] ) ? mb_substr( sanitize_text_field( (string) $body['n'] ), 0, 120 ) : '';
		$page    = isset( $body['p'] ) ? esc_url_raw( (string) $body['p'] ) : '';
		$page    = AARYE_Sources::is_own( $page ) ? $page : '';

		// Sent again after a correction: update that entry instead of adding another one.
		$visit = AARYE_Tracker::read();
		$prev  = self::recent( $form_id, $visit['visitor'] );
		if ( $prev ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
			$wpdb->update(
				AARYE_Entries::table(),
				array(
					'fields' => wp_json_encode( $fields ),
					'name'   => mb_substr( $contact['name'], 0, 190 ),
					'email'  => mb_substr( $contact['email'], 0, 190 ),
					'phone'  => mb_substr( $contact['phone'], 0, 60 ),
				),
				array( 'id' => $prev )
			);
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$id = AARYE_Entries::capture(
			array(
				'plugin'    => self::KEY,
				'form_id'   => $form_id,
				'form_name' => '' !== $name ? $name : AARYE_Entries::humanize( $form_id ),
				'fields'    => $fields,
				'page'      => $page,
			)
		);
		return new WP_REST_Response( array( 'ok' => (bool) $id ), $id ? 200 : 202 );
	}

	/**
	 * This visitor's entry from the same form in the last 10 minutes.
	 *
	 * @param string $form_id Form.
	 * @param string $visitor Visitor ID.
	 * @return int Entry ID or 0.
	 */
	private static function recent( $form_id, $visitor ) {
		if ( '' === $visitor ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE form_plugin = %s AND form_id = %s AND visitor_id = %s AND created_at > %s ORDER BY id DESC LIMIT 1', AARYE_Entries::table(), self::KEY, $form_id, $visitor, gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ) );
	}

	/**
	 * Settings rows.
	 *
	 * @param array $s Settings.
	 */
	public static function settings_rows( $s ) {
		?>
		<tr>
			<th><?php esc_html_e( 'Other forms', 'aarye-marketing-addon-for-forms' ); ?></th>
			<td>
				<label><input type="checkbox" name="other_forms" value="yes" <?php checked( isset( $s['other_forms'] ) ? $s['other_forms'] : 'yes', 'yes' ); ?> /> <?php esc_html_e( 'Also save forms made by your theme or by hand (for example Houzez enquiry forms) when they have an email or phone field', 'aarye-marketing-addon-for-forms' ); ?></label>
				<p class="description"><?php esc_html_e( 'Saved when the visitor clicks send. Logins, searches, carts, checkouts, comments and payment forms are never saved. If the form rejects the message and the visitor corrects it, the same entry is updated.', 'aarye-marketing-addon-for-forms' ); ?></p>
				<p><label><?php esc_html_e( 'Ignore these forms (CSS selectors, one per line)', 'aarye-marketing-addon-for-forms' ); ?><br /><textarea class="large-text code" rows="2" name="other_forms_exclude" placeholder="#newsletter-form&#10;.mortgage-calculator"><?php echo esc_textarea( isset( $s['other_forms_exclude'] ) ? $s['other_forms_exclude'] : '' ); ?></textarea></label></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the rows.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save_settings( $values ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked before this filter runs.
		$values['other_forms']         = ! empty( $_POST['other_forms'] ) ? 'yes' : 'no';
		$values['other_forms_exclude'] = isset( $_POST['other_forms_exclude'] ) ? sanitize_textarea_field( wp_unslash( $_POST['other_forms_exclude'] ) ) : '';
		// phpcs:enable
		return $values;
	}
}
