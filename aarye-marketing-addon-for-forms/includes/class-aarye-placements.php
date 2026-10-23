<?php
/**
 * Where each form is used: scans published pages, posts, templates and popups for form shortcodes,
 * blocks and Elementor widgets of every supported form plugin. Also finds Elementor Pro forms, which
 * only exist inside page designs. Cached until a post is saved.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Placements.
 */
class AARYE_Placements {

	const CACHE = 'aarye_form_places';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'forget' ) );
		add_action( 'deleted_post', array( __CLASS__, 'forget' ) );
		add_action( 'elementor/document/after_save', array( __CLASS__, 'forget' ) );
	}

	/**
	 * Clear the cache.
	 */
	public static function forget() {
		delete_transient( self::CACHE );
	}

	/**
	 * Shortcode and block patterns per plugin; the first group is the form ID.
	 *
	 * @return array<string, string[]>
	 */
	private static function patterns() {
		$sc = static function ( $tag, $attr = 'id' ) {
			return '/\[' . $tag . '\b[^\]]*?\b' . $attr . '\s*=\s*["\']?([A-Za-z0-9_-]+)/i';
		};
		$block = static function ( $name, $attr ) {
			return '/<!--\s*wp:' . preg_quote( $name, '/' ) . '\s+\{[^}]*?"' . $attr . '"\s*:\s*"?([A-Za-z0-9_-]+)/i';
		};
		return array(
			'cf7'            => array( $sc( 'contact-form-7' ), $block( 'contact-form-7/contact-form-selector', 'id' ), $block( 'contact-form-7/contact-form-selector', 'hash' ) ),
			'wpforms'        => array( $sc( 'wpforms' ), $block( 'wpforms/form-selector', 'formId' ) ),
			'gravity'        => array( $sc( 'gravityforms?' ), $block( 'gravityforms/form', 'formId' ) ),
			'forminator'     => array( $sc( 'forminator_form' ), $block( 'forminator/forms', 'module_id' ) ),
			'ninja'          => array( $sc( 'ninja_forms?' ), $block( 'ninja-forms/form', 'formID' ) ),
			'formidable'     => array( $sc( 'formidable' ), $block( 'formidable/simple-form', 'formId' ) ),
			'fluentform'     => array( $sc( 'fluentform' ), $block( 'fluentfom/guten-block', 'formId' ), $block( 'fluent-forms/guten-block', 'formId' ) ),
			'jetformbuilder' => array( $sc( 'jet_fb_form', 'form_id' ), $block( 'jet-forms/form-block', 'form_id' ) ),
			'metform'        => array( $sc( 'metform', 'form_id' ) ),
		);
	}

	/**
	 * Scan the site (cached).
	 *
	 * @return array{places: array<string, int[]>, elementor: array<int, array{id: string, title: string}>}
	 */
	public static function scan() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['places'], $cached['elementor'] ) ) {
			return $cached;
		}
		global $wpdb;
		$places    = array();
		$elementor = array();
		$add       = static function ( $plugin, $form, $post_id ) use ( &$places ) {
			$key = $plugin . ':' . $form;
			if ( ! isset( $places[ $key ] ) || ! in_array( $post_id, $places[ $key ], true ) ) {
				$places[ $key ][] = $post_id;
			}
		};
		$patterns = self::patterns();
		$match    = static function ( $text, $post_id ) use ( $patterns, $add ) {
			foreach ( $patterns as $plugin => $list ) {
				foreach ( $list as $re ) {
					if ( preg_match_all( $re, $text, $m ) ) {
						foreach ( $m[1] as $id ) {
							$add( $plugin, $id, $post_id );
						}
					}
				}
			}
		};

		// Classic and block content.
		$likes = array( '[contact-form-7', '[wpforms', '[gravityform', '[forminator_form', '[ninja_form', '[formidable', '[fluentform', '[jet_fb_form', '[metform', 'wp:contact-form-7/', 'wp:wpforms/', 'wp:gravityforms/', 'wp:forminator/', 'wp:ninja-forms/', 'wp:formidable/', 'wp:fluentfom/', 'wp:fluent-forms/', 'wp:jet-forms/form-block' );
		$where = implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) );
		$args  = array_map(
			static function ( $l ) use ( $wpdb ) {
				return '%' . $wpdb->esc_like( $l ) . '%';
			},
			$likes
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where is fixed SQL with placeholders; cached.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','private','future') AND post_type NOT IN ('revision','nav_menu_item') AND ({$where}) LIMIT 2000", $args ) );
		foreach ( (array) $rows as $row ) {
			$match( (string) $row->post_content, (int) $row->ID );
		}

		// Elementor designs: Elementor Pro form widgets, MetForm and WPForms widgets, and shortcodes in them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cached.
		$rows = $wpdb->get_results( "SELECT p.ID, p.post_title, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_elementor_data' AND p.post_status IN ('publish','private','future','draft') AND p.post_type <> 'revision' AND m.meta_value LIKE '%widgetType%' LIMIT 2000" );
		$seen = array();
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row->ID;
			$page    = '' !== $row->post_title ? $row->post_title : '#' . $post_id;
			$walk    = static function ( $elements ) use ( &$walk, &$elementor, &$seen, $add, $match, $post_id, $page ) {
				foreach ( (array) $elements as $el ) {
					if ( ! is_array( $el ) ) {
						continue;
					}
					$type = isset( $el['widgetType'] ) ? (string) $el['widgetType'] : '';
					$set  = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
					if ( 'form' === $type && isset( $el['id'] ) ) {
						$id = (string) $el['id'];
						$add( 'elementor', $id, $post_id );
						if ( ! isset( $seen[ $id ] ) ) {
							$seen[ $id ] = true;
							$name        = isset( $set['form_name'] ) && '' !== trim( (string) $set['form_name'] ) ? (string) $set['form_name'] : __( 'Form', 'aarye-marketing-addon-for-forms' );
							$elementor[] = array(
								'id'    => $id,
								/* translators: 1: form name, 2: page title. */
								'title' => sprintf( __( '%1$s (on "%2$s")', 'aarye-marketing-addon-for-forms' ), $name, $page ),
							);
						}
					} elseif ( 'metform' === $type && ! empty( $set['mf_form_id'] ) ) {
						$add( 'metform', (string) $set['mf_form_id'], $post_id );
					} elseif ( 'wpforms' === $type && ! empty( $set['form_id'] ) ) {
						$add( 'wpforms', (string) $set['form_id'], $post_id );
					}
					foreach ( $set as $value ) {
						if ( is_string( $value ) && false !== strpos( $value, '[' ) ) {
							$match( $value, $post_id ); // Shortcode and text widgets.
						}
					}
					if ( ! empty( $el['elements'] ) ) {
						$walk( $el['elements'] );
					}
				}
			};
			$walk( json_decode( (string) $row->meta_value, true ) );
		}

		// Contact Form 7 shortcodes usually use the form's short hash.
		if ( post_type_exists( 'wpcf7_contact_form' ) && function_exists( 'wpcf7_contact_form' ) ) {
			foreach ( get_posts( array( 'post_type' => 'wpcf7_contact_form', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $cf7_id ) {
				$form = wpcf7_contact_form( $cf7_id );
				$hash = $form && method_exists( $form, 'hash' ) ? (string) $form->hash() : '';
				foreach ( array_keys( $places ) as $key ) {
					if ( '' !== $hash && ( 'cf7:' . $hash === $key || ( 0 === strpos( $key, 'cf7:' ) && 0 === strpos( $hash, substr( $key, 4 ) ) && strlen( $key ) > 8 ) ) ) {
						foreach ( $places[ $key ] as $post_id ) {
							$add( 'cf7', (string) $cf7_id, $post_id );
						}
						unset( $places[ $key ] );
					}
				}
			}
		}

		$out = array(
			'places'    => $places,
			'elementor' => $elementor,
		);
		set_transient( self::CACHE, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Posts where a form is used.
	 *
	 * @param string $plugin Plugin key.
	 * @param string $form   Form ID.
	 * @return int[]
	 */
	public static function of( $plugin, $form ) {
		$places = self::scan()['places'];
		$key    = $plugin . ':' . $form;
		return isset( $places[ $key ] ) ? $places[ $key ] : array();
	}
}
