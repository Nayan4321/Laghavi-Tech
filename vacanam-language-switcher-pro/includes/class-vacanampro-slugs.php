<?php
/**
 * Addresses in every language:
 *
 * - Shared slugs: a page and its translations can use the same slug (example.com/about/ and
 *   example.com/ar/about/), and copies keep the original slug instead of "about-ar".
 * - Translated bases: the fixed parts of addresses (product, product-category, portfolio, category,
 *   tag…) can be translated per language (example.com/ar/منتج/…).
 *
 * @package VacanamPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slugs.
 */
class VACANAMPRO_Slugs {

	/**
	 * Address as requested, while WordPress reads the one with original bases.
	 *
	 * @var string|null
	 */
	private static $original_uri = null;

	/**
	 * PATH_INFO as requested (some servers pass the path there too).
	 *
	 * @var string|null
	 */
	private static $original_path_info = null;

	/**
	 * Reading addresses keeps working while the license is checked, so links never break.
	 */
	public static function init_routing() {
		add_filter( 'vacanam_request_uri', array( __CLASS__, 'request_uri' ), 10, 2 );
		add_filter( 'do_parse_request', array( __CLASS__, 'before_parse' ), 5 );
		add_action( 'parse_request', array( __CLASS__, 'after_parse' ), 1 );
		add_filter( 'request', array( __CLASS__, 'resolve_shared' ), 5 );
		add_action( 'parse_query', array( __CLASS__, 'resolve_shared_terms' ), 1 );
	}

	/**
	 * Writing addresses and settings (licensed).
	 */
	public static function init() {
		add_filter( 'post_type_link', array( __CLASS__, 'post_link' ), 25, 2 );
		add_filter( 'post_link', array( __CLASS__, 'post_link' ), 25, 2 );
		add_filter( 'term_link', array( __CLASS__, 'term_link' ), 25, 3 );
		add_filter( 'post_type_archive_link', array( __CLASS__, 'archive_link' ), 25, 2 );
		add_filter( 'vacanam_duplicate_slug', array( __CLASS__, 'copy_slug' ), 10, 3 );
		add_filter( 'vacanam_duplicate_term_slug', array( __CLASS__, 'copy_term_slug' ), 10, 3 );
		add_action( 'vacanam_post_duplicated', array( __CLASS__, 'after_post_copy' ), 10, 3 );
		add_action( 'vacanam_term_duplicated', array( __CLASS__, 'after_term_copy' ), 10, 3 );
		add_filter( 'wp_unique_post_slug', array( __CLASS__, 'unique_post_slug' ), 20, 6 );
		add_filter( 'wp_unique_term_slug', array( __CLASS__, 'unique_term_slug' ), 20, 3 );
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 5 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save' ) );
	}

	/*
	 * Translated bases.
	 */

	/**
	 * Fixed address parts that can be translated: key => array( label, base ).
	 *
	 * @return array
	 */
	public static function base_keys() {
		$out = array();
		if ( ! class_exists( 'Vacanam_Model' ) ) {
			return $out;
		}
		foreach ( Vacanam_Model::post_types() as $type ) {
			$obj = get_post_type_object( $type );
			if ( $obj && ! empty( $obj->rewrite['slug'] ) && $obj->public && ! in_array( $type, array( 'post', 'page', 'attachment' ), true ) ) {
				$out[ 'pt:' . $type ] = array( $obj->labels->singular_name, trim( $obj->rewrite['slug'], '/' ) );
			}
		}
		foreach ( Vacanam_Model::taxonomies() as $tax ) {
			$obj = get_taxonomy( $tax );
			if ( $obj && ! empty( $obj->rewrite['slug'] ) && $obj->public ) {
				$label = $obj->labels->singular_name;
				$types = array_diff( (array) $obj->object_type, array( 'post' ) );
				$first = $types ? get_post_type_object( reset( $types ) ) : null;
				if ( $first ) {
					/* translators: 1: taxonomy, 2: post type, e.g. "Category (Products)". */
					$label = sprintf( __( '%1$s (%2$s)', 'vacanam-language-switcher-pro' ), $label, $first->labels->name );
				}
				$out[ 'tax:' . $tax ] = array( $label, trim( $obj->rewrite['slug'], '/' ) );
			}
		}
		return $out;
	}

