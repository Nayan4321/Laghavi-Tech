<?php
/**
 * Forms and emails in the visitor's language: Contact Form 7 and WPForms forms have a copy per
 * language (labels, messages and the emails they send), shown automatically on pages of that
 * language; WordPress account emails use the language the visitor signed up in.
 *
 * @package VacanamLanguageSwitcherPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Forms.
 */
class VACANAMPRO_Forms {

	/**
	 * Form post types of the active form plugins.
	 *
	 * @return string[]
	 */
	public static function types() {
		$out = array();
		if ( defined( 'WPCF7_VERSION' ) ) {
			$out[] = 'wpcf7_contact_form';
		}
		if ( function_exists( 'wpforms' ) ) {
			$out[] = 'wpforms';
		}
		return $out;
	}

	/**
	 * Hook everything up (licensed).
	 */
	public static function init() {
		add_filter( 'vacanampro_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 37 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'user_register', array( __CLASS__, 'user_locale' ), 20 );
		if ( ! VACANAMPRO_Settings::on( 'forms' ) || ! self::types() ) {
			return;
		}
		add_filter( 'vacanam_post_types', array( __CLASS__, 'post_types' ) );
		add_action( 'vacanam_post_duplicated', array( __CLASS__, 'duplicated' ), 5, 3 );
		add_filter( 'vacanampro_post_parts', array( __CLASS__, 'parts' ), 20, 2 );
		add_action( 'vacanampro_post_translated', array( __CLASS__, 'translated' ), 10, 2 );
		add_action( 'admin_post_vacanampro_copy_form', array( __CLASS__, 'copy_form' ) );
		// Contact Form 7.
		add_filter( 'shortcode_atts_wpcf7', array( __CLASS__, 'cf7_atts' ) );
		add_filter( 'wpcf7_editor_panels', array( __CLASS__, 'cf7_panel' ) );
		// WPForms.
		add_filter( 'shortcode_atts_wpforms', array( __CLASS__, 'wpforms_atts' ) );
		add_filter( 'render_block_data', array( __CLASS__, 'wpforms_block' ) );
		add_filter( 'wpforms_overview_row_actions', array( __CLASS__, 'wpforms_row_actions' ), 20, 2 );
	}

	/**
	 * Defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$defaults['forms']       = 'yes';
		$defaults['user_locale'] = 'yes';
		return $defaults;
	}

	/**
	 * Forms can have translations.
	 *
	 * @param string[] $types Post types.
	 * @return string[]
	 */
	public static function post_types( $types ) {
		return array_merge( $types, self::types() );
	}

	/**
	 * The form of a language (the same one when it has no copy there).
	 *
	 * @param int    $id   Form.
	 * @param string $lang Language ('' for the language of the visit).
	 * @return int
	 */
	public static function form_in( $id, $lang = '' ) {
		$lang = '' !== $lang ? $lang : Vacanam_Router::current();
		if ( ! $id || ( ! Vacanam_Router::is_front() && '' === $lang ) ) {
			return $id;
		}
		$tr = Vacanam_Model::post_translation( (int) $id, $lang );
		return $tr && 'publish' === get_post_status( $tr ) ? (int) $tr : (int) $id;
	}

	/**
	 * A form was copied into a language: give it its own identity.
	 *
	 * @param int    $new  Copy.
	 * @param int    $orig Original.
	 * @param string $lang Language.
	 */
	public static function duplicated( $new, $orig, $lang ) {
		$type = get_post_type( $orig );
		if ( 'wpcf7_contact_form' === $type ) {
			delete_post_meta( $new, '_hash' );
			if ( function_exists( 'wpcf7_generate_contact_form_hash' ) ) {
				add_post_meta( $new, '_hash', wpcf7_generate_contact_form_hash( $new ), true );
			}
			$locale = Vacanam_Languages::get( $lang );
			if ( $locale ) {
				update_post_meta( $new, '_locale', $locale['locale'] );
			}
			wp_update_post(
				array(
					'ID'          => $new,
					'post_status' => 'publish',
				)
			);
		} elseif ( 'wpforms' === $type ) {
			$data = json_decode( (string) get_post_field( 'post_content', $new ), true );
			if ( is_array( $data ) ) {
				$data['id'] = (string) $new;
				wp_update_post(
					wp_slash(
						array(
							'ID'           => $new,
							'post_status'  => 'publish',
							'post_content' => wp_json_encode( $data ),
						)
					)
				);
			}
		}
	}

	/**
	 * Copy a form into a language (from the form screens).
	 */
	public static function copy_form() {
		$id   = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		check_admin_referer( 'vacanampro_copy_form_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) || ! Vacanam_Languages::exists( $lang ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'vacanam-language-switcher-pro' ) );
		}
		if ( '' === Vacanam_Model::post_lang( $id ) ) {
			Vacanam_Model::set_post_lang( $id, Vacanam_Languages::default_slug() );
		}
		$new = Vacanam_Model::post_translation( $id, $lang );
		if ( ! $new ) {
			$new = Vacanam_Duplicator::duplicate_post( $id, $lang, array( 'status' => 'same' ) );
		}
		$url = 'wpforms' === get_post_type( $id ) ? admin_url( 'admin.php?page=wpforms-builder&view=fields&form_id=' . (int) $new ) : admin_url( 'admin.php?page=wpcf7&action=edit&post=' . (int) $new );
		wp_safe_redirect( is_wp_error( $new ) ? wp_get_referer() : $url );
		exit;
	}

	/**
	 * Links to the copies of a form, or to make them.
	 *
	 * @param int $id Form.
	 * @return array lang => array( label, url ).
	 */
	private static function links( $id ) {
		$own   = Vacanam_Model::post_lang( $id );
		$own   = '' !== $own ? $own : Vacanam_Languages::default_slug();
		$type  = get_post_type( $id );
		$group = Vacanam_Model::post_translations( $id );
		$out   = array();
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			if ( $slug === $own ) {
				continue;
			}
			if ( isset( $group[ $slug ] ) ) {
				$url = 'wpforms' === $type ? admin_url( 'admin.php?page=wpforms-builder&view=fields&form_id=' . (int) $group[ $slug ] ) : admin_url( 'admin.php?page=wpcf7&action=edit&post=' . (int) $group[ $slug ] );
				/* translators: %s: language */
				$out[ $slug ] = array( sprintf( __( 'Edit %s', 'vacanam-language-switcher-pro' ), $lang['name'] ), $url );
			} else {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=vacanampro_copy_form&form=' . $id . '&lang=' . $slug ), 'vacanampro_copy_form_' . $id );
				/* translators: %s: language */
				$out[ $slug ] = array( sprintf( __( 'Copy into %s', 'vacanam-language-switcher-pro' ), $lang['name'] ), $url );
			}
		}
		return $out;
	}

