<?php
/**
 * Course commerce: every course sellable as a WooCommerce product, inside the same membership engine.
 *
 * Membership › Courses connects products to courses (LearnDash, LifterLMS, or a category of course pages):
 * how long access lasts and when it starts. One product can unlock several courses (a bundle) and one course can
 * be unlocked by several products, or be included in tiers (the course / tier matrix). A purchase is its own
 * entitlement in the ledger, so cancelling a membership never removes a course bought on its own.
 *
 * Also "connect a category to a product": posts in a category connected to a product can be read only by
 * customers with an active subscription to (or a purchase of) that product. No field on each post.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Courses.
 */
class RINNOVOMEM_Courses {

	const TD      = 'rinnovo-membership-content-pro';
	const OPTION  = 'rinnovomem_course_map';
	const PRODUCT = '_rinnovomem_product';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'rinnovomem_admin_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'rinnovomem_admin_tab_courses', array( __CLASS__, 'render' ) );
		add_action( 'rinnovomem_admin_save_courses', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'paid' ), 25 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'paid' ), 25 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'undone' ), 25 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'undone' ), 25 );
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'subscribed' ), 25, 2 );
		add_action( 'rinnovo_subscription_status_changed', array( __CLASS__, 'sub_status' ), 25, 2 );
		add_filter( 'rinnovomem_can_view', array( __CLASS__, 'can_view' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'term_hooks' ), 20 );
		add_action( 'created_term', array( __CLASS__, 'save_term' ), 20, 3 );
		add_action( 'edited_term', array( __CLASS__, 'save_term' ), 20, 3 );
	}

	/**
	 * Tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['courses'] = __( 'Courses', self::TD );
		return $tabs;
	}

	/**
	 * Mapping rows.
	 *
	 * @return array[] product, courses, days, start, date.
	 */
	public static function map() {
		$out = array();
		foreach ( (array) get_option( self::OPTION, array() ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['product'] ) || empty( $row['courses'] ) ) {
				continue;
			}
			$out[] = wp_parse_args(
				$row,
				array(
					'product' => 0,
					'courses' => array(),
					'days'    => 0,
					'start'   => 'purchase',
					'date'    => '',
				)
			);
		}
		return $out;
	}

	/**
	 * Courses that can be sold: LearnDash and LifterLMS courses, and categories of course pages.
	 *
	 * @return array<string, string> key (post ID or cat:term) => label.
	 */
	public static function catalogue() {
		$out   = array();
		$types = array_filter( array( 'sfwd-courses', 'course' ), 'post_type_exists' );
		if ( $types ) {
			foreach ( get_posts(
				array(
					'post_type'      => array_values( $types ),
					'posts_per_page' => 300,
					'post_status'    => 'publish',
				)
			) as $p ) {
				$out[ (string) $p->ID ] = $p->post_title;
			}
		}
		foreach ( get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 200,
			)
		) as $term ) {
			/* translators: %s: category. */
			$out[ 'cat:' . $term->term_id ] = sprintf( __( 'Course pages in "%s"', self::TD ), $term->name );
		}
		return $out;
	}

	/**
	 * Label of a course key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function label( $key ) {
		if ( 0 === strpos( (string) $key, 'cat:' ) ) {
			$term = get_term( (int) substr( $key, 4 ) );
			return $term && ! is_wp_error( $term ) ? $term->name : $key;
		}
		return get_the_title( (int) $key );
	}

	/**
	 * Rows for a product (or its parent).
	 *
	 * @param int $product_id Product or variation.
	 * @return array[]
	 */
	public static function rows_for( $product_id ) {
		$ids = array( (int) $product_id, (int) wp_get_post_parent_id( (int) $product_id ) );
		return array_values(
			array_filter(
				self::map(),
				static function ( $r ) use ( $ids ) {
					return in_array( (int) $r['product'], $ids, true );
				}
			)
		);
	}

	/**
	 * Start and end of access for a row.
	 *
	 * @param array $row  Row.
	 * @param int   $paid Paid at.
	 * @return int[] start, end (0 = lifetime).
	 */
	private static function window( $row, $paid ) {
		$start = 'date' === $row['start'] && '' !== $row['date'] ? max( $paid, (int) strtotime( $row['date'] . ' 00:00:00 ' . wp_timezone_string() ) ) : $paid;
		return array( $start, (int) $row['days'] ? $start + (int) $row['days'] * DAY_IN_SECONDS : 0 );
	}

	/**
	 * Paid order: one-time course products are enrolled.
	 *
	 * @param int $order_id Order.
	 */
	public static function paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_customer_id() || $order->get_meta( '_rinnovo_renewal' ) ) {
			return;
		}
		$paid = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time();
		$done = false;
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( '_rinnovo_plan' ) ) {
				continue; // Subscriptions: see subscribed().
			}
			foreach ( self::rows_for( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() ) as $row ) {
				list( $start, $end ) = self::window( $row, $paid );
				foreach ( $row['courses'] as $course ) {
					RINNOVOMEM_Ledger::add( $order->get_customer_id(), 'course', (string) $course, 'order', $order->get_id(), $start, $end, $item->get_name() );
					$done = true;
				}
			}
		}
		if ( $done ) {
			RINNOVOMEM_Members::sync( $order->get_customer_id() );
		}
	}

	/**
	 * Refunded or cancelled order: its course access ends.
	 *
	 * @param int $order_id Order.
	 */
	public static function undone( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && RINNOVOMEM_Ledger::end_source( 'order', (int) $order_id ) && $order->get_customer_id() ) {
			RINNOVOMEM_Members::sync( $order->get_customer_id() );
		}
	}

	/**
	 * Subscription products mapped to courses: access while the subscription gives access.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order Order.
	 */
	public static function subscribed( $sub, $order ) {
		$rows = self::rows_for( (int) $sub->product_id );
		foreach ( $rows as $row ) {
			list( $start, $end ) = self::window( $row, time() );
			foreach ( $row['courses'] as $course ) {
				RINNOVOMEM_Ledger::add( (int) $sub->user_id, 'course', (string) $course, 'subscription', (int) $sub->id, $start, $end, $sub->name );
			}
		}
		if ( $rows ) {
			RINNOVOMEM_Members::sync( (int) $sub->user_id );
		}
	}

	/**
	 * Subscription ended: its course rows end (at the paid-through date when cancellations keep the period).
	 *
	 * @param object $sub Subscription.
	 * @param string $old Old status.
	 */
	public static function sub_status( $sub, $old ) {
		if ( ! in_array( $sub->status, array( 'cancelled', 'expired' ), true ) || ! self::rows_for( (int) $sub->product_id ) ) {
			return;
		}
		$at = RINNOVOMEM_Members::sub_gives_access( $sub ) && $sub->next_payment ? strtotime( $sub->next_payment . ' UTC' ) : 0;
		RINNOVOMEM_Ledger::end_source( 'subscription', (int) $sub->id, 'ended', $at );
		RINNOVOMEM_Members::sync( (int) $sub->user_id );
	}

	/**
	 * Products a user owns now: active subscriptions and paid, not refunded purchases.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function owned_products( $user_id ) {
		static $cache = array();
		if ( isset( $cache[ $user_id ] ) ) {
			return $cache[ $user_id ];
		}
		$out = array();
		foreach ( RINNOVO_Subscriptions::for_user( $user_id ) as $sub ) {
			if ( RINNOVOMEM_Members::sub_gives_access( $sub ) ) {
				$out[] = (int) $sub->product_id;
				$out[] = (int) wp_get_post_parent_id( (int) $sub->product_id );
			}
		}
		foreach ( wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'processing', 'completed' ),
				'limit'       => 200,
			)
		) as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! $item->get_meta( '_rinnovo_plan' ) && abs( $order->get_qty_refunded_for_item( $item->get_id() ) ) < $item->get_quantity() ) {
					$out[] = (int) $item->get_product_id();
					$out[] = (int) $item->get_variation_id();
				}
			}
		}
		$cache[ $user_id ] = array_values( array_unique( array_filter( $out ) ) );
		return $cache[ $user_id ];
	}

	/**
	 * Course pages bought on their own, and categories connected to a product.
	 *
	 * @param array    $result  Result.
	 * @param int      $post_id Post.
	 * @param int|null $user_id User.
	 * @return array
	 */
	public static function can_view( $result, $post_id, $user_id ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( $user_id && ( user_can( $user_id, 'edit_others_posts' ) || user_can( $user_id, 'manage_woocommerce' ) ) ) {
			return $result;
		}
		$terms = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
		$terms = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );

		// A course bought on its own unlocks its pages even without the tier.
		if ( ! $result['ok'] && 'drip' !== $result['reason'] && $user_id ) {
			foreach ( RINNOVOMEM_Ledger::active( $user_id, 'course' ) as $row ) {
				if ( (string) $row->target === (string) $post_id || ( 0 === strpos( $row->target, 'cat:' ) && in_array( (int) substr( $row->target, 4 ), $terms, true ) ) ) {
					$result['ok']     = true;
					$result['reason'] = '';
					return $result;
				}
			}
		}

		// Categories connected to a product.
		foreach ( $terms as $term_id ) {
			$products = array_filter( array_map( 'absint', (array) get_term_meta( $term_id, self::PRODUCT, true ) ) );
			if ( ! $products ) {
				continue;
			}
			if ( ! $user_id ) {
				return array_merge( $result, array( 'ok' => false, 'reason' => 'login', 'product' => $products[0] ) );
			}
			if ( ! array_intersect( $products, self::owned_products( $user_id ) ) ) {
				return array_merge( $result, array( 'ok' => false, 'reason' => 'product', 'product' => $products[0] ) );
			}
		}
		return $result;
	}

	/**
	 * "Connected to a product" field on categories.
	 */
	public static function term_hooks() {
		add_action( 'category_add_form_fields', array( __CLASS__, 'term_add' ), 20 );
		add_action( 'category_edit_form_fields', array( __CLASS__, 'term_edit' ), 20 );
	}

	/**
	 * Product select for terms.
	 *
	 * @param int[] $selected Products.
	 */
	private static function term_select( $selected ) {
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_nonce_field( 'rinnovomem_term_product', 'rinnovomem_term_product_nonce' );
		echo '<select class="wc-product-search" multiple="multiple" style="width:95%" name="rinnovomem_term_product[]" data-action="woocommerce_json_search_products_and_variations" data-placeholder="' . esc_attr__( 'Search for products…', self::TD ) . '">';
		foreach ( $selected as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				echo '<option value="' . (int) $id . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select><p class="description">' . esc_html__( 'Only logged-in customers with an active subscription to, or a purchase of, one of these products can read the posts of this category. Everyone else sees "Log in" or "Get access".', self::TD ) . '</p>';
	}

	/**
	 * Add form.
	 */
	public static function term_add() {
		echo '<div class="form-field"><label>' . esc_html__( 'Connected to a product', self::TD ) . '</label>';
		self::term_select( array() );
		echo '</div>';
	}

	/**
	 * Edit form.
	 *
	 * @param WP_Term $term Term.
	 */
	public static function term_edit( $term ) {
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Connected to a product', self::TD ) . '</th><td>';
		self::term_select( array_map( 'absint', (array) get_term_meta( $term->term_id, self::PRODUCT, true ) ) );
		echo '</td></tr>';
	}

	/**
	 * Save.
	 *
	 * @param int    $term_id  Term.
	 * @param int    $tt_id    Term taxonomy.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function save_term( $term_id, $tt_id, $taxonomy ) {
		if ( 'category' !== $taxonomy || ! isset( $_POST['rinnovomem_term_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovomem_term_product_nonce'] ) ), 'rinnovomem_term_product' ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$ids = isset( $_POST['rinnovomem_term_product'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['rinnovomem_term_product'] ) ) ) ) : array();
		if ( $ids ) {
			update_term_meta( $term_id, self::PRODUCT, $ids );
		} else {
			delete_term_meta( $term_id, self::PRODUCT );
		}
	}

	/**
	 * Product picker for the tab.
	 *
	 * @param string $name     Name.
	 * @param int    $selected Product.
	 * @return string
	 */
	private static function picker( $name, $selected ) {
		$html = '<select class="wc-product-search" style="width:100%" name="' . esc_attr( $name ) . '" data-allow_clear="true" data-action="woocommerce_json_search_products_and_variations" data-placeholder="' . esc_attr__( 'Search for a product…', self::TD ) . '">';
		$p    = $selected ? wc_get_product( $selected ) : null;
		if ( $p ) {
			$html .= '<option value="' . (int) $selected . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * One mapping row.
	 *
	 * @param int|string $i       Index.
	 * @param array      $row     Row.
	 * @param array      $courses Catalogue.
	 * @return string
	 */
	private static function row( $i, $row, $courses ) {
		$n    = 'map[' . $i . ']';
		$html = '<tr><td>' . self::picker( $n . '[product]', (int) $row['product'] ) . '</td><td><select class="wc-enhanced-select" multiple="multiple" style="width:100%" name="' . esc_attr( $n ) . '[courses][]">';
		foreach ( $courses as $key => $label ) {
			$html .= '<option value="' . esc_attr( $key ) . '"' . selected( in_array( (string) $key, array_map( 'strval', (array) $row['courses'] ), true ), true, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select></td><td><input type="number" min="0" style="width:80px" name="' . esc_attr( $n ) . '[days]" value="' . (int) $row['days'] . '" /><br /><small>' . esc_html__( '0 = lifetime', self::TD ) . '</small></td><td><select name="' . esc_attr( $n ) . '[start]"><option value="purchase"' . selected( $row['start'], 'purchase', false ) . '>' . esc_html__( 'At purchase', self::TD ) . '</option><option value="date"' . selected( $row['start'], 'date', false ) . '>' . esc_html__( 'On a date', self::TD ) . '</option></select> <input type="date" name="' . esc_attr( $n ) . '[date]" value="' . esc_attr( $row['date'] ) . '" /></td><td><button type="button" class="button-link-delete" onclick="this.closest(\'tr\').remove()">' . esc_html__( 'Remove', self::TD ) . '</button></td></tr>';
		return $html;
	}

	/**
	 * Tab content: mapping and matrix.
	 */
	public static function render() {
		$courses = self::catalogue();
		$map     = self::map();
		echo '<h2>' . esc_html__( 'Course & product mapping', self::TD ) . '</h2><p>' . esc_html__( 'Sell any course as a WooCommerce product: pick the product, the course(s) it unlocks, how long access lasts and when it starts. On payment the customer is enrolled, the access is recorded and the course shows in their dashboard. Refunds and cancellations follow the lifecycle rules. One product can unlock several courses (a bundle), and one course can be unlocked by several products.', self::TD ) . '</p>';
		echo '<table class="widefat" id="rinnovomem-map"><thead><tr><th style="width:26%">' . esc_html__( 'Product', self::TD ) . '</th><th>' . esc_html__( 'Course(s)', self::TD ) . '</th><th style="width:100px">' . esc_html__( 'Access (days)', self::TD ) . '</th><th style="width:230px">' . esc_html__( 'Access starts', self::TD ) . '</th><th style="width:70px"></th></tr></thead><tbody>';
		foreach ( $map as $i => $row ) {
			echo self::row( $i, $row, $courses ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		}
		echo self::row( count( $map ), array( 'product' => 0, 'courses' => array(), 'days' => 0, 'start' => 'purchase', 'date' => '' ), $courses ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '</tbody></table><p class="description">' . esc_html__( 'Save to get another empty row.', self::TD ) . '</p>';

		// Matrix.
		$tiers = RINNOVOMEM_Tiers::all();
		$keys  = array_filter(
			array_keys( $courses ),
			static function ( $k ) {
				return ctype_digit( (string) $k );
			}
		);
		echo '<h2>' . esc_html__( 'Courses and tiers', self::TD ) . '</h2><p>' . esc_html__( 'Which tiers include each course, next to the products that sell it on its own. Individual purchases and memberships are separate: a member who cancels Gold keeps a course bought on its own.', self::TD ) . '</p>';
		if ( ! $keys || ! $tiers ) {
			echo '<p><em>' . esc_html__( 'Add LearnDash or LifterLMS courses and tiers to see the matrix. Categories of course pages get tiers in their own "Members only" setting.', self::TD ) . '</em></p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Course', self::TD ) . '</th><th>' . esc_html__( 'Sold on its own', self::TD ) . '</th>';
			foreach ( $tiers as $tier ) {
				echo '<th style="text-align:center">' . esc_html( $tier['name'] ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $keys as $key ) {
				$selling = array();
				foreach ( $map as $row ) {
					if ( in_array( (string) $key, array_map( 'strval', $row['courses'] ), true ) ) {
						$p = wc_get_product( $row['product'] );
						if ( $p ) {
							$selling[] = wp_strip_all_tags( $p->get_name() . ' — ' . wc_price( wc_get_price_to_display( $p ) ) ) . ( (int) $row['days'] ? ' · ' . sprintf( /* translators: %d: days. */ __( '%d days', self::TD ), (int) $row['days'] ) : ' · ' . __( 'lifetime', self::TD ) );
						}
					}
				}
				echo '<tr><td>' . esc_html( $courses[ $key ] ) . '</td><td>' . esc_html( $selling ? implode( '; ', $selling ) : '—' ) . '</td>';
				foreach ( $tiers as $id => $tier ) {
					echo '<td style="text-align:center"><input type="checkbox" name="matrix[' . esc_attr( $id ) . '][]" value="' . (int) $key . '"' . checked( in_array( (int) $key, $tier['courses'], true ), true, false ) . ' aria-label="' . esc_attr( $courses[ $key ] . ' / ' . $tier['name'] ) . '" /></td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table><input type="hidden" name="matrix_on" value="1" />';
		}
		submit_button();
	}

	/**
	 * Save.
	 */
	public static function save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked by RINNOVOMEM_Admin::save(); cleaned below.
		$rows    = array();
		$allowed = array_map( 'strval', array_keys( self::catalogue() ) );
		foreach ( isset( $_POST['map'] ) ? (array) wp_unslash( $_POST['map'] ) : array() as $raw ) {
			$product = isset( $raw['product'] ) ? absint( $raw['product'] ) : 0;
			$courses = array_values( array_intersect( array_map( 'strval', isset( $raw['courses'] ) ? (array) $raw['courses'] : array() ), $allowed ) );
			if ( ! $product || ! $courses ) {
				continue;
			}
			$date   = isset( $raw['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $raw['date'] ) ? (string) $raw['date'] : '';
			$rows[] = array(
				'product' => $product,
				'courses' => $courses,
				'days'    => isset( $raw['days'] ) ? absint( $raw['days'] ) : 0,
				'start'   => isset( $raw['start'] ) && 'date' === $raw['start'] && '' !== $date ? 'date' : 'purchase',
				'date'    => $date,
			);
		}
		update_option( self::OPTION, $rows, false );
		if ( ! empty( $_POST['matrix_on'] ) ) {
			$matrix = isset( $_POST['matrix'] ) ? (array) wp_unslash( $_POST['matrix'] ) : array();
			$tiers  = array();
			foreach ( RINNOVOMEM_Tiers::all() as $id => $tier ) {
				$tier['courses'] = isset( $matrix[ $id ] ) ? array_map( 'absint', (array) $matrix[ $id ] ) : array();
				$tiers[]         = $tier;
			}
			RINNOVOMEM_Tiers::save( $tiers );
		}
		// phpcs:enable
		set_transient( 'rinnovomem_tab_note_' . get_current_user_id(), array( 'success', __( 'Courses saved.', self::TD ) ), 60 );
	}
}
