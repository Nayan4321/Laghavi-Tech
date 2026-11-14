<?php
/**
 * Elementor widget: Box builder.
 *
 * @package RinnovoBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box builder widget.
 */
class RINNOVOBB_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'rinnovo_box_builder';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Box builder', 'rinnovo-box-builder' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-products';
	}

	/**
	 * Categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'woocommerce-elements', 'general' );
	}

	/**
	 * Keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'box', 'builder', 'subscription', 'rinnovo', 'meal', 'bundle' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$products = array( '0' => __( 'This product (on product pages)', 'rinnovo-box-builder' ) );
		foreach ( wc_get_products(
			array(
				'status'     => 'publish',
				'limit'      => 200,
				'meta_key'   => RINNOVOBB_Config::META, // phpcs:ignore WordPress.DB.SlowDBQuery -- Editor only.
				'meta_compare' => 'EXISTS',
			)
		) as $p ) {
			if ( RINNOVOBB_Config::active( $p ) ) {
				$products[ (string) $p->get_id() ] = $p->get_name();
			}
		}
		$this->start_controls_section( 'content', array( 'label' => __( 'Box builder', 'rinnovo-box-builder' ) ) );
		$this->add_control(
			'product',
			array(
				'label'   => __( 'Product', 'rinnovo-box-builder' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $products,
				'default' => '0',
			)
		);
		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'rinnovo-box-builder' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array_merge( array( '' => __( 'As set on the product', 'rinnovo-box-builder' ) ), RINNOVOBB_Config::layouts() ),
				'default' => '',
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Output.
	 */
	protected function render() {
		$s = $this->get_settings_for_display();
		echo RINNOVOBB_Render::shortcode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
			array(
				'product' => isset( $s['product'] ) ? (int) $s['product'] : 0,
				'layout'  => isset( $s['layout'] ) ? (string) $s['layout'] : '',
			)
		);
	}
}
