<?php
/**
 * Menu templates: how each looks and behaves, its options and its default colours.
 *
 * Two kinds: "bar" templates show the menu items in place (a horizontal or vertical menu);
 * "toggle" templates show a menu button that opens a panel (slide-down, overlay, dropdown, drawer).
 *
 * @package ManoharaMenuDesign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Templates.
 */
class Manohara_Templates {

	/**
	 * All templates.
	 *
	 * @return array key => template.
	 */
	public static function all() {
		static $list = null;
		if ( null !== $list ) {
			return $list;
		}
		$list = array(
			'classic'    => array(
				'label'   => __( 'Classic', 'manohara-menu-design' ),
				'kind'    => 'bar',
				'best'    => array( 'desktop', 'tablet' ),
				'desc'    => __( 'Items side by side with an animated underline and smooth dropdowns.', 'manohara-menu-design' ),
				'options' => array( 'align', 'open_on', 'hover_effect', 'highlight_last' ),
				'style'   => array(
					'panel_bg'   => '#ffffff',
					'text'       => '#1f2937',
					'text_hover' => '#2563eb',
					'accent'     => '#2563eb',
					'radius'     => 10,
					'item_gap'   => 12,
				),
			),
			'pills'      => array(
				'label'   => __( 'Pills', 'manohara-menu-design' ),
				'kind'    => 'bar',
				'best'    => array( 'desktop', 'tablet' ),
				'desc'    => __( 'Rounded buttons; the current page is highlighted and hover glides between items.', 'manohara-menu-design' ),
				'options' => array( 'align', 'open_on', 'highlight_last' ),
				'style'   => array(
					'panel_bg'    => '#ffffff',
					'text'        => '#1f2937',
					'text_hover'  => '#1d4ed8',
					'accent'      => '#1d4ed8',
					'accent_text' => '#ffffff',
					'bubble'      => '#e0e7ff',
					'radius'      => 999,
					'item_gap'    => 6,
				),
			),
			'vertical'   => array(
				'label'   => __( 'Vertical list', 'manohara-menu-design' ),
				'kind'    => 'bar',
				'best'    => array( 'desktop', 'tablet', 'mobile' ),
				'desc'    => __( 'A stacked menu for sidebars and footers, with arrows that open submenus.', 'manohara-menu-design' ),
				'options' => array( 'submenu_open' ),
				'style'   => array(
					'panel_bg'   => '#ffffff',
					'text'       => '#1f2937',
					'text_hover' => '#f05a28',
					'accent'     => '#f05a28',
					'divider'    => '#eeeeee',
					'radius'     => 10,
					'item_gap'   => 0,
				),
			),
			'slide-down' => array(
				'label'   => __( 'Slide-down panel', 'manohara-menu-design' ),
				'kind'    => 'toggle',
				'best'    => array( 'mobile', 'tablet' ),
				'desc'    => __( 'The menu slides down under the header: buttons on top, round arrows for submenus, extra links below.', 'manohara-menu-design' ),
				'options' => array( 'buttons', 'secondary', 'submenu', 'bar' ),
				'style'   => array(
					'bar_bg'         => '#ffffff',
					'bar_text'       => '#0b3540',
					'panel_bg'       => '#ffffff',
					'text'           => '#0b3540',
					'text_hover'     => '#e8930a',
					'accent'         => '#f9a40d',
					'accent_text'    => '#0b3540',
					'bubble'         => '#b5e2a8',
					'divider'        => '#eef0f2',
					'secondary_bg'   => '#f2f4f5',
					'secondary_text' => '#0b3540',
					'radius'         => 999,
					'item_gap'       => 4,
				),
			),
			'overlay'    => array(
				'label'   => __( 'Full-screen overlay', 'manohara-menu-design' ),
				'kind'    => 'toggle',
				'best'    => array( 'mobile', 'tablet' ),
				'desc'    => __( 'The menu fills the screen with a smooth fade; submenus slide in.', 'manohara-menu-design' ),
				'options' => array( 'buttons', 'secondary', 'submenu', 'bar' ),
				'style'   => array(
					'bar_bg'         => '#ffffff',
					'bar_text'       => '#1f6f66',
					'panel_bg'       => '#1f6f66',
					'text'           => '#ffffff',
					'text_hover'     => '#f6a623',
					'accent'         => '#f6a623',
					'accent_text'    => '#ffffff',
					'bubble'         => 'rgba(255,255,255,.12)',
					'divider'        => 'rgba(255,255,255,.14)',
					'secondary_bg'   => 'rgba(0,0,0,.12)',
					'secondary_text' => '#ffffff',
					'font_weight'    => 600,
					'radius'         => 999,
					'item_gap'       => 6,
				),
			),
			'dropdown'   => array(
				'label'   => __( 'Dropdown list', 'manohara-menu-design' ),
				'kind'    => 'toggle',
				'best'    => array( 'mobile', 'tablet' ),
				'desc'    => __( 'A centred list drops down under the header, with dividers and a highlighted last item.', 'manohara-menu-design' ),
				'options' => array( 'buttons', 'highlight_last', 'submenu_open', 'bar' ),
				'style'   => array(
					'bar_bg'      => '#ffffff',
					'bar_text'    => '#1f2937',
					'panel_bg'    => '#ffffff',
					'text'        => '#1f2937',
					'text_hover'  => '#0e7490',
					'accent'      => '#d5eff1',
					'accent_text' => '#0f172a',
					'divider'     => '#e5e7eb',
					'font_size'   => 15,
					'radius'      => 8,
					'item_gap'    => 0,
				),
			),
			'drawer'     => array(
				'label'   => __( 'Side drawer', 'manohara-menu-design' ),
				'kind'    => 'toggle',
				'best'    => array( 'mobile', 'tablet', 'desktop' ),
				'desc'    => __( 'A panel slides in from the side over a dimmed page.', 'manohara-menu-design' ),
				'options' => array( 'side', 'buttons', 'secondary', 'submenu', 'bar' ),
				'style'   => array(
					'bar_bg'         => '#ffffff',
					'bar_text'       => '#111827',
					'panel_bg'       => '#111827',
					'text'           => '#f9fafb',
					'text_hover'     => '#4ade80',
					'accent'         => '#22c55e',
					'accent_text'    => '#052e16',
					'bubble'         => 'rgba(255,255,255,.08)',
					'divider'        => 'rgba(255,255,255,.08)',
					'secondary_bg'   => 'rgba(255,255,255,.04)',
					'secondary_text' => '#d1d5db',
					'radius'         => 12,
					'item_gap'       => 2,
				),
			),
		);
		/**
		 * Menu templates (add-ons add their own).
		 *
		 * @param array $list key => template.
		 */
		$list = apply_filters( 'manohara_templates', $list );
		return $list;
	}

