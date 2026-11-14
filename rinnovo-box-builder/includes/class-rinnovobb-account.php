<?php
/**
 * My Account: subscribers change their next box (or every box) until the deadline, copy their previous box,
 * and save a favourite box.
 *
 * @package RinnovoBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Account.
 */
class RINNOVOBB_Account {

	const TD  = 'rinnovo-box-builder';
	const FAV = 'rinnovobb_favourites';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_account_' . RINNOVO_Account::endpoint() . '_endpoint', array( __CLASS__, 'panel' ), 4 );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 6 );
	}

	/**
	 * Subscriptions page in My Account.
	 *
	 * @return string
	 */
	public static function url() {
		return wc_get_account_endpoint_url( RINNOVO_Account::endpoint() );
	}

	/**
	 * Subscriptions of a user with a box to choose.
	 *
	 * @param int $user_id User.
	 * @return array[] sub, product, config.
	 */
	private static function boxes( $user_id ) {
		$out = array();
		foreach ( RINNOVO_Subscriptions::for_user( $user_id ) as $sub ) {
			if ( ! in_array( $sub->status, array( 'active', 'trial', 'on-hold' ), true ) ) {
				continue;
			}
			$product = wc_get_product( (int) $sub->product_id );
			if ( ! $product || ! RINNOVOBB_Config::active( $product ) ) {
				continue;
			}
			$config = RINNOVOBB_Config::get( $product );
			if ( ! RINNOVOBB_Config::interactive( $config ) ) {
				continue;
			}
			$out[] = array(
				'sub'     => $sub,
				'product' => $product,
				'config'  => $config,
			);
		}
		return $out;
	}

	/**
	 * Box editors above the subscriptions list.
	 */
	public static function panel() {
		$boxes = self::boxes( get_current_user_id() );
		if ( ! $boxes ) {
			return;
		}
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		echo '<div class="rinnovobb-account">';
		foreach ( $boxes as $b ) {
			$sub    = $b['sub'];
			$config = $b['config'];
			$time   = RINNOVOBB_Selections::next_time( $sub );
			$cycle  = RINNOVOBB_Selections::cycle( $time );
			$cut    = RINNOVOBB_Selections::deadline( $config, $time );
			$open   = RINNOVOBB_Selections::open( $sub, $config );
			$next   = RINNOVOBB_Selections::get( $sub->id, $cycle );
			$next   = is_array( $next ) && ! isset( $next['taken'] ) ? $next : null;
			$shown  = $next ? $next : RINNOVOBB_Selections::for_delivery( $sub, $config, $time );
			$favs   = (array) get_user_meta( get_current_user_id(), self::FAV, true );
			echo '<details class="rinnovobb-account-box"' . ( $open ? ' open' : '' ) . '><summary><strong>' . esc_html( $b['product']->get_name() ) . '</strong> — ';
			/* translators: %s: date. */
			echo esc_html( sprintf( __( 'next box %s', self::TD ), wp_date( get_option( 'date_format' ), $time ) ) ) . '</summary>';
			if ( $open ) {
				if ( $config['deadline']['enabled'] ) {
					/* translators: %s: date and time. */
					echo '<p class="rinnovobb-deadline">' . esc_html( sprintf( __( 'You can change this box until %s.', self::TD ), wp_date( $fmt, $cut ) ) ) . ( $config['rules']['autofill'] ? ' ' . esc_html__( 'If you don\'t choose, we\'ll pack our selection for you.', self::TD ) : '' ) . '</p>';
				}
				echo '<p>' . esc_html( $next ? __( 'You chose a box for this delivery only.', self::TD ) : __( 'This is your usual box.', self::TD ) ) . '</p>';
				echo '<form method="post" class="rinnovobb-account-form">';
				wp_nonce_field( 'rinnovobb_account_' . $sub->id, 'rinnovobb_nonce' );
				echo '<input type="hidden" name="rinnovobb_sub" value="' . (int) $sub->id . '" />';
				echo RINNOVOBB_Render::form( $b['product'], $config, $shown, array( 'context' => 'account', 'time' => $time ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
				echo '<p class="rinnovobb-actions">';
				echo '<button type="submit" class="button" name="rinnovobb_todo" value="next">' . esc_html__( 'Save for my next box only', self::TD ) . '</button> ';
				echo '<button type="submit" class="button" name="rinnovobb_todo" value="every">' . esc_html__( 'Save for every box', self::TD ) . '</button> ';
				if ( RINNOVOBB_Selections::get( $sub->id, 'last' ) ) {
					echo '<button type="submit" class="button" name="rinnovobb_todo" value="copy" formnovalidate>' . esc_html__( 'Copy my previous box', self::TD ) . '</button> ';
				}
				echo '<button type="submit" class="button" name="rinnovobb_todo" value="fav">' . esc_html__( 'Save as my favourite', self::TD ) . '</button> ';
				if ( isset( $favs[ $sub->product_id ] ) ) {
					echo '<button type="submit" class="button" name="rinnovobb_todo" value="usefav" formnovalidate>' . esc_html__( 'Use my favourite', self::TD ) . '</button> ';
				}
				if ( $next ) {
					echo '<button type="submit" class="button" name="rinnovobb_todo" value="reset" formnovalidate>' . esc_html__( 'Back to my usual box', self::TD ) . '</button>';
				}
				echo '</p></form>';
			} else {
				$snap = RINNOVOBB_Engine::snapshot( $config, $shown, $b['product'], $time );
				echo '<p>' . esc_html__( 'This box is locked: it is being prepared.', self::TD ) . '</p><p>' . esc_html( RINNOVOBB_Engine::text( $snap ) ) . '</p>';
			}
			echo '</details>';
		}
		echo '</div>';
	}

	/**
	 * Save changes.
	 */
	public static function handle() {
		if ( empty( $_POST['rinnovobb_sub'] ) || empty( $_POST['rinnovobb_todo'] ) || ! is_user_logged_in() ) {
			return;
		}
		$sub_id = absint( $_POST['rinnovobb_sub'] );
		if ( ! isset( $_POST['rinnovobb_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovobb_nonce'] ) ), 'rinnovobb_account_' . $sub_id ) ) {
			return;
		}
		$sub = RINNOVO_Subscriptions::get( $sub_id );
		if ( ! $sub || (int) $sub->user_id !== get_current_user_id() ) {
			return;
		}
		$product = wc_get_product( (int) $sub->product_id );
		if ( ! $product || ! RINNOVOBB_Config::active( $product ) ) {
			return;
		}
		$config = RINNOVOBB_Config::get( $product );
		$todo   = sanitize_key( wp_unslash( $_POST['rinnovobb_todo'] ) );
		$time   = RINNOVOBB_Selections::next_time( $sub );
		$cycle  = RINNOVOBB_Selections::cycle( $time );
		if ( ! RINNOVOBB_Selections::open( $sub, $config ) ) {
			wc_add_notice( __( 'The deadline for this box has passed: it is being prepared.', self::TD ), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}
		$favs = (array) get_user_meta( get_current_user_id(), self::FAV, true );
		$sel  = null;
		if ( 'reset' === $todo ) {
			RINNOVOBB_Selections::forget( $sub->id, $cycle );
			wc_add_notice( __( 'Your next box is your usual box again.', self::TD ) );
		} elseif ( 'copy' === $todo || 'usefav' === $todo ) {
			$from = 'copy' === $todo ? RINNOVOBB_Selections::get( $sub->id, 'last' ) : ( isset( $favs[ $sub->product_id ] ) ? $favs[ $sub->product_id ] : null );
			$from = is_array( $from ) && isset( $from['sel'] ) ? $from['sel'] : $from;
			$sel  = is_array( $from ) ? RINNOVOBB_Engine::validate( $config, $from, (int) $sub->quantity ) : null;
			if ( is_array( $sel ) ) {
				RINNOVOBB_Selections::put( $sub->id, $cycle, $sel );
				wc_add_notice( 'copy' === $todo ? __( 'Your previous box is now your next box.', self::TD ) : __( 'Your favourite box is now your next box.', self::TD ) );
			} else {
				wc_add_notice( __( 'That box can\'t be used any more (items changed or out of stock). Please choose again.', self::TD ), 'error' );
			}
		} else {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read() cleans every value.
			$raw = isset( $_POST['rinnovobb'] ) && is_array( $_POST['rinnovobb'] ) ? wp_unslash( $_POST['rinnovobb'] ) : array();
			$sel = RINNOVOBB_Engine::validate( $config, RINNOVOBB_Engine::read( $config, $raw ), (int) $sub->quantity );
			if ( is_wp_error( $sel ) ) {
				foreach ( array_unique( $sel->get_error_messages() ) as $m ) {
					wc_add_notice( $m, 'error' );
				}
			} elseif ( 'fav' === $todo ) {
				$favs[ $sub->product_id ] = $sel;
				update_user_meta( get_current_user_id(), self::FAV, $favs );
				wc_add_notice( __( 'Saved as your favourite box.', self::TD ) );
			} elseif ( 'every' === $todo ) {
				RINNOVOBB_Selections::put( $sub->id, 'default', $sel );
				RINNOVOBB_Selections::forget( $sub->id, $cycle );
				wc_add_notice( __( 'Saved: you get this box every time.', self::TD ) );
			} else {
				RINNOVOBB_Selections::put( $sub->id, $cycle, $sel );
				wc_add_notice( __( 'Saved for your next box only.', self::TD ) );
			}
		}
		if ( is_array( $sel ) && 'product' !== $config['pricing'] && RINNOVO_Manager::is_automatic( $sub ) ) {
			wc_add_notice( __( 'Your card is charged the usual amount; any price difference is settled on a later payment.', self::TD ), 'notice' );
		}
		wp_safe_redirect( self::url() );
		exit;
	}
}
