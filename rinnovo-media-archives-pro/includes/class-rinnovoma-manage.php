<?php
/**
 * Media Archives › Overview: the setup wizard (pick the business model; only what it needs is switched on),
 * the steps that are left, media analytics and subscriber activity.
 *
 * @package RinnovoMediaArchives
 */

defined( 'ABSPATH' ) || exit;

/**
 * Management screen and wizard.
 */
class RINNOVOMA_Manage {

	const TD   = 'rinnovo-media-archives-pro';
	const PAGE = 'rinnovoma-overview';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_rinnovoma_wizard', array( __CLASS__, 'save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Business models.
	 *
	 * @return array key => title, example, media types, steps.
	 */
	public static function models() {
		return array(
			'stream'  => array(
				__( 'A · Paid video streaming library', self::TD ),
				__( 'A fitness trainer with 200 workouts, a cooking school, a video course library.', self::TD ),
				array( 'video' ),
				array(
					__( 'Make a subscription product (Products › Add, tick "Subscription").', self::TD ),
					__( 'Create a plan for it in "1 · Plans & Quotas", with stream and device limits.', self::TD ),
					__( 'Connect Bunny Stream or Cloudflare Stream in "2 · Media Providers".', self::TD ),
					__( 'Add videos under Media Archives › Add media item, in collections (series, levels).', self::TD ),
					__( 'Members browse the library page, resume, keep favourites and playlists in their portal.', self::TD ),
				),
			),
			'podcast' => array(
				__( 'B · Premium podcast network', self::TD ),
				__( 'Free and subscriber-only episodes, seasons, a private RSS feed per subscriber for any podcast app.', self::TD ),
				array( 'audio' ),
				array(
					__( 'Create a plan and a subscription product.', self::TD ),
					__( 'Add shows under Episodes › Shows and episodes (free or subscribers only).', self::TD ),
					__( 'Host audio on Bunny Storage, CloudFront or S3 ("2 · Media Providers"); feeds never show the storage address.', self::TD ),
					__( 'Each subscriber gets a private feed address in their portal; a shared feed is flagged and can be renewed.', self::TD ),
				),
			),
			'stock'   => array(
				__( 'C · Stock media / digital asset library', self::TD ),
				__( 'Photos, footage, sound effects, music, motion graphics and design files with download quotas.', self::TD ),
				array( 'image', 'video', 'audio', 'file' ),
				array(
					__( 'Give each plan a downloads quota in "1 · Plans & Quotas" (e.g. downloads=50).', self::TD ),
					__( 'Add assets as media items with "Subscribers can download it" ticked, in collections.', self::TD ),
					__( 'Sell extra downloads as products with "Media Archives extra: downloads=50".', self::TD ),
					__( 'Turn on the watermark in "3 · Protection" for previews.', self::TD ),
				),
			),
			'portal'  => array(
				__( 'D · Private media portals', self::TD ),
				__( 'Agencies sharing campaign videos with clients, production houses delivering footage, internal training.', self::TD ),
				array( 'video', 'image', 'file' ),
				array(
					__( 'Create a library per client under Media Archives › Libraries & clients, with their emails, colour and logo.', self::TD ),
					__( 'Add items to the client\'s library: only that client sees them.', self::TD ),
					__( 'Send one item without an account with the item\'s "Share with a client" link (expires).', self::TD ),
				),
			),
		);
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . RINNOVOMA_Library::TYPE, __( 'Overview', self::TD ), __( 'Overview & analytics', self::TD ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ), 0 );
	}

	/**
	 * Reminder to run the wizard.
	 */
	public static function notice() {
		if ( '' !== (string) RINNOVOMA_Plans::get( 'business_model' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Rinnovo Media Archives Pro: tell us what you are building and we will show only the settings you need.', self::TD ) . ' <a class="button button-primary" href="' . esc_url( admin_url( 'edit.php?post_type=' . RINNOVOMA_Library::TYPE . '&page=' . self::PAGE ) ) . '">' . esc_html__( 'Start the setup', self::TD ) . '</a></p></div>';
	}

	/**
	 * The screen.
	 */
	public static function page() {
		$model  = (string) RINNOVOMA_Plans::get( 'business_model' );
		$models = self::models();
		echo '<div class="wrap"><h1>' . esc_html__( 'Media Archives', self::TD ) . '</h1>';
		echo '<h2>' . esc_html__( 'What are you building?', self::TD ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rinnovoma_wizard" />';
		wp_nonce_field( 'rinnovoma_wizard' );
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;max-width:1100px">';
		foreach ( $models as $key => $m ) {
			echo '<label style="display:block;background:#fff;border:2px solid ' . ( $key === $model ? '#2271b1' : '#dcdcde' ) . ';border-radius:8px;padding:14px;cursor:pointer"><input type="radio" name="model" value="' . esc_attr( $key ) . '"' . checked( $model, $key, false ) . ' /> <strong>' . esc_html( $m[0] ) . '</strong><br /><span class="description">' . esc_html( $m[1] ) . '</span></label>';
		}
		echo '</div><p><label><input type="checkbox" name="page" value="1"' . checked( '' === $model, true, false ) . ' /> ' . esc_html__( 'Create a "Media library" page with [media_library]', self::TD ) . '</label></p>';
		submit_button( '' === $model ? __( 'Set up', self::TD ) : __( 'Change', self::TD ) );
		echo '</form>';
		if ( isset( $models[ $model ] ) ) {
			echo '<h2>' . esc_html__( 'Your next steps', self::TD ) . '</h2><ol>';
			foreach ( $models[ $model ][3] as $step ) {
				echo '<li>' . esc_html( $step ) . '</li>';
			}
			echo '</ol><p>' . esc_html__( 'Members find everything in My Account › Access (or [media_account]): plan and usage, continue watching, favourites and playlists, download history, private feeds, devices and billing. Library: [media_library layout="grid|list|carousel" columns="3" ratio="16:9"].', self::TD ) . '</p>';
		}

		$a      = RINNOVOMA_Pipeline::analytics( 30 );
		$totals = $a['totals'];
		$n      = static function ( $k, $f = 'n' ) use ( $totals ) {
			return isset( $totals[ $k ] ) ? (int) $totals[ $k ]->$f : 0;
		};
		echo '<h2>' . esc_html__( 'Last 30 days', self::TD ) . '</h2><p style="font-size:1.1em">';
		/* translators: 1: plays, 2: people, 3: downloads, 4: share views, 5: refused. */
		echo esc_html( sprintf( __( '%1$d plays by %2$d people · %3$d downloads · %4$d share-link views · %5$d refused attempts', self::TD ), $n( 'play' ), $n( 'play', 'u' ), $n( 'download' ), $n( 'share' ), $n( 'denied' ) ) ) . '</p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Item', self::TD ) . '</th><th>' . esc_html__( 'Plays', self::TD ) . '</th><th>' . esc_html__( 'Downloads', self::TD ) . '</th><th>' . esc_html__( 'People', self::TD ) . '</th></tr></thead><tbody>';
		if ( ! $a['top'] ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No plays yet.', self::TD ) . '</td></tr>';
		}
		foreach ( $a['top'] as $r ) {
			echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( (int) $r->media_id ) ) . '">' . esc_html( get_the_title( (int) $r->media_id ) ) . '</a></td><td>' . (int) $r->plays . '</td><td>' . (int) $r->downloads . '</td><td>' . (int) $r->people . '</td></tr>';
		}
		echo '</tbody></table>';

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Admin screen.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, media_id, action, detail, device, created_at FROM %i WHERE action IN ('play','download','denied','share') ORDER BY id DESC LIMIT 40", RINNOVOMA_Pipeline::table() ) );
		echo '<h2>' . esc_html__( 'Subscriber activity', self::TD ) . '</h2><table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'When', self::TD ) . '</th><th>' . esc_html__( 'Customer', self::TD ) . '</th><th>' . esc_html__( 'What', self::TD ) . '</th><th>' . esc_html__( 'Item', self::TD ) . '</th><th>' . esc_html__( 'Device', self::TD ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$u = get_userdata( (int) $r->user_id );
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', strtotime( $r->created_at . ' UTC' ) ) ) . '</td><td>' . esc_html( $u ? $u->user_email : __( 'share link', self::TD ) ) . '</td><td>' . esc_html( $r->action . ( '' !== $r->detail ? ' (' . $r->detail . ')' : '' ) ) . '</td><td>' . esc_html( get_the_title( (int) $r->media_id ) ) . '</td><td><code>' . esc_html( $r->device ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Save the wizard.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'rinnovoma_wizard' ) ) {
			wp_die( esc_html__( 'Not allowed.', self::TD ) );
		}
		$model = isset( $_POST['model'] ) ? sanitize_key( wp_unslash( $_POST['model'] ) ) : '';
		$all   = self::models();
		if ( isset( $all[ $model ] ) ) {
			RINNOVOMA_Plans::save_settings(
				array(
					'business_model' => $model,
					'media_types'    => $all[ $model ][2],
				)
			);
			if ( ! empty( $_POST['page'] ) ) {
				wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => __( 'Media library', self::TD ),
						'post_content' => '[media_library layout="' . ( 'podcast' === $model ? 'list' : 'grid' ) . '"]',
					)
				);
			}
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . RINNOVOMA_Library::TYPE . '&page=' . self::PAGE ) );
		exit;
	}
}
