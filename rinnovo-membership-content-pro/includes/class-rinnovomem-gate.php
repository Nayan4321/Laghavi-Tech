<?php
/**
 * Applying the rules: members-only posts show the teaser and paywall instead of their content (also in
 * feeds, excerpts and the REST API); bbPress forums and BuddyPress / BuddyBoss areas send non-members to
 * the paywall; parts of a page are gated with the "Members only" block, the members-only CSS class on any
 * block, or shortcodes.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gate.
 */
class RINNOVOMEM_Gate {

	const TD = 'rinnovo-membership-content-pro';

	/**
	 * Locked posts of this request: ID => [result, content].
	 *
	 * @var array
	 */
	private static $locked = array();

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'hold' ), 1 );
		add_filter( 'the_content', array( __CLASS__, 'replace' ), 9999 );
		add_action( 'template_redirect', array( __CLASS__, 'areas' ), 9 );
		add_filter( 'the_content', array( __CLASS__, 'locked_notice' ), 5 );

		add_shortcode( 'members_only', array( __CLASS__, 'shortcode_members' ) );
		add_shortcode( 'non_members', array( __CLASS__, 'shortcode_non_members' ) );
		add_shortcode( 'member_tier', array( __CLASS__, 'shortcode_tier' ) );

		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_filter( 'render_block', array( __CLASS__, 'class_rule' ), 10, 2 );
	}

	/**
	 * Swap the content of a locked post for a marker before anything else runs on it (shortcodes and
	 * embeds of members-only content are never processed for non-members).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function hold( $content ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $content;
		}
		$id = (int) get_the_ID();
		if ( ! $id ) {
			return $content;
		}
		$result = RINNOVOMEM_Rules::can_view( $id );
		if ( $result['ok'] ) {
			return $content;
		}
		self::$locked[ $id ] = array( $result, $content );
		return '<!--rinnovomem-locked-' . $id . '-->';
	}

	/**
	 * Put the teaser and paywall where the marker is.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function replace( $content ) {
		if ( false === strpos( $content, '<!--rinnovomem-locked-' ) ) {
			return $content;
		}
		return preg_replace_callback(
			'/(<p>)?<!--rinnovomem-locked-(\d+)-->(<\/p>)?/',
			static function ( $m ) {
				$id = (int) $m[2];
				if ( ! isset( self::$locked[ $id ] ) ) {
					return '';
				}
				list( $result, $raw ) = self::$locked[ $id ];
				return '<div class="rinnovomem-locked">' . ( 'drip' === $result['reason'] ? '' : RINNOVOMEM_Paywall::teaser( $id, $raw ) ) . RINNOVOMEM_Paywall::box( $result ) . '</div>';
			},
			$content
		);
	}

	/**
	 * Forums and community areas: non-members go to the paywall.
	 */
	public static function areas() {
		$rule = null;
		if ( is_singular( array( 'forum', 'topic', 'reply' ) ) ) {
			$result = RINNOVOMEM_Rules::can_view( get_queried_object_id() );
			if ( ! $result['ok'] ) {
				self::send_to_paywall( $result['tier'] );
			}
			return;
		}
		if ( function_exists( 'bp_current_component' ) && bp_current_component() ) {
			$components = (array) RINNOVOMEM_Settings::get( 'bp_components' );
			$component  = bp_current_component();
			if ( ! empty( $components[ $component ] ) ) {
				$rule = array(
					'tier' => $components[ $component ],
					'drip' => 0,
					'date' => 0,
				);
				$result = RINNOVOMEM_Rules::check( $rule );
				// Members may always see their own profile.
				if ( ! $result['ok'] && ! ( 'members' === $component && function_exists( 'bp_is_my_profile' ) && bp_is_my_profile() ) ) {
					self::send_to_paywall( $result['tier'] );
				}
			}
		}
	}

	/**
	 * Redirect to where the tier is sold, with the paywall on top.
	 *
	 * @param string $tier Tier ID.
	 */
	private static function send_to_paywall( $tier ) {
		wp_safe_redirect( add_query_arg( 'rinnovomem_locked', rawurlencode( $tier ), RINNOVOMEM_Tiers::join_url( $tier ) ) );
		exit;
	}

	/**
	 * The paywall box on the page non-members were sent to.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function locked_notice( $content ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$tier = isset( $_GET['rinnovomem_locked'] ) ? sanitize_key( wp_unslash( $_GET['rinnovomem_locked'] ) ) : '';
		if ( '' === $tier || ! is_singular() || ! in_the_loop() || ! is_main_query() || RINNOVOMEM_Tiers::rank( $tier ) < 0 ) {
			return $content;
		}
		remove_filter( 'the_content', array( __CLASS__, 'locked_notice' ), 5 );
		return RINNOVOMEM_Paywall::box(
			array(
				'ok'     => false,
				'reason' => is_user_logged_in() ? 'tier' : 'login',
				'tier'   => $tier,
				'unlock' => 0,
			),
			'area'
		) . $content;
	}

	/**
	 * Rule of a shortcode or block: its own tier, else the lowest paid tier.
	 *
	 * @param array $atts tier, drip, date.
	 * @return array
	 */
	private static function inline_rule( $atts ) {
		$tier = isset( $atts['tier'] ) ? sanitize_key( (string) $atts['tier'] ) : '';
		if ( '' === $tier || RINNOVOMEM_Tiers::rank( $tier ) < 0 ) {
			$all  = RINNOVOMEM_Tiers::all();
			$tier = $all ? (string) key( $all ) : RINNOVOMEM_Tiers::FREE;
		}
		$date = isset( $atts['date'] ) ? (string) $atts['date'] : '';
		return array(
			'tier' => $tier,
			'drip' => isset( $atts['drip'] ) ? max( 0, (int) $atts['drip'] ) : 0,
			'date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? (int) strtotime( $date . ' 00:00:00 ' . wp_timezone_string() ) : 0,
		);
	}

	/**
	 * [members_only tier="gold" drip="7" date="2026-12-01"]…[/members_only]
	 *
	 * @param array  $atts    Attributes.
	 * @param string $content Content.
	 * @return string
	 */
	public static function shortcode_members( $atts, $content = '' ) {
		$atts   = shortcode_atts(
			array(
				'tier'    => '',
				'drip'    => 0,
				'date'    => '',
				'paywall' => 'yes',
			),
			$atts,
			'members_only'
		);
		$result = RINNOVOMEM_Rules::check( self::inline_rule( $atts ) );
		if ( $result['ok'] ) {
			return do_shortcode( $content );
		}
		return 'no' === $atts['paywall'] ? '' : RINNOVOMEM_Paywall::box( $result, 'block' );
	}

	/**
	 * [non_members]Only shown to visitors without a membership.[/non_members]
	 *
	 * @param array  $atts    Attributes.
	 * @param string $content Content.
	 * @return string
	 */
	public static function shortcode_non_members( $atts, $content = '' ) {
		return RINNOVOMEM_Members::rank( get_current_user_id() ) > 0 ? '' : do_shortcode( $content );
	}

	/**
	 * [member_tier] The visitor's tier badge.
	 *
	 * @return string
	 */
	public static function shortcode_tier() {
		$top = RINNOVOMEM_Members::top( get_current_user_id() );
		return '' !== $top ? RINNOVOMEM_Tiers::badge( $top ) : '';
	}

	/**
	 * The "Members only" block.
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_style( 'rinnovomem-block-editor', RINNOVOMEM_URL . 'assets/css/block-editor.css', array(), RINNOVOMEM_VERSION );
		wp_register_script( 'rinnovomem-block', RINNOVOMEM_URL . 'assets/js/block.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ), RINNOVOMEM_VERSION, true );
		$tiers = array();
		foreach ( RINNOVOMEM_Tiers::choices( false ) as $value => $label ) {
			$tiers[] = array(
				'value' => $value,
				'label' => '' === $value ? __( 'Lowest paid tier', self::TD ) : $label,
			);
		}
		wp_localize_script(
			'rinnovomem-block',
			'rinnovomemBlock',
			array(
				'tiers' => $tiers,
				'title' => __( 'Members only', self::TD ),
				'drip'  => __( 'Unlock after (days of membership)', self::TD ),
				'date'  => __( 'Or unlock on (YYYY-MM-DD)', self::TD ),
				'tier'  => __( 'Who can see this', self::TD ),
				'note'  => __( 'Members only', self::TD ),
			)
		);
		register_block_type(
			'rinnovomem/members-only',
			array(
				'editor_script'   => 'rinnovomem-block',
				'editor_style'    => 'rinnovomem-block-editor',
				'attributes'      => array(
					'tier' => array(
						'type'    => 'string',
						'default' => '',
					),
					'drip' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'date' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
	}

	/**
	 * Render the block.
	 *
	 * @param array  $attrs   Attributes.
	 * @param string $content Inner blocks.
	 * @return string
	 */
	public static function render_block( $attrs, $content ) {
		$result = RINNOVOMEM_Rules::check( self::inline_rule( $attrs ) );
		return $result['ok'] ? $content : RINNOVOMEM_Paywall::box( $result, 'block' );
	}

	/**
	 * Any block with the CSS class "members-only" (or "members-only-gold") is for members.
	 *
	 * @param string $html  Block HTML.
	 * @param array  $block Block.
	 * @return string
	 */
	public static function class_rule( $html, $block ) {
		$class = isset( $block['attrs']['className'] ) ? (string) $block['attrs']['className'] : '';
		if ( false === strpos( $class, 'members-only' ) || is_admin() ) {
			return $html;
		}
		$tier = '';
		if ( preg_match( '/(?:^|\s)members-only-([a-z0-9_-]+)/', $class, $m ) ) {
			$tier = $m[1];
		} elseif ( ! preg_match( '/(?:^|\s)members-only(?:\s|$)/', $class ) ) {
			return $html;
		}
		$result = RINNOVOMEM_Rules::check( self::inline_rule( array( 'tier' => $tier ) ) );
		return $result['ok'] ? $html : RINNOVOMEM_Paywall::box( $result, 'block' );
	}
}