	/*
	 * Contact Form 7.
	 */

	/**
	 * [contact-form-7 id="…"] shows the form of the page's language.
	 *
	 * @param array $atts Attributes.
	 * @return array
	 */
	public static function cf7_atts( $atts ) {
		if ( ! Vacanam_Router::is_front() || empty( $atts['id'] ) ) {
			return $atts;
		}
		$id = self::cf7_id( (string) $atts['id'] );
		$tr = self::form_in( $id );
		if ( $tr && $tr !== $id ) {
			$atts['id']    = (string) $tr;
			$atts['title'] = '';
		}
		return $atts;
	}

	/**
	 * Form of a CF7 shortcode id (a hash or a number), in any language. CF7's own lookup only sees
	 * forms of the page's language.
	 *
	 * @param string $id Shortcode id.
	 * @return int
	 */
	private static function cf7_id( $id ) {
		if ( ! preg_match( '/^[0-9a-f]{7,}$/', $id ) || ctype_digit( $id ) && get_post_type( (int) $id ) === 'wpcf7_contact_form' ) {
			return absint( $id );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One lookup per form shown, like CF7's own.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_hash' AND meta_value LIKE %s LIMIT 1", $wpdb->esc_like( $id ) . '%' ) );
		return (int) $found;
	}

	/**
	 * "Languages" tab in the form editor.
	 *
	 * @param array $panels Panels.
	 * @return array
	 */
	public static function cf7_panel( $panels ) {
		$panels['vacanam-panel'] = array(
			'title'    => __( 'Languages', 'vacanam-language-switcher-pro' ),
			'callback' => array( __CLASS__, 'cf7_panel_html' ),
		);
		return $panels;
	}

	/**
	 * The tab.
	 *
	 * @param WPCF7_ContactForm $form Form.
	 */
	public static function cf7_panel_html( $form ) {
		echo '<h2>' . esc_html__( 'Languages', 'vacanam-language-switcher-pro' ) . '</h2>';
		if ( ! $form->id() ) {
			echo '<p>' . esc_html__( 'Save the form first.', 'vacanam-language-switcher-pro' ) . '</p>';
			return;
		}
		$lang = Vacanam_Languages::get( Vacanam_Model::post_lang( $form->id() ) );
		echo '<p>' . esc_html( sprintf( /* translators: %s: language */ __( 'This form is in %s. Pages in another language show that language\'s copy of the form automatically, with its own labels, messages and emails.', 'vacanam-language-switcher-pro' ), $lang ? $lang['name'] : Vacanam_Languages::get( Vacanam_Languages::default_slug() )['name'] ) ) . '</p><p>';
		foreach ( self::links( $form->id() ) as $link ) {
			echo '<a class="button" href="' . esc_url( $link[1] ) . '">' . esc_html( $link[0] ) . '</a> ';
		}
		echo '</p>';
	}

	/*
	 * WPForms.
	 */

	/**
	 * [wpforms id="…"] shows the form of the page's language.
	 *
	 * @param array $atts Attributes.
	 * @return array
	 */
	public static function wpforms_atts( $atts ) {
		if ( Vacanam_Router::is_front() && ! empty( $atts['id'] ) ) {
			$atts['id'] = self::form_in( absint( $atts['id'] ) );
		}
		return $atts;
	}

	/**
	 * The WPForms block shows the form of the page's language.
	 *
	 * @param array $block Block.
	 * @return array
	 */
	public static function wpforms_block( $block ) {
		if ( isset( $block['blockName'] ) && 'wpforms/form-selector' === $block['blockName'] && ! empty( $block['attrs']['formId'] ) && Vacanam_Router::is_front() ) {
			$block['attrs']['formId'] = (string) self::form_in( absint( $block['attrs']['formId'] ) );
		}
		return $block;
	}

	/**
	 * Language links under each form in WPForms > All Forms.
	 *
	 * @param array  $actions Actions.
	 * @param object $form    Form.
	 * @return array
	 */
	public static function wpforms_row_actions( $actions, $form ) {
		$id = isset( $form->ID ) ? (int) $form->ID : 0;
		if ( $id && current_user_can( 'edit_post', $id ) ) {
			foreach ( self::links( $id ) as $slug => $link ) {
				$actions[ 'vacanam_' . $slug ] = '<a href="' . esc_url( $link[1] ) . '">' . esc_html( $link[0] ) . '</a>';
			}
		}
		return $actions;
	}

	/*
	 * AI translation of forms.
	 */

	/**
	 * Forms keep their content as code: it is translated piece by piece after the title.
	 *
	 * @param array   $parts Parts.
	 * @param WP_Post $post  Original.
	 * @return array
	 */
	public static function parts( $parts, $post ) {
		if ( in_array( $post->post_type, array( 'wpcf7_contact_form', 'wpforms' ), true ) ) {
			$parts['content'] = '';
			$parts['excerpt'] = '';
			unset( $parts['meta']['_form'] );
		}
		return $parts;
	}

	/**
	 * Translate the labels, messages and emails of a form copy.
	 *
	 * @param int $id     Copy.
	 * @param int $source Original.
	 */
	public static function translated( $id, $source ) {
		$type = get_post_type( $id );
		$from = Vacanam_Model::post_lang( $source );
		$to   = Vacanam_Model::post_lang( $id );
		if ( 'wpcf7_contact_form' === $type ) {
			$form  = (string) get_post_meta( $source, '_form', true );
			$texts = self::cf7_texts( $form );
			$mails = array();
			foreach ( array( '_mail', '_mail_2' ) as $key ) {
				$mail = get_post_meta( $source, $key, true );
				if ( is_array( $mail ) ) {
					$mails[ $key ] = $mail;
					foreach ( array( 'subject', 'body' ) as $field ) {
						if ( ! empty( $mail[ $field ] ) ) {
							$texts[] = (string) $mail[ $field ];
						}
					}
				}
			}
			$messages = get_post_meta( $source, '_messages', true );
			if ( is_array( $messages ) ) {
				$texts = array_merge( $texts, array_values( array_filter( array_map( 'strval', $messages ) ) ) );
			}
			$map = self::run( $texts, $from, $to );
			if ( null === $map ) {
				return;
			}
			update_post_meta( $id, '_form', wp_slash( self::cf7_apply( $form, $map ) ) );
			foreach ( $mails as $key => $mail ) {
				foreach ( array( 'subject', 'body' ) as $field ) {
					if ( isset( $mail[ $field ], $map[ $mail[ $field ] ] ) ) {
						$mail[ $field ] = $map[ $mail[ $field ] ];
					}
				}
				update_post_meta( $id, $key, wp_slash( $mail ) );
			}
			if ( is_array( $messages ) ) {
				foreach ( $messages as $key => $message ) {
					if ( isset( $map[ (string) $message ] ) ) {
						$messages[ $key ] = $map[ (string) $message ];
					}
				}
				update_post_meta( $id, '_messages', wp_slash( $messages ) );
			}
		} elseif ( 'wpforms' === $type ) {
			$data = json_decode( (string) get_post_field( 'post_content', $source ), true );
			if ( ! is_array( $data ) ) {
				return;
			}
			$texts = array();
			self::wpforms_walk( $data, $texts, null );
			$map = self::run( $texts, $from, $to );
			if ( null === $map ) {
				return;
			}
			self::wpforms_walk( $data, $texts, $map );
			$data['id'] = (string) $id;
			if ( isset( $data['settings']['form_title'] ) ) {
				$data['settings']['form_title'] = get_the_title( $id );
			}
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $id,
						'post_content' => wp_json_encode( $data ),
					)
				)
			);
		}
	}

	/**
	 * Translate texts (null on failure).
	 *
	 * @param string[] $texts Texts.
	 * @param string   $from  Language.
	 * @param string   $to    Language.
	 * @return array|null
	 */
	private static function run( $texts, $from, $to ) {
		$texts = array_values( array_filter( array_unique( array_map( 'trim', $texts ) ), array( 'VACANAMPRO_Translator', 'has_words' ) ) );
		if ( ! $texts ) {
			return array();
		}
		$result = VACANAMPRO_Translator::translate_segments( $texts, $from, $to, 4 );
		return is_wp_error( $result ) ? null : $result['map'];
	}

	/**
	 * Human texts of a CF7 form template: labels between tags, placeholders and quoted options.
	 *
	 * @param string $form Template.
	 * @return string[]
	 */
	private static function cf7_texts( $form ) {
		$out = array();
		// Text outside [tags] and <html>.
		foreach ( preg_split( '/\[[^\]]*\]|<[^>]*>/', $form ) as $piece ) {
			if ( '' !== trim( $piece ) ) {
				$out[] = trim( $piece );
			}
		}
		// Quoted values inside tags: options, placeholders, button labels.
		if ( preg_match_all( '/\[[^\]]*\]/', $form, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match_all( '/"([^"]+)"/', $tag, $quoted ) ) {
					$out = array_merge( $out, $quoted[1] );
				}
			}
		}
		return $out;
	}

	/**
	 * Put translations into a CF7 form template.
	 *
	 * @param string $form Template.
	 * @param array  $map  text => translation.
	 * @return string
	 */
	private static function cf7_apply( $form, $map ) {
		$parts = preg_split( '/(\[[^\]]*\]|<[^>]*>)/', $form, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '[' === $part[0] ) {
				$parts[ $i ] = preg_replace_callback(
					'/"([^"]+)"/',
					static function ( $m ) use ( $map ) {
						return '"' . ( isset( $map[ $m[1] ] ) ? str_replace( '"', '”', $map[ $m[1] ] ) : $m[1] ) . '"';
					},
					$part
				);
			} elseif ( '<' !== $part[0] && isset( $map[ trim( $part ) ] ) ) {
				$parts[ $i ] = str_replace( trim( $part ), $map[ trim( $part ) ], $part );
			}
		}
		return implode( '', $parts );
	}

	/**
	 * Collect (or replace, when $map is given) the texts of a WPForms form.
	 *
	 * @param array      $data  Form data.
	 * @param array      $texts Texts found.
	 * @param array|null $map   Translations.
	 */
	private static function wpforms_walk( &$data, &$texts, $map ) {
		$keys = array( 'label', 'description', 'placeholder', 'default_value', 'submit_text', 'submit_text_processing', 'confirmation_message', 'notification_subject', 'subject', 'message', 'sender_name', 'label_hide_text', 'page_title', 'next', 'prev', 'title', 'form_desc' );
		foreach ( $data as $key => &$value ) {
			if ( is_array( $value ) ) {
				self::wpforms_walk( $value, $texts, $map );
			} elseif ( is_string( $value ) && in_array( (string) $key, $keys, true ) && '' !== trim( $value ) && false === strpos( $value, '{field_id' ) ) {
				if ( null === $map ) {
					$texts[] = $value;
				} elseif ( isset( $map[ trim( $value ) ] ) ) {
					$value = $map[ trim( $value ) ];
				}
			}
		}
		unset( $value );
	}

	/*
	 * WordPress emails.
	 */

	/**
	 * Someone signed up on a page in a language: their account emails (password reset, new
	 * account) come in that language.
	 *
	 * @param int $user_id User.
	 */
	public static function user_locale( $user_id ) {
		if ( ! VACANAMPRO_Settings::on( 'user_locale' ) || ! Vacanam_Router::is_front() || is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$lang = Vacanam_Languages::get( Vacanam_Router::current() );
		if ( $lang && Vacanam_Languages::default_slug() !== $lang['slug'] && '' === (string) get_user_meta( $user_id, 'locale', true ) ) {
			update_user_meta( $user_id, 'locale', $lang['locale'] );
		}
	}

	/*
	 * Settings.
	 */

	/**
	 * Section.
	 */
	public static function section() {
		$found = array();
		if ( defined( 'WPCF7_VERSION' ) ) {
			$found[] = 'Contact Form 7';
		}
		if ( function_exists( 'wpforms' ) ) {
			$found[] = 'WPForms';
		}
		echo '<div class="vacanam-card"><h2>' . esc_html__( 'Forms and emails', 'vacanam-language-switcher-pro' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Forms', 'vacanam-language-switcher-pro' ) . '</th><td><label><input type="checkbox" name="vacanampro_forms" value="1"' . checked( VACANAMPRO_Settings::on( 'forms' ), true, false ) . ' /> ' . esc_html__( 'A copy of each Contact Form 7 and WPForms form per language, shown automatically on pages of that language, with its own labels, messages and notification emails', 'vacanam-language-switcher-pro' ) . '</label><p class="description">' . esc_html( $found ? sprintf( /* translators: %s: plugin names */ __( 'Found: %s. Copy a form from its "Languages" tab (Contact Form 7) or from the links under it in WPForms > All Forms; with AI translation set up, the copy can be translated in one click from Languages > Copy & translate.', 'vacanam-language-switcher-pro' ), implode( ', ', $found ) ) : __( 'Neither Contact Form 7 nor WPForms is active.', 'vacanam-language-switcher-pro' ) ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Account emails', 'vacanam-language-switcher-pro' ) . '</th><td><label><input type="checkbox" name="vacanampro_user_locale" value="1"' . checked( VACANAMPRO_Settings::on( 'user_locale' ), true, false ) . ' /> ' . esc_html__( 'People who sign up on a page in a language get WordPress emails (new account, password reset) in that language', 'vacanam-language-switcher-pro' ) . '</label></td></tr>';
		echo '</table></div>';
	}

	/**
	 * Save.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save_settings( $values ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in VACANAMPRO_Settings::save().
		$values['forms']       = empty( $_POST['vacanampro_forms'] ) ? 'no' : 'yes';
		$values['user_locale'] = empty( $_POST['vacanampro_user_locale'] ) ? 'no' : 'yes';
		// phpcs:enable
		return $values;
	}
}
