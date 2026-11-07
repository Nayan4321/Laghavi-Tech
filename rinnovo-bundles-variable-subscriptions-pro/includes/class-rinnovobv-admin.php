<?php
/**
 * WooCommerce > Bundles & Plans: settings (one invoice, one renewal day, proration, stock alerts,
 * switching and swaps) and a list of the bundle subscriptions with their items.
 *
 * @package RinnovoBundles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class RINNOVOBV_Admin {

	const TD   = 'rinnovo-bundles-variable-subscriptions-pro';
	const PAGE = 'rinnovo-bundles';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 64 );
		add_action( 'admin_post_rinnovobv_settings', array( __CLASS__, 'save' ) );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Bundles & Plans', self::TD ), __( 'Bundles & Plans', self::TD ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * URL.
	 *
	 * @param array $args Args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	/**
	 * The page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$saved = ! empty( $_GET['saved'] );
		// phpcs:enable
		echo '<div class="wrap"><h1>' . esc_html__( 'Bundles & Plans', self::TD ) . '</h1>';
		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', self::TD ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper"><a class="nav-tab ' . ( 'list' !== $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', self::TD ) . '</a><a class="nav-tab ' . ( 'list' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => 'list' ) ) ) . '">' . esc_html__( 'Bundle subscriptions', self::TD ) . '</a></nav>';
		if ( 'list' === $tab ) {
			self::table();
		} else {
			self::settings();
		}
		echo '</div>';
	}

	/**
	 * Bundle subscriptions.
	 */
	private static function table() {
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Customer', self::TD ) . '</th><th>' . esc_html__( 'Subscription', self::TD ) . '</th><th>' . esc_html__( 'Status', self::TD ) . '</th><th>' . esc_html__( 'Next renewal', self::TD ) . '</th><th>' . esc_html__( 'Items', self::TD ) . '</th></tr></thead>';
		$rows = 0;
		foreach ( RINNOVO_Subscriptions::query( '1=1', 300 ) as $sub ) {
			$def  = RINNOVOBV_Bundle::def( (int) $sub->product_id );
			$data = RINNOVOBV_Settings::sub_data( $sub->id );
			if ( ! $def['enabled'] && ! $data['merged'] ) {
				continue;
			}
			++$rows;
			$user  = get_userdata( (int) $sub->user_id );
			$items = 'choose' === $def['mode'] ? $data['items'] : $def['components'];
			$text  = $def['enabled'] ? RINNOVOBV_Bundle::contents_text( $items ) : '';
			foreach ( $data['merged'] as $m ) {
				$text .= ( '' !== $text ? ' + ' : '' ) . ( isset( $m['name'] ) ? $m['name'] : '' );
			}
			echo '<tr><td>#' . (int) $sub->id . '</td><td>' . esc_html( $user ? $user->display_name : '—' ) . '</td><td>' . esc_html( $sub->name ) . '</td><td>' . esc_html( RINNOVO_Subscriptions::status_label( $sub->status ) ) . '</td><td>' . esc_html( RINNOVO_Subscriptions::date_label( $sub->next_payment ) ) . '</td><td>' . esc_html( $text ) . '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No bundle subscriptions yet. Tick "Bundle" in a subscription product\'s Bundle tab.', self::TD ) . '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * Settings.
	 */
	private static function settings() {
		$s     = RINNOVOBV_Settings::all();
		$input = static function ( $key, $label, $help = '', $type = 'text', $choices = array() ) use ( $s ) {
			echo '<tr><th><label for="bv-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'checkbox' === $type ) {
				echo '<label><input type="checkbox" id="bv-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" value="1" ' . checked( (bool) $s[ $key ], true, false ) . ' /> ' . esc_html( $help ) . '</label>';
				$help = '';
			} elseif ( 'select' === $type ) {
				echo '<select id="bv-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']">';
				foreach ( $choices as $value => $text ) {
					echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $s[ $key ], (string) $value, false ) . '>' . esc_html( $text ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input type="' . esc_attr( $type ) . '" id="bv-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s[ $key ] ) . '" class="small-text" />';
			}
			echo '' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '';
			echo '</td></tr>';
		};
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rinnovobv_settings" />';
		wp_nonce_field( 'rinnovobv_settings' );
		echo '<h2>' . esc_html__( 'One invoice, one renewal day', self::TD ) . '</h2><table class="form-table" role="presentation">';
		$input( 'unify', __( 'One recurring invoice', self::TD ), __( 'Subscriptions bought together (same billing period, no trial) become one subscription with one renewal invoice', self::TD ), 'checkbox' );
		$input( 'align_day', __( 'Renew on day', self::TD ), __( '1–28 of every month (0 = each subscription on its own date). Monthly subscriptions without a trial.', self::TD ), 'number' );
		$input(
			'align_scope',
			__( 'Renewal day applies to', self::TD ),
			'',
			'select',
			array(
				'bundles' => __( 'Bundles only', self::TD ),
				'all'     => __( 'All monthly subscriptions', self::TD ),
			)
		);
		$input( 'prorate', __( 'First payment', self::TD ), __( 'Charge only the days left until the renewal day', self::TD ), 'checkbox' );
		echo '</table><h2>' . esc_html__( 'Stock', self::TD ) . '</h2><table class="form-table" role="presentation">';
		$input( 'stock_days', __( 'Check stock', self::TD ), __( 'Days before a renewal to check that every item of the box is in stock.', self::TD ), 'number' );
		$input(
			'stock_notify',
			__( 'Tell', self::TD ),
			'',
			'select',
			array(
				'admin' => __( 'The store admin', self::TD ),
				'both'  => __( 'The store admin and the customer', self::TD ),
			)
		);
		echo '</table><h2>' . esc_html__( 'Switching and swaps', self::TD ) . '</h2><table class="form-table" role="presentation">';
		$input( 'switch', __( 'Change plan', self::TD ), __( 'Customers can upgrade or downgrade to another variation (the difference is prorated)', self::TD ), 'checkbox' );
		$input( 'swap_same', __( 'Swap', self::TD ), __( 'Customers can swap to a variation of the same price (size, flavour…)', self::TD ), 'checkbox' );
		$input( 'swap_items', __( 'Change items', self::TD ), __( 'Customers can change the items of a pick-your-own box', self::TD ), 'checkbox' );
		$input( 'swap_cutoff', __( 'Close changes', self::TD ), __( 'Days before a renewal when item changes close.', self::TD ), 'number' );
		echo '</table>';
		submit_button();
		echo '</form><h2>' . esc_html__( 'Box builder', self::TD ) . '</h2><p>' . esc_html__( 'Put [bundle_builder product="123"] on any page to show the step-by-step builder (box size, items, how often) for a bundle product.', self::TD ) . '</p>';
	}

	/**
	 * Save.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', self::TD ) );
		}
		check_admin_referer( 'rinnovobv_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
		$raw  = isset( $_POST['s'] ) ? (array) wp_unslash( $_POST['s'] ) : array();
		$vals = array();
		foreach ( RINNOVOBV_Settings::defaults() as $key => $default ) {
			if ( in_array( $key, array( 'unify', 'prorate', 'switch', 'swap_same', 'swap_items' ), true ) ) {
				$vals[ $key ] = empty( $raw[ $key ] ) ? 0 : 1;
			} elseif ( is_int( $default ) ) {
				$vals[ $key ] = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : 0;
			} else {
				$vals[ $key ] = isset( $raw[ $key ] ) ? sanitize_key( $raw[ $key ] ) : $default;
			}
		}
		$vals['align_day']    = min( 28, $vals['align_day'] );
		$vals['align_scope']  = 'all' === $vals['align_scope'] ? 'all' : 'bundles';
		$vals['stock_notify'] = 'both' === $vals['stock_notify'] ? 'both' : 'admin';
		RINNOVOBV_Settings::save( $vals );
		wp_safe_redirect( self::url( array( 'saved' => 1 ) ) );
		exit;
	}
}
