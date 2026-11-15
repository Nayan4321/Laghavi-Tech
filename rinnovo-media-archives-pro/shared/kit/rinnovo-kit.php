<?php
/**
 * Rinnovo kit: parts shared by every Rinnovo Pro plugin.
 *
 * - rinnovo_insights( $section, $args ): read-only analytics for dashboards (and GET /wp-json/rinnovo/v1/insights).
 * - "Works great with" panel on each Pro's settings screen.
 * - Page builder widgets (Elementor and block editor) for each Pro's shortcodes.
 *
 * Each Pro carries a copy of this folder; when several Pros are active only the newest copy is loaded.
 * Source of truth: rinnovo-shared/kit in the repository; copied into each Pro by sync.sh.
 *
 * @package RinnovoKit
 */

defined( 'ABSPATH' ) || exit;

// This copy's version: raise it whenever anything in this folder changes.
$rinnovo_kit_copy = '1.0.1';

if ( ! isset( $GLOBALS['rinnovo_kit_copies'] ) || ! is_array( $GLOBALS['rinnovo_kit_copies'] ) ) {
	$GLOBALS['rinnovo_kit_copies'] = array();
}
$GLOBALS['rinnovo_kit_copies'][ __DIR__ ] = $rinnovo_kit_copy;
unset( $rinnovo_kit_copy );

if ( ! function_exists( 'rinnovo_kit_pro' ) ) {

	/**
	 * A licensed Rinnovo Pro registers itself (call on rinnovo_loaded, before priority 99).
	 *
	 * @param array $args slug, name, pages (admin page slugs of its settings screens), widgets
	 *                    (shortcode => [title, description, icon, attrs => [name => label]]).
	 */
	function rinnovo_kit_pro( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'slug'    => '',
				'name'    => '',
				'pages'   => array(),
				'widgets' => array(),
			)
		);
		if ( '' !== $args['slug'] ) {
			$GLOBALS['rinnovo_kit_pros'][ $args['slug'] ] = $args;
		}
	}

	/**
	 * Load the newest copy once.
	 */
	function rinnovo_kit_boot() {
		if ( empty( $GLOBALS['rinnovo_kit_pros'] ) || class_exists( 'RINNOVO_Kit_Insights', false ) ) {
			return;
		}
		$copies = (array) $GLOBALS['rinnovo_kit_copies'];
		uasort( $copies, 'version_compare' );
		end( $copies );
		$dir = trailingslashit( (string) key( $copies ) );
		define( 'RINNOVO_KIT_VERSION', (string) current( $copies ) );
		define( 'RINNOVO_KIT_DIR', $dir );
		define( 'RINNOVO_KIT_URL', plugin_dir_url( $dir . 'rinnovo-kit.php' ) );
		require_once $dir . 'includes/class-rinnovo-kit-insights.php';
		require_once $dir . 'includes/class-rinnovo-kit-sections.php';
		require_once $dir . 'includes/class-rinnovo-kit-panel.php';
		require_once $dir . 'includes/class-rinnovo-kit-widgets.php';
		RINNOVO_Kit_Insights::init();
		RINNOVO_Kit_Sections::init();
		RINNOVO_Kit_Panel::init();
		RINNOVO_Kit_Widgets::init();
		/**
		 * The shared Rinnovo kit is ready.
		 */
		do_action( 'rinnovo_kit_loaded' );
	}
	add_action( 'rinnovo_loaded', 'rinnovo_kit_boot', 99 );

	/**
	 * Read-only analytics.
	 *
	 * Sections: sections (what is available), subscriptions, licenses_pro, boxes, membership, media, contracts,
	 * sessions, bundles, actions (things to follow up). Each returns
	 * [ 'section', 'from', 'to', 'currency', 'totals' => [...], 'daily' => [ 'Y-m-d' => [...] ], 'breakdown' => [...] ].
	 *
	 * @param string $section Section.
	 * @param array  $args    from, to (Y-m-d; default the last 30 days), fresh (skip the cache).
	 * @return array|WP_Error
	 */
	function rinnovo_insights( $section, $args = array() ) {
		if ( ! class_exists( 'RINNOVO_Kit_Insights', false ) ) {
			return new WP_Error( 'rinnovo_insights', 'Rinnovo insights are not available.' );
		}
		return RINNOVO_Kit_Insights::get( $section, $args );
	}
}