	/**
	 * Translated bases of a language: original base => translated base.
	 *
	 * @param string $lang Language.
	 * @return array
	 */
	public static function bases( $lang ) {
		$all  = (array) VACANAMPRO_Settings::get( 'bases' );
		$keys = self::base_keys();
		$out  = array();
		if ( empty( $all[ $lang ] ) ) {
			return $out;
		}
		foreach ( (array) $all[ $lang ] as $key => $translated ) {
			if ( isset( $keys[ $key ] ) && '' !== (string) $translated && $translated !== $keys[ $key ][1] ) {
				$out[ $keys[ $key ][1] ] = (string) $translated;
			}
		}
		// Longer bases first, so "product-category" is matched before "product".
		uksort(
			$out,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $out;
	}

	/**
	 * Replace a base in an address path.
	 *
	 * @param string $url  Address (or path).
	 * @param array  $map  from => to.
	 * @return string
	 */
	private static function swap_base( $url, $map ) {
		foreach ( $map as $from => $to ) {
			$from_q = preg_quote( $from, '#' );
			$new    = preg_replace( '#(/)' . $from_q . '(?=/)#', '$1' . $to, $url, 1, $count );
			if ( $count ) {
				return $new;
			}
		}
		return $url;
	}

	/**
	 * Translated base in a link.
	 *
	 * @param string $url  Address.
	 * @param string $lang Language.
	 * @return string
	 */
	private static function translate_link( $url, $lang ) {
		if ( '' === $lang ) {
			return $url;
		}
		$map = self::bases( $lang );
		if ( ! $map ) {
			return $url;
		}
		$home = untrailingslashit( Vacanam_Router::url_for( trailingslashit( Vacanam_Router::raw_home() ), $lang ) );
		if ( 0 !== strpos( $url, $home ) ) {
			return $url;
		}
		$rest = substr( $url, strlen( $home ) );
		return $home . self::swap_base( $rest, array_map( array( __CLASS__, 'encode' ), $map ) );
	}

	/**
	 * Percent-encode a slug the way WordPress writes addresses.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function encode( $slug ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', rawurldecode( $slug ) ) ) );
	}

	/**
	 * Post address.
	 *
	 * @param string      $url  Address.
	 * @param WP_Post|int $post Post.
	 * @return string
	 */
	public static function post_link( $url, $post ) {
		$post = get_post( $post );
		return $post && Vacanam_Model::is_translated_type( $post->post_type ) ? self::translate_link( $url, Vacanam_Model::post_lang( $post ) ) : $url;
	}

	/**
	 * Term address.
	 *
	 * @param string  $url      Address.
	 * @param WP_Term $term     Term.
	 * @param string  $taxonomy Taxonomy.
	 * @return string
	 */
	public static function term_link( $url, $term, $taxonomy ) {
		return Vacanam_Model::is_translated_tax( $taxonomy ) ? self::translate_link( $url, Vacanam_Model::term_lang( $term ) ) : $url;
	}

	/**
	 * Archive address.
	 *
	 * @param string $url       Address.
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function archive_link( $url, $post_type = '' ) {
		return Vacanam_Router::is_front() ? self::translate_link( $url, Vacanam_Router::current() ) : $url;
	}

	/**
	 * Directory mode: the free plugin hands over the address without the language.
	 *
	 * @param string $uri  Address.
	 * @param string $lang Language.
	 * @return string
	 */
	public static function request_uri( $uri, $lang ) {
		return self::untranslate( $uri, $lang );
	}

	/**
	 * Translated bases back to the original ones, so WordPress's rules recognise the address.
	 *
	 * @param string $uri  Address.
	 * @param string $lang Language.
	 * @return string
	 */
	private static function untranslate( $uri, $lang ) {
		if ( '' === (string) $lang ) {
			return $uri;
		}
		$map = array();
		foreach ( self::bases( $lang ) as $from => $to ) {
			$map[ self::encode( $to ) ] = $from;
			$map[ $to ]                 = $from;
		}
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$decoded = rawurldecode( $uri );
		foreach ( $map as $translated => $original ) {
			foreach ( array( $uri, $decoded ) as $candidate ) {
				$new = preg_replace( '#(/)' . preg_quote( $translated, '#' ) . '(?=/|\?|$)#u', '$1' . $original, $candidate, 1, $count );
				if ( $count ) {
					return $new;
				}
			}
		}
		return $uri;
	}

	/**
	 * Other address modes (parameter, domains): translate bases while WordPress reads the address.
	 *
	 * @param bool $parse Whether to read it.
	 * @return bool
	 */
	public static function before_parse( $parse ) {
		if ( ! class_exists( 'Vacanam_Router' ) || ! Vacanam_Router::is_front() || 'directory' === Vacanam_Settings::get( 'url_mode' ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return $parse;
		}
		$uri = $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as it was.
		$new = self::untranslate( $uri, Vacanam_Router::current() );
		if ( $new !== $uri ) {
			self::$original_uri     = $uri;
			$_SERVER['REQUEST_URI'] = $new;
		}
		if ( ! empty( $_SERVER['PATH_INFO'] ) ) {
			$info = $_SERVER['PATH_INFO']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as it was; WordPress reads it next.
			$new  = self::untranslate( $info, Vacanam_Router::current() );
			if ( $new !== $info ) {
				self::$original_path_info = $info;
				$_SERVER['PATH_INFO']     = $new;
			}
		}
		return $parse;
	}

	/**
	 * Put the address back as requested.
	 */
	public static function after_parse() {
		if ( null !== self::$original_uri ) {
			$_SERVER['REQUEST_URI'] = self::$original_uri;
			self::$original_uri     = null;
		}
		if ( null !== self::$original_path_info ) {
			$_SERVER['PATH_INFO']     = self::$original_path_info;
			self::$original_path_info = null;
		}
	}

	/*
	 * Shared slugs.
	 */

	/**
	 * Copies keep the original slug.
	 *
	 * @param string  $slug Slug.
	 * @param WP_Post $post Original.
	 * @param string  $lang Language.
	 * @return string
	 */
	public static function copy_slug( $slug, $post, $lang ) {
		return VACANAMPRO_Settings::on( 'share_slugs' ) ? $post->post_name : $slug;
	}

	/**
	 * Copies of terms keep the original slug.
	 *
	 * @param string  $slug Slug.
	 * @param WP_Term $term Original.
	 * @param string  $lang Language.
	 * @return string
	 */
	public static function copy_term_slug( $slug, $term, $lang ) {
		return VACANAMPRO_Settings::on( 'share_slugs' ) ? $term->slug : $slug;
	}

	/**
	 * WordPress makes slugs unique while inserting, before the copy has its language: give the copy
	 * the original slug once its language is set, unless a post of that language already uses it.
	 *
	 * @param int    $new  Copy ID.
	 * @param int    $id   Original ID.
	 * @param string $lang Language.
	 */
	public static function after_post_copy( $new, $id, $lang ) {
		global $wpdb;
		$new  = (int) $new;
		$post = get_post( (int) $id );
		$copy = get_post( $new );
		if ( ! VACANAMPRO_Settings::on( 'share_slugs' ) || ! $copy || ! $post || '' === $post->post_name || $copy->post_name === $post->post_name ) {
			return;
		}
		if ( self::slug_taken_in_language( $post->post_name, $copy, $lang ) ) {
			return;
		}
		$wpdb->update( $wpdb->posts, array( 'post_name' => $post->post_name ), array( 'ID' => $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WordPress would make the slug unique again.
		clean_post_cache( $new );
	}

	/**
	 * Same for terms.
	 *
	 * @param int    $new Copy term ID.
	 * @param int    $id  Original term ID.
	 * @param string $lang Language.
	 */
	public static function after_term_copy( $new, $id, $lang ) {
		global $wpdb;
		$term = get_term( (int) $id );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$copy = get_term( (int) $new );
		if ( ! VACANAMPRO_Settings::on( 'share_slugs' ) || ! $copy || is_wp_error( $copy ) || ! $term || $copy->slug === $term->slug ) {
			return;
		}
		foreach ( self::terms_with_slug( $term->slug, $copy->taxonomy ) as $other ) {
			if ( (int) $other !== (int) $copy->term_id && Vacanam_Model::term_lang( $other ) === $lang ) {
				return;
			}
		}
		$wpdb->update( $wpdb->terms, array( 'slug' => $term->slug ), array( 'term_id' => $copy->term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WordPress would make the slug unique again.
		clean_term_cache( $copy->term_id, $copy->taxonomy );
	}

	/**
	 * Whether another post of the same type, parent and language uses a slug.
	 *
	 * @param string  $slug Slug.
	 * @param WP_Post $post Post.
	 * @param string  $lang Language.
	 * @return bool
	 */
	private static function slug_taken_in_language( $slug, $post, $lang ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_parent = %d AND ID <> %d AND post_status NOT IN ('trash','auto-draft','inherit')", $slug, $post->post_type, $post->post_parent, $post->ID ) );
		foreach ( $ids as $id ) {
			if ( Vacanam_Model::post_lang( (int) $id ) === $lang ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Term IDs with a slug in a taxonomy.
	 *
	 * @param string $slug     Slug.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private static function terms_with_slug( $slug, $taxonomy ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s", $slug, $taxonomy ) ) );
	}

	/**
	 * Editing a post: a slug used only by other languages' posts may be used again.
	 *
	 * @param string $slug          Unique slug WordPress made.
	 * @param int    $post_id       Post.
	 * @param string $post_status   Status.
	 * @param string $post_type     Type.
	 * @param int    $post_parent   Parent.
	 * @param string $original_slug Slug asked for.
	 * @return string
	 */
	public static function unique_post_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		if ( $slug === $original_slug || ! VACANAMPRO_Settings::on( 'share_slugs' ) || ! Vacanam_Model::is_translated_type( $post_type ) || ! $post_id ) {
			return $slug;
		}
		$post = get_post( $post_id );
		$lang = $post ? Vacanam_Model::post_lang( $post ) : '';
		if ( '' === $lang ) {
			return $slug;
		}
		$probe              = clone $post;
		$probe->post_parent = (int) $post_parent;
		return self::slug_taken_in_language( $original_slug, $probe, $lang ) ? $slug : $original_slug;
	}

	/**
	 * Editing a term: same.
	 *
	 * @param string $slug Unique slug.
	 * @param object $term Term being saved.
	 * @param string $original_slug Slug asked for.
	 * @return string
	 */
	public static function unique_term_slug( $slug, $term, $original_slug ) {
		if ( $slug === $original_slug || ! VACANAMPRO_Settings::on( 'share_slugs' ) || empty( $term->term_id ) || empty( $term->taxonomy ) || ! Vacanam_Model::is_translated_tax( $term->taxonomy ) ) {
			return $slug;
		}
		$lang = Vacanam_Model::term_lang( (int) $term->term_id );
		if ( '' === $lang ) {
			return $slug;
		}
		foreach ( self::terms_with_slug( $original_slug, $term->taxonomy ) as $other ) {
			if ( (int) $other !== (int) $term->term_id && Vacanam_Model::term_lang( $other ) === $lang ) {
				return $slug;
			}
		}
		return $original_slug;
	}

	/**
	 * A slug shared by several languages: open the post of the language of the address.
	 *
	 * @param array $vars Query variables.
	 * @return array
	 */
	public static function resolve_shared( $vars ) {
		if ( ! class_exists( 'Vacanam_Router' ) || ! Vacanam_Router::is_front() || ! VACANAMPRO_Settings::on( 'share_slugs' ) ) {
			return $vars;
		}
		$lang = Vacanam_Router::current();
		if ( '' === $lang ) {
			return $vars;
		}
		if ( ! empty( $vars['pagename'] ) ) {
			$id = self::page_by_path( $vars['pagename'], 'page', $lang );
			if ( $id ) {
				$vars['page_id'] = $id;
				unset( $vars['pagename'] );
			}
			return $vars;
		}
		foreach ( Vacanam_Model::post_types() as $type ) {
			$obj = get_post_type_object( $type );
			$var = 'post' === $type ? 'name' : ( $obj && $obj->query_var ? $obj->query_var : '' );
			if ( '' === $var || empty( $vars[ $var ] ) || ( 'post' === $type && ! empty( $vars['post_type'] ) && 'post' !== $vars['post_type'] ) ) {
				continue;
			}
			$id = self::page_by_path( (string) $vars[ $var ], $type, $lang );
			if ( $id ) {
				$vars['p']         = $id;
				$vars['post_type'] = $type;
				unset( $vars[ $var ], $vars['name'] );
			}
			return $vars;
		}
		return $vars;
	}

	/**
	 * Post of a language at a path (hierarchical paths like parent/child included).
	 *
	 * @param string $path Path.
	 * @param string $type Post type.
	 * @param string $lang Language.
	 * @return int
	 */
	private static function page_by_path( $path, $type, $lang ) {
		global $wpdb;
		$parts = array_values( array_filter( explode( '/', trim( rawurldecode( (string) $path ), '/' ) ), 'strlen' ) );
		if ( ! $parts ) {
			return 0;
		}
		$last = sanitize_title( end( $parts ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name IN (%s, %s) AND post_type = %s AND post_status NOT IN ('trash','auto-draft')", $last, rawurlencode( end( $parts ) ), $type ) );
		if ( count( $ids ) < 2 ) {
			return 0; // Unique slug: WordPress finds it by itself.
		}
		$want = implode( '/', array_map( 'sanitize_title', $parts ) );
		foreach ( $ids as $id ) {
			if ( Vacanam_Model::post_lang( (int) $id ) !== $lang ) {
				continue;
			}
			$uri = get_page_uri( (int) $id );
			if ( count( $parts ) === 1 || rawurldecode( (string) $uri ) === rawurldecode( $want ) || $uri === $want ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * A term slug shared by several languages: list the term of the language of the address.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function resolve_shared_terms( $query ) {
		if ( ! $query->is_main_query() || is_admin() || ! class_exists( 'Vacanam_Router' ) || ! Vacanam_Router::is_front() || ! VACANAMPRO_Settings::on( 'share_slugs' ) ) {
			return;
		}
		$lang = Vacanam_Router::current();
		foreach ( Vacanam_Model::taxonomies() as $tax ) {
			$obj = get_taxonomy( $tax );
			$var = 'category' === $tax ? 'category_name' : ( 'post_tag' === $tax ? 'tag' : ( $obj ? $obj->query_var : '' ) );
			$val = $var ? $query->get( $var ) : '';
			if ( '' === (string) $val || false !== strpos( (string) $val, ',' ) || false !== strpos( (string) $val, '+' ) ) {
				continue;
			}
			$slug = sanitize_title( basename( (string) $val ) );
			$ids  = self::terms_with_slug( $slug, $tax );
			if ( count( $ids ) < 2 ) {
				continue;
			}
			foreach ( $ids as $id ) {
				if ( Vacanam_Model::term_lang( $id ) === $lang ) {
					$query->set( $var, '' );
					$query->set(
						'tax_query',
						array(
							array(
								'taxonomy' => $tax,
								'field'    => 'term_id',
								'terms'    => array( $id ),
							),
						)
					);
					if ( 'category' === $tax ) {
						$query->set( 'cat', $id );
					}
					// Rebuild the term query now, before other plugins look up the page's term by its slug.
					$query->parse_tax_query( $query->query_vars );
					$query->queried_object    = null;
					$query->queried_object_id = null;
					return;
				}
			}
		}
	}

	/*
	 * Settings.
	 */

	/**
	 * Settings section.
	 */
	public static function section() {
		$bases = (array) VACANAMPRO_Settings::get( 'bases' );
		$keys  = self::base_keys();
		$langs = Vacanam_Languages::all();
		?>
		<div class="vacanam-card">
			<h2><?php esc_html_e( 'Addresses', 'vacanam-language-switcher-pro' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Shared slugs', 'vacanam-language-switcher-pro' ); ?></th><td>
					<label><input type="checkbox" name="vacanampro_share_slugs" value="1" <?php checked( VACANAMPRO_Settings::on( 'share_slugs' ) ); ?> /> <?php esc_html_e( 'Translations may use the same slug as the original (example.com/about/ and example.com/ar/about/). New copies keep the original slug instead of adding the language code.', 'vacanam-language-switcher-pro' ); ?></label>
				</td></tr>
			</table>
			<?php if ( $keys ) : ?>
				<h3><?php esc_html_e( 'Translated address parts', 'vacanam-language-switcher-pro' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Translate the fixed parts of addresses, like "product" or "product-category". Leave a field empty to keep the original. Both the original and the translation keep working.', 'vacanam-language-switcher-pro' ); ?></p>
				<table class="widefat striped" style="max-width:980px;margin-top:10px">
					<thead><tr><th><?php esc_html_e( 'Address part', 'vacanam-language-switcher-pro' ); ?></th>
					<?php foreach ( $langs as $slug => $lang ) : ?>
						<?php
						if ( $slug === Vacanam_Languages::default_slug() ) {
							continue;
						}
						?>
						<th><?php echo esc_html( $lang['name'] ); ?></th>
					<?php endforeach; ?></tr></thead>
					<tbody>
					<?php foreach ( $keys as $key => $info ) : ?>
						<tr><td><strong><?php echo esc_html( $info[0] ); ?></strong><br /><code><?php echo esc_html( $info[1] ); ?></code></td>
						<?php foreach ( $langs as $slug => $lang ) : ?>
							<?php
							if ( $slug === Vacanam_Languages::default_slug() ) {
								continue;
							}
							$value = isset( $bases[ $slug ][ $key ] ) ? rawurldecode( (string) $bases[ $slug ][ $key ] ) : '';
							?>
							<td><input type="text" dir="auto" name="vacanampro_bases[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $info[1] ); ?>" style="width:100%" /></td>
						<?php endforeach; ?></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save the section.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save( $values ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in VACANAMPRO_Settings::save().
		$values['share_slugs'] = empty( $_POST['vacanampro_share_slugs'] ) ? 'no' : 'yes';
		$bases                 = array();
		$keys                  = self::base_keys();
		$posted                = isset( $_POST['vacanampro_bases'] ) ? (array) wp_unslash( $_POST['vacanampro_bases'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized below.
		// phpcs:enable
		foreach ( $posted as $lang => $row ) {
			$lang = sanitize_key( $lang );
			if ( ! Vacanam_Languages::exists( $lang ) ) {
				continue;
			}
			foreach ( (array) $row as $key => $text ) {
				$key  = sanitize_text_field( (string) $key );
				$slug = implode( '/', array_filter( array_map( 'sanitize_title', explode( '/', sanitize_text_field( (string) $text ) ) ) ) );
				if ( isset( $keys[ $key ] ) && '' !== $slug && rawurldecode( $slug ) !== $keys[ $key ][1] ) {
					$bases[ $lang ][ $key ] = rawurldecode( $slug );
				}
			}
		}
		$values['bases'] = $bases;
		return $values;
	}
}
