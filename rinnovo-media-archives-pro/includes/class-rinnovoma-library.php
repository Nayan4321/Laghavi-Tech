<?php
/**
 * The media library: videos, audio, images and files as items (WooCommerce › Media), in collections (series,
 * seasons, playlists made by the shop) and in libraries (brands or client portals, with their own branding and
 * client permissions). Items can be scheduled, have a free preview, be downloadable, and need a plan (or a
 * Membership Content Pro tier).
 *
 * Front end: [media_library] (search, filters; grid, list or carousel) and each item's page with the player,
 * resume, favourites, "add to my playlist" and download. Members' history, playlists and favourites show in the
 * portal.
 *
 * @package RinnovoMediaArchives
 */

defined( 'ABSPATH' ) || exit;

/**
 * Library.
 */
class RINNOVOMA_Library {

	const TD   = 'rinnovo-media-archives-pro';
	const TYPE = 'rinnovoma_media';
	const COLL = 'rinnovoma_collection';
	const LIB  = 'rinnovoma_library';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'single' ), 15 );
		add_shortcode( 'media_library', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'media_my', array( __CLASS__, 'my_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'actions' ) );
		add_action( self::LIB . '_add_form_fields', array( __CLASS__, 'library_fields_add' ) );
		add_action( self::LIB . '_edit_form_fields', array( __CLASS__, 'library_fields_edit' ) );
		add_action( 'created_' . self::LIB, array( __CLASS__, 'save_library' ) );
		add_action( 'edited_' . self::LIB, array( __CLASS__, 'save_library' ) );
		add_action( 'wp_head', array( __CLASS__, 'branding_css' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'extra_field' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_extra_field' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'extra_paid' ), 30 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'extra_paid' ), 30 );
	}

	/**
	 * Types.
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Media', self::TD ),
					'singular_name' => __( 'Media item', self::TD ),
					'add_new_item'  => __( 'Add media item', self::TD ),
					'edit_item'     => __( 'Edit media item', self::TD ),
					'menu_name'     => __( 'Media Archives', self::TD ),
				),
				'public'       => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-format-video',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => 'watch' ),
				'show_in_rest' => true,
			)
		);
		register_taxonomy(
			self::COLL,
			self::TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Collections & seasons', self::TD ),
					'singular_name' => __( 'Collection', self::TD ),
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'collection' ),
			)
		);
		register_taxonomy(
			self::LIB,
			self::TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Libraries & clients', self::TD ),
					'singular_name' => __( 'Library', self::TD ),
				),
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * An item's settings.
	 *
	 * @param int $id Item.
	 * @return array|null
	 */
	public static function item( $id ) {
		if ( self::TYPE !== get_post_type( $id ) ) {
			return null;
		}
		$m = static function ( $k, $d = '' ) use ( $id ) {
			$v = get_post_meta( $id, '_rinnovoma_' . $k, true );
			return '' === $v ? $d : $v;
		};
		return array(
			'kind'       => (string) $m( 'kind', 'video' ),
			'provider'   => (string) $m( 'provider', 'bunny' ),
			'id'         => (string) $m( 'media_id' ),
			'plan'       => (string) $m( 'plan', 'any' ),
			'tier'       => (string) $m( 'tier' ),
			'preview_id' => (string) $m( 'preview_id' ),
			'download'   => 'yes' === $m( 'download', 'no' ),
			'duration'   => (string) $m( 'duration' ),
		);
	}

	/**
	 * Whether a member may see items of the item's client libraries (an empty client list = everyone).
	 *
	 * @param int $user_id User.
	 * @param int $id      Item.
	 * @return bool
	 */
	public static function client_ok( $user_id, $id ) {
		$terms = wp_get_post_terms( $id, self::LIB );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return true;
		}
		$user = get_userdata( (int) $user_id );
		foreach ( $terms as $term ) {
			$clients = array_filter( array_map( 'trim', explode( ',', strtolower( (string) get_term_meta( $term->term_id, '_rinnovoma_clients', true ) ) ) ) );
			if ( ! $clients || ( $user && in_array( strtolower( $user->user_email ), $clients, true ) ) ) {
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------- Admin.

	/**
	 * Settings box.
	 */
	public static function meta_box() {
		add_meta_box( 'rinnovoma-item', __( 'Media', self::TD ), array( __CLASS__, 'box' ), self::TYPE, 'normal', 'high' );
	}

	/**
	 * The box.
	 *
	 * @param WP_Post $post Item.
	 */
	public static function box( $post ) {
		$i = self::item( $post->ID );
		wp_nonce_field( 'rinnovoma_item', 'rinnovoma_item_nonce' );
		$sel = static function ( $name, $opts, $val ) {
			$h = '<select name="' . esc_attr( $name ) . '">';
			foreach ( $opts as $k => $l ) {
				$h .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $val, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			return $h . '</select>';
		};
		$providers = array(
			'bunny'      => 'Bunny Stream',
			'cloudflare' => 'Cloudflare Stream',
			'vimeo'      => 'Vimeo',
			'bunnycdn'   => 'Bunny Storage / CDN',
			'cloudfront' => 'Amazon CloudFront',
			's3'         => 'Amazon S3',
			'file'       => __( 'Protected file (attachment ID)', self::TD ),
		);
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Type', self::TD ) . '</th><td>' . $sel( 'rma[kind]', array( 'video' => __( 'Video', self::TD ), 'audio' => __( 'Audio / podcast', self::TD ), 'image' => __( 'Image / footage / design', self::TD ), 'file' => __( 'File', self::TD ) ), $i['kind'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $sel.
		echo '<tr><th>' . esc_html__( 'Hosted on', self::TD ) . '</th><td>' . $sel( 'rma[provider]', $providers, $i['provider'] ) . ' <input type="text" class="regular-text" name="rma[media_id]" value="' . esc_attr( $i['id'] ) . '" placeholder="' . esc_attr__( 'Video ID, path, key or attachment ID', self::TD ) . '" /><p class="description">' . esc_html__( 'Storage keys stay on the server: visitors only ever get short-lived signed links.', self::TD ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $sel.
		echo '<tr><th>' . esc_html__( 'Who can play it', self::TD ) . '</th><td>' . $sel( 'rma[plan]', array_merge( array( 'any' => __( 'Any plan', self::TD ) ), RINNOVOMA_Plans::choices() ), $i['plan'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $sel.
		if ( class_exists( 'RINNOVOMEM_Tiers' ) ) {
			$tiers = array( '' => __( '— or a Membership tier —', self::TD ) );
			foreach ( RINNOVOMEM_Tiers::all() as $id => $t ) {
				$tiers[ $id ] = $t['name'];
			}
			echo ' ' . $sel( 'rma[tier]', $tiers, $i['tier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $sel.
		}
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Free preview', self::TD ) . '</th><td><input type="text" class="regular-text" name="rma[preview_id]" value="' . esc_attr( $i['preview_id'] ) . '" placeholder="' . esc_attr__( 'ID of a short trailer on the same host (optional)', self::TD ) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Download', self::TD ) . '</th><td><label><input type="checkbox" name="rma[download]" value="yes"' . checked( $i['download'], true, false ) . ' /> ' . esc_html__( 'Subscribers can download it (counts against a "downloads" quota when the plan has one)', self::TD ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Length', self::TD ) . '</th><td><input type="text" name="rma[duration]" value="' . esc_attr( $i['duration'] ) . '" placeholder="12:30" style="width:90px" /></td></tr>';
		if ( 'publish' === $post->post_status ) {
			echo '<tr><th>' . esc_html__( 'Share with a client', self::TD ) . '</th><td><input type="text" class="large-text" readonly onclick="this.select()" value="' . esc_attr( RINNOVOMA_Pipeline::share_link( $post->ID, 7 ) ) . '" /><p class="description">' . esc_html__( 'Plays this one item without an account for 7 days.', self::TD ) . '</p></td></tr>';
		}
		echo '</table><p class="description">' . esc_html__( 'Schedule it with "Publish › Schedule": it appears on that date. Put it in collections (series, seasons) and libraries (brands or clients) on the right.', self::TD ) . '</p>';
	}

	/**
	 * Save.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['rinnovoma_item_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovoma_item_nonce'] ) ), 'rinnovoma_item' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
		$r = isset( $_POST['rma'] ) ? (array) wp_unslash( $_POST['rma'] ) : array();
		update_post_meta( $post_id, '_rinnovoma_kind', isset( $r['kind'] ) && in_array( $r['kind'], array( 'video', 'audio', 'image', 'file' ), true ) ? $r['kind'] : 'video' );
		update_post_meta( $post_id, '_rinnovoma_provider', isset( $r['provider'] ) ? sanitize_key( $r['provider'] ) : 'bunny' );
		foreach ( array( 'media_id', 'preview_id', 'duration' ) as $k ) {
			update_post_meta( $post_id, '_rinnovoma_' . $k, isset( $r[ $k ] ) ? sanitize_text_field( $r[ $k ] ) : '' );
		}
		update_post_meta( $post_id, '_rinnovoma_plan', isset( $r['plan'] ) ? sanitize_key( $r['plan'] ) : 'any' );
		update_post_meta( $post_id, '_rinnovoma_tier', isset( $r['tier'] ) ? sanitize_key( $r['tier'] ) : '' );
		update_post_meta( $post_id, '_rinnovoma_download', ! empty( $r['download'] ) ? 'yes' : 'no' );
	}

	/**
	 * Library fields: clients and branding.
	 */
	public static function library_fields_add() {
		wp_nonce_field( 'rinnovoma_lib', 'rinnovoma_lib_nonce' );
		echo '<div class="form-field"><label>' . esc_html__( 'Clients (emails, comma separated)', self::TD ) . '</label><input type="text" name="rma_clients" /><p>' . esc_html__( 'Only these customers see this library\'s items (empty = every subscriber).', self::TD ) . '</p></div><div class="form-field"><label>' . esc_html__( 'Brand colour', self::TD ) . '</label><input type="color" name="rma_color" value="#2271b1" /></div><div class="form-field"><label>' . esc_html__( 'Logo URL', self::TD ) . '</label><input type="url" name="rma_logo" /></div>';
	}

	/**
	 * Edit form.
	 *
	 * @param WP_Term $term Term.
	 */
	public static function library_fields_edit( $term ) {
		wp_nonce_field( 'rinnovoma_lib', 'rinnovoma_lib_nonce' );
		echo '<tr class="form-field"><th>' . esc_html__( 'Clients', self::TD ) . '</th><td><input type="text" name="rma_clients" value="' . esc_attr( (string) get_term_meta( $term->term_id, '_rinnovoma_clients', true ) ) . '" /><p class="description">' . esc_html__( 'Emails, comma separated. Only these customers see this library\'s items (empty = every subscriber).', self::TD ) . '</p></td></tr>';
		echo '<tr class="form-field"><th>' . esc_html__( 'Brand colour', self::TD ) . '</th><td><input type="color" name="rma_color" value="' . esc_attr( (string) ( get_term_meta( $term->term_id, '_rinnovoma_color', true ) ? get_term_meta( $term->term_id, '_rinnovoma_color', true ) : '#2271b1' ) ) . '" /></td></tr>';
		echo '<tr class="form-field"><th>' . esc_html__( 'Logo URL', self::TD ) . '</th><td><input type="url" name="rma_logo" value="' . esc_attr( (string) get_term_meta( $term->term_id, '_rinnovoma_logo', true ) ) . '" /></td></tr>';
	}

	/**
	 * Save a library.
	 *
	 * @param int $term_id Term.
	 */
	public static function save_library( $term_id ) {
		if ( ! isset( $_POST['rinnovoma_lib_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovoma_lib_nonce'] ) ), 'rinnovoma_lib' ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$clients = isset( $_POST['rma_clients'] ) ? implode( ', ', array_filter( array_map( 'sanitize_email', explode( ',', sanitize_text_field( wp_unslash( $_POST['rma_clients'] ) ) ) ) ) ) : '';
		update_term_meta( $term_id, '_rinnovoma_clients', $clients );
		update_term_meta( $term_id, '_rinnovoma_color', isset( $_POST['rma_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['rma_color'] ) ) : '' );
		update_term_meta( $term_id, '_rinnovoma_logo', isset( $_POST['rma_logo'] ) ? esc_url_raw( wp_unslash( $_POST['rma_logo'] ) ) : '' );
	}

	/**
	 * Player colour, the client's brand colour and the shop's custom CSS.
	 */
	public static function branding_css() {
		$color = (string) RINNOVOMA_Plans::get( 'player_color' );
		$css   = '';
		if ( is_singular( self::TYPE ) ) {
			$libs = wp_get_post_terms( get_the_ID(), self::LIB );
			if ( ! is_wp_error( $libs ) && $libs && get_term_meta( $libs[0]->term_id, '_rinnovoma_color', true ) ) {
				$color = (string) get_term_meta( $libs[0]->term_id, '_rinnovoma_color', true );
			}
		}
		if ( $color && sanitize_hex_color( $color ) ) {
			$css .= ':root{--rinnovoma-accent:' . sanitize_hex_color( $color ) . '}';
		}
		$css .= (string) RINNOVOMA_Plans::get( 'portal_css' );
		if ( '' !== trim( $css ) ) {
			echo '<style id="rinnovoma-brand">' . wp_strip_all_tags( $css ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS from the shop owner, tags stripped.
		}
	}

	// ---------------------------------------------------------------- Front end.

	/**
	 * Player of an item through the pipeline (or its preview, or the locked box).
	 *
	 * @param int $id Item.
	 * @return string
	 */
	public static function player( $id ) {
		$item = self::item( $id );
		$user = get_current_user_id();
		if ( RINNOVOMA_Pipeline::shared( $id ) ) {
			$url = RINNOVOMA_Signer::is_embed( $item['provider'] ) ? RINNOVOMA_Signer::url( $item['provider'], $item['id'] ) : RINNOVOMA_Player::media_link( $item['provider'], $item['id'], 0, HOUR_IN_SECONDS, 'share' );
			RINNOVOMA_Pipeline::event( 0, $id, 'share' );
			return self::embed( $item, $url, $id, false );
		}
		$auth = RINNOVOMA_Pipeline::authorize( $user, $id, 'play' );
		if ( ! $auth['ok'] ) {
			$html = '';
			if ( $auth['preview'] ) {
				$preview = $item;
				$preview['id'] = $item['preview_id'];
				$url           = RINNOVOMA_Signer::is_embed( $item['provider'] ) ? RINNOVOMA_Signer::url( $item['provider'], $item['preview_id'] ) : RINNOVOMA_Player::media_link( $item['provider'], $item['preview_id'], 0, HOUR_IN_SECONDS, 'share' );
				$html          = '<p class="rinnovoma-preview-label">' . esc_html__( 'Free preview', self::TD ) . '</p>' . self::embed( $preview, $url, $id, false );
			}
			$why = array(
				'scheduled' => __( 'Coming soon.', self::TD ),
				'client'    => __( 'This item belongs to another client\'s library.', self::TD ),
				'locked'    => __( 'Your account is locked. Please contact us.', self::TD ),
				'rate'      => __( 'Too many requests. Please wait a minute.', self::TD ),
			);
			return $html . ( isset( $why[ $auth['reason'] ] ) ? '<p class="rinnovoma-locked">' . esc_html( $why[ $auth['reason'] ] ) . '</p>' : RINNOVOMA_Player::locked( $item['plan'], 'audio' === $item['kind'] ? 'audio' : 'video' ) );
		}
		return self::embed( $item, $auth['url'], $id, true );
	}

	/**
	 * Player HTML.
	 *
	 * @param array  $item  Item.
	 * @param string $url   Signed address.
	 * @param int    $id    Item.
	 * @param bool   $track Save progress.
	 * @return string
	 */
	private static function embed( $item, $url, $id, $track ) {
		$pos   = $track && get_current_user_id() ? RINNOVOMA_Pipeline::position( get_current_user_id(), $id ) : array( 'seconds' => 0 );
		$data  = $track ? ' data-media="' . (int) $id . '" data-resume="' . (int) $pos['seconds'] . '"' : '';
		if ( $track ) {
			wp_enqueue_script( 'rinnovoma-library', RINNOVOMA_URL . 'assets/js/library.js', array(), RINNOVOMA_VERSION, true );
			wp_localize_script( 'rinnovoma-library', 'rinnovomaLib', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'rinnovoma_progress' ) ) );
		}
		switch ( $item['kind'] ) {
			case 'audio':
				$inner = '<audio controls preload="metadata" controlsList="nodownload" src="' . esc_url( $url ) . '"' . $data . '></audio>';
				break;
			case 'image':
				$inner = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( get_the_title( $id ) ) . '" loading="lazy" />';
				break;
			case 'file':
				$inner = '';
				break;
			default:
				$inner = RINNOVOMA_Signer::is_embed( $item['provider'] ) ? '<iframe src="' . esc_url( $url ) . '" title="' . esc_attr( get_the_title( $id ) ) . '" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" style="aspect-ratio:16/9;width:100%"></iframe>' : '<video controls playsinline preload="metadata" controlsList="nodownload noremoteplayback" disablePictureInPicture src="' . esc_url( $url ) . '"' . $data . ' style="width:100%;aspect-ratio:16/9"></video>';
		}
		return '' === $inner ? '' : RINNOVOMA_Player::wrap( $inner, 'image' === $item['kind'] ? 'art' : ( 'audio' === $item['kind'] ? 'audio' : 'video' ) );
	}

	/**
	 * Favourites and playlists of a member.
	 *
	 * @param int $user_id User.
	 * @return array favourites (ids), playlists (name => ids).
	 */
	public static function mine( $user_id ) {
		$m = get_user_meta( $user_id, '_rinnovoma_mine', true );
		$m = is_array( $m ) ? $m : array();
		return array(
			'favourites' => isset( $m['favourites'] ) ? array_map( 'intval', (array) $m['favourites'] ) : array(),
			'playlists'  => isset( $m['playlists'] ) ? (array) $m['playlists'] : array(),
		);
	}

	/**
	 * Favourite, playlist and download actions.
	 */
	public static function actions() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below.
		if ( empty( $_GET['rma_do'] ) || empty( $_GET['item'] ) ) {
			return;
		}
		$do   = sanitize_key( wp_unslash( $_GET['rma_do'] ) );
		$id   = absint( $_GET['item'] );
		$user = get_current_user_id();
		if ( ! $user || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rma_' . $do . '_' . $id ) || ! self::item( $id ) ) {
			return;
		}
		$mine = self::mine( $user );
		if ( 'fav' === $do ) {
			$mine['favourites'] = in_array( $id, $mine['favourites'], true ) ? array_values( array_diff( $mine['favourites'], array( $id ) ) ) : array_merge( $mine['favourites'], array( $id ) );
		} elseif ( 'list' === $do ) {
			$name = isset( $_GET['list'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['list'] ) ), 0, 40 ) : __( 'My playlist', self::TD );
			$name = '' === $name ? __( 'My playlist', self::TD ) : $name;
			$list = isset( $mine['playlists'][ $name ] ) ? array_map( 'intval', (array) $mine['playlists'][ $name ] ) : array();
			if ( ! in_array( $id, $list, true ) ) {
				$list[] = $id;
			}
			$mine['playlists'][ $name ] = array_slice( $list, -200 );
		} elseif ( 'download' === $do ) {
			$auth = RINNOVOMA_Pipeline::authorize( $user, $id, 'download' );
			if ( $auth['ok'] ) {
				wp_redirect( $auth['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Our own signed link.
				exit;
			}
			wp_die( esc_html( 'quota' === $auth['reason'] ? __( 'Your download quota for this period is used up. Buy extra downloads or wait for your next renewal.', self::TD ) : __( 'This download is not available on your plan.', self::TD ) ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		// phpcs:enable
		update_user_meta( $user, '_rinnovoma_mine', $mine );
		wp_safe_redirect( remove_query_arg( array( 'rma_do', 'item', '_wpnonce', 'list' ) ) );
		exit;
	}

	/**
	 * Action link.
	 *
	 * @param string $do   Action.
	 * @param int    $id   Item.
	 * @param array  $args More.
	 * @return string
	 */
	private static function action_url( $do, $id, $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'rma_do' => $do, 'item' => $id ), $args ) ), 'rma_' . $do . '_' . $id );
	}

	/**
	 * Item page: player, actions, collection.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function single( $content ) {
		if ( ! is_singular( self::TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id   = get_the_ID();
		$item = self::item( $id );
		$html = self::player( $id );
		$user = get_current_user_id();
		if ( $user ) {
			$mine  = self::mine( $user );
			$html .= '<p class="rinnovoma-actions"><a class="button" href="' . esc_url( self::action_url( 'fav', $id ) ) . '">' . esc_html( in_array( $id, $mine['favourites'], true ) ? __( '★ Favourite', self::TD ) : __( '☆ Add to favourites', self::TD ) ) . '</a> <a class="button" href="' . esc_url( self::action_url( 'list', $id ) ) . '">' . esc_html__( '+ My playlist', self::TD ) . '</a>';
			if ( $item['download'] ) {
				$html .= ' <a class="button" href="' . esc_url( self::action_url( 'download', $id ) ) . '" rel="nofollow">' . esc_html( RINNOVOMA_Pipeline::downloaded( $user, $id ) ? __( 'Download again', self::TD ) : __( 'Download', self::TD ) ) . '</a>';
			}
			$html .= '</p>';
		}
		$colls = wp_get_post_terms( $id, self::COLL );
		if ( ! is_wp_error( $colls ) && $colls ) {
			$siblings = get_posts(
				array(
					'post_type'      => self::TYPE,
					'posts_per_page' => 30,
					'orderby'        => 'menu_order date',
					'order'          => 'ASC',
					'tax_query'      => array( array( 'taxonomy' => self::COLL, 'terms' => $colls[0]->term_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				)
			);
			$html .= '<nav class="rinnovoma-series"><h3>' . esc_html( $colls[0]->name ) . '</h3><ol>';
			foreach ( $siblings as $s ) {
				$html .= '<li' . ( $s->ID === $id ? ' class="is-current"' : '' ) . '><a href="' . esc_url( get_permalink( $s ) ) . '">' . esc_html( get_the_title( $s ) ) . '</a></li>';
			}
			$html .= '</ol></nav>';
		}
		return $html . $content;
	}

	/**
	 * Card of an item.
	 *
	 * @param WP_Post $p    Item.
	 * @param array   $opts Layout options.
	 * @param int     $user User.
	 * @return string
	 */
	public static function card( $p, $opts, $user ) {
		$i    = self::item( $p->ID );
		$pos  = $user ? RINNOVOMA_Pipeline::position( $user, $p->ID ) : array( 'seconds' => 0, 'duration' => 0, 'done' => false );
		$lock = ! RINNOVOMA_Access::has( $user, $i['plan'] ) && ! ( '' !== $i['tier'] && class_exists( 'RINNOVOMEM_Members' ) && RINNOVOMEM_Members::has( $user, $i['tier'] ) );
		$h    = '<article class="rinnovoma-card' . ( $lock ? ' is-locked' : '' ) . '"><a href="' . esc_url( get_permalink( $p ) ) . '" class="rinnovoma-card__thumb" style="aspect-ratio:' . esc_attr( $opts['ratio'] ) . '">' . ( has_post_thumbnail( $p ) ? get_the_post_thumbnail( $p, 'medium_large' ) : '' ) . ( $lock ? '<span class="rinnovoma-card__lock">🔒</span>' : '' ) . ( '' !== $i['duration'] ? '<span class="rinnovoma-card__time">' . esc_html( $i['duration'] ) . '</span>' : '' ) . ( $pos['duration'] ? '<span class="rinnovoma-card__bar" style="width:' . esc_attr( (string) min( 100, round( 100 * $pos['seconds'] / max( 1, $pos['duration'] ) ) ) ) . '%"></span>' : '' ) . '</a>';
		$h   .= '<h3 class="rinnovoma-card__title"><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></h3>';
		if ( $opts['desc'] && has_excerpt( $p ) ) {
			$h .= '<p class="rinnovoma-card__desc">' . esc_html( wp_trim_words( get_the_excerpt( $p ), 20 ) ) . '</p>';
		}
		return $h . '</article>';
	}

	/**
	 * [media_library type="video|audio|image|file" collection="slug" library="slug" layout="grid|list|carousel" columns="3" ratio="16:9" desc="yes" search="yes" per_page="24"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$a = shortcode_atts(
			array(
				'type'       => '',
				'collection' => '',
				'library'    => '',
				'layout'     => 'grid',
				'columns'    => 3,
				'ratio'      => '16:9',
				'desc'       => 'yes',
				'search'     => 'yes',
				'per_page'   => 24,
			),
			$atts,
			'media_library'
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters of a list.
		$q    = isset( $_GET['mq'] ) ? sanitize_text_field( wp_unslash( $_GET['mq'] ) ) : '';
		$coll = isset( $_GET['mcol'] ) ? sanitize_title( wp_unslash( $_GET['mcol'] ) ) : sanitize_title( $a['collection'] );
		$type = isset( $_GET['mtype'] ) ? sanitize_key( wp_unslash( $_GET['mtype'] ) ) : sanitize_key( $a['type'] );
		$page = isset( $_GET['mpage'] ) ? max( 1, absint( $_GET['mpage'] ) ) : 1;
		// phpcs:enable
		$args = array(
			'post_type'      => self::TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 100, (int) $a['per_page'] ) ),
			'paged'          => $page,
			's'              => $q,
			'tax_query'      => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'meta_query'     => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
		if ( '' !== $coll ) {
			$args['tax_query'][] = array( 'taxonomy' => self::COLL, 'field' => 'slug', 'terms' => $coll );
		}
		if ( '' !== $a['library'] ) {
			$args['tax_query'][] = array( 'taxonomy' => self::LIB, 'field' => 'slug', 'terms' => sanitize_title( $a['library'] ) );
		}
		if ( '' !== $type ) {
			$args['meta_query'][] = array( 'key' => '_rinnovoma_kind', 'value' => $type );
		}
		$query = new WP_Query( $args );
		$user  = get_current_user_id();
		$opts  = array(
			'ratio' => preg_match( '/^\d+:\d+$/', $a['ratio'] ) ? str_replace( ':', ' / ', $a['ratio'] ) : '16 / 9',
			'desc'  => 'no' !== $a['desc'],
		);
		$h     = '<div class="rinnovoma-library rinnovoma-library--' . esc_attr( sanitize_key( $a['layout'] ) ) . '" style="--cols:' . (int) $a['columns'] . '">';
		if ( 'no' !== $a['search'] ) {
			$h .= '<form method="get" class="rinnovoma-filter"><input type="search" name="mq" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search…', self::TD ) . '" /> <select name="mcol"><option value="">' . esc_html__( 'All collections', self::TD ) . '</option>';
			foreach ( get_terms( array( 'taxonomy' => self::COLL, 'hide_empty' => true ) ) as $t ) {
				$h .= '<option value="' . esc_attr( $t->slug ) . '"' . selected( $coll, $t->slug, false ) . '>' . esc_html( $t->name ) . '</option>';
			}
			$h .= '</select> <select name="mtype"><option value="">' . esc_html__( 'All types', self::TD ) . '</option>';
			foreach ( array( 'video' => __( 'Video', self::TD ), 'audio' => __( 'Audio', self::TD ), 'image' => __( 'Images', self::TD ), 'file' => __( 'Files', self::TD ) ) as $k => $l ) {
				$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $type, $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			$h .= '</select> <button class="button">' . esc_html__( 'Filter', self::TD ) . '</button></form>';
		}
		$h .= '<div class="rinnovoma-grid">';
		foreach ( $query->posts as $p ) {
			if ( $user && ! self::client_ok( $user, $p->ID ) && ! current_user_can( 'manage_woocommerce' ) ) {
				continue;
			}
			$h .= self::card( $p, $opts, $user );
		}
		if ( ! $query->posts ) {
			$h .= '<p>' . esc_html__( 'Nothing found.', self::TD ) . '</p>';
		}
		$h .= '</div>';
		if ( $query->max_num_pages > 1 ) {
			$h .= '<p class="rinnovoma-pages">';
			for ( $n = 1; $n <= $query->max_num_pages; $n++ ) {
				$h .= $n === $page ? ' <strong>' . (int) $n . '</strong>' : ' <a href="' . esc_url( add_query_arg( 'mpage', $n ) ) . '">' . (int) $n . '</a>';
			}
			$h .= '</p>';
		}
		return $h . '</div>';
	}

	/**
	 * [media_my]: continue watching, favourites, playlists and downloads of the member.
	 *
	 * @return string
	 */
	public static function my_shortcode() {
		$user = get_current_user_id();
		if ( ! $user ) {
			return '';
		}
		$opts = array(
			'ratio' => '16 / 9',
			'desc'  => false,
		);
		$h    = '<div class="rinnovoma-my">';
		$cont = RINNOVOMA_Pipeline::recent( $user, 8, true );
		if ( $cont ) {
			$h .= '<h3>' . esc_html__( 'Continue watching', self::TD ) . '</h3><div class="rinnovoma-grid rinnovoma-library--carousel">';
			foreach ( $cont as $r ) {
				$h .= self::card( get_post( $r['media'] ), $opts, $user );
			}
			$h .= '</div>';
		}
		$recent = RINNOVOMA_Pipeline::recent( $user, 8 );
		if ( $recent ) {
			$h .= '<h3>' . esc_html__( 'Recently viewed', self::TD ) . '</h3><ul>';
			foreach ( $recent as $r ) {
				$h .= '<li><a href="' . esc_url( (string) get_permalink( $r['media'] ) ) . '">' . esc_html( get_the_title( $r['media'] ) ) . '</a> <small>' . esc_html( human_time_diff( $r['at'] ) ) . '</small></li>';
			}
			$h .= '</ul>';
		}
		$mine = self::mine( $user );
		if ( $mine['favourites'] ) {
			$h .= '<h3>' . esc_html__( 'Favourites', self::TD ) . '</h3><ul>';
			foreach ( $mine['favourites'] as $id ) {
				$h .= get_post( $id ) ? '<li><a href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></li>' : '';
			}
			$h .= '</ul>';
		}
		foreach ( $mine['playlists'] as $name => $ids ) {
			$h .= '<h3>' . esc_html( $name ) . '</h3><ol>';
			foreach ( (array) $ids as $id ) {
				$h .= get_post( (int) $id ) ? '<li><a href="' . esc_url( (string) get_permalink( (int) $id ) ) . '">' . esc_html( get_the_title( (int) $id ) ) . '</a></li>' : '';
			}
			$h .= '</ol>';
		}
		$dl = RINNOVOMA_Pipeline::downloads( $user, 10 );
		if ( $dl ) {
			$h .= '<h3>' . esc_html__( 'Download history', self::TD ) . '</h3><ul>';
			foreach ( $dl as $r ) {
				$h .= '<li>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $r->created_at . ' UTC' ) ) ) . ' — ' . esc_html( get_the_title( (int) $r->media_id ) ) . '</li>';
			}
			$h .= '</ul>';
		}
		return $h . '</div>';
	}

	// ---------------------------------------------------------------- Usage add-ons.

	/**
	 * "Extra allowance" on products (sell more downloads or streaming hours).
	 */
	public static function extra_field() {
		woocommerce_wp_text_input(
			array(
				'id'          => '_rinnovoma_extra',
				'label'       => __( 'Media Archives extra', self::TD ),
				'placeholder' => 'downloads=50',
				'description' => __( 'Usage add-on: buying this adds to the buyer\'s quota for their current billing period, e.g. downloads=50 or stream_hours=10.', self::TD ),
				'desc_tip'    => true,
			)
		);
	}

	/**
	 * Save it.
	 *
	 * @param int $product_id Product.
	 */
	public static function save_extra_field( $product_id ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		update_post_meta( $product_id, '_rinnovoma_extra', isset( $_POST['_rinnovoma_extra'] ) ? sanitize_text_field( wp_unslash( $_POST['_rinnovoma_extra'] ) ) : '' );
	}

	/**
	 * Paid add-ons raise the buyer's quota (once per order line).
	 *
	 * @param int $order_id Order.
	 */
	public static function extra_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_customer_id() ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$rule = (string) get_post_meta( $item->get_product_id(), '_rinnovoma_extra', true );
			if ( '' === $rule || $item->get_meta( '_rinnovoma_extra_given' ) ) {
				continue;
			}
			$extra = get_user_meta( $order->get_customer_id(), '_rinnovoma_extra_quota', true );
			$extra = is_array( $extra ) ? $extra : array();
			foreach ( RINNOVOMA_Plans::parse_features( str_replace( ',', "\n", $rule ) ) as $k => $v ) {
				if ( is_int( $v ) ) {
					$extra[ $k ] = ( isset( $extra[ $k ] ) ? (int) $extra[ $k ] : 0 ) + $v * $item->get_quantity();
				}
			}
			update_user_meta( $order->get_customer_id(), '_rinnovoma_extra_quota', $extra );
			$item->update_meta_data( '_rinnovoma_extra_given', 1 );
			$item->save();
		}
	}
}
