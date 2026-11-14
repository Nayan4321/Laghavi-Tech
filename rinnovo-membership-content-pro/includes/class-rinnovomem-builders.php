<?php
/**
 * Page builders: show or hide any part of a page by membership, from inside the builder.
 *
 * - Elementor / Elementor Pro: "Members only" in the Advanced tab of every section, container, column and
 *   widget (also in popups, theme templates and loop items, which use the same elements), dynamic tags (member
 *   name, tier, renewal date, credits left) and widgets (My Membership, resource library, courses, credits,
 *   member directory).
 * - Block editor: a "Members only" panel on every block.
 * - Bricks: element conditions.
 * - Every other builder (Divi, Beaver Builder, WPBakery, Oxygen, Breakdance…): the [members_only] and other
 *   shortcodes.
 *
 * Hidden parts are removed on the server (never sent to the browser), optionally replaced by a teaser, and pages
 * using these rules are not served from a page cache.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page builder visibility.
 */
class RINNOVOMEM_Builders {

	const TD = 'rinnovo-membership-content-pro';

	/**
	 * Hooks.
	 */
	public static function init() {
		// Elementor.
		add_action( 'elementor/element/common/_section_style/after_section_end', array( __CLASS__, 'elementor_controls' ), 10, 2 );
		add_action( 'elementor/element/section/section_advanced/after_section_end', array( __CLASS__, 'elementor_controls' ), 10, 2 );
		add_action( 'elementor/element/column/section_advanced/after_section_end', array( __CLASS__, 'elementor_controls' ), 10, 2 );
		add_action( 'elementor/element/container/section_layout/after_section_end', array( __CLASS__, 'elementor_controls' ), 10, 2 );
		foreach ( array( 'section', 'column', 'container' ) as $type ) {
			add_filter( 'elementor/frontend/' . $type . '/should_render', array( __CLASS__, 'elementor_should_render' ), 10, 2 );
		}
		add_filter( 'elementor/frontend/widget/should_render', array( __CLASS__, 'elementor_widget_should_render' ), 10, 2 );
		add_filter( 'elementor/widget/render_content', array( __CLASS__, 'elementor_widget_content' ), 10, 2 );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'elementor_widgets' ) );
		add_action( 'elementor/dynamic_tags/register', array( __CLASS__, 'elementor_tags' ) );
		// Block editor.
		add_filter( 'register_block_type_args', array( __CLASS__, 'block_attribute' ), 10, 2 );
		add_filter( 'render_block', array( __CLASS__, 'render_block' ), 5, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_assets' ) );
		// Bricks.
		add_filter( 'bricks/conditions/groups', array( __CLASS__, 'bricks_groups' ) );
		add_filter( 'bricks/conditions/options', array( __CLASS__, 'bricks_options' ) );
		add_filter( 'bricks/conditions/result', array( __CLASS__, 'bricks_result' ), 10, 3 );
		// Caches.
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 1 );
	}

	/**
	 * Who can be targeted.
	 *
	 * @return array
	 */
	public static function modes() {
		return array(
			''           => __( 'Everyone', self::TD ),
			'logged_in'  => __( 'Logged-in users', self::TD ),
			'logged_out' => __( 'Logged-out visitors', self::TD ),
			'member'     => __( 'Any member', self::TD ),
			'non_member' => __( 'Non-members (show "Subscribe" offers)', self::TD ),
			'tier'       => __( 'Members of a tier and higher', self::TD ),
			'exact'      => __( 'Members of exactly one tier', self::TD ),
			'group'      => __( 'Members of a group (programme)', self::TD ),
			'expired'    => __( 'Former members (expired or cancelled)', self::TD ),
			'course'     => __( 'Owners of a course (ID)', self::TD ),
			'plan'       => __( 'Members with a download plan', self::TD ),
			'product'    => __( 'Customers of a product (ID)', self::TD ),
		);
	}

	/**
	 * Whether a rule lets the current user see something.
	 *
	 * @param string $mode  Mode.
	 * @param string $value Tier, group, course or product.
	 * @param int    $user  User (default current).
	 * @return bool
	 */
	public static function visible( $mode, $value = '', $user = null ) {
		$user = null === $user ? get_current_user_id() : (int) $user;
		switch ( $mode ) {
			case '':
				return true;
			case 'logged_in':
				return $user > 0;
			case 'logged_out':
				return ! $user;
			case 'member':
				return RINNOVOMEM_Members::rank( $user ) > 0;
			case 'non_member':
				return RINNOVOMEM_Members::rank( $user ) < 1;
			case 'tier':
				return $user && RINNOVOMEM_Members::has( $user, (string) $value );
			case 'exact':
				return $user && array_key_exists( (string) $value, RINNOVOMEM_Members::held( $user ) );
			case 'group':
				foreach ( array_keys( RINNOVOMEM_Members::held( $user ) ) as $t ) {
					if ( RINNOVOMEM_Tiers::group( $t ) === sanitize_key( (string) $value ) ) {
						return true;
					}
				}
				return false;
			case 'expired':
				if ( ! $user || RINNOVOMEM_Members::rank( $user ) > 0 ) {
					return false;
				}
				foreach ( RINNOVO_Subscriptions::for_user( $user ) as $sub ) {
					if ( in_array( $sub->status, array( 'cancelled', 'expired' ), true ) && '' !== RINNOVOMEM_Tiers::for_product( $sub->product_id ) ) {
						return true;
					}
				}
				return false;
			case 'course':
				return $user && ( RINNOVOMEM_Ledger::has( $user, 'course', (string) $value ) || isset( RINNOVOMEM_Dashboard::courses( $user )[ 'c' . $value ] ) );
			case 'plan':
				return $user && null !== RINNOVOMEM_Credits::plan_for( $user );
			case 'product':
				return $user && in_array( (int) $value, RINNOVOMEM_Courses::owned_products( $user ), true );
		}
		return true;
	}

	/**
	 * Teaser shown instead of a hidden part (a "Subscribe to unlock" box when no text is set).
	 *
	 * @param string $mode   Mode.
	 * @param string $value  Value.
	 * @param string $teaser Text.
	 * @return string
	 */
	public static function teaser( $mode, $value, $teaser ) {
		if ( '' === trim( (string) $teaser ) ) {
			return '';
		}
		if ( '[paywall]' === trim( $teaser ) ) {
			return RINNOVOMEM_Paywall::box(
				array(
					'ok'     => false,
					'reason' => is_user_logged_in() ? 'tier' : 'login',
					'tier'   => 'tier' === $mode || 'exact' === $mode ? (string) $value : (string) key( RINNOVOMEM_Tiers::all() ),
					'unlock' => 0,
				),
				'block'
			);
		}
		return '<div class="rinnovomem-teaser">' . wp_kses_post( wpautop( $teaser ) ) . '</div>';
	}

	// ---------------------------------------------------------------- Elementor.

	/**
	 * Controls in the Advanced tab.
	 *
	 * @param object $element Element.
	 * @param array  $args    Args.
	 */
	public static function elementor_controls( $element, $args ) {
		$tiers = array();
		foreach ( RINNOVOMEM_Tiers::all() as $id => $t ) {
			$tiers[ $id ] = $t['name'];
		}
		$element->start_controls_section(
			'rinnovomem_section',
			array(
				'label' => __( 'Members only (Rinnovo)', self::TD ),
				'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
			)
		);
		$element->add_control(
			'rinnovomem_vis',
			array(
				'label'   => __( 'Show to', self::TD ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => self::modes(),
				'default' => '',
			)
		);
		$element->add_control(
			'rinnovomem_vis_tier',
			array(
				'label'     => __( 'Tier', self::TD ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => $tiers,
				'condition' => array( 'rinnovomem_vis' => array( 'tier', 'exact' ) ),
			)
		);
		$element->add_control(
			'rinnovomem_vis_value',
			array(
				'label'     => __( 'Group, course ID or product ID', self::TD ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'condition' => array( 'rinnovomem_vis' => array( 'group', 'course', 'product' ) ),
			)
		);
		$element->add_control(
			'rinnovomem_vis_teaser',
			array(
				'label'       => __( 'Show instead (optional)', self::TD ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'description' => __( 'Text shown to everyone else, or [paywall] for the "Subscribe to unlock" box. Empty = hidden. Hidden parts are never sent to the browser.', self::TD ),
				'condition'   => array( 'rinnovomem_vis!' => '' ),
			)
		);
		$element->end_controls_section();
	}

	/**
	 * The rule of an element.
	 *
	 * @param object $element Element.
	 * @return array mode, value, teaser.
	 */
	private static function rule_of( $element ) {
		$s    = $element->get_settings();
		$mode = isset( $s['rinnovomem_vis'] ) ? (string) $s['rinnovomem_vis'] : '';
		return array(
			'mode'   => $mode,
			'value'  => in_array( $mode, array( 'tier', 'exact' ), true ) ? ( isset( $s['rinnovomem_vis_tier'] ) ? (string) $s['rinnovomem_vis_tier'] : '' ) : ( isset( $s['rinnovomem_vis_value'] ) ? (string) $s['rinnovomem_vis_value'] : '' ),
			'teaser' => isset( $s['rinnovomem_vis_teaser'] ) ? (string) $s['rinnovomem_vis_teaser'] : '',
		);
	}

	/**
	 * Whether Elementor is editing (everything shows in the editor).
	 *
	 * @return bool
	 */
	private static function editing() {
		return class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor && ( \Elementor\Plugin::$instance->editor->is_edit_mode() || ( \Elementor\Plugin::$instance->preview && \Elementor\Plugin::$instance->preview->is_preview_mode() ) );
	}

	/**
	 * Sections, columns, containers.
	 *
	 * @param bool   $should  Render.
	 * @param object $element Element.
	 * @return bool
	 */
	public static function elementor_should_render( $should, $element ) {
		if ( ! $should || self::editing() ) {
			return $should;
		}
		$r = self::rule_of( $element );
		if ( '' === $r['mode'] || self::visible( $r['mode'], $r['value'] ) ) {
			return $should;
		}
		if ( '' !== trim( $r['teaser'] ) ) {
			echo self::teaser( $r['mode'], $r['value'], $r['teaser'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in teaser().
		}
		return false;
	}

	/**
	 * Widgets without a teaser are not rendered at all.
	 *
	 * @param bool   $should Render.
	 * @param object $widget Widget.
	 * @return bool
	 */
	public static function elementor_widget_should_render( $should, $widget ) {
		if ( ! $should || self::editing() ) {
			return $should;
		}
		$r = self::rule_of( $widget );
		return '' === $r['mode'] || self::visible( $r['mode'], $r['value'] ) || '' !== trim( $r['teaser'] );
	}

	/**
	 * Widgets with a teaser show it instead of their content.
	 *
	 * @param string $content Content.
	 * @param object $widget  Widget.
	 * @return string
	 */
	public static function elementor_widget_content( $content, $widget ) {
		if ( self::editing() ) {
			return $content;
		}
		$r = self::rule_of( $widget );
		if ( '' === $r['mode'] || self::visible( $r['mode'], $r['value'] ) ) {
			return $content;
		}
		return self::teaser( $r['mode'], $r['value'], $r['teaser'] );
	}

	/**
	 * Widgets.
	 *
	 * @param object $manager Widgets manager.
	 */
	public static function elementor_widgets( $manager ) {
		require_once RINNOVOMEM_DIR . 'includes/class-rinnovomem-elementor.php';
		foreach ( RINNOVOMEM_Elementor_Widget::all() as $key => $def ) {
			$manager->register( new RINNOVOMEM_Elementor_Widget( array(), array( 'rinnovomem_kind' => $key ) ) );
		}
	}

	/**
	 * Dynamic tags.
	 *
	 * @param object $tags Tags manager.
	 */
	public static function elementor_tags( $tags ) {
		require_once RINNOVOMEM_DIR . 'includes/class-rinnovomem-elementor.php';
		$tags->register_group( 'rinnovomem', array( 'title' => __( 'Membership', self::TD ) ) );
		$tags->register( new RINNOVOMEM_Elementor_Tag() );
	}

	// ---------------------------------------------------------------- Block editor.

	/**
	 * Every block gets the "Members only" attribute (so dynamic blocks accept it too).
	 *
	 * @param array  $args Block args.
	 * @param string $name Block name.
	 * @return array
	 */
	public static function block_attribute( $args, $name ) {
		$args['attributes']                  = isset( $args['attributes'] ) ? $args['attributes'] : array();
		$args['attributes']['rinnovomemVis'] = array(
			'type'    => 'object',
			'default' => array(),
		);
		return $args;
	}

	/**
	 * Remove blocks the visitor may not see (or show the teaser).
	 *
	 * @param string $html  HTML.
	 * @param array  $block Block.
	 * @return string
	 */
	public static function render_block( $html, $block ) {
		$v = isset( $block['attrs']['rinnovomemVis'] ) && is_array( $block['attrs']['rinnovomemVis'] ) ? $block['attrs']['rinnovomemVis'] : array();
		if ( empty( $v['mode'] ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' ) ) ) {
			return $html;
		}
		$mode  = sanitize_key( $v['mode'] );
		$value = isset( $v['value'] ) ? (string) $v['value'] : '';
		if ( self::visible( $mode, $value ) ) {
			return $html;
		}
		return self::teaser( $mode, $value, isset( $v['teaser'] ) ? (string) $v['teaser'] : '' );
	}

	/**
	 * Editor script.
	 */
	public static function editor_assets() {
		wp_enqueue_script( 'rinnovomem-visibility', RINNOVOMEM_URL . 'assets/js/visibility.js', array( 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ), RINNOVOMEM_VERSION, true );
		$tiers = array();
		foreach ( RINNOVOMEM_Tiers::all() as $id => $t ) {
			$tiers[] = array(
				'value' => $id,
				'label' => $t['name'],
			);
		}
		$modes = array();
		foreach ( self::modes() as $k => $label ) {
			$modes[] = array(
				'value' => $k,
				'label' => $label,
			);
		}
		wp_localize_script(
			'rinnovomem-visibility',
			'rinnovomemVis',
			array(
				'modes' => $modes,
				'tiers' => $tiers,
				'title' => __( 'Members only', self::TD ),
				'tier'  => __( 'Tier', self::TD ),
				'value' => __( 'Group, course ID or product ID', self::TD ),
				'tease' => __( 'Show instead (optional; [paywall] = Subscribe box)', self::TD ),
				'note'  => __( 'Hidden blocks are removed on the server, never sent to the browser.', self::TD ),
			)
		);
	}

	// ---------------------------------------------------------------- Bricks.

	/**
	 * Condition group.
	 *
	 * @param array $groups Groups.
	 * @return array
	 */
	public static function bricks_groups( $groups ) {
		$groups[] = array(
			'name'  => 'rinnovomem',
			'label' => __( 'Membership', self::TD ),
		);
		return $groups;
	}

	/**
	 * Condition options.
	 *
	 * @param array $options Options.
	 * @return array
	 */
	public static function bricks_options( $options ) {
		$tiers = array();
		foreach ( RINNOVOMEM_Tiers::all() as $id => $t ) {
			$tiers[ $id ] = $t['name'];
		}
		$options[] = array(
			'key'     => 'rinnovomem_mode',
			'label'   => __( 'Member status', self::TD ),
			'group'   => 'rinnovomem',
			'compare' => array(
				'type'    => 'select',
				'options' => array(
					'==' => __( 'is', self::TD ),
					'!=' => __( 'is not', self::TD ),
				),
			),
			'value'   => array(
				'type'    => 'select',
				'options' => array(
					'member'     => __( 'Any member', self::TD ),
					'non_member' => __( 'Non-member', self::TD ),
					'expired'    => __( 'Former member', self::TD ),
					'plan'       => __( 'Has a download plan', self::TD ),
				),
			),
		);
		$options[] = array(
			'key'     => 'rinnovomem_tier',
			'label'   => __( 'Member of tier (or higher)', self::TD ),
			'group'   => 'rinnovomem',
			'compare' => array(
				'type'    => 'select',
				'options' => array(
					'==' => __( 'is', self::TD ),
					'!=' => __( 'is not', self::TD ),
				),
			),
			'value'   => array(
				'type'    => 'select',
				'options' => $tiers,
			),
		);
		return $options;
	}

	/**
	 * Condition result.
	 *
	 * @param bool   $result    Result.
	 * @param string $key       Condition key.
	 * @param array  $condition Condition.
	 * @return bool
	 */
	public static function bricks_result( $result, $key, $condition ) {
		if ( ! in_array( $key, array( 'rinnovomem_mode', 'rinnovomem_tier' ), true ) ) {
			return $result;
		}
		$value = isset( $condition['value'] ) ? (string) $condition['value'] : '';
		$ok    = 'rinnovomem_tier' === $key ? self::visible( 'tier', $value ) : self::visible( $value );
		return isset( $condition['compare'] ) && '!=' === $condition['compare'] ? ! $ok : $ok;
	}

	// ---------------------------------------------------------------- Caching.

	/**
	 * Pages that show different parts to different people must not come from a shared page cache.
	 */
	public static function no_cache() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		$found = false !== strpos( $post->post_content, 'rinnovomemVis' ) || false !== strpos( $post->post_content, '[members_only' ) || false !== strpos( $post->post_content, '[non_members' );
		if ( ! $found ) {
			$data  = (string) get_post_meta( $post->ID, '_elementor_data', true );
			$found = false !== strpos( $data, '"rinnovomem_vis":"' ) && false === strpos( $data, '"rinnovomem_vis":""' ) || preg_match( '/"rinnovomem_vis":"[a-z_]+"/', $data );
		}
		if ( $found || RINNOVOMEM_Rules::requirement( $post->ID ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard cache plugin constant.
			}
			nocache_headers();
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
	}
}
