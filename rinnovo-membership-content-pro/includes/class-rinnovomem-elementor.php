<?php
/**
 * Elementor widgets (each shows one of the plugin's shortcodes) and the "Membership" dynamic tag.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * One widget class for every membership element (the kind is given when it is registered).
 */
class RINNOVOMEM_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Kind of widget.
	 *
	 * @var string
	 */
	private $kind = 'dashboard';

	/**
	 * Widgets: kind => title, shortcode, icon.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'dashboard' => array( __( 'My Membership', 'rinnovo-membership-content-pro' ), '[rinnovo_my_membership]', 'eicon-person' ),
			'resources' => array( __( 'Resource library', 'rinnovo-membership-content-pro' ), '[rinnovo_resources]', 'eicon-gallery-grid' ),
			'courses'   => array( __( 'My courses', 'rinnovo-membership-content-pro' ), '[rinnovo_courses]', 'eicon-library-open' ),
			'credits'   => array( __( 'Credit balance', 'rinnovo-membership-content-pro' ), '[rinnovo_credits]', 'eicon-counter' ),
			'directory' => array( __( 'Member directory', 'rinnovo-membership-content-pro' ), '[member_directory]', 'eicon-person' ),
			'files'     => array( __( 'Member files', 'rinnovo-membership-content-pro' ), '[member_library]', 'eicon-document-file' ),
		);
	}

	/**
	 * Constructor.
	 *
	 * @param array      $data Data.
	 * @param array|null $args Args (rinnovomem_kind).
	 */
	public function __construct( $data = array(), $args = null ) {
		if ( is_array( $args ) && ! empty( $args['rinnovomem_kind'] ) && isset( self::all()[ $args['rinnovomem_kind'] ] ) ) {
			$this->kind = $args['rinnovomem_kind'];
		}
		parent::__construct( $data, $args );
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'rinnovomem_' . $this->kind;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return self::all()[ $this->kind ][0];
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return self::all()[ $this->kind ][2];
	}

	/**
	 * Categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'member', 'membership', 'rinnovo', $this->kind );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => $this->get_title() ) );
		$this->add_control(
			'atts',
			array(
				'label'       => __( 'Options', 'rinnovo-membership-content-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'resources' === $this->kind ? 'category="photos" per_page="24" columns="4"' : '',
				'description' => __( 'Optional shortcode options.', 'rinnovo-membership-content-pro' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Output.
	 */
	protected function render() {
		$s    = $this->get_settings_for_display();
		$code = self::all()[ $this->kind ][1];
		$atts = isset( $s['atts'] ) ? preg_replace( '/[\[\]]/', '', (string) $s['atts'] ) : '';
		echo do_shortcode( '' !== trim( $atts ) ? substr( $code, 0, -1 ) . ' ' . $atts . ']' : $code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output.
	}
}

/**
 * Dynamic tag: member name, tier, renewal date, credits left.
 */
class RINNOVOMEM_Elementor_Tag extends \Elementor\Core\DynamicTags\Tag {

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'rinnovomem-member';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Member info', 'rinnovo-membership-content-pro' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function get_group() {
		return 'rinnovomem';
	}

	/**
	 * Categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->add_control(
			'field',
			array(
				'label'   => __( 'Show', 'rinnovo-membership-content-pro' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'name',
				'options' => array(
					'name'    => __( 'Member name', 'rinnovo-membership-content-pro' ),
					'tier'    => __( 'Tier', 'rinnovo-membership-content-pro' ),
					'renewal' => __( 'Renewal date', 'rinnovo-membership-content-pro' ),
					'credits' => __( 'Credits left', 'rinnovo-membership-content-pro' ),
				),
			)
		);
	}

	/**
	 * Output.
	 */
	public function render() {
		$user = get_current_user_id();
		if ( ! $user ) {
			return;
		}
		switch ( $this->get_settings( 'field' ) ) {
			case 'tier':
				$top = RINNOVOMEM_Members::top( $user );
				echo esc_html( '' !== $top ? RINNOVOMEM_Tiers::name( $top ) : '' );
				break;
			case 'renewal':
				$next = '';
				foreach ( RINNOVOMEM_Members::held( $user ) as $h ) {
					$sub = $h['sub'] ? RINNOVO_Subscriptions::get( (int) $h['sub'] ) : null;
					if ( $sub && $sub->next_payment && ( '' === $next || $sub->next_payment < $next ) ) {
						$next = $sub->next_payment;
					}
				}
				echo esc_html( '' !== $next ? RINNOVO_Subscriptions::date_label( $next ) : '' );
				break;
			case 'credits':
				$b = RINNOVOMEM_Credits::balance( $user );
				echo esc_html( $b['unlimited'] ? __( 'Unlimited', 'rinnovo-membership-content-pro' ) : (string) ( array_sum( array_map( 'max', array_fill( 0, count( $b['plan_credits'] ), 0 ), $b['plan_credits'] ) ) + max( 0, $b['bought'] ) ) );
				break;
			default:
				$u = wp_get_current_user();
				echo esc_html( $u->first_name ? $u->first_name : $u->display_name );
		}
	}
}
