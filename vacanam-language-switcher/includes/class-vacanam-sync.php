<?php
/**
 * Synchronization: when a post is saved, chosen properties are copied to its translations
 * (categories and tags in their language, featured image, parent page, template, order, comments,
 * sticky, post format, date, custom fields). Texts and page-builder designs are never synchronized.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sync.
 */
class Vacanam_Sync {

	/**
	 * Running (no loops).
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'saved' ), 100, 2 );
	}

	/**
	 * What can be synchronized.
	 *
	 * @return string[] key => label.
	 */
	public static function options() {
		return array(
			'taxonomies'     => __( 'Categories, tags and other taxonomies', 'vacanam-language-switcher' ),
			'featured'       => __( 'Featured image', 'vacanam-language-switcher' ),
			'parent'         => __( 'Parent page', 'vacanam-language-switcher' ),
			'template'       => __( 'Page template', 'vacanam-language-switcher' ),
			'order'          => __( 'Page order', 'vacanam-language-switcher' ),
			'comment_status' => __( 'Comments and pings open or closed', 'vacanam-language-switcher' ),
			'sticky'         => __( 'Sticky posts', 'vacanam-language-switcher' ),
			'format'         => __( 'Post format', 'vacanam-language-switcher' ),
			'date'           => __( 'Published date', 'vacanam-language-switcher' ),
			'meta'           => __( 'Custom fields (except page-builder designs and SEO texts)', 'vacanam-language-switcher' ),
		);
	}

	/**
	 * Is it switched on?
	 *
	 * @param string $key Option.
	 * @return bool
	 */
	public static function on( $key ) {
		return in_array( $key, (array) Vacanam_Settings::get( 'sync' ), true );
	}

	/**
	 * A post was saved.
	 *
	 * @param int     $id   Post ID.
	 * @param WP_Post $post Post.
	 */
	public static function saved( $id, $post ) {
		if ( self::$running || Vacanam_Duplicator::running() || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || 'auto-draft' === $post->post_status || ! Vacanam_Model::is_translated_type( $post->post_type ) ) {
			return;
		}
		$group = Vacanam_Model::post_translations( $id );
		if ( count( $group ) < 2 || ! Vacanam_Settings::get( 'sync' ) ) {
			return;
		}
		self::$running = true;
		foreach ( $group as $lang => $target ) {
			if ( $target !== $id && get_post( $target ) ) {
				self::copy( $post, $target, $lang );
			}
		}
		self::$running = false;
	}

	/**
	 * Copy the chosen properties of a post to a translation.
	 *
	 * @param WP_Post $from Source.
	 * @param int     $to   Translation.
	 * @param string  $lang Language of the translation.
	 */
	public static function copy( $from, $to, $lang ) {
		global $wpdb;
		$fields = array();
		if ( self::on( 'parent' ) && is_post_type_hierarchical( $from->post_type ) ) {
			$parent             = $from->post_parent ? Vacanam_Model::post_translation( $from->post_parent, $lang ) : 0;
			$fields['post_parent'] = $from->post_parent ? ( $parent ? $parent : (int) get_post_field( 'post_parent', $to ) ) : 0;
		}
		if ( self::on( 'order' ) ) {
			$fields['menu_order'] = $from->menu_order;
		}
		if ( self::on( 'comment_status' ) ) {
			$fields['comment_status'] = $from->comment_status;
			$fields['ping_status']    = $from->ping_status;
		}
		if ( self::on( 'date' ) && 'publish' === $from->post_status ) {
			$fields['post_date']     = $from->post_date;
			$fields['post_date_gmt'] = $from->post_date_gmt;
		}
		if ( $fields ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Avoids running every save hook again for each translation.
			$wpdb->update( $wpdb->posts, $fields, array( 'ID' => $to ) );
			clean_post_cache( $to );
		}
		if ( self::on( 'template' ) ) {
			$template = get_post_meta( $from->ID, '_wp_page_template', true );
			if ( $template ) {
				update_post_meta( $to, '_wp_page_template', $template );
			} else {
				delete_post_meta( $to, '_wp_page_template' );
			}
		}
		if ( self::on( 'featured' ) ) {
			$thumb = (int) get_post_thumbnail_id( $from );
			if ( $thumb ) {
				$tr = Vacanam_Model::is_translated_type( 'attachment' ) ? Vacanam_Model::post_translation( $thumb, $lang ) : 0;
				set_post_thumbnail( $to, $tr ? $tr : $thumb );
			} else {
				delete_post_thumbnail( $to );
			}
		}
		if ( self::on( 'sticky' ) && 'post' === $from->post_type ) {
			if ( is_sticky( $from->ID ) ) {
				stick_post( $to );
			} else {
				unstick_post( $to );
			}
		}
		if ( self::on( 'taxonomies' ) || self::on( 'format' ) ) {
			self::copy_terms( $from, $to, $lang );
		}
		if ( self::on( 'meta' ) ) {
			self::copy_meta( $from->ID, $to );
		}
		/**
		 * Properties were copied to a translation.
		 *
		 * @param WP_Post $from Source.
		 * @param int     $to   Translation.
		 * @param string  $lang Language.
		 */
		do_action( 'vacanam_synced', $from, $to, $lang );
	}

