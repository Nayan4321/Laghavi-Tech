<?php
/**
 * AI translation in the admin: settings, the "Translate with AI" button in the Languages box of each
 * translation, translating the whole site on the Copy & translate screen, translating texts, text
 * in images, and translating new copies automatically in the background.
 *
 * @package VacanamPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * AI translation screens.
 */
class VACANAMPRO_AI_Admin {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		VACANAMPRO_Translator::install();
		add_filter( 'vacanampro_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 20 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'vacanam_post_box_after', array( __CLASS__, 'post_box' ) );
		add_action( 'vacanam_copy_page_after', array( __CLASS__, 'copy_page' ) );
		add_action( 'vacanam_strings_page_after', array( __CLASS__, 'strings_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'post', 'queue', 'job', 'images', 'image_make', 'image_use', 'image_discard', 'strings' ) as $action ) {
			add_action( 'wp_ajax_vacanampro_ai_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		add_action( 'vacanam_post_duplicated', array( __CLASS__, 'auto_post' ), 20, 3 );
		add_action( 'vacanam_term_duplicated', array( __CLASS__, 'auto_term' ), 20, 3 );
		add_action( 'vacanampro_ai_auto', array( __CLASS__, 'run_auto' ) );
	}

	/**
	 * Default settings.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'ai_provider'       => 'anthropic',
				'ai_keys'           => array(),
				'ai_models'         => array(),
				'ai_tone'           => 'neutral',
				'ai_arabic'         => 'msa',
				'ai_about'          => '',
				'ai_glossary'       => '',
				'ai_auto'           => 'no',
				'ai_image_provider' => 'openai',
			)
		);
	}

	/*
	 * Settings.
	 */

	/**
	 * Settings section.
	 */
	public static function section() {
		$keys     = (array) VACANAMPRO_Settings::get( 'ai_keys' );
		$models   = (array) VACANAMPRO_Settings::get( 'ai_models' );
		$provider = VACANAMPRO_AI::chosen()['provider'];
		$links    = array(
			'anthropic' => 'https://platform.claude.com/settings/keys',
			'openai'    => 'https://platform.openai.com/api-keys',
			'google'    => 'https://aistudio.google.com/apikey',
			'deepl'     => 'https://www.deepl.com/your-account/keys',
		);
		?>
		<div class="vacanam-card">
			<h2><?php esc_html_e( 'AI and DeepL translation', 'vacanam-language-switcher-pro' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Translations use your own API key, so you pay the service directly at its own prices. Every translation is remembered: the same sentence is never paid for twice.', 'vacanam-language-switcher-pro' ); ?></p>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Translate with', 'vacanam-language-switcher-pro' ); ?></th><td>
					<?php foreach ( VACANAMPRO_AI::providers() as $id => $label ) : ?>
						<label style="margin-right:18px"><input type="radio" name="vacanampro_ai_provider" value="<?php echo esc_attr( $id ); ?>" <?php checked( $provider, $id ); ?> /> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</td></tr>
				<?php foreach ( VACANAMPRO_AI::providers() as $id => $label ) : ?>
					<tr><th scope="row"><?php echo esc_html( sprintf( /* translators: %s: service */ __( '%s API key', 'vacanam-language-switcher-pro' ), $label ) ); ?></th><td>
						<input type="password" class="regular-text" autocomplete="new-password" name="vacanampro_ai_keys[<?php echo esc_attr( $id ); ?>]" value="" placeholder="<?php echo esc_attr( ! empty( $keys[ $id ] ) ? __( 'Saved (type a new key to replace it)', 'vacanam-language-switcher-pro' ) : '' ); ?>" />
						<?php if ( ! empty( $keys[ $id ] ) ) : ?>
							<label><input type="checkbox" name="vacanampro_ai_remove[<?php echo esc_attr( $id ); ?>]" value="1" /> <?php esc_html_e( 'Remove', 'vacanam-language-switcher-pro' ); ?></label>
						<?php endif; ?>
						<a href="<?php echo esc_url( $links[ $id ] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get a key', 'vacanam-language-switcher-pro' ); ?></a>
						<?php if ( VACANAMPRO_AI::models( $id ) ) : ?>
							<br /><label><?php esc_html_e( 'Model', 'vacanam-language-switcher-pro' ); ?>
								<input type="text" list="vacanampro-models-<?php echo esc_attr( $id ); ?>" name="vacanampro_ai_models[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( isset( $models[ $id ] ) ? $models[ $id ] : '' ); ?>" placeholder="<?php echo esc_attr( (string) current( VACANAMPRO_AI::models( $id ) ) ); ?>" /></label>
							<datalist id="vacanampro-models-<?php echo esc_attr( $id ); ?>"><?php foreach ( VACANAMPRO_AI::models( $id ) as $model ) : ?><option value="<?php echo esc_attr( $model ); ?>"></option><?php endforeach; ?></datalist>
						<?php endif; ?>
					</td></tr>
				<?php endforeach; ?>
				<tr><th scope="row"><?php esc_html_e( 'Tone', 'vacanam-language-switcher-pro' ); ?></th><td>
					<select name="vacanampro_ai_tone">
						<?php
						foreach ( array(
							'neutral'  => __( 'Like the original', 'vacanam-language-switcher-pro' ),
							'formal'   => __( 'Formal', 'vacanam-language-switcher-pro' ),
							'friendly' => __( 'Friendly', 'vacanam-language-switcher-pro' ),
						) as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( VACANAMPRO_Settings::get( 'ai_tone' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Arabic', 'vacanam-language-switcher-pro' ); ?></th><td>
					<select name="vacanampro_ai_arabic">
						<option value="msa" <?php selected( VACANAMPRO_Settings::get( 'ai_arabic' ), 'msa' ); ?>><?php esc_html_e( 'Modern Standard Arabic', 'vacanam-language-switcher-pro' ); ?></option>
						<option value="gulf" <?php selected( VACANAMPRO_Settings::get( 'ai_arabic' ), 'gulf' ); ?>><?php esc_html_e( 'Modern Standard Arabic for Gulf readers (UAE, Saudi Arabia…)', 'vacanam-language-switcher-pro' ); ?></option>
					</select>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'About your website', 'vacanam-language-switcher-pro' ); ?></th><td>
					<input type="text" class="large-text" name="vacanampro_ai_about" value="<?php echo esc_attr( (string) VACANAMPRO_Settings::get( 'ai_about' ) ); ?>" placeholder="<?php esc_attr_e( 'For example: a dental clinic in Dubai, or a shop for handmade leather bags', 'vacanam-language-switcher-pro' ); ?>" />
					<p class="description"><?php esc_html_e( 'One sentence helps the AI choose the right words for your field.', 'vacanam-language-switcher-pro' ); ?></p>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Glossary', 'vacanam-language-switcher-pro' ); ?></th><td>
					<textarea class="large-text code" rows="5" name="vacanampro_ai_glossary" placeholder="<?php echo esc_attr( "Laghavi\nKaizen AMS\nCheckout => ar: الدفع, fr: Paiement" ); ?>"><?php echo esc_textarea( (string) VACANAMPRO_Settings::get( 'ai_glossary' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line. A word alone is never translated (brand and product names). "word => ar: translation, fr: translation" is always translated that way.', 'vacanam-language-switcher-pro' ); ?></p>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'New copies', 'vacanam-language-switcher-pro' ); ?></th><td>
					<label><input type="checkbox" name="vacanampro_ai_auto" value="1" <?php checked( VACANAMPRO_Settings::on( 'ai_auto' ) ); ?> /> <?php esc_html_e( 'Translate pages, posts and categories automatically when they are copied into a language (in the background, a minute or two later).', 'vacanam-language-switcher-pro' ); ?></label>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Text in images', 'vacanam-language-switcher-pro' ); ?></th><td>
					<select name="vacanampro_ai_image_provider">
						<option value="openai" <?php selected( VACANAMPRO_Settings::get( 'ai_image_provider' ), 'openai' ); ?>>OpenAI (gpt-image-1)</option>
						<option value="google" <?php selected( VACANAMPRO_Settings::get( 'ai_image_provider' ), 'google' ); ?>>Google (Gemini image)</option>
					</select>
					<p class="description"><?php esc_html_e( 'Banners and graphics with words in them are drawn again with the text in the page\'s language. You see the new image next to the original and choose whether to use it. Uses the OpenAI or Google key above.', 'vacanam-language-switcher-pro' ); ?></p>
				</td></tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Save the section.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save( $values ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in VACANAMPRO_Settings::save().
		$provider              = isset( $_POST['vacanampro_ai_provider'] ) ? sanitize_key( wp_unslash( $_POST['vacanampro_ai_provider'] ) ) : 'anthropic';
		$values['ai_provider'] = isset( VACANAMPRO_AI::providers()[ $provider ] ) ? $provider : 'anthropic';
		$keys                  = (array) VACANAMPRO_Settings::get( 'ai_keys' );
		$posted                = isset( $_POST['vacanampro_ai_keys'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['vacanampro_ai_keys'] ) ) : array();
		$remove                = isset( $_POST['vacanampro_ai_remove'] ) ? array_map( 'sanitize_key', array_keys( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['vacanampro_ai_remove'] ) ) ) ) : array();
		foreach ( array_keys( VACANAMPRO_AI::providers() ) as $id ) {
			if ( in_array( $id, $remove, true ) ) {
				unset( $keys[ $id ] );
			} elseif ( ! empty( $posted[ $id ] ) ) {
				$keys[ $id ] = VACANAMPRO_AI::encrypt( trim( $posted[ $id ] ) );
			}
		}
		$values['ai_keys']   = $keys;
		$models              = isset( $_POST['vacanampro_ai_models'] ) ? (array) wp_unslash( $_POST['vacanampro_ai_models'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		$values['ai_models'] = array();
		foreach ( $models as $id => $model ) {
			$model = preg_replace( '/[^A-Za-z0-9._:\/-]/', '', (string) $model );
			if ( isset( VACANAMPRO_AI::providers()[ $id ] ) && '' !== $model ) {
				$values['ai_models'][ $id ] = $model;
			}
		}
		$tone                        = isset( $_POST['vacanampro_ai_tone'] ) ? sanitize_key( wp_unslash( $_POST['vacanampro_ai_tone'] ) ) : 'neutral';
		$values['ai_tone']           = in_array( $tone, array( 'neutral', 'formal', 'friendly' ), true ) ? $tone : 'neutral';
		$values['ai_arabic']         = isset( $_POST['vacanampro_ai_arabic'] ) && 'gulf' === $_POST['vacanampro_ai_arabic'] ? 'gulf' : 'msa';
		$values['ai_about']          = isset( $_POST['vacanampro_ai_about'] ) ? sanitize_text_field( wp_unslash( $_POST['vacanampro_ai_about'] ) ) : '';
		$values['ai_glossary']       = isset( $_POST['vacanampro_ai_glossary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vacanampro_ai_glossary'] ) ) : '';
		$values['ai_auto']           = empty( $_POST['vacanampro_ai_auto'] ) ? 'no' : 'yes';
		$values['ai_image_provider'] = isset( $_POST['vacanampro_ai_image_provider'] ) && 'google' === $_POST['vacanampro_ai_image_provider'] ? 'google' : 'openai';
		// phpcs:enable
		return $values;
	}

	/*
	 * Screens.
	 */

	/**
	 * Script and styles on the screens that use them.
	 *
	 * @param string $hook Admin page.
	 */
	public static function assets( $hook ) {
		$screens = array( 'post.php', 'post-new.php' );
		if ( ! in_array( $hook, $screens, true ) && false === strpos( (string) $hook, 'vacanam' ) ) {
			return;
		}
		wp_enqueue_style( 'vacanampro-admin', VACANAMPRO_URL . 'assets/css/admin.css', array(), VACANAMPRO_VERSION );
		wp_enqueue_script( 'vacanampro-ai', VACANAMPRO_URL . 'assets/js/ai.js', array( 'jquery' ), VACANAMPRO_VERSION, true );
		wp_localize_script(
			'vacanampro-ai',
			'vacanamproAI',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'vacanampro_ai' ),
				'i18n'  => array(
					'confirm'   => __( 'Replace the text of this translation with a new translation of the original? Your own edits to this translation are replaced.', 'vacanam-language-switcher-pro' ),
					'working'   => __( 'Translating…', 'vacanam-language-switcher-pro' ),
					/* translators: %d: number of sentences. */
					'left'      => __( 'Translating… %d sentences left', 'vacanam-language-switcher-pro' ),
					'done'      => __( 'Translated. Reloading…', 'vacanam-language-switcher-pro' ),
					'error'     => __( 'Error:', 'vacanam-language-switcher-pro' ),
					'queue'     => __( 'Finding what to translate…', 'vacanam-language-switcher-pro' ),
					/* translators: 1: done, 2: total. */
					'progress'  => __( '%1$d of %2$d translated', 'vacanam-language-switcher-pro' ),
					'finished'  => __( 'Finished.', 'vacanam-language-switcher-pro' ),
					'nothing'   => __( 'Nothing to translate.', 'vacanam-language-switcher-pro' ),
					'drawing'   => __( 'Drawing the image with translated text… (up to a minute)', 'vacanam-language-switcher-pro' ),
					'use'       => __( 'Use in this page', 'vacanam-language-switcher-pro' ),
					'discard'   => __( 'Discard', 'vacanam-language-switcher-pro' ),
					'make'      => __( 'Translate the text in this image', 'vacanam-language-switcher-pro' ),
					'again'     => __( 'Try again', 'vacanam-language-switcher-pro' ),
					'used'      => __( 'Used in this page. Reload the editor to see it.', 'vacanam-language-switcher-pro' ),
					'noImages'  => __( 'This page has no images.', 'vacanam-language-switcher-pro' ),
					'original'  => __( 'Original', 'vacanam-language-switcher-pro' ),
					'translated' => __( 'Translated', 'vacanam-language-switcher-pro' ),
				),
			)
		);
	}

	/**
	 * In the Languages box of a translation: translate it from the original, and its images.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function post_box( $post ) {
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) || '' === Vacanam_Model::post_lang( $post ) ) {
			return;
		}
		$source = VACANAMPRO_Translator::source_post( $post->ID );
		if ( ! $source ) {
			return;
		}
		$from = Vacanam_Languages::get( Vacanam_Model::post_lang( $source ) );
		$done = get_post_meta( $post->ID, '_vacanampro_translated', true );
		echo '<div class="vacanampro-ai-box" data-post="' . esc_attr( $post->ID ) . '">';
		if ( ! VACANAMPRO_AI::ready() ) {
			echo '<p class="description">' . wp_kses_post( sprintf( /* translators: %s: link */ __( 'To translate with AI or DeepL, add an API key in %s.', 'vacanam-language-switcher-pro' ), '<a href="' . esc_url( admin_url( 'admin.php?page=' . VACANAMPRO_Settings::PAGE ) ) . '">' . esc_html__( 'Languages > Pro', 'vacanam-language-switcher-pro' ) . '</a>' ) ) . '</p>';
		} else {
			/* translators: %s: language name */
			echo '<p><button type="button" class="button button-primary vacanampro-ai-translate">' . esc_html( sprintf( __( 'Translate from %s with AI', 'vacanam-language-switcher-pro' ), $from ? $from['name'] : '' ) ) . '</button></p>';
			if ( is_array( $done ) && ! empty( $done['time'] ) ) {
				/* translators: %s: date */
				echo '<p class="description">' . esc_html( sprintf( __( 'Last translated %s.', 'vacanam-language-switcher-pro' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $done['time'] ) ) ) . '</p>';
			}
			echo '<p class="vacanampro-ai-status" aria-live="polite"></p>';
		}
		if ( '' !== VACANAMPRO_AI::image_provider() ) {
			echo '<p><button type="button" class="button vacanampro-ai-images">' . esc_html__( 'Text in images…', 'vacanam-language-switcher-pro' ) . '</button></p><div class="vacanampro-ai-image-list"></div>';
		}
		echo '</div>';
	}

	/**
	 * On the Copy & translate screen: translate the whole site into a language.
	 */
	public static function copy_page() {
		$langs = Vacanam_Languages::all();
		$main  = Vacanam_Languages::default_slug();
		?>
		<div class="vacanam-card vacanampro-ai-bulk">
			<h2><?php esc_html_e( 'Translate the whole site with AI', 'vacanam-language-switcher-pro' ); ?></h2>
			<?php if ( ! VACANAMPRO_AI::ready() ) : ?>
				<p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: link */ __( 'Add an API key in %s first.', 'vacanam-language-switcher-pro' ), '<a href="' . esc_url( admin_url( 'admin.php?page=' . VACANAMPRO_Settings::PAGE ) ) . '">' . esc_html__( 'Languages > Pro', 'vacanam-language-switcher-pro' ) . '</a>' ) ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Copy your content into the language first (above). Then translate every copy here: pages, posts, products, categories, menus and texts. Keep this screen open until it finishes; you can stop and continue later, nothing is translated twice.', 'vacanam-language-switcher-pro' ); ?></p>
				<p>
					<label><?php esc_html_e( 'Language', 'vacanam-language-switcher-pro' ); ?>
						<select class="vacanampro-ai-lang">
							<?php foreach ( $langs as $slug => $lang ) : ?>
								<?php
								if ( $slug === $main ) {
									continue;
								}
								?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $lang['name'] ); ?></option>
							<?php endforeach; ?>
						</select></label>
				</p>
				<p>
					<?php foreach ( Vacanam_Model::post_types() as $type ) : ?>
						<?php
						$obj = get_post_type_object( $type );
						if ( ! $obj || 'attachment' === $type ) {
							continue;
						}
						?>
						<label style="margin-right:14px"><input type="checkbox" class="vacanampro-ai-what" value="<?php echo esc_attr( $type ); ?>" checked /> <?php echo esc_html( $obj->labels->name ); ?></label>
					<?php endforeach; ?>
					<label style="margin-right:14px"><input type="checkbox" class="vacanampro-ai-what" value="terms" checked /> <?php esc_html_e( 'Categories and tags', 'vacanam-language-switcher-pro' ); ?></label>
					<label style="margin-right:14px"><input type="checkbox" class="vacanampro-ai-what" value="menus" checked /> <?php esc_html_e( 'Menus', 'vacanam-language-switcher-pro' ); ?></label>
					<label style="margin-right:14px"><input type="checkbox" class="vacanampro-ai-what" value="strings" checked /> <?php esc_html_e( 'Texts (site title, tagline, widgets)', 'vacanam-language-switcher-pro' ); ?></label>
				</p>
				<p><label><input type="checkbox" class="vacanampro-ai-new" checked /> <?php esc_html_e( 'Only content not translated with AI yet', 'vacanam-language-switcher-pro' ); ?></label></p>
				<p><button type="button" class="button button-primary vacanampro-ai-start"><?php esc_html_e( 'Translate', 'vacanam-language-switcher-pro' ); ?></button> <button type="button" class="button vacanampro-ai-stop" disabled><?php esc_html_e( 'Stop', 'vacanam-language-switcher-pro' ); ?></button></p>
				<div class="vacanampro-ai-bar"><span></span></div>
				<p class="vacanampro-ai-status" aria-live="polite"></p>
				<ol class="vacanampro-ai-log"></ol>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * On the Texts screen: translate the texts that have no translation yet.
	 *
	 * @param string $lang Language shown.
	 */
	public static function strings_page( $lang ) {
		if ( ! VACANAMPRO_AI::ready() || $lang === Vacanam_Languages::default_slug() ) {
			return;
		}
		echo '<p class="vacanampro-ai-strings" data-lang="' . esc_attr( $lang ) . '"><button type="button" class="button">' . esc_html__( 'Translate the empty texts with AI', 'vacanam-language-switcher-pro' ) . '</button> <span class="vacanampro-ai-status"></span></p>';
	}

