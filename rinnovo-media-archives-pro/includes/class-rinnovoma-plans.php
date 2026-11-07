<?php
/**
 * Settings and plans. A plan (for example Basic, Pro) lists the WooCommerce products or variations that
 * give it, the feature flags and quotas your web app reads (ai_credits=500, exports=yes), and how many
 * devices and streams it allows. Plans are ordered lowest first; content for "Basic" is open to "Pro" too.
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plans and settings.
 */
class RINNOVOMA_Plans {

	const SETTINGS = 'rinnovoma_settings';
	const PLANS    = 'rinnovoma_plans';

	/**
	 * Cache.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Cache.
	 *
	 * @var array|null
	 */
	private static $plans = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'grace_days'      => 3,
			'bunny_library'   => '',
			'bunny_key'       => '',
			'bunny_cdn_host'  => '',
			'bunny_cdn_key'   => '',
			'cf_code'         => '',
			'cf_key_id'       => '',
			'cf_pem'          => '',
			'cfr_domain'      => '',
			'cfr_key_pair'    => '',
			'cfr_private_key' => '',
			's3_bucket'       => '',
			's3_region'       => '',
			's3_key'          => '',
			's3_secret'       => '',
			'link_minutes'    => 240,
			'watermark'       => 1,
			'watermark_text'  => '{email} · {ip}',
			'protect'         => 1,
			'referer_lock'    => 1,
			'art_width'       => 1600,
			'max_logins'      => 0,
			'anomaly'         => 'flag',
			'anomaly_minutes' => 60,
			'cutoff_logout'   => 0,
			'api_keys'        => array(),
			'webhooks'        => array(),
			'webhook_secret'  => '',
			'jwt_secret'      => '',
			'oauth_clients'   => array(),
			'app_url'         => '',
			'app_login_url'   => '',
			'frame_origins'   => '',
			'trial_no_card'   => 1,
			'switch_credit'   => 1,
			'pause_months'    => 3,
			'feed_ip_limit'   => 8,
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		if ( null === self::$settings ) {
			$saved          = get_option( self::SETTINGS, array() );
			self::$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
			// Secrets are created once.
			foreach ( array( 'jwt_secret', 'webhook_secret' ) as $key ) {
				if ( '' === self::$settings[ $key ] ) {
					self::$settings[ $key ] = wp_generate_password( 48, false );
					update_option( self::SETTINGS, self::$settings );
				}
			}
		}
		return self::$settings;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting.
	 * @return mixed
	 */
	public static function get( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/**
	 * Save settings.
	 *
	 * @param array $values Values.
	 */
	public static function save_settings( $values ) {
		update_option( self::SETTINGS, array_merge( self::settings(), $values ) );
		self::$settings = null;
	}

	/**
	 * "ai_credits=500\nexports=yes" -> array.
	 *
	 * @param string $text Lines.
	 * @return array<string, string|int|bool>
	 */
	public static function parse_features( $text ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $text ) as $line ) {
			if ( ! preg_match( '/^\s*([a-z0-9_.-]+)\s*(?:[=:]\s*(.*?))?\s*$/i', $line, $m ) ) {
				continue;
			}
			$value = isset( $m[2] ) ? $m[2] : 'yes';
			if ( preg_match( '/^-?\d+$/', $value ) ) {
				$value = (int) $value;
			} elseif ( in_array( strtolower( $value ), array( 'yes', 'true', 'on' ), true ) ) {
				$value = true;
			} elseif ( in_array( strtolower( $value ), array( 'no', 'false', 'off' ), true ) ) {
				$value = false;
			}
			$out[ strtolower( $m[1] ) ] = $value;
		}
		return $out;
	}

	/**
	 * Plans, lowest first, keyed by ID (with rank).
	 *
	 * @return array<string, array>
	 */
	public static function all() {
		if ( null === self::$plans ) {
			self::$plans = array();
			$rank        = 0;
			foreach ( (array) get_option( self::PLANS, array() ) as $plan ) {
				$plan = wp_parse_args(
					(array) $plan,
					array(
						'id'       => '',
						'name'     => '',
						'products' => array(),
						'features' => '',
						'devices'  => 0,
						'streams'  => 0,
					)
				);
				if ( '' === $plan['id'] ) {
					continue;
				}
				$plan['products']              = array_map( 'intval', (array) $plan['products'] );
				$plan['rank']                  = ++$rank;
				$plan['flags']                 = self::parse_features( $plan['features'] );
				self::$plans[ $plan['id'] ]    = $plan;
			}
		}
		return self::$plans;
	}

	/**
	 * Save plans.
	 *
	 * @param array[] $plans Plans.
	 */
	public static function save( $plans ) {
		$clean = array();
		$used  = array();
		foreach ( $plans as $plan ) {
			$name = isset( $plan['name'] ) ? sanitize_text_field( $plan['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$id = sanitize_key( ! empty( $plan['id'] ) ? $plan['id'] : sanitize_title( $name ) );
			while ( '' === $id || isset( $used[ $id ] ) ) {
				$id .= '_' . wp_rand( 1, 99 );
			}
			$used[ $id ] = true;
			$clean[]     = array(
				'id'       => $id,
				'name'     => $name,
				'products' => array_values( array_filter( array_map( 'absint', isset( $plan['products'] ) ? (array) $plan['products'] : array() ) ) ),
				'features' => isset( $plan['features'] ) ? sanitize_textarea_field( $plan['features'] ) : '',
				'devices'  => isset( $plan['devices'] ) ? absint( $plan['devices'] ) : 0,
				'streams'  => isset( $plan['streams'] ) ? absint( $plan['streams'] ) : 0,
			);
		}
		update_option( self::PLANS, $clean );
		self::$plans = null;
	}

	/**
	 * One plan.
	 *
	 * @param string $id Plan.
	 * @return array|null
	 */
	public static function get_plan( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * The plan a product or variation gives.
	 *
	 * @param int $product_id Product.
	 * @return string Plan ID or ''.
	 */
	public static function for_product( $product_id ) {
		$parent = (int) wp_get_post_parent_id( (int) $product_id );
		$best   = '';
		foreach ( self::all() as $id => $plan ) {
			if ( in_array( (int) $product_id, $plan['products'], true ) || ( $parent && in_array( $parent, $plan['products'], true ) ) ) {
				$best = $id;
			}
		}
		return $best;
	}

	/**
	 * Rank of a plan ID (-1 unknown; "any" = 1).
	 *
	 * @param string $id Plan.
	 * @return int
	 */
	public static function rank( $id ) {
		if ( '' === $id || 'any' === $id ) {
			return 1;
		}
		$plan = self::get_plan( $id );
		return $plan ? (int) $plan['rank'] : -1;
	}

	/**
	 * Plan choices for selects.
	 *
	 * @return array<string, string>
	 */
	public static function choices() {
		$out = array( 'any' => __( 'Any plan', 'rinnovo-media-archives-pro' ) );
		foreach ( self::all() as $id => $plan ) {
			/* translators: %s: plan. */
			$out[ $id ] = sprintf( __( '%s and higher', 'rinnovo-media-archives-pro' ), $plan['name'] );
		}
		return $out;
	}
}
