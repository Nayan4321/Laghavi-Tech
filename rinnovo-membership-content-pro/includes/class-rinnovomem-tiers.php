<?php
/**
 * Membership tiers (for example Silver, Gold, All-Access), lowest first. A higher tier includes everything
 * of the tiers below it. "Free" is built in: any logged-in user.
 *
 * Each tier lists the WooCommerce products (or variations) that give it, and its perks: a store discount,
 * free shipping, free products, a user role and courses to enroll members in.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tiers.
 */
class RINNOVOMEM_Tiers {

	const OPTION = 'rinnovomem_tiers';
	const FREE   = 'free';

	/**
	 * Tiers of this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * A tier with every key present.
	 *
	 * @param array $tier Stored tier.
	 * @return array
	 */
	public static function normalize( $tier ) {
		$tier = wp_parse_args(
			(array) $tier,
			array(
				'id'             => '',
				'name'           => '',
				'color'          => '#7c3aed',
				'products'       => array(),
				'role'           => 0,
				'discount'       => 0,
				'discount_cats'  => array(),
				'free_shipping'  => 0,
				'free_products'  => array(),
				'courses'        => array(),
				'join_url'       => '',
			)
		);
		foreach ( array( 'products', 'discount_cats', 'free_products', 'courses' ) as $key ) {
			$tier[ $key ] = array_values( array_filter( array_map( 'absint', (array) $tier[ $key ] ) ) );
		}
		$tier['discount'] = min( 100, max( 0, (float) $tier['discount'] ) );
		return $tier;
	}

	/**
	 * All tiers, lowest first, keyed by ID. Each has its rank (1 = lowest).
	 *
	 * @return array<string, array>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			self::$cache = array();
			$rank        = 0;
			foreach ( (array) get_option( self::OPTION, array() ) as $tier ) {
				$tier = self::normalize( $tier );
				if ( '' === $tier['id'] ) {
					continue;
				}
				$tier['rank']                = ++$rank;
				self::$cache[ $tier['id'] ] = $tier;
			}
		}
		return self::$cache;
	}

	/**
	 * Save the tiers (in this order).
	 *
	 * @param array[] $tiers Tiers.
	 */
	public static function save( $tiers ) {
		$clean = array();
		$used  = array( self::FREE => true );
		foreach ( $tiers as $tier ) {
			$tier = self::normalize( $tier );
			if ( '' === trim( $tier['name'] ) ) {
				continue;
			}
			$id = sanitize_key( '' !== $tier['id'] ? $tier['id'] : sanitize_title( $tier['name'] ) );
			while ( '' === $id || isset( $used[ $id ] ) ) {
				$id .= '_' . wp_rand( 1, 99 );
			}
			$used[ $id ] = true;
			$tier['id']  = $id;
			unset( $tier['rank'] );
			$clean[] = $tier;
		}
		update_option( self::OPTION, $clean );
		self::$cache = null;
		self::sync_roles();
	}

	/**
	 * One tier.
	 *
	 * @param string $id Tier ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Rank of a tier ID (0 = Free, -1 = unknown).
	 *
	 * @param string $id Tier ID.
	 * @return int
	 */
	public static function rank( $id ) {
		if ( self::FREE === $id ) {
			return 0;
		}
		$tier = self::get( $id );
		return $tier ? (int) $tier['rank'] : -1;
	}

	/**
	 * Name of a tier ID.
	 *
	 * @param string $id Tier ID.
	 * @return string
	 */
	public static function name( $id ) {
		if ( self::FREE === $id ) {
			return __( 'Free (any registered user)', 'rinnovo-membership-content-pro' );
		}
		$tier = self::get( $id );
		return $tier ? $tier['name'] : $id;
	}

	/**
	 * Options for "Who can see this" selects.
	 *
	 * @param bool $inherit Include "Inherit" and "Everyone".
	 * @return array<string, string>
	 */
	public static function choices( $inherit = true ) {
		$out = $inherit ? array(
			''       => __( 'Not restricted (or from its category / parent)', 'rinnovo-membership-content-pro' ),
			'public' => __( 'Everyone (ignore category and parent rules)', 'rinnovo-membership-content-pro' ),
		) : array( '' => __( '— Not restricted —', 'rinnovo-membership-content-pro' ) );
		$out[ self::FREE ] = self::name( self::FREE );
		foreach ( self::all() as $id => $tier ) {
			/* translators: %s: tier name. */
			$out[ $id ] = sprintf( __( '%s members and higher', 'rinnovo-membership-content-pro' ), $tier['name'] );
		}
		return $out;
	}

