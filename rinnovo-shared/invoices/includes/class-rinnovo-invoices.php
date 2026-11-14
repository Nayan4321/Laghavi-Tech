<?php
/**
 * Invoices: an invoice for every paid order (renewals included), three templates, the seller's and the
 * customer's legal details (company name, address, TRN), a My Account "Invoices" tab and links in emails.
 *
 * @package RinnovoInvoices
 */

defined( 'ABSPATH' ) || exit;

/**
 * Invoices for WooCommerce orders.
 */
class RINNOVO_Invoices {

	const OPTION   = 'rinnovopro_invoices';
	const COUNTER  = 'rinnovopro_invoice_next';
	const META     = '_rinnovopro_invoice';
	const ENDPOINT = 'invoices';
	const PAGE     = 'rinnovopro-invoices';
	const TD       = 'rinnovo-invoices';
	const USERMETA = 'rinnovopro_legal_';

	/**
	 * Unsaved settings used while showing a preview.
	 *
	 * @var array|null
	 */
	private static $preview = null;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 70 );
		add_action( 'admin_post_' . self::PAGE, array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_' . self::PAGE . '_preview', array( __CLASS__, 'preview' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		RINNOVO_Invoice_Docs::init();

		if ( ! self::get( 'enabled' ) ) {
			return;
		}

		// Create the invoice when an order is paid.
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'maybe_create' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_create' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_create' ) );

		// My Account.
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ), 30 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'account_list' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'account_title' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_show_customer_invoice' ), 5 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'order_action' ), 10, 2 );

		// The customer's legal details.
		if ( 'off' !== self::get( 'customer_mode' ) ) {
			add_action( 'woocommerce_edit_account_form', array( __CLASS__, 'account_fields' ) );
			add_action( 'woocommerce_save_account_details_errors', array( __CLASS__, 'validate_account_fields' ), 10, 2 );
			add_action( 'woocommerce_save_account_details', array( __CLASS__, 'save_account_fields' ) );
			add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_fields' ) );
			add_filter( 'woocommerce_form_field', array( __CLASS__, 'tax_invoice_hint' ), 10, 3 );
			add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_checkout_fields' ), 10, 2 );
			add_action( 'woocommerce_init', array( __CLASS__, 'register_block_fields' ) );
			add_action( 'show_user_profile', array( __CLASS__, 'profile_fields' ) );
			add_action( 'edit_user_profile', array( __CLASS__, 'profile_fields' ) );
			add_action( 'personal_options_update', array( __CLASS__, 'save_profile_fields' ) );
			add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_fields' ) );
		}

		// Emails and admin.
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_link' ), 20, 4 );
		add_filter( 'woocommerce_email_attachments', array( __CLASS__, 'email_attachment' ), 10, 4 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_post_' . self::PAGE . '_view', array( __CLASS__, 'admin_view' ) );
		add_action( 'admin_post_' . self::PAGE . '_action', array( __CLASS__, 'admin_action' ) );
	}

	/*
	 * Settings.
	 */

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => 1,
			'template'       => 'classic',
			'color'          => '',
			'logo'           => '',
			'legal_name'     => '',
			'legal_address'  => '',
			'trn'            => '',
			'tax_label'      => 'TRN',
			'email'          => '',
			'phone'          => '',
			'website'        => '',
			'prefix'         => 'INV-',
			'digits'         => 5,
			// Translated only once WordPress has loaded translations (settings are read earlier, while hooking up).
			'footer'         => did_action( 'init' ) ? __( 'Thank you for your business!', self::TD ) : 'Thank you for your business!', // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain -- Same domain as the rest of the plugin.
			'customer_mode'  => 'optional',
			'free_orders'    => 0,
			'email_link'     => 1,
			'email_pdf'      => 1,
			'fy_reset'       => 0,
			'fy_start'       => 1,
			'credit_notes'   => 1,
			'cn_prefix'      => 'CN-',
			'tax_mode'       => 'standard',
			'seller_state'   => '',
			'default_hsn'    => '',
		);
	}

	/**
	 * Read a setting.
	 *
	 * @param string $key Setting.
	 * @return mixed
	 */
	public static function get( $key ) {
		// WordPress caches the option itself; reading it each time keeps changes made in this request.
		$saved    = null !== self::$preview ? self::$preview : get_option( self::OPTION, array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * The three templates.
	 *
	 * @return array key => array( name, default colour ).
	 */
	public static function templates() {
		return array(
			'classic' => array( __( 'Classic (black and white)', 'rinnovo-invoices' ), '#111111' ),
			'bold'    => array( __( 'Bold (accent lines and "Paid" stamp)', 'rinnovo-invoices' ), '#e3342f' ),
			'modern'  => array( __( 'Modern (coloured table and balance box)', 'rinnovo-invoices' ), '#1a6cf0' ),
		);
	}

	/**
	 * "Invoices" under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Invoices', 'rinnovo-invoices' ), __( 'Invoices', 'rinnovo-invoices' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'settings_page' ) );
	}

	/**
	 * Media picker for the logo.
	 *
	 * @param string $hook Screen.
	 */
	public static function admin_assets( $hook ) {
		if ( false !== strpos( $hook, self::PAGE ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Clean submitted settings.
	 *
	 * @param array $in Submitted values.
	 * @return array
	 */
	private static function clean( $in ) {
		$text  = static function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) ? sanitize_text_field( $in[ $key ] ) : '';
		};
		$area  = static function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) ? sanitize_textarea_field( $in[ $key ] ) : '';
		};
		$color = isset( $in['color'] ) ? sanitize_hex_color( $in['color'] ) : '';
		$clean = array(
			'enabled'       => empty( $in['enabled'] ) ? 0 : 1,
			'template'      => isset( $in['template'] ) && array_key_exists( $in['template'], self::templates() ) ? $in['template'] : 'classic',
			'color'         => $color ? $color : '',
			'logo'          => isset( $in['logo'] ) ? esc_url_raw( $in['logo'] ) : '',
			'legal_name'    => $text( 'legal_name' ),
			'legal_address' => $area( 'legal_address' ),
			'trn'           => $text( 'trn' ),
			'tax_label'     => '' !== $text( 'tax_label' ) ? $text( 'tax_label' ) : 'TRN',
			'email'         => sanitize_email( $text( 'email' ) ),
			'phone'         => $text( 'phone' ),
			'website'       => $text( 'website' ),
			'prefix'        => substr( preg_replace( '/[^A-Za-z0-9\-\/_#{}]/', '', $text( 'prefix' ) ), 0, 20 ),
			'digits'        => isset( $in['digits'] ) ? min( 10, max( 1, absint( $in['digits'] ) ) ) : 5,
			'footer'        => $area( 'footer' ),
			'customer_mode' => isset( $in['customer_mode'] ) && in_array( $in['customer_mode'], array( 'optional', 'required', 'off' ), true ) ? $in['customer_mode'] : 'optional',
			'free_orders'   => empty( $in['free_orders'] ) ? 0 : 1,
			'email_link'    => empty( $in['email_link'] ) ? 0 : 1,
			'email_pdf'     => empty( $in['email_pdf'] ) ? 0 : 1,
			'fy_reset'      => empty( $in['fy_reset'] ) ? 0 : 1,
			'fy_start'      => isset( $in['fy_start'] ) ? min( 12, max( 1, absint( $in['fy_start'] ) ) ) : 1,
			'credit_notes'  => empty( $in['credit_notes'] ) ? 0 : 1,
			'cn_prefix'     => substr( preg_replace( '/[^A-Za-z0-9\-\/_#{}]/', '', $text( 'cn_prefix' ) ), 0, 20 ),
			'tax_mode'      => isset( $in['tax_mode'] ) && 'india_gst' === $in['tax_mode'] ? 'india_gst' : 'standard',
			'seller_state'  => isset( $in['seller_state'] ) && array_key_exists( $in['seller_state'], RINNOVO_Invoice_Docs::states() ) ? $in['seller_state'] : '',
			'default_hsn'   => substr( preg_replace( '/[^0-9A-Za-z]/', '', $text( 'default_hsn' ) ), 0, 12 ),
		);
		return $clean;
	}

	/**
	 * Submitted settings form values.
	 *
	 * @return array
	 */
	private static function posted() {
		return isset( $_POST['inv'] ) && is_array( $_POST['inv'] ) ? wp_unslash( $_POST['inv'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The callers check the nonce; values are cleaned in clean().
	}

	/**
	 * Save the settings.
	 */
	public static function save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'rinnovo-invoices' ) );
		}
		check_admin_referer( self::PAGE );
		$in = self::posted();
		update_option( self::OPTION, self::clean( $in ), false );

		// The next number can be moved up, never down, so numbers are never used twice.
		$next = isset( $in['next'] ) ? absint( $in['next'] ) : 0;
		if ( $next > self::peek_next() ) {
			update_option( RINNOVO_Invoice_Docs::counter( 'inv' ), $next, false );
		}
		flush_rewrite_rules( false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE,
					'saved'    => 1,
					'_invnonce' => wp_create_nonce( self::PAGE . '_view' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The settings screen.
	 */
	public static function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$nonce = isset( $_GET['_invnonce'] ) ? sanitize_key( wp_unslash( $_GET['_invnonce'] ) ) : '';
		$saved = '' !== $nonce && wp_verify_nonce( $nonce, self::PAGE . '_view' ) && ! empty( $_GET['saved'] );
		$s     = array();
		foreach ( array_keys( self::defaults() ) as $key ) {
			$s[ $key ] = self::get( $key );
		}
		$n      = static function ( $key ) {
			return 'inv[' . $key . ']';
		};
		$sample = self::latest_invoice_order();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Invoices', 'rinnovo-invoices' ); ?></h1>
			<p class="description"><?php esc_html_e( 'An invoice is created for every paid order, subscription renewals included. Customers find them in My Account > Invoices and can save them as PDF.', 'rinnovo-invoices' ); ?></p>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'rinnovo-invoices' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<?php // Pressing Enter uses the first button of a form: keep that one "Save", not a Preview button. ?>
				<button type="submit" name="action" value="<?php echo esc_attr( self::PAGE ); ?>" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"></button>
				<?php wp_nonce_field( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Invoices', 'rinnovo-invoices' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $n( 'enabled' ) ); ?>" value="1" <?php checked( 1, (int) $s['enabled'] ); ?> /> <?php esc_html_e( 'Create an invoice for every paid order', 'rinnovo-invoices' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $n( 'free_orders' ) ); ?>" value="1" <?php checked( 1, (int) $s['free_orders'] ); ?> /> <?php esc_html_e( 'Also for free orders (total 0)', 'rinnovo-invoices' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $n( 'email_link' ) ); ?>" value="1" <?php checked( 1, (int) $s['email_link'] ); ?> /> <?php esc_html_e( 'Add a link to the invoice in the customer\'s order emails', 'rinnovo-invoices' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $n( 'email_pdf' ) ); ?>" value="1" <?php checked( 1, (int) $s['email_pdf'] ); ?> /> <?php esc_html_e( 'Attach the invoice PDF to the customer\'s order emails', 'rinnovo-invoices' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Template', 'rinnovo-invoices' ); ?></th>
						<td>
							<?php foreach ( self::templates() as $key => $tpl ) : ?>
								<p style="margin:0 0 8px">
									<label><input type="radio" name="<?php echo esc_attr( $n( 'template' ) ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $s['template'], $key ); ?> /> <?php echo esc_html( $tpl[0] ); ?></label>
									<button type="submit" class="button button-small" name="action" value="<?php echo esc_attr( self::PAGE . '_preview' ); ?>" formaction="<?php echo esc_url( admin_url( 'admin-post.php?preview_template=' . $key ) ); ?>" formtarget="_blank" style="margin-left:8px"><?php esc_html_e( 'Preview', 'rinnovo-invoices' ); ?></button>
								</p>
							<?php endforeach; ?>
							<p><label><?php esc_html_e( 'Colour', 'rinnovo-invoices' ); ?> <input type="text" name="<?php echo esc_attr( $n( 'color' ) ); ?>" value="<?php echo esc_attr( $s['color'] ); ?>" placeholder="#111111" class="small-text" style="width:90px" /></label> <span class="description"><?php esc_html_e( 'Leave empty for the template\'s own colour.', 'rinnovo-invoices' ); ?></span></p>
							<p class="description"><?php esc_html_e( 'Preview opens a sample invoice (PDF) with what is filled in on this page, also before you save.', 'rinnovo-invoices' ); ?></p>
							<?php if ( $sample ) : ?>
								<p><a href="<?php echo esc_url( self::admin_url( $sample, true ) ); ?>"><?php esc_html_e( 'Download your latest real invoice (saved settings)', 'rinnovo-invoices' ); ?></a></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="inv-logo"><?php esc_html_e( 'Logo', 'rinnovo-invoices' ); ?></label></th>
						<td>
							<input type="url" id="inv-logo" name="<?php echo esc_attr( $n( 'logo' ) ); ?>" value="<?php echo esc_attr( $s['logo'] ); ?>" class="regular-text" placeholder="https://" />
							<button type="button" class="button" id="inv-logo-pick"><?php esc_html_e( 'Choose image', 'rinnovo-invoices' ); ?></button>
							<p class="description"><?php esc_html_e( 'Empty: the site logo from the Customizer is used, if there is one.', 'rinnovo-invoices' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Your company on the invoice', 'rinnovo-invoices' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="inv-legal-name"><?php esc_html_e( 'Legal company name', 'rinnovo-invoices' ); ?></label></th><td><input type="text" id="inv-legal-name" name="<?php echo esc_attr( $n( 'legal_name' ) ); ?>" value="<?php echo esc_attr( $s['legal_name'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></td></tr>
					<tr><th scope="row"><label for="inv-legal-address"><?php esc_html_e( 'Legal address', 'rinnovo-invoices' ); ?></label></th><td><textarea id="inv-legal-address" name="<?php echo esc_attr( $n( 'legal_address' ) ); ?>" rows="3" class="regular-text"><?php echo esc_textarea( $s['legal_address'] ); ?></textarea><p class="description"><?php esc_html_e( 'Empty: the store address from WooCommerce > Settings is used.', 'rinnovo-invoices' ); ?></p></td></tr>
					<tr>
						<th scope="row"><label for="inv-trn"><?php esc_html_e( 'Tax registration number', 'rinnovo-invoices' ); ?></label></th>
						<td>
							<input type="text" id="inv-trn" name="<?php echo esc_attr( $n( 'trn' ) ); ?>" value="<?php echo esc_attr( $s['trn'] ); ?>" class="regular-text" />
							<label><?php esc_html_e( 'shown as', 'rinnovo-invoices' ); ?> <input type="text" name="<?php echo esc_attr( $n( 'tax_label' ) ); ?>" value="<?php echo esc_attr( $s['tax_label'] ); ?>" style="width:90px" /></label>
							<p class="description"><?php esc_html_e( 'With a number here the title reads "Tax Invoice"; without, "Invoice". Use the label your country uses: TRN, VAT No., GSTIN…', 'rinnovo-invoices' ); ?></p>
						</td>
					</tr>
					<tr><th scope="row"><label for="inv-email"><?php esc_html_e( 'Email', 'rinnovo-invoices' ); ?></label></th><td><input type="email" id="inv-email" name="<?php echo esc_attr( $n( 'email' ) ); ?>" value="<?php echo esc_attr( $s['email'] ); ?>" class="regular-text" /></td></tr>
					<tr><th scope="row"><label for="inv-phone"><?php esc_html_e( 'Phone', 'rinnovo-invoices' ); ?></label></th><td><input type="text" id="inv-phone" name="<?php echo esc_attr( $n( 'phone' ) ); ?>" value="<?php echo esc_attr( $s['phone'] ); ?>" class="regular-text" /></td></tr>
					<tr><th scope="row"><label for="inv-website"><?php esc_html_e( 'Website', 'rinnovo-invoices' ); ?></label></th><td><input type="text" id="inv-website" name="<?php echo esc_attr( $n( 'website' ) ); ?>" value="<?php echo esc_attr( $s['website'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>" /></td></tr>
					<tr><th scope="row"><label for="inv-footer"><?php esc_html_e( 'Note at the bottom', 'rinnovo-invoices' ); ?></label></th><td><textarea id="inv-footer" name="<?php echo esc_attr( $n( 'footer' ) ); ?>" rows="2" class="regular-text"><?php echo esc_textarea( $s['footer'] ); ?></textarea></td></tr>
				</table>

				<h2><?php esc_html_e( 'Numbering', 'rinnovo-invoices' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Invoice number', 'rinnovo-invoices' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Prefix', 'rinnovo-invoices' ); ?> <input type="text" name="<?php echo esc_attr( $n( 'prefix' ) ); ?>" value="<?php echo esc_attr( $s['prefix'] ); ?>" style="width:90px" /></label>
							<label><?php esc_html_e( 'Digits', 'rinnovo-invoices' ); ?> <input type="number" min="1" max="10" name="<?php echo esc_attr( $n( 'digits' ) ); ?>" value="<?php echo esc_attr( $s['digits'] ); ?>" class="small-text" /></label>
							<label><?php esc_html_e( 'Next number', 'rinnovo-invoices' ); ?> <input type="number" min="1" name="<?php echo esc_attr( $n( 'next' ) ); ?>" value="<?php echo esc_attr( self::peek_next() ); ?>" class="small-text" style="width:90px" /></label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: example invoice number. */
									esc_html__( 'Next invoice: %s. Numbers follow on without gaps; the next number can be raised (for example to continue from your old system) but not lowered.', 'rinnovo-invoices' ),
									'<code>' . esc_html( self::format_number( self::peek_next() ) ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Financial year', 'rinnovo-invoices' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $n( 'fy_reset' ) ); ?>" value="1" <?php checked( 1, (int) $s['fy_reset'] ); ?> /> <?php esc_html_e( 'Start numbering again at 1 every financial year', 'rinnovo-invoices' ); ?></label><br />
							<label><?php esc_html_e( 'The financial year starts in', 'rinnovo-invoices' ); ?> <select name="<?php echo esc_attr( $n( 'fy_start' ) ); ?>">
								<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
									<option value="<?php echo esc_attr( (string) $m ); ?>" <?php selected( (int) $s['fy_start'], $m ); ?>><?php echo esc_html( date_i18n( 'F', mktime( 0, 0, 0, $m, 1 ) ) ); ?></option>
								<?php endfor; ?>
							</select></label>
							<p class="description"><?php esc_html_e( 'Put {fy} in the prefix for the financial year (INV/{fy}/ becomes INV/26-27/00001) or {year} for the calendar year. India: April; UAE, EU, UK and most others: January.', 'rinnovo-invoices' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Credit notes and corrections', 'rinnovo-invoices' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Credit notes', 'rinnovo-invoices' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $n( 'credit_notes' ) ); ?>" value="1" <?php checked( 1, (int) $s['credit_notes'] ); ?> /> <?php esc_html_e( 'Issue a credit note for every refund (full or partial), linked to its invoice', 'rinnovo-invoices' ); ?></label><br />
							<label><?php esc_html_e( 'Prefix', 'rinnovo-invoices' ); ?> <input type="text" name="<?php echo esc_attr( $n( 'cn_prefix' ) ); ?>" value="<?php echo esc_attr( $s['cn_prefix'] ); ?>" style="width:110px" /></label>
							<?php /* translators: %s: next credit note number. */ ?>
							<p class="description"><?php printf( esc_html__( 'Credit notes have their own number sequence. Next: %s. Issued invoices are never changed: to correct one, use "Cancel invoice" on the order (a credit note is issued) and then "Issue a corrected invoice".', 'rinnovo-invoices' ), '<code>' . esc_html( RINNOVO_Invoice_Docs::format( RINNOVO_Invoice_Docs::peek( 'cn' ), 'cn' ) ) . '</code>' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Tax layout', 'rinnovo-invoices' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Layout', 'rinnovo-invoices' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $n( 'tax_mode' ) ); ?>" value="standard" <?php checked( $s['tax_mode'], 'standard' ); ?> /> <?php esc_html_e( 'Standard: the store\'s tax lines (VAT, sales tax…) as WooCommerce calculates them — UAE, Saudi Arabia, EU, UK and most countries', 'rinnovo-invoices' ); ?></label><br />
							<label><input type="radio" name="<?php echo esc_attr( $n( 'tax_mode' ) ); ?>" value="india_gst" <?php checked( $s['tax_mode'], 'india_gst' ); ?> /> <?php esc_html_e( 'India GST: CGST + SGST within your state, IGST to other states, export outside India; HSN / SAC codes, place of supply and the amount in words', 'rinnovo-invoices' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'India GST', 'rinnovo-invoices' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Your state', 'rinnovo-invoices' ); ?> <select name="<?php echo esc_attr( $n( 'seller_state' ) ); ?>"><option value=""><?php esc_html_e( '—', 'rinnovo-invoices' ); ?></option>
								<?php foreach ( RINNOVO_Invoice_Docs::states() as $code => $row ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['seller_state'], $code ); ?>><?php echo esc_html( $row[1] . ' (' . $row[0] . ')' ); ?></option>
								<?php endforeach; ?>
							</select></label>
							<label style="margin-left:12px"><?php esc_html_e( 'Default HSN / SAC code', 'rinnovo-invoices' ); ?> <input type="text" name="<?php echo esc_attr( $n( 'default_hsn' ) ); ?>" value="<?php echo esc_attr( $s['default_hsn'] ); ?>" style="width:110px" placeholder="998314" /></label>
							<p class="description"><?php esc_html_e( 'Each product can have its own code (product > General > Tax). Set "Tax registration number" above to your GSTIN and its label to GSTIN.', 'rinnovo-invoices' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Your customers\' details', 'rinnovo-invoices' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Business customers can add their legal company name, legal address and tax registration number (TRN) in My Account > Account details and at checkout. They are printed on their invoices.', 'rinnovo-invoices' ); ?></p>
				<?php
				$modes = array(
					'optional' => __( 'Optional: customers may add them if they want a tax invoice (recommended)', 'rinnovo-invoices' ),
					'required' => __( 'Required: every customer must add a legal name and address (TRN stays optional)', 'rinnovo-invoices' ),
					'off'      => __( 'Off: invoices use the billing name, company and address', 'rinnovo-invoices' ),
				);
				foreach ( $modes as $value => $label ) :
					?>
					<p><label><input type="radio" name="<?php echo esc_attr( $n( 'customer_mode' ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s['customer_mode'], $value ); ?> /> <?php echo esc_html( $label ); ?></label></p>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
			<h2><?php esc_html_e( 'Export for accounting', 'rinnovo-invoices' ); ?></h2>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PAGE . '_export' ); ?>" />
				<?php wp_nonce_field( self::PAGE . '_export' ); ?>
				<label><?php esc_html_e( 'From', 'rinnovo-invoices' ); ?> <input type="date" name="from" value="<?php echo esc_attr( wp_date( 'Y-m-01' ) ); ?>" /></label>
				<label><?php esc_html_e( 'to', 'rinnovo-invoices' ); ?> <input type="date" name="to" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" /></label>
				<?php submit_button( __( 'Download CSV', 'rinnovo-invoices' ), 'secondary', '', false ); ?>
				<p class="description"><?php esc_html_e( 'Every invoice and credit note in the period with its customer, tax number, net, tax and total (credit notes negative), for your accountant or accounting software.', 'rinnovo-invoices' ); ?></p>
			</form>
		</div>
		<script>
		( function () {
			var button = document.getElementById( 'inv-logo-pick' );
			if ( ! button ) { return; }
			// The media library loads at the end of the page, so look for it only when the button is clicked.
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( ! window.wp || ! wp.media ) {
					window.alert( '<?php echo esc_js( __( 'The media library is still loading. Please try again in a moment.', self::TD ) ); ?>' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain -- Same domain as the rest of the plugin.
					return;
				}
				var frame = wp.media( { title: '<?php echo esc_js( __( 'Choose a logo', self::TD ) ); ?>', library: { type: 'image' }, multiple: false } ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain -- Same domain as the rest of the plugin.
				frame.on( 'select', function () {
					document.getElementById( 'inv-logo' ).value = frame.state().get( 'selection' ).first().get( 'url' );
				} );
				frame.open();
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Sample invoice as PDF, with the values on the settings form (not saved).
	 */
	public static function preview() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'rinnovo-invoices' ) );
		}
		check_admin_referer( self::PAGE );
		$settings = self::clean( self::posted() );
		$template = isset( $_GET['preview_template'] ) ? sanitize_key( wp_unslash( $_GET['preview_template'] ) ) : $settings['template'];
		if ( array_key_exists( $template, self::templates() ) ) {
			$settings['template'] = $template;
		}
		self::$preview = $settings;

		$tpls  = self::templates();
		$color = '' !== $settings['color'] ? $settings['color'] : $tpls[ $settings['template'] ][1];
		$bytes = RINNOVO_Invoice_PDF::build( self::sample_data(), $settings['template'], $color );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="invoice-preview-' . $settings['template'] . '.pdf"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF.
		exit;
	}

	/**
	 * A sample subscription invoice for the preview.
	 *
	 * @return array View data in the same shape as view_data().
	 */
	private static function sample_data() {
		$order = new WC_Order();
		$order->set_id( 1024 );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_payment_method_title( __( 'Credit card', 'rinnovo-invoices' ) );
		$money  = static function ( $amount ) use ( $order ) {
			return self::money( $amount, $order, true );
		};
		$seller = self::seller();
		$tax    = '' !== $seller['trn'] ? 0.05 : 0;
		$items  = array(
			array( __( 'Business plan – yearly subscription', 'rinnovo-invoices' ), __( 'Plan: 5 sites · License key included', 'rinnovo-invoices' ), 1, 199 ),
			array( __( 'Priority support add-on', 'rinnovo-invoices' ), '', 1, 49 ),
		);
		$lines  = array();
		$sub    = 0;
		foreach ( $items as $it ) {
			$sub    += $it[2] * $it[3];
			$lines[] = array(
				'name'  => $it[0],
				'meta'  => $it[1],
				'qty'   => $it[2],
				'unit'  => $money( $it[3] ),
				'tax'   => $money( $it[2] * $it[3] * $tax ),
				'total' => $money( $it[2] * $it[3] ),
			);
		}
		$totals = array( array( __( 'Subtotal', 'rinnovo-invoices' ), $money( $sub ) ) );
		if ( $tax ) {
			$totals[] = array( __( 'VAT 5%', 'rinnovo-invoices' ), $money( $sub * $tax ) );
		}
		$date              = get_option( 'date_format' );
		$seller['payment'] = implode(
			"\n",
			array(
				/* translators: %s: payment method. */
				sprintf( __( 'Payment method: %s', 'rinnovo-invoices' ), __( 'Credit card', 'rinnovo-invoices' ) ),
				/* translators: %s: date. */
				sprintf( __( 'Paid on: %s', 'rinnovo-invoices' ), date_i18n( $date ) ),
				__( 'Type: new subscription', 'rinnovo-invoices' ),
				/* translators: 1: e.g. "every month", 2: how it renews. */
				sprintf( __( 'Billing: recurring, %1$s – %2$s', 'rinnovo-invoices' ), self::every( 'year', 1 ), __( 'renews automatically', 'rinnovo-invoices' ) ),
				/* translators: %s: date. */
				sprintf( __( 'Next payment: %s', 'rinnovo-invoices' ), date_i18n( $date, strtotime( '+1 year' ) ) ),
			)
		);
		$total = $sub * ( 1 + $tax );
		return array(
			'order'     => $order,
			'invoice'   => array(
				'number' => self::format_number( self::peek_next() ),
				'date'   => time(),
			),
			'seller'    => $seller,
			'buyer'     => array(
				'name'    => __( 'Sample Customer LLC', 'rinnovo-invoices' ),
				'contact' => __( 'Alex Morgan', 'rinnovo-invoices' ),
				'address' => __( "Office 1204, Bay Square 5\nBusiness Bay, Dubai, UAE", 'rinnovo-invoices' ),
				'trn'     => $tax ? '100000000000003' : '',
				'email'   => 'billing@example.com',
				'phone'   => '+971 50 123 4567',
			),
			'title'     => '' !== $seller['trn'] ? __( 'Tax Invoice', 'rinnovo-invoices' ) : __( 'Invoice', 'rinnovo-invoices' ),
			'date'      => date_i18n( $date ),
			'lines'     => $lines,
			'totals'    => $totals,
			'total'     => $money( $total ),
			'paid'      => true,
			/* translators: 1: date, 2: payment method. */
			'paid_text' => sprintf( __( 'Paid on %1$s by %2$s', 'rinnovo-invoices' ), date_i18n( $date ), __( 'Credit card', 'rinnovo-invoices' ) ),
			'no_label'    => __( 'Invoice No.', 'rinnovo-invoices' ),
			'stamp'       => __( 'PAID', 'rinnovo-invoices' ),
			'total_label' => __( 'Total paid', 'rinnovo-invoices' ),
			'due'       => $money( 0 ),
			'has_tax'   => (bool) $tax,
			'currency'  => get_woocommerce_currency(),
		);
	}

	/*
	 * Numbering and creation.
	 */

	/**
	 * The number the next invoice will get.
	 *
	 * @return int
	 */
	public static function peek_next() {
		return RINNOVO_Invoice_Docs::peek( 'inv' );
	}

	/**
	 * Take the next number, safely even when two orders are paid at the same moment.
	 *
	 * @return int
	 */
	private static function take_number() {
		return RINNOVO_Invoice_Docs::take( 'inv' );
	}

	/**
	 * Formatted invoice number ({fy} and {year} in the prefix are filled in).
	 *
	 * @param int $number Number.
	 * @return string
	 */
	public static function format_number( $number ) {
		return RINNOVO_Invoice_Docs::format( $number, 'inv' );
	}

	/**
	 * Invoice data saved on an order, or null.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null
	 */
	public static function invoice( $order ) {
		$data = $order ? $order->get_meta( self::META ) : null;
		return is_array( $data ) && ! empty( $data['number'] ) ? $data : null;
	}

	/**
	 * Create the invoice for a paid order, once.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function maybe_create( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order instanceof WC_Order_Refund || self::invoice( $order ) ) {
			return;
		}
		if ( ! self::get( 'free_orders' ) && 0.0 >= (float) $order->get_total() ) {
			return;
		}
		/**
		 * Skip the invoice for an order (an extension issues its own document).
		 *
		 * @param bool     $skip  Skip.
		 * @param WC_Order $order Order.
		 */
		if ( apply_filters( 'rinnovo_invoice_skip', false, $order ) ) {
			return;
		}
		self::create( $order );
	}

	/**
	 * Create an invoice. An issued invoice never changes: corrections are a credit note and a new invoice.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $replaces Number of the cancelled invoice this one replaces.
	 * @return array Invoice data.
	 */
	public static function create( $order, $replaces = '' ) {
		$existing = self::invoice( $order );
		if ( $existing ) {
			return $existing;
		}
		$data = array(
			'number'  => self::format_number( self::take_number() ),
			'date'    => time(),
			'seller'  => self::seller(),
			'buyer'   => self::buyer( $order ),
			'raw'     => RINNOVO_Invoice_Docs::snapshot( $order ),
			'status'  => 'issued',
			'history' => array( array( time(), 'issued', '', get_current_user_id() ) ),
		);
		if ( '' !== $replaces ) {
			$data['replaces'] = $replaces;
		}
		$order->update_meta_data( self::META, $data );
		$order->save_meta_data();
		/* translators: %s: invoice number. */
		$order->add_order_note( sprintf( __( 'Invoice %s created.', 'rinnovo-invoices' ), $data['number'] ) . ( '' !== $replaces ? ' ' . sprintf( /* translators: %s: invoice number. */ __( 'It replaces cancelled invoice %s.', 'rinnovo-invoices' ), $replaces ) : '' ) );
		/**
		 * An invoice was issued.
		 *
		 * @param array    $data  Invoice.
		 * @param WC_Order $order Order.
		 */
		do_action( 'rinnovo_invoice_issued', $data, $order );
		return $data;
	}

	/**
	 * Note printed after the payment line (reverse charge and similar), from extensions.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function note( $order ) {
		return (string) apply_filters( 'rinnovo_invoice_note', '', $order );
	}

	/**
	 * The seller's details, as they are today.
	 *
	 * @return array
	 */
	private static function seller() {
		$address = (string) self::get( 'legal_address' );
		if ( '' === trim( $address ) && function_exists( 'WC' ) && WC()->countries ) {
			$address = implode(
				"\n",
				array_filter(
					array(
						WC()->countries->get_base_address(),
						WC()->countries->get_base_address_2(),
						trim( WC()->countries->get_base_city() . ' ' . WC()->countries->get_base_postcode() ),
						isset( WC()->countries->countries[ WC()->countries->get_base_country() ] ) ? WC()->countries->countries[ WC()->countries->get_base_country() ] : '',
					)
				)
			);
		}
		$logo = (string) self::get( 'logo' );
		if ( '' === $logo && get_theme_mod( 'custom_logo' ) ) {
			$logo = (string) wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' );
		}
		return array(
			'name'      => '' !== trim( (string) self::get( 'legal_name' ) ) ? self::get( 'legal_name' ) : get_bloginfo( 'name' ),
			'address'   => $address,
			'trn'       => (string) self::get( 'trn' ),
			'tax_label' => (string) self::get( 'tax_label' ),
			'email'     => '' !== (string) self::get( 'email' ) ? self::get( 'email' ) : get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ),
			'phone'     => (string) self::get( 'phone' ),
			'website'   => '' !== (string) self::get( 'website' ) ? self::get( 'website' ) : wp_parse_url( home_url(), PHP_URL_HOST ),
			'logo'      => $logo,
			'payment'   => '',
			'footer'    => (string) self::get( 'footer' ),
		);
	}

	/**
	 * The customer's details: legal details from checkout or their account, else billing details.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function buyer( $order ) {
		$user_id = $order->get_customer_id();
		$legal   = array();
		if ( 'off' !== self::get( 'customer_mode' ) ) {
			foreach ( array( 'name', 'address', 'trn' ) as $key ) {
				$value = (string) $order->get_meta( '_' . self::USERMETA . $key );
				if ( '' === $value ) {
					// Block checkout stores its extra fields under this key.
					$value = (string) $order->get_meta( '_wc_other/rinnovopro/legal-' . $key );
				}
				if ( '' === $value && $user_id ) {
					$value = (string) get_user_meta( $user_id, self::USERMETA . $key, true );
				}
				$legal[ $key ] = trim( $value );
			}
		}

		$billing = array_filter(
			array(
				$order->get_billing_address_1(),
				$order->get_billing_address_2(),
				trim( $order->get_billing_city() . ' ' . $order->get_billing_postcode() ),
				$order->get_billing_state(),
				$order->get_billing_country() && isset( WC()->countries->countries[ $order->get_billing_country() ] ) ? WC()->countries->countries[ $order->get_billing_country() ] : $order->get_billing_country(),
			)
		);
		$person  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$company = '' !== ( isset( $legal['name'] ) ? $legal['name'] : '' ) ? $legal['name'] : $order->get_billing_company();

		$buyer = array(
			'name'    => '' !== $company ? $company : $person,
			'contact' => '' !== $company ? $person : '',
			'address' => ! empty( $legal['address'] ) ? $legal['address'] : implode( "\n", $billing ),
			'trn'     => isset( $legal['trn'] ) ? $legal['trn'] : '',
			'email'   => $order->get_billing_email(),
			'phone'   => $order->get_billing_phone(),
			'company' => $company,
			'country' => $order->get_billing_country(),
			'state'   => $order->get_billing_state(),
		);
		/**
		 * The customer on an invoice (extensions add a PO number, GSTIN and so on).
		 *
		 * @param array    $buyer Buyer.
		 * @param WC_Order $order Order.
		 */
		return (array) apply_filters( 'rinnovo_invoice_buyer', $buyer, $order );
	}

	/*
	 * Showing an invoice.
	 */

	/**
	 * Money in the order's currency, as plain text.
	 *
	 * @param float    $amount Amount.
	 * @param WC_Order $order  Order.
	 * @param bool     $pdf    For the PDF: currency symbols the PDF fonts can't show become the code (AED).
	 * @return string
	 */
	public static function money_text( $amount, $order, $pdf = false ) {
		return self::money( $amount, $order, $pdf );
	}

	/**
	 * Money (see money_text()).
	 *
	 * @param float    $amount Amount.
	 * @param WC_Order $order  Order.
	 * @param bool     $pdf    For the PDF.
	 * @return string
	 */
	private static function money( $amount, $order, $pdf = false ) {
		$args = array( 'currency' => $order->get_currency() );
		$text = html_entity_decode( wp_strip_all_tags( wc_price( $amount, $args ) ), ENT_QUOTES, 'UTF-8' );
		if ( $pdf && ! RINNOVO_Inv_PDF::can_encode( $text ) ) {
			$text   = $order->get_currency() . ' ' . number_format( (float) $amount, wc_get_price_decimals(), wc_get_price_decimal_separator(), wc_get_price_thousand_separator() );
		}
		return $text;
	}

	/**
	 * The subscription an order belongs to (Rinnovo or Laghavi Subscriptions), or null.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null array( subscription row, renewal ).
	 */
	private static function subscription( $order ) {
		foreach ( array( 'RINNOVO_Subscriptions' => '_rinnovo_', 'LSUB_Subscriptions' => '_lsub_' ) as $class => $prefix ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$id  = (int) $order->get_meta( $prefix . 'subscription_id' );
			$sub = $id ? call_user_func( array( $class, 'get' ), $id ) : null;
			if ( ! $sub && is_callable( array( $class, 'for_order' ) ) ) {
				$subs = call_user_func( array( $class, 'for_order' ), $order->get_id() );
				$sub  = $subs ? $subs[0] : null;
			}
			if ( $sub ) {
				return array( $sub, (bool) $order->get_meta( $prefix . 'renewal' ) );
			}
		}
		return null;
	}

	/**
	 * "Every month", "every 3 months", "every year"…
	 *
	 * @param string $period   day, week, month or year.
	 * @param int    $interval Interval.
	 * @return string
	 */
	private static function every( $period, $interval ) {
		$interval = max( 1, (int) $interval );
		$names    = array(
			/* translators: %d: number of days. */
			'day'   => _n( 'every day', 'every %d days', $interval, 'rinnovo-invoices' ),
			/* translators: %d: number of weeks. */
			'week'  => _n( 'every week', 'every %d weeks', $interval, 'rinnovo-invoices' ),
			/* translators: %d: number of months. */
			'month' => _n( 'every month', 'every %d months', $interval, 'rinnovo-invoices' ),
			/* translators: %d: number of years. */
			'year'  => _n( 'every year', 'every %d years', $interval, 'rinnovo-invoices' ),
		);
		return sprintf( isset( $names[ $period ] ) ? $names[ $period ] : $names['month'], $interval );
	}

	/**
	 * Payment details, from the order and its subscription.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $paid  Whether it is paid.
	 * @return string One item per line.
	 */
	private static function payment_text( $order, $paid ) {
		$date  = get_option( 'date_format' );
		$lines = array();
		/* translators: %s: payment method, e.g. Credit card (Stripe). */
		$lines[] = sprintf( __( 'Payment method: %s', 'rinnovo-invoices' ), $order->get_payment_method_title() ? $order->get_payment_method_title() : __( 'Online payment', 'rinnovo-invoices' ) );
		if ( $paid ) {
			$when = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
			/* translators: %s: date. */
			$lines[] = sprintf( __( 'Paid on: %s', 'rinnovo-invoices' ), $when ? date_i18n( $date, $when->getTimestamp() ) : '' );
		} else {
			$lines[] = __( 'Status: not paid yet', 'rinnovo-invoices' );
		}
		if ( '' !== (string) $order->get_transaction_id() ) {
			/* translators: %s: transaction ID from the payment provider. */
			$lines[] = sprintf( __( 'Transaction ID: %s', 'rinnovo-invoices' ), $order->get_transaction_id() );
		}
		$found = self::subscription( $order );
		if ( $found ) {
			list( $sub, $renewal ) = $found;
			$automatic             = '' !== (string) $sub->gateway_subscription_id || '' !== (string) $sub->gateway_customer_id;
			$lines[]               = $renewal ? __( 'Type: subscription renewal', 'rinnovo-invoices' ) : __( 'Type: new subscription', 'rinnovo-invoices' );
			$lines[]               = sprintf(
				/* translators: 1: e.g. "every month", 2: how it renews. */
				__( 'Billing: recurring, %1$s – %2$s', 'rinnovo-invoices' ),
				self::every( (string) $sub->period, (int) $sub->interval_count ),
				$automatic ? __( 'renews automatically', 'rinnovo-invoices' ) : __( 'renewal invoice sent by email', 'rinnovo-invoices' )
			);
			if ( 'active' === $sub->status && ! empty( $sub->next_payment ) ) {
				/* translators: %s: date. */
				$lines[] = sprintf( __( 'Next payment: %s', 'rinnovo-invoices' ), date_i18n( $date, strtotime( $sub->next_payment . ' UTC' ) ) );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Everything a template needs.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $pdf   For the PDF.
	 * @return array
	 */
	public static function view_data( $order, $pdf = false ) {
		$invoice = self::invoice( $order );
		if ( ! $invoice ) {
			$invoice = self::create( $order );
		}
		// Invoices issued before snapshots existed are read from the order.
		$raw       = ! empty( $invoice['raw'] ) ? $invoice['raw'] : RINNOVO_Invoice_Docs::snapshot( $order );
		$view      = RINNOVO_Invoice_Docs::format_snapshot( $raw, $order, $pdf, $invoice['buyer'] );
		$paid      = $order->is_paid() || in_array( $order->get_status(), array( 'processing', 'completed', 'refunded' ), true );
		$cancelled = isset( $invoice['status'] ) && 'cancelled' === $invoice['status'];
		// Payment details always come from the order, never from typed-in text.
		$invoice['seller']['payment'] = self::payment_text( $order, $paid );
		$paid_date = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		$extra     = array_filter(
			array(
				self::note( $order ),
				RINNOVO_Invoice_Docs::place_of_supply( $order ),
				$view['words'],
				! empty( $invoice['replaces'] ) ? sprintf( /* translators: %s: invoice number. */ __( 'Replaces cancelled invoice %s.', 'rinnovo-invoices' ), $invoice['replaces'] ) : '',
				$cancelled ? sprintf( /* translators: %s: credit note number. */ __( 'Cancelled by credit note %s.', 'rinnovo-invoices' ), $invoice['cancelled_by'] ) : '',
			)
		);
		if ( $extra ) {
			$invoice['seller']['payment'] = trim( $invoice['seller']['payment'] . "\n" . implode( "\n", $extra ) );
		}
		$paid_text = $paid ? sprintf(
			/* translators: 1: date, 2: payment method. */
			__( 'Paid on %1$s by %2$s', 'rinnovo-invoices' ),
			$paid_date ? date_i18n( get_option( 'date_format' ), $paid_date->getTimestamp() ) : '',
			$order->get_payment_method_title() ? $order->get_payment_method_title() : __( 'online payment', 'rinnovo-invoices' )
		) : __( 'Not paid yet', 'rinnovo-invoices' );
		return array(
			'order'       => $order,
			'invoice'     => $invoice,
			'seller'      => $invoice['seller'],
			'buyer'       => $invoice['buyer'],
			'title'       => '' !== $invoice['seller']['trn'] ? __( 'Tax Invoice', 'rinnovo-invoices' ) : __( 'Invoice', 'rinnovo-invoices' ),
			'no_label'    => __( 'Invoice No.', 'rinnovo-invoices' ),
			'stamp'       => $cancelled ? __( 'CANCELLED', 'rinnovo-invoices' ) : __( 'PAID', 'rinnovo-invoices' ),
			'total_label' => __( 'Total paid', 'rinnovo-invoices' ),
			'date'        => date_i18n( get_option( 'date_format' ), (int) $invoice['date'] ),
			'lines'       => $view['lines'],
			'totals'      => $view['totals'],
			'total'       => $view['total'],
			'paid'        => $paid || $cancelled,
			'paid_text'   => $paid_text,
			'due'         => self::money( $paid ? 0 : (float) $order->get_total(), $order, $pdf ),
			'has_tax'     => wc_tax_enabled() || (float) $raw['tax'] > 0,
			'currency'    => $order->get_currency(),
		);
	}

	/**
	 * Everything a template needs for a credit note.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $note   Credit note.
	 * @param bool     $pdf    For the PDF.
	 * @return array
	 */
	public static function credit_view_data( $order, $note, $pdf = false ) {
		$view   = RINNOVO_Invoice_Docs::format_snapshot( $note['raw'], $order, $pdf, $note['buyer'] );
		$extra  = array_filter(
			array(
				sprintf( /* translators: 1: invoice number. */ __( 'Credit against invoice %1$s', 'rinnovo-invoices' ), $note['invoice'] ),
				'cancel' === $note['kind'] ? __( 'The invoice is cancelled', 'rinnovo-invoices' ) : __( 'Refund', 'rinnovo-invoices' ),
				'' !== (string) $note['reason'] ? sprintf( /* translators: %s: reason. */ __( 'Reason: %s', 'rinnovo-invoices' ), $note['reason'] ) : '',
				RINNOVO_Invoice_Docs::place_of_supply( $order ),
				$view['words'],
			)
		);
		$seller            = $note['seller'];
		$seller['payment'] = implode( "\n", $extra );
		return array(
			'order'       => $order,
			'invoice'     => array_merge( $note, array( 'number' => $note['number'] ) ),
			'seller'      => $seller,
			'buyer'       => $note['buyer'],
			'title'       => __( 'Credit Note', 'rinnovo-invoices' ),
			'no_label'    => __( 'Credit note No.', 'rinnovo-invoices' ),
			'stamp'       => __( 'CREDIT', 'rinnovo-invoices' ),
			'total_label' => __( 'Total credited', 'rinnovo-invoices' ),
			'date_label'  => __( 'Credit note date:', 'rinnovo-invoices' ),
			'paid_label'  => __( 'Amount credited', 'rinnovo-invoices' ),
			'date'        => date_i18n( get_option( 'date_format' ), (int) $note['date'] ),
			'lines'       => $view['lines'],
			'totals'      => $view['totals'],
			'total'       => $view['total'],
			'paid'        => true,
			'paid_text'   => sprintf( /* translators: %s: invoice number. */ __( 'Credit against invoice %s', 'rinnovo-invoices' ), $note['invoice'] ),
			'due'         => self::money( 0, $order, $pdf ),
			'has_tax'     => (float) $note['raw']['tax'] > 0,
			'currency'    => $order->get_currency(),
		);
	}

	/**
	 * Lines of text as safe HTML with line breaks.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lines( $text ) {
		return nl2br( esc_html( trim( (string) $text ) ) );
	}

	/**
	 * The full invoice page (for the browser, and for saving as PDF).
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $back    Address of the "Back" link.
	 * @param string   $pdf_url Address of the PDF.
	 */
	public static function render( $order, $back, $pdf_url, $d = null ) {
		$d        = null !== $d ? $d : self::view_data( $order );
		$template = (string) self::get( 'template' );
		$tpls     = self::templates();
		$template = isset( $tpls[ $template ] ) ? $template : 'classic';
		$color    = '' !== (string) self::get( 'color' ) ? (string) self::get( 'color' ) : $tpls[ $template ][1];
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( $d['invoice']['number'] ); ?></title>
<style>
:root{--ac:<?php echo esc_html( $color ); ?>;--ink:#1d1f23;--mute:#6b7280;--line:#d9dce1;--soft:#f3f4f6}
*{box-sizing:border-box}
html,body{margin:0;background:#e9ebef;color:var(--ink);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.bar{position:sticky;top:0;z-index:2;display:flex;gap:10px;justify-content:center;align-items:center;padding:12px;background:#1d1f23}
.bar a,.bar button{padding:8px 16px;border:0;border-radius:6px;background:#fff;color:#1d1f23;font:600 14px/1.2 inherit;text-decoration:none;cursor:pointer}
.bar a.pdf{background:var(--ac);color:#fff}
.sheet{width:210mm;min-height:297mm;margin:24px auto;padding:16mm 16mm 14mm;background:#fff;box-shadow:0 2px 18px rgba(0,0,0,.12);position:relative;overflow:hidden}
.logo{max-height:64px;max-width:200px}
h1{margin:0;font-weight:700;letter-spacing:.5px}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-weight:600}
td,th{padding:10px 8px;vertical-align:top}
.num{text-align:right;white-space:nowrap}
.muted{color:var(--mute)}
.small{font-size:12px}
.meta-line{font-size:12px;color:var(--mute)}
.label{font-size:12px;text-transform:uppercase;letter-spacing:.06em;font-weight:700}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:28px}
.right{text-align:right}
.totals{margin-left:auto;width:52%}
.totals td{padding:6px 8px}
.foot{margin-top:28px}
/* Classic */
.t-classic h1{font:400 46px/1 Georgia,"Times New Roman",serif}
.t-classic .head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:40px}
.t-classic .items{margin-top:34px}
.t-classic .items th{border-bottom:2px solid var(--ink)}
.t-classic .items td{border-bottom:1px solid var(--line)}
.t-classic .grand{background:var(--ac);color:#fff;font-size:18px;font-weight:700}
.t-classic .grand td{padding:12px 14px}
.t-classic .thanks{font:italic 34px/1.1 Georgia,"Times New Roman",serif;margin:30px 0 22px}
/* Bold */
.t-bold h1{font-size:34px;text-align:right}
.t-bold .head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:34px}
.t-bold .kv{display:grid;grid-template-columns:auto auto;gap:2px 22px;justify-content:end;margin-top:8px;font-size:13px}
.t-bold .kv b{color:var(--ac);font-weight:700}
.t-bold .sect{color:var(--ac);border-bottom:1px solid var(--ac);padding-bottom:6px;margin-bottom:10px}
.t-bold .items{margin-top:34px}
.t-bold .items thead th{border-bottom:2px solid var(--ac);font-size:12px;text-transform:uppercase}
.t-bold .items tbody td{background:#f1f1f1;border-bottom:3px solid #fff}
.t-bold .totals td{background:#f1f1f1;border-bottom:3px solid #fff}
.t-bold .grand td{background:var(--ac);color:#fff;font-weight:700}
.t-bold .stamp{position:absolute;left:22mm;top:150mm;transform:rotate(-14deg);border:4px solid var(--ac);color:var(--ac);padding:6px 16px;font:800 30px/1 Arial,sans-serif;letter-spacing:2px;opacity:.85}
/* Modern */
.t-modern h1{font-size:30px;text-align:right}
.t-modern .head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:30px}
.t-modern .kv{display:grid;grid-template-columns:auto auto;gap:2px 24px;justify-content:end;margin-top:10px;font-size:12px;color:var(--mute)}
.t-modern .kv span:nth-child(even){color:var(--ink);text-align:right}
.t-modern .items{margin-top:30px}
.t-modern .items thead th{background:var(--ac);color:#fff;font-size:12px;text-transform:uppercase;font-weight:600}
.t-modern .items tbody tr:nth-child(even) td{background:color-mix(in srgb,var(--ac) 7%,#fff)}
.t-modern .bottom{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-top:26px}
.t-modern .totals{width:100%}
.t-modern .balance td{background:color-mix(in srgb,var(--ac) 10%,#fff);font-weight:700;font-size:16px;padding:12px 8px;border-top:2px solid var(--ac)}
@media print{
	html,body{background:#fff}
	.bar{display:none}
	.sheet{margin:0;box-shadow:none;width:auto;min-height:0;padding:0}
	@page{size:A4;margin:14mm}
	*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
@media (max-width:820px){.sheet{width:auto;min-height:0;margin:0;padding:22px}.grid2,.t-modern .bottom{grid-template-columns:1fr}.totals{width:100%}}
</style>
</head>
<body>
<div class="bar">
	<?php if ( '' !== $back ) : ?><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back', 'rinnovo-invoices' ); ?></a><?php endif; ?>
	<a class="pdf" href="<?php echo esc_url( $pdf_url ); ?>"><?php esc_html_e( 'Download PDF', 'rinnovo-invoices' ); ?></a>
</div>
<div class="sheet t-<?php echo esc_attr( $template ); ?>">
		<?php
		call_user_func( array( __CLASS__, 'template_' . $template ), $d );
		?>
</div>
</body>
</html>
		<?php
	}

	/**
	 * The invoice as PDF bytes.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function pdf( $order, $d = null ) {
		$d        = null !== $d ? $d : self::view_data( $order, true );
		$tpls     = self::templates();
		$template = (string) self::get( 'template' );
		$template = isset( $tpls[ $template ] ) ? $template : 'classic';
		$color    = '' !== (string) self::get( 'color' ) ? (string) self::get( 'color' ) : $tpls[ $template ][1];
		return RINNOVO_Invoice_PDF::build( $d, $template, $color );
	}

	/**
	 * File name of the PDF, e.g. INV-00012.pdf.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function pdf_name( $order ) {
		$invoice = self::invoice( $order );
		return sanitize_file_name( ( $invoice ? $invoice['number'] : 'invoice-' . $order->get_id() ) . '.pdf' );
	}

	/**
	 * Send the PDF to the browser as a download.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function send_pdf( $order, $note = null ) {
		$bytes = $note ? self::pdf( $order, self::credit_view_data( $order, $note, true ) ) : self::pdf( $order );
		$name  = $note ? sanitize_file_name( $note['number'] . '.pdf' ) : self::pdf_name( $order );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF.
	}

	/**
	 * Seller block (name, address, contact, TRN).
	 *
	 * @param array $s     Seller.
	 * @param bool  $bold  Name in bold.
	 */
	private static function seller_block( $s, $bold = true ) {
		echo $bold ? '<strong>' . esc_html( $s['name'] ) . '</strong><br />' : esc_html( $s['name'] ) . '<br />';
		echo self::lines( $s['address'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in lines().
		foreach ( array( 'email', 'phone', 'website' ) as $key ) {
			if ( '' !== (string) $s[ $key ] ) {
				echo '<br />' . esc_html( $s[ $key ] );
			}
		}
		if ( '' !== $s['trn'] ) {
			echo '<br /><strong>' . esc_html( $s['tax_label'] ) . ':</strong> ' . esc_html( $s['trn'] );
		}
	}

	/**
	 * Buyer block (name, contact, address, TRN).
	 *
	 * @param array $b         Buyer.
	 * @param array $s         Seller (for the tax label).
	 * @param bool  $with_mail Show email and phone.
	 */
	private static function buyer_block( $b, $s, $with_mail = true ) {
		echo '<strong>' . esc_html( $b['name'] ) . '</strong>';
		if ( '' !== $b['contact'] ) {
			echo '<br />' . esc_html( $b['contact'] );
		}
		if ( '' !== trim( $b['address'] ) ) {
			echo '<br />' . self::lines( $b['address'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in lines().
		}
		if ( $with_mail ) {
			foreach ( array( 'email', 'phone' ) as $key ) {
				if ( '' !== (string) $b[ $key ] ) {
					echo '<br />' . esc_html( $b[ $key ] );
				}
			}
		}
		if ( '' !== $b['trn'] ) {
			echo '<br /><strong>' . esc_html( $s['tax_label'] ) . ':</strong> ' . esc_html( $b['trn'] );
		}
	}

	/**
	 * Item rows.
	 *
	 * @param array $d       View data.
	 * @param bool  $tax_col Show the tax column.
	 */
	private static function item_rows( $d, $tax_col ) {
		foreach ( $d['lines'] as $line ) {
			echo '<tr><td>' . esc_html( $line['name'] );
			if ( '' !== $line['meta'] ) {
				echo '<div class="meta-line">' . esc_html( $line['meta'] ) . '</div>';
			}
			echo '</td><td class="num">' . esc_html( $line['qty'] ) . '</td><td class="num">' . esc_html( $line['unit'] ) . '</td>';
			if ( $tax_col ) {
				echo '<td class="num">' . esc_html( $line['tax'] ) . '</td>';
			}
			echo '<td class="num">' . esc_html( $line['total'] ) . '</td></tr>';
		}
	}

	/**
	 * Logo or the seller's name.
	 *
	 * @param array $s Seller.
	 */
	private static function logo( $s ) {
		if ( '' !== $s['logo'] ) {
			echo '<img class="logo" src="' . esc_url( $s['logo'] ) . '" alt="' . esc_attr( $s['name'] ) . '" />';
		} else {
			echo '<strong style="font-size:20px">' . esc_html( $s['name'] ) . '</strong>';
		}
	}

	/**
	 * Template 1: Classic.
	 *
	 * @param array $d View data.
	 */
	private static function template_classic( $d ) {
		$s = $d['seller'];
		?>
		<div class="head">
			<div><?php self::logo( $s ); ?></div>
			<h1><?php echo esc_html( $d['title'] ); ?></h1>
		</div>
		<div class="grid2">
			<div><div class="label"><?php esc_html_e( 'Billed to', 'rinnovo-invoices' ); ?></div><?php self::buyer_block( $d['buyer'], $s ); ?></div>
			<div class="right">
				<?php echo esc_html( $d['no_label'] ); ?> <strong><?php echo esc_html( $d['invoice']['number'] ); ?></strong><br />
				<?php echo esc_html( $d['date'] ); ?><br />
				<span class="muted">
					<?php
					/* translators: %s: order number. */
					echo esc_html( sprintf( __( 'Order #%s', 'rinnovo-invoices' ), $d['order']->get_order_number() ) );
					?>
				</span>
			</div>
		</div>
		<table class="items">
			<thead><tr><th><?php esc_html_e( 'Item', 'rinnovo-invoices' ); ?></th><th class="num"><?php esc_html_e( 'Quantity', 'rinnovo-invoices' ); ?></th><th class="num"><?php esc_html_e( 'Unit price', 'rinnovo-invoices' ); ?></th><?php if ( $d['has_tax'] ) : ?><th class="num"><?php esc_html_e( 'Tax', 'rinnovo-invoices' ); ?></th><?php endif; ?><th class="num"><?php esc_html_e( 'Total', 'rinnovo-invoices' ); ?></th></tr></thead>
			<tbody><?php self::item_rows( $d, $d['has_tax'] ); ?></tbody>
		</table>
		<table class="totals" style="margin-top:14px">
			<?php foreach ( $d['totals'] as $row ) : ?>
				<tr><td><strong><?php echo esc_html( $row[0] ); ?></strong></td><td class="num"><?php echo esc_html( $row[1] ); ?></td></tr>
			<?php endforeach; ?>
			<tr class="grand"><td><?php esc_html_e( 'Total', 'rinnovo-invoices' ); ?></td><td class="num"><?php echo esc_html( $d['total'] ); ?></td></tr>
			<tr><td colspan="2" class="right small muted"><?php echo esc_html( $d['paid_text'] ); ?></td></tr>
		</table>
		<?php if ( '' !== trim( $s['footer'] ) ) : ?>
			<div class="thanks"><?php echo esc_html( $s['footer'] ); ?></div>
		<?php endif; ?>
		<div class="grid2 foot">
			<div>
				<?php if ( '' !== trim( $s['payment'] ) ) : ?>
					<div class="label"><?php esc_html_e( 'Payment information', 'rinnovo-invoices' ); ?></div><?php echo self::lines( $s['payment'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in lines(). ?>
				<?php endif; ?>
			</div>
			<div class="right"><?php self::seller_block( $s, false ); ?></div>
		</div>
		<?php
	}

	/**
	 * Template 2: Bold.
	 *
	 * @param array $d View data.
	 */
	private static function template_bold( $d ) {
		$s = $d['seller'];
		?>
		<div class="head">
			<div><?php self::logo( $s ); ?></div>
			<div>
				<h1><?php echo esc_html( strtoupper( $d['title'] ) ); ?></h1>
				<div class="kv">
					<b><?php echo esc_html( strtoupper( $d['no_label'] ) . ':' ); ?></b><span><?php echo esc_html( $d['invoice']['number'] ); ?></span>
					<b><?php esc_html_e( 'BILLING DATE:', 'rinnovo-invoices' ); ?></b><span><?php echo esc_html( $d['date'] ); ?></span>
					<b><?php esc_html_e( 'ORDER:', 'rinnovo-invoices' ); ?></b><span>#<?php echo esc_html( $d['order']->get_order_number() ); ?></span>
				</div>
			</div>
		</div>
		<div class="grid2">
			<div><div class="label sect"><?php esc_html_e( 'Our information', 'rinnovo-invoices' ); ?></div><?php self::seller_block( $s ); ?></div>
			<div><div class="label sect"><?php esc_html_e( 'Billing to', 'rinnovo-invoices' ); ?></div><?php self::buyer_block( $d['buyer'], $s ); ?></div>
		</div>
		<table class="items">
			<thead><tr><th><?php esc_html_e( 'Product', 'rinnovo-invoices' ); ?></th><th class="num"><?php esc_html_e( 'Qty', 'rinnovo-invoices' ); ?></th><th class="num"><?php esc_html_e( 'Price', 'rinnovo-invoices' ); ?></th><?php if ( $d['has_tax'] ) : ?><th class="num"><?php esc_html_e( 'VAT', 'rinnovo-invoices' ); ?></th><?php endif; ?><th class="num"><?php esc_html_e( 'Total', 'rinnovo-invoices' ); ?></th></tr></thead>
			<tbody><?php self::item_rows( $d, $d['has_tax'] ); ?></tbody>
		</table>
		<table class="totals" style="margin-top:6px">
			<?php foreach ( $d['totals'] as $row ) : ?>
				<tr><td><?php echo esc_html( $row[0] ); ?></td><td class="num"><?php echo esc_html( $row[1] ); ?></td></tr>
			<?php endforeach; ?>
			<tr><td><?php esc_html_e( 'Payment', 'rinnovo-invoices' ); ?></td><td class="num"><?php echo esc_html( $d['order']->get_payment_method_title() ); ?></td></tr>
			<tr class="grand"><td><?php echo esc_html( $d['paid'] ? $d['total_label'] : __( 'Total due', 'rinnovo-invoices' ) ); ?></td><td class="num"><?php echo esc_html( $d['total'] ); ?></td></tr>
		</table>
		<?php if ( $d['paid'] ) : ?>
			<div class="stamp"><?php echo esc_html( $d['stamp'] ); ?></div>
		<?php endif; ?>
		<div class="foot">
			<?php if ( '' !== trim( $s['payment'] ) ) : ?>
				<div class="label sect"><?php esc_html_e( 'Payment information', 'rinnovo-invoices' ); ?></div>
				<p><?php echo self::lines( $s['payment'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in lines(). ?></p>
			<?php endif; ?>
			<?php if ( '' !== trim( $s['footer'] ) ) : ?>
				<p class="small"><?php echo esc_html( $s['footer'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Template 3: Modern.
	 *
	 * @param array $d View data.
	 */
	private static function template_modern( $d ) {
		$s = $d['seller'];
		?>
		<div class="head">
			<div><?php self::logo( $s ); ?></div>
			<div>
				<h1><?php echo esc_html( $d['title'] ); ?></h1>
				<div class="kv">
					<span><?php echo esc_html( $d['no_label'] . ':' ); ?></span><span><?php echo esc_html( $d['invoice']['number'] ); ?></span>
					<span><?php echo esc_html( isset( $d['date_label'] ) ? $d['date_label'] : __( 'Invoice date:', 'rinnovo-invoices' ) ); ?></span><span><?php echo esc_html( $d['date'] ); ?></span>
					<span><?php esc_html_e( 'Order:', 'rinnovo-invoices' ); ?></span><span>#<?php echo esc_html( $d['order']->get_order_number() ); ?></span>
				</div>
			</div>
		</div>
		<div class="grid2">
			<div><div class="label"><?php esc_html_e( 'From', 'rinnovo-invoices' ); ?></div><?php self::seller_block( $s ); ?></div>
			<div class="right"><div class="label"><?php esc_html_e( 'Bill to', 'rinnovo-invoices' ); ?></div><?php self::buyer_block( $d['buyer'], $s ); ?></div>
		</div>
		<table class="items">
			<thead><tr><th><?php esc_html_e( 'Description', 'rinnovo-invoices' ); ?></th><th class="num"><?php esc_html_e( 'Qty', 'rinnovo-invoices' ); ?></th><th class="num"><?php echo esc_html( sprintf( /* translators: %s: currency code. */ __( 'Rate, %s', 'rinnovo-invoices' ), $d['currency'] ) ); ?></th><?php if ( $d['has_tax'] ) : ?><th class="num"><?php esc_html_e( 'Tax', 'rinnovo-invoices' ); ?></th><?php endif; ?><th class="num"><?php echo esc_html( sprintf( /* translators: %s: currency code. */ __( 'Amount, %s', 'rinnovo-invoices' ), $d['currency'] ) ); ?></th></tr></thead>
			<tbody><?php self::item_rows( $d, $d['has_tax'] ); ?></tbody>
		</table>
		<div class="bottom">
			<div>
				<?php if ( '' !== trim( $s['payment'] ) ) : ?>
					<div class="label"><?php esc_html_e( 'Payment instructions', 'rinnovo-invoices' ); ?></div>
					<p><?php echo self::lines( $s['payment'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in lines(). ?></p>
				<?php endif; ?>
				<?php if ( '' !== trim( $s['footer'] ) ) : ?>
					<div class="label"><?php esc_html_e( 'Notes', 'rinnovo-invoices' ); ?></div>
					<p><?php echo esc_html( $s['footer'] ); ?></p>
				<?php endif; ?>
			</div>
			<table class="totals">
				<?php foreach ( $d['totals'] as $row ) : ?>
					<tr><td><?php echo esc_html( $row[0] ); ?></td><td class="num"><?php echo esc_html( $row[1] ); ?></td></tr>
				<?php endforeach; ?>
				<tr><td><strong><?php esc_html_e( 'Total', 'rinnovo-invoices' ); ?></strong></td><td class="num"><strong><?php echo esc_html( $d['total'] ); ?></strong></td></tr>
				<tr><td><?php echo esc_html( isset( $d['paid_label'] ) ? $d['paid_label'] : __( 'Amount paid', 'rinnovo-invoices' ) ); ?></td><td class="num"><?php echo esc_html( $d['paid'] ? $d['total'] : self::money( 0, $d['order'] ) ); ?></td></tr>
				<tr class="balance"><td><?php esc_html_e( 'Balance due', 'rinnovo-invoices' ); ?></td><td class="num"><?php echo esc_html( $d['due'] ); ?></td></tr>
			</table>
		</div>
		<?php
	}

	/*
	 * My Account.
	 */

	/**
	 * Register the endpoint.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		// After an install or update, WordPress must rebuild its address rules once, or the tab is a 404.
		if ( get_option( self::OPTION . '_rewrite' ) !== RINNOVO_INVOICES_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION . '_rewrite', RINNOVO_INVOICES_VERSION, true );
		}
	}

	/**
	 * Tell WooCommerce about it.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * "Invoices" in the account menu, after Orders.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function menu_item( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'orders' === $key ) {
				$out[ self::ENDPOINT ] = __( 'Invoices', 'rinnovo-invoices' );
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Invoices', 'rinnovo-invoices' );
		}
		return $out;
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public static function account_title() {
		return __( 'Invoices', 'rinnovo-invoices' );
	}

	/**
	 * Invoice address for customers.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $pdf   The PDF download instead of the page.
	 * @return string
	 */
	public static function customer_url( $order, $pdf = false, $note = '' ) {
		$url = wc_get_account_endpoint_url( self::ENDPOINT ) . $order->get_id() . '/';
		$url = '' !== $note ? add_query_arg( 'cn', rawurlencode( $note ), $url ) : $url;
		return $pdf ? add_query_arg( 'pdf', 1, $url ) : $url;
	}

	/**
	 * Invoice address in the admin.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function admin_url( $order, $pdf = false, $note = '' ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::PAGE . '_view&order=' . $order->get_id() . ( $pdf ? '&pdf=1' : '' ) . ( '' !== $note ? '&cn=' . rawurlencode( $note ) : '' ) ), self::PAGE . '_view_' . $order->get_id() );
	}

	/**
	 * The customer's orders that have an invoice.
	 *
	 * @param int $user_id User ID.
	 * @return WC_Order[]
	 */
	private static function customer_invoice_orders( $user_id ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 200,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only orders with an invoice.
				'meta_compare' => 'EXISTS',
			)
		);
		return array_values(
			array_filter(
				$orders,
				static function ( $order ) {
					return (bool) self::invoice( $order );
				}
			)
		);
	}

	/**
	 * The latest order with an invoice (for the preview button).
	 *
	 * @return WC_Order|null
	 */
	private static function latest_invoice_order() {
		$orders = wc_get_orders(
			array(
				'limit'        => 1,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'meta_key'     => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row.
				'meta_compare' => 'EXISTS',
			)
		);
		return $orders ? $orders[0] : null;
	}

	/**
	 * My Account > Invoices.
	 */
	public static function account_list() {
		$orders = self::customer_invoice_orders( get_current_user_id() );
		if ( ! $orders ) {
			echo '<p>' . esc_html__( 'You have no invoices yet. An invoice appears here after every payment.', 'rinnovo-invoices' ) . '</p>';
			return;
		}
		?>
		<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders rinnovo-invoices">
			<thead><tr>
				<th><?php esc_html_e( 'Invoice', 'rinnovo-invoices' ); ?></th>
				<th><?php esc_html_e( 'Date', 'rinnovo-invoices' ); ?></th>
				<th><?php esc_html_e( 'Order', 'rinnovo-invoices' ); ?></th>
				<th><?php esc_html_e( 'Total', 'rinnovo-invoices' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'rinnovo-invoices' ); ?></span></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<?php $inv = self::invoice( $order ); ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Invoice', 'rinnovo-invoices' ); ?>"><?php echo esc_html( $inv['number'] ); ?></td>
						<td data-title="<?php esc_attr_e( 'Date', 'rinnovo-invoices' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), (int) $inv['date'] ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Order', 'rinnovo-invoices' ); ?>"><a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
						<td data-title="<?php esc_attr_e( 'Total', 'rinnovo-invoices' ); ?>"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
						<td>
							<a class="woocommerce-button button view" href="<?php echo esc_url( self::customer_url( $order ) ); ?>"><?php esc_html_e( 'View', 'rinnovo-invoices' ); ?></a>
							<a class="woocommerce-button button" href="<?php echo esc_url( self::customer_url( $order, true ) ); ?>"><?php esc_html_e( 'Download PDF', 'rinnovo-invoices' ); ?></a>
						</td>
					</tr>
					<?php foreach ( RINNOVO_Invoice_Docs::notes( $order ) as $cn ) : ?>
						<tr class="rinnovo-credit-note">
							<td data-title="<?php esc_attr_e( 'Credit note', 'rinnovo-invoices' ); ?>"><?php echo esc_html( $cn['number'] ); ?> <small>(<?php esc_html_e( 'credit note', 'rinnovo-invoices' ); ?>)</small></td>
							<td data-title="<?php esc_attr_e( 'Date', 'rinnovo-invoices' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), (int) $cn['date'] ) ); ?></td>
							<td data-title="<?php esc_attr_e( 'Order', 'rinnovo-invoices' ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></td>
							<td data-title="<?php esc_attr_e( 'Total', 'rinnovo-invoices' ); ?>">−<?php echo wp_kses_post( wc_price( (float) $cn['raw']['total'], array( 'currency' => $order->get_currency() ) ) ); ?></td>
							<td>
								<a class="woocommerce-button button view" href="<?php echo esc_url( self::customer_url( $order, false, $cn['number'] ) ); ?>"><?php esc_html_e( 'View', 'rinnovo-invoices' ); ?></a>
								<a class="woocommerce-button button" href="<?php echo esc_url( self::customer_url( $order, true, $cn['number'] ) ); ?>"><?php esc_html_e( 'Download PDF', 'rinnovo-invoices' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( 'off' !== self::get( 'customer_mode' ) ) {
			echo '<p class="rinnovo-invoices__hint">' . wp_kses_post(
				sprintf(
					/* translators: %s: link to Account details. */
					__( 'Need a tax invoice in your company\'s name? Add your legal company name, address and TRN in %s; they are used for your next invoices.', 'rinnovo-invoices' ),
					'<a href="' . esc_url( wc_get_account_endpoint_url( 'edit-account' ) ) . '">' . esc_html__( 'Account details', 'rinnovo-invoices' ) . '</a>'
				)
			) . '</p>';
		}
	}

	/**
	 * Show one invoice to its customer: /my-account/invoices/123/.
	 */
	public static function maybe_show_customer_invoice() {
		global $wp;
		if ( ! isset( $wp->query_vars[ self::ENDPOINT ] ) || '' === $wp->query_vars[ self::ENDPOINT ] ) {
			return;
		}
		$order_id = absint( $wp->query_vars[ self::ENDPOINT ] );
		if ( ! $order_id ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}
		$order = wc_get_order( $order_id );
		$own   = $order && (int) $order->get_customer_id() === get_current_user_id();
		if ( ! $order || ! self::invoice( $order ) || ( ! $own && ! current_user_can( 'edit_shop_orders' ) ) ) {
			wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
			exit;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; access is checked above.
		$number = isset( $_GET['cn'] ) ? sanitize_text_field( wp_unslash( $_GET['cn'] ) ) : '';
		$note   = '' !== $number ? RINNOVO_Invoice_Docs::note( $order, $number ) : null;
		if ( ! empty( $_GET['pdf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; access is checked above.
			self::send_pdf( $order, $note );
			exit;
		}
		if ( $note ) {
			self::render( $order, wc_get_account_endpoint_url( self::ENDPOINT ), self::customer_url( $order, true, $number ), self::credit_view_data( $order, $note ) );
			exit;
		}
		self::render( $order, wc_get_account_endpoint_url( self::ENDPOINT ), self::customer_url( $order, true ) );
		exit;
	}

	/**
	 * "Invoice" button on My Account > Orders.
	 *
	 * @param array    $actions Actions.
	 * @param WC_Order $order   Order.
	 * @return array
	 */
	public static function order_action( $actions, $order ) {
		if ( self::invoice( $order ) ) {
			$actions['rinnovo-invoice'] = array(
				'url'  => self::customer_url( $order ),
				'name' => __( 'Invoice', 'rinnovo-invoices' ),
			);
		}
		return $actions;
	}

	/*
	 * The customer's legal details.
	 */

	/**
	 * Labels of the three fields.
	 *
	 * @return array
	 */
	private static function field_labels() {
		return array(
			'name'    => __( 'Legal company name', 'rinnovo-invoices' ),
			'address' => __( 'Legal address', 'rinnovo-invoices' ),
			/* translators: %s: tax number label, e.g. TRN. */
			'trn'     => sprintf( __( 'Tax registration number (%s)', 'rinnovo-invoices' ), self::get( 'tax_label' ) ),
		);
	}

	/**
	 * Whether a field must be filled in.
	 *
	 * @param string $key Field.
	 * @return bool
	 */
	private static function required( $key ) {
		return 'required' === self::get( 'customer_mode' ) && 'trn' !== $key;
	}

	/**
	 * Fields on My Account > Account details.
	 */
	public static function account_fields() {
		$user_id = get_current_user_id();
		?>
		<fieldset class="rinnovo-legal">
			<legend><?php esc_html_e( 'Details for your invoices', 'rinnovo-invoices' ); ?></legend>
			<p class="description"><?php echo 'required' === self::get( 'customer_mode' ) ? esc_html__( 'Your invoices are issued to this legal name and address.', 'rinnovo-invoices' ) : esc_html__( 'Optional. Fill these in if you need tax invoices in your company\'s name.', 'rinnovo-invoices' ); ?></p>
			<?php foreach ( self::field_labels() as $key => $label ) : ?>
				<?php $value = (string) get_user_meta( $user_id, self::USERMETA . $key, true ); ?>
				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="rinnovo-legal-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?><?php echo self::required( $key ) ? '&nbsp;<span class="required">*</span>' : ''; ?></label>
					<?php if ( 'address' === $key ) : ?>
						<textarea class="woocommerce-Input input-text" name="rinnovo_legal_<?php echo esc_attr( $key ); ?>" id="rinnovo-legal-<?php echo esc_attr( $key ); ?>" rows="3"><?php echo esc_textarea( $value ); ?></textarea>
					<?php else : ?>
						<input type="text" class="woocommerce-Input input-text" name="rinnovo_legal_<?php echo esc_attr( $key ); ?>" id="rinnovo-legal-<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					<?php endif; ?>
				</p>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Required fields check (WooCommerce has checked its nonce).
	 *
	 * @param WP_Error $errors Errors.
	 */
	public static function validate_account_fields( $errors ) {
		foreach ( self::field_labels() as $key => $label ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the account form nonce.
			if ( self::required( $key ) && empty( $_POST[ 'rinnovo_legal_' . $key ] ) ) {
				/* translators: %s: field name. */
				$errors->add( 'rinnovo_legal_' . $key, sprintf( __( '%s is a required field.', 'rinnovo-invoices' ), $label ) );
			}
		}
	}

	/**
	 * Save the fields from Account details.
	 *
	 * @param int $user_id User ID.
	 */
	public static function save_account_fields( $user_id ) {
		foreach ( array_keys( self::field_labels() ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the account form nonce.
			if ( isset( $_POST[ 'rinnovo_legal_' . $key ] ) ) {
				$raw = wp_unslash( $_POST[ 'rinnovo_legal_' . $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned on the next line.
				update_user_meta( $user_id, self::USERMETA . $key, 'address' === $key ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ) );
			}
		}
	}

	/**
	 * Fields on the classic checkout, filled with what the customer saved before.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public static function checkout_fields( $fields ) {
		$user_id  = get_current_user_id();
		$priority = 26;
		foreach ( self::field_labels() as $key => $label ) {
			$fields['billing'][ 'rinnovo_legal_' . $key ] = array(
				'type'     => 'address' === $key ? 'textarea' : 'text',
				'label'    => $label,
				'required' => self::required( $key ),
				'class'    => array( 'form-row-wide' ),
				'priority' => $priority++,
				'default'  => $user_id ? (string) get_user_meta( $user_id, self::USERMETA . $key, true ) : '',
			);
		}
		return $fields;
	}

	/**
	 * Classic checkout: "(for a tax invoice)" instead of WooCommerce's "(optional)" on these fields.
	 *
	 * @param string $field Field HTML.
	 * @param string $key   Field key.
	 * @param array  $args  Field settings.
	 * @return string
	 */
	public static function tax_invoice_hint( $field, $key, $args ) {
		if ( 0 !== strpos( (string) $key, 'rinnovo_legal_' ) || ! empty( $args['required'] ) ) {
			return $field;
		}
		return preg_replace( '#<span class="optional">.*?</span>#', '<span class="optional">' . esc_html__( '(for a tax invoice)', 'rinnovo-invoices' ) . '</span>', $field, 1 );
	}

	/**
	 * Keep checkout values on the order and on the customer's account.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted checkout data.
	 */
	public static function save_checkout_fields( $order, $data ) {
		foreach ( array_keys( self::field_labels() ) as $key ) {
			$value = isset( $data[ 'rinnovo_legal_' . $key ] ) ? trim( (string) $data[ 'rinnovo_legal_' . $key ] ) : '';
			if ( '' === $value ) {
				continue;
			}
			$order->update_meta_data( '_' . self::USERMETA . $key, $value );
			if ( $order->get_customer_id() ) {
				update_user_meta( $order->get_customer_id(), self::USERMETA . $key, $value );
			}
		}
	}

	/**
	 * The same fields on the block checkout (WooCommerce 8.9+).
	 */
	public static function register_block_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		foreach ( self::field_labels() as $key => $label ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => 'rinnovopro/legal-' . $key,
					'label'    => $label,
					'location' => 'contact',
					'type'          => 'text',
					'required'      => self::required( $key ),
					/* translators: %s: field name. */
					'optionalLabel' => sprintf( __( '%s (for a tax invoice)', 'rinnovo-invoices' ), $label ),
				)
			);
		}
	}

	/**
	 * The fields on Users > Profile, for administrators.
	 *
	 * @param WP_User $user User.
	 */
	public static function profile_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Details for invoices', 'rinnovo-invoices' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( self::field_labels() as $key => $label ) : ?>
				<tr>
					<th scope="row"><label for="rinnovo-legal-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<?php if ( 'address' === $key ) : ?>
							<textarea id="rinnovo-legal-<?php echo esc_attr( $key ); ?>" name="rinnovo_legal_<?php echo esc_attr( $key ); ?>" rows="3" class="regular-text"><?php echo esc_textarea( (string) get_user_meta( $user->ID, self::USERMETA . $key, true ) ); ?></textarea>
						<?php else : ?>
							<input type="text" id="rinnovo-legal-<?php echo esc_attr( $key ); ?>" name="rinnovo_legal_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, self::USERMETA . $key, true ) ); ?>" class="regular-text" />
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Save from Users > Profile (WordPress has checked its nonce).
	 *
	 * @param int $user_id User ID.
	 */
	public static function save_profile_fields( $user_id ) {
		if ( current_user_can( 'edit_user', $user_id ) ) {
			self::save_account_fields( $user_id );
		}
	}

	/*
	 * Emails and admin.
	 */

	/**
	 * "Your invoice" link in customer order emails.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email goes to the shop.
	 * @param bool     $plain_text    Plain text email.
	 * @param WC_Email $email         Email.
	 */
	public static function email_link( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! self::get( 'email_link' ) || ! $order instanceof WC_Order || ! $order->get_customer_id() ) {
			return;
		}
		$invoice = self::invoice( $order );
		if ( ! $invoice ) {
			return;
		}
		/* translators: %s: invoice number. */
		$text = sprintf( __( 'Your invoice %s is ready:', 'rinnovo-invoices' ), $invoice['number'] );
		if ( $plain_text ) {
			echo esc_html( $text ) . ' ' . esc_url_raw( self::customer_url( $order ) ) . "\n\n";
			return;
		}
		echo '<p>' . esc_html( $text ) . ' <a href="' . esc_url( self::customer_url( $order ) ) . '">' . esc_html__( 'View or download it', 'rinnovo-invoices' ) . '</a></p>';
	}

	/**
	 * Attach the invoice PDF to the customer's order emails.
	 *
	 * @param array    $attachments Files.
	 * @param string   $email_id    Email ID.
	 * @param mixed    $object      Email object (an order for order emails).
	 * @param WC_Email $email       Email.
	 * @return array
	 */
	public static function email_attachment( $attachments, $email_id, $object = null, $email = null ) {
		if ( ! self::get( 'email_pdf' ) || ! $object instanceof WC_Order || $object instanceof WC_Order_Refund || 0 !== strpos( (string) $email_id, 'customer_' ) || ! self::invoice( $object ) ) {
			return $attachments;
		}
		$dir = trailingslashit( get_temp_dir() ) . 'rinnovo-invoices-' . wp_hash( 'rinnovo-invoices' );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$file = trailingslashit( $dir ) . self::pdf_name( $object );
		if ( false !== file_put_contents( $file, self::pdf( $object ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary file for the email.
			$attachments[] = $file;
			// Removed once the request is done.
			register_shutdown_function(
				static function () use ( $file ) {
					if ( file_exists( $file ) ) {
						wp_delete_file( $file );
					}
				}
			);
		}
		return $attachments;
	}

	/**
	 * "Invoice" box on the order screen.
	 */
	public static function meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'rinnovopro-invoice', __( 'Invoice', 'rinnovo-invoices' ), array( __CLASS__, 'meta_box_content' ), $screen, 'side', 'default' );
		}
	}

	/**
	 * Contents of the box.
	 *
	 * @param WP_Post|WC_Order $object Order or its post.
	 */
	public static function meta_box_content( $object ) {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );
		if ( ! $order ) {
			return;
		}
		$invoice = self::invoice( $order );
		$action  = static function ( $todo ) use ( $order ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::PAGE . '_action&todo=' . $todo . '&order=' . $order->get_id() ), self::PAGE . '_action_' . $order->get_id() );
		};
		if ( $invoice ) {
			echo '<p><strong>' . esc_html( $invoice['number'] ) . '</strong><br />' . esc_html( date_i18n( get_option( 'date_format' ), (int) $invoice['date'] ) ) . '</p>';
			echo '<p><a class="button" target="_blank" href="' . esc_url( self::admin_url( $order ) ) . '">' . esc_html__( 'View invoice', 'rinnovo-invoices' ) . '</a> <a class="button" href="' . esc_url( self::admin_url( $order, true ) ) . '">' . esc_html__( 'PDF', 'rinnovo-invoices' ) . '</a></p>';
			if ( isset( $invoice['status'] ) && 'cancelled' === $invoice['status'] ) {
				/* translators: %s: credit note number. */
				echo '<p><strong style="color:#b32d2e">' . esc_html( sprintf( __( 'Cancelled by credit note %s', 'rinnovo-invoices' ), $invoice['cancelled_by'] ) ) . '</strong></p>';
				echo '<p><a class="button" href="' . esc_url( $action( 'reissue' ) ) . '">' . esc_html__( 'Issue a corrected invoice', 'rinnovo-invoices' ) . '</a></p>';
				echo '<p class="description">' . esc_html__( 'A new number, with today\'s company and customer details, linked to the cancelled invoice.', 'rinnovo-invoices' ) . '</p>';
			} else {
				echo '<p><a href="' . esc_url( $action( 'cancel' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Cancel this invoice? A credit note for its full amount is issued. The invoice itself is kept, marked cancelled.', 'rinnovo-invoices' ) ) . '\')">' . esc_html__( 'Cancel invoice (to correct it)', 'rinnovo-invoices' ) . '</a></p>';
				echo '<p class="description">' . esc_html__( 'Issued invoices never change. To correct details, cancel it (a credit note is issued) and issue a corrected invoice.', 'rinnovo-invoices' ) . '</p>';
			}
			foreach ( RINNOVO_Invoice_Docs::old_invoices( $order ) as $old ) {
				/* translators: %s: invoice number. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Earlier invoice %s (cancelled)', 'rinnovo-invoices' ), $old['number'] ) ) . '</p>';
			}
			foreach ( RINNOVO_Invoice_Docs::notes( $order ) as $cn ) {
				echo '<p>' . esc_html( $cn['number'] ) . ' · ' . esc_html( 'cancel' === $cn['kind'] ? __( 'cancellation', 'rinnovo-invoices' ) : __( 'refund', 'rinnovo-invoices' ) ) . ' · −' . wp_kses_post( wc_price( (float) $cn['raw']['total'], array( 'currency' => $order->get_currency() ) ) ) . '<br /><a target="_blank" href="' . esc_url( self::admin_url( $order, false, $cn['number'] ) ) . '">' . esc_html__( 'View', 'rinnovo-invoices' ) . '</a> · <a href="' . esc_url( self::admin_url( $order, true, $cn['number'] ) ) . '">PDF</a></p>';
			}
		} else {
			echo '<p>' . esc_html__( 'No invoice yet. It is created when the order is paid.', 'rinnovo-invoices' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( $action( 'create' ) ) . '">' . esc_html__( 'Create invoice now', 'rinnovo-invoices' ) . '</a></p>';
		}
	}

	/**
	 * Show an invoice in the admin.
	 */
	public static function admin_view() {
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( self::PAGE . '_view_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'rinnovo-invoices' ) );
		}
		$number = isset( $_GET['cn'] ) ? sanitize_text_field( wp_unslash( $_GET['cn'] ) ) : '';
		$note   = '' !== $number ? RINNOVO_Invoice_Docs::note( $order, $number ) : null;
		if ( ! empty( $_GET['pdf'] ) ) {
			self::send_pdf( $order, $note );
			exit;
		}
		if ( $note ) {
			self::render( $order, $order->get_edit_order_url(), self::admin_url( $order, true, $number ), self::credit_view_data( $order, $note ) );
			exit;
		}
		self::render( $order, $order->get_edit_order_url(), self::admin_url( $order, true ) );
		exit;
	}

	/**
	 * Create or refresh from the order screen.
	 */
	public static function admin_action() {
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( self::PAGE . '_action_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'rinnovo-invoices' ) );
		}
		$todo = isset( $_GET['todo'] ) ? sanitize_key( wp_unslash( $_GET['todo'] ) ) : 'create';
		if ( 'cancel' === $todo ) {
			RINNOVO_Invoice_Docs::cancel( $order, __( 'Invoice cancelled for correction', 'rinnovo-invoices' ) );
		} elseif ( 'reissue' === $todo ) {
			RINNOVO_Invoice_Docs::reissue( $order );
		} else {
			self::create( $order );
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}
}
