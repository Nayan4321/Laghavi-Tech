<?php
/**
 * Form plugin integrations: catch every successful submission of Contact Form 7, WPForms, Elementor Pro
 * Forms, Fluent Forms, Gravity Forms, Forminator, Ninja Forms, Formidable Forms, JetFormBuilder and MetForm.
 *
 * Each handler only reads what the form plugin passes and never stops or changes the submission: errors
 * here are caught so a form always keeps working.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integrations.
 */
class AARYE_Integrations {

	/**
	 * Elementor files copied before its email action may delete them, by form element ID.
	 *
	 * @var array<string, array>
	 */
	private static $elementor_files = array();

	/**
	 * Hook into every form plugin (hooks of missing plugins simply never fire).
	 */
	public static function init() {
		add_action( 'wpcf7_before_send_mail', array( __CLASS__, 'cf7' ), 5, 3 );
		add_action( 'wpforms_process_complete', array( __CLASS__, 'wpforms' ), 10, 4 );
		add_action( 'elementor_pro/forms/process', array( __CLASS__, 'elementor_early_files' ), 1, 2 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'elementor' ), 10, 2 );
		add_action( 'fluentform/submission_inserted', array( __CLASS__, 'fluentform' ), 10, 3 );
		add_action( 'gform_after_submission', array( __CLASS__, 'gravity' ), 10, 2 );
		add_action( 'forminator_after_handle_form', array( __CLASS__, 'forminator' ), 10, 1 );
		add_action( 'ninja_forms_after_submission', array( __CLASS__, 'ninja' ), 10, 1 );
		add_action( 'frm_after_create_entry', array( __CLASS__, 'formidable' ), 30, 3 );
		add_action( 'jet-form-builder/form-handler/after-send', array( __CLASS__, 'jetformbuilder' ), 10, 2 );
		add_action( 'metform_after_store_form_data', array( __CLASS__, 'metform' ), 10, 4 );
	}

	/**
	 * Supported form plugins.
	 *
	 * @return array<string, array{name: string}>
	 */
	public static function plugins() {
		/**
		 * Form plugins and other entry sources (add-ons add theirs).
		 *
		 * @param array $plugins Key => array( 'name' => Name ).
		 */
		return (array) apply_filters( 'aarye_form_plugins', array(
			'cf7'            => array( 'name' => 'Contact Form 7' ),
			'wpforms'        => array( 'name' => 'WPForms' ),
			'elementor'      => array( 'name' => 'Elementor Pro Forms' ),
			'fluentform'     => array( 'name' => 'Fluent Forms' ),
			'gravity'        => array( 'name' => 'Gravity Forms' ),
			'forminator'     => array( 'name' => 'Forminator' ),
			'ninja'          => array( 'name' => 'Ninja Forms' ),
			'formidable'     => array( 'name' => 'Formidable Forms' ),
			'jetformbuilder' => array( 'name' => 'JetFormBuilder' ),
			'metform'        => array( 'name' => 'MetForm' ),
		) );
	}

	/**
	 * Whether a form plugin is active.
	 *
	 * @param string $key Plugin key.
	 * @return bool
	 */
	public static function is_active( $key ) {
		switch ( $key ) {
			case 'cf7':
				return defined( 'WPCF7_VERSION' );
			case 'wpforms':
				return function_exists( 'wpforms' );
			case 'elementor':
				return defined( 'ELEMENTOR_PRO_VERSION' );
			case 'fluentform':
				return defined( 'FLUENTFORM_VERSION' );
			case 'gravity':
				return class_exists( 'GFAPI' );
			case 'forminator':
				return defined( 'FORMINATOR_VERSION' );
			case 'ninja':
				return function_exists( 'Ninja_Forms' );
			case 'formidable':
				return class_exists( 'FrmForm' );
			case 'jetformbuilder':
				return function_exists( 'jet_form_builder' );
			case 'metform':
				return class_exists( '\MetForm\Plugin' );
		}
		/**
		 * Whether an add-on's entry source is active.
		 *
		 * @param bool   $active Active.
		 * @param string $key    Source key.
		 */
		return (bool) apply_filters( 'aarye_form_plugin_active', false, $key );
	}

	/**
	 * Whether any supported form plugin is active.
	 *
	 * @return bool
	 */
	public static function active() {
		foreach ( array_keys( self::plugins() ) as $key ) {
			if ( self::is_active( $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Store an entry, never letting an error reach the form.
	 *
	 * @param callable $build Returns the capture() arguments, or null to skip.
	 */
	private static function safely( $build ) {
		try {
			$args = call_user_func( $build );
			if ( is_array( $args ) ) {
				AARYE_Entries::capture( $args );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Aarye Marketing Addon: could not save a form entry: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug mode only.
			}
		}
	}

	/**
	 * A file on disk for an upload URL inside wp-content/uploads.
	 *
	 * @param string $url URL.
	 * @return string Path, or '' if not a local upload.
	 */
	private static function path_from_url( $url ) {
		$upload = wp_upload_dir( null, false );
		$base   = set_url_scheme( $upload['baseurl'] );
		$url    = set_url_scheme( (string) $url );
		if ( 0 !== strpos( $url, $base ) ) {
			return '';
		}
		$path = $upload['basedir'] . rawurldecode( substr( $url, strlen( $base ) ) );
		return false === strpos( $path, '..' ) && is_file( $path ) ? $path : '';
	}

	/**
	 * Values that are only form plumbing (nonces, captcha tokens, referrers).
	 *
	 * @param string $name Field name.
	 * @return bool
	 */
	private static function is_internal( $name ) {
		return '' === $name || '_' === $name[0] || (bool) preg_match( '/nonce|captcha|recaptcha|turnstile|honeypot|^action$|referer/i', $name );
	}

	// ------------------------------------------------------------------ Contact Form 7.

	/**
	 * Contact Form 7: runs after validation and spam checks, while uploads still exist (CF7 deletes
	 * them at the end of the request).
	 *
	 * @param WPCF7_ContactForm $form       Form.
	 * @param bool              $abort      Whether sending was aborted.
	 * @param WPCF7_Submission  $submission Submission.
	 */
	public static function cf7( $form, $abort = false, $submission = null ) {
		self::safely(
			static function () use ( $form, $submission ) {
				$submission = $submission ? $submission : ( class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null );
				if ( ! $submission || ! is_object( $form ) ) {
					return null;
				}
				$types = array();
				foreach ( $form->scan_form_tags() as $tag ) {
					if ( '' !== $tag->name ) {
						$types[ $tag->name ] = $tag->basetype;
					}
				}
				$uploads = (array) $submission->uploaded_files();
				$fields  = array();
				foreach ( (array) $submission->get_posted_data() as $name => $value ) {
					if ( isset( $uploads[ $name ] ) || self::is_internal( (string) $name ) ) {
						continue;
					}
					$fields[] = array(
						'name'  => $name,
						'label' => '',
						'type'  => isset( $types[ $name ] ) ? $types[ $name ] : '',
						'value' => $value,
					);
				}
				$files = array();
				foreach ( $uploads as $name => $paths ) {
					foreach ( (array) $paths as $path ) {
						$files[] = array(
							'label' => AARYE_Entries::humanize( $name ),
							'path'  => $path,
						);
					}
				}
				return array(
					'plugin'    => 'cf7',
					'form_id'   => $form->id(),
					'form_name' => $form->title(),
					'fields'    => $fields,
					'files'     => $files,
				);
			}
		);
	}

	// ------------------------------------------------------------------ WPForms.

	/**
	 * WPForms: runs only for valid, non-spam submissions.
	 *
	 * @param array $fields    Processed fields.
	 * @param array $entry     Raw entry.
	 * @param array $form_data Form settings.
	 * @param int   $entry_id  Entry ID (Pro; 0 in Lite).
	 */
	public static function wpforms( $fields, $entry, $form_data, $entry_id = 0 ) {
		self::safely(
			static function () use ( $fields, $form_data, $entry_id ) {
				$out   = array();
				$files = array();
				foreach ( (array) $fields as $field ) {
					$type = isset( $field['type'] ) ? $field['type'] : '';
					if ( 'file-upload' === $type ) {
						$raw = isset( $field['value_raw'] ) && is_array( $field['value_raw'] ) ? $field['value_raw'] : array();
						foreach ( $raw as $file ) {
							if ( ! empty( $file['value'] ) ) {
								$files[] = array(
									'label' => isset( $field['name'] ) ? $field['name'] : '',
									'url'   => $file['value'],
									'name'  => isset( $file['file_original'] ) ? $file['file_original'] : '',
								);
							}
						}
						if ( ! $raw && ! empty( $field['value'] ) ) {
							foreach ( preg_split( '/\s+/', (string) $field['value'] ) as $url ) {
								$files[] = array( 'label' => isset( $field['name'] ) ? $field['name'] : '', 'url' => $url );
							}
						}
						continue;
					}
					$out[] = array(
						'name'  => isset( $field['id'] ) ? 'field_' . $field['id'] : '',
						'label' => isset( $field['name'] ) ? $field['name'] : '',
						'type'  => $type,
						'value' => isset( $field['value'] ) ? str_replace( "\n", ', ', (string) $field['value'] ) : '',
					);
				}
				return array(
					'plugin'          => 'wpforms',
					'form_id'         => isset( $form_data['id'] ) ? $form_data['id'] : '',
					'form_name'       => isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : '',
					'source_entry_id' => $entry_id ? $entry_id : '',
					'fields'          => $out,
					'files'           => $files,
				);
			}
		);
	}

	// ------------------------------------------------------------------ Elementor Pro.

	/**
	 * Elementor Pro: copy uploads before the "email with attachment" action deletes them.
	 *
	 * @param object $record  Form record.
	 * @param object $handler Ajax handler.
	 */
	public static function elementor_early_files( $record, $handler ) {
		try {
			$files  = array();
			$fields = (array) $record->get( 'fields' );
			foreach ( (array) $record->get( 'files' ) as $id => $set ) {
				$paths = isset( $set['path'] ) ? (array) $set['path'] : array();
				$urls  = isset( $set['url'] ) && is_array( $set['url'] ) ? $set['url'] : array();
				foreach ( $paths as $i => $path ) {
					$files[] = array(
						'label' => ! empty( $fields[ $id ]['title'] ) ? $fields[ $id ]['title'] : AARYE_Entries::humanize( (string) $id ),
						'path'  => $path,
						'url'   => isset( $urls[ $i ] ) && 'attached' !== $urls[ $i ] ? $urls[ $i ] : '',
					);
				}
			}
			if ( $files ) {
				self::$elementor_files[ (string) $record->get_form_settings( 'id' ) ] = AARYE_Entries::prepare_files( $files );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Elementor Pro: a submission that passed validation and every action.
	 *
	 * @param object $record  Form record.
	 * @param object $handler Ajax handler.
	 */
	public static function elementor( $record, $handler ) {
		self::safely(
			static function () use ( $record, $handler ) {
				if ( is_object( $handler ) && ( ! empty( $handler->messages['error'] ) || ( property_exists( $handler, 'is_success' ) && ! $handler->is_success ) ) ) {
					return null;
				}
				$fields = array();
				foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
					if ( isset( $field['type'] ) && in_array( $field['type'], array( 'upload', 'step', 'html', 'recaptcha', 'recaptcha_v3', 'honeypot' ), true ) ) {
						continue;
					}
					$fields[] = array(
						'name'  => $id,
						'label' => isset( $field['title'] ) ? $field['title'] : '',
						'type'  => isset( $field['type'] ) ? $field['type'] : '',
						'value' => isset( $field['value'] ) ? $field['value'] : '',
					);
				}
				$element = (string) $record->get_form_settings( 'id' );
				return array(
					'plugin'    => 'elementor',
					'form_id'   => $element,
					'form_name' => (string) $record->get_form_settings( 'form_name' ),
					'fields'    => $fields,
					'files'     => isset( self::$elementor_files[ $element ] ) ? self::$elementor_files[ $element ] : array(),
				);
			}
		);
	}

	// ------------------------------------------------------------------ Fluent Forms.

	/**
	 * Fluent Forms (also fires the old fluentform_submission_inserted hook: we only use this one).
	 *
	 * @param int    $insert_id Entry ID.
	 * @param array  $data      Values by field name.
	 * @param object $form      Form.
	 */
	public static function fluentform( $insert_id, $data, $form ) {
		self::safely(
			static function () use ( $insert_id, $data, $form ) {
				if ( class_exists( '\FluentForm\App\Models\Submission' ) ) {
					$row = \FluentForm\App\Models\Submission::find( $insert_id );
					if ( $row && 'spam' === $row->status ) {
						return null;
					}
				}
				$inputs = array();
				if ( class_exists( '\FluentForm\App\Modules\Form\FormFieldsParser' ) ) {
					$inputs = (array) \FluentForm\App\Modules\Form\FormFieldsParser::getEntryInputs( $form, array( 'admin_label', 'raw', 'element' ) );
				}
				$fields = array();
				$files  = array();
				foreach ( (array) $data as $name => $value ) {
					if ( self::is_internal( (string) $name ) ) {
						continue;
					}
					$info    = isset( $inputs[ $name ] ) ? (array) $inputs[ $name ] : array();
					$element = isset( $info['element'] ) ? $info['element'] : '';
					$label   = isset( $info['admin_label'] ) && '' !== $info['admin_label'] ? $info['admin_label'] : ( isset( $info['label'] ) ? $info['label'] : '' );
					if ( in_array( $element, array( 'input_file', 'input_image' ), true ) ) {
						foreach ( (array) $value as $url ) {
							$files[] = array(
								'label' => $label,
								'url'   => $url,
								'path'  => self::path_from_url( $url ),
							);
						}
						continue;
					}
					if ( 'input_name' === $element && is_array( $value ) ) {
						$value = implode( ' ', array_filter( array_map( 'strval', $value ), 'strlen' ) );
					}
					$fields[] = array(
						'name'  => $name,
						'label' => $label,
						'type'  => $element,
						'value' => $value,
					);
				}
				return array(
					'plugin'          => 'fluentform',
					'form_id'         => isset( $form->id ) ? $form->id : '',
					'form_name'       => isset( $form->title ) ? $form->title : '',
					'source_entry_id' => $insert_id,
					'fields'          => $fields,
					'files'           => $files,
				);
			}
		);
	}

	// ------------------------------------------------------------------ Gravity Forms.

	/**
	 * Gravity Forms (also runs for spam entries: those are skipped).
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 */
	public static function gravity( $entry, $form ) {
		self::safely(
			static function () use ( $entry, $form ) {
				if ( isset( $entry['status'] ) && 'spam' === $entry['status'] ) {
					return null;
				}
				$fields = array();
				$files  = array();
				foreach ( (array) $form['fields'] as $field ) {
					if ( ! is_object( $field ) || in_array( $field->type, array( 'html', 'section', 'page', 'captcha' ), true ) ) {
						continue;
					}
					$label = '' !== (string) $field->adminLabel ? $field->adminLabel : $field->label; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms property.
					if ( 'fileupload' === $field->type ) {
						$raw  = isset( $entry[ (string) $field->id ] ) ? $entry[ (string) $field->id ] : '';
						$urls = method_exists( $field, 'to_array' ) ? (array) $field->to_array( $raw ) : array_filter( array( $raw ) );
						foreach ( $urls as $url ) {
							$path    = class_exists( 'GFFormsModel' ) && method_exists( 'GFFormsModel', 'get_physical_file_path' ) ? GFFormsModel::get_physical_file_path( $url, isset( $entry['id'] ) ? $entry['id'] : null ) : '';
							$files[] = array(
								'label' => $label,
								'url'   => $url,
								'path'  => is_string( $path ) && is_file( $path ) ? $path : '',
							);
						}
						continue;
					}
					$value = method_exists( $field, 'get_value_export' ) ? $field->get_value_export( $entry ) : ( isset( $entry[ (string) $field->id ] ) ? $entry[ (string) $field->id ] : '' );
					$fields[] = array(
						'name'  => 'input_' . $field->id,
						'label' => $label,
						'type'  => $field->type,
						'value' => $value,
					);
				}
				return array(
					'plugin'          => 'gravity',
					'form_id'         => isset( $form['id'] ) ? $form['id'] : '',
					'form_name'       => isset( $form['title'] ) ? $form['title'] : '',
					'source_entry_id' => isset( $entry['id'] ) ? $entry['id'] : '',
					'fields'          => $fields,
					'files'           => $files,
				);
			}
		);
	}

	// ------------------------------------------------------------------ Forminator.

	/**
	 * Forminator: end of a successful submission (AJAX or not). Only "active" entries are real ones.
	 *
	 * @param object $entry Forminator_Form_Entry_Model.
	 */
	public static function forminator( $entry ) {
		self::safely(
			static function () use ( $entry ) {
				if ( ! is_object( $entry ) || ( isset( $entry->status ) && 'active' !== $entry->status ) ) {
					return null;
				}
				$form_id = (int) $entry->form_id;
				$defs    = array();
				if ( class_exists( 'Forminator_API' ) ) {
					$list = Forminator_API::get_form_fields( $form_id );
					foreach ( is_array( $list ) ? $list : array() as $field ) {
						$defs[ $field->slug ] = array(
							'label' => isset( $field->raw['field_label'] ) ? $field->raw['field_label'] : '',
							'type'  => isset( $field->raw['type'] ) ? $field->raw['type'] : '',
						);
					}
				}
				$fields = array();
				$files  = array();
				foreach ( (array) $entry->meta_data as $slug => $meta ) {
					if ( self::is_internal( (string) $slug ) ) {
						continue;
					}
					$value = isset( $meta['value'] ) ? $meta['value'] : '';
					$def   = isset( $defs[ $slug ] ) ? $defs[ $slug ] : array( 'label' => '', 'type' => '' );
					if ( is_array( $value ) && isset( $value['file'] ) && is_array( $value['file'] ) ) {
						$urls  = isset( $value['file']['file_url'] ) ? (array) $value['file']['file_url'] : array();
						$paths = isset( $value['file']['file_path'] ) ? (array) $value['file']['file_path'] : array();
						foreach ( $urls as $i => $url ) {
							$files[] = array(
								'label' => $def['label'],
								'url'   => $url,
								'path'  => isset( $paths[ $i ] ) ? $paths[ $i ] : '',
							);
						}
						continue;
					}
					$fields[] = array(
						'name'  => $slug,
						'label' => $def['label'],
						'type'  => $def['type'],
						'value' => $value,
					);
				}
				$name = '';
				if ( class_exists( 'Forminator_API' ) ) {
					$form = Forminator_API::get_form( $form_id );
					$name = is_object( $form ) && isset( $form->settings['formName'] ) ? $form->settings['formName'] : ( is_object( $form ) && isset( $form->name ) ? $form->name : '' );
				}
				return array(
					'plugin'          => 'forminator',
					'form_id'         => $form_id,
					'form_name'       => $name,
					'source_entry_id' => ! empty( $entry->entry_id ) ? $entry->entry_id : '',
					'fields'          => $fields,
					'files'           => $files,
				);
			}
		);
	}

	// ------------------------------------------------------------------ Ninja Forms.

	/**
	 * Ninja Forms.
	 *
	 * @param array $data Submission data.
	 */
	public static function ninja( $data ) {
		self::safely(
			static function () use ( $data ) {
				if ( ! empty( $data['settings']['is_preview'] ) ) {
					return null;
				}
				$fields = array();
				$files  = array();
				foreach ( (array) ( isset( $data['fields'] ) ? $data['fields'] : array() ) as $field ) {
					$type = isset( $field['type'] ) ? $field['type'] : '';
					if ( in_array( $type, array( 'submit', 'html', 'hr', 'recaptcha', 'recaptcha_v3', 'spam', 'confirm', 'save' ), true ) ) {
						continue;
					}
					if ( 'file_upload' === $type ) {
						foreach ( (array) ( isset( $field['value'] ) ? $field['value'] : array() ) as $url ) {
							if ( is_string( $url ) && '' !== $url ) {
								$files[] = array( 'label' => isset( $field['label'] ) ? $field['label'] : '', 'url' => $url, 'path' => self::path_from_url( $url ) );
							}
						}
						continue;
					}
					$fields[] = array(
						'name'  => isset( $field['key'] ) ? $field['key'] : '',
						'label' => ! empty( $field['admin_label'] ) ? $field['admin_label'] : ( isset( $field['label'] ) ? $field['label'] : '' ),
						'type'  => $type,
						'value' => isset( $field['value'] ) ? $field['value'] : '',
					);
				}
				return array(
					'plugin'          => 'ninja',
					'form_id'         => isset( $data['form_id'] ) ? $data['form_id'] : '',
					'form_name'       => isset( $data['settings']['title'] ) ? $data['settings']['title'] : '',
					'source_entry_id' => isset( $data['actions']['save']['sub_id'] ) ? $data['actions']['save']['sub_id'] : '',
					'fields'          => $fields,
					'files'           => $files,
				);
			}
		);
	}

	// ------------------------------------------------------------------ Formidable Forms.

	/**
	 * Formidable Forms: skip child (repeater) entries, drafts and spam.
	 *
	 * @param int   $entry_id Entry ID.
	 * @param int   $form_id  Form ID.
	 * @param array $args     Includes is_child.
	 */
	public static function formidable( $entry_id, $form_id, $args = array() ) {
		self::safely(
			static function () use ( $entry_id, $form_id, $args ) {
				if ( ! empty( $args['is_child'] ) || ! class_exists( 'FrmEntry' ) ) {
					return null;
				}
				$entry = FrmEntry::getOne( $entry_id, true );
				if ( ! $entry || 0 !== (int) $entry->is_draft ) {
					return null;
				}
				$fields = array();
				$files  = array();
				foreach ( (array) FrmField::get_all_for_form( $form_id ) as $field ) {
					if ( in_array( $field->type, array( 'html', 'divider', 'break', 'end_divider', 'captcha', 'submit', 'summary' ), true ) ) {
						continue;
					}
					$value = isset( $entry->metas[ $field->id ] ) ? $entry->metas[ $field->id ] : '';
					if ( 'file' === $field->type ) {
						foreach ( array_filter( (array) $value ) as $attachment_id ) {
							if ( is_numeric( $attachment_id ) ) {
								$files[] = array(
									'label' => $field->name,
									'url'   => (string) wp_get_attachment_url( (int) $attachment_id ),
									'path'  => (string) get_attached_file( (int) $attachment_id ),
								);
							}
						}
						continue;
					}
					$fields[] = array(
						'name'  => $field->field_key,
						'label' => $field->name,
						'type'  => $field->type,
						'value' => $value,
					);
				}
				$form = class_exists( 'FrmForm' ) ? FrmForm::getOne( $form_id ) : null;
				return array(
					'plugin'          => 'formidable',
					'form_id'         => $form_id,
					'form_name'       => $form ? $form->name : '',
					'source_entry_id' => $entry_id,
					'fields'          => $fields,
					'files'           => $files,
				);
			}
		);
	}

	// ------------------------------------------------------------------ JetFormBuilder.

	/**
	 * JetFormBuilder (also fires after failed submissions: those are skipped).
	 *
	 * @param object $handler    Form handler.
	 * @param bool   $is_success Whether it succeeded.
	 */
	public static function jetformbuilder( $handler, $is_success ) {
		self::safely(
			static function () use ( $handler, $is_success ) {
				if ( true !== $is_success || ! function_exists( 'jet_fb_context' ) ) {
					return null;
				}
				$fields = array();
				$files  = array();
				foreach ( jet_fb_context()->iterate_parsers() as $name => $parser ) {
					if ( self::is_internal( (string) $name ) ) {
						continue;
					}
					$type = method_exists( $parser, 'get_type' ) ? (string) $parser->get_type() : '';
					if ( in_array( $type, array( 'submit-field', 'form-break-field', 'heading-field', 'group-break-field', 'progress-bar' ), true ) ) {
						continue;
					}
					$label = method_exists( $parser, 'get_label' ) ? (string) $parser->get_label() : '';
					if ( 'media-field' === $type ) {
						$file = jet_fb_context()->get_file( $name );
						foreach ( is_object( $file ) && method_exists( $file, 'get_files' ) ? $file->get_files() : ( $file ? array( $file ) : array() ) as $one ) {
							if ( is_object( $one ) && method_exists( $one, 'get_url' ) ) {
								$files[] = array(
									'label' => $label,
									'url'   => (string) $one->get_url(),
									'path'  => method_exists( $one, 'get_file' ) ? (string) $one->get_file() : '',
								);
							}
						}
						continue;
					}
					$fields[] = array(
						'name'  => (string) $name,
						'label' => $label,
						'type'  => $type,
						'value' => $parser->get_value(),
					);
				}
				$form_id = method_exists( $handler, 'get_form_id' ) ? (int) $handler->get_form_id() : 0;
				$record  = function_exists( 'jet_fb_action_handler' ) ? jet_fb_action_handler()->get_context( 'save_record', 'id' ) : '';
				return array(
					'plugin'          => 'jetformbuilder',
					'form_id'         => $form_id,
					'form_name'       => get_the_title( $form_id ),
					'source_entry_id' => is_scalar( $record ) ? (string) $record : '',
					'fields'          => $fields,
					'files'           => $files,
				);
			}
		);
	}

	// ------------------------------------------------------------------ MetForm.

	/**
	 * MetForm.
	 *
	 * @param int   $form_id       Form ID.
	 * @param array $form_data     Submitted values (raw request).
	 * @param array $form_settings Settings.
	 * @param array $attributes    Includes file_upload_info.
	 */
	public static function metform( $form_id, $form_data, $form_settings = array(), $attributes = array() ) {
		self::safely(
			static function () use ( $form_id, $form_data, $attributes ) {
				$map = array();
				try {
					if ( class_exists( '\MetForm\Core\Entries\Action' ) ) {
						$map = (array) \MetForm\Core\Entries\Action::instance()->get_fields( $form_id );
					}
				} catch ( \Throwable $e ) {
					$map = array(); // Field labels are optional: keep the values.
				}
				$fields = array();
				foreach ( (array) $form_data as $name => $value ) {
					if ( self::is_internal( (string) $name ) || ( $map && ! isset( $map[ $name ] ) ) || in_array( $name, array( 'id', 'form_nonce' ), true ) ) {
						continue;
					}
					$def = isset( $map[ $name ] ) ? $map[ $name ] : null;
					if ( $def && isset( $def->widgetType ) && 'mf-file-upload' === $def->widgetType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MetForm property.
						continue;
					}
					$fields[] = array(
						'name'  => $name,
						'label' => $def && isset( $def->mf_input_label ) ? $def->mf_input_label : '',
						'type'  => $def && isset( $def->widgetType ) ? $def->widgetType : '', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						'value' => $value,
					);
				}
				$files = array();
				$info  = isset( $attributes['file_upload_info'] ) ? (array) $attributes['file_upload_info'] : array();
				foreach ( $info as $name => $list ) {
					foreach ( (array) $list as $file ) {
						$files[] = array(
							'label' => isset( $map[ $name ]->mf_input_label ) ? $map[ $name ]->mf_input_label : $name,
							'url'   => isset( $file['url'] ) ? $file['url'] : '',
							'path'  => isset( $file['file'] ) ? $file['file'] : '',
							'name'  => isset( $file['name'] ) ? $file['name'] : '',
						);
					}
				}
				return array(
					'plugin'    => 'metform',
					'form_id'   => $form_id,
					'form_name' => get_the_title( $form_id ),
					'fields'    => $fields,
					'files'     => $files,
				);
			}
		);
	}

	// ------------------------------------------------------------------ Lists and links.

	/**
	 * Forms of one plugin, for the Forms screen and filters.
	 *
	 * @param string $key Plugin key.
	 * @return array<int, array{id: string, title: string}>
	 */
	public static function forms( $key ) {
		$out = array();
		try {
			switch ( $key ) {
				case 'cf7':
					$posts = get_posts( array( 'post_type' => 'wpcf7_contact_form', 'numberposts' => 200 ) );
					break;
				case 'wpforms':
					$posts = get_posts( array( 'post_type' => 'wpforms', 'numberposts' => 200 ) );
					break;
				case 'jetformbuilder':
					$posts = get_posts( array( 'post_type' => 'jet-form-builder', 'numberposts' => 200 ) );
					break;
				case 'metform':
					$posts = get_posts( array( 'post_type' => 'metform-form', 'numberposts' => 200 ) );
					break;
				case 'forminator':
					$posts = get_posts( array( 'post_type' => 'forminator_forms', 'numberposts' => 200 ) );
					break;
				case 'gravity':
					foreach ( class_exists( 'GFAPI' ) ? (array) GFAPI::get_forms() : array() as $form ) {
						$out[] = array( 'id' => (string) $form['id'], 'title' => $form['title'] );
					}
					return $out;
				case 'fluentform':
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own table, read-only list.
					foreach ( (array) $wpdb->get_results( "SELECT id, title FROM {$wpdb->prefix}fluentform_forms ORDER BY id DESC LIMIT 200" ) as $row ) {
						$out[] = array( 'id' => (string) $row->id, 'title' => $row->title );
					}
					return $out;
				case 'formidable':
					foreach ( class_exists( 'FrmForm' ) ? (array) FrmForm::get_published_forms() : array() as $form ) {
						$out[] = array( 'id' => (string) $form->id, 'title' => $form->name );
					}
					return $out;
				case 'ninja':
					foreach ( function_exists( 'Ninja_Forms' ) ? (array) Ninja_Forms()->form()->get_forms() : array() as $form ) {
						$out[] = array( 'id' => (string) $form->get_id(), 'title' => (string) $form->get_setting( 'title' ) );
					}
					return $out;
				case 'elementor':
					return self::forms_seen( 'elementor' );
				default:
					return self::forms_seen( $key ); // Add-on sources: the forms that sent entries.
			}
			foreach ( $posts as $post ) {
				$out[] = array( 'id' => (string) $post->ID, 'title' => $post->post_title );
			}
		} catch ( \Throwable $e ) {
			return self::forms_seen( $key );
		}
		return $out;
	}

	/**
	 * Forms that already sent entries (for plugins without a form list).
	 *
	 * @param string $key Plugin key.
	 * @return array
	 */
	public static function forms_seen( $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT form_id, MAX(form_name) AS form_name FROM %i WHERE form_plugin = %s GROUP BY form_id', AARYE_Entries::table(), $key ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array( 'id' => (string) $row->form_id, 'title' => '' !== $row->form_name ? $row->form_name : $row->form_id );
		}
		return $out;
	}

	/**
	 * All forms of all active plugins.
	 *
	 * @return array<int, array{plugin: string, plugin_name: string, id: string, title: string}>
	 */
	public static function all_forms() {
		$out = array();
		foreach ( self::plugins() as $key => $plugin ) {
			if ( ! self::is_active( $key ) ) {
				continue;
			}
			foreach ( self::forms( $key ) as $form ) {
				$out[] = array_merge( $form, array( 'plugin' => $key, 'plugin_name' => $plugin['name'] ) );
			}
		}
		return $out;
	}

	/**
	 * Link to the entry inside the form plugin, when it keeps entries.
	 *
	 * @param object $e Entry.
	 * @return string
	 */
	public static function entry_link( $e ) {
		$id = (string) $e->source_entry_id;
		if ( '' === $id ) {
			return '';
		}
		switch ( $e->form_plugin ) {
			case 'gravity':
				return admin_url( 'admin.php?page=gf_entries&view=entry&id=' . rawurlencode( $e->form_id ) . '&lid=' . rawurlencode( $id ) );
			case 'fluentform':
				return admin_url( 'admin.php?page=fluent_forms&route=entries&form_id=' . rawurlencode( $e->form_id ) . '#/entries/' . rawurlencode( $id ) );
			case 'formidable':
				return admin_url( 'admin.php?page=formidable-entries&frm_action=show&id=' . rawurlencode( $id ) );
			case 'jetformbuilder':
				return admin_url( 'admin.php?page=jfb-records&item_id=' . rawurlencode( $id ) );
			case 'wpforms':
				return admin_url( 'admin.php?page=wpforms-entries&view=details&entry_id=' . rawurlencode( $id ) );
			case 'ninja':
				return admin_url( 'post.php?post=' . rawurlencode( $id ) . '&action=edit' );
			case 'forminator':
				return admin_url( 'admin.php?page=forminator-entries&form_type=forminator_forms&form_id=' . rawurlencode( $e->form_id ) );
		}
		return '';
	}
}
