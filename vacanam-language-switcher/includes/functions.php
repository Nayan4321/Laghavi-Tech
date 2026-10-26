<?php
/**
 * Functions for themes and plugins.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Language of the visit (in the admin: the language chosen in the admin bar, else the main language).
 *
 * @param string $field slug, locale, name, flag, rtl or hreflang.
 * @return string|bool
 */
function vacanam_current_language( $field = 'slug' ) {
	$slug = Vacanam_Router::current();
	if ( '' === $slug && is_admin() && class_exists( 'Vacanam_Admin_Content' ) ) {
		$slug = Vacanam_Admin_Content::filter_language();
	}
	if ( '' === $slug ) {
		$slug = Vacanam_Languages::default_slug();
	}
	return vacanam_language_field( $slug, $field );
}

/**
 * Main language.
 *
 * @param string $field slug, locale, name, flag, rtl or hreflang.
 * @return string|bool
 */
function vacanam_default_language( $field = 'slug' ) {
	return vacanam_language_field( Vacanam_Languages::default_slug(), $field );
}

/**
 * One field of a language.
 *
 * @param string $slug  Language.
 * @param string $field Field.
 * @return string|bool
 */
function vacanam_language_field( $slug, $field ) {
	$lang = Vacanam_Languages::get( $slug );
	if ( ! $lang ) {
		return '';
	}
	if ( 'hreflang' === $field ) {
		return Vacanam_Languages::hreflang( $lang );
	}
	return isset( $lang[ $field ] ) ? $lang[ $field ] : $slug;
}

/**
 * The site's languages.
 *
 * @param string $field Field to return for each language ('' for the whole language).
 * @return array
 */
function vacanam_languages( $field = 'slug' ) {
	$out = array();
	foreach ( Vacanam_Languages::all() as $slug => $lang ) {
		$out[ $slug ] = '' === $field ? $lang : vacanam_language_field( $slug, $field );
	}
	return '' === $field ? $out : array_values( $out );
}

/**
 * Translation of a post or page.
 *
 * @param int    $id   Post ID.
 * @param string $lang Language ('' for the language of the visit).
 * @return int 0 when it is not translated.
 */
function vacanam_get_post( $id, $lang = '' ) {
	return Vacanam_Model::post_translation( (int) $id, '' !== $lang ? $lang : vacanam_current_language() );
}

/**
 * Translation of a category, tag or other term.
 *
 * @param int    $id   Term ID.
 * @param string $lang Language ('' for the language of the visit).
 * @return int
 */
function vacanam_get_term( $id, $lang = '' ) {
	return Vacanam_Model::term_translation( (int) $id, '' !== $lang ? $lang : vacanam_current_language() );
}

/**
 * Language of a post.
 *
 * @param int $id Post ID.
 * @return string
 */
function vacanam_get_post_language( $id ) {
	return Vacanam_Model::post_lang( (int) $id );
}

/**
 * Language of a term.
 *
 * @param int $id Term ID.
 * @return string
 */
function vacanam_get_term_language( $id ) {
	return Vacanam_Model::term_lang( (int) $id );
}

/**
 * Home page address of a language.
 *
 * @param string $lang Language ('' for the language of the visit).
 * @return string
 */
function vacanam_home_url( $lang = '' ) {
	return Vacanam_Router::home_for( '' !== $lang ? $lang : vacanam_current_language() );
}

/**
 * Show a language switcher.
 *
 * @param array $args See Vacanam_Switcher::defaults(); echo => false returns the HTML.
 * @return string
 */
function vacanam_switcher( $args = array() ) {
	$html = Vacanam_Switcher::render( $args );
	if ( ! isset( $args['echo'] ) || $args['echo'] ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render().
	}
	return $html;
}

/**
 * Languages of the current page, with their addresses (to build your own switcher).
 *
 * @param array $args hide_current.
 * @return array
 */
function vacanam_language_links( $args = array() ) {
	return Vacanam_Switcher::items( $args );
}

/**
 * Register a text of your theme or plugin, translatable in Languages > Texts.
 *
 * @param string $name      Name.
 * @param string $string    Text.
 * @param string $group     Group.
 * @param bool   $multiline Several lines.
 */
function vacanam_register_string( $name, $string, $group = 'Theme', $multiline = false ) {
	Vacanam_Strings::register( $name, $string, $group, $multiline );
}

/**
 * Translation of a registered text in the language of the visit.
 *
 * @param string $string Text.
 * @param string $lang   Language ('' for the language of the visit).
 * @return string
 */
function vacanam__( $string, $lang = '' ) {
	return Vacanam_Strings::get( $string, $lang );
}

/**
 * Echo the translation of a registered text.
 *
 * @param string $string Text.
 */
function vacanam_e( $string ) {
	echo esc_html( Vacanam_Strings::get( $string ) );
}