	/**
	 * One template.
	 *
	 * @param string $key Key.
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return is_string( $key ) && isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Is it a toggle (menu button) template?
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_toggle( $key ) {
		$t = self::get( $key );
		return $t && 'toggle' === $t['kind'];
	}

	/**
	 * Style of a template: defaults with the template's colours.
	 *
	 * @param string $key Key.
	 * @return array
	 */
	public static function style_for( $key ) {
		$t = self::get( $key );
		return array_merge( Manohara_Store::default_style(), $t ? $t['style'] : array() );
	}

	/**
	 * Option groups: key => fields (name => default).
	 *
	 * @return array
	 */
	public static function option_fields() {
		return array(
			'align'          => array( 'align' => 'end' ),
			'open_on'        => array( 'open_on' => 'hover' ),
			'hover_effect'   => array( 'hover_effect' => 'underline' ),
			'highlight_last' => array( 'highlight_last' => 0 ),
			'submenu'        => array( 'submenu' => 'accordion' ),
			'submenu_open'   => array( 'submenu_open' => 0 ),
			'side'           => array( 'side' => 'right' ),
			'secondary'      => array(),
			'buttons'        => array(
				'btn1_text' => '',
				'btn1_url'  => '',
				'btn2_text' => '',
				'btn2_url'  => '',
			),
			'bar'            => array(
				'logo'     => 'site',
				'logo_url' => '',
				'search'   => 0,
				'sticky'   => 1,
				'shadow'   => 1,
			),
		);
	}

	/**
	 * Default options of a template.
	 *
	 * @param string $key Key.
	 * @return array
	 */
	public static function default_opts( $key ) {
		$t   = self::get( $key );
		$out = array();
		if ( ! $t ) {
			return $out;
		}
		$fields = self::option_fields();
		foreach ( $t['options'] as $group ) {
			if ( isset( $fields[ $group ] ) ) {
				$out = array_merge( $out, $fields[ $group ] );
			}
		}
		if ( 'dropdown' === $key ) {
			$out['highlight_last'] = 1;
		}
		if ( 'overlay' === $key ) {
			$out['submenu'] = 'drilldown';
		}
		return $out;
	}

	/**
	 * Does a template have an option group?
	 *
	 * @param string $key   Template.
	 * @param string $group Option group.
	 * @return bool
	 */
	public static function has( $key, $group ) {
		$t = self::get( $key );
		return $t && in_array( $group, $t['options'], true );
	}

