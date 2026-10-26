<?php
/**
 * Admin screens: Languages, Copy & translate, Menus, Texts, Switcher, Settings.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class Vacanam_Admin {

	const SLUG = 'vacanam';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VACANAM_FILE ), array( __CLASS__, 'action_links' ) );
		foreach ( array( 'add_language', 'edit_language', 'delete_language', 'default_language', 'move_language', 'assign_all', 'save_settings', 'save_switcher', 'save_strings', 'save_menus', 'copy_menu' ) as $action ) {
			add_action( 'admin_post_vacanam_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Capability.
	 *
	 * @return string
	 */
	public static function cap() {
		/**
		 * Capability to manage languages.
		 *
		 * @param string $cap Capability.
		 */
		return apply_filters( 'vacanam_capability', 'manage_options' );
	}

	/**
	 * Admin menu.
	 */
	public static function menu() {
		$cap = self::cap();
		add_menu_page( __( 'Languages', 'vacanam-language-switcher' ), __( 'Languages', 'vacanam-language-switcher' ), $cap, self::SLUG, array( __CLASS__, 'page_languages' ), 'dashicons-translation', 71 );
		add_submenu_page( self::SLUG, __( 'Languages', 'vacanam-language-switcher' ), __( 'Languages', 'vacanam-language-switcher' ), $cap, self::SLUG, array( __CLASS__, 'page_languages' ) );
		add_submenu_page( self::SLUG, __( 'Copy into a language', 'vacanam-language-switcher' ), __( 'Copy & translate', 'vacanam-language-switcher' ), $cap, 'vacanam-copy', array( __CLASS__, 'page_copy' ) );
		add_submenu_page( self::SLUG, __( 'Menus per language', 'vacanam-language-switcher' ), __( 'Menus', 'vacanam-language-switcher' ), $cap, 'vacanam-menus', array( __CLASS__, 'page_menus' ) );
		add_submenu_page( self::SLUG, __( 'Translate texts', 'vacanam-language-switcher' ), __( 'Texts', 'vacanam-language-switcher' ), $cap, 'vacanam-strings', array( __CLASS__, 'page_strings' ) );
		add_submenu_page( self::SLUG, __( 'Language switcher', 'vacanam-language-switcher' ), __( 'Switcher', 'vacanam-language-switcher' ), $cap, 'vacanam-switcher', array( __CLASS__, 'page_switcher' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'vacanam-language-switcher' ), __( 'Settings', 'vacanam-language-switcher' ), $cap, 'vacanam-settings', array( __CLASS__, 'page_settings' ) );
		/**
		 * Add-ons add their screens here.
		 */
		do_action( 'vacanam_admin_menu' );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Languages', 'vacanam-language-switcher' ) . '</a>' );
		return $links;
	}

	/**
	 * Styles and scripts.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		wp_register_style( 'vacanam-admin', VACANAM_URL . 'assets/css/admin.css', array(), vacanam_asset_version( 'assets/css/admin.css' ) );
		wp_register_script( 'vacanam-admin', VACANAM_URL . 'assets/js/admin.js', array(), vacanam_asset_version( 'assets/js/admin.js' ), true );
		wp_localize_script(
			'vacanam-admin',
			'vacanamAdmin',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'jobNonce'  => wp_create_nonce( 'vacanam_job' ),
				'searchNonce' => wp_create_nonce( 'vacanam_search' ),
				'catalog'   => Vacanam_Languages::catalog(),
				'flags'     => VACANAM_URL . 'assets/flags/',
				'i18n'      => array(
					/* translators: 1: copies done, 2: copies to make */
					'working'  => __( 'Copying… %1$d of %2$d', 'vacanam-language-switcher' ),
					/* translators: %d: number of copies */
					'done'     => __( 'Done: %d copies made. They are ready to translate.', 'vacanam-language-switcher' ),
					'error'    => __( 'Something went wrong. Click the button again to continue where it stopped.', 'vacanam-language-switcher' ),
					'noChoice' => __( 'Choose at least one language and one kind of content.', 'vacanam-language-switcher' ),
					'leave'    => __( 'The copy is not finished. Leave anyway?', 'vacanam-language-switcher' ),
				),
			)
		);
		$screens = array( 'toplevel_page_vacanam', 'languages_page_vacanam-copy', 'languages_page_vacanam-menus', 'languages_page_vacanam-strings', 'languages_page_vacanam-switcher', 'languages_page_vacanam-settings' );
		if ( in_array( $hook, $screens, true ) || false !== strpos( $hook, 'vacanam' ) ) {
			wp_enqueue_style( 'vacanam-admin' );
			wp_enqueue_script( 'vacanam-admin' );
			wp_enqueue_style( 'vacanam-switcher', VACANAM_URL . 'assets/css/switcher.css', array(), vacanam_asset_version( 'assets/css/switcher.css' ) );
			wp_enqueue_script( 'vacanam-switcher', VACANAM_URL . 'assets/js/switcher.js', array(), vacanam_asset_version( 'assets/js/switcher.js' ), true );
		}
	}

	/**
	 * Warnings.
	 */
	public static function notices() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		if ( defined( 'POLYLANG_VERSION' ) || defined( 'ICL_SITEPRESS_VERSION' ) || defined( 'TRP_PLUGIN_VERSION' ) || defined( 'WEGLOT_VERSION' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Vacanam Language Switcher: another translation plugin is active (Polylang, WPML, TranslatePress or Weglot). Use only one translation plugin at a time.', 'vacanam-language-switcher' ) . '</p></div>';
		}
		$screen = get_current_screen();
		if ( ! Vacanam_Languages::all() && $screen && false === strpos( $screen->id, 'vacanam-setup' ) ) {
			echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Vacanam Language Switcher', 'vacanam-language-switcher' ) . '</strong> – ' . esc_html__( 'Add your languages in a few steps.', 'vacanam-language-switcher' ) . ' <a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=vacanam-setup' ) ) . '">' . esc_html__( 'Start the setup', 'vacanam-language-switcher' ) . '</a></p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Message after a redirect.
		$msg = isset( $_GET['vacanam_msg'] ) ? sanitize_key( wp_unslash( $_GET['vacanam_msg'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err = isset( $_GET['vacanam_err'] ) ? sanitize_text_field( wp_unslash( $_GET['vacanam_err'] ) ) : '';
		$messages = array(
			'saved'    => __( 'Saved.', 'vacanam-language-switcher' ),
			'added'    => __( 'Language added.', 'vacanam-language-switcher' ),
			'deleted'  => __( 'Language removed. Its pages and posts are kept, without a language.', 'vacanam-language-switcher' ),
			'assigned' => __( 'Everything without a language is now in the main language.', 'vacanam-language-switcher' ),
			'copied'   => __( 'Menu copied. Translate its labels in Appearance > Menus.', 'vacanam-language-switcher' ),
		);
		if ( isset( $messages[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
		}
		if ( '' !== $err ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
		}
	}

	/**
	 * Back to a screen with a message.
	 *
	 * @param string $page Page slug.
	 * @param string $msg  Message key.
	 * @param string $err  Error text.
	 */
	private static function back( $page, $msg = 'saved', $err = '' ) {
		$args = array( 'page' => $page );
		if ( '' !== $err ) {
			$args['vacanam_err'] = rawurlencode( $err );
		} else {
			$args['vacanam_msg'] = $msg;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Check the request.
	 *
	 * @param string $action Action.
	 */
	private static function check( $action ) {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'vacanam-language-switcher' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Screen header with tabs.
	 *
	 * @param string $title   Title.
	 * @param string $current Current page.
	 */
	private static function header( $title, $current ) {
		$tabs = array(
			self::SLUG         => __( 'Languages', 'vacanam-language-switcher' ),
			'vacanam-copy'     => __( 'Copy & translate', 'vacanam-language-switcher' ),
			'vacanam-menus'    => __( 'Menus', 'vacanam-language-switcher' ),
			'vacanam-strings'  => __( 'Texts', 'vacanam-language-switcher' ),
			'vacanam-switcher' => __( 'Switcher', 'vacanam-language-switcher' ),
			'vacanam-settings' => __( 'Settings', 'vacanam-language-switcher' ),
		);
		/**
		 * Tabs of the Languages screens.
		 *
		 * @param array $tabs page => label.
		 */
		$tabs = apply_filters( 'vacanam_admin_tabs', $tabs );
		echo '<div class="wrap vacanam-wrap"><h1 class="vacanam-title"><span class="dashicons dashicons-translation"></span> ' . esc_html( $title ) . '</h1>';
		echo '<nav class="nav-tab-wrapper vacanam-tabs">';
		foreach ( $tabs as $page => $label ) {
			echo '<a class="nav-tab' . ( $page === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Flag image.
	 *
	 * @param string $flag Flag code.
	 * @return string
	 */
	public static function flag( $flag ) {
		$url = Vacanam_Languages::flag_url( $flag );
		return $url ? '<img class="vacanam-admin-flag" src="' . esc_url( $url ) . '" width="20" height="15" alt="" />' : '';
	}

	/*
	 * Languages.
	 */

	/**
	 * Languages screen.
	 */
	public static function page_languages() {
		self::header( __( 'Languages', 'vacanam-language-switcher' ), self::SLUG );
		$langs   = Vacanam_Languages::all();
		$default = Vacanam_Languages::default_slug();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which language to edit.
		$edit    = isset( $_GET['edit'] ) ? Vacanam_Languages::get( sanitize_key( wp_unslash( $_GET['edit'] ) ) ) : null;
		$missing = $langs ? Vacanam_Model::count_without_language() : 0;
		if ( $missing ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( /* translators: %d: number */ _n( '%d page, post or category has no language yet. Without a language it is shown only in the main language.', '%d pages, posts or categories have no language yet. Without a language they are shown only in the main language.', $missing, 'vacanam-language-switcher' ), $missing ) ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="vacanam_assign_all" />';
			wp_nonce_field( 'vacanam_assign_all' );
			submit_button( __( 'Put them in the main language', 'vacanam-language-switcher' ), 'secondary', 'submit', false );
			echo '</form><p></p></div>';
		}
		?>
		<div class="vacanam-columns">
			<div class="vacanam-col-form vacanam-card">
				<h2><?php echo $edit ? esc_html__( 'Edit language', 'vacanam-language-switcher' ) : esc_html__( 'Add a language', 'vacanam-language-switcher' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vacanam-lang-form">
					<input type="hidden" name="action" value="<?php echo $edit ? 'vacanam_edit_language' : 'vacanam_add_language'; ?>" />
					<?php wp_nonce_field( $edit ? 'vacanam_edit_language' : 'vacanam_add_language' ); ?>
					<?php if ( $edit ) : ?>
						<input type="hidden" name="slug" value="<?php echo esc_attr( $edit['slug'] ); ?>" />
					<?php else : ?>
						<p><label for="vacanam-locale-pick"><strong><?php esc_html_e( 'Language', 'vacanam-language-switcher' ); ?></strong></label><br />
							<select id="vacanam-locale-pick" name="locale" class="vacanam-locale-pick">
								<option value=""><?php esc_html_e( '— Choose —', 'vacanam-language-switcher' ); ?></option>
								<?php foreach ( Vacanam_Languages::catalog() as $locale => $info ) : ?>
									<option value="<?php echo esc_attr( $locale ); ?>"><?php echo esc_html( $info[2] . ' – ' . $info[1] . ' (' . $locale . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endif; ?>
					<p><label><?php esc_html_e( 'Name shown to visitors', 'vacanam-language-switcher' ); ?><br /><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $edit ? $edit['name'] : '' ); ?>" /></label></p>
					<p><label><?php esc_html_e( 'WordPress locale', 'vacanam-language-switcher' ); ?><br /><input type="text" name="locale_custom" class="regular-text code" value="<?php echo esc_attr( $edit ? $edit['locale'] : '' ); ?>" placeholder="ar" /></label></p>
					<?php if ( ! $edit ) : ?>
						<p><label><?php esc_html_e( 'Code in addresses', 'vacanam-language-switcher' ); ?><br /><input type="text" name="slug" class="small-text code" maxlength="10" placeholder="ar" /></label><br /><span class="description"><?php esc_html_e( 'example.com/ar/ – two letters are best.', 'vacanam-language-switcher' ); ?></span></p>
					<?php endif; ?>
					<p><label><?php esc_html_e( 'Flag', 'vacanam-language-switcher' ); ?><br />
						<select name="flag" class="vacanam-flag-pick">
							<option value=""><?php esc_html_e( 'No flag', 'vacanam-language-switcher' ); ?></option>
							<?php foreach ( self::flags() as $code ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $edit ? $edit['flag'] : '', $code ); ?>><?php echo esc_html( strtoupper( $code ) ); ?></option>
							<?php endforeach; ?>
						</select> <span class="vacanam-flag-preview"><?php echo $edit ? self::flag( $edit['flag'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag(). ?></span></label></p>
					<p><label><input type="checkbox" name="rtl" value="1" <?php checked( $edit && $edit['rtl'] ); ?> /> <?php esc_html_e( 'Written right to left (Arabic, Hebrew, Persian, Urdu…)', 'vacanam-language-switcher' ); ?></label></p>
					<p><label><?php esc_html_e( 'hreflang code for search engines (optional)', 'vacanam-language-switcher' ); ?><br /><input type="text" name="hreflang" class="small-text code" value="<?php echo esc_attr( $edit ? $edit['hreflang'] : '' ); ?>" placeholder="<?php echo esc_attr( $edit ? Vacanam_Languages::hreflang( array_merge( $edit, array( 'hreflang' => '' ) ) ) : 'ar' ); ?>" /></label><br /><span class="description"><?php esc_html_e( 'Leave empty: set for you (ar, or ar-AE when the site has two kinds of Arabic).', 'vacanam-language-switcher' ); ?></span></p>
					<?php if ( $edit && $edit['slug'] !== $default ) : ?>
						<p><label><input type="checkbox" name="active" value="1" <?php checked( $edit['active'] ); ?> /> <?php esc_html_e( 'Visible to visitors (untick while you translate: only logged-in editors see it)', 'vacanam-language-switcher' ); ?></label></p>
					<?php endif; ?>
					<?php submit_button( $edit ? __( 'Save language', 'vacanam-language-switcher' ) : __( 'Add language', 'vacanam-language-switcher' ) ); ?>
					<?php if ( $edit ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Cancel', 'vacanam-language-switcher' ); ?></a>
					<?php endif; ?>
				</form>
			</div>
			<div class="vacanam-col-list">
				<table class="widefat striped vacanam-langs">
					<thead><tr>
						<th><?php esc_html_e( 'Language', 'vacanam-language-switcher' ); ?></th>
						<th><?php esc_html_e( 'Code', 'vacanam-language-switcher' ); ?></th>
						<th><?php esc_html_e( 'Locale', 'vacanam-language-switcher' ); ?></th>
						<th><?php esc_html_e( 'Direction', 'vacanam-language-switcher' ); ?></th>
						<th><?php esc_html_e( 'Posts', 'vacanam-language-switcher' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'vacanam-language-switcher' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( ! $langs ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No language yet. Add your main language first: the language your site is written in now.', 'vacanam-language-switcher' ); ?></td></tr>
					<?php endif; ?>
					<?php
					$i = 0;
					foreach ( $langs as $slug => $lang ) :
						$term  = get_term_by( 'slug', $slug, Vacanam_Model::TAX );
						$count = $term ? (int) $term->count : 0;
						?>
						<tr>
							<td><?php echo self::flag( $lang['flag'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag(). ?> <strong <?php echo $lang['rtl'] ? 'dir="rtl"' : ''; ?>><?php echo esc_html( $lang['name'] ); ?></strong>
								<?php if ( $slug === $default ) : ?>
									<span class="vacanam-badge vacanam-badge-main"><?php esc_html_e( 'Main language', 'vacanam-language-switcher' ); ?></span>
								<?php endif; ?>
								<?php if ( ! $lang['active'] ) : ?>
									<span class="vacanam-badge"><?php esc_html_e( 'Hidden', 'vacanam-language-switcher' ); ?></span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $slug ); ?></code></td>
							<td><code><?php echo esc_html( $lang['locale'] ); ?></code></td>
							<td><?php echo $lang['rtl'] ? esc_html__( 'Right to left', 'vacanam-language-switcher' ) : esc_html__( 'Left to right', 'vacanam-language-switcher' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
							<td class="vacanam-actions">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&edit=' . $slug ) ); ?>"><?php esc_html_e( 'Edit', 'vacanam-language-switcher' ); ?></a>
								<?php if ( $slug !== $default ) : ?>
									| <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vacanam_default_language&slug=' . $slug ), 'vacanam_default_language' ) ); ?>" data-confirm="<?php esc_attr_e( 'Make this the main language? Pages without a language and the address without a language code will be in this language.', 'vacanam-language-switcher' ); ?>"><?php esc_html_e( 'Make main', 'vacanam-language-switcher' ); ?></a>
								<?php endif; ?>
								<?php if ( $i > 0 ) : ?>
									| <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vacanam_move_language&dir=up&slug=' . $slug ), 'vacanam_move_language' ) ); ?>" aria-label="<?php esc_attr_e( 'Move up', 'vacanam-language-switcher' ); ?>">↑</a>
								<?php endif; ?>
								<?php if ( $i < count( $langs ) - 1 ) : ?>
									| <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vacanam_move_language&dir=down&slug=' . $slug ), 'vacanam_move_language' ) ); ?>" aria-label="<?php esc_attr_e( 'Move down', 'vacanam-language-switcher' ); ?>">↓</a>
								<?php endif; ?>
								| <a class="vacanam-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vacanam_delete_language&slug=' . $slug ), 'vacanam_delete_language' ) ); ?>" data-confirm="<?php esc_attr_e( 'Remove this language? Its pages and posts are not deleted: they stay, without a language.', 'vacanam-language-switcher' ); ?>"><?php esc_html_e( 'Remove', 'vacanam-language-switcher' ); ?></a>
							</td>
						</tr>
						<?php
						++$i;
					endforeach;
					?>
					</tbody>
				</table>
				<?php if ( $langs ) : ?>
					<p class="description"><?php esc_html_e( 'The order here is the order of the language switchers.', 'vacanam-language-switcher' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=vacanam-setup' ) ); ?>"><?php esc_html_e( 'Run the setup again', 'vacanam-language-switcher' ); ?></a></p>
					<?php self::overview(); ?>
				<?php endif; ?>
			</div>
		</div>
		</div>
		<?php
	}

	/**
	 * How much is translated.
	 */
	private static function overview() {
		$langs = Vacanam_Languages::all();
		if ( count( $langs ) < 2 ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Translated so far', 'vacanam-language-switcher' ) . '</h2><table class="widefat striped vacanam-overview"><thead><tr><th>' . esc_html__( 'Content', 'vacanam-language-switcher' ) . '</th>';
		foreach ( $langs as $lang ) {
			echo '<th>' . self::flag( $lang['flag'] ) . ' ' . esc_html( $lang['name'] ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag().
		}
		echo '</tr></thead><tbody>';
		foreach ( Vacanam_Model::post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object || 'attachment' === $type ) {
				continue;
			}
			echo '<tr><td>' . esc_html( $object->labels->name ) . '</td>';
			foreach ( $langs as $slug => $lang ) {
				$q = new WP_Query(
					array(
						'post_type'      => $type,
						'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'lang'           => $slug,
						'tax_query'      => array( Vacanam_Frontend::language_clause( $slug ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Admin overview.
					)
				);
				$url = admin_url( 'edit.php?post_type=' . $type . '&vacanam_lang=' . $slug );
				echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( number_format_i18n( $q->found_posts ) ) . '</a></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Flag codes available.
	 *
	 * @return string[]
	 */
	public static function flags() {
		$files = glob( VACANAM_DIR . 'assets/flags/*.svg' );
		$out   = array();
		foreach ( $files ? $files : array() as $file ) {
			$out[] = basename( $file, '.svg' );
		}
		return $out;
	}

	/**
	 * Add a language.
	 */
	public static function handle_add_language() {
		self::check( 'vacanam_add_language' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check().
		$locale = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '';
		$custom = isset( $_POST['locale_custom'] ) ? sanitize_text_field( wp_unslash( $_POST['locale_custom'] ) ) : '';
		$data   = array( 'locale' => '' !== $custom ? $custom : $locale );
		foreach ( array( 'name', 'slug', 'flag', 'hreflang' ) as $key ) {
			if ( ! empty( $_POST[ $key ] ) ) {
				$data[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		if ( '' !== $locale || '' !== $custom ) {
			$data['rtl'] = ! empty( $_POST['rtl'] );
		}
		// phpcs:enable
		$result = Vacanam_Languages::add( $data );
		if ( is_wp_error( $result ) ) {
			self::back( self::SLUG, '', $result->get_error_message() );
		}
		self::back( self::SLUG, 'added' );
	}

	/**
	 * Edit a language.
	 */
	public static function handle_edit_language() {
		self::check( 'vacanam_edit_language' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check().
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$data = array(
			'name'     => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'flag'     => isset( $_POST['flag'] ) ? sanitize_key( wp_unslash( $_POST['flag'] ) ) : '',
			'hreflang' => isset( $_POST['hreflang'] ) ? sanitize_text_field( wp_unslash( $_POST['hreflang'] ) ) : '',
			'rtl'      => ! empty( $_POST['rtl'] ),
			'active'   => ! empty( $_POST['active'] ) || Vacanam_Languages::default_slug() === $slug,
		);
		if ( ! empty( $_POST['locale_custom'] ) ) {
			$data['locale'] = sanitize_text_field( wp_unslash( $_POST['locale_custom'] ) );
		}
		// phpcs:enable
		if ( '' === $data['name'] ) {
			unset( $data['name'] );
		}
		$result = Vacanam_Languages::update( $slug, $data );
		self::back( self::SLUG, 'saved', is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Language from the address of a link.
	 *
	 * @return string
	 */
	private static function slug_param() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in check().
		return isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
	}

	/**
	 * Remove a language.
	 */
	public static function handle_delete_language() {
		self::check( 'vacanam_delete_language' );
		Vacanam_Languages::delete( self::slug_param() );
		self::back( self::SLUG, 'deleted' );
	}

	/**
	 * Main language.
	 */
	public static function handle_default_language() {
		self::check( 'vacanam_default_language' );
		$slug = self::slug_param();
		if ( Vacanam_Languages::exists( $slug ) ) {
			Vacanam_Settings::update( array( 'default' => $slug ) );
			Vacanam_Languages::update( $slug, array( 'active' => true ) );
		}
		self::back( self::SLUG );
	}

	/**
	 * Order.
	 */
	public static function handle_move_language() {
		self::check( 'vacanam_move_language' );
		$slug  = self::slug_param();
		$slugs = Vacanam_Languages::slugs();
		$pos   = array_search( $slug, $slugs, true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in check().
		$dir = isset( $_GET['dir'] ) && 'up' === $_GET['dir'] ? -1 : 1;
		if ( false !== $pos && isset( $slugs[ $pos + $dir ] ) ) {
			$other               = $slugs[ $pos + $dir ];
			$slugs[ $pos + $dir ] = $slug;
			$slugs[ $pos ]        = $other;
			Vacanam_Languages::reorder( $slugs );
		}
		self::back( self::SLUG );
	}

	/**
	 * Everything without a language → main language.
	 */
	public static function handle_assign_all() {
		self::check( 'vacanam_assign_all' );
		Vacanam_Model::assign_all( Vacanam_Languages::default_slug() );
		self::back( self::SLUG, 'assigned' );
	}

	/*
	 * Copy & translate.
	 */

	/**
	 * Copy the site into a language.
	 */
	public static function page_copy() {
		self::header( __( 'Copy into a language', 'vacanam-language-switcher' ), 'vacanam-copy' );
		if ( count( Vacanam_Languages::all() ) < 2 ) {
			echo '<div class="vacanam-card"><p>' . esc_html__( 'Add a second language first.', 'vacanam-language-switcher' ) . '</p></div></div>';
			return;
		}
		echo '<div class="vacanam-card">';
		echo '<p>' . esc_html__( 'Make a copy of your pages, posts, categories and menus in another language, with the same design: blocks, Elementor and other page-builder layouts are copied with all their settings. Each copy is connected to its original as its translation, and links between pages lead to the pages of the same language. Then open each copy and change the text.', 'vacanam-language-switcher' ) . '</p>';
		self::copy_form( 'draft' );
		echo '</div>';
		/**
		 * After the copy tool (add-ons add machine translation here).
		 */
		do_action( 'vacanam_copy_page_after' );
		echo '</div>';
	}

	/**
	 * The copy form (also used by the setup).
	 *
	 * @param string $status Default status.
	 */
	public static function copy_form( $status = 'draft' ) {
		$default = Vacanam_Languages::default_slug();
		?>
		<form class="vacanam-copy-form" data-job="1">
			<h3><?php esc_html_e( 'Copy into', 'vacanam-language-switcher' ); ?></h3>
			<p>
			<?php foreach ( Vacanam_Languages::all() as $slug => $lang ) : ?>
				<?php if ( $slug !== $default ) : ?>
					<label class="vacanam-chip"><input type="checkbox" name="langs[]" value="<?php echo esc_attr( $slug ); ?>" checked="checked" /> <?php echo self::flag( $lang['flag'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag(). ?> <?php echo esc_html( $lang['name'] ); ?></label>
				<?php endif; ?>
			<?php endforeach; ?>
			</p>
			<h3><?php esc_html_e( 'What to copy', 'vacanam-language-switcher' ); ?></h3>
			<p>
			<?php
			foreach ( Vacanam_Model::post_types() as $type ) :
				$object = get_post_type_object( $type );
				if ( ! $object || 'attachment' === $type ) {
					continue;
				}
				$on = in_array( $type, array( 'page', 'post' ), true );
				?>
				<label class="vacanam-chip"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( $on ); ?> /> <?php echo esc_html( $object->labels->name ); ?></label>
			<?php endforeach; ?>
				<label class="vacanam-chip"><input type="checkbox" name="terms" value="1" checked="checked" /> <?php esc_html_e( 'Categories and tags', 'vacanam-language-switcher' ); ?></label>
				<label class="vacanam-chip"><input type="checkbox" name="menus" value="1" checked="checked" /> <?php esc_html_e( 'Menus', 'vacanam-language-switcher' ); ?></label>
			</p>
			<p class="description"><?php esc_html_e( 'Pages that already have a translation in a language are skipped, so you can run this again at any time.', 'vacanam-language-switcher' ); ?></p>
			<h3><?php esc_html_e( 'Copies are', 'vacanam-language-switcher' ); ?></h3>
			<p>
				<label><input type="radio" name="status" value="draft" <?php checked( 'draft', $status ); ?> /> <?php esc_html_e( 'Drafts: publish each one once translated (recommended)', 'vacanam-language-switcher' ); ?></label><br />
				<label><input type="radio" name="status" value="same" <?php checked( 'same', $status ); ?> /> <?php esc_html_e( 'Published like their original (visitors see them right away, still in the original language until you translate them)', 'vacanam-language-switcher' ); ?></label>
			</p>
			<p><button type="submit" class="button button-primary button-hero vacanam-copy-start"><?php esc_html_e( 'Make the copies', 'vacanam-language-switcher' ); ?></button></p>
			<div class="vacanam-progress" hidden>
				<div class="vacanam-bar"><span></span></div>
				<p class="vacanam-progress-text" aria-live="polite"></p>
				<ul class="vacanam-progress-errors"></ul>
			</div>
		</form>
		<?php
	}

	/*
	 * Menus.
	 */

	/**
	 * Menus per language.
	 */
	public static function page_menus() {
		self::header( __( 'Menus per language', 'vacanam-language-switcher' ), 'vacanam-menus' );
		$locations = array_filter(
			get_registered_nav_menus(),
			static function ( $key ) {
				return false === strpos( $key, Vacanam_Menus::SEP );
			},
			ARRAY_FILTER_USE_KEY
		);
		$menus     = wp_get_nav_menus();
		$langs     = Vacanam_Languages::all();
		echo '<div class="vacanam-card"><p>' . esc_html__( 'Choose the menu shown in each place of your theme, for each language. Menus used by Elementor or by name are replaced by their copy in the language of the visit. A menu used in every language also leads to the translated pages by itself.', 'vacanam-language-switcher' ) . '</p>';
		if ( ! $locations ) {
			echo '<p>' . esc_html__( 'Your theme has no menu locations (for example with Elementor headers): copy the menus below, and each language shows its own copy.', 'vacanam-language-switcher' ) . '</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="vacanam_save_menus" />';
			wp_nonce_field( 'vacanam_save_menus' );
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Theme location', 'vacanam-language-switcher' ) . '</th>';
			foreach ( $langs as $lang ) {
				echo '<th>' . self::flag( $lang['flag'] ) . ' ' . esc_html( $lang['name'] ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag().
			}
			echo '</tr></thead><tbody>';
			foreach ( $locations as $location => $label ) {
				echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td>';
				foreach ( $langs as $slug => $lang ) {
					$current = Vacanam_Menus::assigned( $location, $slug );
					echo '<td><select name="loc[' . esc_attr( $location ) . '][' . esc_attr( $slug ) . ']"><option value="0">' . esc_html__( '— Same as main language —', 'vacanam-language-switcher' ) . '</option>';
					foreach ( $menus as $menu ) {
						echo '<option value="' . esc_attr( $menu->term_id ) . '" ' . selected( $current, $menu->term_id, false ) . '>' . esc_html( $menu->name ) . '</option>';
					}
					echo '</select></td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
			submit_button();
			echo '</form>';
		}
		if ( $menus && count( $langs ) > 1 ) {
			echo '<h2>' . esc_html__( 'Copy a menu into a language', 'vacanam-language-switcher' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Menu', 'vacanam-language-switcher' ) . '</th><th>' . esc_html__( 'Translations', 'vacanam-language-switcher' ) . '</th></tr></thead><tbody>';
			foreach ( $menus as $menu ) {
				$own = Vacanam_Menus::menu_lang( (int) $menu->term_id );
				if ( '' !== $own && Vacanam_Languages::default_slug() !== $own ) {
					continue;
				}
				echo '<tr><td><strong>' . esc_html( $menu->name ) . '</strong></td><td>';
				foreach ( $langs as $slug => $lang ) {
					if ( Vacanam_Languages::default_slug() === $slug ) {
						continue;
					}
					$tr = Vacanam_Menus::translation( (int) $menu->term_id, $slug );
					if ( $tr ) {
						echo '<a class="button" href="' . esc_url( admin_url( 'nav-menus.php?action=edit&menu=' . $tr ) ) . '">' . self::flag( $lang['flag'] ) . ' ' . esc_html__( 'Edit', 'vacanam-language-switcher' ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag().
					} else {
						echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vacanam_copy_menu&menu=' . $menu->term_id . '&slug=' . $slug ), 'vacanam_copy_menu' ) ) . '">' . self::flag( $lang['flag'] ) . ' ' . esc_html( sprintf( /* translators: %s: language */ __( 'Copy into %s', 'vacanam-language-switcher' ), $lang['name'] ) ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag().
					}
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p class="description">' . wp_kses_post( sprintf( /* translators: %s: link */ __( 'To add a language switcher to a menu, open %s and use the "Language switcher" box.', 'vacanam-language-switcher' ), '<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '">' . esc_html__( 'Appearance > Menus', 'vacanam-language-switcher' ) . '</a>' ) ) . '</p>';
		echo '</div></div>';
	}

	/**
	 * Save menu locations.
	 */
	public static function handle_save_menus() {
		self::check( 'vacanam_save_menus' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in check(); values cast below.
		$locs = isset( $_POST['loc'] ) && is_array( $_POST['loc'] ) ? wp_unslash( $_POST['loc'] ) : array();
		foreach ( $locs as $location => $per_lang ) {
			foreach ( (array) $per_lang as $slug => $menu ) {
				if ( Vacanam_Languages::exists( $slug ) ) {
					Vacanam_Menus::assign( sanitize_key( $location ), $slug, absint( $menu ) );
				}
			}
		}
		self::back( 'vacanam-menus' );
	}

	/**
	 * Copy a menu.
	 */
	public static function handle_copy_menu() {
		self::check( 'vacanam_copy_menu' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in check().
		$menu   = isset( $_GET['menu'] ) ? absint( $_GET['menu'] ) : 0;
		$result = Vacanam_Duplicator::duplicate_menu( $menu, self::slug_param() );
		self::back( 'vacanam-menus', 'copied', is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/*
	 * Texts.
	 */

	/**
	 * Translate texts.
	 */
	public static function page_strings() {
		self::header( __( 'Translate texts', 'vacanam-language-switcher' ), 'vacanam-strings' );
		$langs = Vacanam_Languages::all();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters of the list.
		$lang   = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$group  = isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '';
		// phpcs:enable
		$others = array_diff_key( $langs, array( Vacanam_Languages::default_slug() => 1 ) );
		if ( ! isset( $others[ $lang ] ) ) {
			$lang = $others ? (string) key( $others ) : '';
		}
		echo '<div class="vacanam-card"><p>' . esc_html__( 'Texts that are not pages or posts: the site title and tagline, date formats, widget titles and texts that your theme and plugins register. Leave a translation empty to show the original.', 'vacanam-language-switcher' ) . '</p>';
		if ( '' === $lang ) {
			echo '<p>' . esc_html__( 'Add a second language first.', 'vacanam-language-switcher' ) . '</p></div></div>';
			return;
		}
		$strings      = Vacanam_Strings::registered();
		$translations = Vacanam_Strings::translations();
		$groups       = array_unique( wp_list_pluck( $strings, 'group' ) );
		echo '<form method="get" class="vacanam-filter"><input type="hidden" name="page" value="vacanam-strings" />';
		echo '<select name="lang">';
		foreach ( $others as $slug => $info ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $lang, $slug, false ) . '>' . esc_html( $info['name'] ) . '</option>';
		}
		echo '</select> <select name="group"><option value="">' . esc_html__( 'All groups', 'vacanam-language-switcher' ) . '</option>';
		foreach ( $groups as $g ) {
			echo '<option value="' . esc_attr( $g ) . '" ' . selected( $group, $g, false ) . '>' . esc_html( $g ) . '</option>';
		}
		echo '</select> <input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search texts', 'vacanam-language-switcher' ) . '" /> ';
		submit_button( __( 'Filter', 'vacanam-language-switcher' ), 'secondary', '', false );
		echo '</form>';
		$rtl = ! empty( $langs[ $lang ]['rtl'] );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="vacanam_save_strings" /><input type="hidden" name="lang" value="' . esc_attr( $lang ) . '" />';
		wp_nonce_field( 'vacanam_save_strings' );
		echo '<table class="widefat striped vacanam-strings"><thead><tr><th style="width:18%">' . esc_html__( 'Name', 'vacanam-language-switcher' ) . '</th><th style="width:12%">' . esc_html__( 'Group', 'vacanam-language-switcher' ) . '</th><th>' . esc_html__( 'Original', 'vacanam-language-switcher' ) . '</th><th>' . esc_html( $langs[ $lang ]['name'] ) . '</th></tr></thead><tbody>';
		$shown = 0;
		foreach ( $strings as $key => $s ) {
			if ( ( '' !== $group && $s['group'] !== $group ) || ( '' !== $search && false === stripos( $s['string'] . ' ' . $s['name'], $search ) ) ) {
				continue;
			}
			++$shown;
			$value = isset( $translations[ $lang ][ $key ] ) ? $translations[ $lang ][ $key ] : '';
			echo '<tr><td>' . esc_html( $s['name'] ) . '</td><td>' . esc_html( $s['group'] ) . '</td><td><div class="vacanam-original">' . esc_html( $s['string'] ) . '</div></td><td>';
			if ( $s['multiline'] ) {
				echo '<textarea class="large-text" rows="3" name="t[' . esc_attr( $key ) . ']"' . ( $rtl ? ' dir="rtl"' : '' ) . '>' . esc_textarea( $value ) . '</textarea>';
			} else {
				echo '<input type="text" class="large-text" name="t[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '"' . ( $rtl ? ' dir="rtl"' : '' ) . ' />';
			}
			echo '</td></tr>';
		}
		if ( ! $shown ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No texts found.', 'vacanam-language-switcher' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save translations', 'vacanam-language-switcher' ) );
		echo '</form>';
		/**
		 * After the texts table (add-ons add machine translation here).
		 *
		 * @param string $lang Language.
		 */
		do_action( 'vacanam_strings_page_after', $lang );
		echo '<p class="description">' . esc_html__( 'Developers: register your own texts with vacanam_register_string() (or Polylang\'s pll_register_string()) and show them with vacanam__().', 'vacanam-language-switcher' ) . '</p></div></div>';
	}

	/**
	 * Save texts.
	 */
	public static function handle_save_strings() {
		self::check( 'vacanam_save_strings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check().
		$lang  = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$items = isset( $_POST['t'] ) && is_array( $_POST['t'] ) ? array_map( 'wp_kses_post', wp_unslash( $_POST['t'] ) ) : array();
		// phpcs:enable
		if ( Vacanam_Languages::exists( $lang ) ) {
			Vacanam_Strings::save( $lang, $items );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'vacanam-strings', 'lang' => $lang, 'vacanam_msg' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/*
	 * Switcher.
	 */

	/**
	 * Appearance of the switchers.
	 */
	public static function page_switcher() {
		self::header( __( 'Language switcher', 'vacanam-language-switcher' ), 'vacanam-switcher' );
		$s = Vacanam_Switcher::defaults();
		$f = Vacanam_Switcher::floating_settings();
		?>
		<div class="vacanam-columns">
			<div class="vacanam-col-form vacanam-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vacanam-switcher-form">
					<input type="hidden" name="action" value="vacanam_save_switcher" />
					<?php wp_nonce_field( 'vacanam_save_switcher' ); ?>
					<h2><?php esc_html_e( 'Look', 'vacanam-language-switcher' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Used by every switcher that does not set its own look. Leave colours empty to use your theme\'s colours.', 'vacanam-language-switcher' ); ?></p>
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Style', 'vacanam-language-switcher' ); ?></th><td>
							<select name="s[style]" data-preview="style">
								<?php foreach ( Vacanam_Switcher::styles() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['style'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td></tr>
						<tr><th><?php esc_html_e( 'Show', 'vacanam-language-switcher' ); ?></th><td>
							<select name="s[names]" data-preview="names">
								<option value="name" <?php selected( $s['names'], 'name' ); ?>><?php esc_html_e( 'Language names', 'vacanam-language-switcher' ); ?></option>
								<option value="code" <?php selected( $s['names'], 'code' ); ?>><?php esc_html_e( 'Codes (EN, AR)', 'vacanam-language-switcher' ); ?></option>
								<option value="none" <?php selected( $s['names'], 'none' ); ?>><?php esc_html_e( 'No text (flags only)', 'vacanam-language-switcher' ); ?></option>
							</select></td></tr>
						<tr><th><?php esc_html_e( 'Flags', 'vacanam-language-switcher' ); ?></th><td>
							<label><input type="checkbox" name="s[flags]" value="1" <?php checked( $s['flags'] ); ?> data-preview="flags" /> <?php esc_html_e( 'Show flags', 'vacanam-language-switcher' ); ?></label>
							&nbsp; <select name="s[flag_shape]" data-preview="flag_shape">
								<option value="rect" <?php selected( $s['flag_shape'], 'rect' ); ?>><?php esc_html_e( 'Rectangle', 'vacanam-language-switcher' ); ?></option>
								<option value="round" <?php selected( $s['flag_shape'], 'round' ); ?>><?php esc_html_e( 'Round', 'vacanam-language-switcher' ); ?></option>
								<option value="square" <?php selected( $s['flag_shape'], 'square' ); ?>><?php esc_html_e( 'Square', 'vacanam-language-switcher' ); ?></option>
							</select>
							&nbsp; <label><?php esc_html_e( 'Size', 'vacanam-language-switcher' ); ?> <input type="number" min="10" max="60" name="s[flag_size]" value="<?php echo esc_attr( $s['flag_size'] ); ?>" class="small-text" data-preview="flag_size" /> px</label>
						</td></tr>
						<tr><th><?php esc_html_e( 'Current language', 'vacanam-language-switcher' ); ?></th><td><label><input type="checkbox" name="s[hide_current]" value="1" <?php checked( $s['hide_current'] ); ?> /> <?php esc_html_e( 'Hide it (show only the other languages)', 'vacanam-language-switcher' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Dropdown opens on', 'vacanam-language-switcher' ); ?></th><td>
							<label><input type="radio" name="s[open_on]" value="click" <?php checked( $s['open_on'], 'click' ); ?> /> <?php esc_html_e( 'Click', 'vacanam-language-switcher' ); ?></label>
							&nbsp; <label><input type="radio" name="s[open_on]" value="hover" <?php checked( $s['open_on'], 'hover' ); ?> /> <?php esc_html_e( 'Hover', 'vacanam-language-switcher' ); ?></label></td></tr>
						<?php
						$colors = array(
							'text'        => __( 'Text', 'vacanam-language-switcher' ),
							'background'  => __( 'Background', 'vacanam-language-switcher' ),
							'hover_text'  => __( 'Text on hover', 'vacanam-language-switcher' ),
							'hover_bg'    => __( 'Background on hover', 'vacanam-language-switcher' ),
							'active_text' => __( 'Current language text', 'vacanam-language-switcher' ),
							'active_bg'   => __( 'Current language background', 'vacanam-language-switcher' ),
							'border'      => __( 'Border', 'vacanam-language-switcher' ),
						);
						foreach ( $colors as $key => $label ) :
							?>
							<tr><th><?php echo esc_html( $label ); ?></th><td class="vacanam-color-row">
								<input type="color" value="<?php echo esc_attr( $s[ $key ] ? $s[ $key ] : '#000000' ); ?>" data-for="vacanam-c-<?php echo esc_attr( $key ); ?>" class="vacanam-color-pick" />
								<input type="text" id="vacanam-c-<?php echo esc_attr( $key ); ?>" name="s[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $s[ $key ] ); ?>" class="regular-text code vacanam-color-text" placeholder="<?php esc_attr_e( 'Theme colour', 'vacanam-language-switcher' ); ?>" data-preview="<?php echo esc_attr( $key ); ?>" />
								<button type="button" class="button-link vacanam-color-clear" data-for="vacanam-c-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Clear', 'vacanam-language-switcher' ); ?></button>
							</td></tr>
						<?php endforeach; ?>
						<tr><th><?php esc_html_e( 'Corner radius', 'vacanam-language-switcher' ); ?></th><td><input type="number" min="0" max="40" name="s[radius]" value="<?php echo esc_attr( $s['radius'] ); ?>" class="small-text" data-preview="radius" /> px</td></tr>
						<tr><th><?php esc_html_e( 'Text size', 'vacanam-language-switcher' ); ?></th><td><input type="number" min="0" max="40" name="s[font_size]" value="<?php echo esc_attr( $s['font_size'] ); ?>" class="small-text" data-preview="font_size" /> px <span class="description"><?php esc_html_e( '0 = theme size', 'vacanam-language-switcher' ); ?></span></td></tr>
					</table>

					<h2><?php esc_html_e( 'Floating switcher', 'vacanam-language-switcher' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Show', 'vacanam-language-switcher' ); ?></th><td><label><input type="checkbox" name="f[enabled]" value="1" <?php checked( $f['enabled'] ); ?> /> <?php esc_html_e( 'Show a switcher in a corner of every page', 'vacanam-language-switcher' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Corner', 'vacanam-language-switcher' ); ?></th><td><select name="f[position]">
							<option value="bottom-end" <?php selected( $f['position'], 'bottom-end' ); ?>><?php esc_html_e( 'Bottom, end side (right in English, left in Arabic)', 'vacanam-language-switcher' ); ?></option>
							<option value="bottom-start" <?php selected( $f['position'], 'bottom-start' ); ?>><?php esc_html_e( 'Bottom, start side', 'vacanam-language-switcher' ); ?></option>
							<option value="top-end" <?php selected( $f['position'], 'top-end' ); ?>><?php esc_html_e( 'Top, end side', 'vacanam-language-switcher' ); ?></option>
							<option value="top-start" <?php selected( $f['position'], 'top-start' ); ?>><?php esc_html_e( 'Top, start side', 'vacanam-language-switcher' ); ?></option>
						</select></td></tr>
						<tr><th><?php esc_html_e( 'Style', 'vacanam-language-switcher' ); ?></th><td><select name="f[style]">
							<option value="dropdown" <?php selected( $f['style'], 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'vacanam-language-switcher' ); ?></option>
							<option value="pills" <?php selected( $f['style'], 'pills' ); ?>><?php esc_html_e( 'Buttons (pills)', 'vacanam-language-switcher' ); ?></option>
							<option value="select" <?php selected( $f['style'], 'select' ); ?>><?php esc_html_e( 'Select box', 'vacanam-language-switcher' ); ?></option>
						</select></td></tr>
						<tr><th><?php esc_html_e( 'Phones', 'vacanam-language-switcher' ); ?></th><td><label><input type="checkbox" name="f[mobile]" value="1" <?php checked( $f['mobile'] ); ?> /> <?php esc_html_e( 'Show on phones too', 'vacanam-language-switcher' ); ?></label></td></tr>
					</table>
					<?php submit_button(); ?>
				</form>
			</div>
			<div class="vacanam-col-list">
				<div class="vacanam-card vacanam-preview-card">
					<h2><?php esc_html_e( 'Preview', 'vacanam-language-switcher' ); ?></h2>
					<div class="vacanam-preview-stage">
						<?php
						foreach ( array_keys( Vacanam_Switcher::styles() ) as $style ) {
							echo '<div class="vacanam-preview" data-style="' . esc_attr( $style ) . '"' . ( $style === $s['style'] ? '' : ' hidden' ) . '>';
							echo Vacanam_Switcher::render( array_merge( $s, array( 'style' => $style, 'hide_current' => 0 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render().
							echo '</div>';
						}
						?>
					</div>
					<p class="description"><?php esc_html_e( 'Colours and sizes change here as you choose them.', 'vacanam-language-switcher' ); ?></p>
				</div>
				<div class="vacanam-card">
					<h2><?php esc_html_e( 'Where to add a switcher', 'vacanam-language-switcher' ); ?></h2>
					<ul class="vacanam-howto">
						<li><strong><?php esc_html_e( 'Menu', 'vacanam-language-switcher' ); ?></strong> – <?php echo wp_kses_post( sprintf( /* translators: %s: link */ __( '%s, "Language switcher" box: as a dropdown or side by side, with your theme\'s menu style.', 'vacanam-language-switcher' ), '<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '">' . esc_html__( 'Appearance > Menus', 'vacanam-language-switcher' ) . '</a>' ) ); ?></li>
						<li><strong>Elementor</strong> – <?php esc_html_e( 'search for the "Language switcher" widget: all styles, colours, typography, spacing.', 'vacanam-language-switcher' ); ?></li>
						<li><strong><?php esc_html_e( 'Blocks', 'vacanam-language-switcher' ); ?></strong> – <?php esc_html_e( 'the "Language switcher" block, in pages, templates and the site editor header.', 'vacanam-language-switcher' ); ?></li>
						<li><strong><?php esc_html_e( 'Widgets', 'vacanam-language-switcher' ); ?></strong> – <?php esc_html_e( 'the "Language switcher" widget in any sidebar or footer.', 'vacanam-language-switcher' ); ?></li>
						<li><strong><?php esc_html_e( 'Shortcode', 'vacanam-language-switcher' ); ?></strong> – <code>[vacanam_switcher style="pills" names="code"]</code></li>
						<li><strong>PHP</strong> – <code>&lt;?php vacanam_switcher( array( 'style' =&gt; 'dropdown' ) ); ?&gt;</code></li>
					</ul>
				</div>
			</div>
		</div>
		</div>
		<?php
	}

	/**
	 * Save the switcher look.
	 */
	public static function handle_save_switcher() {
		self::check( 'vacanam_save_switcher' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in check(); cleaned below.
		$s = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array();
		$f = isset( $_POST['f'] ) && is_array( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : array();
		// phpcs:enable
		$s['flags']        = empty( $s['flags'] ) ? 0 : 1;
		$s['hide_current'] = empty( $s['hide_current'] ) ? 0 : 1;
		$clean             = Vacanam_Switcher::clean( $s );
		Vacanam_Settings::update(
			array(
				'switcher' => $clean,
				'floating' => array(
					'enabled'  => empty( $f['enabled'] ) ? 0 : 1,
					'position' => isset( $f['position'] ) && in_array( $f['position'], array( 'bottom-end', 'bottom-start', 'top-end', 'top-start' ), true ) ? $f['position'] : 'bottom-end',
					'style'    => isset( $f['style'] ) && in_array( $f['style'], array( 'dropdown', 'pills', 'select' ), true ) ? $f['style'] : 'dropdown',
					'mobile'   => empty( $f['mobile'] ) ? 0 : 1,
				),
			)
		);
		self::back( 'vacanam-switcher' );
	}

	/*
	 * Settings.
	 */

	/**
	 * Settings screen.
	 */
	public static function page_settings() {
		self::header( __( 'Settings', 'vacanam-language-switcher' ), 'vacanam-settings' );
		$s     = Vacanam_Settings::all();
		$saved = get_option( Vacanam_Settings::OPTION, array() );
		$mode  = isset( $saved['url_mode'] ) ? $saved['url_mode'] : $s['url_mode'];
		$home  = Vacanam_Router::raw_home();
		$modes = array(
			'directory' => sprintf( /* translators: %s: example address */ __( 'Language folder: %s', 'vacanam-language-switcher' ), $home . '/ar/about/' ),
			'query'     => sprintf( /* translators: %s: example address */ __( 'Address parameter: %s', 'vacanam-language-switcher' ), $home . '/about/?lang=ar' ),
		);
		/**
		 * Address modes (add-ons add sub-domains and one domain per language).
		 *
		 * @param array $modes mode => label.
		 */
		$modes = apply_filters( 'vacanam_url_modes', $modes );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vacanam-card">
			<input type="hidden" name="action" value="vacanam_save_settings" />
			<?php wp_nonce_field( 'vacanam_save_settings' ); ?>
			<h2><?php esc_html_e( 'Addresses', 'vacanam-language-switcher' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Language in the address', 'vacanam-language-switcher' ); ?></th><td>
					<?php foreach ( $modes as $key => $label ) : ?>
						<label><input type="radio" name="url_mode" value="<?php echo esc_attr( $key ); ?>" <?php checked( $mode, $key ); ?> /> <?php echo esc_html( $label ); ?></label><br />
					<?php endforeach; ?>
					<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
						<p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: link */ __( 'Language folders need pretty permalinks: choose any structure but "Plain" in %s. Until then the address parameter is used.', 'vacanam-language-switcher' ), '<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Settings > Permalinks', 'vacanam-language-switcher' ) . '</a>' ) ); ?></p>
					<?php endif; ?>
					<?php
					/**
					 * More address settings (add-ons).
					 *
					 * @param array $s Settings.
					 */
					do_action( 'vacanam_settings_url_after', $s );
					?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Main language', 'vacanam-language-switcher' ); ?></th><td><label><input type="checkbox" name="hide_default" value="yes" <?php checked( $s['hide_default'], 'yes' ); ?> /> <?php esc_html_e( 'No language code for the main language (example.com/about/ instead of example.com/en/about/)', 'vacanam-language-switcher' ); ?></label>
					<p class="description"><?php esc_html_e( 'Keep this on for a site that already ranks in search engines: its addresses do not change.', 'vacanam-language-switcher' ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Browser language', 'vacanam-language-switcher' ); ?></th><td><label><input type="checkbox" name="browser" value="yes" <?php checked( $s['browser'], 'yes' ); ?> /> <?php esc_html_e( 'On the first visit of the home page, show it in the visitor\'s browser language', 'vacanam-language-switcher' ); ?></label>
					<p class="description"><?php esc_html_e( 'Search engines are never redirected, and the visitor\'s choice in a switcher is remembered.', 'vacanam-language-switcher' ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Pages not translated yet', 'vacanam-language-switcher' ); ?></th><td>
					<label><input type="radio" name="untranslated" value="home" <?php checked( $s['untranslated'], 'home' ); ?> /> <?php esc_html_e( 'The switcher leads to the home page of the language', 'vacanam-language-switcher' ); ?></label><br />
					<label><input type="radio" name="untranslated" value="hide" <?php checked( $s['untranslated'], 'hide' ); ?> /> <?php esc_html_e( 'The switcher hides that language', 'vacanam-language-switcher' ); ?></label></td></tr>
			</table>

			<h2><?php esc_html_e( 'SEO', 'vacanam-language-switcher' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th>hreflang</th><td><label><input type="checkbox" name="hreflang" value="yes" <?php checked( $s['hreflang'], 'yes' ); ?> /> <?php esc_html_e( 'Tell search engines which pages are translations of each other (hreflang links)', 'vacanam-language-switcher' ); ?></label><br />
					<label><input type="checkbox" name="xdefault" value="default" <?php checked( $s['xdefault'], 'default' ); ?> /> <?php esc_html_e( 'Main language for visitors of other languages (x-default)', 'vacanam-language-switcher' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'One address per page', 'vacanam-language-switcher' ); ?></th><td><label><input type="checkbox" name="redirect" value="yes" <?php checked( $s['redirect'], 'yes' ); ?> /> <?php esc_html_e( 'Send visitors and search engines to the right address when a page is opened with the wrong language code (301 redirect)', 'vacanam-language-switcher' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'SEO plugins', 'vacanam-language-switcher' ); ?></th><td><p class="description"><?php esc_html_e( 'Yoast SEO, Rank Math and SEOPress work as they are: titles, descriptions and focus keywords are per page, so each translation has its own; canonical addresses, sitemaps (all languages), breadcrumbs, og:locale and og:locale:alternate follow the language. Copies never keep a canonical address pointing to the original.', 'vacanam-language-switcher' ); ?></p></td></tr>
			</table>

			<h2><?php esc_html_e( 'What can be translated', 'vacanam-language-switcher' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Content types', 'vacanam-language-switcher' ); ?></th><td>
					<label><input type="checkbox" checked="checked" disabled="disabled" /> <?php esc_html_e( 'Posts and pages', 'vacanam-language-switcher' ); ?></label><br />
					<?php foreach ( Vacanam_Model::available_post_types() as $name => $type ) : ?>
						<label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, (array) $s['post_types'], true ) ); ?> /> <?php echo esc_html( $type->labels->name ); ?> <code><?php echo esc_html( $name ); ?></code></label><br />
					<?php endforeach; ?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Taxonomies', 'vacanam-language-switcher' ); ?></th><td>
					<label><input type="checkbox" checked="checked" disabled="disabled" /> <?php esc_html_e( 'Categories and tags', 'vacanam-language-switcher' ); ?></label><br />
					<?php foreach ( Vacanam_Model::available_taxonomies() as $name => $tax ) : ?>
						<label><input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, (array) $s['taxonomies'], true ) ); ?> /> <?php echo esc_html( $tax->labels->name ); ?> <code><?php echo esc_html( $name ); ?></code></label><br />
					<?php endforeach; ?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Media', 'vacanam-language-switcher' ); ?></th><td><label><input type="checkbox" name="media" value="yes" <?php checked( $s['media'], 'yes' ); ?> /> <?php esc_html_e( 'Translate the title, alt text and caption of images and files (each translation uses the same file)', 'vacanam-language-switcher' ); ?></label></td></tr>
			</table>

			<h2><?php esc_html_e( 'Synchronization', 'vacanam-language-switcher' ); ?></h2>
			<p class="description"><?php esc_html_e( 'When you save a page, these are copied to its translations. Texts and page-builder designs are never overwritten.', 'vacanam-language-switcher' ); ?></p>
			<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Keep the same', 'vacanam-language-switcher' ); ?></th><td>
				<?php foreach ( Vacanam_Sync::options() as $key => $label ) : ?>
					<label><input type="checkbox" name="sync[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['sync'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label><br />
				<?php endforeach; ?>
			</td></tr></table>

			<?php
			/**
			 * More settings (add-ons).
			 *
			 * @param array $s Settings.
			 */
			do_action( 'vacanam_settings_after', $s );
			?>
			<h2><?php esc_html_e( 'Removing the plugin', 'vacanam-language-switcher' ); ?></h2>
			<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Data', 'vacanam-language-switcher' ); ?></th><td><label><input type="checkbox" name="delete_data" value="yes" <?php checked( $s['delete_data'], 'yes' ); ?> /> <?php esc_html_e( 'When the plugin is deleted, also delete its settings, languages and links between translations (pages and posts are never deleted)', 'vacanam-language-switcher' ); ?></label></td></tr></table>
			<?php submit_button(); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 */
	public static function handle_save_settings() {
		self::check( 'vacanam_save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check().
		$modes  = array_keys( apply_filters( 'vacanam_url_modes', array( 'directory' => 1, 'query' => 1 ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Documented in page_settings().
		$mode   = isset( $_POST['url_mode'] ) ? sanitize_key( wp_unslash( $_POST['url_mode'] ) ) : 'directory';
		$values = array(
			'url_mode'     => in_array( $mode, $modes, true ) ? $mode : 'directory',
			'hide_default' => empty( $_POST['hide_default'] ) ? 'no' : 'yes',
			'browser'      => empty( $_POST['browser'] ) ? 'no' : 'yes',
			'untranslated' => isset( $_POST['untranslated'] ) && 'hide' === $_POST['untranslated'] ? 'hide' : 'home',
			'hreflang'     => empty( $_POST['hreflang'] ) ? 'no' : 'yes',
			'xdefault'     => empty( $_POST['xdefault'] ) ? 'none' : 'default',
			'redirect'     => empty( $_POST['redirect'] ) ? 'no' : 'yes',
			'media'        => empty( $_POST['media'] ) ? 'no' : 'yes',
			'post_types'   => isset( $_POST['post_types'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ), array_keys( Vacanam_Model::available_post_types() ) ) ) : array(),
			'taxonomies'   => isset( $_POST['taxonomies'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['taxonomies'] ) ), array_keys( Vacanam_Model::available_taxonomies() ) ) ) : array(),
			'sync'         => isset( $_POST['sync'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['sync'] ) ), array_keys( Vacanam_Sync::options() ) ) ) : array(),
			'delete_data'  => empty( $_POST['delete_data'] ) ? 'no' : 'yes',
		);
		// phpcs:enable
		/**
		 * Settings about to be saved (add-ons add theirs).
		 *
		 * @param array $values Settings.
		 */
		$values = apply_filters( 'vacanam_save_settings', $values );
		Vacanam_Settings::update( $values );
		delete_option( 'rewrite_rules' );
		self::back( 'vacanam-settings' );
	}
}
