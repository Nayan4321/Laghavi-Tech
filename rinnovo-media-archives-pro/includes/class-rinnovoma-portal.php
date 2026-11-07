<?php
/**
 * Self-service portal: My Account > Your access (also [media_account] on any page, and embeddable in your
 * app). Plan and status, usage of quotas, a button into the app, private podcast feed addresses, devices
 * with "sign out everywhere", security alerts, pause and resume, plan changes with credit, and the card
 * and invoices from WooCommerce.
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Portal.
 */
class RINNOVOMA_Portal {

	const TD       = 'rinnovo-media-archives-pro';
	const ENDPOINT = 'access';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 16 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_shortcode( 'media_account', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 6 );
		add_action( 'send_headers', array( __CLASS__, 'frame_headers' ) );
	}

	/**
	 * Portal address.
	 *
	 * @return string
	 */
	public static function url() {
		return wc_get_account_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Register the endpoint.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Refresh permalinks once per version.
	 */
	public static function maybe_flush() {
		if ( get_option( 'rinnovoma_rewrite_version' ) !== RINNOVOMA_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'rinnovoma_rewrite_version', RINNOVOMA_VERSION );
		}
	}

	/**
	 * Query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Menu.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function menu( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$out[ self::ENDPOINT ] = self::title();
			}
		}
		return isset( $out[ self::ENDPOINT ] ) ? $out : array( self::ENDPOINT => self::title() ) + $out;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public static function title() {
		return __( 'Your access', self::TD );
	}

	/**
	 * Let your app show the portal in a frame.
	 */
	public static function frame_headers() {
		$origins = trim( (string) RINNOVOMA_Plans::get( 'frame_origins' ) );
		if ( '' !== $origins && function_exists( 'is_account_page' ) ) {
			header( "Content-Security-Policy: frame-ancestors 'self' " . preg_replace( '/[^a-z0-9:\/\.\-\s\*]/i', '', $origins ) );
		}
	}

	/**
	 * [media_account].
	 *
	 * @return string
	 */
	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p><a class="button" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', self::TD ) . '</a></p>';
		}
		ob_start();
		self::render();
		return (string) ob_get_clean();
	}

	/**
	 * Hidden fields of a form.
	 *
	 * @param string $todo Action.
	 */
	private static function fields( $todo ) {
		wp_nonce_field( 'rinnovoma_portal', 'rinnovoma_portal_nonce' );
		echo '<input type="hidden" name="rinnovoma_do" value="' . esc_attr( $todo ) . '" />';
	}

	/**
	 * The portal.
	 */
	public static function render() {
		$user  = get_current_user_id();
		$state = RINNOVOMA_Access::state( $user );
		$sub   = $state['sub'];
		echo '<div class="rinnovoma-portal">';

		// Plan.
		echo '<section class="rinnovoma-section"><h3>' . esc_html__( 'Your plan', self::TD ) . '</h3>';
		if ( $state['active'] ) {
			echo '<p class="rinnovoma-plan"><strong>' . esc_html( $state['plan_name'] ) . '</strong> · ' . esc_html( RINNOVO_Subscriptions::status_label( $sub->status ) );
			if ( $state['trial'] && $sub->trial_end ) {
				/* translators: %s: date. */
				echo '<br />' . esc_html( sprintf( __( 'Free trial until %s', self::TD ), RINNOVO_Subscriptions::date_label( $sub->trial_end ) ) );
			} elseif ( 'pending-cancel' === $sub->status ) {
				/* translators: %s: date. */
				echo '<br />' . esc_html( sprintf( __( 'Access ends on %s', self::TD ), RINNOVO_Subscriptions::date_label( $sub->next_payment ) ) );
			} elseif ( $state['renews'] ) {
				/* translators: 1: amount, 2: date. */
				echo '<br />' . esc_html( sprintf( __( '%1$s, next payment %2$s', self::TD ), RINNOVO_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ), RINNOVO_Subscriptions::date_label( $sub->next_payment ) ) );
			}
			$pause = RINNOVOMA_Access::pause_of( $sub );
			if ( $pause ) {
				/* translators: 1: date, 2: date. */
				echo '<br />' . esc_html( sprintf( __( 'Paused from %1$s until %2$s.', self::TD ), wp_date( get_option( 'date_format' ), $pause['from'] ), wp_date( get_option( 'date_format' ), $pause['until'] ) ) );
			}
			echo '</p>';
			$app = (string) RINNOVOMA_Plans::get( 'app_login_url' );
			$app = '' !== $app ? $app : (string) RINNOVOMA_Plans::get( 'app_url' );
			if ( '' !== $app ) {
				echo '<p><a class="button alt" href="' . esc_url( $app ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the app', self::TD ) . '</a></p>';
			}
		} elseif ( 'paused' === $state['status'] ) {
			/* translators: %s: date. */
			echo '<p>' . esc_html( sprintf( __( 'Your subscription is paused until %s.', self::TD ), wp_date( get_option( 'date_format' ), $state['paused_until'] ) ) ) . '</p><form method="post">';
			self::fields( 'resume' );
			echo '<button class="button alt">' . esc_html__( 'Resume now', self::TD ) . '</button></form>';
		} else {
			echo '<p>' . esc_html__( 'You have no active plan.', self::TD ) . '</p><p><a class="button alt" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'See plans', self::TD ) . '</a></p>';
		}
		echo '</section>';

		if ( $state['active'] ) {
			self::usage( $user );
			self::feeds( $user );
		}
		self::devices( $user );
		if ( $state['active'] && $sub ) {
			self::pause_form( $sub );
			self::plans( $sub );
		}
		self::billing( $user, $sub );
		echo '</div>';
	}

	/**
	 * Usage of quotas.
	 *
	 * @param int $user User.
	 */
	private static function usage( $user ) {
		$report = RINNOVOMA_Access::usage_report( $user );
		$flags  = array_filter(
			RINNOVOMA_Access::state( $user )['features'],
			static function ( $v ) {
				return true === $v;
			}
		);
		if ( ! $report && ! $flags ) {
			return;
		}
		echo '<section class="rinnovoma-section"><h3>' . esc_html__( 'Included in your plan', self::TD ) . '</h3><ul class="rinnovoma-usage">';
		foreach ( $report as $key => $row ) {
			$pct = $row['limit'] > 0 ? min( 100, (int) round( 100 * $row['used'] / $row['limit'] ) ) : 0;
			/* translators: 1: used, 2: limit. */
			echo '<li><span>' . esc_html( ucwords( str_replace( array( '_', '-' ), ' ', $key ) ) ) . '</span> <span>' . esc_html( sprintf( __( '%1$d of %2$d used this period', self::TD ), $row['used'], $row['limit'] ) ) . '</span><span class="rinnovoma-bar"><span style="width:' . (int) $pct . '%"></span></span></li>';
		}
		foreach ( array_keys( $flags ) as $key ) {
			echo '<li><span>✓ ' . esc_html( ucwords( str_replace( array( '_', '-' ), ' ', $key ) ) ) . '</span></li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Private podcast feeds.
	 *
	 * @param int $user User.
	 */
	private static function feeds( $user ) {
		$shows = get_terms(
			array(
				'taxonomy'   => RINNOVOMA_Podcast::SHOW,
				'hide_empty' => true,
			)
		);
		if ( ! is_array( $shows ) || ! $shows ) {
			return;
		}
		echo '<section class="rinnovoma-section"><h3>' . esc_html__( 'Your private podcast feeds', self::TD ) . '</h3><p>' . esc_html__( 'Add these addresses in your podcast app ("Add a show by URL"). They are only for you and stop working if your subscription ends.', self::TD ) . '</p>';
		foreach ( $shows as $show ) {
			$plan = (string) get_term_meta( $show->term_id, '_rinnovoma_plan', true );
			if ( ! RINNOVOMA_Access::has( $user, '' !== $plan ? $plan : 'any' ) ) {
				continue;
			}
			$url = RINNOVOMA_Podcast::feed_url( $user, $show );
			echo '<p class="rinnovoma-feed"><strong>' . esc_html( $show->name ) . '</strong><br /><input type="text" readonly value="' . esc_attr( $url ) . '" onclick="this.select()" /> <a class="button" href="' . esc_url( str_replace( array( 'https://', 'http://' ), 'podcast://', $url ) ) . '">' . esc_html__( 'Open in Apple Podcasts', self::TD ) . '</a></p>';
		}
		echo '<form method="post">';
		self::fields( 'new_feed' );
		echo '<button class="button" onclick="return confirm(this.dataset.c)" data-c="' . esc_attr__( 'Your old feed addresses will stop working. Continue?', self::TD ) . '">' . esc_html__( 'Get new feed addresses', self::TD ) . '</button></form></section>';
	}

	/**
	 * Devices and alerts.
	 *
	 * @param int $user User.
	 */
	private static function devices( $user ) {
		$sessions = get_user_meta( $user, 'session_tokens', true );
		$seen     = array_reverse( (array) get_user_meta( $user, '_rinnovoma_seen', true ) );
		echo '<section class="rinnovoma-section"><h3>' . esc_html__( 'Devices and security', self::TD ) . '</h3>';
		/* translators: %d: number. */
		echo '<p>' . esc_html( sprintf( _n( '%d device is signed in to this site.', '%d devices are signed in to this site.', is_array( $sessions ) ? count( $sessions ) : 0, self::TD ), is_array( $sessions ) ? count( $sessions ) : 0 ) ) . '</p>';
		if ( $seen ) {
			echo '<table class="shop_table"><tr><th>' . esc_html__( 'When', self::TD ) . '</th><th>' . esc_html__( 'Where', self::TD ) . '</th></tr>';
			foreach ( array_slice( $seen, 0, 6 ) as $row ) {
				if ( is_array( $row ) ) {
					echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) ) . '</td><td>' . esc_html( trim( $row['country'] . ' · ' . $row['ip'], ' ·' ) ) . '</td></tr>';
				}
			}
			echo '</table>';
		}
		echo '<form method="post">';
		self::fields( 'logout_all' );
		echo '<button class="button">' . esc_html__( 'Sign out everywhere else (site and app)', self::TD ) . '</button></form></section>';
	}

	/**
	 * Pause.
	 *
	 * @param object $sub Subscription.
	 */
	private static function pause_form( $sub ) {
		$max = (int) RINNOVOMA_Plans::get( 'pause_months' );
		if ( $max < 1 || ! in_array( $sub->status, array( 'active', 'trial' ), true ) ) {
			return;
		}
		if ( RINNOVOMA_Access::pause_of( $sub ) ) {
			echo '<section class="rinnovoma-section"><form method="post">';
			self::fields( 'resume' );
			echo '<button class="button">' . esc_html__( 'Cancel the pause', self::TD ) . '</button></form></section>';
			return;
		}
		echo '<section class="rinnovoma-section"><h3>' . esc_html__( 'Take a break', self::TD ) . '</h3><form method="post">';
		self::fields( 'pause' );
		/* translators: %s: date. */
		echo '<p>' . esc_html( sprintf( __( 'Pause from %s (the end of the period you paid for) for', self::TD ), RINNOVO_Subscriptions::date_label( $sub->next_payment ) ) ) . ' <select name="rinnovoma_months">';
		for ( $i = 1; $i <= $max; $i++ ) {
			/* translators: %d: months. */
			echo '<option value="' . (int) $i . '">' . esc_html( sprintf( _n( '%d month', '%d months', $i, self::TD ), $i ) ) . '</option>';
		}
		echo '</select> <button class="button">' . esc_html__( 'Pause', self::TD ) . '</button></p><p class="description">' . esc_html__( 'Nothing is charged while paused, and your card and settings are kept.', self::TD ) . '</p></form></section>';
	}

	/**
	 * Plan changes.
	 *
	 * @param object $sub Subscription.
	 */
	private static function plans( $sub ) {
		$options = RINNOVOMA_Access::switch_options( $sub );
		if ( ! $options ) {
			return;
		}
		$credit = RINNOVOMA_Access::credit( $sub );
		echo '<section class="rinnovoma-section"><h3>' . esc_html__( 'Change plan', self::TD ) . '</h3>';
		if ( $credit > 0 ) {
			/* translators: %s: amount. */
			echo '<p>' . esc_html( sprintf( __( '%s of unused time is taken off your new plan\'s first payment.', self::TD ), wp_strip_all_tags( wc_price( $credit, array( 'currency' => $sub->currency ) ) ) ) ) . '</p>';
		}
		echo '<form method="post">';
		self::fields( 'switch' );
		foreach ( $options as $product ) {
			$plan  = RINNOVO_Product::plan( $product );
			$price = wp_strip_all_tags( wc_price( wc_get_price_to_display( $product ) ) ) . ( $plan ? ' ' . RINNOVO_Subscriptions::period_string( $plan['period'], $plan['interval'] ) : '' );
			echo '<p class="rinnovoma-switch"><span>' . esc_html( $product->get_name() . ' · ' . $price ) . '</span> <button class="button" name="rinnovoma_to" value="' . (int) $product->get_id() . '">' . esc_html__( 'Switch', self::TD ) . '</button></p>';
		}
		echo '</form></section>';
	}

	/**
	 * Card and invoices.
	 *
	 * @param int         $user User.
	 * @param object|null $sub  Subscription.
	 */
	private static function billing( $user, $sub ) {
		echo '<section class="rinnovoma-section"><h3>' . esc_html__( 'Billing', self::TD ) . '</h3>';
		if ( $sub ) {
			echo '<table class="shop_table">';
			foreach ( array_slice( RINNOVO_Subscriptions::orders( $sub ), 0, 6 ) as $order ) {
				$invoice = class_exists( 'RINNOVOPRO_Invoices' ) && $order->get_meta( '_rinnovopro_invoice' ) ? ' · <a href="' . esc_url( RINNOVOPRO_Invoices::customer_url( $order, true ) ) . '">' . esc_html__( 'Invoice PDF', self::TD ) . '</a>' : '';
				echo '<tr><td><a href="' . esc_url( $order->get_view_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td><td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td><td>' . wp_kses_post( $order->get_formatted_order_total() . $invoice ) . '</td></tr>';
			}
			echo '</table>';
		}
		echo '<p><a class="button" href="' . esc_url( wc_get_account_endpoint_url( RINNOVO_Account::endpoint() ) ) . '">' . esc_html__( 'Update card or cancel', self::TD ) . '</a></p></section>';
	}

	/**
	 * Forms.
	 */
	public static function handle() {
		if ( empty( $_POST['rinnovoma_do'] ) || ! is_user_logged_in() ) {
			return;
		}
		check_admin_referer( 'rinnovoma_portal', 'rinnovoma_portal_nonce' );
		$user = get_current_user_id();
		$todo = sanitize_key( wp_unslash( $_POST['rinnovoma_do'] ) );
		$sub  = RINNOVOMA_Access::state( $user )['sub'];
		$back = wp_get_referer() ? wp_get_referer() : self::url();
		$r    = true;
		if ( 'new_feed' === $todo ) {
			RINNOVOMA_Podcast::token( $user, true );
			wc_add_notice( __( 'New feed addresses are ready. Add them to your podcast app again.', self::TD ) );
		} elseif ( 'logout_all' === $todo ) {
			if ( class_exists( 'WP_Session_Tokens' ) ) {
				WP_Session_Tokens::get_instance( $user )->destroy_others( wp_get_session_token() );
			}
			update_user_meta( $user, '_rinnovoma_token_version', (int) get_user_meta( $user, '_rinnovoma_token_version', true ) + 1 );
			delete_user_meta( $user, '_rinnovoma_refresh' );
			delete_user_meta( $user, '_rinnovoma_devices' );
			delete_user_meta( $user, '_rinnovoma_streams' );
			wc_add_notice( __( 'All other devices and app sessions are signed out.', self::TD ) );
		} elseif ( 'pause' === $todo && $sub ) {
			$r = RINNOVOMA_Access::pause( $sub, isset( $_POST['rinnovoma_months'] ) ? absint( $_POST['rinnovoma_months'] ) : 1 );
			if ( true === $r ) {
				wc_add_notice( __( 'Your subscription will pause at the end of this period.', self::TD ) );
			}
		} elseif ( 'resume' === $todo ) {
			foreach ( RINNOVO_Subscriptions::for_user( $user ) as $s ) {
				if ( RINNOVOMA_Access::pause_of( $s ) ) {
					$r = RINNOVOMA_Access::resume( $s );
				}
			}
			if ( true === $r ) {
				wc_add_notice( __( 'Welcome back! Your subscription continues.', self::TD ) );
			}
		} elseif ( 'switch' === $todo && $sub ) {
			$to      = isset( $_POST['rinnovoma_to'] ) ? absint( $_POST['rinnovoma_to'] ) : 0;
			$options = RINNOVOMA_Access::switch_options( $sub );
			if ( isset( $options[ $to ] ) ) {
				WC()->cart->empty_cart();
				WC()->session->set( RINNOVOMA_Access::SESSION, (int) $sub->id );
				$p = $options[ $to ];
				if ( $p->is_type( 'variation' ) ) {
					WC()->cart->add_to_cart( $p->get_parent_id(), 1, $p->get_id(), $p->get_variation_attributes() );
				} else {
					WC()->cart->add_to_cart( $p->get_id(), 1 );
				}
				wp_safe_redirect( wc_get_checkout_url() );
				exit;
			}
		}
		if ( is_wp_error( $r ) ) {
			wc_add_notice( $r->get_error_message(), 'error' );
		}
		wp_safe_redirect( $back );
		exit;
	}
}