	/**
	 * Small picture of a template for the template picker.
	 *
	 * @param string $key Key.
	 * @return string SVG.
	 */
	public static function thumb( $key ) {
		$p = '#7c3aed';
		$g = '#d1d5db';
		switch ( $key ) {
			case 'classic':
				$body = '<rect x="6" y="10" width="20" height="6" rx="2" fill="' . $g . '"/><rect x="40" y="11" width="14" height="4" rx="2" fill="#6b7280"/><rect x="58" y="11" width="14" height="4" rx="2" fill="#6b7280"/><rect x="76" y="11" width="14" height="4" rx="2" fill="#6b7280"/><rect x="40" y="18" width="14" height="2" rx="1" fill="' . $p . '"/><rect x="56" y="24" width="30" height="26" rx="4" fill="#fff" stroke="' . $g . '"/><rect x="60" y="29" width="20" height="3" rx="1.5" fill="' . $g . '"/><rect x="60" y="36" width="16" height="3" rx="1.5" fill="' . $g . '"/><rect x="60" y="43" width="18" height="3" rx="1.5" fill="' . $g . '"/>';
				break;
			case 'pills':
				$body = '<rect x="6" y="10" width="20" height="6" rx="2" fill="' . $g . '"/><rect x="36" y="8" width="18" height="10" rx="5" fill="' . $p . '"/><rect x="58" y="11" width="12" height="4" rx="2" fill="#6b7280"/><rect x="74" y="11" width="12" height="4" rx="2" fill="#6b7280"/><rect x="6" y="28" width="88" height="26" rx="4" fill="#f3f4f6"/>';
				break;
			case 'vertical':
				$body = '<rect x="10" y="6" width="44" height="48" rx="5" fill="#fff" stroke="' . $g . '"/><rect x="16" y="12" width="26" height="4" rx="2" fill="' . $p . '"/><rect x="16" y="22" width="30" height="4" rx="2" fill="#6b7280"/><rect x="16" y="32" width="24" height="4" rx="2" fill="#6b7280"/><rect x="16" y="42" width="28" height="4" rx="2" fill="#6b7280"/><path d="M47 13l2 1.5-2 1.5" stroke="#6b7280" fill="none"/><rect x="60" y="6" width="34" height="48" rx="4" fill="#f3f4f6"/>';
				break;
			case 'overlay':
				$body = '<rect x="30" y="2" width="40" height="56" rx="6" fill="#1f6f66"/><path d="M60 8l5 5M65 8l-5 5" stroke="#fff" stroke-width="1.6"/><rect x="36" y="18" width="18" height="3" rx="1.5" fill="#fff"/><rect x="36" y="26" width="22" height="3" rx="1.5" fill="#fff"/><rect x="36" y="34" width="16" height="3" rx="1.5" fill="#fff"/><rect x="36" y="42" width="20" height="3" rx="1.5" fill="#fff"/><path d="M62 26l2 1.5-2 1.5M62 34l2 1.5-2 1.5" stroke="#f6a623" fill="none"/>';
				break;
			case 'dropdown':
				$body = '<rect x="30" y="2" width="40" height="56" rx="6" fill="#f3f4f6"/><rect x="30" y="2" width="40" height="10" rx="6" fill="#fff"/><rect x="33" y="12" width="34" height="34" fill="#fff"/><rect x="42" y="16" width="16" height="3" rx="1.5" fill="#6b7280"/><rect x="40" y="23" width="20" height="3" rx="1.5" fill="#6b7280"/><rect x="43" y="30" width="14" height="3" rx="1.5" fill="#6b7280"/><rect x="33" y="36" width="34" height="9" fill="#d5eff1"/><rect x="40" y="39" width="20" height="3" rx="1.5" fill="#0e7490"/>';
				break;
			case 'drawer':
				$body = '<rect x="30" y="2" width="40" height="56" rx="6" fill="#9ca3af"/><rect x="44" y="2" width="26" height="56" rx="0" fill="#111827"/><rect x="48" y="12" width="16" height="3" rx="1.5" fill="#fff"/><rect x="48" y="20" width="18" height="3" rx="1.5" fill="#fff"/><rect x="48" y="28" width="14" height="3" rx="1.5" fill="#fff"/><rect x="48" y="46" width="18" height="6" rx="3" fill="#22c55e"/>';
				break;
			default: // slide-down.
				$body = '<rect x="30" y="2" width="40" height="56" rx="6" fill="#fff" stroke="' . $g . '"/><rect x="34" y="6" width="12" height="4" rx="2" fill="#0b3540"/><rect x="34" y="14" width="32" height="5" rx="2.5" fill="none" stroke="#f9a40d"/><rect x="34" y="21" width="32" height="5" rx="2.5" fill="#f9a40d"/><rect x="34" y="30" width="20" height="3" rx="1.5" fill="#0b3540"/><circle cx="64" cy="31.5" r="2.4" fill="#b5e2a8"/><rect x="34" y="37" width="18" height="3" rx="1.5" fill="#0b3540"/><circle cx="64" cy="38.5" r="2.4" fill="#b5e2a8"/><rect x="30" y="45" width="40" height="13" fill="#f2f4f5"/><rect x="34" y="48" width="12" height="2" rx="1" fill="#0b3540"/><rect x="34" y="52" width="10" height="2" rx="1" fill="#0b3540"/>';
		}
		return '<svg viewBox="0 0 100 60" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">' . $body . '</svg>';
	}
}
