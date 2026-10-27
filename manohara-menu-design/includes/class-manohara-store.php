<?php
/**
 * Saved menu designs and global settings.
 *
 * A design: name, where it applies (a theme menu location, or only where it is placed with the
 * Elementor widget, block or shortcode), its menu, a style (colours, font, animation) and one setup
 * per device: desktop, tablet and mobile. Each device keeps the theme's own menu or uses a template.
 *
 * @package ManoharaMenuDesign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Store.
 */
class Manohara_Store {

	const DESIGNS  = 'manohara_designs';
	const SETTINGS = 'manohara_settings';

	/**
	 * Devices, largest first.
	 *
	 * @return string[] key => label.
	 */
	public static function devices() {
		return array(
			'desktop' => __( 'Desktop', 'manohara-menu-design' ),
			'tablet'  => __( 'Tablet', 'manohara-menu-design' ),
			'mobile'  => __( 'Mobile', 'manohara-menu-design' ),
		);
	}

	/*
	 * Global settings.
	 */

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'tablet_max'       => 1024,
			'mobile_max'       => 767,
			'header_selectors' => '#masthead, .site-header, #site-header, header.wp-block-template-part, .elementor-location-header, #header, .main-header-bar-wrap, #main-header, .ast-mobile-header-wrap',
			'delete_data'      => 0,
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::SETTINGS, array() );
		return array_merge( self::default_settings(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Save settings.
	 *
	 * @param array $values Values.
	 */
	public static function save_settings( $values ) {
		update_option( self::SETTINGS, array_merge( self::settings(), $values ) );
	}

	/*
	 * Designs.
	 */

	/**
	 * Style defaults (the template's own colours are applied over these).
	 *
	 * @return array
	 */
	public static function default_style() {
		return array(
			'bar_bg'         => '#ffffff',
			'bar_text'       => '#0b3540',
			'panel_bg'       => '#ffffff',
			'text'           => '#1f2937',
			'text_hover'     => '#2563eb',
			'accent'         => '#2563eb',
			'accent_text'    => '#ffffff',
			'bubble'         => '#e5edff',
			'divider'        => '#e5e7eb',
			'secondary_bg'   => '#f3f4f6',
			'secondary_text' => '#1f2937',
			'backdrop'       => 'rgba(15,23,42,.45)',
			'font_family'    => 'inherit',
			'font_custom'    => '',
			'font_size'      => 16,
			'sub_font_size'  => 15,
			'font_weight'    => 500,
			'text_transform' => 'none',
			'letter_spacing' => 0,
			'item_gap'       => 8,
			'radius'         => 999,
			'duration'       => 350,
			'easing'         => 'smooth',
		);
	}

	/**
	 * A device set up with the theme's own menu.
	 *
	 * @return array
	 */
	public static function default_device() {
		return array(
			'mode'           => 'theme',
			'template'       => '',
			'menu'           => 0,
			'menu2'          => 0,
			'placement'      => 'bar',
			'opts'           => array(),
			'style_override' => 0,
			'style'          => array(),
		);
	}

	/**
	 * A new design.
	 *
	 * @return array
	 */
	public static function new_design() {
		$design = array(
			'id'       => 0,
			'name'     => __( 'Main menu', 'manohara-menu-design' ),
			'location' => '',
			'menu'     => 0,
			'style'    => array(),
			'devices'  => array(),
		);
		foreach ( array_keys( self::devices() ) as $device ) {
			$design['devices'][ $device ] = self::default_device();
		}
		// A useful start: the theme's desktop menu, a slide-down panel on phones and tablets.
		foreach ( array( 'tablet', 'mobile' ) as $device ) {
			$design['devices'][ $device ]['mode']     = 'design';
			$design['devices'][ $device ]['template'] = 'slide-down';
		}
		// The first theme location no other design uses yet.
		$used = wp_list_pluck( self::designs(), 'location' );
		foreach ( array_keys( get_registered_nav_menus() ) as $location ) {
			if ( ! in_array( $location, $used, true ) ) {
				$design['location'] = (string) $location;
				break;
			}
		}
		if ( '' === $design['location'] && $used ) {
			$design['name'] = __( 'Menu design', 'manohara-menu-design' );
		}
		$design['style'] = Manohara_Templates::style_for( 'slide-down' );
		return $design;
	}

	/**
	 * All designs.
	 *
	 * @return array id => design.
	 */
	public static function designs() {
		$saved = get_option( self::DESIGNS, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $id => $design ) {
			if ( is_array( $design ) ) {
				$out[ (int) $id ] = self::normalize( $design, (int) $id );
			}
		}
		return $out;
	}

	/**
	 * One design.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function design( $id ) {
		$all = self::designs();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * The design applied to a theme menu location.
	 *
	 * @param string $location Location.
	 * @return array|null
	 */
	public static function design_for_location( $location ) {
		if ( '' === (string) $location ) {
			return null;
		}
		foreach ( self::designs() as $design ) {
			if ( $design['location'] === $location ) {
				return $design;
			}
		}
		return null;
	}

	/**
	 * Fill in missing keys.
	 *
	 * @param array $design Design.
	 * @param int   $id     ID.
	 * @return array
	 */
	public static function normalize( $design, $id ) {
		$design          = array_merge(
			array(
				'name'     => '',
				'location' => '',
				'menu'     => 0,
				'style'    => array(),
				'devices'  => array(),
			),
			$design
		);
		$design['id']    = (int) $id;
		$design['style'] = array_merge( self::default_style(), (array) $design['style'] );
		foreach ( array_keys( self::devices() ) as $device ) {
			$set = isset( $design['devices'][ $device ] ) && is_array( $design['devices'][ $device ] ) ? $design['devices'][ $device ] : array();
			$set = array_merge( self::default_device(), $set );
			if ( 'design' === $set['mode'] && ! Manohara_Templates::get( $set['template'] ) ) {
				$set['mode'] = 'theme';
			}
			$set['opts']                  = array_merge( Manohara_Templates::default_opts( $set['template'] ), (array) $set['opts'] );
			$design['devices'][ $device ] = $set;
		}
		return $design;
	}

	/**
	 * Save a design.
	 *
	 * @param array $design Design (id 0 for a new one).
	 * @return int ID.
	 */
	public static function save( $design ) {
		$all = get_option( self::DESIGNS, array() );
		$all = is_array( $all ) ? $all : array();
		$id  = (int) $design['id'];
		if ( ! $id ) {
			$id = $all ? max( array_map( 'intval', array_keys( $all ) ) ) + 1 : 1;
		}
		// One design per location: the location moves to this design.
		if ( '' !== $design['location'] ) {
			foreach ( $all as $other_id => $other ) {
				if ( (int) $other_id !== $id && isset( $other['location'] ) && $other['location'] === $design['location'] ) {
					$all[ $other_id ]['location'] = '';
				}
			}
		}
		unset( $design['id'] );
		$all[ $id ] = $design;
		update_option( self::DESIGNS, $all );
		return $id;
	}

	/**
	 * Delete a design.
	 *
	 * @param int $id ID.
	 */
	public static function delete( $id ) {
		$all = get_option( self::DESIGNS, array() );
		if ( is_array( $all ) && isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			update_option( self::DESIGNS, $all );
		}
	}

	/**
	 * Style used on a device (the design's, or the device's own).
	 *
	 * @param array  $design Design.
	 * @param string $device Device.
	 * @return array
	 */
	public static function device_style( $design, $device ) {
		$set = $design['devices'][ $device ];
		if ( ! empty( $set['style_override'] ) && $set['style'] ) {
			return array_merge( $design['style'], (array) $set['style'] );
		}
		return $design['style'];
	}

	/**
	 * Menu shown on a device: its own, the design's, or the one at the location.
	 *
	 * @param array  $design Design.
	 * @param string $device Device.
	 * @return int
	 */
	public static function device_menu( $design, $device ) {
		$set = $design['devices'][ $device ];
		if ( $set['menu'] && is_nav_menu( (int) $set['menu'] ) ) {
			return (int) $set['menu'];
		}
		if ( $design['menu'] && is_nav_menu( (int) $design['menu'] ) ) {
			return (int) $design['menu'];
		}
		$locations = get_nav_menu_locations();
		if ( '' !== $design['location'] && ! empty( $locations[ $design['location'] ] ) ) {
			return (int) $locations[ $design['location'] ];
		}
		$menus = wp_get_nav_menus();
		return $menus ? (int) $menus[0]->term_id : 0;
	}
}
