<?php
/**
 * Ways to place a feed: block, Elementor widget, classic widget (the shortcode is in NYASAH_Front).
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integrations.
 */
class NYASAH_Integrations {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'block' ) );
		add_action( 'widgets_init', array( __CLASS__, 'widget' ) );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'elementor' ) );
		// The editor shows blocks in a frame: it needs the feed styles too.
		add_action(
			'enqueue_block_assets',
			static function () {
				if ( is_admin() ) {
					NYASAH_Front::register();
					wp_enqueue_style( 'nyasah' );
					wp_enqueue_script( 'nyasah' );
				}
			}
		);
	}

	/**
	 * Feeds for pickers: id => name.
	 *
	 * @return array
	 */
	public static function choices() {
		$out = array();
		foreach ( NYASAH_Feeds::list_all() as $post ) {
			$out[ $post->ID ] = '' !== $post->post_title ? $post->post_title : '#' . $post->ID;
		}
		return $out;
	}

	/**
	 * Block.
	 */
	public static function block() {
		wp_register_script( 'nyasah-block', NYASAH_URL . 'blocks/feed/index.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), nyasah_asset_version( 'blocks/feed/index.js' ), true );
		wp_set_script_translations( 'nyasah-block', 'nyasah-social-feed' );
		register_block_type(
			NYASAH_DIR . 'blocks/feed',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
		add_action(
			'enqueue_block_editor_assets',
			static function () {
				$feeds = array();
				foreach ( self::choices() as $id => $name ) {
					$feeds[] = array(
						'value' => (string) $id,
						'label' => $name,
					);
				}
				wp_localize_script(
					'nyasah-block',
					'nyasahBlock',
					array(
						'feeds' => $feeds,
						'add'   => admin_url( 'admin.php?page=nyasah-feed' ),
					)
				);
				NYASAH_Front::assets();
			}
		);
	}

	/**
	 * Block on the page.
	 *
	 * @param array $attributes Attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		$id = isset( $attributes['feed'] ) ? (int) $attributes['feed'] : 0;
		if ( ! $id ) {
			return '';
		}
		$html = NYASAH_Front::by_id( $id );
		return '' !== $html ? '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>' : '';
	}

	/**
	 * Classic widget.
	 */
	public static function widget() {
		require_once NYASAH_DIR . 'includes/class-nyasah-widget.php';
		register_widget( 'NYASAH_Widget' );
	}

	/**
	 * Elementor widget.
	 *
	 * @param object $manager Widgets manager.
	 */
	public static function elementor( $manager ) {
		require_once NYASAH_DIR . 'includes/class-nyasah-elementor.php';
		$manager->register( new NYASAH_Elementor_Widget() );
	}
}
