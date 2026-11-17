<?php
/**
 * Multilingual sites. Menu translations are separate menus, so each language's menu items keep their
 * own icons, badges and mega menus (Vacanam copies them when it copies a menu). When a translated item
 * has no settings of its own, the original item's are used (WPML). Patterns and Elementor templates
 * shown in panels follow the visitor's language.
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Multilingual support.
 */
class MNHP_I18n {

	/**
	 * Item whose settings are used for a menu item.
	 *
	 * @param int $id Item.
	 * @return int
	 */
	public static function source_item( $id ) {
		$id = (int) $id;
		if ( $id <= 0 || metadata_exists( 'post', $id, MNHP_Items::META ) ) {
			return $id;
		}
		if ( 'wpml' === Manohara_I18n::plugin() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filters.
			$default = (string) apply_filters( 'wpml_default_language', '' );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filters.
			$orig = (int) apply_filters( 'wpml_object_id', $id, 'nav_menu_item', false, $default );
			if ( $orig && $orig !== $id ) {
				return $orig;
			}
		}
		return $id;
	}

	/**
	 * A post (pattern, template) in the visitor's language.
	 *
	 * @param int $id Post.
	 * @return int
	 */
	public static function post( $id ) {
		$id = (int) $id;
		if ( ! $id ) {
			return 0;
		}
		switch ( Manohara_I18n::plugin() ) {
			case 'vacanam':
				if ( function_exists( 'vacanam_get_post' ) ) {
					$copy = (int) vacanam_get_post( $id );
					return $copy ? $copy : $id;
				}
				break;
			case 'polylang':
				if ( function_exists( 'pll_get_post' ) ) {
					$copy = (int) pll_get_post( $id );
					return $copy ? $copy : $id;
				}
				break;
			case 'wpml':
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
				return (int) apply_filters( 'wpml_object_id', $id, get_post_type( $id ), true );
		}
		return $id;
	}

	/**
	 * Register Pro texts of each design for translation (menu button label).
	 *
	 * @param string[] $fields Fields.
	 * @return string[]
	 */
	public static function fields( $fields ) {
		$fields[] = 'mnhp_toggle_label';
		$fields[] = 'mnhp_mobile_logo';
		return $fields;
	}
}
