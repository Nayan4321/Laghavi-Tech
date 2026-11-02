<?php
/**
 * WooCommerce > Licenses: search, revoke, extend and add licenses.
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen.
 */
class RINNOVO_License_Admin {

	const PAGE = 'rinnovo-licenses';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_rinnovo_license_admin', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Licenses', 'rinnovo-subscriptions-licenses' ), __( 'Licenses', 'rinnovo-subscriptions-licenses' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Actions on a license.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'rinnovo-subscriptions-licenses' ) );
		}
		check_admin_referer( 'rinnovo_license_admin' );
		$todo    = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';
		$license = RINNOVO_Licenses::get( isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0 );
		$back    = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : admin_url( 'admin.php?page=' . self::PAGE );

		if ( 'add' === $todo ) {
			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$settings   = RINNOVO_License_Product::settings( $product_id );
			if ( $settings['enabled'] && is_email( $email ) ) {
				$user    = get_user_by( 'email', $email );
				$created = RINNOVO_Licenses::create(
					array(
						'product_id'    => $settings['id'],
						'user_id'       => $user ? $user->ID : 0,
						'email'         => $email,
						'sites_allowed' => $settings['sites'],
						'days'          => $settings['days'],
					)
				);
				if ( $created ) {
					$back = add_query_arg( 's', $created->license_key, admin_url( 'admin.php?page=' . self::PAGE ) );
				}
			}
		} elseif ( $license ) {
			switch ( $todo ) {
				case 'revoke':
					RINNOVO_Licenses::update( $license->id, array( 'status' => 'revoked' ) );
					break;
				case 'restore':
					RINNOVO_Licenses::update( $license->id, array( 'status' => 'active' ) );
					break;
				case 'extend':
					$from = RINNOVO_Licenses::is_expired( $license ) || empty( $license->expires_at ) ? time() : strtotime( $license->expires_at . ' UTC' );
					RINNOVO_Licenses::update( $license->id, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', $from + YEAR_IN_SECONDS ) ) );
					break;
				case 'sites':
					RINNOVO_Licenses::update( $license->id, array( 'sites_allowed' => isset( $_POST['sites_allowed'] ) ? absint( $_POST['sites_allowed'] ) : 1 ) );
					break;
				case 'remove_site':
					RINNOVO_Licenses::remove_activation( $license->id, isset( $_POST['site'] ) ? sanitize_text_field( wp_unslash( $_POST['site'] ) ) : '' );
					break;
			}
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * The page.
	 */
	public static function page() {
		global $wpdb;
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$t = RINNOVO_Licenses::tables()['licenses'];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Search and paging.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$offset = ( $paged - 1 ) * 50;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$order = absint( ltrim( $search, '#' ) );
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE license_key LIKE %s OR email LIKE %s OR order_id = %d', $t, $like, $like, $order ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE license_key LIKE %s OR email LIKE %s OR order_id = %d ORDER BY id DESC LIMIT 50 OFFSET %d', $t, $like, $like, $order, $offset ) );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 50 OFFSET %d', $t, $offset ) );
		}
		// phpcs:enable
		$back     = add_query_arg( array( 's' => $search, 'paged' => $paged ), admin_url( 'admin.php?page=' . self::PAGE ) );
		$products = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => 100,
				'meta_key'       => '_rinnovo_license_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$form     = static function ( $license, $todo, $label, $extra = '' ) use ( $back ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:2px 4px 2px 0">
				<input type="hidden" name="action" value="rinnovo_license_admin" />
				<input type="hidden" name="todo" value="<?php echo esc_attr( $todo ); ?>" />
				<input type="hidden" name="license_id" value="<?php echo esc_attr( $license->id ); ?>" />
				<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>" />
				<?php wp_nonce_field( 'rinnovo_license_admin' ); ?>
				<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts below. ?>
				<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
			</form>
			<?php
		};
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Licenses', 'rinnovo-subscriptions-licenses' ); ?></h1>
			<?php foreach ( $products as $product ) : ?>
				<?php if ( '' === RINNOVO_License_Product::settings( $product->ID )['slug'] ) : ?>
					<div class="notice notice-error inline"><p>
						<?php
						/* translators: %s: product name. */
						echo esc_html( sprintf( __( '"%s" has no Plugin slug, so its keys cannot be activated. Edit the product and fill in Plugin slug in its License tab.', 'rinnovo-subscriptions-licenses' ), $product->post_title ) );
						?>
						<a href="<?php echo esc_url( get_edit_post_link( $product->ID ) ); ?>"><?php esc_html_e( 'Edit product', 'rinnovo-subscriptions-licenses' ); ?></a>
					</p></div>
				<?php endif; ?>
			<?php endforeach; ?>
			<form method="get" style="margin:12px 0">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Key, email or order number', 'rinnovo-subscriptions-licenses' ); ?>" class="regular-text" />
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'rinnovo-subscriptions-licenses' ); ?></button>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Key', 'rinnovo-subscriptions-licenses' ); ?></th>
						<th><?php esc_html_e( 'Product', 'rinnovo-subscriptions-licenses' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'rinnovo-subscriptions-licenses' ); ?></th>
						<th><?php esc_html_e( 'Status', 'rinnovo-subscriptions-licenses' ); ?></th>
						<th><?php esc_html_e( 'Updates until', 'rinnovo-subscriptions-licenses' ); ?></th>
						<th><?php esc_html_e( 'Sites', 'rinnovo-subscriptions-licenses' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'rinnovo-subscriptions-licenses' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No licenses found.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $license ) : ?>
					<?php
					$status = RINNOVO_Licenses::status( $license );
					$sites  = RINNOVO_Licenses::activations( $license->id );
					?>
					<tr>
						<td><code><?php echo esc_html( $license->license_key ); ?></code></td>
						<td><?php echo esc_html( get_the_title( $license->product_id ) ); ?></td>
						<td>
							<?php echo esc_html( $license->email ); ?>
							<?php $order = $license->order_id ? wc_get_order( $license->order_id ) : null; ?>
							<?php if ( $order ) : ?>
								<br /><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php /* translators: %d: order number. */ echo esc_html( sprintf( __( 'Order #%d', 'rinnovo-subscriptions-licenses' ), $license->order_id ) ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( RINNOVO_Licenses::status_label( $status ) ); ?></td>
						<td><?php echo esc_html( RINNOVO_Licenses::expiry_label( $license ) ); ?></td>
						<td>
							<?php /* translators: 1: used, 2: allowed. */ echo esc_html( sprintf( __( '%1$d of %2$s', 'rinnovo-subscriptions-licenses' ), count( $sites ), $license->sites_allowed > 0 ? (string) $license->sites_allowed : '∞' ) ); ?>
							<?php foreach ( $sites as $site ) : ?>
								<div><code><?php echo esc_html( $site->site ); ?></code> <?php $form( $license, 'remove_site', __( 'Remove', 'rinnovo-subscriptions-licenses' ), '<input type="hidden" name="site" value="' . esc_attr( $site->site ) . '" />' ); ?></div>
							<?php endforeach; ?>
						</td>
						<td>
							<?php
							if ( 'revoked' === $status ) {
								$form( $license, 'restore', __( 'Restore', 'rinnovo-subscriptions-licenses' ) );
							} else {
								$form( $license, 'revoke', __( 'Revoke', 'rinnovo-subscriptions-licenses' ) );
							}
							$form( $license, 'extend', __( '+1 year', 'rinnovo-subscriptions-licenses' ) );
							$form( $license, 'sites', __( 'Set sites', 'rinnovo-subscriptions-licenses' ), '<input type="number" name="sites_allowed" min="0" class="small-text" value="' . esc_attr( $license->sites_allowed ) . '" />' );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $total > 50 ) : ?>
				<p><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => (int) ceil( $total / 50 ) ) ) ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Add a license by hand', 'rinnovo-subscriptions-licenses' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rinnovo_license_admin" />
				<input type="hidden" name="todo" value="add" />
				<?php wp_nonce_field( 'rinnovo_license_admin' ); ?>
				<select name="product_id" required>
					<?php foreach ( $products as $product ) : ?>
						<option value="<?php echo esc_attr( $product->ID ); ?>"><?php echo esc_html( $product->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="email" name="email" placeholder="<?php esc_attr_e( 'Customer email', 'rinnovo-subscriptions-licenses' ); ?>" required />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create key', 'rinnovo-subscriptions-licenses' ); ?></button>
			</form>
		</div>
		<?php
	}
}
