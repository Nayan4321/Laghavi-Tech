<?php
/**
 * Icons for menu items: a built-in line icon set (drawn here, nothing loaded from outside), WordPress
 * Dashicons, Font Awesome classes (when the theme or Elementor loads Font Awesome) and media images.
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Icons.
 */
class MNHP_Icons {

	/**
	 * Whether Dashicons are needed on this page.
	 *
	 * @var bool
	 */
	public static $dashicons = false;

	/**
	 * Built-in line icons: name => SVG paths (24×24, stroke).
	 *
	 * @return array
	 */
	public static function set() {
		return array(
			'home'      => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>',
			'grid'      => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
			'star'      => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
			'heart'     => '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>',
			'bolt'      => '<path d="M13 3L5 14h6l-1 7 8-11h-6z"/>',
			'rocket'    => '<path d="M14 4c3 0 6 3 6 6l-6 6-6-6c0-3 3-6 6-6z"/><path d="M8 10l-4 1 2 3M14 16l-1 4-3-2"/><circle cx="14" cy="10" r="1.5"/>',
			'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
			'pie'       => '<path d="M12 3v9h9a9 9 0 1 1-9-9z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15z"/>',
			'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/>',
			'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
			'cart'      => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 11.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.5L21 7H6"/>',
			'bag'       => '<path d="M5 8h14l-1 13H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
			'tag'       => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
			'gift'      => '<rect x="3" y="8" width="18" height="5" rx="1"/><path d="M5 13v8h14v-8M12 8v13M12 8S10 3 7.5 4.5 9 8 12 8zm0 0s2-5 4.5-3.5S15 8 12 8z"/>',
			'phone'     => '<path d="M5 4h3.5l1.5 4.5-2.2 1.4a11 11 0 0 0 4.3 4.3l1.4-2.2 4.5 1.5V17a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
			'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
			'chat'      => '<path d="M4 5h16v11H9l-5 4z"/>',
			'help'      => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7M12 17h.01"/>',
			'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5h.01"/>',
			'book'      => '<path d="M4 4h6a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H4zM20 4h-6a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h6z"/>',
			'cap'       => '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c3 2 9 2 12 0v-5"/>',
			'doc'       => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
			'folder'    => '<path d="M3 6h6l2 2h10v11H3z"/>',
			'code'      => '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M14 5l-4 14"/>',
			'terminal'  => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9l3 3-3 3M12 15h5"/>',
			'gear'      => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1L7 17M17 7l2.1-2.1"/>',
			'shield'    => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
			'lock'      => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
			'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
			'map'       => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
			'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
			'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'camera'    => '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
			'image'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 17l-5-5-9 8"/>',
			'video'     => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3"/>',
			'music'     => '<path d="M9 18V5l11-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="17" cy="16" r="3"/>',
			'cloud'     => '<path d="M7 18a4 4 0 0 1-.5-8A6 6 0 0 1 18 9a4.5 4.5 0 0 1-1 9z"/>',
			'layers'    => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
			'palette'   => '<path d="M12 3a9 9 0 0 0 0 18c1.5 0 2-1 2-2s-1-1.5-1-2.5 1-1.5 2-1.5h2a4 4 0 0 0 4-4c0-4.4-4-8-9-8z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="15" cy="7.5" r="1"/>',
			'cursor'    => '<path d="M5 3l14 7-6 2-2 6z"/>',
			'search'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
			'link'      => '<path d="M10 14a4 4 0 0 0 6 0l3-3a4 4 0 0 0-6-6l-1 1M14 10a4 4 0 0 0-6 0l-3 3a4 4 0 0 0 6 6l1-1"/>',
			'external'  => '<path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6"/>',
			'download'  => '<path d="M12 4v11M7 10l5 5 5-5M4 20h16"/>',
			'arrow'     => '<path d="M5 12h14M13 6l6 6-6 6"/>',
			'check'     => '<path d="M5 12l5 5 9-10"/>',
			'fire'      => '<path d="M12 3c1 4 5 5.5 5 10a5 5 0 0 1-10 0c0-2 1-3.5 2-4.5 0 2 1 3 2 3 0-3-1-5.5 1-8.5z"/>',
			'sparkle'   => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
			'truck'     => '<path d="M2 6h12v10H2zM14 10h4l4 4v2h-8"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
			'wallet'    => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18M16 15h2"/>',
			'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>',
			'building'  => '<path d="M4 21V4h11v17M15 9h5v12M8 8h3M8 12h3M8 16h3"/>',
			'eye'       => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
			'pencil'    => '<path d="M4 20l4-1 11-11-3-3L5 16z"/>',
		);
	}

	/**
	 * Icon HTML from a stored value: "svg:name", "dashicons-…", Font Awesome classes or "img:ID".
	 *
	 * @param string $value Value.
	 * @param string $class Extra class.
	 * @return string
	 */
	public static function html( $value, $class = '' ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$cls = 'mnhp-icon' . ( '' !== $class ? ' ' . $class : '' );
		if ( 0 === strpos( $value, 'svg:' ) ) {
			$set  = self::set();
			$name = substr( $value, 4 );
			return isset( $set[ $name ] ) ? '<svg class="' . esc_attr( $cls ) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $set[ $name ] . '</svg>' : '';
		}
		if ( 0 === strpos( $value, 'img:' ) ) {
			$img = wp_get_attachment_image( (int) substr( $value, 4 ), 'thumbnail', false, array( 'class' => $cls . ' mnhp-icon-img', 'alt' => '', 'loading' => 'lazy' ) );
			return (string) $img;
		}
		if ( 0 === strpos( $value, 'dashicons-' ) ) {
			self::$dashicons = true;
			return '<span class="' . esc_attr( $cls . ' dashicons ' . sanitize_html_class( $value ) ) . '" aria-hidden="true"></span>';
		}
		// Font Awesome and other icon fonts: classes only, the font comes from the theme or Elementor.
		$classes = implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', $value ) ) );
		return '<i class="' . esc_attr( $cls . ' ' . $classes ) . '" aria-hidden="true"></i>';
	}
}
