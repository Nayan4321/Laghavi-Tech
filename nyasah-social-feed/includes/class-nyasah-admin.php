<?php
/**
 * Admin screens: feeds list, feed editor with live preview, connected accounts with guides.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class NYASAH_Admin {

	const SLUG = 'nyasah-feeds';
	const CAP  = 'manage_options';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_nyasah_save_feed', array( __CLASS__, 'save_feed' ) );
		add_action( 'admin_post_nyasah_delete_feed', array( __CLASS__, 'delete_feed' ) );
		add_action( 'admin_post_nyasah_save_account', array( __CLASS__, 'save_account' ) );
		add_action( 'admin_post_nyasah_delete_account', array( __CLASS__, 'delete_account' ) );
		add_action( 'admin_post_nyasah_clear_cache', array( __CLASS__, 'clear_cache' ) );
		add_action( 'wp_ajax_nyasah_preview', array( __CLASS__, 'preview' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NYASAH_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_menu_page( __( 'Social Feeds', 'nyasah-social-feed' ), __( 'Social Feeds', 'nyasah-social-feed' ), self::CAP, self::SLUG, array( __CLASS__, 'page_list' ), 'dashicons-share', 58 );
		add_submenu_page( self::SLUG, __( 'All feeds', 'nyasah-social-feed' ), __( 'All feeds', 'nyasah-social-feed' ), self::CAP, self::SLUG, array( __CLASS__, 'page_list' ) );
		add_submenu_page( self::SLUG, __( 'Add feed', 'nyasah-social-feed' ), __( 'Add feed', 'nyasah-social-feed' ), self::CAP, 'nyasah-feed', array( __CLASS__, 'page_edit' ) );
		add_submenu_page( self::SLUG, __( 'Accounts', 'nyasah-social-feed' ), __( 'Accounts', 'nyasah-social-feed' ), self::CAP, 'nyasah-accounts', array( __CLASS__, 'page_accounts' ) );
	}

	/**
	 * "Feeds" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Feeds', 'nyasah-social-feed' ) . '</a>' );
		return $links;
	}

	/**
	 * Styles and scripts on our screens.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'nyasah' ) ) {
			return;
		}
		wp_enqueue_style( 'nyasah-admin', NYASAH_URL . 'assets/css/admin.css', array(), nyasah_asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'nyasah-admin', NYASAH_URL . 'assets/js/admin.js', array(), nyasah_asset_version( 'assets/js/admin.js' ), true );
		$networks = array();
		foreach ( NYASAH_Networks::all() as $key => $net ) {
			$networks[ $key ] = array(
				'label'   => $net['label'],
				'sources' => $net['sources'],
			);
		}
		wp_localize_script(
			'nyasah-admin',
			'nyasahAdmin',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'nyasah_preview' ),
				'networks' => $networks,
				'copied'   => __( 'Copied', 'nyasah-social-feed' ),
				'loading'  => __( 'Loading preview…', 'nyasah-social-feed' ),
			)
		);
		if ( false !== strpos( (string) $hook, 'nyasah-feed' ) ) {
			NYASAH_Front::assets();
		}
	}

	/**
	 * Check permission and nonce of a form.
	 *
	 * @param string $action Nonce action.
	 */
	private static function check( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nyasah-social-feed' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Message after a redirect.
	 */
	private static function notice() {
		if ( ! isset( $_GET['nyasah_msg'], $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'nyasah_msg' ) ) {
			return;
		}
		$messages = array(
			'saved'         => __( 'Feed saved.', 'nyasah-social-feed' ),
			'deleted'       => __( 'Feed deleted.', 'nyasah-social-feed' ),
			'account'       => __( 'Account saved.', 'nyasah-social-feed' ),
			'account_gone'  => __( 'Account removed.', 'nyasah-social-feed' ),
			'cache'         => __( 'All feeds will be fetched again.', 'nyasah-social-feed' ),
			'account_error' => __( 'The network did not accept this token. Check it and try again.', 'nyasah-social-feed' ),
		);
		$key      = sanitize_key( wp_unslash( $_GET['nyasah_msg'] ) );
		if ( isset( $messages[ $key ] ) ) {
			$class = 'account_error' === $key ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}
	}

	/**
	 * Back to a screen with a message.
	 *
	 * @param string $page Page.
	 * @param string $msg  Message.
	 * @param array  $args More arguments.
	 */
	private static function back( $page, $msg, $args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page'       => $page,
					'nyasah_msg' => $msg,
					'_wpnonce'   => wp_create_nonce( 'nyasah_msg' ),
				),
				$args
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/*
	 * Feeds list.
	 */

	/**
	 * Feeds list.
	 */
	public static function page_list() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$feeds = NYASAH_Feeds::list_all();
		echo '<div class="wrap nyasah-admin"><h1 class="wp-heading-inline">' . esc_html__( 'Social Feeds', 'nyasah-social-feed' ) . '</h1> <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=nyasah-feed' ) ) . '">' . esc_html__( 'Add feed', 'nyasah-social-feed' ) . '</a><hr class="wp-header-end" />';
		self::notice();
		if ( ! $feeds ) {
			echo '<div class="nyasah-empty"><div class="nyasah-empty__icons">';
			foreach ( array_keys( NYASAH_Networks::all() ) as $key ) {
				echo wp_kses( NYASAH_Networks::icon( $key, 28 ), NYASAH_Networks::svg_tags() );
			}
			echo '</div><h2>' . esc_html__( 'All your social media in one place', 'nyasah-social-feed' ) . '</h2><p>' . esc_html__( 'Create a feed for YouTube, Instagram, Facebook, Threads, TikTok, X, Pinterest, LinkedIn, Snapchat or Reddit, then put it on any page with its shortcode, block, widget or Elementor widget.', 'nyasah-social-feed' ) . '</p><a class="button button-primary button-hero" href="' . esc_url( admin_url( 'admin.php?page=nyasah-feed' ) ) . '">' . esc_html__( 'Create your first feed', 'nyasah-social-feed' ) . '</a></div></div>';
			return;
		}
		echo '<table class="widefat striped nyasah-table"><thead><tr><th>' . esc_html__( 'Feed', 'nyasah-social-feed' ) . '</th><th>' . esc_html__( 'Network', 'nyasah-social-feed' ) . '</th><th>' . esc_html__( 'Layout', 'nyasah-social-feed' ) . '</th><th>' . esc_html__( 'Shortcode', 'nyasah-social-feed' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $feeds as $post ) {
			$s      = NYASAH_Feeds::get( $post->ID );
			$net    = NYASAH_Networks::get( $s['network'] );
			$edit   = admin_url( 'admin.php?page=nyasah-feed&feed=' . $post->ID );
			$delete = wp_nonce_url( admin_url( 'admin-post.php?action=nyasah_delete_feed&feed=' . $post->ID ), 'nyasah_delete_feed_' . $post->ID );
			$code   = '[nyasah-feed id="' . $post->ID . '"]';
			echo '<tr><td><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( $post->post_title ) . '</a></td>';
			echo '<td class="nyasah-table__net">' . wp_kses( NYASAH_Networks::icon( $s['network'], 18 ), NYASAH_Networks::svg_tags() ) . ' ' . esc_html( $net ? $net['label'] : $s['network'] ) . ' <span class="description">· ' . esc_html( isset( $net['sources'][ $s['source'] ] ) ? $net['sources'][ $s['source'] ]['label'] : '' ) . '</span></td>';
			echo '<td>' . esc_html( self::layouts()[ $s['layout'] ] ) . '</td>';
			echo '<td><code class="nyasah-copy" tabindex="0" role="button" title="' . esc_attr__( 'Click to copy', 'nyasah-social-feed' ) . '">' . esc_html( $code ) . '</code></td>';
			echo '<td class="nyasah-table__actions"><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'nyasah-social-feed' ) . '</a> | <a class="nyasah-delete" href="' . esc_url( $delete ) . '">' . esc_html__( 'Delete', 'nyasah-social-feed' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="nyasah-cache-form"><input type="hidden" name="action" value="nyasah_clear_cache" />';
		wp_nonce_field( 'nyasah_clear_cache' );
		echo '<button class="button">' . esc_html__( 'Fetch all feeds again', 'nyasah-social-feed' ) . '</button> <span class="description">' . esc_html__( 'Feeds are kept for the time set in each feed, then fetched again on the next visit.', 'nyasah-social-feed' ) . '</span></form></div>';
	}

	/**
	 * Layout names.
	 *
	 * @return array
	 */
	private static function layouts() {
		return array(
			'grid'     => __( 'Grid', 'nyasah-social-feed' ),
			'masonry'  => __( 'Masonry', 'nyasah-social-feed' ),
			'list'     => __( 'List', 'nyasah-social-feed' ),
			'carousel' => __( 'Carousel', 'nyasah-social-feed' ),
		);
	}

	/*
	 * Feed editor.
	 */

	/**
	 * Feed editor.
	 */
	public static function page_edit() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$id = isset( $_GET['feed'] ) ? absint( $_GET['feed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which feed to show.
		$s  = $id ? NYASAH_Feeds::get( $id ) : null;
		if ( ! $s ) {
			$id = 0;
			$s  = array_merge( NYASAH_Feeds::defaults(), array( 'name' => '' ) );
		}
		echo '<div class="wrap nyasah-admin"><h1>' . esc_html( $id ? __( 'Edit feed', 'nyasah-social-feed' ) : __( 'Add feed', 'nyasah-social-feed' ) ) . '</h1>';
		self::notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="nyasah-form" class="nyasah-editor"><input type="hidden" name="action" value="nyasah_save_feed" /><input type="hidden" name="feed" value="' . esc_attr( $id ) . '" />';
		wp_nonce_field( 'nyasah_save_feed' );
		echo '<div class="nyasah-editor__side">';
		echo '<p><label class="nyasah-label" for="nyasah-name">' . esc_html__( 'Feed name', 'nyasah-social-feed' ) . '</label><input type="text" id="nyasah-name" name="name" class="widefat" value="' . esc_attr( $s['name'] ) . '" placeholder="' . esc_attr__( 'For example: Homepage Instagram', 'nyasah-social-feed' ) . '" /></p>';
		if ( $id ) {
			echo '<p class="nyasah-shortcode">' . esc_html__( 'Shortcode', 'nyasah-social-feed' ) . ': <code class="nyasah-copy" tabindex="0" role="button">[nyasah-feed id="' . esc_html( $id ) . '"]</code></p>';
		}

		self::section_open( __( 'Source', 'nyasah-social-feed' ), true );
		echo '<div class="nyasah-networks" role="radiogroup">';
		foreach ( NYASAH_Networks::all() as $key => $net ) {
			echo '<label class="nyasah-network"><input type="radio" name="s[network]" value="' . esc_attr( $key ) . '" ' . checked( $s['network'], $key, false ) . ' />' . wp_kses( NYASAH_Networks::icon( $key, 22 ), NYASAH_Networks::svg_tags() ) . '<span>' . esc_html( $net['label'] ) . '</span></label>';
		}
		echo '</div>';
		echo '<p><label class="nyasah-label" for="nyasah-source">' . esc_html__( 'Show', 'nyasah-social-feed' ) . '</label><select id="nyasah-source" name="s[source]" data-current="' . esc_attr( $s['source'] ) . '"></select></p>';
		echo '<p class="nyasah-source-help description"></p>';
		echo '<p class="nyasah-value-row"><label class="nyasah-label" for="nyasah-value"></label><textarea id="nyasah-value" name="s[value]" rows="2" class="widefat" dir="ltr">' . esc_textarea( $s['value'] ) . '</textarea></p>';
		echo '<p class="nyasah-account-row"><label class="nyasah-label" for="nyasah-account">' . esc_html__( 'Account', 'nyasah-social-feed' ) . '</label><select id="nyasah-account" name="s[account]"><option value="">' . esc_html__( 'First connected account', 'nyasah-social-feed' ) . '</option>';
		foreach ( NYASAH_Accounts::all() as $aid => $account ) {
			echo '<option value="' . esc_attr( $aid ) . '" data-network="' . esc_attr( $account['network'] ) . '" ' . selected( $s['account'], $aid, false ) . '>' . esc_html( $account['label'] ) . '</option>';
		}
		echo '</select> <a href="' . esc_url( admin_url( 'admin.php?page=nyasah-accounts' ) ) . '" target="_blank">' . esc_html__( 'Manage accounts', 'nyasah-social-feed' ) . '</a></p>';
		self::number( 'count', __( 'Posts shown', 'nyasah-social-feed' ), $s, 1, 50 );
		self::section_close();

		self::section_open( __( 'Layout', 'nyasah-social-feed' ) );
		echo '<div class="nyasah-layouts">';
		foreach ( self::layouts() as $key => $label ) {
			echo '<label class="nyasah-layout nyasah-layout--' . esc_attr( $key ) . '"><input type="radio" name="s[layout]" value="' . esc_attr( $key ) . '" ' . checked( $s['layout'], $key, false ) . ' /><span class="nyasah-layout__pic" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span><span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</div>';
		echo '<div class="nyasah-row3">';
		self::number( 'cols', __( 'Columns', 'nyasah-social-feed' ), $s, 1, 8 );
		self::number( 'cols_tablet', __( 'Tablet', 'nyasah-social-feed' ), $s, 1, 6 );
		self::number( 'cols_mobile', __( 'Phone', 'nyasah-social-feed' ), $s, 1, 4 );
		echo '</div><div class="nyasah-row3">';
		self::number( 'gap', __( 'Space (px)', 'nyasah-social-feed' ), $s, 0, 60 );
		self::number( 'radius', __( 'Corners (px)', 'nyasah-social-feed' ), $s, 0, 40 );
		echo '</div>';
		self::select(
			'ratio',
			__( 'Picture shape', 'nyasah-social-feed' ),
			$s,
			array(
				'square'    => __( 'Square', 'nyasah-social-feed' ),
				'portrait'  => __( 'Portrait (4:5)', 'nyasah-social-feed' ),
				'landscape' => __( 'Landscape (16:9)', 'nyasah-social-feed' ),
				'original'  => __( 'Original', 'nyasah-social-feed' ),
			)
		);
		self::select(
			'card',
			__( 'Text', 'nyasah-social-feed' ),
			$s,
			array(
				'card'    => __( 'Below the picture', 'nyasah-social-feed' ),
				'overlay' => __( 'On the picture, on hover', 'nyasah-social-feed' ),
			)
		);
		self::section_close();

		self::section_open( __( 'Posts', 'nyasah-social-feed' ) );
		self::check_box( 'show_caption', __( 'Text of the post', 'nyasah-social-feed' ), $s );
		self::number( 'caption_length', __( 'Text length (characters)', 'nyasah-social-feed' ), $s, 0, 2000 );
		self::check_box( 'show_date', __( 'Date', 'nyasah-social-feed' ), $s );
		self::check_box( 'show_stats', __( 'Likes, comments and views', 'nyasah-social-feed' ), $s );
		self::check_box( 'show_author', __( 'Author', 'nyasah-social-feed' ), $s );
		self::check_box( 'show_icon', __( 'Network icon', 'nyasah-social-feed' ), $s );
		self::select(
			'click',
			__( 'Clicking a post', 'nyasah-social-feed' ),
			$s,
			array(
				'popup' => __( 'Opens it in a popup on this page', 'nyasah-social-feed' ),
				'link'  => __( 'Opens it on the network', 'nyasah-social-feed' ),
			)
		);
		self::section_close();

		self::section_open( __( 'Header and buttons', 'nyasah-social-feed' ) );
		self::check_box( 'show_header', __( 'Profile header', 'nyasah-social-feed' ), $s );
		self::check_box( 'show_avatar', __( 'Profile picture', 'nyasah-social-feed' ), $s );
		self::check_box( 'show_bio', __( 'Bio', 'nyasah-social-feed' ), $s );
		self::check_box( 'show_follow', __( '“Follow” button', 'nyasah-social-feed' ), $s );
		self::text( 'follow_text', __( '“Follow” text', 'nyasah-social-feed' ), $s, __( 'Follow on …', 'nyasah-social-feed' ) );
		self::check_box( 'load_more', __( '“Load more” button', 'nyasah-social-feed' ), $s );
		self::text( 'load_more_text', __( '“Load more” text', 'nyasah-social-feed' ), $s, __( 'Load more', 'nyasah-social-feed' ) );
		echo '<p class="description">' . esc_html__( 'Leave the texts empty to use the site’s language automatically (and each language on multilingual sites).', 'nyasah-social-feed' ) . '</p>';
		self::section_close();

		self::section_open( __( 'Colours and refresh', 'nyasah-social-feed' ) );
		echo '<div class="nyasah-row3">';
		self::color( 'accent', __( 'Buttons', 'nyasah-social-feed' ), $s );
		self::color( 'bg', __( 'Background', 'nyasah-social-feed' ), $s );
		self::color( 'text_color', __( 'Text', 'nyasah-social-feed' ), $s );
		echo '</div><p class="description">' . esc_html__( 'Empty: the network’s colour and your theme’s colours.', 'nyasah-social-feed' ) . '</p>';
		self::number( 'cache', __( 'Check for new posts every (minutes)', 'nyasah-social-feed' ), $s, 15, 1440 );
		self::section_close();

		echo '<p class="nyasah-editor__save"><button type="submit" class="button button-primary button-large">' . esc_html__( 'Save feed', 'nyasah-social-feed' ) . '</button></p>';
		echo '</div>';
		echo '<div class="nyasah-editor__preview"><div class="nyasah-preview-bar"><strong>' . esc_html__( 'Preview', 'nyasah-social-feed' ) . '</strong><span class="nyasah-devices" role="group"><button type="button" class="button is-on" data-width="">' . esc_html__( 'Desktop', 'nyasah-social-feed' ) . '</button><button type="button" class="button" data-width="768">' . esc_html__( 'Tablet', 'nyasah-social-feed' ) . '</button><button type="button" class="button" data-width="390">' . esc_html__( 'Phone', 'nyasah-social-feed' ) . '</button></span><label><input type="checkbox" id="nyasah-rtl" /> ' . esc_html__( 'Right-to-left', 'nyasah-social-feed' ) . '</label></div><div class="nyasah-preview" id="nyasah-preview" aria-live="polite"></div></div>';
		echo '</form></div>';
	}

	/**
	 * Section start.
	 *
	 * @param string $title Title.
	 * @param bool   $open  Open.
	 */
	private static function section_open( $title, $open = false ) {
		echo '<details class="nyasah-section"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( $title ) . '</summary><div class="nyasah-section__body">';
	}

	/**
	 * Section end.
	 */
	private static function section_close() {
		echo '</div></details>';
	}

	/**
	 * Number field.
	 *
	 * @param string $key   Setting.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 */
	private static function number( $key, $label, $s, $min, $max ) {
		echo '<p><label class="nyasah-label" for="nyasah-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input type="number" id="nyasah-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" value="' . esc_attr( $s[ $key ] ) . '" class="small-text" /></p>';
	}

	/**
	 * Text field.
	 *
	 * @param string $key         Setting.
	 * @param string $label       Label.
	 * @param array  $s           Settings.
	 * @param string $placeholder Placeholder.
	 */
	private static function text( $key, $label, $s, $placeholder ) {
		echo '<p><label class="nyasah-label" for="nyasah-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input type="text" id="nyasah-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[ $key ] ) . '" placeholder="' . esc_attr( $placeholder ) . '" class="widefat" /></p>';
	}

	/**
	 * Colour field.
	 *
	 * @param string $key   Setting.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 */
	private static function color( $key, $label, $s ) {
		echo '<p class="nyasah-color"><label class="nyasah-label" for="nyasah-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><span><input type="color" id="nyasah-' . esc_attr( $key ) . '" value="' . esc_attr( '' !== $s[ $key ] ? $s[ $key ] : '#ffffff' ) . '" data-target="nyasah-' . esc_attr( $key ) . '-value" /><input type="hidden" id="nyasah-' . esc_attr( $key ) . '-value" name="s[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[ $key ] ) . '" /><button type="button" class="button-link nyasah-color__clear" data-target="nyasah-' . esc_attr( $key ) . '-value">' . esc_html__( 'Reset', 'nyasah-social-feed' ) . '</button></span></p>';
	}

	/**
	 * Select field.
	 *
	 * @param string $key     Setting.
	 * @param string $label   Label.
	 * @param array  $s       Settings.
	 * @param array  $choices Choices.
	 */
	private static function select( $key, $label, $s, $choices ) {
		echo '<p><label class="nyasah-label" for="nyasah-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><select id="nyasah-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']">';
		foreach ( $choices as $value => $name ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $s[ $key ], $value, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * Checkbox.
	 *
	 * @param string $key   Setting.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 */
	private static function check_box( $key, $label, $s ) {
		echo '<p class="nyasah-check"><label><input type="checkbox" name="s[' . esc_attr( $key ) . ']" value="1" ' . checked( $s[ $key ], 1, false ) . ' /> ' . esc_html( $label ) . '</label></p>';
	}

	/**
	 * Settings sent by the editor.
	 *
	 * @return array
	 */
	private static function posted_settings() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by the callers; every value is cleaned in NYASAH_Feeds::sanitize().
		$raw = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array();
		return NYASAH_Feeds::sanitize( $raw );
	}

	/**
	 * Save a feed.
	 */
	public static function save_feed() {
		self::check( 'nyasah_save_feed' );
		check_admin_referer( 'nyasah_save_feed' );
		$id   = isset( $_POST['feed'] ) ? absint( $_POST['feed'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( $id && NYASAH_Feeds::TYPE !== get_post_type( $id ) ) {
			$id = 0;
		}
		$result = NYASAH_Feeds::save( $id, $name, self::posted_settings() );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		self::back( 'nyasah-feed', 'saved', array( 'feed' => $result ) );
	}

	/**
	 * Delete a feed.
	 */
	public static function delete_feed() {
		$id = isset( $_GET['feed'] ) ? absint( $_GET['feed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce for this feed is checked on the next line.
		self::check( 'nyasah_delete_feed_' . $id );
		if ( NYASAH_Feeds::TYPE === get_post_type( $id ) ) {
			NYASAH_Cache::forget( $id );
			wp_delete_post( $id, true );
		}
		self::back( self::SLUG, 'deleted' );
	}

	/**
	 * Fetch all feeds again.
	 */
	public static function clear_cache() {
		self::check( 'nyasah_clear_cache' );
		NYASAH_Cache::flush();
		self::back( self::SLUG, 'cache' );
	}

	/**
	 * Live preview of the editor's settings.
	 */
	public static function preview() {
		check_ajax_referer( 'nyasah_preview', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$s = self::posted_settings();
		wp_send_json_success( array( 'html' => NYASAH_Render::feed( $s, array( 'preview' => true ) ) ) );
	}

	/*
	 * Accounts.
	 */

	/**
	 * What each network's account form asks for.
	 *
	 * @return array
	 */
	public static function account_fields() {
		return array(
			'youtube'   => array( 'token' => __( 'YouTube Data API key', 'nyasah-social-feed' ) ),
			'instagram' => array(
				'token'   => __( 'Access token', 'nyasah-social-feed' ),
				'user_id' => __( 'Instagram account ID (only for Facebook Login tokens)', 'nyasah-social-feed' ),
			),
			'facebook'  => array(
				'token'   => __( 'Page access token', 'nyasah-social-feed' ),
				'user_id' => __( 'Page ID', 'nyasah-social-feed' ),
			),
			'threads'   => array( 'token' => __( 'Access token', 'nyasah-social-feed' ) ),
			'tiktok'    => array(
				'token'         => __( 'Access token', 'nyasah-social-feed' ),
				'refresh_token' => __( 'Refresh token', 'nyasah-social-feed' ),
				'client_key'    => __( 'Client key', 'nyasah-social-feed' ),
				'client_secret' => __( 'Client secret', 'nyasah-social-feed' ),
			),
			'twitter'   => array( 'token' => __( 'Bearer token', 'nyasah-social-feed' ) ),
			'linkedin'  => array( 'token' => __( 'Access token (page admin)', 'nyasah-social-feed' ) ),
		);
	}

	/**
	 * Step-by-step guide for a network.
	 *
	 * @param string $network Network.
	 * @return array Steps (may contain links).
	 */
	private static function guide( $network ) {
		$guides = array(
			'youtube'   => array(
				__( 'Open console.cloud.google.com, create a project (or choose one).', 'nyasah-social-feed' ),
				__( 'APIs & Services › Library: enable “YouTube Data API v3”.', 'nyasah-social-feed' ),
				__( 'APIs & Services › Credentials › Create credentials › API key. Restrict it to the YouTube Data API v3.', 'nyasah-social-feed' ),
				__( 'Paste the key here. It is free within Google’s daily quota, which is plenty because feeds are cached.', 'nyasah-social-feed' ),
			),
			'instagram' => array(
				__( 'Your Instagram account must be a Business or Creator account (Instagram app › Settings › Account type).', 'nyasah-social-feed' ),
				__( 'At developers.facebook.com create an app of type “Business” and add the product “Instagram” › “API setup with Instagram login”.', 'nyasah-social-feed' ),
				__( 'Add your Instagram account under “Generate access tokens” and click “Generate token”. Copy the token (it starts with IG).', 'nyasah-social-feed' ),
				__( 'Paste it here. Nyasah renews it automatically before it expires (every 60 days).', 'nyasah-social-feed' ),
			),
			'facebook'  => array(
				__( 'At developers.facebook.com create an app of type “Business”.', 'nyasah-social-feed' ),
				__( 'Open Tools › Graph API Explorer, select your app, choose “Get Page Access Token” and allow pages_read_engagement and pages_show_list.', 'nyasah-social-feed' ),
				__( 'Select your Page, then open the Access Token Debugger and click “Extend Access Token” to get a token that does not expire.', 'nyasah-social-feed' ),
				__( 'Paste the token and your Page ID (Page › About › Page transparency, or the number in the Page address).', 'nyasah-social-feed' ),
			),
			'threads'   => array(
				__( 'At developers.facebook.com create an app and add the “Threads API” use case.', 'nyasah-social-feed' ),
				__( 'Add yourself as a Threads tester (App roles), accept the invite in Threads › Settings › Account › Website permissions.', 'nyasah-social-feed' ),
				__( 'Use the “User Token Generator” of the Threads API to create a long-lived token with threads_basic.', 'nyasah-social-feed' ),
				__( 'Paste it here. Nyasah renews it automatically before it expires.', 'nyasah-social-feed' ),
			),
			'tiktok'    => array(
				__( 'At developers.tiktok.com create an app with “Login Kit” and the scopes user.info.basic and video.list.', 'nyasah-social-feed' ),
				__( 'Authorize your TikTok account once with the app (TikTok’s OAuth flow) to get an access token and a refresh token.', 'nyasah-social-feed' ),
				__( 'Paste the access token, refresh token, client key and client secret. Access tokens last 24 hours; Nyasah renews them with the refresh token.', 'nyasah-social-feed' ),
				__( 'No developer app? Use “Chosen videos” in the feed instead: it works with any public TikTok address.', 'nyasah-social-feed' ),
			),
			'twitter'   => array(
				__( 'Reading posts needs one of X’s paid API plans (developer.x.com).', 'nyasah-social-feed' ),
				__( 'In the developer portal open your project’s app › Keys and tokens › Bearer token.', 'nyasah-social-feed' ),
				__( 'Paste the bearer token here.', 'nyasah-social-feed' ),
				__( 'No paid plan? Use “Chosen posts” in the feed instead: it works with any public post address, free.', 'nyasah-social-feed' ),
			),
			'linkedin'  => array(
				__( 'At linkedin.com/developers create an app linked to your company page and request the “Community Management API”.', 'nyasah-social-feed' ),
				__( 'Once approved, create a 3-legged OAuth token with r_organization_social as an admin of the page.', 'nyasah-social-feed' ),
				__( 'Paste the token here and put the organization ID in the feed.', 'nyasah-social-feed' ),
				__( 'For personal profiles, or without an approved app, use “Chosen posts” in the feed instead.', 'nyasah-social-feed' ),
			),
		);
		return isset( $guides[ $network ] ) ? $guides[ $network ] : array();
	}

	/**
	 * Accounts screen.
	 */
	public static function page_accounts() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap nyasah-admin"><h1>' . esc_html__( 'Accounts', 'nyasah-social-feed' ) . '</h1>';
		self::notice();
		echo '<p class="nyasah-lead">' . esc_html__( 'Pinterest, Reddit, Snapchat, YouTube (latest 15 videos) and chosen posts from Facebook, TikTok, X and LinkedIn work without any account. Connect an account for full feeds of the networks below.', 'nyasah-social-feed' ) . '</p>';
		$accounts = NYASAH_Accounts::all();
		if ( $accounts ) {
			echo '<table class="widefat striped nyasah-table"><thead><tr><th>' . esc_html__( 'Account', 'nyasah-social-feed' ) . '</th><th>' . esc_html__( 'Network', 'nyasah-social-feed' ) . '</th><th>' . esc_html__( 'Token', 'nyasah-social-feed' ) . '</th><th>' . esc_html__( 'Renews', 'nyasah-social-feed' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $accounts as $aid => $a ) {
				$net    = NYASAH_Networks::get( $a['network'] );
				$delete = wp_nonce_url( admin_url( 'admin-post.php?action=nyasah_delete_account&account=' . $aid ), 'nyasah_delete_account_' . $aid );
				echo '<tr><td><strong>' . esc_html( $a['label'] ) . '</strong></td><td class="nyasah-table__net">' . wp_kses( NYASAH_Networks::icon( $a['network'], 18 ), NYASAH_Networks::svg_tags() ) . ' ' . esc_html( $net ? $net['label'] : $a['network'] ) . '</td><td><code>' . esc_html( NYASAH_Accounts::mask( $a['token'] ) ) . '</code></td><td>' . esc_html( $a['expires'] ? wp_date( get_option( 'date_format' ), (int) $a['expires'] ) : '—' ) . '</td><td><a class="nyasah-delete" href="' . esc_url( $delete ) . '">' . esc_html__( 'Remove', 'nyasah-social-feed' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<h2>' . esc_html__( 'Connect an account', 'nyasah-social-feed' ) . '</h2><div class="nyasah-accounts">';
		foreach ( self::account_fields() as $network => $fields ) {
			$net = NYASAH_Networks::get( $network );
			echo '<details class="nyasah-account"><summary>' . wp_kses( NYASAH_Networks::icon( $network, 22 ), NYASAH_Networks::svg_tags() ) . ' ' . esc_html( $net['label'] ) . '</summary><div class="nyasah-account__body"><ol class="nyasah-guide">';
			foreach ( self::guide( $network ) as $step ) {
				echo '<li>' . esc_html( $step ) . '</li>';
			}
			echo '</ol><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nyasah_save_account" /><input type="hidden" name="network" value="' . esc_attr( $network ) . '" />';
			wp_nonce_field( 'nyasah_save_account' );
			echo '<p><label class="nyasah-label">' . esc_html__( 'Name (only for you)', 'nyasah-social-feed' ) . '<input type="text" name="label" class="widefat" /></label></p>';
			foreach ( $fields as $field => $label ) {
				echo '<p><label class="nyasah-label">' . esc_html( $label ) . '<input type="' . ( in_array( $field, array( 'token', 'client_secret', 'refresh_token' ), true ) ? 'password' : 'text' ) . '" name="' . esc_attr( $field ) . '" class="widefat" autocomplete="off" dir="ltr" /></label></p>';
			}
			echo '<p><button class="button button-primary">' . esc_html__( 'Connect', 'nyasah-social-feed' ) . '</button></p></form></div></details>';
		}
		echo '</div></div>';
	}

	/**
	 * Save an account (checked with the network when possible).
	 */
	public static function save_account() {
		self::check( 'nyasah_save_account' );
		check_admin_referer( 'nyasah_save_account' );
		$network = isset( $_POST['network'] ) ? sanitize_key( wp_unslash( $_POST['network'] ) ) : '';
		$fields  = self::account_fields();
		if ( ! isset( $fields[ $network ] ) ) {
			self::back( 'nyasah-accounts', 'account_error' );
		}
		$account = array(
			'network'   => $network,
			'refreshed' => time(),
		);
		foreach ( array_keys( $fields[ $network ] ) as $field ) {
			$account[ $field ] = isset( $_POST[ $field ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ) : '';
		}
		if ( empty( $account['token'] ) ) {
			self::back( 'nyasah-accounts', 'account_error' );
		}
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$found = self::identify( $account );
		if ( false === $found ) {
			self::back( 'nyasah-accounts', 'account_error' );
		}
		$net              = NYASAH_Networks::get( $network );
		$account['label'] = '' !== $label ? $label : ( '' !== $found ? $found : $net['label'] );
		if ( 'tiktok' === $network ) {
			$account['expires'] = time() + DAY_IN_SECONDS;
		} elseif ( in_array( $network, array( 'instagram', 'threads' ), true ) ) {
			$account['expires'] = time() + 60 * DAY_IN_SECONDS;
		}
		NYASAH_Accounts::save( $account );
		NYASAH_Cache::flush();
		self::back( 'nyasah-accounts', 'account' );
	}

	/**
	 * Name of the account behind a token ('' when it cannot be checked, false when refused).
	 *
	 * @param array $account Account.
	 * @return string|false
	 */
	private static function identify( $account ) {
		$account = array_merge(
			array(
				'user_id' => '',
				'token'   => '',
			),
			$account
		);
		switch ( $account['network'] ) {
			case 'instagram':
				$p = NYASAH_Source_Instagram::profile( $account );
				return '' !== $p['username'] ? '@' . $p['username'] : false;
			case 'threads':
				$p = NYASAH_HTTP::get_json( 'https://graph.threads.net/v1.0/me?fields=username&access_token=' . rawurlencode( $account['token'] ) );
				return is_wp_error( $p ) || empty( $p['username'] ) ? false : '@' . $p['username'];
			case 'facebook':
				$p = NYASAH_HTTP::get_json( 'https://graph.facebook.com/v21.0/' . rawurlencode( $account['user_id'] ) . '?fields=name&access_token=' . rawurlencode( $account['token'] ) );
				return is_wp_error( $p ) || empty( $p['name'] ) ? false : $p['name'];
			case 'youtube':
				$p = NYASAH_HTTP::get_json( 'https://www.googleapis.com/youtube/v3/channels?part=id&forHandle=%40YouTube&key=' . rawurlencode( $account['token'] ) );
				return is_wp_error( $p ) ? false : __( 'YouTube API key', 'nyasah-social-feed' );
		}
		return '';
	}

	/**
	 * Remove an account.
	 */
	public static function delete_account() {
		$id = isset( $_GET['account'] ) ? sanitize_key( wp_unslash( $_GET['account'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce for this account is checked on the next line.
		self::check( 'nyasah_delete_account_' . $id );
		NYASAH_Accounts::delete( $id );
		self::back( 'nyasah-accounts', 'account_gone' );
	}
}
