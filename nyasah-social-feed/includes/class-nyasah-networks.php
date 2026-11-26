<?php
/**
 * The social networks: names, colours, icons and the kinds of feed each one can show.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Networks.
 */
class NYASAH_Networks {

	/**
	 * All networks.
	 *
	 * Each source: label, input (what the site owner types), help, account (true when a connected account
	 * is needed, 'optional' when it only adds more).
	 *
	 * @return array
	 */
	public static function all() {
		$list = array(
			'youtube'   => array(
				'label'   => 'YouTube',
				'color'   => '#ff0000',
				'class'   => 'NYASAH_Source_YouTube',
				'sources' => array(
					'channel'  => array(
						'label'   => __( 'Channel', 'nyasah-social-feed' ),
						'input'   => __( 'Channel ID (UC…), @handle or channel address', 'nyasah-social-feed' ),
						'help'    => __( 'Without an API key the latest 15 videos are shown and only a channel ID (starting with UC) works. Add a free YouTube API key under Accounts for more videos, @handles and view counts.', 'nyasah-social-feed' ),
						'account' => 'optional',
					),
					'playlist' => array(
						'label'   => __( 'Playlist', 'nyasah-social-feed' ),
						'input'   => __( 'Playlist ID (PL…) or playlist address', 'nyasah-social-feed' ),
						'help'    => '',
						'account' => 'optional',
					),
					'urls'     => array(
						'label'   => __( 'Chosen videos', 'nyasah-social-feed' ),
						'input'   => __( 'Video addresses, one per line', 'nyasah-social-feed' ),
						'help'    => '',
						'account' => false,
					),
				),
			),
			'instagram' => array(
				'label'   => 'Instagram',
				'color'   => '#e1306c',
				'class'   => 'NYASAH_Source_Instagram',
				'sources' => array(
					'account' => array(
						'label'   => __( 'My account', 'nyasah-social-feed' ),
						'input'   => '',
						'help'    => __( 'Shows the posts of a connected Instagram Business or Creator account (Accounts › Instagram).', 'nyasah-social-feed' ),
						'account' => true,
					),
				),
			),
			'facebook'  => array(
				'label'   => 'Facebook',
				'color'   => '#1877f2',
				'class'   => 'NYASAH_Source_Facebook',
				'sources' => array(
					'page' => array(
						'label'   => __( 'Page', 'nyasah-social-feed' ),
						'input'   => '',
						'help'    => __( 'Shows the posts of a connected Facebook Page (Accounts › Facebook).', 'nyasah-social-feed' ),
						'account' => true,
					),
					'urls' => array(
						'label'   => __( 'Chosen posts (profiles and pages)', 'nyasah-social-feed' ),
						'input'   => __( 'Public post or video addresses, one per line', 'nyasah-social-feed' ),
						'help'    => __( 'Facebook does not let any app read a personal profile’s timeline. Paste the addresses of public posts from your profile or page and they are shown with Facebook’s own embedded post.', 'nyasah-social-feed' ),
						'account' => false,
					),
				),
			),
			'threads'   => array(
				'label'   => 'Threads',
				'color'   => '#101010',
				'class'   => 'NYASAH_Source_Threads',
				'sources' => array(
					'account' => array(
						'label'   => __( 'My profile', 'nyasah-social-feed' ),
						'input'   => '',
						'help'    => __( 'Shows the posts of a connected Threads profile (Accounts › Threads).', 'nyasah-social-feed' ),
						'account' => true,
					),
				),
			),
			'tiktok'    => array(
				'label'   => 'TikTok',
				'color'   => '#010101',
				'class'   => 'NYASAH_Source_TikTok',
				'sources' => array(
					'account' => array(
						'label'   => __( 'My account', 'nyasah-social-feed' ),
						'input'   => '',
						'help'    => __( 'Shows the videos of a connected TikTok account (Accounts › TikTok).', 'nyasah-social-feed' ),
						'account' => true,
					),
					'urls'    => array(
						'label'   => __( 'Chosen videos', 'nyasah-social-feed' ),
						'input'   => __( 'Video addresses, one per line', 'nyasah-social-feed' ),
						'help'    => __( 'No account needed: works with any public TikTok video.', 'nyasah-social-feed' ),
						'account' => false,
					),
				),
			),
			'twitter'   => array(
				'label'   => 'X (Twitter)',
				'color'   => '#000000',
				'class'   => 'NYASAH_Source_Twitter',
				'sources' => array(
					'user' => array(
						'label'   => __( 'Account', 'nyasah-social-feed' ),
						'input'   => __( 'Username (without @)', 'nyasah-social-feed' ),
						'help'    => __( 'Needs an X API bearer token (Accounts › X). Reading posts is part of X’s paid API plans.', 'nyasah-social-feed' ),
						'account' => true,
					),
					'urls' => array(
						'label'   => __( 'Chosen posts', 'nyasah-social-feed' ),
						'input'   => __( 'Post addresses, one per line', 'nyasah-social-feed' ),
						'help'    => __( 'No account needed: works with any public post.', 'nyasah-social-feed' ),
						'account' => false,
					),
				),
			),
			'pinterest' => array(
				'label'   => 'Pinterest',
				'color'   => '#e60023',
				'class'   => 'NYASAH_Source_Pinterest',
				'sources' => array(
					'user'  => array(
						'label'   => __( 'Profile', 'nyasah-social-feed' ),
						'input'   => __( 'Username', 'nyasah-social-feed' ),
						'help'    => __( 'No account needed: the latest public Pins.', 'nyasah-social-feed' ),
						'account' => false,
					),
					'board' => array(
						'label'   => __( 'Board', 'nyasah-social-feed' ),
						'input'   => __( 'Board address or username/board', 'nyasah-social-feed' ),
						'help'    => __( 'No account needed: the latest Pins of a public board.', 'nyasah-social-feed' ),
						'account' => false,
					),
				),
			),
			'linkedin'  => array(
				'label'   => 'LinkedIn',
				'color'   => '#0a66c2',
				'class'   => 'NYASAH_Source_LinkedIn',
				'sources' => array(
					'organization' => array(
						'label'   => __( 'Company page', 'nyasah-social-feed' ),
						'input'   => __( 'Organization ID (numbers)', 'nyasah-social-feed' ),
						'help'    => __( 'Needs a LinkedIn app with the Community Management API and a token of a page admin (Accounts › LinkedIn).', 'nyasah-social-feed' ),
						'account' => true,
					),
					'urls'         => array(
						'label'   => __( 'Chosen posts (profiles and pages)', 'nyasah-social-feed' ),
						'input'   => __( 'Public post addresses, one per line', 'nyasah-social-feed' ),
						'help'    => __( 'LinkedIn does not let apps read personal profiles. Paste public post addresses from your profile or page and they are shown with LinkedIn’s own embedded post.', 'nyasah-social-feed' ),
						'account' => false,
					),
				),
			),
			'snapchat'  => array(
				'label'   => 'Snapchat',
				'color'   => '#fffc00',
				'class'   => 'NYASAH_Source_Snapchat',
				'sources' => array(
					'urls' => array(
						'label'   => __( 'Chosen Spotlights and Stories', 'nyasah-social-feed' ),
						'input'   => __( 'Public Snapchat addresses, one per line', 'nyasah-social-feed' ),
						'help'    => __( 'Snapchat has no feed for apps. Paste public Spotlight, Story or profile addresses.', 'nyasah-social-feed' ),
						'account' => false,
					),
				),
			),
			'reddit'    => array(
				'label'   => 'Reddit',
				'color'   => '#ff4500',
				'class'   => 'NYASAH_Source_Reddit',
				'sources' => array(
					'user'      => array(
						'label'   => __( 'User’s posts', 'nyasah-social-feed' ),
						'input'   => __( 'Username (without u/)', 'nyasah-social-feed' ),
						'help'    => __( 'No account needed: the posts this user shared.', 'nyasah-social-feed' ),
						'account' => false,
					),
					'subreddit' => array(
						'label'   => __( 'Community', 'nyasah-social-feed' ),
						'input'   => __( 'Community name (without r/)', 'nyasah-social-feed' ),
						'help'    => __( 'No account needed: the newest posts of a public community.', 'nyasah-social-feed' ),
						'account' => false,
					),
				),
			),
		);
		/**
		 * Networks and the feeds they can show.
		 *
		 * @param array $list Networks.
		 */
		return (array) apply_filters( 'nyasah_networks', $list );
	}

