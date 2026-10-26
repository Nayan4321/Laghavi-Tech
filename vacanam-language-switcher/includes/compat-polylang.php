<?php
/**
 * Polylang's functions, for themes and plugins written for Polylang. Loaded only when Polylang is not
 * active.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Polylang's public API names, so existing themes keep working.

/**
 * Current language.
 *
 * @param string $field Field.
 * @return string|false
 */
function pll_current_language( $field = 'slug' ) {
	$map = array(
		'locale' => 'locale',
		'name'   => 'name',
		'slug'   => 'slug',
		'flag'   => 'flag',
	);
	return vacanam_current_language( isset( $map[ $field ] ) ? $map[ $field ] : 'slug' );
}

/**
 * Main language.
 *
 * @param string $field Field.
 * @return string|false
 */
function pll_default_language( $field = 'slug' ) {
	return vacanam_default_language( 'locale' === $field || 'name' === $field ? $field : 'slug' );
}

/**
 * Translation of a post.
 *
 * @param int    $id   Post ID.
 * @param string $lang Language.
 * @return int|false|null
 */
function pll_get_post( $id, $lang = '' ) {
	$tr = vacanam_get_post( $id, $lang );
	return $tr ? $tr : false;
}

/**
 * Translation of a term.
 *
 * @param int    $id   Term ID.
 * @param string $lang Language.
 * @return int|false
 */
function pll_get_term( $id, $lang = '' ) {
	$tr = vacanam_get_term( $id, $lang );
	return $tr ? $tr : false;
}

/**
 * Translations of a post.
 *
 * @param int $id Post ID.
 * @return int[]
 */
function pll_get_post_translations( $id ) {
	return Vacanam_Model::post_translations( (int) $id );
}

/**
 * Translations of a term.
 *
 * @param int $id Term ID.
 * @return int[]
 */
function pll_get_term_translations( $id ) {
	return Vacanam_Model::term_translations( (int) $id );
}

/**
 * Language of a post.
 *
 * @param int    $id    Post ID.
 * @param string $field Field.
 * @return string|false
 */
function pll_get_post_language( $id, $field = 'slug' ) {
	$slug = vacanam_get_post_language( $id );
	return '' === $slug ? false : vacanam_language_field( $slug, 'slug' === $field ? 'slug' : $field );
}

/**
 * Language of a term.
 *
 * @param int    $id    Term ID.
 * @param string $field Field.
 * @return string|false
 */
function pll_get_term_language( $id, $field = 'slug' ) {
	$slug = vacanam_get_term_language( $id );
	return '' === $slug ? false : vacanam_language_field( $slug, 'slug' === $field ? 'slug' : $field );
}

/**
 * Home address.
 *
 * @param string $lang Language.
 * @return string
 */
function pll_home_url( $lang = '' ) {
	return vacanam_home_url( $lang );
}

/**
 * Languages.
 *
 * @param array $args fields.
 * @return array
 */
function pll_languages_list( $args = array() ) {
	$field = isset( $args['fields'] ) ? $args['fields'] : 'slug';
	return vacanam_languages( in_array( $field, array( 'slug', 'locale', 'name', 'flag' ), true ) ? $field : 'slug' );
}

/**
 * Register a text.
 *
 * @param string $name      Name.
 * @param string $string    Text.
 * @param string $context   Group.
 * @param bool   $multiline Several lines.
 */
function pll_register_string( $name, $string, $context = 'Polylang', $multiline = false ) {
	vacanam_register_string( $name, $string, $context, $multiline );
}

/**
 * Translated text.
 *
 * @param string $string Text.
 * @return string
 */
function pll__( $string ) {
	return vacanam__( $string );
}

/**
 * Echo a translated text.
 *
 * @param string $string Text.
 */
function pll_e( $string ) {
	vacanam_e( $string );
}

/**
 * Translated text in a language.
 *
 * @param string $string Text.
 * @param string $lang   Language.
 * @return string
 */
function pll_translate_string( $string, $lang ) {
	return vacanam__( $string, $lang );
}

/**
 * Language switcher.
 *
 * @param array $args Polylang arguments.
 * @return string|array
 */
function pll_the_languages( $args = array() ) {
	$args  = wp_parse_args(
		$args,
		array(
			'dropdown'      => 0,
			'echo'          => 1,
			'show_flags'    => 0,
			'show_names'    => 1,
			'hide_current'  => 0,
			'raw'           => 0,
			'display_names_as' => 'name',
		)
	);
	$items = Vacanam_Switcher::items( array( 'hide_current' => $args['hide_current'] ) );
	if ( $args['raw'] ) {
		$out = array();
		foreach ( $items as $slug => $item ) {
			$out[ $slug ] = array(
				'slug'           => $slug,
				'name'           => $item['name'],
				'url'            => $item['url'],
				'flag'           => $item['flag'],
				'current_lang'   => $item['current'],
				'no_translation' => false,
				'locale'         => str_replace( '_', '-', vacanam_language_field( $slug, 'locale' ) ),
				'classes'        => array( 'lang-item', 'lang-item-' . $slug ),
			);
		}
		return $out;
	}
	$html = '';
	foreach ( $items as $item ) {
		$label = '';
		if ( $args['show_flags'] && $item['flag'] ) {
			$label .= '<img src="' . esc_url( $item['flag'] ) . '" alt="' . esc_attr( $item['name'] ) . '" width="16" height="12" />';
		}
		if ( $args['show_names'] ) {
			$label .= '<span>' . esc_html( 'slug' === $args['display_names_as'] ? $item['code'] : $item['name'] ) . '</span>';
		}
		$html .= '<li class="lang-item lang-item-' . esc_attr( $item['slug'] ) . ( $item['current'] ? ' current-lang' : '' ) . '"><a lang="' . esc_attr( $item['hreflang'] ) . '" hreflang="' . esc_attr( $item['hreflang'] ) . '" href="' . esc_url( $item['url'] ) . '">' . $label . '</a></li>';
	}
	if ( $args['dropdown'] ) {
		$html = Vacanam_Switcher::render(
			array(
				'style' => 'select',
				'hide_current' => $args['hide_current'],
			)
		);
	}
	if ( $args['echo'] ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}
	return $html;
}
