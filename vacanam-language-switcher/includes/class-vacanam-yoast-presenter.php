<?php
/**
 * Yoast SEO presenter for og:locale:alternate.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Vacanam_Yoast_Locale_Presenter' ) ) {
	/**
	 * og:locale:alternate.
	 */
	class Vacanam_Yoast_Locale_Presenter extends Yoast\WP\SEO\Presenters\Abstract_Indexable_Presenter {

		/**
		 * Locale.
		 *
		 * @var string
		 */
		private $locale;

		/**
		 * Constructor.
		 *
		 * @param string $locale Locale.
		 */
		public function __construct( $locale ) {
			$this->locale = $locale;
		}

		/**
		 * The tag.
		 *
		 * @return string
		 */
		public function present() {
			return '<meta property="og:locale:alternate" content="' . esc_attr( $this->locale ) . '" />';
		}

		/**
		 * The value.
		 *
		 * @return string
		 */
		public function get() {
			return $this->locale;
		}
	}
}
