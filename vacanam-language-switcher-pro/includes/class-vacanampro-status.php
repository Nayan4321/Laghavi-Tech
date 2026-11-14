<?php
/**
 * Translation status: complete, partial (copied but not finished, or the original changed since) or
 * missing, in the lists, in the editor, on an overview screen and in the language switchers.
 *
 * @package VacanamLanguageSwitcherPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status.
 */
class VACANAMPRO_Status {

	const PAGE = 'vacanam-pro-status';
	const META = '_vacanampro_done';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'vacanampro_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 50 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save_settings' ) );
		add_filter( 'vacanam_translation_cell', array( __CLASS__, 'cell' ), 10, 2 );
		add_action( 'vacanam_post_box_after', array( __CLASS__, 'post_box' ), 5 );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 30, 2 );
		add_action( 'vacanampro_post_translated', array( __CLASS__, 'mark' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_filter( 'vacanam_admin_tabs', array( __CLASS__, 'tab' ), 26 );
		add_filter( 'vacanam_switcher_items', array( __CLASS__, 'switcher_items' ), 20, 2 );
	}

	/**
	 * Defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$defaults['switcher_missing'] = '';
		return $defaults;
	}

	/**
	 * Status of a translation.
	 *
	 * @param int $tr Translation.
	 * @return array { status: complete|partial|outdated|original|none, label }
	 */
	public static function get( $tr ) {
		$post = get_post( $tr );
		if ( ! $post ) {
			return array(
				'status' => 'none',
				'label'  => '',
			);
		}
		if ( Vacanam_Model::post_lang( $post ) === Vacanam_Languages::default_slug() ) {
			return array(
				'status' => 'original',
				'label'  => __( 'Original', 'vacanam-language-switcher-pro' ),
			);
		}
		$source = get_post( VACANAMPRO_Translator::source_post( $post->ID ) );
		$done   = get_post_meta( $post->ID, self::META, true );
		if ( ! is_array( $done ) ) {
			// Translated with AI before statuses were kept.
			$ai   = get_post_meta( $post->ID, '_vacanampro_translated', true );
			$done = is_array( $ai ) && ! empty( $ai['time'] ) ? array( 'time' => (int) $ai['time'] ) : '';
		}
		if ( ! $source || ! is_array( $done ) || empty( $done['time'] ) ) {
			return array(
				'status' => 'partial',
				'label'  => __( 'Partly translated: not marked as complete yet', 'vacanam-language-switcher-pro' ),
			);
		}
		if ( strtotime( $source->post_modified_gmt . ' UTC' ) > (int) $done['time'] ) {
			return array(
				'status' => 'outdated',
				'label'  => __( 'The original changed since it was translated', 'vacanam-language-switcher-pro' ),
			);
		}
		return array(
			'status' => 'complete',
			'label'  => __( 'Translated', 'vacanam-language-switcher-pro' ),
		);
	}

	/**
	 * Mark a translation complete, as of now.
	 *
	 * @param int $tr     Translation.
	 * @param int $source Original (unused, kept for the action's signature).
	 */
	public static function mark( $tr, $source = 0 ) {
		unset( $source );
		update_post_meta( $tr, self::META, array( 'time' => time() + 1 ) );
	}

	/**
	 * Colour of a cell in the lists.
	 *
	 * @param array $info Info.
	 * @param int   $tr   Translation.
	 * @return array
	 */
	public static function cell( $info, $tr ) {
		$status = self::get( $tr );
		if ( in_array( $status['status'], array( 'complete', 'partial', 'outdated' ), true ) ) {
			$info['class'] = 'vacanam-status-' . $status['status'];
			$info['title'] = $status['label'];
		}
		return $info;
	}

	/**
	 * Checkbox in the editor of a translation.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function post_box( $post ) {
		$lang = Vacanam_Model::post_lang( $post );
		if ( '' === $lang || Vacanam_Languages::default_slug() === $lang || ! VACANAMPRO_Translator::source_post( $post->ID ) ) {
			return;
		}
		$status = self::get( $post->ID );
		wp_nonce_field( 'vacanampro_status', 'vacanampro_status_nonce' );
		echo '<p class="vacanampro-status vacanampro-status-' . esc_attr( $status['status'] ) . '"><label><input type="checkbox" name="vacanampro_complete" value="1"' . checked( 'complete', $status['status'], false ) . ' /> ' . esc_html__( 'This translation is complete', 'vacanam-language-switcher-pro' ) . '</label>';
		if ( 'outdated' === $status['status'] ) {
			echo '<br /><span class="description">' . esc_html( $status['label'] ) . '. ' . esc_html__( 'Check it, then tick the box again.', 'vacanam-language-switcher-pro' ) . '</span>';
		}
		echo '<input type="hidden" name="vacanampro_status_box" value="1" /></p>';
	}

	/**
	 * Save the checkbox.
	 *
	 * @param int     $id   Post.
	 * @param WP_Post $post Post.
	 */
	public static function save_post( $id, $post ) {
		if ( wp_is_post_revision( $id ) || ! isset( $_POST['vacanampro_status_box'], $_POST['vacanampro_status_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vacanampro_status_nonce'] ) ), 'vacanampro_status' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		unset( $post );
		if ( empty( $_POST['vacanampro_complete'] ) ) {
			delete_post_meta( $id, self::META );
		} elseif ( 'complete' !== self::get( $id )['status'] ) {
			self::mark( $id );
		}
	}

	/*
	 * Overview.
	 */

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( 'vacanam', __( 'Translation status', 'vacanam-language-switcher-pro' ), __( 'Translation status', 'vacanam-language-switcher-pro' ), VACANAMPRO_Settings::capability(), self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs[ self::PAGE ] = __( 'Status', 'vacanam-language-switcher-pro' );
		return $tabs;
	}

	/**
	 * Count statuses of a content type.
	 *
	 * @param string $type Post type.
	 * @return array lang => { complete, partial, outdated, missing, items: [ id, status, tr ] }
	 */
	public static function report( $type ) {
		$main  = Vacanam_Languages::default_slug();
		$ids   = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'lang'           => $main,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out   = array();
		$langs = array_diff( Vacanam_Languages::slugs(), array( $main ) );
		foreach ( $langs as $slug ) {
			$out[ $slug ] = array(
				'complete' => 0,
				'partial'  => 0,
				'outdated' => 0,
				'missing'  => 0,
				'items'    => array(),
			);
		}
		foreach ( $ids as $id ) {
			if ( Vacanam_Model::post_lang( $id ) !== $main ) {
				continue;
			}
			$group = Vacanam_Model::post_translations( $id );
			foreach ( $langs as $slug ) {
				if ( empty( $group[ $slug ] ) ) {
					$status = 'missing';
					$tr     = 0;
				} else {
					$tr     = (int) $group[ $slug ];
					$status = self::get( $tr )['status'];
				}
				if ( ! isset( $out[ $slug ][ $status ] ) ) {
					continue;
				}
				++$out[ $slug ][ $status ];
				if ( 'complete' !== $status ) {
					$out[ $slug ]['items'][] = array( $id, $status, $tr );
				}
			}
		}
		return $out;
	}

	/**
	 * The overview screen.
	 */
	public static function page() {
		if ( ! current_user_can( VACANAMPRO_Settings::capability() ) ) {
			return;
		}
		Vacanam_Admin::header( __( 'Translation status', 'vacanam-language-switcher-pro' ), self::PAGE );
		$langs = Vacanam_Languages::all();
		$names = array(
			'complete' => __( 'Translated', 'vacanam-language-switcher-pro' ),
			'partial'  => __( 'Partly translated', 'vacanam-language-switcher-pro' ),
			'outdated' => __( 'Original changed', 'vacanam-language-switcher-pro' ),
			'missing'  => __( 'Missing', 'vacanam-language-switcher-pro' ),
		);
		echo '<div class="vacanam-card"><p>' . esc_html__( 'How much of each language is done. A translation counts as complete once you tick "This translation is complete" in its editor, or when it was translated with AI; it shows "Original changed" when the original was edited after that.', 'vacanam-language-switcher-pro' ) . '</p>';
		echo '<p class="vacanampro-legend">';
		foreach ( $names as $key => $label ) {
			echo '<span class="vacanampro-dot vacanampro-dot-' . esc_attr( $key ) . '"></span> ' . esc_html( $label ) . ' &nbsp; ';
		}
		echo '</p></div>';
		foreach ( Vacanam_Model::post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object || 'attachment' === $type || ! $object->show_ui ) {
				continue;
			}
			$report = self::report( $type );
			$total  = 0;
			foreach ( $report as $row ) {
				$total = max( $total, $row['complete'] + $row['partial'] + $row['outdated'] + $row['missing'] );
			}
			if ( ! $total ) {
				continue;
			}
			echo '<div class="vacanam-card"><h2>' . esc_html( $object->labels->name ) . ' <span class="description">(' . esc_html( number_format_i18n( $total ) ) . ')</span></h2><table class="widefat striped vacanampro-status-table"><tbody>';
			foreach ( $report as $slug => $row ) {
				echo '<tr><th style="width:14%">' . wp_kses_post( Vacanam_Admin::flag( $langs[ $slug ] ) ) . ' ' . esc_html( $langs[ $slug ]['name'] ) . '</th><td><div class="vacanampro-bar">';
				foreach ( array_keys( $names ) as $key ) {
					if ( $row[ $key ] ) {
						echo '<span class="vacanampro-dot-' . esc_attr( $key ) . '" style="width:' . esc_attr( round( 100 * $row[ $key ] / $total, 2 ) ) . '%" title="' . esc_attr( $names[ $key ] . ': ' . $row[ $key ] ) . '"></span>';
					}
				}
				echo '</div><p class="description">';
				foreach ( $names as $key => $label ) {
					echo esc_html( $label . ': ' . number_format_i18n( $row[ $key ] ) ) . ' &nbsp; ';
				}
				echo '</p>';
				if ( $row['items'] ) {
					echo '<details><summary>' . esc_html__( 'Show what needs work', 'vacanam-language-switcher-pro' ) . '</summary><ul class="vacanampro-todo">';
					foreach ( array_slice( $row['items'], 0, 200 ) as $item ) {
						list( $id, $status, $tr ) = $item;
						$link = $tr ? get_edit_post_link( $tr, 'raw' ) : Vacanam_Admin_Content::duplicate_url( $id, $slug );
						echo '<li><span class="vacanampro-dot vacanampro-dot-' . esc_attr( $status ) . '"></span> <a href="' . esc_url( (string) $link ) . '">' . esc_html( get_the_title( $tr ? $tr : $id ) ) . '</a> <span class="description">' . esc_html( $names[ $status ] ) . ( $tr ? '' : ' · ' . __( 'copy it into this language', 'vacanam-language-switcher-pro' ) ) . '</span></li>';
					}
					echo '</ul></details>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';
	}

	/*
	 * Switchers.
	 */

	/**
	 * Show languages this page is not translated into as "not translated yet".
	 *
	 * @param array $items Items.
	 * @param array $args  Settings.
	 * @return array
	 */
	public static function switcher_items( $items, $args ) {
		if ( 'dim' !== VACANAMPRO_Settings::get( 'switcher_missing' ) || is_admin() || ! did_action( 'wp' ) ) {
			return $items;
		}
		$object = get_queried_object();
		$out    = array();
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			if ( isset( $items[ $slug ] ) ) {
				$out[ $slug ] = $items[ $slug ];
			}
			if ( ! $lang['active'] && ! current_user_can( 'edit_posts' ) ) {
				continue;
			}
			$missing = false;
			if ( $object instanceof WP_Post && Vacanam_Model::is_translated_type( $object->post_type ) ) {
				$tr      = Vacanam_Model::post_translation( $object->ID, $slug );
				$missing = ! $tr || 'publish' !== get_post_status( $tr );
			} elseif ( $object instanceof WP_Term && Vacanam_Model::is_translated_tax( $object->taxonomy ) ) {
				$missing = ! Vacanam_Model::term_translation( $object->term_id, $slug );
			}
			if ( ! $missing ) {
				continue;
			}
			if ( ! empty( $args['hide_current'] ) && vacanam_current_language() === $slug ) {
				continue;
			}
			$out[ $slug ] = array(
				'slug'     => $slug,
				'name'     => $lang['name'],
				'code'     => strtoupper( strtok( $slug, '-' ) ),
				'flag'     => Vacanam_Languages::lang_flag_url( $lang ),
				'url'      => Vacanam_Router::home_for( $slug ),
				'current'  => vacanam_current_language() === $slug,
				'rtl'      => $lang['rtl'],
				'hreflang' => Vacanam_Languages::hreflang( $lang ),
				/* translators: %s: language */
				'missing'  => sprintf( __( 'This page is not available in %s yet. Opens the home page.', 'vacanam-language-switcher-pro' ), $lang['name'] ),
			);
		}
		return $out;
	}

	/*
	 * Settings.
	 */

	/**
	 * Section.
	 */
	public static function section() {
		$value = (string) VACANAMPRO_Settings::get( 'switcher_missing' );
		echo '<div class="vacanam-card"><h2>' . esc_html__( 'Pages that are not translated', 'vacanam-language-switcher-pro' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'In language switchers', 'vacanam-language-switcher-pro' ) . '</th><td>';
		echo '<label><input type="radio" name="vacanampro_switcher_missing" value=""' . checked( '', $value, false ) . ' /> ' . esc_html__( 'As set in Languages > Settings (link to the home page, or hide the language)', 'vacanam-language-switcher-pro' ) . '</label><br />';
		echo '<label><input type="radio" name="vacanampro_switcher_missing" value="dim"' . checked( 'dim', $value, false ) . ' /> ' . esc_html__( 'Show the language faded, with "not available in this language yet" (it opens the home page)', 'vacanam-language-switcher-pro' ) . '</label>';
		echo '</td></tr></table></div>';
	}

	/**
	 * Save.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save_settings( $values ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in VACANAMPRO_Settings::save().
		$values['switcher_missing'] = isset( $_POST['vacanampro_switcher_missing'] ) && 'dim' === $_POST['vacanampro_switcher_missing'] ? 'dim' : '';
		return $values;
	}
}