	/*
	 * AJAX.
	 */

	/**
	 * Check the request.
	 *
	 * @param int $post_id Post that is changed (0 for site-wide jobs).
	 */
	private static function check( $post_id = 0 ) {
		check_ajax_referer( 'vacanampro_ai', 'nonce' );
		$ok = $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( VACANAMPRO_Settings::capability() );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'vacanam-language-switcher-pro' ) ), 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Translation requests can take a minute or two.
		}
	}

	/**
	 * Send the result of a job.
	 *
	 * @param array|true|int|WP_Error $result Result.
	 */
	private static function reply( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( is_array( $result ) ? $result : array( 'status' => 'done' ) );
	}

	/**
	 * Translate one post (one step).
	 */
	public static function ajax_post() {
		$id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in check().
		self::check( $id );
		self::reply( VACANAMPRO_Translator::translate_post( $id, 2 ) );
	}

	/**
	 * Everything to translate into a language.
	 */
	public static function ajax_queue() {
		self::check();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check().
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$what = isset( $_POST['what'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['what'] ) ) : array();
		$new  = ! empty( $_POST['only_new'] );
		// phpcs:enable
		if ( ! Vacanam_Languages::exists( $lang ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown language.', 'vacanam-language-switcher-pro' ) ) );
		}
		$jobs  = array();
		$types = array_values( array_intersect( $what, Vacanam_Model::post_types() ) );
		if ( $types ) {
			$ids = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'lang'           => '',
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => Vacanam_Model::TAX,
							'field'    => 'slug',
							'terms'    => array( $lang ),
						),
					),
				)
			);
			foreach ( $ids as $id ) {
				if ( ( $new && get_post_meta( $id, '_vacanampro_translated', true ) ) || ! VACANAMPRO_Translator::source_post( $id ) ) {
					continue;
				}
				$jobs[] = array(
					'type'  => 'post',
					'id'    => (int) $id,
					'label' => get_the_title( $id ),
				);
			}
		}
		if ( in_array( 'terms', $what, true ) ) {
			foreach ( Vacanam_Model::taxonomies() as $tax ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $tax,
						'hide_empty' => false,
						'lang'       => '',
					)
				);
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					if ( Vacanam_Model::term_lang( $term ) !== $lang || ( $new && get_term_meta( $term->term_id, '_vacanampro_translated', true ) ) || count( Vacanam_Model::term_translations( $term->term_id ) ) < 2 ) {
						continue;
					}
					$jobs[] = array(
						'type'  => 'term',
						'id'    => (int) $term->term_id,
						'label' => $term->name,
					);
				}
			}
		}
		if ( in_array( 'menus', $what, true ) ) {
			foreach ( wp_get_nav_menus() as $menu ) {
				if ( Vacanam_Menus::menu_lang( $menu->term_id ) === $lang ) {
					$jobs[] = array(
						'type'  => 'menu',
						'id'    => (int) $menu->term_id,
						'label' => $menu->name,
					);
				}
			}
		}
		if ( in_array( 'strings', $what, true ) ) {
			$jobs[] = array(
				'type'  => 'strings',
				'id'    => 0,
				'lang'  => $lang,
				'label' => __( 'Texts', 'vacanam-language-switcher-pro' ),
			);
		}
		wp_send_json_success( array( 'jobs' => $jobs ) );
	}

	/**
	 * Run one job of the queue (one step).
	 */
	public static function ajax_job() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check().
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		// phpcs:enable
		self::check( 'post' === $type ? $id : 0 );
		switch ( $type ) {
			case 'post':
				self::reply( VACANAMPRO_Translator::translate_post( $id, 3 ) );
				break;
			case 'term':
				self::reply( VACANAMPRO_Translator::translate_term( $id ) );
				break;
			case 'menu':
				self::reply( VACANAMPRO_Translator::translate_menu( $id ) );
				break;
			case 'strings':
				$count = VACANAMPRO_Translator::translate_strings( $lang );
				self::reply( is_wp_error( $count ) ? $count : array( 'status' => 'done', 'count' => $count ) );
				break;
		}
		wp_send_json_error( array( 'message' => __( 'Unknown job.', 'vacanam-language-switcher-pro' ) ) );
	}

	/**
	 * Translate the empty texts of a language (Texts screen).
	 */
	public static function ajax_strings() {
		self::check();
		$lang  = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in check().
		$count = VACANAMPRO_Translator::translate_strings( $lang );
		self::reply( is_wp_error( $count ) ? $count : array( 'status' => 'done', 'count' => $count ) );
	}

	/*
	 * Images.
	 */

	/**
	 * Image IDs used by a post: featured image, images in the content and in Elementor designs.
	 *
	 * @param int $post_id Post.
	 * @return int[]
	 */
	public static function post_images( $post_id ) {
		$ids   = array();
		$thumb = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			$ids[] = $thumb;
		}
		if ( preg_match_all( '/wp-image-(\d+)/', (string) get_post_field( 'post_content', $post_id ), $m ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $m[1] ) );
		}
		$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
		if ( is_array( $data ) ) {
			$walk = static function ( $node ) use ( &$walk, &$ids ) {
				if ( ! is_array( $node ) ) {
					return;
				}
				if ( isset( $node['id'], $node['url'] ) && is_numeric( $node['id'] ) && (int) $node['id'] > 0 ) {
					$ids[] = (int) $node['id'];
				}
				foreach ( $node as $child ) {
					$walk( $child );
				}
			};
			$walk( $data );
		}
		return array_values(
			array_unique(
				array_filter(
					$ids,
					static function ( $id ) {
						return wp_attachment_is_image( $id );
					}
				)
			)
		);
	}

	/**
	 * Images of a translation, with their translated versions.
	 */
	public static function ajax_images() {
		$id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in check().
		self::check( $id );
		$lang = Vacanam_Model::post_lang( $id );
		$out  = array();
		foreach ( self::post_images( $id ) as $image ) {
			$made = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'lang'           => '',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => '_vacanampro_image_of',
							'value' => $image,
						),
						array(
							'key'   => '_vacanampro_image_lang',
							'value' => $lang,
						),
					),
				)
			);
			$out[] = array(
				'id'         => $image,
				'url'        => (string) wp_get_attachment_image_url( $image, 'medium' ),
				'translated' => $made ? (int) $made[0] : 0,
				'turl'       => $made ? (string) wp_get_attachment_image_url( $made[0], 'medium' ) : '',
				'is_made'    => (bool) get_post_meta( $image, '_vacanampro_image_of', true ),
			);
		}
		wp_send_json_success( array( 'images' => $out ) );
	}

	/**
	 * Draw an image again with its text in the page's language.
	 */
	public static function ajax_image_make() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check().
		$id    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$image = isset( $_POST['image'] ) ? absint( $_POST['image'] ) : 0;
		// phpcs:enable
		self::check( $id );
		$to     = Vacanam_Languages::get( Vacanam_Model::post_lang( $id ) );
		$source = VACANAMPRO_Translator::source_post( $id );
		$from   = $source ? Vacanam_Languages::get( Vacanam_Model::post_lang( $source ) ) : Vacanam_Languages::get( Vacanam_Languages::default_slug() );
		$path   = get_attached_file( $image );
		if ( ! $to || ! $from || ! $path || ! file_exists( $path ) || ! wp_attachment_is_image( $image ) ) {
			wp_send_json_error( array( 'message' => __( 'This image cannot be translated.', 'vacanam-language-switcher-pro' ) ) );
		}
		$bytes = VACANAMPRO_AI::translate_image( $path, $from, $to );
		if ( is_wp_error( $bytes ) ) {
			self::reply( $bytes );
		}
		$name   = sanitize_file_name( pathinfo( $path, PATHINFO_FILENAME ) . '-' . $to['slug'] . '.png' );
		$upload = wp_upload_bits( $name, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			wp_send_json_error( array( 'message' => $upload['error'] ) );
		}
		$new = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => get_the_title( $image ) . ' (' . $to['name'] . ')',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( is_wp_error( $new ) || ! $new ) {
			wp_send_json_error( array( 'message' => __( 'The image could not be saved.', 'vacanam-language-switcher-pro' ) ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $new, wp_generate_attachment_metadata( $new, $upload['file'] ) );
		update_post_meta( $new, '_vacanampro_image_of', $image );
		update_post_meta( $new, '_vacanampro_image_lang', $to['slug'] );
		$alt = get_post_meta( $image, '_wp_attachment_image_alt', true );
		if ( '' !== (string) $alt ) {
			update_post_meta( $new, '_wp_attachment_image_alt', $alt );
		}
		if ( Vacanam_Model::is_translated_type( 'attachment' ) ) {
			Vacanam_Model::set_post_lang( $new, $to['slug'] );
		}
		wp_send_json_success(
			array(
				'id'  => $new,
				'url' => (string) wp_get_attachment_image_url( $new, 'medium' ),
			)
		);
	}

	/**
	 * Use a translated image in the page instead of the original.
	 */
	public static function ajax_image_use() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check().
		$id   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$from = isset( $_POST['from'] ) ? absint( $_POST['from'] ) : 0;
		$to   = isset( $_POST['to'] ) ? absint( $_POST['to'] ) : 0;
		// phpcs:enable
		self::check( $id );
		if ( (int) get_post_meta( $to, '_vacanampro_image_of', true ) !== $from ) {
			wp_send_json_error( array( 'message' => __( 'These images do not belong together.', 'vacanam-language-switcher-pro' ) ) );
		}
		self::replace_image( $id, $from, $to );
		wp_send_json_success( array( 'status' => 'done' ) );
	}

	/**
	 * Replace an image everywhere in a post.
	 *
	 * @param int $post_id Post.
	 * @param int $from    Original image.
	 * @param int $to      Translated image.
	 */
	public static function replace_image( $post_id, $from, $to ) {
		if ( (int) get_post_thumbnail_id( $post_id ) === $from ) {
			set_post_thumbnail( $post_id, $to );
		}
		$url_to  = (string) wp_get_attachment_url( $to );
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( false !== strpos( $content, 'wp-image-' . $from ) ) {
			$content = preg_replace_callback(
				'/<img\b[^>]*\bwp-image-' . $from . '\b[^>]*>/i',
				static function ( $m ) use ( $from, $to, $url_to ) {
					$tag = preg_replace( '/\s(srcset|sizes)=("[^"]*"|\'[^\']*\')/i', '', $m[0] );
					$tag = preg_replace( '/\ssrc=("[^"]*"|\'[^\']*\')/i', ' src="' . esc_url( $url_to ) . '"', $tag );
					return str_replace( 'wp-image-' . $from, 'wp-image-' . $to, $tag );
				},
				$content
			);
			$content = preg_replace( '/("id":)' . $from . '(?=[,}])/', '${1}' . $to, $content );
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					)
				)
			);
		}
		$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
		if ( is_array( $data ) ) {
			$swap = static function ( $node ) use ( &$swap, $from, $to, $url_to ) {
				if ( ! is_array( $node ) ) {
					return $node;
				}
				if ( isset( $node['id'], $node['url'] ) && (int) $node['id'] === $from ) {
					$node['id']  = $to;
					$node['url'] = $url_to;
				}
				foreach ( $node as $k => $child ) {
					$node[ $k ] = $swap( $child );
				}
				return $node;
			};
			update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $swap( $data ) ) ) );
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		}
	}

	/**
	 * Delete a translated image that will not be used.
	 */
	public static function ajax_image_discard() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check().
		$id    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$image = isset( $_POST['image'] ) ? absint( $_POST['image'] ) : 0;
		// phpcs:enable
		self::check( $id );
		if ( get_post_meta( $image, '_vacanampro_image_of', true ) && current_user_can( 'delete_post', $image ) ) {
			wp_delete_attachment( $image, true );
		}
		wp_send_json_success( array( 'status' => 'done' ) );
	}

	/*
	 * Automatic translation of new copies.
	 */

	/**
	 * A page or post was copied into a language: translate it in the background.
	 *
	 * @param int    $new  Copy.
	 * @param int    $id   Original.
	 * @param string $lang Language.
	 */
	public static function auto_post( $new, $id, $lang ) {
		if ( VACANAMPRO_Settings::on( 'ai_auto' ) && VACANAMPRO_AI::ready() && 'attachment' !== get_post_type( $new ) ) {
			wp_schedule_single_event( time() + 10, 'vacanampro_ai_auto', array( array( 'post', (int) $new, 0 ) ) );
		}
	}

	/**
	 * A term was copied into a language: same.
	 *
	 * @param int    $new  Copy.
	 * @param int    $id   Original.
	 * @param string $lang Language.
	 */
	public static function auto_term( $new, $id, $lang ) {
		if ( VACANAMPRO_Settings::on( 'ai_auto' ) && VACANAMPRO_AI::ready() ) {
			wp_schedule_single_event( time() + 10, 'vacanampro_ai_auto', array( array( 'term', (int) $new, 0 ) ) );
		}
	}

	/**
	 * Background job: translate, and come back for the rest of a long page.
	 *
	 * @param array $job type, id, tries.
	 */
	public static function run_auto( $job ) {
		list( $type, $id, $tries ) = array_pad( (array) $job, 3, 0 );
		if ( 'term' === $type ) {
			VACANAMPRO_Translator::translate_term( (int) $id );
			return;
		}
		$result = VACANAMPRO_Translator::translate_post( (int) $id, 6 );
		if ( is_array( $result ) && 'more' === $result['status'] && $tries < 20 ) {
			wp_schedule_single_event( time() + 5, 'vacanampro_ai_auto', array( array( 'post', (int) $id, $tries + 1 ) ) );
		}
	}
}
