<?php
/**
 * What Sarala Login Page Pro adds, shown only on this plugin's own screens and only while Pro is
 * not installed: a "Pro features" screen, an "Upgrade to Pro" menu link and a link on the Plugins
 * screen. Nothing in the free plugin is locked or limited.
 *
 * @package SaralaLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro features screen and upgrade links.
 */
class SARALOPA_Pro_Features {

	const PAGE = 'sarala-login-page-pro-features';
	const URL  = 'https://laghavi.site/sarala-login-page/';

	/**
	 * Hook everything up, unless Pro is active.
	 */
	public static function init() {
		if ( self::pro_active() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'menu_style' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SARALOPA_FILE ), array( __CLASS__, 'action_link' ), 20 );
	}

	/**
	 * Is Sarala Login Page Pro active?
	 *
	 * @return bool
	 */
	public static function pro_active() {
		return defined( 'SLPP_VERSION' );
	}

	/**
	 * Address of the Pro page, with where the visitor came from.
	 *
	 * @param string $where Place of the link.
	 * @return string
	 */
	public static function url( $where = 'menu' ) {
		return add_query_arg(
			array(
				'utm_source'   => 'sarala-login-page',
				'utm_medium'   => 'plugin',
				'utm_campaign' => sanitize_key( $where ),
			),
			self::URL
		);
	}

	/**
	 * "Pro features" and "Upgrade to Pro" at the end of the Sarala Login menu.
	 */
	public static function menu() {
		add_submenu_page( SARALOPA_Settings::PAGE, __( 'Sarala Login Page Pro', 'sarala-login-page' ), __( 'Pro features', 'sarala-login-page' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
		add_submenu_page( SARALOPA_Settings::PAGE, __( 'Upgrade to Pro', 'sarala-login-page' ), __( 'Upgrade to Pro', 'sarala-login-page' ), 'manage_options', self::url( 'menu' ) );
	}

	/**
	 * Green "Upgrade to Pro" item in the admin menu.
	 */
	public static function menu_style() {
		$sel = '#adminmenu #toplevel_page_sarala-login-page a[href^="' . esc_url( self::URL ) . '"]';
		wp_add_inline_style( 'wp-admin', $sel . '{background:#16a34a;color:#fff;font-weight:600}' . $sel . ':hover,' . $sel . ':focus{background:#15803d;color:#fff}' );
	}

	/**
	 * "Go Pro" on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_link( $links ) {
		$links[] = '<a href="' . esc_url( self::url( 'plugins' ) ) . '" target="_blank" rel="noopener" style="color:#16a34a;font-weight:700">' . esc_html__( 'Go Pro', 'sarala-login-page' ) . '</a>';
		return $links;
	}

	/**
	 * Pro features, grouped.
	 *
	 * @return array title => array( intro, items[] ).
	 */
	private static function features() {
		return array(
			__( 'Login page design', 'sarala-login-page' ) => array(
				__( 'Your brand on every login screen, with a live preview on desktop and phone.', 'sarala-login-page' ),
				array(
					__( 'Five templates: Glass, Split screen, Wave card, Image card and Gradient', 'sarala-login-page' ),
					__( 'Your logo, background image, panel image and a slider to darken images', 'sarala-login-page' ),
					__( 'Colours, fonts (Poppins, Inter, Montserrat, DM Sans) and corners', 'sarala-login-page' ),
					__( 'Your own headline, panel text, footer and titles for each screen', 'sarala-login-page' ),
					__( 'Register, forgot-password and reset-password screens match automatically, also on your custom login address', 'sarala-login-page' ),
					__( 'Hide what you don\'t need: "Remember me", "Register", the language switcher, the brand panel on phones…', 'sarala-login-page' ),
				),
			),
			__( 'Button style', 'sarala-login-page' )      => array(
				__( 'Login buttons that match your brand.', 'sarala-login-page' ),
				array(
					__( 'Icon tiles, outline boxes or brand pills, besides the standard buttons', 'sarala-login-page' ),
					__( 'Rounded, pill, square or circle corners', 'sarala-login-page' ),
					__( 'Your own colours for every button, with a live preview', 'sarala-login-page' ),
				),
			),
			__( 'More login providers', 'sarala-login-page' ) => array(
				__( 'Seven more ways to sign up and log in.', 'sarala-login-page' ),
				array(
					__( 'Slack, TikTok, Twitch, VK (VKontakte), Patreon, Amazon and Disqus', 'sarala-login-page' ),
				),
			),
			__( 'Stronger login security', 'sarala-login-page' ) => array(
				__( 'Extra protection that never locks you out.', 'sarala-login-page' ),
				array(
					__( 'Cloudflare Turnstile, Google reCAPTCHA or hCaptcha, with the security question as automatic backup if the CAPTCHA breaks', 'sarala-login-page' ),
					__( 'Provider keys stored encrypted with your site\'s own security keys', 'sarala-login-page' ),
					__( 'Blocked redirects: addresses nobody is ever sent to after logging in or out', 'sarala-login-page' ),
				),
			),
			__( 'Super admin', 'sarala-login-page' )       => array(
				__( 'You stay the owner of the site, even with other administrators.', 'sarala-login-page' ),
				array(
					__( 'Only the super admin can add or promote administrators', 'sarala-login-page' ),
					__( 'Other admins can\'t edit, delete or demote the super admin or each other, or deactivate Sarala', 'sarala-login-page' ),
					__( 'Optionally only the super admin installs plugins and themes, edits files and updates WordPress', 'sarala-login-page' ),
					__( 'Hide the super admin from other administrators', 'sarala-login-page' ),
				),
			),
			__( 'User activity log', 'sarala-login-page' ) => array(
				__( 'Know who did what, and when.', 'sarala-login-page' ),
				array(
					__( '"Last activity" for every user on the Users screen', 'sarala-login-page' ),
					__( 'Logins, sign-ups, edits, publishing, media, plugins, themes, settings and role changes', 'sarala-login-page' ),
					__( 'WooCommerce orders, order status changes, account details and addresses', 'sarala-login-page' ),
					__( 'Filter by user, type and date, with IP and device, and export to CSV', 'sarala-login-page' ),
				),
			),
			__( 'Hide & restrict per role or user', 'sarala-login-page' ) => array(
				__( 'Show each role only what it needs.', 'sarala-login-page' ),
				array(
					__( 'Admin menus and submenus (hidden screens can\'t be opened by address either)', 'sarala-login-page' ),
					__( 'Admin bar tools and dashboard widgets', 'sarala-login-page' ),
					__( 'Meta boxes, custom fields and single Advanced Custom Fields fields', 'sarala-login-page' ),
					__( 'Editor blocks, sidebar panels and editor features', 'sarala-login-page' ),
				),
			),
			__( 'Login redirects', 'sarala-login-page' )   => array(
				__( 'Send each role to the right page after login and logout.', 'sarala-login-page' ),
				array(
					__( 'A page for every role: admins to the dashboard, customers to My Account, members to their area', 'sarala-login-page' ),
					__( 'Separate pages after login and after logout, with a fallback for everyone else', 'sarala-login-page' ),
					__( 'Works with password logins, social logins and WooCommerce', 'sarala-login-page' ),
				),
			),
			__( 'Login widget', 'sarala-login-page' )      => array(
				__( 'A login box for your sidebar, footer or any page.', 'sarala-login-page' ),
				array(
					__( 'AJAX login: no page reload, errors shown in place', 'sarala-login-page' ),
					__( 'Social login buttons, "Remember me", lost password and register links', 'sarala-login-page' ),
					__( 'Your own HTML for logged-in users and your own CSS', 'sarala-login-page' ),
					__( 'As a classic widget or anywhere with a shortcode', 'sarala-login-page' ),
				),
			),
			__( 'Profiles', 'sarala-login-page' )          => array(
				__( 'Profile pictures and control over connected accounts.', 'sarala-login-page' ),
				array(
					__( 'Users upload their own profile picture, used instead of Gravatar everywhere', 'sarala-login-page' ),
					__( 'Choose who may disconnect social accounts, and stop users locking themselves out', 'sarala-login-page' ),
					__( 'Disconnect social accounts for many users at once', 'sarala-login-page' ),
				),
			),
			__( 'Content for the right people', 'sarala-login-page' ) => array(
				__( 'Show blocks and widgets to chosen visitors only.', 'sarala-login-page' ),
				array(
					__( 'Every block: everyone, chosen roles, logged-out visitors, or everyone except them', 'sarala-login-page' ),
					__( 'The same setting on classic sidebar widgets', 'sarala-login-page' ),
				),
			),
		);
	}

	/**
	 * The Pro features screen.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$templates = array(
			'glass'    => __( 'Glass', 'sarala-login-page' ),
			'split'    => __( 'Split screen', 'sarala-login-page' ),
			'wave'     => __( 'Wave card', 'sarala-login-page' ),
			'image'    => __( 'Image card', 'sarala-login-page' ),
			'gradient' => __( 'Gradient', 'sarala-login-page' ),
		);
		?>
		<div class="wrap saralopa-admin saralopa-pro">
			<div class="saralopa-pro__hero">
				<div>
					<span class="saralopa-pro__badge"><?php esc_html_e( 'Sarala Login Page Pro', 'sarala-login-page' ); ?></span>
					<h1><?php esc_html_e( 'Make your login page yours', 'sarala-login-page' ); ?></h1>
					<p><?php esc_html_e( 'Everything in the free plugin keeps working. Pro is an add-on that adds login page templates with your brand, login redirects per role, a login widget, a super admin, a user activity log and per-role restrictions.', 'sarala-login-page' ); ?></p>
					<p><a class="button button-hero saralopa-pro__cta" href="<?php echo esc_url( self::url( 'features-top' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Pro – from USD 54 / year', 'sarala-login-page' ); ?></a></p>
				</div>
			</div>

			<h2><?php esc_html_e( 'Five login page templates', 'sarala-login-page' ); ?></h2>
			<div class="saralopa-pro__templates">
				<?php foreach ( $templates as $key => $name ) : ?>
					<figure class="saralopa-tpl saralopa-tpl--<?php echo esc_attr( $key ); ?>">
						<div class="saralopa-tpl__screen"><span class="saralopa-tpl__panel"></span><span class="saralopa-tpl__card"><i class="l"></i><i></i><i></i><i class="b"></i></span></div>
						<figcaption><?php echo esc_html( $name ); ?></figcaption>
					</figure>
				<?php endforeach; ?>
			</div>

			<div class="saralopa-pro__grid">
				<?php foreach ( self::features() as $title => $group ) : ?>
					<div class="saralopa-card">
						<h2><?php echo esc_html( $title ); ?></h2>
						<p class="description"><?php echo esc_html( $group[0] ); ?></p>
						<ul class="saralopa-pro__list">
							<?php foreach ( $group[1] as $item ) : ?>
								<li><?php echo esc_html( $item ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="saralopa-card saralopa-pro__end">
				<h2><?php esc_html_e( 'Ready to make it yours?', 'sarala-login-page' ); ?></h2>
				<p><?php esc_html_e( 'Plans for 1 site, 5 sites or unlimited sites, with a year of updates and support. Install Pro next to this plugin: your settings stay as they are.', 'sarala-login-page' ); ?></p>
				<p><a class="button button-hero saralopa-pro__cta" href="<?php echo esc_url( self::url( 'features-bottom' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See plans and prices', 'sarala-login-page' ); ?></a></p>
			</div>
		</div>
		<?php
	}

	/**
	 * A short note at the end of the settings screen.
	 */
	public static function settings_note() {
		if ( self::pro_active() ) {
			return;
		}
		echo '<div class="saralopa-card saralopa-pro-note"><h2>' . esc_html__( 'Want your own login page design?', 'sarala-login-page' ) . '</h2><p>' . esc_html__( 'Sarala Login Page Pro adds five login page templates with your logo, colours and texts, login redirects per role, an AJAX login widget, a super admin, a user activity log and per-role restrictions.', 'sarala-login-page' ) . '</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'See the Pro features', 'sarala-login-page' ) . '</a></p></div>';
	}
}
