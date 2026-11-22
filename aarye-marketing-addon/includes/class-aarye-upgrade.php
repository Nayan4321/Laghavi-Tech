<?php
/**
 * "Upgrade to Pro" screen: what Aarye Pro adds, with links to laghavi.site. Hidden once Pro is installed.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro introduction.
 */
class AARYE_Upgrade {

	const PAGE = 'aarye-leads-pro';
	const URL  = 'https://laghavi.site/aarye-marketing-addon/';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( self::has_pro() ) {
			return;
		}
		add_action( 'aarye_admin_menu', array( __CLASS__, 'menu' ), 100, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( AARYE_FILE ), array( __CLASS__, 'action_link' ), 20 );
	}

	/**
	 * Is Aarye Pro installed and active?
	 *
	 * @return bool
	 */
	public static function has_pro() {
		return defined( 'AARYEPRO_VERSION' );
	}

	/**
	 * Address of the Pro page on laghavi.site, tagged so the visit shows where it came from.
	 *
	 * @param string $place Where the link is (page, plans, plugins…).
	 * @param string $hash  Part of the page to open.
	 * @return string
	 */
	public static function url( $place = 'page', $hash = '' ) {
		$url = add_query_arg(
			array(
				'utm_source'   => 'aarye-free',
				'utm_medium'   => 'plugin',
				'utm_campaign' => 'upgrade',
				'utm_content'  => sanitize_key( $place ),
			),
			self::URL
		);
		return $url . ( '' !== $hash ? '#' . $hash : '' );
	}

	/**
	 * Menu item under Aarye Leads.
	 *
	 * @param string $parent_slug Parent slug.
	 * @param string $cap    Capability.
	 */
	public static function menu( $parent_slug, $cap ) {
		add_submenu_page(
			$parent_slug,
			__( 'Aarye Pro', 'aarye-marketing-addon' ),
			'<span style="color:#f59e0b;font-weight:600">' . esc_html__( 'Upgrade to Pro', 'aarye-marketing-addon' ) . '</span>',
			$cap,
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * "Get Pro" on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_link( $links ) {
		$links[] = '<a href="' . esc_url( self::url( 'plugins' ) ) . '" target="_blank" rel="noopener" style="color:#16a34a;font-weight:600">' . esc_html__( 'Get Pro', 'aarye-marketing-addon' ) . '</a>';
		return $links;
	}

	/**
	 * Pro features.
	 *
	 * @return array[]
	 */
	private static function features() {
		return array(
			array(
				'icon'  => 'dashicons-share-alt2',
				'title' => __( 'Send every lead to your CRM', 'aarye-marketing-addon' ),
				'text'  => __( 'Each lead, with its ad, campaign and keyword, reaches HubSpot, Zoho CRM, Airtable, Notion, your own database or any webhook (Zapier, Make, n8n) a few seconds after the form is sent. If a service is down, Aarye tries again for 7 days.', 'aarye-marketing-addon' ),
			),
			array(
				'icon'  => 'dashicons-phone',
				'title' => __( 'WhatsApp and call clicks', 'aarye-marketing-addon' ),
				'text'  => __( 'Every click on a WhatsApp, call, SMS or email button is saved with the ad or website that brought the visitor, even when they never send a form.', 'aarye-marketing-addon' ),
			),
			array(
				'icon'  => 'dashicons-chart-line',
				'title' => __( 'Form analytics', 'aarye-marketing-addon' ),
				'text'  => __( 'Views, starts, submissions and abandons for every form, the field where people give up, and conversion by source: do Meta visitors fill in your form as often as Google visitors?', 'aarye-marketing-addon' ),
			),
			array(
				'icon'  => 'dashicons-visibility',
				'title' => __( 'Pages viewed before the lead', 'aarye-marketing-addon' ),
				'text'  => __( 'On every lead: the pages the visitor read before getting in touch, how long they stayed on each, and their IP address.', 'aarye-marketing-addon' ),
			),
			array(
				'icon'  => 'dashicons-controls-repeat',
				'title' => __( 'Automations', 'aarye-marketing-addon' ),
				'text'  => __( 'Rules like "when the lead came from Meta Ads and the budget is 5k+: mark it Qualified, email sales and send it to HubSpot". Conditions on source, campaign, ad, form or any field.', 'aarye-marketing-addon' ),
			),
			array(
				'icon'  => 'dashicons-shield',
				'title' => __( 'Spam and bot protection', 'aarye-marketing-addon' ),
				'text'  => __( 'Bots, throwaway emails, link spam, blocked words, emails and IP addresses, and repeat senders are kept as spam, never sent to your CRM or counted as leads.', 'aarye-marketing-addon' ),
			),
			array(
				'icon'  => 'dashicons-money-alt',
				'title' => __( 'Cost per real lead', 'aarye-marketing-addon' ),
				'text'  => __( 'Connect Laghavi Ads Hub to compare what each ad network reports with the real leads you got, and see the cost per real lead for every campaign and ad.', 'aarye-marketing-addon' ),
			),
			array(
				'icon'  => 'dashicons-email-alt',
				'title' => __( 'Zoho Forms and email support', 'aarye-marketing-addon' ),
				'text'  => __( 'Zoho Forms embedded on your site get the ad source too. Pro comes with automatic updates and email support from the people who make Aarye.', 'aarye-marketing-addon' ),
			),
		);
	}

	/**
	 * Free vs Pro rows: label, free, pro.
	 *
	 * @return array[]
	 */
	private static function rows() {
		$yes = '&#10003;';
		return array(
			array( __( 'Source, ad, campaign and keyword of every lead', 'aarye-marketing-addon' ), $yes, $yes ),
			array( __( 'Source proof and spam check', 'aarye-marketing-addon' ), $yes, $yes ),
			array( __( 'Dashboard, CSV export and Google Sheets', 'aarye-marketing-addon' ), $yes, $yes ),
			array( __( 'Leads sent to your CRM, database or webhook', 'aarye-marketing-addon' ), '', $yes ),
			array( __( 'WhatsApp, call, SMS and email clicks', 'aarye-marketing-addon' ), '', $yes ),
			array( __( 'Form analytics and abandonment by field', 'aarye-marketing-addon' ), '', $yes ),
			array( __( 'Pages viewed and IP address on every lead', 'aarye-marketing-addon' ), '', $yes ),
			array( __( 'Automations: status, email and routing rules', 'aarye-marketing-addon' ), '', $yes ),
			array( __( 'Spam and bot protection', 'aarye-marketing-addon' ), __( 'Flagged', 'aarye-marketing-addon' ), __( 'Blocked', 'aarye-marketing-addon' ) ),
			array( __( 'Cost per real lead with Laghavi Ads Hub', 'aarye-marketing-addon' ), '', $yes ),
			array( __( 'Email support and automatic updates', 'aarye-marketing-addon' ), '', $yes ),
		);
	}

	/**
	 * The screen.
	 */
	public static function page() {
		if ( ! current_user_can( AARYE_Admin::cap() ) ) {
			return;
		}
		$plans = array(
			array( __( 'Personal', 'aarye-marketing-addon' ), __( '1 website', 'aarye-marketing-addon' ), '$59', false ),
			array( __( 'Business', 'aarye-marketing-addon' ), __( 'Up to 5 websites', 'aarye-marketing-addon' ), '$129', true ),
			array( __( 'Agency', 'aarye-marketing-addon' ), __( 'Unlimited websites', 'aarye-marketing-addon' ), '$249', false ),
		);
		?>
		<div class="wrap aarye aarye-pro">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Aarye Pro', 'aarye-marketing-addon' ); ?></h1>
			<hr class="wp-header-end" />
			<div class="aarye-pro__hero">
				<span class="aarye-pro__pill"><?php esc_html_e( 'Aarye Pro', 'aarye-marketing-addon' ); ?></span>
				<h2 class="aarye-pro__title"><?php esc_html_e( 'Put every lead to work', 'aarye-marketing-addon' ); ?></h2>
				<p><?php esc_html_e( 'The free plugin tells you which ad brought every lead. Pro sends those leads to your CRM, catches WhatsApp and call clicks, shows where your forms lose people, and acts on every new lead for you.', 'aarye-marketing-addon' ); ?></p>
				<p class="aarye-pro__ctas">
					<a class="button button-primary button-hero" href="<?php echo esc_url( self::url( 'hero', 'pro' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'aarye-marketing-addon' ); ?></a>
					<a class="button button-hero" href="<?php echo esc_url( self::url( 'hero' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more', 'aarye-marketing-addon' ); ?></a>
				</p>
			</div>

			<div class="aarye-pro__features">
				<?php foreach ( self::features() as $f ) : ?>
					<div class="aarye-pro__feature">
						<span class="dashicons <?php echo esc_attr( $f['icon'] ); ?>" aria-hidden="true"></span>
						<h2><?php echo esc_html( $f['title'] ); ?></h2>
						<p><?php echo esc_html( $f['text'] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="aarye-card">
				<h2><?php esc_html_e( 'Free and Pro', 'aarye-marketing-addon' ); ?></h2>
				<table class="widefat striped aarye-pro__table">
					<thead><tr><th></th><th><?php esc_html_e( 'Free', 'aarye-marketing-addon' ); ?></th><th><?php esc_html_e( 'Pro', 'aarye-marketing-addon' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( self::rows() as $r ) : ?>
							<tr>
								<td><?php echo esc_html( $r[0] ); ?></td>
								<td class="aarye-pro__mark"><?php echo '' !== $r[1] ? wp_kses_post( $r[1] ) : '&ndash;'; ?></td>
								<td class="aarye-pro__mark aarye-pro__mark--pro"><?php echo wp_kses_post( $r[2] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<h2 class="aarye-pro__h"><?php esc_html_e( 'Choose your plan', 'aarye-marketing-addon' ); ?></h2>
			<div class="aarye-pro__plans">
				<?php foreach ( $plans as $p ) : ?>
					<div class="aarye-pro__plan<?php echo $p[3] ? ' aarye-pro__plan--pop' : ''; ?>">
						<?php if ( $p[3] ) : ?>
							<span class="aarye-pro__tag"><?php esc_html_e( 'Most popular', 'aarye-marketing-addon' ); ?></span>
						<?php endif; ?>
						<h3><?php echo esc_html( $p[0] ); ?></h3>
						<div class="aarye-muted"><?php echo esc_html( $p[1] ); ?></div>
						<div class="aarye-pro__price"><?php echo esc_html( $p[2] ); ?><span> / <?php esc_html_e( 'year', 'aarye-marketing-addon' ); ?></span></div>
						<a class="button <?php echo $p[3] ? 'button-primary' : ''; ?>" href="<?php echo esc_url( self::url( 'plan-' . sanitize_title( $p[0] ), 'pro' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Pro', 'aarye-marketing-addon' ); ?></a>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="aarye-muted aarye-pro__fine"><?php esc_html_e( 'Prices in US dollars, billed yearly. Cancel any time: Pro keeps working until the end of the year you paid for. Your leads stay in WordPress either way.', 'aarye-marketing-addon' ); ?></p>
		</div>
		<?php
	}
}
