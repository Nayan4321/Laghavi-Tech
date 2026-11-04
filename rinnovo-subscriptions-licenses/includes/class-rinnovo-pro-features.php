<?php
/**
 * What Rinnovo Subscriptions & Licenses Pro adds, shown only while Pro is not installed: a "Pro
 * features" screen under WooCommerce and a link on the Plugins screen. Nothing in this plugin is
 * locked or limited; Pro is a separate plugin with its own code.
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro features screen and upgrade links.
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
		add_filter( 'plugin_action_links_' . plugin_basename( RINNOVO_FILE ), array( __CLASS__, 'action_link' ), 20 );
	}

	/**
	 * Address of the Pro page, with where the visitor came from.
	 *
	 * @param string $where Place of the link.
	 * @return string
	 */
	public static function url( $where = 'features' ) {
		return add_query_arg(
			array(
				'utm_source'   => 'rinnovo-subscriptions-licenses',
				'utm_medium'   => 'plugin',
				'utm_campaign' => sanitize_key( $where ),
			),
			self::URL
		);
	}

	/**
	 * "Rinnovo Pro" under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Rinnovo Subscriptions & Licenses Pro', 'rinnovo-subscriptions-licenses' ), __( 'Rinnovo Pro', 'rinnovo-subscriptions-licenses' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * "Go Pro" on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_link( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '" style="color:#16a34a;font-weight:700">' . esc_html__( 'Go Pro', 'rinnovo-subscriptions-licenses' ) . '</a>';
		return $links;
	}

	/**
	 * Pro features, grouped.
	 *
	 * @return array title => array( intro, items[] ).
	 */
	private static function features() {
		return array(
			__( 'Variable products', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'Sell one product in several plans, each at its own price.', 'rinnovo-subscriptions-licenses' ),
				array(
					__( 'Subscriptions on variations: Monthly and Yearly, each with its own period, trial, sign-up fee and number of payments', 'rinnovo-subscriptions-licenses' ),
					__( 'Licenses on variable products: 1 site, 5 sites, unlimited sites…', 'rinnovo-subscriptions-licenses' ),
					__( 'One License tab (plugin slug, zip, version) for the whole product', 'rinnovo-subscriptions-licenses' ),
					__( '"License: sites per key" on each plan, 0 for unlimited', 'rinnovo-subscriptions-licenses' ),
					__( 'Each plan can be its own monthly or yearly subscription', 'rinnovo-subscriptions-licenses' ),
				),
			),
			__( 'Ask for price', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'For custom work, enterprise plans and services you quote.', 'rinnovo-subscriptions-licenses' ),
				array(
					__( '"Price on request" instead of a price, also hidden from Google\'s product data', 'rinnovo-subscriptions-licenses' ),
					__( 'The product can\'t be bought online by mistake', 'rinnovo-subscriptions-licenses' ),
					__( '"Ask for price" button to your contact or booking page', 'rinnovo-subscriptions-licenses' ),
					__( '"Chat on WhatsApp" with a ready-written message naming the product', 'rinnovo-subscriptions-licenses' ),
				),
			),
			__( 'Currency switcher', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'Let customers pay in their own currency.', 'rinnovo-subscriptions-licenses' ),
				array(
					__( 'Add currencies (AED, EUR, GBP…) next to your store currency', 'rinnovo-subscriptions-licenses' ),
					__( 'Exchange rates updated daily or with one click, or typed by you', 'rinnovo-subscriptions-licenses' ),
					__( 'Decimals and price rounding per currency, and a currency per country on the first visit', 'rinnovo-subscriptions-licenses' ),
					__( 'A floating switcher, "Pay in USD · AED · EUR" on checkout and a shortcode', 'rinnovo-subscriptions-licenses' ),
					__( 'Prices, sign-up fees, coupons and shipping convert; orders, Stripe payments and every renewal stay in the chosen currency', 'rinnovo-subscriptions-licenses' ),
				),
			),
		);
	}

	/**
	 * The Pro features screen.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap rinnovo-pro">
			<style>
				.rinnovo-pro__hero{background:linear-gradient(135deg,#140f3a,#7c3aed 60%,#db2777);color:#fff;border-radius:16px;padding:32px 36px;margin:16px 0 24px}
				.rinnovo-pro__hero h1{color:#fff;font-size:30px;margin:12px 0 8px}
				.rinnovo-pro__hero p{font-size:15px;max-width:780px;color:#f1ecff}
				.rinnovo-pro__badge{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:5px 12px;border-radius:999px;background:rgba(255,255,255,.16)}
				.rinnovo-pro .rinnovo-pro__cta{background:#16a34a;border-color:#16a34a;color:#fff;font-weight:600}
				.rinnovo-pro .rinnovo-pro__cta:hover,.rinnovo-pro .rinnovo-pro__cta:focus{background:#15803d;border-color:#15803d;color:#fff}
				.rinnovo-pro__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
				.rinnovo-pro__card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px 24px}
				.rinnovo-pro__card h2{margin-top:0}
				.rinnovo-pro__list li{position:relative;padding-inline-start:22px;margin-bottom:8px}
				.rinnovo-pro__list li::before{content:"\2713";position:absolute;inset-inline-start:0;color:#16a34a;font-weight:700}
				.rinnovo-pro__plans{display:flex;gap:10px;flex-wrap:wrap;margin:6px 0 12px}
				.rinnovo-pro__plans span{border:1.5px solid #e6e8f0;border-radius:10px;padding:8px 14px;font-weight:600}
				.rinnovo-pro__plans span.is-on{border-color:#ea580c;color:#c2410c}
				.rinnovo-pro__end{margin-top:20px;text-align:center}
			</style>
			<div class="rinnovo-pro__hero">
				<span class="rinnovo-pro__badge"><?php esc_html_e( 'Rinnovo Subscriptions & Licenses Pro', 'rinnovo-subscriptions-licenses' ); ?></span>
				<h1><?php esc_html_e( 'Sell plans, quote prices and take every currency', 'rinnovo-subscriptions-licenses' ); ?></h1>
				<p><?php esc_html_e( 'Everything in Rinnovo Subscriptions & Licenses keeps working as it is. Pro is a separate add-on that adds subscriptions and licenses on variable products, "Ask for price" products and a currency switcher.', 'rinnovo-subscriptions-licenses' ); ?></p>
				<p><a class="button button-hero rinnovo-pro__cta" href="<?php echo esc_url( self::url( 'features-top' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Rinnovo Pro', 'rinnovo-subscriptions-licenses' ); ?></a></p>
			</div>
			<div class="rinnovo-pro__grid">
				<?php foreach ( self::features() as $title => $group ) : ?>
					<div class="rinnovo-pro__card">
						<h2><?php echo esc_html( $title ); ?></h2>
						<p class="description"><?php echo esc_html( $group[0] ); ?></p>
						<?php if ( __( 'Variable products', 'rinnovo-subscriptions-licenses' ) === $title ) : ?>
							<div class="rinnovo-pro__plans"><span><?php esc_html_e( '1 site', 'rinnovo-subscriptions-licenses' ); ?></span><span class="is-on"><?php esc_html_e( '5 sites', 'rinnovo-subscriptions-licenses' ); ?></span><span><?php esc_html_e( 'Unlimited', 'rinnovo-subscriptions-licenses' ); ?></span></div>
						<?php endif; ?>
						<ul class="rinnovo-pro__list">
							<?php foreach ( $group[1] as $item ) : ?>
								<li><?php echo esc_html( $item ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="rinnovo-pro__card rinnovo-pro__end">
				<h2><?php esc_html_e( 'Ready to grow your shop?', 'rinnovo-subscriptions-licenses' ); ?></h2>
				<p><?php esc_html_e( 'Install Pro next to this plugin: your products, subscriptions and license keys stay as they are.', 'rinnovo-subscriptions-licenses' ); ?></p>
				<p><a class="button button-hero rinnovo-pro__cta" href="<?php echo esc_url( self::url( 'features-bottom' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See plans and prices', 'rinnovo-subscriptions-licenses' ); ?></a></p>
			</div>
		</div>
		<?php
	}
}
