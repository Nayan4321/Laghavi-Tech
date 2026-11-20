<?php
/**
 * Mega menus inside menus drawn by Manohara: the item's mega menu (Appearance › Mega Menus, an
 * Elementor template or a synced pattern) replaces its submenu. Placement is done by mnhp.js.
 * Theme menus get the same mega menus through MNHP_Panels.
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mega menus in Manohara's menus.
 */
class MNHP_Mega {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'manohara_item_submenu', array( __CLASS__, 'submenu' ), 10, 5 );
	}

	/**
	 * The mega panel of a top-level item.
	 *
	 * @param string|null $custom Panel.
	 * @param WP_Post     $item   Item.
	 * @param array       $tree   Items by parent.
	 * @param array       $opts   Options.
	 * @param int         $depth  Depth.
	 * @return string|null
	 */
	public static function submenu( $custom, $item, $tree, $opts, $depth ) {
		unset( $tree, $opts );
		if ( 0 !== (int) $depth || ! MNHP_License::is_valid() ) {
			return $custom;
		}
		$s     = MNHP_Items::get( MNHP_I18n::source_item( $item->ID ) );
		$panel = (int) $s['panel'];
		if ( ! $panel ) {
			return $custom;
		}
		$body = MNHP_Panels::panel( $panel, 'manohara' );
		if ( '' === $body ) {
			return $custom;
		}
		MNHP_Frontend::$used = true;
		$p                   = MNHP_Panels::TYPE === get_post_type( $panel ) ? MNHP_Panels::settings( $panel ) : MNHP_Panels::settings( 0 );
		$width               = array(
			'full'    => 'full',
			'content' => 'container',
			'item'    => 'custom',
		);
		$class               = 'mnhp-mega mnhp-tpl-saved mnhp-w-' . $width[ isset( $width[ $p['width'] ] ) ? $p['width'] : 'content' ];
		return '<div class="' . esc_attr( $class ) . '" style="' . esc_attr( '--mnhp-w:' . (int) $p['width_px'] . 'px' ) . '">' . $body . '</div>';
	}
}
