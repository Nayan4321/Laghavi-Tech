<?php
/**
 * A sub-domain (ar.example.com) or a separate domain (example.ae) per language.
 *
 * Every domain must point to this same WordPress site (an alias or parked domain at the host). The
 * language is read from the domain of each visit, links are written with each language's domain,
 * and pages opened on the wrong domain are sent to the right one. Files, the REST API and AJAX are
 * loaded from the domain being visited, so browsers never block them as coming from another site.
 *
 * @package VacanamPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Domains per language.
 */
class VACANAMPRO_Domains {

	/**
	 * Read languages from domains and write domains in links (always on once set up, so a site that
	 * uses domains keeps working while the license is checked).
	 */
	public static function init_routing() {
		add_filter( 'vacanam_url_modes', array( __CLASS__, 'modes' ) );
		add_filter( 'vacanam_detect_language', array( __CLASS__, 'detect' ), 10, 2 );
		add_filter( 'vacanam_language_of_url', array( __CLASS__, 'lang_of_url' ), 10, 3 );
		add_filter( 'vacanam_url_for_language', array( __CLASS__, 'url_for' ), 10, 4 );
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allowed_hosts' ) );
		foreach ( array( 'site_url', 'content_url', 'plugins_url', 'includes_url', 'rest_url', 'style_loader_src', 'script_loader_src' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'same_host' ), 99 );
		}
		add_filter( 'admin_url', array( __CLASS__, 'ajax_url' ), 99, 2 );
		add_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ), 99 );
		add_action( 'template_redirect', array( __CLASS__, 'canonical' ), 0 );
	}

	/**
	 * Settings (licensed).
	 */
	public static function init() {
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 10 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save' ) );
	}

	/**
	 * The address mode, if it is one of ours.
	 *
	 * @return string subdomain, domain or ''.
	 */
	public static function mode() {
		if ( ! class_exists( 'Vacanam_Settings' ) ) {
			return '';
		}
		$mode = (string) Vacanam_Settings::get( 'url_mode' );
		return in_array( $mode, array( 'subdomain', 'domain' ), true ) ? $mode : '';
	}

	/**
	 * Address modes offered in Languages > Settings.
	 *
	 * @param array $modes mode => label.
	 * @return array
	 */
	public static function modes( $modes ) {
		$host                = self::main_host();
		$modes['subdomain'] = sprintf( /* translators: %s: example address */ __( 'A sub-domain per language (%s) - Pro', 'vacanam-language-switcher-pro' ), 'ar.' . $host );
		$modes['domain']    = __( 'A separate domain per language (example.ae) - Pro: set the domains in Languages > Pro', 'vacanam-language-switcher-pro' );
		return $modes;
	}

	/**
	 * Host of the site as set in Settings > General.
	 *
	 * @return string
	 */
	public static function main_host() {
		return strtolower( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) );
	}

	/**
	 * Host of the main site without "www.", the base of sub-domains.
	 *
	 * @return string
	 */
	private static function base_host() {
		return preg_replace( '/^www\./', '', self::main_host() );
	}

	/**
	 * Host of a language.
	 *
	 * @param string $slug Language.
	 * @return string
	 */
	public static function host_for( $slug ) {
		$mode = self::mode();
		if ( ! class_exists( 'Vacanam_Languages' ) || ! Vacanam_Languages::exists( $slug ) ) {
			return self::main_host();
		}
		if ( $slug === Vacanam_Languages::default_slug() ) {
			$domains = (array) VACANAMPRO_Settings::get( 'domains' );
			return 'domain' === $mode && ! empty( $domains[ $slug ] ) ? preg_replace( '/:\d+$/', '', $domains[ $slug ] ) : self::main_host();
		}
		if ( 'subdomain' === $mode ) {
			return $slug . '.' . self::base_host();
		}
		if ( 'domain' === $mode ) {
			$domains = (array) VACANAMPRO_Settings::get( 'domains' );
			return ! empty( $domains[ $slug ] ) ? preg_replace( '/:\d+$/', '', $domains[ $slug ] ) : self::main_host();
		}
		return self::main_host();
	}

	/**
	 * Every host of the site: language => host.
	 *
	 * @return array
	 */
	public static function hosts() {
		$out = array();
		if ( '' === self::mode() || ! class_exists( 'Vacanam_Languages' ) ) {
			return $out;
		}
		foreach ( Vacanam_Languages::slugs() as $slug ) {
			$out[ $slug ] = preg_replace( '/:\d+$/', '', self::host_for( $slug ) );
		}
		return $out;
	}

	/**
	 * Language of a host ('' when it is none of ours, or the main host shared by several).
	 *
	 * @param string $host Host.
	 * @return string
	 */
	public static function lang_of_host( $host ) {
		$host = strtolower( preg_replace( '/:\d+$/', '', (string) $host ) );
		foreach ( self::hosts() as $slug => $h ) {
			if ( $h === $host && ( $slug === Vacanam_Languages::default_slug() || $h !== self::main_host() ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Host of this visit.
	 *
	 * @return string
	 */
	public static function current_host() {
		return isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) : self::main_host();
	}

	/**
	 * Language of the visit, from its domain.
	 *
	 * @param string $lang Language found by the free plugin.
	 * @param string $mode Address mode.
	 * @return string
	 */
	public static function detect( $lang, $mode ) {
		if ( ! in_array( $mode, array( 'subdomain', 'domain' ), true ) ) {
			return $lang;
		}
		$found = self::lang_of_host( self::current_host() );
		return '' !== $found ? $found : $lang;
	}

	/**
	 * Language of an address, from its domain.
	 *
	 * @param string $lang Language.
	 * @param string $url  Address.
	 * @param string $mode Address mode.
	 * @return string
	 */
	public static function lang_of_url( $lang, $url, $mode ) {
		if ( ! in_array( $mode, array( 'subdomain', 'domain' ), true ) ) {
			return $lang;
		}
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		return '' !== $host ? self::lang_of_host( $host ) : $lang;
	}

	/**
	 * An address in a language: the same path on that language's domain.
	 *
	 * @param string $out  Address.
	 * @param string $url  Address as given.
	 * @param string $slug Language.
	 * @param string $mode Address mode.
	 * @return string
	 */
	public static function url_for( $out, $url, $slug, $mode ) {
		if ( ! in_array( $mode, array( 'subdomain', 'domain' ), true ) ) {
			return $out;
		}
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( '' === $host || ! in_array( strtolower( $host ), array_merge( array( self::main_host() ), array_values( self::hosts() ) ), true ) ) {
			return $out; // Not an address of this site.
		}
		$to = self::host_for( $slug );
		return (string) preg_replace( '#^(https?:)?//' . preg_quote( $host, '#' ) . '(?=[:/?\#]|$)#i', '$1//' . $to, (string) $url, 1 );
	}

	/**
	 * Safe redirects may go to every language's domain.
	 *
	 * @param array $hosts Hosts.
	 * @return array
	 */
	public static function allowed_hosts( $hosts ) {
		return array_values( array_unique( array_merge( (array) $hosts, array_values( self::hosts() ) ) ) );
	}

	/**
	 * On a language's domain, the site's files and REST API come from that same domain.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function same_host( $url ) {
		if ( '' === self::mode() || is_admin() ) {
			return $url;
		}
		$here = self::current_host();
		if ( $here === self::main_host() || '' === self::lang_of_host( $here ) ) {
			return $url;
		}
		return (string) preg_replace( '#^(https?:)?//' . preg_quote( self::main_host(), '#' ) . '(?=[:/?\#]|$)#i', '$1//' . $here, (string) $url, 1 );
	}

	/**
	 * AJAX from a language's domain stays on that domain.
	 *
	 * @param string $url  Address.
	 * @param string $path Path.
	 * @return string
	 */
	public static function ajax_url( $url, $path ) {
		return 0 === strpos( ltrim( (string) $path, '/' ), 'admin-ajax.php' ) ? self::same_host( $url ) : $url;
	}

	/**
	 * Media addresses on the language's domain.
	 *
	 * @param array $dir Upload folder.
	 * @return array
	 */
	public static function upload_dir( $dir ) {
		if ( isset( $dir['url'], $dir['baseurl'] ) ) {
			$dir['url']     = self::same_host( $dir['url'] );
			$dir['baseurl'] = self::same_host( $dir['baseurl'] );
		}
		return $dir;
	}

	/**
	 * A page opened on the domain of another language goes to its own domain (one address per page).
	 */
	public static function canonical() {
		if ( '' === self::mode() || ! class_exists( 'Vacanam_Router' ) || ! Vacanam_Router::is_front() || ! Vacanam_Settings::on( 'redirect' ) ) {
			return;
		}
		if ( is_preview() || is_customize_preview() || is_404() || is_feed() || ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page builders' previews keep their address.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['vacanam_keep'] ) ) {
			return;
		}
		$object = get_queried_object();
		$lang   = '';
		if ( $object instanceof WP_Post && Vacanam_Model::is_translated_type( $object->post_type ) && ! ( is_front_page() && ! is_paged() ) ) {
			$lang = Vacanam_Model::post_lang( $object );
		} elseif ( $object instanceof WP_Term && Vacanam_Model::is_translated_tax( $object->taxonomy ) ) {
			$lang = Vacanam_Model::term_lang( $object );
		}
		if ( '' === $lang ) {
			return;
		}
		$host = self::current_host();
		$to   = self::host_for( $lang );
		if ( $host !== $to ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			wp_safe_redirect( ( is_ssl() ? 'https://' : 'http://' ) . $to . $uri, 301, 'Vacanam Pro' );
			exit;
		}
	}

	/**
	 * Settings section.
	 */
	public static function section() {
		$domains = (array) VACANAMPRO_Settings::get( 'domains' );
		$mode    = (string) Vacanam_Settings::get( 'url_mode' );
		?>
		<div class="vacanam-card">
			<h2><?php esc_html_e( 'Domains per language', 'vacanam-language-switcher-pro' ); ?></h2>
			<p class="description">
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: link to the settings */
						__( 'Choose "A sub-domain per language" or "A separate domain per language" in %s. Every domain or sub-domain must point to this same WordPress site at your host (an alias or parked domain, with its SSL certificate).', 'vacanam-language-switcher-pro' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=vacanam-settings' ) ) . '">' . esc_html__( 'Languages > Settings', 'vacanam-language-switcher-pro' ) . '</a>'
					)
				);
				?>
			</p>
			<table class="form-table" role="presentation">
				<?php foreach ( Vacanam_Languages::all() as $slug => $lang ) : ?>
					<tr><th scope="row"><?php echo esc_html( $lang['name'] ); ?></th><td>
						<input type="text" class="regular-text code" name="vacanampro_domains[<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( isset( $domains[ $slug ] ) ? $domains[ $slug ] : '' ); ?>" placeholder="<?php echo esc_attr( $slug === Vacanam_Languages::default_slug() ? self::main_host() : 'example.' . $slug ); ?>" />
						<?php if ( 'subdomain' === $mode ) : ?>
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: host */ __( 'Sub-domain mode uses %s.', 'vacanam-language-switcher-pro' ), self::host_for( $slug ) ) ); ?></p>
						<?php endif; ?>
					</td></tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}

	/**
	 * Save the domains.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save( $values ) {
		$domains = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in VACANAMPRO_Settings::save(); each value is sanitized below.
		$posted = isset( $_POST['vacanampro_domains'] ) ? (array) wp_unslash( $_POST['vacanampro_domains'] ) : array();
		foreach ( $posted as $slug => $host ) {
			$slug = sanitize_key( $slug );
			$host = strtolower( trim( sanitize_text_field( (string) $host ) ) );
			$host = preg_replace( '#^https?://#', '', $host );
			$host = preg_replace( '#[/?\#:].*$#', '', $host );
			if ( Vacanam_Languages::exists( $slug ) && preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
				$domains[ $slug ] = $host;
			}
		}
		$values['domains'] = $domains;
		return $values;
	}
}
