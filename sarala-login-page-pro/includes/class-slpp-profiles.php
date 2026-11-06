<?php
/**
 * Profiles: custom profile pictures (avatars), and control over disconnecting social accounts.
 *
 * @package SaralaLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Custom avatars and social account unlink rules.
 */
class SLPP_Profiles {

	const OPTION = 'slpp_profiles';
	const PAGE   = 'sarala-login-page-profiles';
	const META   = '_slpp_avatar_id';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 23 );
		add_action( 'admin_post_slpp_profiles', array( __CLASS__, 'handle_form' ) );

		$settings = self::settings();
		if ( $settings['avatars'] ) {
			add_filter( 'pre_get_avatar_data', array( __CLASS__, 'avatar_data' ), 10, 2 );
			add_action( 'user_edit_form_tag', array( __CLASS__, 'form_enctype' ) );
			add_filter( 'user_profile_picture_description', array( __CLASS__, 'core_picture_text' ) );
			add_action( 'show_user_profile', array( __CLASS__, 'profile_field' ), 5 );
			add_action( 'edit_user_profile', array( __CLASS__, 'profile_field' ), 5 );
			add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
			add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
			if ( $settings['avatar_wc'] ) {
				add_action( 'woocommerce_edit_account_form_tag', array( __CLASS__, 'form_enctype' ) );
				add_action( 'woocommerce_edit_account_form_start', array( __CLASS__, 'wc_field' ) );
				add_action( 'woocommerce_save_account_details', array( __CLASS__, 'save_wc' ) );
			}
		}

		add_filter( 'saralopa_can_unlink', array( __CLASS__, 'can_unlink' ), 10, 3 );
		add_action( 'saralopa_unlinked', array( __CLASS__, 'log_unlink' ), 10, 2 );
		add_filter( 'bulk_actions-users', array( __CLASS__, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-users', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'avatars'       => 1,
				'avatar_wc'     => 1,
				'avatar_max_mb' => 2,
				'unlink'        => 'everyone',
				'protect_last'  => 1,
			)
		);
	}

	/*
	 * Profile pictures.
	 */

	/**
	 * Use the uploaded picture instead of Gravatar.
	 *
	 * @param array $args        Avatar arguments.
	 * @param mixed $id_or_email User ID, email, WP_User, WP_Post or WP_Comment.
	 * @return array
	 */
	public static function avatar_data( $args, $id_or_email ) {
		$user_id = 0;
		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( $id_or_email instanceof WP_User ) {
			$user_id = $id_or_email->ID;
		} elseif ( $id_or_email instanceof WP_Post ) {
			$user_id = (int) $id_or_email->post_author;
		} elseif ( $id_or_email instanceof WP_Comment ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user    = get_user_by( 'email', $id_or_email );
			$user_id = $user ? $user->ID : 0;
		}
		$attachment = $user_id ? (int) get_user_meta( $user_id, self::META, true ) : 0;
		if ( ! $attachment ) {
			return $args;
		}
		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		$url  = wp_get_attachment_image_url( $attachment, array( $size * 2, $size * 2 ) );
		if ( $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}
		return $args;
	}

	/**
	 * WordPress's own "Profile Picture" row points to Gravatar; point to the upload field instead.
	 *
	 * @return string
	 */
	public static function core_picture_text() {
		return esc_html__( 'Upload your own picture under "Profile picture" below.', 'sarala-login-page-pro' );
	}

	/**
	 * Let the profile forms send files.
	 */
	public static function form_enctype() {
		echo ' enctype="multipart/form-data"';
	}

	/**
	 * Picture field on Users > Profile.
	 *
	 * @param WP_User $user User being edited.
	 */
	public static function profile_field( $user ) {
		?>
		<h2><?php esc_html_e( 'Profile picture', 'sarala-login-page-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="slpp-avatar"><?php esc_html_e( 'Your picture', 'sarala-login-page-pro' ); ?></label></th>
				<td><?php self::field_body( $user->ID ); ?></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Picture field on WooCommerce's account details form.
	 */
	public static function wc_field() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		?>
		<fieldset class="slpp-avatar-field">
			<legend><?php esc_html_e( 'Profile picture', 'sarala-login-page-pro' ); ?></legend>
			<?php self::field_body( $user_id ); ?>
		</fieldset>
		<?php
	}

	/**
	 * Current picture, file input and "remove" option.
	 *
	 * @param int $user_id User ID.
	 */
	private static function field_body( $user_id ) {
		$max = (int) self::settings()['avatar_max_mb'];
		echo get_avatar( $user_id, 96, '', '', array( 'class' => 'slpp-avatar-preview' ) );
		?>
		<p>
			<input type="file" name="slpp_avatar" id="slpp-avatar" accept="image/jpeg,image/png,image/webp,image/gif" />
			<?php wp_nonce_field( 'slpp_avatar_' . $user_id, 'slpp_avatar_nonce', false ); ?>
		</p>
		<?php if ( get_user_meta( $user_id, self::META, true ) ) : ?>
			<p><label><input type="checkbox" name="slpp_avatar_remove" value="1" /> <?php esc_html_e( 'Remove my picture', 'sarala-login-page-pro' ); ?></label></p>
		<?php endif; ?>
		<p class="description">
			<?php
			printf(
				/* translators: %d: maximum size in MB. */
				esc_html__( 'JPG, PNG, WebP or GIF, up to %d MB. A square picture looks best. Without one, your Gravatar is used.', 'sarala-login-page-pro' ),
				(int) $max
			);
			?>
		</p>
		<?php
	}

	/**
	 * Save from Users > Profile (WordPress has checked its own nonce and the right to edit).
	 *
	 * @param int $user_id User ID.
	 */
	public static function save_profile( $user_id ) {
		if ( current_user_can( 'edit_user', $user_id ) ) {
			$result = self::save( $user_id );
			if ( is_wp_error( $result ) ) {
				add_action(
					'user_profile_update_errors',
					static function ( $errors ) use ( $result ) {
						$errors->add( $result->get_error_code(), $result->get_error_message() );
					}
				);
			}
		}
	}

	/**
	 * Save from WooCommerce's account details form (WooCommerce has checked its own nonce).
	 *
	 * @param int $user_id User ID.
	 */
	public static function save_wc( $user_id ) {
		if ( get_current_user_id() !== (int) $user_id ) {
			return;
		}
		$result = self::save( $user_id );
		if ( is_wp_error( $result ) && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		}
	}

	/**
	 * Store an uploaded picture, or remove the current one.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private static function save( $user_id ) {
		$nonce = isset( $_POST['slpp_avatar_nonce'] ) ? sanitize_key( wp_unslash( $_POST['slpp_avatar_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'slpp_avatar_' . $user_id ) ) {
			return true;
		}
		if ( ! empty( $_POST['slpp_avatar_remove'] ) ) {
			self::delete( $user_id );
		}
		if ( empty( $_FILES['slpp_avatar']['name'] ) || ! isset( $_FILES['slpp_avatar']['tmp_name'], $_FILES['slpp_avatar']['size'] ) ) {
			return true;
		}

		$max = max( 1, (int) self::settings()['avatar_max_mb'] ) * MB_IN_BYTES;
		if ( (int) $_FILES['slpp_avatar']['size'] > $max ) {
			return new WP_Error( 'slpp_avatar_size', __( 'The picture is too large.', 'sarala-login-page-pro' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$mimes  = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
			'gif'          => 'image/gif',
		);
		$upload = wp_handle_upload(
			$_FILES['slpp_avatar'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_handle_upload() checks the file.
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);
		if ( isset( $upload['error'] ) ) {
			return new WP_Error( 'slpp_avatar_upload', $upload['error'] );
		}
		if ( ! wp_getimagesize( $upload['file'] ) ) {
			wp_delete_file( $upload['file'] );
			return new WP_Error( 'slpp_avatar_type', __( 'Please choose an image file.', 'sarala-login-page-pro' ) );
		}

		$attachment = wp_insert_attachment(
			array(
				'post_mime_type' => $upload['type'],
				/* translators: %s: user's display name. */
				'post_title'     => sprintf( __( 'Profile picture of %s', 'sarala-login-page-pro' ), get_userdata( $user_id )->display_name ),
				'post_status'    => 'inherit',
				'post_author'    => $user_id,
			),
			$upload['file']
		);
		if ( is_wp_error( $attachment ) || ! $attachment ) {
			wp_delete_file( $upload['file'] );
			return new WP_Error( 'slpp_avatar_upload', __( 'The picture could not be saved.', 'sarala-login-page-pro' ) );
		}
		wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $upload['file'] ) );
		update_post_meta( $attachment, '_slpp_avatar_of', $user_id );

		self::delete( $user_id );
		update_user_meta( $user_id, self::META, $attachment );
		return true;
	}

	/**
	 * Remove a user's picture and its file.
	 *
	 * @param int $user_id User ID.
	 */
	private static function delete( $user_id ) {
		$old = (int) get_user_meta( $user_id, self::META, true );
		// Only delete images this feature uploaded, never something picked from the media library.
		if ( $old && (int) get_post_meta( $old, '_slpp_avatar_of', true ) === (int) $user_id ) {
			wp_delete_attachment( $old, true );
		}
		delete_user_meta( $user_id, self::META );
	}

	/*
	 * Disconnecting social accounts.
	 */

	/**
	 * Whether the user can log in without social accounts: a password they chose.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function has_own_password( $user_id ) {
		// Sarala marks accounts created by a social sign-up; their random password is unknown to the user.
		if ( ! get_user_meta( $user_id, '_saralopa_registered_with', true ) ) {
			return true;
		}
		$changed = (int) get_user_meta( $user_id, '_saralopa_password_changed', true );
		$joined  = strtotime( get_userdata( $user_id )->user_registered . ' UTC' );
		return $changed && $changed > $joined + MINUTE_IN_SECONDS;
	}

	/**
	 * Apply the unlink rules.
	 *
	 * @param bool   $allowed  Allowed so far.
	 * @param int    $user_id  User the account belongs to.
	 * @param string $provider Provider ID.
	 * @return bool
	 */
	public static function can_unlink( $allowed, $user_id, $provider ) {
		if ( ! $allowed ) {
			return false;
		}
		$settings = self::settings();
		$is_admin = current_user_can( 'edit_users' ) && get_current_user_id() !== (int) $user_id;

		if ( 'nobody' === $settings['unlink'] && ! SLPP_Owner::current_user_is_boss() ) {
			return false;
		}
		if ( 'admins' === $settings['unlink'] && ! $is_admin ) {
			return false;
		}
		if ( $settings['protect_last'] && ! $is_admin && class_exists( 'SARALOPA_Account' ) ) {
			$linked = SARALOPA_Account::linked( $user_id );
			if ( 1 >= count( $linked ) && ! self::has_own_password( $user_id ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Note the disconnection in the activity log.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $provider Provider ID.
	 */
	public static function log_unlink( $user_id, $provider ) {
		if ( class_exists( 'SLPP_Activity' ) && is_callable( array( 'SLPP_Activity', 'log' ) ) ) {
			$user = get_userdata( $user_id );
			SLPP_Activity::log( 'social_unlinked', SARALOPA_Providers::label( $provider ) . ( $user ? ' (' . $user->display_name . ')' : '' ), 'user', $user_id );
		}
	}

	/**
	 * Bulk action on the Users screen.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function bulk_action( $actions ) {
		if ( current_user_can( 'edit_users' ) ) {
			$actions['slpp_unlink_social'] = __( 'Disconnect social accounts', 'sarala-login-page-pro' );
		}
		return $actions;
	}

	/**
	 * Run the bulk action (WordPress has checked the nonce).
	 *
	 * @param string $redirect Return address.
	 * @param string $action   Action.
	 * @param int[]  $user_ids Users.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $user_ids ) {
		if ( 'slpp_unlink_social' !== $action || ! current_user_can( 'edit_users' ) ) {
			return $redirect;
		}
		$count = 0;
		foreach ( array_map( 'intval', (array) $user_ids ) as $user_id ) {
			if ( ! current_user_can( 'edit_user', $user_id ) ) {
				continue;
			}
			foreach ( array_keys( SARALOPA_Account::linked( $user_id ) ) as $provider ) {
				SARALOPA_Auth::unlink( $user_id, $provider );
				/** This action is documented in sarala-login-page/includes/class-saralopa-account.php. */
				do_action( 'saralopa_unlinked', $user_id, $provider ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The free plugin's hook, so its listeners run too.
				++$count;
			}
		}
		return add_query_arg(
			array(
				'slpp_unlinked' => $count,
				'_slppnonce'    => wp_create_nonce( 'slpp_unlinked' ),
			),
			$redirect
		);
	}

	/**
	 * Result of the bulk action.
	 */
	public static function bulk_notice() {
		$nonce = isset( $_GET['_slppnonce'] ) ? sanitize_key( wp_unslash( $_GET['_slppnonce'] ) ) : '';
		if ( ! isset( $_GET['slpp_unlinked'] ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'slpp_unlinked' ) ) {
			return;
		}
		$count = absint( $_GET['slpp_unlinked'] );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of social accounts. */
					_n( '%d social account disconnected.', '%d social accounts disconnected.', $count, 'sarala-login-page-pro' ),
					$count
				)
			)
		);
	}

	/*
	 * Settings screen.
	 */

	/**
	 * "Profiles" submenu.
	 */
	public static function menu() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		add_submenu_page(
			'sarala-login-page',
			__( 'Profiles & social accounts', 'sarala-login-page-pro' ),
			__( 'Profiles', 'sarala-login-page-pro' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Save the settings.
	 */
	public static function handle_form() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sarala-login-page-pro' ) );
		}
		check_admin_referer( 'slpp_profiles' );
		$unlink = isset( $_POST['unlink'] ) ? sanitize_key( wp_unslash( $_POST['unlink'] ) ) : 'everyone';
		update_option(
			self::OPTION,
			array(
				'avatars'       => empty( $_POST['avatars'] ) ? 0 : 1,
				'avatar_wc'     => empty( $_POST['avatar_wc'] ) ? 0 : 1,
				'avatar_max_mb' => isset( $_POST['avatar_max_mb'] ) ? min( 20, max( 1, absint( $_POST['avatar_max_mb'] ) ) ) : 2,
				'unlink'        => in_array( $unlink, array( 'everyone', 'admins', 'nobody' ), true ) ? $unlink : 'everyone',
				'protect_last'  => empty( $_POST['protect_last'] ) ? 0 : 1,
			),
			false
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE,
					'saved'      => 1,
					'_slppnonce' => wp_create_nonce( 'slpp_profiles_view' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The settings screen.
	 */
	public static function page() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		$nonce = isset( $_GET['_slppnonce'] ) ? sanitize_key( wp_unslash( $_GET['_slppnonce'] ) ) : '';
		$saved = '' !== $nonce && wp_verify_nonce( $nonce, 'slpp_profiles_view' ) && ! empty( $_GET['saved'] );
		$s     = self::settings();
		?>
		<div class="wrap saralopa-admin slpp-profiles">
			<h1><?php esc_html_e( 'Profiles & social accounts', 'sarala-login-page-pro' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'sarala-login-page-pro' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="slpp_profiles" />
				<?php wp_nonce_field( 'slpp_profiles' ); ?>

				<div class="saralopa-card">
					<h2><?php esc_html_e( 'Profile pictures', 'sarala-login-page-pro' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Users upload their own picture, shown everywhere WordPress shows an avatar (comments, admin bar, author boxes, the login widget). Users without one keep their Gravatar.', 'sarala-login-page-pro' ); ?></p>
					<p><label><input type="checkbox" name="avatars" value="1" <?php checked( 1, (int) $s['avatars'] ); ?> /> <?php esc_html_e( 'Let users upload a profile picture on Users > Profile', 'sarala-login-page-pro' ); ?></label></p>
					<p><label><input type="checkbox" name="avatar_wc" value="1" <?php checked( 1, (int) $s['avatar_wc'] ); ?> /> <?php esc_html_e( 'Also on WooCommerce My Account > Account details', 'sarala-login-page-pro' ); ?></label></p>
					<p><label><?php esc_html_e( 'Largest picture', 'sarala-login-page-pro' ); ?> <input type="number" min="1" max="20" class="small-text" name="avatar_max_mb" value="<?php echo esc_attr( $s['avatar_max_mb'] ); ?>" /> MB</label></p>
				</div>

				<div class="saralopa-card">
					<h2><?php esc_html_e( 'Disconnecting social accounts', 'sarala-login-page-pro' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Who may remove a connected Google, Facebook, Apple, Microsoft, LinkedIn or X account from a user.', 'sarala-login-page-pro' ); ?></p>
					<?php
					$choices = array(
						'everyone' => __( 'Users (their own accounts) and administrators', 'sarala-login-page-pro' ),
						'admins'   => __( 'Administrators only', 'sarala-login-page-pro' ),
						'nobody'   => __( 'Site administrators only: other users and admins can\'t disconnect accounts', 'sarala-login-page-pro' ),
					);
					foreach ( $choices as $value => $label ) :
						?>
						<p><label><input type="radio" name="unlink" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s['unlink'], $value ); ?> /> <?php echo esc_html( $label ); ?></label></p>
					<?php endforeach; ?>
					<p><label><input type="checkbox" name="protect_last" value="1" <?php checked( 1, (int) $s['protect_last'] ); ?> /> <?php esc_html_e( 'Don\'t let users disconnect their last social account if they signed up with it and never set a password (they would be locked out)', 'sarala-login-page-pro' ); ?></label></p>
					<p class="description"><?php esc_html_e( 'Administrators can disconnect accounts for many users at once on the Users screen: tick them, then Bulk actions > Disconnect social accounts. Every disconnection is recorded in the activity log.', 'sarala-login-page-pro' ); ?></p>
				</div>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
