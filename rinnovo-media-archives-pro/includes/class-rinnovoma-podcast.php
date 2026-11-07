<?php
/**
 * Private podcasts: Shows and Episodes (Podcasts menu), each subscriber gets their own RSS address per show
 * for Apple Podcasts, Overcast, Pocket Casts and other apps. Audio links in the feed are checked on every
 * play, so a feed stops working the moment the subscription ends: it then shows one "subscription ended"
 * item and no audio. Feeds used from too many IP addresses a day are flagged as shared.
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Podcasts.
 */
class RINNOVOMA_Podcast {

	const TD   = 'rinnovo-media-archives-pro';
	const TYPE = 'rinnovoma_episode';
	const SHOW = 'rinnovoma_show';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'feed' ), 30 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ) );
		add_action( self::SHOW . '_add_form_fields', array( __CLASS__, 'show_fields_add' ) );
		add_action( self::SHOW . '_edit_form_fields', array( __CLASS__, 'show_fields_edit' ) );
		add_action( 'created_' . self::SHOW, array( __CLASS__, 'save_show' ) );
		add_action( 'edited_' . self::SHOW, array( __CLASS__, 'save_show' ) );
		add_filter( 'the_content', array( __CLASS__, 'player' ), 8 );
	}

	/**
	 * Episodes and Shows.
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Podcast episodes', self::TD ),
					'singular_name' => __( 'Episode', self::TD ),
					'menu_name'     => __( 'Podcasts', self::TD ),
					'add_new_item'  => __( 'Add episode', self::TD ),
					'edit_item'     => __( 'Edit episode', self::TD ),
				),
				'public'       => true,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'episodes' ),
				'menu_icon'    => 'dashicons-microphone',
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			)
		);
		register_taxonomy(
			self::SHOW,
			self::TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Shows', self::TD ),
					'singular_name' => __( 'Show', self::TD ),
					'add_new_item'  => __( 'Add show', self::TD ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'show' ),
			)
		);
	}

	/**
	 * Episode media box.
	 */
	public static function meta_box() {
		add_meta_box( 'rinnovoma_episode', __( 'Episode audio', self::TD ), array( __CLASS__, 'meta_box_content' ), self::TYPE, 'normal', 'high' );
	}

	/**
	 * Its fields.
	 *
	 * @param WP_Post $post Episode.
	 */
	public static function meta_box_content( $post ) {
		wp_nonce_field( 'rinnovoma_episode', 'rinnovoma_episode_nonce' );
		$get = static function ( $key ) use ( $post ) {
			return (string) get_post_meta( $post->ID, '_rinnovoma_' . $key, true );
		};
		$providers = array(
			'file'       => __( 'Protected file from the Media Library (attachment ID)', self::TD ),
			'bunnycdn'   => __( 'Bunny CDN (path in the pull zone)', self::TD ),
			'cloudfront' => __( 'Amazon CloudFront (path)', self::TD ),
			's3'         => __( 'Amazon S3 (object key)', self::TD ),
		);
		?>
		<p><label><?php esc_html_e( 'Where the audio is', self::TD ); ?><br /><select name="rinnovoma_provider">
			<?php foreach ( $providers as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $get( 'provider' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select></label></p>
		<p><label><?php esc_html_e( 'File (ID, path or key)', self::TD ); ?><br /><input type="text" class="large-text" name="rinnovoma_media" value="<?php echo esc_attr( $get( 'media' ) ); ?>" placeholder="/season-1/episode-01.mp3" /></label></p>
		<p>
			<label><?php esc_html_e( 'Duration', self::TD ); ?> <input type="text" name="rinnovoma_duration" value="<?php echo esc_attr( $get( 'duration' ) ); ?>" placeholder="00:42:10" size="10" /></label>
			<label><?php esc_html_e( 'Size (bytes)', self::TD ); ?> <input type="number" name="rinnovoma_bytes" value="<?php echo esc_attr( $get( 'bytes' ) ); ?>" /></label>
			<label><?php esc_html_e( 'Season', self::TD ); ?> <input type="number" name="rinnovoma_season" value="<?php echo esc_attr( $get( 'season' ) ); ?>" style="width:70px" /></label>
			<label><?php esc_html_e( 'Episode', self::TD ); ?> <input type="number" name="rinnovoma_number" value="<?php echo esc_attr( $get( 'number' ) ); ?>" style="width:70px" /></label>
		</p>
		<p><label><?php esc_html_e( 'Who can listen', self::TD ); ?> <select name="rinnovoma_plan"><option value=""><?php esc_html_e( 'The show\'s plan', self::TD ); ?></option>
			<?php foreach ( RINNOVOMA_Plans::choices() as $id => $label ) : ?>
				<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $get( 'plan' ), $id ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select></label></p>
		<?php
	}

	/**
	 * Save an episode.
	 *
	 * @param int $post_id Episode.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST['rinnovoma_episode_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovoma_episode_nonce'] ) ), 'rinnovoma_episode' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array( 'provider', 'media', 'duration', 'bytes', 'season', 'number', 'plan' ) as $key ) {
			update_post_meta( $post_id, '_rinnovoma_' . $key, isset( $_POST[ 'rinnovoma_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'rinnovoma_' . $key ] ) ) : '' );
		}
	}

	/**
	 * Show fields (add form).
	 */
	public static function show_fields_add() {
		self::show_fields( 0 );
	}

	/**
	 * Show fields (edit form).
	 *
	 * @param WP_Term $term Show.
	 */
	public static function show_fields_edit( $term ) {
		echo '<tr class="form-field"><th>' . esc_html__( 'Podcast', self::TD ) . '</th><td>';
		self::show_fields( $term->term_id );
		echo '</td></tr>';
	}

	/**
	 * The fields.
	 *
	 * @param int $term_id Show.
	 */
	private static function show_fields( $term_id ) {
		wp_nonce_field( 'rinnovoma_show', 'rinnovoma_show_nonce' );
		$get = static function ( $key ) use ( $term_id ) {
			return $term_id ? (string) get_term_meta( $term_id, '_rinnovoma_' . $key, true ) : '';
		};
		?>
		<div class="form-field">
			<p><label><?php esc_html_e( 'Plan needed', self::TD ); ?><br /><select name="rinnovoma_show_plan">
				<?php foreach ( RINNOVOMA_Plans::choices() as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $get( 'plan' ), $id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select></label></p>
			<p><label><?php esc_html_e( 'Cover art URL (1400–3000 px square)', self::TD ); ?><br /><input type="url" name="rinnovoma_show_image" value="<?php echo esc_attr( $get( 'image' ) ); ?>" /></label></p>
			<p><label><?php esc_html_e( 'Author', self::TD ); ?><br /><input type="text" name="rinnovoma_show_author" value="<?php echo esc_attr( $get( 'author' ) ); ?>" /></label></p>
			<p><label><?php esc_html_e( 'Apple Podcasts category', self::TD ); ?><br /><input type="text" name="rinnovoma_show_category" value="<?php echo esc_attr( $get( 'category' ) ); ?>" placeholder="Business" /></label></p>
			<p><label><input type="checkbox" name="rinnovoma_show_explicit" value="1" <?php checked( '1', $get( 'explicit' ) ); ?> /> <?php esc_html_e( 'Explicit', self::TD ); ?></label></p>
		</div>
		<?php
	}

	/**
	 * Save show fields.
	 *
	 * @param int $term_id Show.
	 */
	public static function save_show( $term_id ) {
		if ( ! isset( $_POST['rinnovoma_show_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovoma_show_nonce'] ) ), 'rinnovoma_show' ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		update_term_meta( $term_id, '_rinnovoma_plan', isset( $_POST['rinnovoma_show_plan'] ) ? sanitize_key( wp_unslash( $_POST['rinnovoma_show_plan'] ) ) : 'any' );
		update_term_meta( $term_id, '_rinnovoma_image', isset( $_POST['rinnovoma_show_image'] ) ? esc_url_raw( wp_unslash( $_POST['rinnovoma_show_image'] ) ) : '' );
		update_term_meta( $term_id, '_rinnovoma_author', isset( $_POST['rinnovoma_show_author'] ) ? sanitize_text_field( wp_unslash( $_POST['rinnovoma_show_author'] ) ) : '' );
		update_term_meta( $term_id, '_rinnovoma_category', isset( $_POST['rinnovoma_show_category'] ) ? sanitize_text_field( wp_unslash( $_POST['rinnovoma_show_category'] ) ) : '' );
		update_term_meta( $term_id, '_rinnovoma_explicit', ! empty( $_POST['rinnovoma_show_explicit'] ) ? '1' : '' );
	}

	/**
	 * Plan an episode needs.
	 *
	 * @param int $post_id Episode.
	 * @return string
	 */
	public static function plan_of( $post_id ) {
		$own = (string) get_post_meta( $post_id, '_rinnovoma_plan', true );
		if ( '' !== $own ) {
			return $own;
		}
		$shows = get_the_terms( $post_id, self::SHOW );
		$best  = 'any';
		foreach ( is_array( $shows ) ? $shows : array() as $show ) {
			$plan = (string) get_term_meta( $show->term_id, '_rinnovoma_plan', true );
			if ( '' !== $plan && RINNOVOMA_Plans::rank( $plan ) > RINNOVOMA_Plans::rank( $best ) ) {
				$best = $plan;
			}
		}
		return $best;
	}

	/**
	 * Player on episode pages.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function player( $content ) {
		if ( ! is_singular( self::TYPE ) || ! in_the_loop() ) {
			return $content;
		}
		$id = get_the_ID();
		return do_shortcode( '[media_audio provider="' . esc_attr( get_post_meta( $id, '_rinnovoma_provider', true ) ) . '" id="' . esc_attr( get_post_meta( $id, '_rinnovoma_media', true ) ) . '" plan="' . esc_attr( self::plan_of( $id ) ) . '"]' ) . $content;
	}

	/**
	 * A subscriber's feed token (created when first needed).
	 *
	 * @param int  $user_id User.
	 * @param bool $renew   Make a new one (old feed addresses stop working).
	 * @return string
	 */
	public static function token( $user_id, $renew = false ) {
		$token = (string) get_user_meta( $user_id, '_rinnovoma_feed_token', true );
		if ( '' === $token || $renew ) {
			$token = strtolower( wp_generate_password( 32, false ) );
			update_user_meta( $user_id, '_rinnovoma_feed_token', $token );
			delete_user_meta( $user_id, '_rinnovoma_feed_ips' );
		}
		return $token;
	}

	/**
	 * A subscriber's private feed address for a show.
	 *
	 * @param int     $user_id User.
	 * @param WP_Term $show    Show.
	 * @return string
	 */
	public static function feed_url( $user_id, $show ) {
		return add_query_arg(
			array(
				'rinnovoma_feed' => self::token( $user_id ),
				'show'           => $show->slug,
			),
			home_url( '/' )
		);
	}

	/**
	 * Note the IP address of a feed request, and flag feeds used from too many places.
	 *
	 * @param int $user_id User.
	 */
	private static function track( $user_id ) {
		$day  = gmdate( 'Y-m-d' );
		$ips  = (array) get_user_meta( $user_id, '_rinnovoma_feed_ips', true );
		$ips  = isset( $ips[ $day ] ) ? array( $day => (array) $ips[ $day ] ) : array( $day => array() );
		$ip   = RINNOVOMA_Security::ip();
		if ( ! in_array( $ip, $ips[ $day ], true ) ) {
			$ips[ $day ][] = $ip;
			update_user_meta( $user_id, '_rinnovoma_feed_ips', $ips );
			$limit = (int) RINNOVOMA_Plans::get( 'feed_ip_limit' );
			if ( $limit > 0 && count( $ips[ $day ] ) === $limit + 1 ) {
				/* translators: %d: number of IP addresses. */
				RINNOVOMA_Security::alert( $user_id, 'feed_shared', sprintf( __( 'Private podcast feed used from %d IP addresses today. It may have been shared; renew the feed address in the customer\'s portal if needed.', self::TD ), count( $ips[ $day ] ) ) );
			}
		}
	}

	/**
	 * Serve a private feed.
	 */
	public static function feed() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The token is the secret.
		if ( empty( $_GET['rinnovoma_feed'] ) ) {
			return;
		}
		$token = sanitize_key( wp_unslash( $_GET['rinnovoma_feed'] ) );
		$slug  = isset( $_GET['show'] ) ? sanitize_title( wp_unslash( $_GET['show'] ) ) : '';
		// phpcs:enable
		$users = get_users(
			array(
				'meta_key'   => '_rinnovoma_feed_token', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		$show  = get_term_by( 'slug', $slug, self::SHOW );
		if ( strlen( $token ) < 20 || ! $users || ! $show ) {
			status_header( 404 );
			exit;
		}
		$user_id = (int) $users[0];
		self::track( $user_id );
		$plan    = (string) get_term_meta( $show->term_id, '_rinnovoma_plan', true );
		$allowed = RINNOVOMA_Access::has( $user_id, '' !== $plan ? $plan : 'any' );
		$image   = (string) get_term_meta( $show->term_id, '_rinnovoma_image', true );
		$author  = (string) get_term_meta( $show->term_id, '_rinnovoma_author', true );
		$cat     = (string) get_term_meta( $show->term_id, '_rinnovoma_category', true );

		nocache_headers();
		header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:podcast="https://podcastindex.org/namespace/1.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>' . "\n";
		echo '<title>' . esc_html( $show->name ) . '</title><link>' . esc_url( get_term_link( $show ) ) . '</link><language>' . esc_html( get_bloginfo( 'language' ) ) . '</language>';
		echo '<description>' . esc_html( wp_strip_all_tags( $show->description ) ) . '</description>';
		echo '<itunes:block>Yes</itunes:block><podcast:locked>yes</podcast:locked>';
		echo '' !== $author ? '<itunes:author>' . esc_html( $author ) . '</itunes:author>' : '';
		echo '' !== $image ? '<itunes:image href="' . esc_url( $image ) . '" /><image><url>' . esc_url( $image ) . '</url><title>' . esc_html( $show->name ) . '</title><link>' . esc_url( get_term_link( $show ) ) . '</link></image>' : '';
		echo '' !== $cat ? '<itunes:category text="' . esc_attr( $cat ) . '" />' : '';
		echo '<itunes:explicit>' . ( get_term_meta( $show->term_id, '_rinnovoma_explicit', true ) ? 'true' : 'false' ) . '</itunes:explicit>' . "\n";

		if ( ! $allowed ) {
			// The feed stays valid for podcast apps, but has no audio any more.
			$renew = wc_get_account_endpoint_url( RINNOVO_Account::endpoint() );
			echo '<item><title>' . esc_html__( 'Your subscription has ended', self::TD ) . '</title><guid isPermaLink="false">rinnovoma-ended-' . (int) $user_id . '</guid><pubDate>' . esc_html( gmdate( DATE_RSS ) ) . '</pubDate><description>' . esc_html( sprintf( /* translators: %s: link. */ __( 'Renew to keep listening: %s', self::TD ), $renew ) ) . '</description><link>' . esc_url( $renew ) . '</link></item>';
			echo '</channel></rss>';
			exit;
		}
		$episodes = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => self::SHOW,
						'field'    => 'term_id',
						'terms'    => $show->term_id,
					),
				),
			)
		);
		// Links stay the same for a month (so apps don't download again), and are checked on every play.
		$ttl = (int) strtotime( 'first day of next month 00:00 UTC' ) + 7 * DAY_IN_SECONDS - time();
		foreach ( $episodes as $ep ) {
			if ( ! RINNOVOMA_Access::has( $user_id, self::plan_of( $ep->ID ) ) ) {
				continue;
			}
			$provider = (string) get_post_meta( $ep->ID, '_rinnovoma_provider', true );
			$media    = (string) get_post_meta( $ep->ID, '_rinnovoma_media', true );
			if ( '' === $media ) {
				continue;
			}
			$url  = RINNOVOMA_Player::media_link( $provider, $media, $user_id, $ttl, 'feed' );
			$mime = 'audio/mpeg';
			if ( 'file' === $provider ) {
				$type = wp_check_filetype( (string) get_attached_file( absint( $media ) ) );
				$mime = $type['type'] ? $type['type'] : $mime;
			}
			echo '<item><title>' . esc_html( get_the_title( $ep ) ) . '</title><guid isPermaLink="false">rinnovoma-' . (int) $ep->ID . '</guid>';
			echo '<pubDate>' . esc_html( get_post_time( DATE_RSS, true, $ep ) ) . '</pubDate>';
			echo '<description>' . esc_html( wp_strip_all_tags( get_the_excerpt( $ep ) ) ) . '</description>';
			echo '<content:encoded><![CDATA[' . wp_kses_post( wpautop( $ep->post_content ) ) . ']]></content:encoded>';
			echo '<enclosure url="' . esc_url( $url ) . '" length="' . (int) get_post_meta( $ep->ID, '_rinnovoma_bytes', true ) . '" type="' . esc_attr( $mime ) . '" />';
			$duration = (string) get_post_meta( $ep->ID, '_rinnovoma_duration', true );
			echo '' !== $duration ? '<itunes:duration>' . esc_html( $duration ) . '</itunes:duration>' : '';
			echo get_post_meta( $ep->ID, '_rinnovoma_season', true ) ? '<itunes:season>' . (int) get_post_meta( $ep->ID, '_rinnovoma_season', true ) . '</itunes:season>' : '';
			echo get_post_meta( $ep->ID, '_rinnovoma_number', true ) ? '<itunes:episode>' . (int) get_post_meta( $ep->ID, '_rinnovoma_number', true ) . '</itunes:episode>' : '';
			if ( has_post_thumbnail( $ep ) ) {
				echo '<itunes:image href="' . esc_url( (string) get_the_post_thumbnail_url( $ep, 'full' ) ) . '" />';
			}
			echo "</item>\n";
		}
		echo '</channel></rss>';
		exit;
	}
}
