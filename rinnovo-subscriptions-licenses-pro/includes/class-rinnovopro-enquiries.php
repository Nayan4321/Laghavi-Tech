<?php
/**
 * "Ask for price" enquiries: every click on the contact or WhatsApp button is recorded (product,
 * variation, customer, quantity, date, channel) in WooCommerce > Enquiries, with a status from new to won
 * or lost, so no lead is lost once the visitor leaves the product page.
 *
 * @package RinnovoSubscriptionsLicensesPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enquiries.
 */
class RINNOVOPRO_Enquiries {

	const TD   = 'rinnovo-subscriptions-licenses-pro';
	const TYPE = 'rinnovo_enquiry';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'template_redirect', array( __CLASS__, 'track' ), 1 );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_rinnovopro_enquiry_status', array( __CLASS__, 'set_status' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'status_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
	}

	/**
	 * Statuses.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'new'       => __( 'New', self::TD ),
			'contacted' => __( 'Contacted', self::TD ),
			'quoted'    => __( 'Quote sent', self::TD ),
			'won'       => __( 'Won', self::TD ),
			'lost'      => __( 'Lost', self::TD ),
		);
	}

	/**
	 * Private post type, listed under WooCommerce.
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Enquiries', self::TD ),
					'singular_name' => __( 'Enquiry', self::TD ),
					'not_found'     => __( 'No enquiries yet. They appear here when visitors click "Ask for price" or WhatsApp on a product.', self::TD ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'supports'        => array( 'title' ),
				'capability_type' => 'product',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Address the buttons use: records the enquiry, then opens the contact page or WhatsApp.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $channel contact or whatsapp.
	 * @return string
	 */
	public static function url( $product, $channel ) {
		return add_query_arg(
			array(
				'rinnovo_enquiry' => $channel,
				'p'               => $product->get_id(),
			),
			home_url( '/' )
		);
	}

	/**
	 * Record the click and send the visitor on.
	 */
	public static function track() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A link click; nothing is changed for the visitor.
		if ( empty( $_GET['rinnovo_enquiry'] ) ) {
			return;
		}
		$channel = 'whatsapp' === $_GET['rinnovo_enquiry'] ? 'whatsapp' : 'contact';
		$product = wc_get_product( isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 0 );
		$qty     = isset( $_GET['q'] ) ? max( 1, absint( $_GET['q'] ) ) : 1;
		// phpcs:enable
		if ( ! $product || ! RINNOVO_Ask_Price::applies( $product ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$target = 'whatsapp' === $channel ? RINNOVO_Ask_Price::whatsapp_url( $product ) : RINNOVO_Ask_Price::contact_url( $product );
		if ( '' === $target ) {
			$target = $product->get_permalink();
		}
		if ( ! self::is_bot() && ! self::recent( $product->get_id(), $channel ) ) {
			self::record( $product, $channel, $qty );
		}
		// The target is built on the server from the store's own settings (a wa.me link, the contact page or the
		// booking link the store set), never taken from the request, so it may be on another site.
		wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Target from the store's settings only.
		exit;
	}

	/**
	 * Robots don't make enquiries.
	 *
	 * @return bool
	 */
	private static function is_bot() {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return '' === $agent || (bool) preg_match( '/bot|crawl|spider|slurp|preview|headless|curl|wget|python/i', $agent );
	}

	/**
	 * Same visitor, same product and channel within 15 minutes: one enquiry.
	 *
	 * @param int    $product_id Product.
	 * @param string $channel    Channel.
	 * @return bool
	 */
	private static function recent( $product_id, $channel ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'rinnovopro_enq_' . md5( get_current_user_id() . '|' . $ip . '|' . $product_id . '|' . $channel );
		if ( get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, 15 * MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Save an enquiry.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $channel Channel.
	 * @param int        $qty     Quantity.
	 * @return int
	 */
	public static function record( $product, $channel, $qty ) {
		$user = wp_get_current_user();
		$name = $user->exists() ? $user->display_name : __( 'Visitor', self::TD );
		$id   = wp_insert_post(
			array(
				'post_type'   => self::TYPE,
				'post_status' => 'publish',
				/* translators: 1: product, 2: customer. */
				'post_title'  => sprintf( __( '%1$s – %2$s', self::TD ), $product->get_name(), $name ),
			)
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		$meta = array(
			'_product'   => $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(),
			'_variation' => $product->get_parent_id() ? $product->get_id() : 0,
			'_channel'   => $channel,
			'_qty'       => $qty,
			'_user'      => $user->exists() ? $user->ID : 0,
			'_email'     => $user->exists() ? $user->user_email : '',
			'_status'    => 'new',
			'_page'      => wp_get_raw_referer() ? esc_url_raw( wp_get_raw_referer() ) : '',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		/**
		 * An "Ask for price" enquiry was made.
		 *
		 * @param int        $id      Enquiry.
		 * @param WC_Product $product Product.
		 * @param string     $channel contact or whatsapp.
		 */
		do_action( 'rinnovopro_enquiry', $id, $product, $channel );
		return (int) $id;
	}

	/**
	 * List columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'       => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'title'    => __( 'Enquiry', self::TD ),
			'product'  => __( 'Product', self::TD ),
			'customer' => __( 'Customer', self::TD ),
			'qty'      => __( 'Quantity', self::TD ),
			'channel'  => __( 'Channel', self::TD ),
			'status'   => __( 'Status', self::TD ),
			'date'     => __( 'Date', self::TD ),
		);
	}

	/**
	 * Column values.
	 *
	 * @param string $column Column.
	 * @param int    $id     Enquiry.
	 */
	public static function column( $column, $id ) {
		switch ( $column ) {
			case 'product':
				$pid = (int) get_post_meta( $id, '_product', true );
				$vid = (int) get_post_meta( $id, '_variation', true );
				echo '<a href="' . esc_url( (string) get_edit_post_link( $pid ) ) . '">' . esc_html( get_the_title( $vid ? $vid : $pid ) ) . '</a>';
				break;
			case 'customer':
				$uid   = (int) get_post_meta( $id, '_user', true );
				$email = (string) get_post_meta( $id, '_email', true );
				echo $uid ? '<a href="' . esc_url( get_edit_user_link( $uid ) ) . '">' . esc_html( get_the_author_meta( 'display_name', $uid ) ) . '</a><br /><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : esc_html__( 'Visitor (not logged in)', self::TD );
				break;
			case 'qty':
				echo esc_html( (string) get_post_meta( $id, '_qty', true ) );
				break;
			case 'channel':
				echo esc_html( 'whatsapp' === get_post_meta( $id, '_channel', true ) ? 'WhatsApp' : __( 'Contact page', self::TD ) );
				break;
			case 'status':
				$s = (string) get_post_meta( $id, '_status', true );
				echo '<strong>' . esc_html( isset( self::statuses()[ $s ] ) ? self::statuses()[ $s ] : $s ) . '</strong>';
				break;
		}
	}

	/**
	 * Status links under each enquiry.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( self::TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'], $actions['edit'], $actions['view'] );
		$current = (string) get_post_meta( $post->ID, '_status', true );
		foreach ( self::statuses() as $key => $label ) {
			if ( $key !== $current ) {
				$actions[ 'st_' . $key ] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rinnovopro_enquiry_status&id=' . $post->ID . '&status=' . $key ), 'rinnovopro_enquiry_' . $post->ID ) ) . '">' . esc_html( $label ) . '</a>';
			}
		}
		$actions['new_order'] = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=new' ) ) . '">' . esc_html__( 'Create order', self::TD ) . '</a>';
		return $actions;
	}

	/**
	 * Change an enquiry's status.
	 */
	public static function set_status() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'rinnovopro_enquiry_' . $id );
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( ! current_user_can( 'edit_post', $id ) || self::TYPE !== get_post_type( $id ) || ! isset( self::statuses()[ $status ] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', self::TD ) );
		}
		update_post_meta( $id, '_status', $status );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . self::TYPE ) );
		exit;
	}

	/**
	 * Filter by status.
	 *
	 * @param string $type Post type.
	 */
	public static function status_filter( $type ) {
		if ( self::TYPE !== $type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- List filter.
		$current = isset( $_GET['enquiry_status'] ) ? sanitize_key( wp_unslash( $_GET['enquiry_status'] ) ) : '';
		echo '<select name="enquiry_status"><option value="">' . esc_html__( 'All statuses', self::TD ) . '</option>';
		foreach ( self::statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Apply the status filter.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_query( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- List filter.
		$status = isset( $_GET['enquiry_status'] ) ? sanitize_key( wp_unslash( $_GET['enquiry_status'] ) ) : '';
		if ( is_admin() && $query->is_main_query() && self::TYPE === $query->get( 'post_type' ) && '' !== $status ) {
			$query->set( 'meta_key', '_status' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->set( 'meta_value', $status ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}
	}
}
