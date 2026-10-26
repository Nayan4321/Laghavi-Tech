<?php
/**
 * Media translation: images and files get a language, so their title, alt text and caption can be
 * translated. A translation uses the same file.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Media.
 */
class Vacanam_Media {

	/**
	 * Attachment being deleted.
	 *
	 * @var int
	 */
	private static $deleting = 0;

	/**
	 * Its translations.
	 *
	 * @var int[]
	 */
	private static $group = array();

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( ! Vacanam_Settings::on( 'media' ) || ! Vacanam_Languages::all() ) {
			return;
		}
		add_action( 'add_attachment', array( __CLASS__, 'uploaded' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'deleting' ) );
		add_filter( 'wp_delete_file', array( __CLASS__, 'keep_shared_file' ) );
		add_action( 'deleted_post', array( __CLASS__, 'deleted' ) );
	}

	/**
	 * New upload: the language of the post it is uploaded to, or the language chosen in the admin bar,
	 * or the main language.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function uploaded( $id ) {
		if ( Vacanam_Duplicator::running() || '' !== Vacanam_Model::post_lang( $id ) ) {
			return;
		}
		$parent = (int) get_post_field( 'post_parent', $id );
		$lang   = $parent ? Vacanam_Model::post_lang( $parent ) : '';
		if ( '' === $lang ) {
			$lang = Vacanam_Admin_Content::filter_language();
		}
		Vacanam_Model::set_post_lang( $id, '' !== $lang ? $lang : Vacanam_Languages::default_slug() );
	}

	/**
	 * Remember which attachment is being deleted.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function deleting( $id ) {
		self::$deleting = (int) $id;
		self::$group    = Vacanam_Model::post_translations( $id );
	}

	/**
	 * The attachment is gone: its translations stay, without it.
	 *
	 * @param int $id Post ID.
	 */
	public static function deleted( $id ) {
		if ( (int) $id !== self::$deleting ) {
			return;
		}
		$rest = array_diff( self::$group, array( (int) $id ) );
		if ( $rest ) {
			Vacanam_Model::link_posts( $rest );
		}
		self::$deleting = 0;
		self::$group    = array();
	}

	/**
	 * Keep the file while a translation of the attachment still uses it.
	 *
	 * @param string $file File path.
	 * @return string '' to keep the file.
	 */
	public static function keep_shared_file( $file ) {
		if ( ! self::$deleting ) {
			return $file;
		}
		$attached = get_post_meta( self::$deleting, '_wp_attached_file', true );
		if ( ! $attached ) {
			return $file;
		}
		foreach ( self::$group as $other ) {
			if ( $other !== self::$deleting && get_post_meta( $other, '_wp_attached_file', true ) === $attached ) {
				return '';
			}
		}
		return $file;
	}
}
