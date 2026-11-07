<?php
/**
 * My Account > My plans: every subscription with its bundle contents, switch to another variation
 * (upgrade / downgrade with the prorated difference shown first), same-price swaps, and the item
 * editor for pick-your-own boxes (changes apply from the next renewal).
 *
 * @package RinnovoBundles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer area.
 */
class RINNOVOBV_Account {

	const TD       = 'rinnovo-bundles-variable-subscriptions-pro';
	const ENDPOINT = 'my-plans';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 18 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'content' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 7 );
	}

	/**
	 * Endpoint.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Refresh permalinks once per version.
	 */
	public static function maybe_flush() {
		if ( get_option( 'rinnovobv_rewrite_version' ) !== RINNOVOBV_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'rinnovobv_rewrite_version', RINNOVOBV_VERSION );
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
	 * Subscriptions of the user this page can change.
	 *
	 * @param int $user_id User.
	 * @return object[]
	 */
	public static function subs( $user_id ) {
		$out = array();
		foreach ( RINNOVO_Subscriptions::for_user( $user_id ) as $sub ) {
			if ( ! in_array( $sub->status, RINNOVO_Subscriptions::live_statuses(), true ) ) {
				continue;
			}
			$def = RINNOVOBV_Bundle::def( (int) $sub->product_id );
			if ( $def['enabled'] || RINNOVOBV_Switch::options( $sub ) ) {
				$out[] = $sub;
			}
		}
		return $out;
	}

	/**
	 * Menu item (only for customers with a bundle or a variable subscription).
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function menu( $items ) {
		if ( ! is_user_logged_in() || ! self::subs( get_current_user_id() ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$out[ self::ENDPOINT ] = self::title();
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = self::title();
		}
		return $out;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public static function title() {
		return __( 'My plans', self::TD );
	}

	/**
	 * Hidden fields.
	 *
	 * @param string $todo   Action.
	 * @param int    $sub_id Subscription.
	 */
	private static function fields( $todo, $sub_id ) {
		wp_nonce_field( 'rinnovobv_' . $todo . '_' . $sub_id, 'rinnovobv_nonce' );
		echo '<input type="hidden" name="rinnovobv_do" value="' . esc_attr( $todo ) . '" /><input type="hidden" name="rinnovobv_sub" value="' . (int) $sub_id . '" />';
	}

	/**
	 * Money in the subscription's currency.
	 *
	 * @param float  $amount Amount.
	 * @param object $sub    Subscription.
	 * @return string
	 */
	private static function money( $amount, $sub ) {
		return wp_strip_all_tags( wc_price( $amount, array( 'currency' => $sub->currency ) ) );
	}

	/**
	 * The page.
	 */
	public static function content() {
		$subs = self::subs( get_current_user_id() );
		if ( ! $subs ) {
			echo '<p>' . esc_html__( 'You have no plans to manage here.', self::TD ) . '</p>';
			return;
		}
		foreach ( $subs as $sub ) {
			self::card( $sub );
		}
	}

	/**
	 * One subscription.
	 *
	 * @param object $sub Subscription.
	 */
	private static function card( $sub ) {
		$def  = RINNOVOBV_Bundle::def( (int) $sub->product_id );
		$data = RINNOVOBV_Settings::sub_data( $sub->id );
		echo '<article class="rinnovobv-card"><header><h3>' . esc_html( $sub->name ) . '</h3><span>' . esc_html( RINNOVO_Subscriptions::status_label( $sub->status ) . ' · ' . RINNOVO_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ) ) . '</span></header>';
		if ( $sub->next_payment ) {
			/* translators: %s: date. */
			echo '<p class="rinnovobv-facts">' . esc_html( sprintf( __( 'Next renewal: %s', self::TD ), RINNOVO_Subscriptions::date_label( $sub->next_payment ) ) ) . '</p>';
		}

		// Everything billed together.
		if ( $data['merged'] ) {
			echo '<h4>' . esc_html__( 'Billed together in this subscription', self::TD ) . '</h4><ul>';
			foreach ( $data['merged'] as $m ) {
				echo '<li>' . esc_html( ( isset( $m['name'] ) ? $m['name'] : '' ) . ( ! empty( $m['qty'] ) && $m['qty'] > 1 ? ' × ' . (int) $m['qty'] : '' ) . ( ! empty( $m['items'] ) && is_array( $m['items'] ) ? ' (' . RINNOVOBV_Bundle::contents_text( $m['items'] ) . ')' : '' ) ) . '</li>';
			}
			echo '</ul>';
		}

		// Contents.
		if ( $def['enabled'] ) {
			$items = 'choose' === $def['mode'] ? $data['items'] : $def['components'];
			if ( $items ) {
				echo '<p><strong>' . esc_html__( 'In your box:', self::TD ) . '</strong> ' . esc_html( RINNOVOBV_Bundle::contents_text( $items ) ) . '</p>';
			}
			if ( 'choose' === $def['mode'] && RINNOVOBV_Settings::get( 'swap_items' ) ) {
				self::item_editor( $sub, $def, $data );
			}
		}

		// Switch / swap.
		$options = RINNOVOBV_Switch::options( $sub );
		if ( $options ) {
			$swaps   = array_filter(
				$options,
				static function ( $o ) {
					return 'swap' === $o['kind'];
				}
			);
			$changes = array_diff_key( $options, $swaps );
			if ( $swaps ) {
				echo '<h4>' . esc_html__( 'Swap (same price)', self::TD ) . '</h4><form method="post" class="rinnovobv-options">';
				self::fields( 'switch', (int) $sub->id );
				foreach ( $swaps as $id => $o ) {
					echo '<p><span>' . esc_html( wc_get_formatted_variation( $o['product'], true, false, false ) ) . '</span> <button class="button" name="target" value="' . (int) $id . '">' . esc_html__( 'Swap', self::TD ) . '</button></p>';
				}
				echo '</form>';
			}
			if ( $changes ) {
				echo '<h4>' . esc_html__( 'Change plan', self::TD ) . '</h4><form method="post" class="rinnovobv-options">';
				self::fields( 'switch', (int) $sub->id );
				foreach ( $changes as $id => $o ) {
					$diff = RINNOVOBV_Switch::difference( $sub, $o );
					if ( $diff > 0 ) {
						/* translators: %s: amount. */
						$note = sprintf( __( 'Pay %s now for the rest of this period', self::TD ), self::money( $diff, $sub ) );
					} elseif ( $diff < 0 ) {
						/* translators: %s: amount. */
						$note = sprintf( __( '%s credit on your next renewal', self::TD ), self::money( -$diff, $sub ) );
					} else {
						$note = __( 'Nothing to pay now', self::TD );
					}
					$label = 'upgrade' === $o['kind'] ? __( 'Upgrade', self::TD ) : ( 'downgrade' === $o['kind'] ? __( 'Downgrade', self::TD ) : __( 'Switch', self::TD ) );
					echo '<p><span><strong>' . esc_html( wc_get_formatted_variation( $o['product'], true, false, false ) ) . '</strong> · ' . esc_html( RINNOVO_Subscriptions::price_string( $o['amount'], $o['plan']['period'], $o['plan']['interval'], $sub->currency ) ) . '<br /><small>' . esc_html( $note ) . '</small></span> <button class="button" name="target" value="' . (int) $id . '" onclick="return confirm(' . esc_attr( wp_json_encode( $note . '?' ) ) . ')">' . esc_html( $label ) . '</button></p>';
				}
				echo '</form>';
			}
		}
		echo '</article>';
	}

	/**
	 * Item editor of a pick-your-own box.
	 *
	 * @param object $sub  Subscription.
	 * @param array  $def  Bundle.
	 * @param array  $data Saved data.
	 */
	private static function item_editor( $sub, $def, $data ) {
		$current = array();
		foreach ( $data['items'] as $it ) {
			$current[ (int) $it[0] ] = (int) $it[1];
		}
		$cut = (int) RINNOVOBV_Settings::get( 'swap_cutoff' );
		echo '<details class="rinnovobv-edit"><summary>' . esc_html__( 'Change my items', self::TD ) . '</summary>';
		if ( $sub->next_payment && strtotime( $sub->next_payment . ' UTC' ) - $cut * DAY_IN_SECONDS < time() ) {
			/* translators: %d: days. */
			echo '<p>' . esc_html( sprintf( __( 'Changes close %d days before the renewal. You can change the box again after it.', self::TD ), $cut ) ) . '</p></details>';
			return;
		}
		/* translators: 1: min, 2: max. */
		echo '<form method="post" class="rinnovobv-picker" data-min="' . (int) $def['min'] . '" data-max="' . (int) $def['max'] . '"><p>' . esc_html( sprintf( __( 'Choose %1$d to %2$d items. Changes apply from your next renewal.', self::TD ), $def['min'], $def['max'] ? $def['max'] : 99 ) ) . '</p>';
		self::fields( 'items', (int) $sub->id );
		echo '<div class="rinnovobv-items">';
		foreach ( $def['choices'] as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$out = ! RINNOVOBV_Bundle::in_stock( $p, 1 );
			echo '<label class="rinnovobv-item' . ( $out ? ' is-out' : '' ) . '"><strong>' . esc_html( $p->get_name() ) . '</strong>' . ( $out ? '<em>' . esc_html__( 'Out of stock', self::TD ) . '</em>' : '' ) . '<input type="number" min="0" max="99" name="items[' . (int) $id . ']" value="' . (int) ( isset( $current[ $id ] ) ? $current[ $id ] : 0 ) . '"' . ( $out ? ' disabled' : '' ) . ' /></label>';
		}
		echo '</div><p><button class="button">' . esc_html__( 'Save my items', self::TD ) . '</button></p></form></details>';
	}

	/**
	 * Handle the forms.
	 */
	public static function handle() {
		if ( ! is_user_logged_in() || empty( $_POST['rinnovobv_do'] ) || empty( $_POST['rinnovobv_sub'] ) ) {
			return;
		}
		$todo = sanitize_key( wp_unslash( $_POST['rinnovobv_do'] ) );
		$id   = absint( $_POST['rinnovobv_sub'] );
		if ( ! isset( $_POST['rinnovobv_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rinnovobv_nonce'] ) ), 'rinnovobv_' . $todo . '_' . $id ) ) {
			return;
		}
		$sub = RINNOVO_Subscriptions::get( $id );
		if ( ! $sub || (int) $sub->user_id !== get_current_user_id() || ! in_array( $sub->status, RINNOVO_Subscriptions::live_statuses(), true ) ) {
			wc_add_notice( __( 'Subscription not found.', self::TD ), 'error' );
			return;
		}
		if ( 'switch' === $todo ) {
			$r = RINNOVOBV_Switch::switch_to( $sub, isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0 );
			if ( is_wp_error( $r ) ) {
				wc_add_notice( $r->get_error_message(), 'error' );
			} elseif ( 'pay' === $r['result'] ) {
				wp_safe_redirect( $r['url'] );
				exit;
			} else {
				wc_add_notice( __( 'Your plan has been changed.', self::TD ) );
			}
		} elseif ( 'items' === $todo ) {
			$items = array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to ints below.
			foreach ( isset( $_POST['items'] ) ? (array) wp_unslash( $_POST['items'] ) : array() as $pid => $qty ) {
				$items[ absint( $pid ) ] = absint( $qty );
			}
			$r = RINNOVOBV_Switch::set_items( $sub, $items );
			if ( is_wp_error( $r ) ) {
				wc_add_notice( $r->get_error_message(), 'error' );
			} else {
				wc_add_notice( __( 'Your items have been saved for your next box.', self::TD ) );
			}
		}
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}
}
