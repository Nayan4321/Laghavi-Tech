<?php
/**
 * WooCommerce > Membership: tiers and their perks, content rules, paywall, files and video, account
 * sharing, and the members list (with access given by hand).
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class RINNOVOMEM_Admin {

	const TD   = 'rinnovo-membership-content-pro';
	const PAGE = 'rinnovo-membership';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 61 );
		add_action( 'admin_post_rinnovomem_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Membership', self::TD ), __( 'Membership', self::TD ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Scripts of the page.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'rinnovomem-admin', RINNOVOMEM_URL . 'assets/js/admin.js', array( 'jquery', 'wc-enhanced-select' ), RINNOVOMEM_VERSION, true );
		wp_enqueue_style( 'rinnovomem-admin', RINNOVOMEM_URL . 'assets/css/admin.css', array(), RINNOVOMEM_VERSION );
	}

	/**
	 * Tabs.
	 *
	 * @return array<string, string>
	 */
	private static function tabs() {
		return array(
			'tiers'   => __( 'Tiers and perks', self::TD ),
			'content' => __( 'Content rules', self::TD ),
			'paywall' => __( 'Teasers and paywall', self::TD ),
			'files'   => __( 'Files and video', self::TD ),
			'access'  => __( 'Access and sharing', self::TD ),
			'members' => __( 'Members', self::TD ),
		);
	}

	/**
	 * The page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab choice only.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'tiers';
		$tab  = isset( self::tabs()[ $tab ] ) ? $tab : 'tiers';
		$note = get_transient( 'rinnovomem_admin_note_' . get_current_user_id() );
		delete_transient( 'rinnovomem_admin_note_' . get_current_user_id() );
		?>
		<div class="wrap rinnovomem-admin">
			<h1><?php esc_html_e( 'Membership', self::TD ); ?></h1>
			<?php if ( $note ) : ?>
				<div class="notice notice-<?php echo esc_attr( $note[0] ); ?> is-dismissible"><p><?php echo wp_kses_post( $note[1] ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rinnovomem_save" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
				<?php wp_nonce_field( 'rinnovomem_save' ); ?>
				<?php call_user_func( array( __CLASS__, 'tab_' . $tab ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Note for the next page load.
	 *
	 * @param string $type success, error, warning.
	 * @param string $text HTML.
	 */
	private static function note( $type, $text ) {
		set_transient( 'rinnovomem_admin_note_' . get_current_user_id(), array( $type, $text ), 60 );
	}

	/**
	 * Courses of LearnDash and LifterLMS.
	 *
	 * @return array<int, string>
	 */
	private static function courses() {
		$types = array_filter( array( 'sfwd-courses', 'course' ), 'post_type_exists' );
		if ( ! $types ) {
			return array();
		}
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => array_values( $types ),
				'posts_per_page' => 300,
				'post_status'    => 'publish',
			)
		) as $post ) {
			$out[ $post->ID ] = $post->post_title;
		}
		return $out;
	}

	/**
	 * Product search select with chosen products.
	 *
	 * @param string $name     Field name.
	 * @param int[]  $selected Product IDs.
	 */
	private static function product_select( $name, $selected ) {
		echo '<select class="wc-product-search" multiple="multiple" style="width:100%" name="' . esc_attr( $name ) . '[]" data-placeholder="' . esc_attr__( 'Search for products…', self::TD ) . '" data-action="woocommerce_json_search_products_and_variations">';
		foreach ( $selected as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				echo '<option value="' . (int) $id . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select>';
	}

	/**
	 * One tier's fields.
	 *
	 * @param array      $tier    Tier.
	 * @param int|string $i       Index.
	 * @param array      $cats    Product categories.
	 * @param array      $courses Courses.
	 */
	private static function tier_fields( $tier, $i, $cats, $courses ) {
		$n = static function ( $key ) use ( $i ) {
			return 'tiers[' . $i . '][' . $key . ']';
		};
		?>
		<div class="rinnovomem-tier">
			<div class="rinnovomem-tier__head">
				<span class="rinnovomem-tier__level"></span>
				<input type="text" name="<?php echo esc_attr( $n( 'name' ) ); ?>" value="<?php echo esc_attr( $tier['name'] ); ?>" placeholder="<?php esc_attr_e( 'Tier name, e.g. Gold', self::TD ); ?>" class="regular-text" />
				<input type="hidden" name="<?php echo esc_attr( $n( 'id' ) ); ?>" value="<?php echo esc_attr( $tier['id'] ); ?>" />
				<input type="color" name="<?php echo esc_attr( $n( 'color' ) ); ?>" value="<?php echo esc_attr( $tier['color'] ); ?>" title="<?php esc_attr_e( 'Badge colour', self::TD ); ?>" />
				<button type="button" class="button rinnovomem-up" title="<?php esc_attr_e( 'Move down a level', self::TD ); ?>">↓</button>
				<button type="button" class="button rinnovomem-down" title="<?php esc_attr_e( 'Move up a level', self::TD ); ?>">↑</button>
				<button type="button" class="button-link-delete rinnovomem-remove"><?php esc_html_e( 'Remove', self::TD ); ?></button>
			</div>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Products that give this tier', self::TD ); ?></th><td><?php self::product_select( $n( 'products' ), $tier['products'] ); ?><p class="description"><?php esc_html_e( 'Subscription products (or variations, e.g. Monthly and Yearly). A one-time purchase gives lifetime access when that is on in "Access and sharing".', self::TD ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Store discount', self::TD ); ?></th><td><input type="number" min="0" max="100" step="0.01" name="<?php echo esc_attr( $n( 'discount' ) ); ?>" value="<?php echo esc_attr( $tier['discount'] ? $tier['discount'] : '' ); ?>" placeholder="0" style="width:80px" /> % <?php esc_html_e( 'on', self::TD ); ?>
					<select name="<?php echo esc_attr( $n( 'discount_cats' ) ); ?>[]" multiple="multiple" class="wc-enhanced-select" style="width:60%" data-placeholder="<?php esc_attr_e( 'All products (or choose categories)', self::TD ); ?>">
						<?php foreach ( $cats as $id => $name ) : ?>
							<option value="<?php echo (int) $id; ?>" <?php selected( in_array( (int) $id, $tier['discount_cats'], true ) ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th><?php esc_html_e( 'Free shipping', self::TD ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $n( 'free_shipping' ) ); ?>" value="1" <?php checked( $tier['free_shipping'] ); ?> /> <?php esc_html_e( 'Members ship for free', self::TD ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Free bonus products', self::TD ); ?></th><td><?php self::product_select( $n( 'free_products' ), $tier['free_products'] ); ?><p class="description"><?php esc_html_e( 'One of each free per order, for example bonus downloads.', self::TD ); ?></p></td></tr>
				<?php if ( $courses ) : ?>
					<tr><th><?php esc_html_e( 'Enroll in courses', self::TD ); ?></th><td><select name="<?php echo esc_attr( $n( 'courses' ) ); ?>[]" multiple="multiple" class="wc-enhanced-select" style="width:100%">
						<?php foreach ( $courses as $id => $title ) : ?>
							<option value="<?php echo (int) $id; ?>" <?php selected( in_array( (int) $id, $tier['courses'], true ) ); ?>><?php echo esc_html( $title ); ?></option>
						<?php endforeach; ?>
					</select><p class="description"><?php esc_html_e( 'LearnDash / LifterLMS: members are enrolled while they have this tier, and unenrolled when it ends.', self::TD ); ?></p></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'User role', self::TD ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $n( 'role' ) ); ?>" value="1" <?php checked( $tier['role'] ); ?> /> <?php esc_html_e( 'Give members a "Member: (tier)" role (for other plugins that check roles); removed when access ends', self::TD ); ?></label></td></tr>
				<tr><th><?php esc_html_e( '"Subscribe to unlock" link', self::TD ); ?></th><td><input type="url" class="regular-text" name="<?php echo esc_attr( $n( 'join_url' ) ); ?>" value="<?php echo esc_attr( $tier['join_url'] ); ?>" placeholder="<?php esc_attr_e( 'Empty = the join page, else the first product', self::TD ); ?>" /></td></tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Tiers tab.
	 */
	private static function tab_tiers() {
		$cats = array();
		foreach ( get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		) as $term ) {
			$cats[ $term->term_id ] = $term->name;
		}
		$courses = self::courses();
		echo '<p>' . esc_html__( 'Lowest tier first. Each tier includes everything of the tiers above it in this list (Gold members see Silver content). "Free" (any registered user) is built in.', self::TD ) . '</p>';
		echo '<div class="rinnovomem-tiers">';
		$i = 0;
		foreach ( RINNOVOMEM_Tiers::all() as $tier ) {
			self::tier_fields( $tier, $i++, $cats, $courses );
		}
		echo '</div>';
		echo '<script type="text/html" id="tmpl-rinnovomem-tier">';
		ob_start();
		self::tier_fields( RINNOVOMEM_Tiers::normalize( array() ), '__i__', $cats, $courses );
		echo str_replace( 'wc-product-search', 'wc-product-search-new', ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in tier_fields().
		echo '</script>';
		echo '<p><button type="button" class="button rinnovomem-add">' . esc_html__( '+ Add a tier', self::TD ) . '</button></p>';
		submit_button();
	}

	/**
	 * Content tab.
	 */
	private static function tab_content() {
		$types   = (array) RINNOVOMEM_Settings::get( 'post_types' );
		$choices = RINNOVOMEM_Tiers::choices( false );
		?>
		<h2><?php esc_html_e( 'Whole content types', self::TD ); ?></h2>
		<p><?php esc_html_e( 'Make every item of a type members-only, for example all courses or all podcast episodes. Single items, categories and tags are set on their own edit screens ("Members only" box), and parts of a page with the "Members only" block or [members_only]…[/members_only].', self::TD ); ?></p>
		<table class="form-table" role="presentation">
			<?php foreach ( RINNOVOMEM_Rules::post_types() as $type ) : ?>
				<?php $object = get_post_type_object( $type ); ?>
				<tr><th><?php echo esc_html( $object ? $object->labels->name : $type ); ?> <code><?php echo esc_html( $type ); ?></code></th><td><select name="post_types[<?php echo esc_attr( $type ); ?>]">
					<?php foreach ( $choices as $id => $label ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( isset( $types[ $type ] ) ? $types[ $type ] : '', $id ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></td></tr>
			<?php endforeach; ?>
			<tr><th><?php esc_html_e( 'Child pages', self::TD ); ?></th><td><label><input type="checkbox" name="child_pages" value="1" <?php checked( RINNOVOMEM_Settings::get( 'child_pages' ) ); ?> /> <?php esc_html_e( 'Child pages follow their parent page\'s rule', self::TD ); ?></label><p class="description"><?php esc_html_e( 'bbPress topics and replies always follow their forum, and LearnDash / LifterLMS lessons their course.', self::TD ); ?></p></td></tr>
		</table>
		<?php if ( function_exists( 'bp_is_active' ) ) : ?>
			<h2><?php esc_html_e( 'BuddyPress / BuddyBoss community', self::TD ); ?></h2>
			<table class="form-table" role="presentation">
				<?php $bp = (array) RINNOVOMEM_Settings::get( 'bp_components' ); ?>
				<?php
				foreach ( array(
					'activity' => __( 'Activity feed', self::TD ),
					'members'  => __( 'Members and profiles', self::TD ),
					'groups'   => __( 'Groups', self::TD ),
					'forums'   => __( 'Forums', self::TD ),
					'messages' => __( 'Messages', self::TD ),
				) as $key => $label ) :
					?>
					<tr><th><?php echo esc_html( $label ); ?></th><td><select name="bp_components[<?php echo esc_attr( $key ); ?>]">
						<?php foreach ( $choices as $id => $choice ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( isset( $bp[ $key ] ) ? $bp[ $key ] : '', $id ); ?>><?php echo esc_html( $choice ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>
		<?php
		submit_button();
	}

	/**
	 * Paywall tab.
	 */
	private static function tab_paywall() {
		$s = RINNOVOMEM_Settings::all();
		?>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Teaser', self::TD ); ?></th><td><select name="teaser">
				<?php
				foreach ( array(
					'words'   => __( 'The first words of the content', self::TD ),
					'excerpt' => __( 'The excerpt (or the first words)', self::TD ),
					'blur'    => __( 'The first words, then a blurred preview', self::TD ),
					'none'    => __( 'Nothing, only the paywall', self::TD ),
				) as $key => $label ) :
					?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['teaser'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select> <input type="number" name="teaser_words" min="10" max="500" value="<?php echo esc_attr( $s['teaser_words'] ); ?>" style="width:80px" /> <?php esc_html_e( 'words', self::TD ); ?><p class="description"><?php esc_html_e( 'A "More" block in the content sets the teaser exactly. The rest of the content never reaches the browser.', self::TD ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Title', self::TD ); ?></th><td><input type="text" class="regular-text" name="cta_title" value="<?php echo esc_attr( $s['cta_title'] ); ?>" placeholder="<?php echo esc_attr( RINNOVOMEM_Settings::text( 'cta_title' ) ); ?>" /></td></tr>
			<tr><th><?php esc_html_e( 'Text', self::TD ); ?></th><td><input type="text" class="large-text" name="cta_text" value="<?php echo esc_attr( $s['cta_text'] ); ?>" placeholder="<?php echo esc_attr( RINNOVOMEM_Settings::text( 'cta_text' ) ); ?>" /><p class="description"><?php esc_html_e( '{tier} is replaced with the tier needed.', self::TD ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Button', self::TD ); ?></th><td><input type="text" class="regular-text" name="cta_button" value="<?php echo esc_attr( $s['cta_button'] ); ?>" placeholder="<?php echo esc_attr( RINNOVOMEM_Settings::text( 'cta_button' ) ); ?>" /></td></tr>
			<tr><th><?php esc_html_e( 'Join page', self::TD ); ?></th><td>
				<?php
				wp_dropdown_pages(
					array(
						'name'              => 'join_page',
						'selected'          => (int) $s['join_page'],
						'show_option_none'  => esc_html__( '— The tier\'s product page —', self::TD ),
						'option_none_value' => 0,
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Where the button leads, for example a pricing page comparing your tiers.', self::TD ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Log-in link', self::TD ); ?></th><td><label><input type="checkbox" name="login_link" value="1" <?php checked( $s['login_link'] ); ?> /> <?php esc_html_e( 'Show "Already a member? Log in"', self::TD ); ?></label></td></tr>
			<tr><th><?php esc_html_e( 'My Account page name', self::TD ); ?></th><td><input type="text" class="regular-text" name="account_label" value="<?php echo esc_attr( $s['account_label'] ); ?>" placeholder="<?php echo esc_attr( RINNOVOMEM_Settings::text( 'account_label' ) ); ?>" /></td></tr>
		</table>
		<?php
		submit_button();
	}

	/**
	 * Files tab.
	 */
	private static function tab_files() {
		$s       = RINNOVOMEM_Settings::all();
		$uploads = wp_upload_dir( null, false );
		$folder  = '/' . ltrim( str_replace( home_url(), '', $uploads['baseurl'] ), '/' ) . '/' . RINNOVOMEM_Files::FOLDER . '/';
		?>
		<h2><?php esc_html_e( 'Protected files', self::TD ); ?></h2>
		<p><?php esc_html_e( 'In the Media Library, open a file and choose "Members only". It moves to a protected folder and only those members can open it, even with a shared link. Link to it with [member_file id="123"], list a member\'s files with [member_library], or play it with [member_video provider="file" id="123"].', self::TD ); ?></p>
		<p><button type="submit" name="check_files" value="1" class="button"><?php esc_html_e( 'Check that the folder is protected', self::TD ); ?></button></p>
		<p class="description">
			<?php esc_html_e( 'Apache and LiteSpeed (most hosts, Hostinger included) read the folder\'s .htaccess. On Nginx add this to the site\'s server block:', self::TD ); ?>
			<br /><code>location <?php echo esc_html( $folder ); ?> { internal; }</code>
		</p>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Sending files', self::TD ); ?></th><td><select name="file_mode">
				<option value="php" <?php selected( $s['file_mode'], 'php' ); ?>><?php esc_html_e( 'By WordPress (works everywhere)', self::TD ); ?></option>
				<option value="xsendfile" <?php selected( $s['file_mode'], 'xsendfile' ); ?>><?php esc_html_e( 'X-Sendfile (Apache with mod_xsendfile, LiteSpeed)', self::TD ); ?></option>
				<option value="xaccel" <?php selected( $s['file_mode'], 'xaccel' ); ?>><?php esc_html_e( 'X-Accel-Redirect (Nginx, with the rule above)', self::TD ); ?></option>
			</select><p class="description"><?php esc_html_e( 'The server options are faster for large videos and audio.', self::TD ); ?></p></td></tr>
		</table>
		<h2><?php esc_html_e( 'Private video', self::TD ); ?></h2>
		<p><?php esc_html_e( '[member_video provider="bunny" id="VIDEO_ID"] — also vimeo (id="123456/hash" for private links), wistia, youtube (unlisted), presto (Presto Player ID) and file. The player is only put in the page for members.', self::TD ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Bunny Stream library ID', self::TD ); ?></th><td><input type="text" name="bunny_library" value="<?php echo esc_attr( $s['bunny_library'] ); ?>" class="regular-text" /></td></tr>
			<tr><th><?php esc_html_e( 'Bunny token authentication key', self::TD ); ?></th><td><input type="password" name="bunny_key" value="<?php echo esc_attr( $s['bunny_key'] ); ?>" class="regular-text" autocomplete="off" /><p class="description"><?php esc_html_e( 'From Bunny Stream > your library > Security > Embed view token authentication. Turn that on in Bunny: every embed link is then signed and expires after 4 hours, so copied links stop working. Also add your domain under "Allowed domains" (Vimeo and Wistia have the same "domain-level privacy" setting).', self::TD ); ?></p></td></tr>
		</table>
		<?php
		submit_button();
	}

	/**
	 * Access tab.
	 */
	private static function tab_access() {
		$s = RINNOVOMEM_Settings::all();
		?>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Failed payments', self::TD ); ?></th><td><?php esc_html_e( 'Keep access for', self::TD ); ?> <input type="number" min="0" max="60" name="grace_days" value="<?php echo esc_attr( $s['grace_days'] ); ?>" style="width:70px" /> <?php esc_html_e( 'days after a renewal payment fails, then remove it. Cancelled and refunded memberships lose access at once (a cancellation keeps the period already paid for).', self::TD ); ?></td></tr>
			<tr><th><?php esc_html_e( 'One-time purchases', self::TD ); ?></th><td><label><input type="checkbox" name="lifetime_orders" value="1" <?php checked( $s['lifetime_orders'] ); ?> /> <?php esc_html_e( 'Buying a tier product without a subscription gives lifetime access (removed if refunded)', self::TD ); ?></label></td></tr>
			<tr><th><?php esc_html_e( 'Plan changes', self::TD ); ?></th><td>
				<label><input type="checkbox" name="switch_credit" value="1" <?php checked( $s['switch_credit'] ); ?> /> <?php esc_html_e( 'Credit the unused part of the current period when upgrading, downgrading or switching monthly / yearly', self::TD ); ?></label><br />
				<label><input type="checkbox" name="switch_auto" value="1" <?php checked( $s['switch_auto'] ); ?> /> <?php esc_html_e( 'Buying another plan replaces the member\'s current plan (no paying twice)', self::TD ); ?></label></td></tr>
			<tr><th><?php esc_html_e( 'Logged-in devices', self::TD ); ?></th><td><?php esc_html_e( 'At most', self::TD ); ?> <input type="number" min="0" max="20" name="max_logins" value="<?php echo esc_attr( $s['max_logins'] ); ?>" style="width:70px" /> <?php esc_html_e( 'at once (0 = no limit); a new log-in signs out the oldest. For', self::TD ); ?>
				<select name="logins_for"><option value="members" <?php selected( $s['logins_for'], 'members' ); ?>><?php esc_html_e( 'members', self::TD ); ?></option><option value="everyone" <?php selected( $s['logins_for'], 'everyone' ); ?>><?php esc_html_e( 'all customers', self::TD ); ?></option></select></td></tr>
			<tr><th><?php esc_html_e( 'Videos at the same time', self::TD ); ?></th><td><?php esc_html_e( 'At most', self::TD ); ?> <input type="number" min="0" max="20" name="max_streams" value="<?php echo esc_attr( $s['max_streams'] ); ?>" style="width:70px" /> <?php esc_html_e( 'per account (0 = no limit), for [member_video].', self::TD ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Member directory', self::TD ); ?></th><td><select name="directory">
				<option value="optin" <?php selected( $s['directory'], 'optin' ); ?>><?php esc_html_e( 'Members who choose to be listed', self::TD ); ?></option>
				<option value="all" <?php selected( $s['directory'], 'all' ); ?>><?php esc_html_e( 'All members, unless they hide themselves', self::TD ); ?></option>
				<option value="off" <?php selected( $s['directory'], 'off' ); ?>><?php esc_html_e( 'Off', self::TD ); ?></option>
			</select><p class="description"><?php esc_html_e( 'Put [member_directory] on a page. Members set their headline and bio in My Account > My Content.', self::TD ); ?></p></td></tr>
		</table>
		<?php
		submit_button();
	}

	/**
	 * Members tab.
	 */
	private static function tab_members() {
		$users = get_users(
			array(
				'meta_key'     => '_rinnovomem_rank', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => 1, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '>=',
				'meta_type'    => 'NUMERIC',
				'number'       => 300,
			)
		);
		$sources = array(
			'subscription' => __( 'Subscription', self::TD ),
			'order'        => __( 'Lifetime purchase', self::TD ),
			'team'         => __( 'Team seat', self::TD ),
			'manual'       => __( 'Given by hand', self::TD ),
		);
		?>
		<h2><?php esc_html_e( 'Give access by hand', self::TD ); ?></h2>
		<p>
			<input type="email" name="grant_email" placeholder="<?php esc_attr_e( 'Customer email', self::TD ); ?>" class="regular-text" />
			<select name="grant_tier">
				<?php foreach ( RINNOVOMEM_Tiers::all() as $id => $tier ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $tier['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<label><?php esc_html_e( 'until', self::TD ); ?> <input type="date" name="grant_until" /></label>
			<button type="submit" name="grant" value="1" class="button button-primary"><?php esc_html_e( 'Give access', self::TD ); ?></button>
		</p>
		<p class="description"><?php esc_html_e( 'For press, partners or comps. Leave the date empty for no end.', self::TD ); ?></p>
		<h2>
			<?php
			/* translators: %d: number of members. */
			echo esc_html( sprintf( __( 'Members (%d)', self::TD ), count( $users ) ) );
			?>
			<button type="submit" name="resync" value="1" class="button"><?php esc_html_e( 'Refresh everyone\'s access', self::TD ); ?></button>
		</h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Member', self::TD ); ?></th><th><?php esc_html_e( 'Tiers', self::TD ); ?></th><th><?php esc_html_e( 'Member since', self::TD ); ?></th><th></th></tr></thead>
			<?php if ( ! $users ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No members yet.', self::TD ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $users as $user ) : ?>
				<?php $held = RINNOVOMEM_Members::held( $user->ID ); ?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a><br /><small><?php echo esc_html( $user->user_email ); ?></small></td>
					<td>
						<?php foreach ( $held as $tier => $h ) : ?>
							<?php echo wp_kses_post( RINNOVOMEM_Tiers::badge( $tier ) ); ?> <small><?php echo esc_html( $sources[ $h['source'] ] . ( $h['sub'] ? ' #' . $h['sub'] : '' ) ); ?></small><br />
						<?php endforeach; ?>
					</td>
					<td><?php echo $held ? esc_html( wp_date( get_option( 'date_format' ), min( wp_list_pluck( $held, 'since' ) ) ) ) : '—'; ?></td>
					<td>
						<?php foreach ( $held as $tier => $h ) : ?>
							<?php if ( 'manual' === $h['source'] ) : ?>
								<button type="submit" name="revoke" value="<?php echo esc_attr( $user->ID . ':' . $tier ); ?>" class="button-link-delete"><?php esc_html_e( 'Take back', self::TD ); ?></button>
							<?php endif; ?>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Save a tab.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', self::TD ) );
		}
		check_admin_referer( 'rinnovomem_save' );
		$tab  = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'tiers';
		$back = admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $tab );
		$tier_ok = static function ( $value ) {
			$value = sanitize_key( $value );
			return array_key_exists( $value, RINNOVOMEM_Tiers::choices( false ) ) ? $value : '';
		};

		if ( 'tiers' === $tab ) {
			$tiers = array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is cleaned below.
			foreach ( isset( $_POST['tiers'] ) ? (array) wp_unslash( $_POST['tiers'] ) : array() as $key => $raw ) {
				if ( '__i__' === (string) $key || ! is_array( $raw ) ) {
					continue;
				}
				$tiers[] = array(
					'id'            => isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '',
					'name'          => isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '',
					'color'         => isset( $raw['color'] ) && sanitize_hex_color( $raw['color'] ) ? sanitize_hex_color( $raw['color'] ) : '#7c3aed',
					'products'      => isset( $raw['products'] ) ? array_map( 'absint', (array) $raw['products'] ) : array(),
					'role'          => ! empty( $raw['role'] ) ? 1 : 0,
					'discount'      => isset( $raw['discount'] ) ? (float) $raw['discount'] : 0,
					'discount_cats' => isset( $raw['discount_cats'] ) ? array_map( 'absint', (array) $raw['discount_cats'] ) : array(),
					'free_shipping' => ! empty( $raw['free_shipping'] ) ? 1 : 0,
					'free_products' => isset( $raw['free_products'] ) ? array_map( 'absint', (array) $raw['free_products'] ) : array(),
					'courses'       => isset( $raw['courses'] ) ? array_map( 'absint', (array) $raw['courses'] ) : array(),
					'join_url'      => isset( $raw['join_url'] ) ? esc_url_raw( $raw['join_url'] ) : '',
				);
			}
			RINNOVOMEM_Tiers::save( $tiers );
			self::note( 'success', __( 'Tiers saved.', self::TD ) );
		} elseif ( 'content' === $tab ) {
			$types = array();
			foreach ( isset( $_POST['post_types'] ) ? (array) wp_unslash( $_POST['post_types'] ) : array() as $type => $tier ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
				$tier = $tier_ok( $tier );
				if ( '' !== $tier && post_type_exists( sanitize_key( $type ) ) ) {
					$types[ sanitize_key( $type ) ] = $tier;
				}
			}
			$bp = array();
			foreach ( isset( $_POST['bp_components'] ) ? (array) wp_unslash( $_POST['bp_components'] ) : array() as $component => $tier ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
				$tier = $tier_ok( $tier );
				if ( '' !== $tier ) {
					$bp[ sanitize_key( $component ) ] = $tier;
				}
			}
			RINNOVOMEM_Settings::save(
				array(
					'post_types'    => $types,
					'bp_components' => $bp,
					'child_pages'   => ! empty( $_POST['child_pages'] ) ? 1 : 0,
				)
			);
			self::note( 'success', __( 'Content rules saved.', self::TD ) );
		} elseif ( 'paywall' === $tab ) {
			$teaser = isset( $_POST['teaser'] ) ? sanitize_key( wp_unslash( $_POST['teaser'] ) ) : 'words';
			RINNOVOMEM_Settings::save(
				array(
					'teaser'        => in_array( $teaser, array( 'words', 'excerpt', 'blur', 'none' ), true ) ? $teaser : 'words',
					'teaser_words'  => isset( $_POST['teaser_words'] ) ? max( 10, absint( $_POST['teaser_words'] ) ) : 55,
					'cta_title'     => isset( $_POST['cta_title'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_title'] ) ) : '',
					'cta_text'      => isset( $_POST['cta_text'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_text'] ) ) : '',
					'cta_button'    => isset( $_POST['cta_button'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_button'] ) ) : '',
					'join_page'     => isset( $_POST['join_page'] ) ? absint( $_POST['join_page'] ) : 0,
					'login_link'    => ! empty( $_POST['login_link'] ) ? 1 : 0,
					'account_label' => isset( $_POST['account_label'] ) ? sanitize_text_field( wp_unslash( $_POST['account_label'] ) ) : '',
				)
			);
			self::note( 'success', __( 'Paywall saved.', self::TD ) );
		} elseif ( 'files' === $tab ) {
			$mode = isset( $_POST['file_mode'] ) ? sanitize_key( wp_unslash( $_POST['file_mode'] ) ) : 'php';
			RINNOVOMEM_Settings::save(
				array(
					'file_mode'     => in_array( $mode, array( 'php', 'xsendfile', 'xaccel' ), true ) ? $mode : 'php',
					'bunny_library' => isset( $_POST['bunny_library'] ) ? sanitize_text_field( wp_unslash( $_POST['bunny_library'] ) ) : '',
					'bunny_key'     => isset( $_POST['bunny_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bunny_key'] ) ) : '',
				)
			);
			if ( ! empty( $_POST['check_files'] ) ) {
				$check = RINNOVOMEM_Files::check_direct_access();
				if ( true === $check ) {
					self::note( 'success', __( 'Protected: files in the folder cannot be opened by their address.', self::TD ) );
				} elseif ( false === $check ) {
					self::note( 'error', __( 'Not protected yet: your server ignores the folder\'s .htaccess (Nginx). Add the rule shown below, or ask your host to.', self::TD ) );
				} else {
					self::note( 'warning', __( 'The check could not reach your site from itself. Try opening a protected file\'s direct address in a private window.', self::TD ) );
				}
			} else {
				self::note( 'success', __( 'Settings saved.', self::TD ) );
			}
		} elseif ( 'access' === $tab ) {
			$dir = isset( $_POST['directory'] ) ? sanitize_key( wp_unslash( $_POST['directory'] ) ) : 'optin';
			RINNOVOMEM_Settings::save(
				array(
					'grace_days'      => isset( $_POST['grace_days'] ) ? min( 60, absint( $_POST['grace_days'] ) ) : 3,
					'lifetime_orders' => ! empty( $_POST['lifetime_orders'] ) ? 1 : 0,
					'switch_credit'   => ! empty( $_POST['switch_credit'] ) ? 1 : 0,
					'switch_auto'     => ! empty( $_POST['switch_auto'] ) ? 1 : 0,
					'max_logins'      => isset( $_POST['max_logins'] ) ? min( 20, absint( $_POST['max_logins'] ) ) : 0,
					'logins_for'      => isset( $_POST['logins_for'] ) && 'everyone' === $_POST['logins_for'] ? 'everyone' : 'members',
					'max_streams'     => isset( $_POST['max_streams'] ) ? min( 20, absint( $_POST['max_streams'] ) ) : 0,
					'directory'       => in_array( $dir, array( 'optin', 'all', 'off' ), true ) ? $dir : 'optin',
				)
			);
			self::note( 'success', __( 'Settings saved.', self::TD ) );
		} elseif ( 'members' === $tab ) {
			if ( ! empty( $_POST['grant'] ) ) {
				$user  = get_user_by( 'email', isset( $_POST['grant_email'] ) ? sanitize_email( wp_unslash( $_POST['grant_email'] ) ) : '' );
				$tier  = isset( $_POST['grant_tier'] ) ? sanitize_key( wp_unslash( $_POST['grant_tier'] ) ) : '';
				$until = isset( $_POST['grant_until'] ) ? sanitize_text_field( wp_unslash( $_POST['grant_until'] ) ) : '';
				if ( ! $user || ! RINNOVOMEM_Tiers::get( $tier ) ) {
					self::note( 'error', __( 'No customer with this email address, or no tier chosen.', self::TD ) );
				} else {
					RINNOVOMEM_Members::grant( $user->ID, $tier, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ? (int) strtotime( $until . ' 23:59:59 ' . wp_timezone_string() ) : 0 );
					/* translators: 1: name, 2: tier. */
					self::note( 'success', esc_html( sprintf( __( '%1$s now has %2$s.', self::TD ), $user->display_name, RINNOVOMEM_Tiers::name( $tier ) ) ) );
				}
			} elseif ( ! empty( $_POST['revoke'] ) ) {
				$parts = explode( ':', sanitize_text_field( wp_unslash( $_POST['revoke'] ) ) );
				if ( 2 === count( $parts ) ) {
					RINNOVOMEM_Members::revoke( absint( $parts[0] ), sanitize_key( $parts[1] ) );
					self::note( 'success', __( 'Access taken back.', self::TD ) );
				}
			} elseif ( ! empty( $_POST['resync'] ) ) {
				$ids = self::all_member_candidates();
				foreach ( $ids as $id ) {
					RINNOVOMEM_Members::refresh_purchases( $id );
					RINNOVOMEM_Members::sync( $id );
				}
				/* translators: %d: number of customers. */
				self::note( 'success', esc_html( sprintf( __( 'Access refreshed for %d customers.', self::TD ), count( $ids ) ) ) );
			}
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Customers who may be members: subscribers and buyers of tier products.
	 *
	 * @return int[]
	 */
	private static function all_member_candidates() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM %i WHERE user_id > 0', RINNOVO_Subscriptions::table() ) ) );
		$products = array();
		foreach ( RINNOVOMEM_Tiers::all() as $tier ) {
			$products = array_merge( $products, $tier['products'] );
		}
		if ( $products ) {
			foreach ( wc_get_orders(
				array(
					'limit'  => 1000,
					'status' => array( 'processing', 'completed' ),
					'return' => 'objects',
				)
			) as $order ) {
				foreach ( $order->get_items() as $item ) {
					if ( in_array( (int) $item->get_product_id(), $products, true ) || in_array( (int) $item->get_variation_id(), $products, true ) ) {
						$ids[] = (int) $order->get_customer_id();
					}
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}
}