	/**
	 * The tier a product or variation gives.
	 *
	 * @param int $product_id Product or variation.
	 * @return string Tier ID, or ''.
	 */
	public static function for_product( $product_id ) {
		$product_id = (int) $product_id;
		$parent     = (int) wp_get_post_parent_id( $product_id );
		$best       = '';
		foreach ( self::all() as $id => $tier ) {
			if ( in_array( $product_id, $tier['products'], true ) || ( $parent && in_array( $parent, $tier['products'], true ) ) ) {
				// A product in several tiers gives the highest.
				$best = $id;
			}
		}
		return $best;
	}

	/**
	 * Products (and variations of variable products) that give a tier, purchasable ones only.
	 *
	 * @param string $id Tier ID.
	 * @return WC_Product[]
	 */
	public static function products( $id ) {
		$tier = self::get( $id );
		$out  = array();
		foreach ( $tier ? $tier['products'] : array() as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || 'publish' !== get_post_status( $product->is_type( 'variation' ) ? $product->get_parent_id() : $pid ) ) {
				continue;
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child ) {
					$c = wc_get_product( $child );
					if ( $c && $c->is_purchasable() ) {
						$out[ $child ] = $c;
					}
				}
			} elseif ( $product->is_purchasable() ) {
				$out[ $pid ] = $product;
			}
		}
		return $out;
	}

	/**
	 * Where "Subscribe to unlock" leads for a tier: its own link, the join page, its first product, or the shop.
	 *
	 * @param string $id Tier ID.
	 * @return string
	 */
	public static function join_url( $id ) {
		$tier = self::get( $id );
		if ( $tier && '' !== $tier['join_url'] ) {
			return $tier['join_url'];
		}
		$page = (int) RINNOVOMEM_Settings::get( 'join_page' );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			return get_permalink( $page );
		}
		if ( self::FREE === $id ) {
			return wc_get_page_permalink( 'myaccount' );
		}
		foreach ( $tier ? $tier['products'] : array() as $pid ) {
			$product = wc_get_product( $pid );
			if ( $product ) {
				$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
				return $parent ? $parent->get_permalink() : wc_get_page_permalink( 'shop' );
			}
		}
		return wc_get_page_permalink( 'shop' );
	}

	/**
	 * Role slug of a tier.
	 *
	 * @param string $id Tier ID.
	 * @return string
	 */
	public static function role( $id ) {
		return 'rinnovomem_' . $id;
	}

	/**
	 * Create the roles of tiers that give one, and remove roles of deleted tiers.
	 */
	public static function sync_roles() {
		$wanted = array();
		foreach ( self::all() as $id => $tier ) {
			if ( $tier['role'] ) {
				$wanted[ self::role( $id ) ] = $tier['name'];
			}
		}
		foreach ( wp_roles()->roles as $slug => $role ) {
			if ( 0 === strpos( $slug, 'rinnovomem_' ) && ! isset( $wanted[ $slug ] ) ) {
				remove_role( $slug );
			}
		}
		foreach ( $wanted as $slug => $name ) {
			if ( ! get_role( $slug ) ) {
				/* translators: %s: tier name. */
				add_role( $slug, sprintf( __( 'Member: %s', 'rinnovo-membership-content-pro' ), $name ), array( 'read' => true ) );
			}
		}
	}

	/**
	 * A badge with the tier's name and colour.
	 *
	 * @param string $id Tier ID.
	 * @return string HTML.
	 */
	public static function badge( $id ) {
		$tier = self::get( $id );
		if ( ! $tier ) {
			return '';
		}
		return '<span class="rinnovomem-badge" style="background-color:' . esc_attr( sanitize_hex_color( $tier['color'] ) ? $tier['color'] : '#7c3aed' ) . '">' . esc_html( $tier['name'] ) . '</span>';
	}
}
