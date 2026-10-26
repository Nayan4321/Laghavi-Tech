<?php
/**
 * Elementor widget: Language switcher.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Box_Shadow;

/**
 * Language switcher widget.
 */
class Vacanam_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'vacanam-language-switcher';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Language switcher', 'vacanam-language-switcher' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-globe';
	}

	/**
	 * Categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'general', 'theme-elements' );
	}

	/**
	 * Search words.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'language', 'switcher', 'translation', 'multilingual', 'vacanam', 'flag', 'arabic', 'لغة' );
	}

	/**
	 * Styles.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( 'vacanam-switcher' );
	}

	/**
	 * Scripts.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( 'vacanam-switcher' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => __( 'Language switcher', 'vacanam-language-switcher' ) ) );
		$this->add_control(
			'style',
			array(
				'label'   => __( 'Style', 'vacanam-language-switcher' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'dropdown',
				'options' => Vacanam_Switcher::styles(),
			)
		);
		$this->add_control(
			'names',
			array(
				'label'   => __( 'Show', 'vacanam-language-switcher' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'name',
				'options' => array(
					'name' => __( 'Language names', 'vacanam-language-switcher' ),
					'code' => __( 'Codes (EN, AR)', 'vacanam-language-switcher' ),
					'none' => __( 'No text (flags only)', 'vacanam-language-switcher' ),
				),
			)
		);
		$this->add_control(
			'flags',
			array(
				'label'        => __( 'Flags', 'vacanam-language-switcher' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		$this->add_control(
			'flag_shape',
			array(
				'label'     => __( 'Flag shape', 'vacanam-language-switcher' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'rect',
				'options'   => array(
					'rect'   => __( 'Rectangle', 'vacanam-language-switcher' ),
					'round'  => __( 'Round', 'vacanam-language-switcher' ),
					'square' => __( 'Square', 'vacanam-language-switcher' ),
				),
				'condition' => array( 'flags' => 'yes' ),
			)
		);
		$this->add_control(
			'hide_current',
			array(
				'label'        => __( 'Hide the current language', 'vacanam-language-switcher' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
			)
		);
		$this->add_control(
			'open_on',
			array(
				'label'     => __( 'Dropdown opens on', 'vacanam-language-switcher' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'click',
				'options'   => array(
					'click' => __( 'Click', 'vacanam-language-switcher' ),
					'hover' => __( 'Hover', 'vacanam-language-switcher' ),
				),
				'condition' => array( 'style' => 'dropdown' ),
			)
		);
		$this->add_control(
			'label',
			array(
				'label'       => __( 'Accessible label', 'vacanam-language-switcher' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => __( 'Language', 'vacanam-language-switcher' ),
			)
		);
		$this->add_responsive_control(
			'align',
			array(
				'label'     => __( 'Alignment', 'vacanam-language-switcher' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Start', 'vacanam-language-switcher' ),
						'icon'  => 'eicon-h-align-left',
					),
					'center'     => array(
						'title' => __( 'Center', 'vacanam-language-switcher' ),
						'icon'  => 'eicon-h-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'End', 'vacanam-language-switcher' ),
						'icon'  => 'eicon-h-align-right',
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .elementor-widget-container' => 'display:flex;justify-content:{{VALUE}};',
					'{{WRAPPER}} .vacanam-list'               => 'justify-content:{{VALUE}};',
				),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'style_section',
			array(
				'label' => __( 'Colours and size', 'vacanam-language-switcher' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .vacanam-link, {{WRAPPER}} .vacanam-toggle, {{WRAPPER}} .vacanam-select',
			)
		);
		$vars = array(
			'text'        => array( __( 'Text', 'vacanam-language-switcher' ), '--vacanam-text' ),
			'background'  => array( __( 'Background', 'vacanam-language-switcher' ), '--vacanam-bg' ),
			'hover_text'  => array( __( 'Text on hover', 'vacanam-language-switcher' ), '--vacanam-hover-text' ),
			'hover_bg'    => array( __( 'Background on hover', 'vacanam-language-switcher' ), '--vacanam-hover-bg' ),
			'active_text' => array( __( 'Current language text', 'vacanam-language-switcher' ), '--vacanam-active-text' ),
			'active_bg'   => array( __( 'Current language background', 'vacanam-language-switcher' ), '--vacanam-active-bg' ),
			'border'      => array( __( 'Border', 'vacanam-language-switcher' ), '--vacanam-border' ),
		);
		foreach ( $vars as $key => $var ) {
			$this->add_control(
				$key . '_color',
				array(
					'label'     => $var[0],
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .vacanam-switcher' => $var[1] . ': {{VALUE}};' ),
				)
			);
		}
		$this->add_responsive_control(
			'flag_size',
			array(
				'label'      => __( 'Flag size', 'vacanam-language-switcher' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 10,
						'max' => 60,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .vacanam-switcher' => '--vacanam-flag: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'radius',
			array(
				'label'      => __( 'Corner radius', 'vacanam-language-switcher' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .vacanam-switcher' => '--vacanam-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'padding',
			array(
				'label'      => __( 'Padding', 'vacanam-language-switcher' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .vacanam-link, {{WRAPPER}} .vacanam-toggle' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'gap',
			array(
				'label'      => __( 'Space between languages', 'vacanam-language-switcher' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .vacanam-switcher > .vacanam-list' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'dropdown_shadow',
				'label'    => __( 'Dropdown shadow', 'vacanam-language-switcher' ),
				'selector' => '{{WRAPPER}} .vacanam-dropdown > .vacanam-list',
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Output.
	 */
	protected function render() {
		$s = $this->get_settings_for_display();
		$html = Vacanam_Switcher::render(
			array(
				'style'        => $s['style'],
				'names'        => $s['names'],
				'flags'        => 'yes' === $s['flags'] ? 1 : 0,
				'flag_shape'   => isset( $s['flag_shape'] ) ? $s['flag_shape'] : 'rect',
				'hide_current' => 'yes' === $s['hide_current'] ? 1 : 0,
				'open_on'      => isset( $s['open_on'] ) ? $s['open_on'] : 'click',
				'label'        => isset( $s['label'] ) ? $s['label'] : '',
				'flag_size'    => ! empty( $s['flag_size']['size'] ) ? $s['flag_size']['size'] : 20,
			),
			false
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render().
	}
}