	/**
	 * One network.
	 *
	 * @param string $key Network.
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * SVG icon of a network.
	 *
	 * @param string $key   Network.
	 * @param int    $size  Size in pixels.
	 * @return string
	 */
	public static function icon( $key, $size = 20 ) {
		$paths = array(
			'youtube'   => '<path fill="currentColor" d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 0 0 .5 6.2C0 8.1 0 12 0 12s0 3.9.5 5.8a3 3 0 0 0 2.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 0 0 2.1-2.1c.5-1.9.5-5.8.5-5.8s0-3.9-.5-5.8zM9.5 15.6V8.4l6.3 3.6-6.3 3.6z"/>',
			'instagram' => '<rect x="2.5" y="2.5" width="19" height="19" rx="5.5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4.3" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.6" cy="6.4" r="1.3" fill="currentColor"/>',
			'facebook'  => '<path fill="currentColor" d="M24 12a12 12 0 1 0-13.9 11.9v-8.4H7.1V12h3V9.4c0-3 1.8-4.7 4.5-4.7 1.3 0 2.7.2 2.7.2v2.9h-1.5c-1.5 0-2 .9-2 1.9V12h3.4l-.5 3.5h-2.9v8.4A12 12 0 0 0 24 12z"/>',
			'threads'   => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M17.5 8.7c-.9-2.5-3-3.7-5.6-3.7-3.8 0-6.4 2.7-6.4 7s2.6 7 6.4 7c3.4 0 5.9-2 5.9-4.9 0-2.6-2-4-4.8-4-2.4 0-3.9 1.2-3.9 2.9 0 1.5 1.2 2.6 3 2.6 2.4 0 3.6-1.8 3.6-5"/>',
			'tiktok'    => '<path fill="currentColor" d="M16.6 2h-3.4v13.3a2.9 2.9 0 1 1-2.9-2.9c.3 0 .6 0 .9.1V9a6.3 6.3 0 1 0 5.4 6.3V8.6a8 8 0 0 0 4.7 1.5V6.7a4.7 4.7 0 0 1-4.7-4.7z"/>',
			'twitter'   => '<path fill="currentColor" d="M17.8 2.3h3.4l-7.4 8.4 8.7 11h-6.8l-5.3-6.9-6.1 6.9H.9l7.9-9L.5 2.3h7l4.8 6.3 5.5-6.3zm-1.2 17.4h1.9L6.4 4.2H4.4l12.2 15.5z"/>',
			'pinterest' => '<path fill="currentColor" d="M12 0a12 12 0 0 0-4.4 23.2c-.1-.9-.2-2.4 0-3.4l1.4-6s-.4-.7-.4-1.8c0-1.7 1-2.9 2.2-2.9 1 0 1.5.8 1.5 1.7 0 1-.7 2.6-1 4-.3 1.2.6 2.2 1.8 2.2 2.1 0 3.8-2.3 3.8-5.5 0-2.9-2.1-4.9-5-4.9a5.2 5.2 0 0 0-5.4 5.2c0 1 .4 2.1.9 2.7.1.1.1.2.1.4l-.3 1.4c-.1.2-.2.3-.5.2-1.5-.7-2.4-2.9-2.4-4.6 0-3.8 2.7-7.2 7.9-7.2 4.1 0 7.3 2.9 7.3 6.9 0 4.1-2.6 7.4-6.2 7.4-1.2 0-2.4-.6-2.8-1.4l-.7 2.9c-.3 1-1 2.3-1.5 3.1A12 12 0 1 0 12 0z"/>',
			'linkedin'  => '<path fill="currentColor" d="M20.4 2H3.6C2.7 2 2 2.7 2 3.6v16.8c0 .9.7 1.6 1.6 1.6h16.8c.9 0 1.6-.7 1.6-1.6V3.6c0-.9-.7-1.6-1.6-1.6zM8 19H5V9.5h3V19zM6.5 8.2a1.7 1.7 0 1 1 0-3.5 1.7 1.7 0 0 1 0 3.5zM19 19h-3v-4.6c0-1.1 0-2.5-1.5-2.5S12.7 13 12.7 14.3V19h-3V9.5h2.8v1.3c.4-.8 1.4-1.5 2.9-1.5 3.1 0 3.6 2 3.6 4.6V19z"/>',
			'snapchat'  => '<path fill="currentColor" stroke="#111" stroke-width=".8" d="M12 2.2c2.9 0 5.2 2.1 5.4 5v2.6c.4.2 1-.1 1.5-.1.6 0 1 .4.9.9-.2.7-1.6 1-2.2 1.3-.3.2-.3.5-.2.9.7 1.7 2.1 3.2 3.9 3.6.4.1.4.5.1.7-.6.4-1.5.6-2.1.7-.2.3-.1.9-.5 1-.6.1-1.4-.2-2.2 0-.9.2-1.8 1.7-4.6 1.7s-3.6-1.5-4.6-1.7c-.8-.2-1.6.1-2.2 0-.4-.1-.3-.7-.5-1-.6-.1-1.5-.3-2.1-.7-.3-.2-.3-.6.1-.7 1.8-.4 3.2-1.9 3.9-3.6.1-.4.1-.7-.2-.9-.6-.3-2-.6-2.2-1.3-.1-.5.3-.9.9-.9.5 0 1.1.3 1.5.1V7.2c.2-2.9 2.5-5 5.4-5z"/>',
			'reddit'    => '<circle cx="12" cy="12" r="11" fill="currentColor"/><ellipse cx="12" cy="14" rx="6.3" ry="4.3" fill="#fff"/><circle cx="18.2" cy="10.6" r="1.6" fill="#fff"/><circle cx="5.8" cy="10.6" r="1.6" fill="#fff"/><circle cx="9.6" cy="13.4" r="1.1" fill="currentColor"/><circle cx="14.4" cy="13.4" r="1.1" fill="currentColor"/><path d="M9.8 15.9c1.3.9 3.1.9 4.4 0" fill="none" stroke="currentColor" stroke-width=".9" stroke-linecap="round"/><circle cx="16.4" cy="5.4" r="1.3" fill="#fff"/><path d="M12 9.7l1.1-4.6 3.3.4" fill="none" stroke="#fff" stroke-width=".9"/>',
		);
		if ( ! isset( $paths[ $key ] ) ) {
			return '';
		}
		return '<svg class="nyasah-icon nyasah-icon--' . esc_attr( $key ) . '" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[ $key ] . '</svg>';
	}

	/**
	 * Allowed tags for the icons (for wp_kses).
	 *
	 * @return array
	 */
	public static function svg_tags() {
		$common = array(
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		);
		return array(
			'svg'     => array(
				'class'       => true,
				'width'       => true,
				'height'      => true,
				'viewbox'     => true,
				'aria-hidden' => true,
				'focusable'   => true,
				'fill'        => true,
			),
			'path'    => array_merge( $common, array( 'd' => true ) ),
			'circle'  => array_merge( $common, array( 'cx' => true, 'cy' => true, 'r' => true ) ),
			'ellipse' => array_merge( $common, array( 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true ) ),
			'rect'    => array_merge( $common, array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true ) ),
		);
	}
}
