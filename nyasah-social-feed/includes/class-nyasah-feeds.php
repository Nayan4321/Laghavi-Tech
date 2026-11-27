<?php
/**
 * Feeds: a private post type; the settings of each feed live in one meta field.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Feeds.
 */
class NYASAH_Feeds {

	const TYPE = 'nyasah_feed';
	const META = '_nyasah_settings';

	/**
	 * Register the post type.
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'label'           => __( 'Social feeds', 'nyasah-social-feed' ),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Default settings of a feed.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'network'        => 'youtube',
			'source'         => 'channel',
			'value'          => '',
			'account'        => '',
			'count'          => 9,
			'layout'         => 'grid',
			'cols'           => 3,
			'cols_tablet'    => 2,
			'cols_mobile'    => 1,
			'gap'            => 14,
			'ratio'          => 'auto',
			'card'           => 'card',
			'show_caption'   => 1,
			'caption_length' => 120,
			'show_date'      => 1,
			'show_stats'     => 1,
			'show_author'    => 0,
			'show_icon'      => 1,
			'show_header'    => 1,
			'show_avatar'    => 1,
			'show_bio'       => 0,
			'show_follow'    => 1,
			'follow_text'    => '',
			'load_more'      => 1,
			'load_more_text' => '',
			'click'          => 'popup',
			'bg'             => '',
			'text_color'     => '',
			'accent'         => '',
			'radius'         => 12,
			'cache'          => 60,
		);
	}

	/**
	 * Settings of a feed, completed with the defaults.
	 *
	 * @param int $id Feed ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		$saved            = get_post_meta( $post->ID, self::META, true );
		$settings         = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		$settings['id']   = (int) $post->ID;
		$settings['name'] = $post->post_title;
		/**
		 * Settings of a feed (for example to change texts per language).
		 *
		 * @param array $settings Settings.
		 * @param int   $id       Feed ID.
		 */
		return (array) apply_filters( 'nyasah_feed_settings', $settings, $post->ID );
	}

	/**
	 * Clean settings sent from the editor.
	 *
	 * @param array $in Raw values (already unslashed).
	 * @return array
	 */
	public static function sanitize( $in ) {
		$d   = self::defaults();
		$out = array();
		$in  = is_array( $in ) ? $in : array();

		$network        = isset( $in['network'] ) ? sanitize_key( $in['network'] ) : $d['network'];
		$net            = NYASAH_Networks::get( $network );
		$out['network'] = $net ? $network : $d['network'];
		$net            = NYASAH_Networks::get( $out['network'] );
		$source         = isset( $in['source'] ) ? sanitize_key( $in['source'] ) : '';
		$out['source']  = isset( $net['sources'][ $source ] ) ? $source : (string) key( $net['sources'] );
		$out['value']   = isset( $in['value'] ) ? sanitize_textarea_field( $in['value'] ) : '';
		$out['account'] = isset( $in['account'] ) ? sanitize_key( $in['account'] ) : '';

		$ints = array(
			'count'          => array( 1, 50 ),
			'cols'           => array( 1, 8 ),
			'cols_tablet'    => array( 1, 6 ),
			'cols_mobile'    => array( 1, 4 ),
			'gap'            => array( 0, 60 ),
			'caption_length' => array( 0, 2000 ),
			'radius'         => array( 0, 40 ),
			'cache'          => array( 15, 1440 ),
		);
		foreach ( $ints as $key => $range ) {
			$value       = isset( $in[ $key ] ) ? (int) $in[ $key ] : $d[ $key ];
			$out[ $key ] = max( $range[0], min( $range[1], $value ) );
		}
		$choices = array(
			'layout' => self::layouts(),
			'ratio'  => array_keys( self::ratios() ),
			'card'   => array( 'card', 'overlay' ),
			'click'  => array( 'popup', 'link' ),
		);
		foreach ( $choices as $key => $allowed ) {
			$value       = isset( $in[ $key ] ) ? sanitize_key( $in[ $key ] ) : '';
			$out[ $key ] = in_array( $value, $allowed, true ) ? $value : $d[ $key ];
		}
		foreach ( array( 'show_caption', 'show_date', 'show_stats', 'show_author', 'show_icon', 'show_header', 'show_avatar', 'show_bio', 'show_follow', 'load_more' ) as $key ) {
			$out[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
		}
		foreach ( array( 'follow_text', 'load_more_text' ) as $key ) {
			$out[ $key ] = isset( $in[ $key ] ) ? sanitize_text_field( $in[ $key ] ) : '';
		}
		foreach ( array( 'bg', 'text_color', 'accent' ) as $key ) {
			$color       = isset( $in[ $key ] ) ? sanitize_hex_color( $in[ $key ] ) : '';
			$out[ $key ] = $color ? $color : '';
		}
		return $out;
	}

	/**
	 * Save a feed.
	 *
	 * @param int    $id       Feed ID (0 to create).
	 * @param string $name     Name.
	 * @param array  $settings Clean settings.
	 * @return int|WP_Error
	 */
	public static function save( $id, $name, $settings ) {
		$data = array(
			'post_type'   => self::TYPE,
			'post_status' => 'publish',
			'post_title'  => '' !== $name ? $name : __( 'Social feed', 'nyasah-social-feed' ),
		);
		if ( $id ) {
			$data['ID'] = (int) $id;
			$result     = wp_update_post( $data, true );
		} else {
			$result = wp_insert_post( $data, true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( (int) $result, self::META, $settings );
		NYASAH_Cache::forget( (int) $result );
		return (int) $result;
	}

	/**
	 * All feeds, for lists and pickers.
	 *
	 * @return WP_Post[]
	 */
	public static function list_all() {
		return get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Layouts that can be used. The free plugin has the grid; Nyasah Pro adds masonry, list and carousel.
	 *
	 * @return string[]
	 */
	public static function layouts() {
		/**
		 * Layouts that can be chosen.
		 *
		 * @param string[] $layouts Layout keys.
		 */
		$layouts = (array) apply_filters( 'nyasah_layouts', array( 'grid' ) );
		return in_array( 'grid', $layouts, true ) ? $layouts : array_merge( array( 'grid' ), $layouts );
	}

	/**
	 * Picture shapes, key => CSS aspect ratio ("auto" picks the usual shape of each network).
	 *
	 * @return array<string, string>
	 */
	public static function ratios() {
		return array(
			'auto'      => '',
			'square'    => '1 / 1',
			'portrait'  => '4 / 5',
			'tall'      => '3 / 4',
			'pin'       => '2 / 3',
			'story'     => '9 / 16',
			'landscape' => '16 / 9',
			'classic'   => '4 / 3',
			'original'  => 'auto',
		);
	}

	/**
	 * The usual picture shape of a network's posts.
	 *
	 * @param string $network Network.
	 * @return string CSS aspect ratio.
	 */
	public static function network_ratio( $network ) {
		$map = array(
			'youtube'   => '16 / 9',
			'twitter'   => '16 / 9',
			'linkedin'  => '16 / 9',
			'tiktok'    => '9 / 16',
			'snapchat'  => '9 / 16',
			'instagram' => '4 / 5',
			'pinterest' => '2 / 3',
			'reddit'    => '4 / 3',
			'facebook'  => '1 / 1',
			'threads'   => '1 / 1',
		);
		/**
		 * Picture shape used by "Automatic" for a network.
		 *
		 * @param string $ratio   CSS aspect ratio.
		 * @param string $network Network.
		 */
		return (string) apply_filters( 'nyasah_network_ratio', isset( $map[ $network ] ) ? $map[ $network ] : '1 / 1', $network );
	}
}
