<?php
/**
 * Admin screens: overview, all entries, one entry, ad setup guide, forms, Google Sheets, settings.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class AARYE_Admin {

	const SLUG = 'aarye-leads';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_aarye_file', array( __CLASS__, 'download_file' ) );
		add_action( 'admin_post_aarye_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_aarye_entry', array( __CLASS__, 'entry_action' ) );
		add_action( 'admin_post_aarye_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_aarye_sheets', array( __CLASS__, 'save_sheets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AARYE_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Capability needed to see leads.
	 *
	 * @return string
	 */
	public static function cap() {
		/**
		 * Capability needed to see and manage leads.
		 *
		 * @param string $cap Capability.
		 */
		return (string) apply_filters( 'aarye_capability', 'manage_options' );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		$cap = self::cap();
		add_menu_page( __( 'Aarye Leads', 'aarye-marketing-addon-for-forms' ), __( 'Aarye Leads', 'aarye-marketing-addon-for-forms' ), $cap, self::SLUG, array( __CLASS__, 'page_overview' ), 'dashicons-chart-area', 26 );
		add_submenu_page( self::SLUG, __( 'Overview', 'aarye-marketing-addon-for-forms' ), __( 'Overview', 'aarye-marketing-addon-for-forms' ), $cap, self::SLUG, array( __CLASS__, 'page_overview' ) );
		add_submenu_page( self::SLUG, __( 'All entries', 'aarye-marketing-addon-for-forms' ), __( 'All entries', 'aarye-marketing-addon-for-forms' ), $cap, self::SLUG . '-entries', array( __CLASS__, 'page_entries' ) );
		add_submenu_page( self::SLUG, __( 'Connect your ads', 'aarye-marketing-addon-for-forms' ), __( 'Connect your ads', 'aarye-marketing-addon-for-forms' ), $cap, self::SLUG . '-guide', array( __CLASS__, 'page_guide' ) );
		add_submenu_page( self::SLUG, __( 'Forms', 'aarye-marketing-addon-for-forms' ), __( 'Forms', 'aarye-marketing-addon-for-forms' ), $cap, self::SLUG . '-forms', array( __CLASS__, 'page_forms' ) );
		add_submenu_page( self::SLUG, __( 'Google Sheets', 'aarye-marketing-addon-for-forms' ), __( 'Google Sheets', 'aarye-marketing-addon-for-forms' ), $cap, self::SLUG . '-sheets', array( __CLASS__, 'page_sheets' ) );
		/**
		 * Add more screens under Aarye Leads (Aarye Pro).
		 *
		 * @param string $parent Menu slug.
		 * @param string $cap    Capability.
		 */
		do_action( 'aarye_admin_menu', self::SLUG, $cap );
		add_submenu_page( self::SLUG, __( 'Settings', 'aarye-marketing-addon-for-forms' ), __( 'Settings', 'aarye-marketing-addon-for-forms' ), 'manage_options', self::SLUG . '-settings', array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * Links on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Leads', 'aarye-marketing-addon-for-forms' ) . '</a>' );
		return $links;
	}

	/**
	 * Styles and scripts on our screens.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'aarye-admin', AARYE_URL . 'assets/css/admin.css', array(), aarye_asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'aarye-admin', AARYE_URL . 'assets/js/admin.js', array(), aarye_asset_version( 'assets/js/admin.js' ), true );
		wp_localize_script( 'aarye-admin', 'aaryeAdmin', array( 'copied' => __( 'Copied', 'aarye-marketing-addon-for-forms' ) ) );
	}

	/**
	 * Address of one of our screens.
	 *
	 * @param string $sub  Sub-page ('' for overview).
	 * @param array  $args Query args.
	 * @return string
	 */
	public static function url( $sub = '', $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG . ( '' !== $sub ? '-' . $sub : '' ) ) );
	}

	/**
	 * Admin download link for a stored file.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $index    File index.
	 * @return string
	 */
	public static function file_url( $entry_id, $index ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'aarye_file',
					'entry'  => (int) $entry_id,
					'file'   => (int) $index,
				),
				admin_url( 'admin-post.php' )
			),
			'aarye_file_' . (int) $entry_id
		);
	}

	/**
	 * Filters from the request.
	 *
	 * @return array
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$f = array();
		foreach ( array( 'channel', 'source', 'campaign', 'form', 'lang', 'status', 's', 'from', 'to', 'range' ) as $key ) {
			$f[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}
		$f['bots'] = ! empty( $_GET['bots'] );
		// phpcs:enable
		if ( '' === $f['from'] && '' === $f['to'] ) {
			$days = in_array( $f['range'], array( '7', '30', '90', '365', 'all' ), true ) ? $f['range'] : '30';
			$f['range'] = $days;
			if ( 'all' !== $days ) {
				$f['from'] = wp_date( 'Y-m-d', time() - ( (int) $days - 1 ) * DAY_IN_SECONDS );
			}
		}
		return $f;
	}

	/**
	 * Coloured source badge for an entry.
	 *
	 * @param object $e Entry.
	 * @return string HTML.
	 */
	public static function badge( $e ) {
		$class = 'aarye-badge aarye-badge--' . sanitize_html_class( $e->channel );
		$title = $e->source_label;
		if ( 'paid' === $e->channel ) {
			$title = '★ ' . $title;
		}
		$out = '<span class="' . esc_attr( $class ) . '">' . esc_html( $title ) . '</span>';
		if ( ! $e->confirmed ) {
			$out .= ' <span class="aarye-hint" title="' . esc_attr__( 'Facebook and Instagram add fbclid to ads and normal posts alike. Add the URL parameters from "Connect your ads" to confirm ad clicks.', 'aarye-marketing-addon-for-forms' ) . '">' . esc_html__( 'ad or post?', 'aarye-marketing-addon-for-forms' ) . '</span>';
		}
		$bits = array_filter( array( $e->campaign, $e->ad_group, $e->ad ) );
		if ( $bits ) {
			$out .= '<span class="aarye-adline">' . esc_html( implode( ' › ', $bits ) ) . '</span>';
		}
		return $out;
	}

	/**
	 * Filter bar.
	 *
	 * @param array  $f    Filters.
	 * @param string $page Page slug.
	 */
	private static function filter_bar( $f, $page ) {
		$forms = AARYE_Integrations::all_forms();
		?>
		<form method="get" class="aarye-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
			<select name="range" onchange="this.form.from.value='';this.form.to.value='';this.form.submit()">
				<?php foreach ( array( '7' => __( 'Last 7 days', 'aarye-marketing-addon-for-forms' ), '30' => __( 'Last 30 days', 'aarye-marketing-addon-for-forms' ), '90' => __( 'Last 90 days', 'aarye-marketing-addon-for-forms' ), '365' => __( 'Last 12 months', 'aarye-marketing-addon-for-forms' ), 'all' => __( 'All time', 'aarye-marketing-addon-for-forms' ) ) as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['range'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>" aria-label="<?php esc_attr_e( 'From', 'aarye-marketing-addon-for-forms' ); ?>" />
			<input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>" aria-label="<?php esc_attr_e( 'To', 'aarye-marketing-addon-for-forms' ); ?>" />
			<select name="channel">
				<option value=""><?php esc_html_e( 'All sources', 'aarye-marketing-addon-for-forms' ); ?></option>
				<?php foreach ( AARYE_Sources::channels() as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['channel'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="source">
				<option value=""><?php esc_html_e( 'All ad networks', 'aarye-marketing-addon-for-forms' ); ?></option>
				<?php foreach ( AARYE_Sources::ad_networks() as $k => $net ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['source'], $k ); ?>><?php echo esc_html( $net['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="form">
				<option value=""><?php esc_html_e( 'All forms', 'aarye-marketing-addon-for-forms' ); ?></option>
				<?php foreach ( $forms as $form ) : ?>
					<option value="<?php echo esc_attr( $form['plugin'] . ':' . $form['id'] ); ?>" <?php selected( $f['form'], $form['plugin'] . ':' . $form['id'] ); ?>><?php echo esc_html( $form['plugin_name'] . ' – ' . $form['title'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<option value=""><?php esc_html_e( 'All leads (not spam)', 'aarye-marketing-addon-for-forms' ); ?></option>
				<?php foreach ( AARYE_Entries::statuses() as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $f['s'] ); ?>" placeholder="<?php esc_attr_e( 'Name, email, campaign…', 'aarye-marketing-addon-for-forms' ); ?>" />
			<?php if ( '' !== $f['campaign'] ) : ?>
				<input type="hidden" name="campaign" value="<?php echo esc_attr( $f['campaign'] ); ?>" />
			<?php endif; ?>
			<button class="button"><?php esc_html_e( 'Filter', 'aarye-marketing-addon-for-forms' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Horizontal bar list.
	 *
	 * @param string $title Title.
	 * @param array  $rows  From AARYE_Entries::grouped().
	 * @param string $link  Filter key to link each row to, or ''.
	 * @param string $class Extra class.
	 * @param string $empty Message when there are no rows.
	 */
	private static function bars( $title, $rows, $link = '', $class = '', $empty = '' ) {
		$max = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, $row['total'] );
		}
		?>
		<div class="aarye-card <?php echo esc_attr( $class ); ?>">
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php if ( ! $rows ) : ?>
				<p class="aarye-muted"><?php echo esc_html( '' !== $empty ? $empty : __( 'No entries yet.', 'aarye-marketing-addon-for-forms' ) ); ?></p>
			<?php else : ?>
				<ul class="aarye-bars">
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$label = '' !== $row['label'] ? $row['label'] : __( '(not set)', 'aarye-marketing-addon-for-forms' );
						$url   = '' !== $link && '' !== $row['key'] ? self::url( 'entries', array( $link => $row['key'] ) ) : '';
						?>
						<li>
							<span class="aarye-bars__label"><?php echo $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label ); ?></span>
							<span class="aarye-bars__bar"><i style="width:<?php echo esc_attr( (string) ( $max ? round( 100 * $row['total'] / $max ) : 0 ) ); ?>%"></i></span>
							<b><?php echo esc_html( number_format_i18n( $row['total'] ) ); ?></b>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Overview.
	 */
	public static function page_overview() {
		$f        = self::filters();
		$all      = AARYE_Entries::query( $f, 1 );
		$paid_f   = array_merge( $f, array( 'channel' => 'paid' ) );
		$paid     = AARYE_Entries::query( $paid_f, 1 );
		$networks = AARYE_Entries::grouped( 'source', $paid_f, 12 );
		foreach ( $networks as &$row ) {
			$nets         = AARYE_Sources::ad_networks();
			$row['label'] = isset( $nets[ $row['key'] ] ) ? $nets[ $row['key'] ]['name'] : $row['key'];
		}
		unset( $row );
		$top_campaign = AARYE_Entries::grouped( 'campaign', $paid_f, 1 );
		$share        = $all['total'] ? round( 100 * $paid['total'] / $all['total'] ) : 0;
		?>
		<div class="wrap aarye">
			<h1><?php esc_html_e( 'Leads overview', 'aarye-marketing-addon-for-forms' ); ?></h1>
			<?php self::filter_bar( $f, self::SLUG ); ?>
			<?php if ( ! AARYE_Integrations::active() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'No supported form plugin is active yet. Aarye works with Contact Form 7, WPForms, Elementor Pro Forms, Fluent Forms, Gravity Forms, Forminator, Ninja Forms, Formidable Forms, JetFormBuilder and MetForm.', 'aarye-marketing-addon-for-forms' ); ?></p></div>
			<?php endif; ?>
			<div class="aarye-kpis">
				<div class="aarye-kpi"><b><?php echo esc_html( number_format_i18n( $all['total'] ) ); ?></b><span><?php esc_html_e( 'Leads', 'aarye-marketing-addon-for-forms' ); ?></span></div>
				<div class="aarye-kpi aarye-kpi--paid"><b><?php echo esc_html( number_format_i18n( $paid['total'] ) ); ?></b><span><?php /* translators: %d: percentage. */ echo esc_html( sprintf( __( 'From ads (%d%%)', 'aarye-marketing-addon-for-forms' ), $share ) ); ?></span></div>
				<div class="aarye-kpi"><b><?php echo esc_html( $networks ? $networks[0]['label'] : '—' ); ?></b><span><?php esc_html_e( 'Top ad network', 'aarye-marketing-addon-for-forms' ); ?></span></div>
				<div class="aarye-kpi"><b class="aarye-kpi__text"><?php echo esc_html( $top_campaign && '' !== $top_campaign[0]['label'] ? $top_campaign[0]['label'] : '—' ); ?></b><span><?php esc_html_e( 'Top campaign', 'aarye-marketing-addon-for-forms' ); ?></span></div>
			</div>
			<div class="aarye-grid">
				<?php
				self::bars( __( 'Leads by source', 'aarye-marketing-addon-for-forms' ), AARYE_Entries::grouped( 'channel', $f ), 'channel' );
				self::bars( __( 'Leads from ads, by ad network', 'aarye-marketing-addon-for-forms' ), $networks, 'source', 'aarye-card--paid' );
				self::bars( __( 'Ad campaigns', 'aarye-marketing-addon-for-forms' ), AARYE_Entries::grouped( 'campaign', $paid_f ), 'campaign' );
				$ads = array_values(
					array_filter(
						AARYE_Entries::grouped( 'ad', $paid_f ),
						static function ( $row ) {
							return '' !== $row['key'];
						}
					)
				);
				self::bars( __( 'Ads', 'aarye-marketing-addon-for-forms' ), $ads, '', '', $paid['total'] ? __( 'Add utm_content (the ad name) to your ad links to see which ad brought each lead. See "Connect your ads".', 'aarye-marketing-addon-for-forms' ) : '' );
				self::bars( __( 'Search, social, AI and other websites', 'aarye-marketing-addon-for-forms' ), AARYE_Entries::grouped( 'source_label', array_merge( $f, array( 'channel' => '', 'not_channel' => 'paid' ) ), 12 ) );
				self::bars( __( 'Forms', 'aarye-marketing-addon-for-forms' ), AARYE_Entries::grouped( 'form_name', $f ) );
				self::bars( __( 'Languages', 'aarye-marketing-addon-for-forms' ), AARYE_Entries::grouped( 'language', $f ), 'lang' );
				self::bars( __( 'Devices', 'aarye-marketing-addon-for-forms' ), AARYE_Entries::grouped( 'device', $f ) );
				/**
				 * More cards on the overview (Aarye Pro).
				 *
				 * @param array $f Filters.
				 */
				do_action( 'aarye_overview_cards', $f );
				?>
			</div>
			<p><a class="button button-primary" href="<?php echo esc_url( self::url( 'entries', array_filter( $f ) ) ); ?>"><?php esc_html_e( 'See all entries', 'aarye-marketing-addon-for-forms' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * All entries, or one entry.
	 */
	public static function page_entries() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Viewing.
		$id = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		if ( $id ) {
			self::page_entry( $id );
			return;
		}
		$f      = self::filters();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Paging.
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result = AARYE_Entries::query( $f, 50, ( $paged - 1 ) * 50 );
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'aarye_export' ), array_filter( $f ) ), admin_url( 'admin-post.php' ) ), 'aarye_export' );
		$plugins = AARYE_Integrations::plugins();
		?>
		<div class="wrap aarye">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'All entries', 'aarye-marketing-addon-for-forms' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'aarye-marketing-addon-for-forms' ); ?></a>
			<?php self::filter_bar( $f, self::SLUG . '-entries' ); ?>
			<p class="aarye-muted">
				<?php
				/* translators: %s: number of entries. */
				echo esc_html( sprintf( _n( '%s entry', '%s entries', $result['total'], 'aarye-marketing-addon-for-forms' ), number_format_i18n( $result['total'] ) ) );
				if ( '' !== $f['campaign'] ) {
					/* translators: %s: campaign name. */
					echo ' · ' . esc_html( sprintf( __( 'Campaign: %s', 'aarye-marketing-addon-for-forms' ), $f['campaign'] ) );
				}
				?>
			</p>
			<table class="widefat striped aarye-table">
				<thead><tr>
					<th><?php esc_html_e( 'Date', 'aarye-marketing-addon-for-forms' ); ?></th>
					<th><?php esc_html_e( 'Contact', 'aarye-marketing-addon-for-forms' ); ?></th>
					<th><?php esc_html_e( 'Came from', 'aarye-marketing-addon-for-forms' ); ?></th>
					<th><?php esc_html_e( 'Form', 'aarye-marketing-addon-for-forms' ); ?></th>
					<th><?php esc_html_e( 'Language', 'aarye-marketing-addon-for-forms' ); ?></th>
					<th><?php esc_html_e( 'Status', 'aarye-marketing-addon-for-forms' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $result['rows'] ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No entries match. New form entries appear here as soon as they are sent.', 'aarye-marketing-addon-for-forms' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $result['rows'] as $e ) : ?>
					<?php $files = AARYE_Entries::json( $e, 'files' ); ?>
					<tr>
						<td><a href="<?php echo esc_url( self::url( 'entries', array( 'entry' => $e->id ) ) ); ?>"><?php echo esc_html( get_date_from_gmt( $e->created_at, get_option( 'date_format' ) . ' H:i' ) ); ?></a></td>
						<td>
							<strong><?php echo esc_html( '' !== $e->name ? $e->name : ( '' !== $e->email ? $e->email : '#' . $e->id ) ); ?></strong>
							<?php if ( '' !== $e->email && '' !== $e->name ) : ?><br /><span class="aarye-muted"><?php echo esc_html( $e->email ); ?></span><?php endif; ?>
							<?php if ( '' !== $e->phone ) : ?><br /><span class="aarye-muted"><?php echo esc_html( $e->phone ); ?></span><?php endif; ?>
							<?php if ( $files ) : ?><br /><span class="aarye-muted"><span class="dashicons dashicons-paperclip" aria-label="<?php esc_attr_e( 'Attachments', 'aarye-marketing-addon-for-forms' ); ?>"></span> <?php echo esc_html( (string) count( $files ) ); ?></span><?php endif; ?>
							<?php if ( $e->is_bot ) : ?><span class="aarye-badge aarye-badge--bot"><?php esc_html_e( 'Bot?', 'aarye-marketing-addon-for-forms' ); ?></span><?php endif; ?>
						</td>
						<td><?php echo wp_kses_post( self::badge( $e ) ); ?></td>
						<td><?php echo esc_html( $e->form_name ); ?><br /><span class="aarye-muted"><?php echo esc_html( isset( $plugins[ $e->form_plugin ] ) ? $plugins[ $e->form_plugin ]['name'] : $e->form_plugin ); ?></span></td>
						<td><?php echo esc_html( strtoupper( $e->language ) ); ?></td>
						<td><?php $statuses = AARYE_Entries::statuses(); echo esc_html( isset( $statuses[ $e->status ] ) ? $statuses[ $e->status ] : $e->status ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $result['total'] / 50 );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				) . '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * One entry.
	 *
	 * @param int $id Entry ID.
	 */
	private static function page_entry( $id ) {
		$e = AARYE_Entries::get( $id );
		if ( ! $e ) {
			echo '<div class="wrap"><p>' . esc_html__( 'This entry no longer exists.', 'aarye-marketing-addon-for-forms' ) . '</p></div>';
			return;
		}
		$fields  = AARYE_Entries::json( $e, 'fields' );
		$files   = AARYE_Entries::json( $e, 'files' );
		$attr    = AARYE_Entries::json( $e, 'attribution' );
		$first   = isset( $attr['first_source'] ) ? $attr['first_source'] : null;
		$plugins = AARYE_Integrations::plugins();
		$days    = $e->first_seen ? max( 0, (int) floor( ( strtotime( $e->created_at ) - strtotime( $e->first_seen ) ) / DAY_IN_SECONDS ) ) : null;
		$back    = wp_get_referer() ? wp_get_referer() : self::url( 'entries' );
		?>
		<div class="wrap aarye">
			<p><a href="<?php echo esc_url( $back ); ?>">← <?php esc_html_e( 'All entries', 'aarye-marketing-addon-for-forms' ); ?></a></p>
			<h1><?php echo esc_html( '' !== $e->name ? $e->name : ( '' !== $e->email ? $e->email : '#' . $e->id ) ); ?></h1>
			<p class="aarye-entry-source"><?php echo wp_kses_post( self::badge( $e ) ); ?></p>
			<div class="aarye-grid aarye-grid--entry">
				<div class="aarye-card">
					<h2><?php esc_html_e( 'Form entry', 'aarye-marketing-addon-for-forms' ); ?></h2>
					<table class="aarye-details">
						<?php foreach ( $fields as $field ) : ?>
							<tr><th><?php echo esc_html( '' !== $field['label'] ? $field['label'] : $field['name'] ); ?></th><td><?php echo nl2br( esc_html( $field['value'] ) ); ?></td></tr>
						<?php endforeach; ?>
					</table>
					<?php if ( $files ) : ?>
						<h3><?php esc_html_e( 'Attachments', 'aarye-marketing-addon-for-forms' ); ?></h3>
						<ul class="aarye-files">
							<?php foreach ( $files as $i => $file ) : ?>
								<li><span class="dashicons dashicons-paperclip" aria-hidden="true"></span> <a href="<?php echo esc_url( '' !== $file['stored'] ? self::file_url( $e->id, $i ) : $file['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $file['name'] ); ?></a><?php echo '' !== $file['label'] ? ' <span class="aarye-muted">(' . esc_html( $file['label'] ) . ')</span>' : ''; ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<p class="aarye-muted">
						<?php
						echo esc_html( $e->form_name . ' · ' . ( isset( $plugins[ $e->form_plugin ] ) ? $plugins[ $e->form_plugin ]['name'] : $e->form_plugin ) . ' · ' . get_date_from_gmt( $e->created_at, get_option( 'date_format' ) . ' H:i' ) );
						$link = AARYE_Integrations::entry_link( $e );
						if ( $link ) {
							echo ' · <a href="' . esc_url( $link ) . '">' . esc_html__( 'Open in the form plugin', 'aarye-marketing-addon-for-forms' ) . '</a>';
						}
						?>
					</p>
				</div>
				<div class="aarye-card">
					<h2><?php esc_html_e( 'Where this lead came from', 'aarye-marketing-addon-for-forms' ); ?></h2>
					<table class="aarye-details">
						<tr><th><?php esc_html_e( 'Source', 'aarye-marketing-addon-for-forms' ); ?></th><td><?php echo esc_html( $e->source_label . ' · ' . AARYE_Sources::channel_label( $e->channel ) ); ?></td></tr>
						<?php
						$rows = array(
							__( 'Campaign', 'aarye-marketing-addon-for-forms' )          => $e->campaign,
							__( 'Ad set / ad group', 'aarye-marketing-addon-for-forms' ) => $e->ad_group,
							__( 'Ad', 'aarye-marketing-addon-for-forms' )                => $e->ad,
							__( 'Keyword', 'aarye-marketing-addon-for-forms' )           => $e->keyword,
							__( 'Click ID', 'aarye-marketing-addon-for-forms' )          => '' !== $e->click_id ? $e->click_param . ' = ' . $e->click_id : '',
						);
						foreach ( $rows as $label => $value ) {
							if ( '' !== (string) $value ) {
								echo '<tr><th>' . esc_html( $label ) . '</th><td class="aarye-break">' . esc_html( $value ) . '</td></tr>';
							}
						}
						?>
						<tr><th><?php esc_html_e( 'Landing page', 'aarye-marketing-addon-for-forms' ); ?></th><td class="aarye-break"><?php echo esc_html( (string) $e->landing_page ); ?></td></tr>
						<?php if ( '' !== (string) $e->referrer ) : ?><tr><th><?php esc_html_e( 'Referrer', 'aarye-marketing-addon-for-forms' ); ?></th><td class="aarye-break"><?php echo esc_html( $e->referrer ); ?></td></tr><?php endif; ?>
						<tr><th><?php esc_html_e( 'Form sent from', 'aarye-marketing-addon-for-forms' ); ?></th><td class="aarye-break"><?php echo esc_html( (string) $e->page_url ); ?></td></tr>
						<?php if ( $first && ( $first['label'] !== $e->source_label || $first['campaign'] !== $e->campaign ) ) : ?>
							<tr><th><?php esc_html_e( 'First visit came from', 'aarye-marketing-addon-for-forms' ); ?></th><td><?php echo esc_html( $first['label'] . ( '' !== $first['campaign'] ? ' · ' . $first['campaign'] : '' ) ); ?></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Visits before sending', 'aarye-marketing-addon-for-forms' ); ?></th><td><?php echo esc_html( (string) $e->visits ); ?><?php echo null !== $days ? esc_html( ' · ' . sprintf( /* translators: %d: days. */ _n( '%d day after the first visit', '%d days after the first visit', $days, 'aarye-marketing-addon-for-forms' ), $days ) ) : ''; ?></td></tr>
						<tr><th><?php esc_html_e( 'Device', 'aarye-marketing-addon-for-forms' ); ?></th><td><?php echo esc_html( ucfirst( $e->device ) . ( '' !== $e->browser ? ' · ' . $e->browser : '' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Language', 'aarye-marketing-addon-for-forms' ); ?></th><td><?php echo esc_html( strtoupper( $e->language ) ); ?></td></tr>
						<?php if ( '' !== $e->ip ) : ?><tr><th><?php esc_html_e( 'IP address', 'aarye-marketing-addon-for-forms' ); ?></th><td><?php echo esc_html( $e->ip ); ?></td></tr><?php endif; ?>
					</table>
					<?php
					$params = isset( $attr['last']['params'] ) && is_array( $attr['last']['params'] ) ? $attr['last']['params'] : array();
					if ( $params ) :
						?>
						<details><summary><?php esc_html_e( 'All tracking parameters', 'aarye-marketing-addon-for-forms' ); ?></summary>
							<table class="aarye-details aarye-details--small">
								<?php foreach ( $params as $k => $v ) : ?>
									<tr><th><code><?php echo esc_html( $k ); ?></code></th><td class="aarye-break"><?php echo esc_html( $v ); ?></td></tr>
								<?php endforeach; ?>
							</table>
						</details>
					<?php endif; ?>
				</div>
				<?php
				/**
				 * More cards on an entry (Aarye Pro: pages viewed, deliveries).
				 *
				 * @param object $e Entry.
				 */
				do_action( 'aarye_entry_cards', $e );
				?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aarye-entry-actions">
				<input type="hidden" name="action" value="aarye_entry" />
				<input type="hidden" name="entry" value="<?php echo esc_attr( (string) $e->id ); ?>" />
				<?php wp_nonce_field( 'aarye_entry_' . $e->id ); ?>
				<label><?php esc_html_e( 'Lead status', 'aarye-marketing-addon-for-forms' ); ?>
					<select name="status">
						<?php foreach ( AARYE_Entries::statuses() as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $e->status, $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-primary" name="todo" value="status"><?php esc_html_e( 'Save', 'aarye-marketing-addon-for-forms' ); ?></button>
				<button type="submit" class="button aarye-danger" name="todo" value="delete" data-confirm="<?php esc_attr_e( 'Delete this entry and its files?', 'aarye-marketing-addon-for-forms' ); ?>"><?php esc_html_e( 'Delete', 'aarye-marketing-addon-for-forms' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Status change or delete.
	 */
	public static function entry_action() {
		$id = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0;
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'aarye_entry_' . $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms' ) );
		}
		$todo = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';
		if ( 'delete' === $todo ) {
			AARYE_Entries::delete( $id );
			wp_safe_redirect( self::url( 'entries' ) );
			exit;
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( isset( AARYE_Entries::statuses()[ $status ] ) ) {
			AARYE_Entries::set_status( $id, $status );
			/**
			 * A lead's status changed.
			 *
			 * @param int    $id     Entry ID.
			 * @param string $status New status.
			 */
			do_action( 'aarye_entry_status_changed', $id, $status );
		}
		wp_safe_redirect( self::url( 'entries', array( 'entry' => $id ) ) );
		exit;
	}

	/**
	 * Serve a stored attachment to an admin.
	 */
	public static function download_file() {
		$id = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'aarye_file_' . $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms' ) );
		}
		$i     = isset( $_GET['file'] ) ? absint( $_GET['file'] ) : 0;
		$entry = AARYE_Entries::get( $id );
		$files = $entry ? AARYE_Entries::json( $entry, 'files' ) : array();
		if ( empty( $files[ $i ]['stored'] ) ) {
			wp_die( esc_html__( 'File not found.', 'aarye-marketing-addon-for-forms' ) );
		}
		$base = realpath( AARYE_Entries::files_dir() );
		$path = realpath( AARYE_Entries::files_dir() . $files[ $i ]['stored'] );
		if ( ! $path || ! $base || 0 !== strpos( $path, $base ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'aarye-marketing-addon-for-forms' ) );
		}
		$type = wp_check_filetype( $path );
		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $files[ $i ]['name'] ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a private file to an admin.
		exit;
	}

	/**
	 * CSV export of the filtered entries, one column per form field.
	 */
	public static function export() {
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'aarye_export' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms' ) );
		}
		$result = AARYE_Entries::query( self::filters(), 10000 );
		$rows   = array();
		$heads  = array();
		foreach ( $result['rows'] as $e ) {
			$row = AARYE_Sheets::row( $e );
			foreach ( array_keys( $row ) as $h ) {
				$heads[ $h ] = true;
			}
			$rows[] = $row;
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="leads-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so Excel shows Arabic, Russian etc. correctly.
		fputcsv( $out, array_keys( $heads ) );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( array_keys( $heads ) as $h ) {
				$value = isset( $row[ $h ] ) ? (string) $row[ $h ] : '';
				// Excel shows the guard apostrophe, so drop it for plain numbers such as +971 50 123 4567.
				if ( preg_match( '/^\'[+-][\d\s().-]+$/', $value ) ) {
					$value = substr( $value, 1 );
				}
				$line[] = $value;
			}
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Ad setup guide, with a link tester.
	 */
	public static function page_guide() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tester.
		$test = isset( $_GET['test_url'] ) ? esc_url_raw( wp_unslash( $_GET['test_url'] ) ) : '';
		?>
		<div class="wrap aarye">
			<h1><?php esc_html_e( 'Connect your ads', 'aarye-marketing-addon-for-forms' ); ?></h1>
			<p class="aarye-lead"><?php esc_html_e( 'Aarye recognises ad clicks from the tags ad networks add to your links. Google, Microsoft, TikTok, LinkedIn, X, Snapchat, Pinterest and Reddit add a click ID by themselves. Add the URL parameters below once per ad account to also see the campaign, ad set and ad of every lead. Nothing to install in your ad accounts and no passwords needed.', 'aarye-marketing-addon-for-forms' ); ?></p>

			<div class="aarye-card">
				<h2><?php esc_html_e( 'Test a link', 'aarye-marketing-addon-for-forms' ); ?></h2>
				<p class="aarye-muted"><?php esc_html_e( 'Paste the address an ad opens (copy it from the ad preview, or click your ad and copy the address bar) to see how its leads will be shown.', 'aarye-marketing-addon-for-forms' ); ?></p>
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-guide' ); ?>" />
					<input type="url" name="test_url" class="large-text" value="<?php echo esc_attr( $test ); ?>" placeholder="https://example.com/?utm_source=facebook&utm_medium=paid&utm_campaign=..." />
					<p><button class="button button-primary"><?php esc_html_e( 'Test', 'aarye-marketing-addon-for-forms' ); ?></button></p>
				</form>
				<?php
				if ( '' !== $test ) {
					parse_str( (string) wp_parse_url( $test, PHP_URL_QUERY ), $params );
					$result = AARYE_Sources::classify( array( 'params' => $params ) );
					$fake   = (object) array(
						'channel'      => $result['channel'],
						'source_label' => $result['label'],
						'confirmed'    => $result['confirmed'],
						'campaign'     => $result['campaign'],
						'ad_group'     => $result['ad_group'],
						'ad'           => $result['ad'],
					);
					echo '<div class="aarye-test-result"><p>' . wp_kses_post( self::badge( $fake ) ) . '</p><table class="aarye-details">';
					foreach ( array( __( 'Counted as an ad', 'aarye-marketing-addon-for-forms' ) => 'paid' === $result['channel'] ? __( 'Yes', 'aarye-marketing-addon-for-forms' ) : __( 'No', 'aarye-marketing-addon-for-forms' ), __( 'Campaign', 'aarye-marketing-addon-for-forms' ) => $result['campaign'], __( 'Ad set / ad group', 'aarye-marketing-addon-for-forms' ) => $result['ad_group'], __( 'Ad', 'aarye-marketing-addon-for-forms' ) => $result['ad'], __( 'Keyword', 'aarye-marketing-addon-for-forms' ) => $result['keyword'] ) as $label => $value ) {
						echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( '' !== $value ? $value : '—' ) . '</td></tr>';
					}
					echo '</table>';
					if ( 'direct' === $result['channel'] ) {
						echo '<p>' . esc_html__( 'This link has no tracking tags: leads from it look like direct visits. Add the URL parameters for your ad network below.', 'aarye-marketing-addon-for-forms' ) . '</p>';
					}
					echo '</div>';
				}
				?>
			</div>

			<?php foreach ( AARYE_Guide::platforms() as $key => $p ) : ?>
				<details class="aarye-card aarye-guide" <?php echo in_array( $key, array( 'google_ads', 'meta_ads' ), true ) ? 'open' : ''; ?>>
					<summary><h2><?php echo esc_html( $p['name'] ); ?></h2></summary>
					<p><?php echo esc_html( $p['auto'] ); ?></p>
					<ol>
						<?php foreach ( $p['where'] as $step ) : ?>
							<li><?php echo esc_html( $step ); ?></li>
						<?php endforeach; ?>
					</ol>
					<div class="aarye-copy">
						<code><?php echo esc_html( $p['template'] ); ?></code>
						<button type="button" class="button aarye-copy__btn" data-copy="<?php echo esc_attr( $p['template'] ); ?>"><?php esc_html_e( 'Copy', 'aarye-marketing-addon-for-forms' ); ?></button>
					</div>
					<?php if ( '' !== $p['note'] ) : ?>
						<p class="aarye-muted"><?php echo esc_html( $p['note'] ); ?></p>
					<?php endif; ?>
				</details>
			<?php endforeach; ?>

			<div class="aarye-card">
				<h2><?php esc_html_e( 'Every ad network Aarye recognises', 'aarye-marketing-addon-for-forms' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Ad network', 'aarye-marketing-addon-for-forms' ); ?></th><th><?php esc_html_e( 'Covers', 'aarye-marketing-addon-for-forms' ); ?></th><th><?php esc_html_e( 'Recognised by', 'aarye-marketing-addon-for-forms' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( AARYE_Sources::ad_networks() as $net ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $net['name'] ); ?></strong></td>
							<td><?php echo esc_html( $net['covers'] ); ?></td>
							<td>
								<?php
								$how = array();
								if ( $net['click'] ) {
									/* translators: %s: parameter names. */
									$how[] = sprintf( __( 'click ID %s (automatic)', 'aarye-marketing-addon-for-forms' ), implode( ', ', $net['click'] ) );
								}
								/* translators: %s: utm_source values. */
								$how[] = sprintf( __( 'utm_source = %s with a paid utm_medium', 'aarye-marketing-addon-for-forms' ), implode( ', ', array_slice( $net['utm'], 0, 4 ) ) );
								echo esc_html( implode( ' · ', $how ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="aarye-muted"><?php esc_html_e( 'Also recognised without ads: search engines (Google, Bing, Yahoo, DuckDuckGo, Baidu, Yandex…), social networks, AI assistants (ChatGPT, Perplexity, Gemini, Copilot, Claude…), email, other websites and direct visits.', 'aarye-marketing-addon-for-forms' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Detected form plugins and forms.
	 */
	public static function page_forms() {
		global $wpdb;
		$counts = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT form_plugin, form_id, COUNT(*) AS n, MAX(created_at) AS last FROM %i GROUP BY form_plugin, form_id', AARYE_Entries::table() ) ) as $row ) {
			$counts[ $row->form_plugin . ':' . $row->form_id ] = $row;
		}
		?>
		<div class="wrap aarye">
			<h1><?php esc_html_e( 'Forms', 'aarye-marketing-addon-for-forms' ); ?></h1>
			<p class="aarye-lead"><?php esc_html_e( 'Every form of these plugins is captured automatically: no setup per form. Entries are saved in Aarye even if the form plugin doesn\'t keep entries itself.', 'aarye-marketing-addon-for-forms' ); ?></p>
			<?php foreach ( AARYE_Integrations::plugins() as $key => $plugin ) : ?>
				<?php $active = AARYE_Integrations::is_active( $key ); ?>
				<div class="aarye-card aarye-plugin <?php echo $active ? '' : 'aarye-plugin--off'; ?>">
					<h2><?php echo esc_html( $plugin['name'] ); ?> <span class="aarye-badge aarye-badge--<?php echo $active ? 'ok' : 'off'; ?>"><?php echo $active ? esc_html__( 'Connected', 'aarye-marketing-addon-for-forms' ) : esc_html__( 'Not installed', 'aarye-marketing-addon-for-forms' ); ?></span></h2>
					<?php if ( $active ) : ?>
						<?php $forms = AARYE_Integrations::forms( $key ); ?>
						<?php if ( ! $forms ) : ?>
							<p class="aarye-muted"><?php esc_html_e( 'No forms found yet.', 'aarye-marketing-addon-for-forms' ); ?></p>
						<?php else : ?>
							<table class="widefat striped"><tbody>
								<?php foreach ( $forms as $form ) : ?>
									<?php $c = isset( $counts[ $key . ':' . $form['id'] ] ) ? $counts[ $key . ':' . $form['id'] ] : null; ?>
									<tr>
										<td><?php echo esc_html( $form['title'] ); ?></td>
										<td><?php echo $c ? '<a href="' . esc_url( self::url( 'entries', array( 'form' => $key . ':' . $form['id'], 'range' => 'all' ) ) ) . '">' . esc_html( sprintf( /* translators: %s: number. */ _n( '%s entry', '%s entries', (int) $c->n, 'aarye-marketing-addon-for-forms' ), number_format_i18n( (int) $c->n ) ) ) . '</a>' : '<span class="aarye-muted">' . esc_html__( 'No entries yet', 'aarye-marketing-addon-for-forms' ) . '</span>'; ?></td>
										<td class="aarye-muted"><?php echo $c ? esc_html( sprintf( /* translators: %s: date. */ __( 'Last: %s', 'aarye-marketing-addon-for-forms' ), get_date_from_gmt( $c->last, get_option( 'date_format' ) ) ) ) : ''; ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody></table>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Google Sheets setup.
	 */
	public static function page_sheets() {
		if ( '' === (string) AARYE_Settings::get( 'sheets_secret' ) ) {
			AARYE_Settings::update( array( 'sheets_secret' => wp_generate_password( 32, false, false ) ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Message after redirect.
		$msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
		?>
		<div class="wrap aarye">
			<h1><?php esc_html_e( 'Google Sheets', 'aarye-marketing-addon-for-forms' ); ?></h1>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice <?php echo 'ok' === $msg ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html( 'ok' === $msg ? __( 'Connected: a test reached your spreadsheet. New entries will appear there as rows.', 'aarye-marketing-addon-for-forms' ) : $msg ); ?></p></div>
			<?php endif; ?>
			<p class="aarye-lead"><?php esc_html_e( 'Every new entry is added to your spreadsheet as a row with its source, campaign and ad, and every form field in its own column. Your Google account stays with you: WordPress only gets a private web address of your sheet.', 'aarye-marketing-addon-for-forms' ); ?></p>
			<div class="aarye-card">
				<ol class="aarye-steps">
					<li><?php echo wp_kses_post( __( 'Create a Google Sheet (or open one) and choose <strong>Extensions → Apps Script</strong>.', 'aarye-marketing-addon-for-forms' ) ); ?></li>
					<li><?php esc_html_e( 'Delete what is in the editor, paste this script and click the save icon:', 'aarye-marketing-addon-for-forms' ); ?>
						<div class="aarye-copy aarye-copy--block"><pre><?php echo esc_html( AARYE_Sheets::script() ); ?></pre><button type="button" class="button aarye-copy__btn" data-copy="<?php echo esc_attr( AARYE_Sheets::script() ); ?>"><?php esc_html_e( 'Copy script', 'aarye-marketing-addon-for-forms' ); ?></button></div>
					</li>
					<li><?php echo wp_kses_post( __( 'Click <strong>Deploy → New deployment</strong>, choose type <strong>Web app</strong>, set "Execute as" to <strong>Me</strong> and "Who has access" to <strong>Anyone</strong>, then <strong>Deploy</strong> and allow access.', 'aarye-marketing-addon-for-forms' ) ); ?></li>
					<li><?php esc_html_e( 'Copy the Web app URL (it ends in /exec), paste it below and save. A test is sent right away.', 'aarye-marketing-addon-for-forms' ); ?></li>
				</ol>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="aarye_sheets" />
					<?php wp_nonce_field( 'aarye_sheets' ); ?>
					<p><input type="url" name="sheets_url" class="large-text" value="<?php echo esc_attr( (string) AARYE_Settings::get( 'sheets_url' ) ); ?>" placeholder="https://script.google.com/macros/s/.../exec" /></p>
					<p><label><input type="checkbox" name="sheets_tab_per_form" value="yes" <?php checked( AARYE_Settings::on( 'sheets_tab_per_form' ) ); ?> /> <?php esc_html_e( 'One tab per form (otherwise all entries go to a tab named "Leads")', 'aarye-marketing-addon-for-forms' ); ?></label></p>
					<p><button class="button button-primary"><?php esc_html_e( 'Save and test', 'aarye-marketing-addon-for-forms' ); ?></button> <button class="button" name="sheets_url_clear" value="1"><?php esc_html_e( 'Disconnect', 'aarye-marketing-addon-for-forms' ); ?></button></p>
				</form>
				<p class="aarye-muted"><?php esc_html_e( 'Changed the script? Deploy → Manage deployments → edit → New version, so the same address keeps working.', 'aarye-marketing-addon-for-forms' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Save Google Sheets.
	 */
	public static function save_sheets() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'aarye_sheets' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms' ) );
		}
		$url = ! empty( $_POST['sheets_url_clear'] ) ? '' : ( isset( $_POST['sheets_url'] ) ? esc_url_raw( wp_unslash( $_POST['sheets_url'] ) ) : '' );
		if ( '' !== $url && 0 !== strpos( $url, 'https://script.google.com/' ) ) {
			wp_safe_redirect( self::url( 'sheets', array( 'msg' => rawurlencode( __( 'Paste the Web app URL from Apps Script: it starts with https://script.google.com/', 'aarye-marketing-addon-for-forms' ) ) ) ) );
			exit;
		}
		AARYE_Settings::update(
			array(
				'sheets_url'          => $url,
				'sheets_tab_per_form' => ! empty( $_POST['sheets_tab_per_form'] ) ? 'yes' : 'no',
			)
		);
		$msg = '';
		if ( '' !== $url ) {
			$test = AARYE_Sheets::send( null );
			$msg  = true === $test ? 'ok' : (string) $test;
		}
		wp_safe_redirect( self::url( 'sheets', $msg ? array( 'msg' => rawurlencode( $msg ) ) : array() ) );
		exit;
	}

	/**
	 * Settings.
	 */
	public static function page_settings() {
		$s = AARYE_Settings::all();
		?>
		<div class="wrap aarye">
			<h1><?php esc_html_e( 'Settings', 'aarye-marketing-addon-for-forms' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aarye-card">
				<input type="hidden" name="action" value="aarye_settings" />
				<?php wp_nonce_field( 'aarye_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Remember the source for', 'aarye-marketing-addon-for-forms' ); ?></th><td><input type="number" name="cookie_days" min="1" max="730" value="<?php echo esc_attr( (string) $s['cookie_days'] ); ?>" class="small-text" /> <?php esc_html_e( 'days', 'aarye-marketing-addon-for-forms' ); ?><p class="description"><?php esc_html_e( 'A visitor who clicks an ad today and sends the form within this time is still credited to the ad.', 'aarye-marketing-addon-for-forms' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Cookie consent', 'aarye-marketing-addon-for-forms' ); ?></th><td><label><input type="checkbox" name="wait_for_consent" value="yes" <?php checked( $s['wait_for_consent'], 'yes' ); ?> /> <?php esc_html_e( 'Only remember the source after the visitor accepts marketing cookies (needs a cookie banner that supports the WP Consent API)', 'aarye-marketing-addon-for-forms' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'IP address', 'aarye-marketing-addon-for-forms' ); ?></th><td><label><input type="checkbox" name="store_ip" value="yes" <?php checked( $s['store_ip'], 'yes' ); ?> /> <?php esc_html_e( 'Save the visitor\'s IP address with each entry', 'aarye-marketing-addon-for-forms' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Attachments', 'aarye-marketing-addon-for-forms' ); ?></th><td><label><input type="checkbox" name="keep_files" value="yes" <?php checked( $s['keep_files'], 'yes' ); ?> /> <?php esc_html_e( 'Keep a private copy of uploaded files (some form plugins delete them after sending the email)', 'aarye-marketing-addon-for-forms' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Bots', 'aarye-marketing-addon-for-forms' ); ?></th><td><label><input type="checkbox" name="skip_bots" value="yes" <?php checked( $s['skip_bots'], 'yes' ); ?> /> <?php esc_html_e( 'Don\'t save entries sent by bots and scripts (otherwise they are saved and marked "Bot?")', 'aarye-marketing-addon-for-forms' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'When deleting the plugin', 'aarye-marketing-addon-for-forms' ); ?></th><td><label><input type="checkbox" name="delete_on_remove" value="yes" <?php checked( $s['delete_on_remove'], 'yes' ); ?> /> <?php esc_html_e( 'Also delete all saved entries and files', 'aarye-marketing-addon-for-forms' ); ?></label></td></tr>
					<?php
					/**
					 * More settings rows (Aarye Pro).
					 *
					 * @param array $s Settings.
					 */
					do_action( 'aarye_settings_rows', $s );
					?>
				</table>
				<p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'aarye-marketing-addon-for-forms' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 */
	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'aarye_settings' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms' ) );
		}
		$values = array( 'cookie_days' => isset( $_POST['cookie_days'] ) ? min( 730, max( 1, absint( $_POST['cookie_days'] ) ) ) : 90 );
		foreach ( array( 'wait_for_consent', 'store_ip', 'keep_files', 'skip_bots', 'delete_on_remove' ) as $key ) {
			$values[ $key ] = ! empty( $_POST[ $key ] ) ? 'yes' : 'no';
		}
		/**
		 * Settings from the form, before saving (Aarye Pro adds its own).
		 *
		 * @param array $values Values.
		 */
		AARYE_Settings::update( (array) apply_filters( 'aarye_save_settings', $values ) );
		wp_safe_redirect( self::url( 'settings', array( 'updated' => 1 ) ) );
		exit;
	}
}