	/**
	 * Terms: translatable taxonomies in the language of the translation, others as they are.
	 *
	 * @param WP_Post $from Source.
	 * @param int     $to   Translation.
	 * @param string  $lang Language.
	 */
	public static function copy_terms( $from, $to, $lang ) {
		foreach ( get_object_taxonomies( $from->post_type ) as $tax ) {
			if ( Vacanam_Model::TAX === $tax ) {
				continue;
			}
			if ( 'post_format' === $tax ? ! self::on( 'format' ) : ! self::on( 'taxonomies' ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $from->ID, $tax, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			if ( Vacanam_Model::is_translated_tax( $tax ) ) {
				$mapped = array();
				foreach ( $terms as $term ) {
					$tr = Vacanam_Model::term_translation( $term, $lang );
					if ( $tr ) {
						$mapped[] = $tr;
					} elseif ( '' === Vacanam_Model::term_lang( $term ) ) {
						$mapped[] = (int) $term; // A term without language fits every language.
					}
				}
				$terms = $mapped;
			}
			wp_set_object_terms( $to, array_map( 'intval', $terms ), $tax );
		}
	}

	/**
	 * Custom fields that are never copied between translations: texts, designs and SEO.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_private_meta( $key ) {
		$never = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_page_template', '_thumbnail_id', Vacanam_Model::TR_META, '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt' );
		if ( in_array( $key, $never, true ) ) {
			return true;
		}
		// Page builders, SEO plugins, our own keys.
		return (bool) preg_match( '/^(_elementor|_et_|_fl_builder|_bricks|_oxygen|ct_builder|_themify|_wpb|_vc_|_brizy|panels_data|_yoast_wpseo_|rank_math_|_aioseo_|_seopress_|_sq_|_vacanam)/', $key );
	}

	/**
	 * Copy custom fields.
	 *
	 * @param int $from Source.
	 * @param int $to   Translation.
	 */
	private static function copy_meta( $from, $to ) {
		foreach ( get_post_meta( $from ) as $key => $values ) {
			if ( self::is_private_meta( $key ) ) {
				continue;
			}
			/**
			 * Copy this custom field to translations.
			 *
			 * @param bool   $copy Copy.
			 * @param string $key  Meta key.
			 */
			if ( ! apply_filters( 'vacanam_sync_meta', true, $key ) ) {
				continue;
			}
			delete_post_meta( $to, $key );
			foreach ( $values as $value ) {
				add_post_meta( $to, $key, maybe_unserialize( $value ) );
			}
		}
	}
}
