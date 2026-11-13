<?php
/**
 * What the separate Rinnovo Pro plugins add: a "Rinnovo Pro" screen under WooCommerce and a link on
 * the Plugins screen, with a link to the Pro page on laghavi.site. Nothing in this plugin is locked or
 * limited; each Pro plugin is a separate plugin with its own code. The screen is hidden once
 * Rinnovo Pro is active.
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro features screen.
 */
class RINNOVO_Pro_Features {

	const PAGE = 'rinnovo-pro-features';
	const URL  = 'https://laghavi.site/rinnovo-subscriptions-licenses/';

	/**
	 * Hook everything up, unless Pro is active.
	 */
	public static function init() {
		if ( defined( 'RINNOVOPRO_VERSION' ) ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RINNOVO_FILE ), array( __CLASS__, 'action_link' ), 20 );
	}

	/**
	 * "Rinnovo Pro" under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Rinnovo Pro', 'rinnovo-subscriptions-licenses' ), __( 'Rinnovo Pro', 'rinnovo-subscriptions-licenses' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Styles for the screen.
	 *
	 * @param string $hook Admin page.
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::PAGE ) ) {
			wp_enqueue_style( 'rinnovo-pro-features', RINNOVO_URL . 'assets/css/pro-features.css', array(), rinnovo_asset_version( 'assets/css/pro-features.css' ) );
		}
	}

	/**
	 * "Pro features" on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_link( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Pro features', 'rinnovo-subscriptions-licenses' ) . '</a>';
		return $links;
	}

	/**
	 * Pro features, grouped: title => array( intro, items ).
	 *
	 * @return array
	 */
	private static function features() {
		return array(
			__( 'Invoices and tax invoices', 'rinnovo-subscriptions-licenses' )   => array(
				__( 'A numbered invoice for every paid order and renewal.', 'rinnovo-subscriptions-licenses' ),
				array(
					__( 'Numbers without gaps (INV-00001), your prefix and start number', 'rinnovo-subscriptions-licenses' ),
					__( 'Three PDF templates in your colour, with your logo', 'rinnovo-subscriptions-licenses' ),
					__( '"Tax Invoice" with your TRN or VAT number, and the customer\'s', 'rinnovo-subscriptions-licenses' ),
					__( 'My Account > Invoices with View and Download PDF', 'rinnovo-subscriptions-licenses' ),
				),
			),
			__( 'Trials and first-payment offers', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'Turn more visitors into subscribers.', 'rinnovo-subscriptions-licenses' ),
				array(
					__( 'Free trials with or without a card', 'rinnovo-subscriptions-licenses' ),
					__( '"First month half price": a percentage or fixed discount on the first payment', 'rinnovo-subscriptions-licenses' ),
					__( '"First payment: $5.00" under the price, in the cart and at checkout', 'rinnovo-subscriptions-licenses' ),
				),
			),
			__( 'VAT / GST for digital goods', 'rinnovo-subscriptions-licenses' )  => array(
				__( 'Sell to businesses in other countries correctly.', 'rinnovo-subscriptions-licenses' ),
				array(
					__( 'VAT / GST number at checkout, EU numbers checked with VIES', 'rinnovo-subscriptions-licenses' ),
					__( 'Reverse charge (0%) for businesses abroad', 'rinnovo-subscriptions-licenses' ),
					__( 'Evidence of location kept on each order', 'rinnovo-subscriptions-licenses' ),
				),
			),
			__( 'Ask for price and currencies', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'For quoted services and customers abroad.', 'rinnovo-subscriptions-licenses' ),
				array(
					__( '"Price on request" products with an "Ask for price" or WhatsApp button', 'rinnovo-subscriptions-licenses' ),
					__( 'A currency switcher with daily exchange rates and rounding', 'rinnovo-subscriptions-licenses' ),
					__( 'Orders, Stripe payments and renewals stay in the chosen currency', 'rinnovo-subscriptions-licenses' ),
				),
			),
		);
	}

	/**
	 * Pro plugins for one kind of business: name => what it is for.
	 *
	 * @return array
	 */
	private static function addons() {
		return array(
			'Rinnovo Physical Subscription Boxes & Goods Pro' => __( 'Boxes, coffee, pet food, cosmetics: skip, swap, shipping days and gift plans', 'rinnovo-subscriptions-licenses' ),
			'Rinnovo Membership Content Pro'                  => __( 'Paid content and courses: tiers, paywall, drip content and team seats', 'rinnovo-subscriptions-licenses' ),
			'Rinnovo Media Archives Pro'                      => __( 'Video, podcasts and music: expiring links, private feeds and plan quotas', 'rinnovo-subscriptions-licenses' ),
			'Rinnovo Service Contracts Pro'                   => __( 'Agencies and retainers: minimum terms, hour quotas and client requests', 'rinnovo-subscriptions-licenses' ),
			'Rinnovo Session Membership Pro'                  => __( 'Studios and coaching: credits, passes, recurring slots and waitlists', 'rinnovo-subscriptions-licenses' ),
			'Rinnovo Bundles & Variable Subscriptions Pro'    => __( 'Bundles, build-a-box and plan upgrades with proration', 'rinnovo-subscriptions-licenses' ),
		);
	}

	/**
	 * The screen.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$url = add_query_arg(
			array(
				'utm_source' => 'rinnovo-subscriptions-licenses',
				'utm_medium' => 'plugin',
			),
			self::URL
		);
		?>
		<div class="wrap rinnovo-pro">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Rinnovo Pro', 'rinnovo-subscriptions-licenses' ); ?></h1>
			<hr class="wp-header-end" />
			<div class="rinnovo-pro__hero">
				<span class="rinnovo-pro__badge"><?php esc_html_e( 'Rinnovo Pro', 'rinnovo-subscriptions-licenses' ); ?></span>
				<h2 class="rinnovo-pro__title"><?php esc_html_e( 'More for your subscription business', 'rinnovo-subscriptions-licenses' ); ?></h2>
				<p><?php esc_html_e( 'Everything in Rinnovo Subscriptions & Licenses keeps working as it is. The Pro plugins are separate add-ons for invoices, trials, tax, currencies and for one kind of business each.', 'rinnovo-subscriptions-licenses' ); ?></p>
				<p><a class="button button-hero rinnovo-pro__cta" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Rinnovo Pro', 'rinnovo-subscriptions-licenses' ); ?></a></p>
			</div>
			<h2><?php esc_html_e( 'Rinnovo Subscriptions & Licenses Pro', 'rinnovo-subscriptions-licenses' ); ?></h2>
			<div class="rinnovo-pro__grid">
				<?php foreach ( self::features() as $title => $group ) : ?>
					<div class="rinnovo-pro__card">
						<h3><?php echo esc_html( $title ); ?></h3>
						<p class="description"><?php echo esc_html( $group[0] ); ?></p>
						<ul class="rinnovo-pro__list">
							<?php foreach ( $group[1] as $item ) : ?>
								<li><?php echo esc_html( $item ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
			<h2><?php esc_html_e( 'Pro plugins for your business', 'rinnovo-subscriptions-licenses' ); ?></h2>
			<div class="rinnovo-pro__grid">
				<?php foreach ( self::addons() as $name => $what ) : ?>
					<div class="rinnovo-pro__card">
						<h3><?php echo esc_html( $name ); ?></h3>
						<p><?php echo esc_html( $what ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="rinnovo-pro__end"><a class="button button-primary button-hero" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See all Pro features and prices', 'rinnovo-subscriptions-licenses' ); ?></a></p>
		</div>
		<?php
	}
}
