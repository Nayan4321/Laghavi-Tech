<?php
/**
 * Privacy: suggested privacy-policy text, and WordPress's personal data export and erase tools.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy.
 */
class AARYE_Privacy {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Suggested text for the privacy policy (Settings > Privacy).
	 */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . __( 'When you visit this website we store a first-party cookie (aarye_attr) with how you arrived: the ad, search engine or website that sent you, the advertising tags in the link (such as utm_campaign or a click ID) and the first page you saw. It also holds signs that a person is using the page, used only to tell real enquiries from spam: the number of pages viewed, when the current page was opened, whether the mouse, touch screen or keyboard was used, whether the browser is controlled by automation software, the time zone and the screen size. A second cookie (aarye_vid) holds a random visitor number. When you send a form, this information is saved with your entry so we know which of our ads or channels brought you. The cookies expire after 90 days and are not shared with anyone by this plugin.', 'aarye-marketing-addon-for-forms' ) . '</p>';
		wp_add_privacy_policy_content( 'Aarye Marketing Addon for Forms', wp_kses_post( $text ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['aarye-marketing-addon-for-forms'] = array(
			'exporter_friendly_name' => __( 'Form entries (Aarye)', 'aarye-marketing-addon-for-forms' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['aarye-marketing-addon-for-forms'] = array(
			'eraser_friendly_name' => __( 'Form entries (Aarye)', 'aarye-marketing-addon-for-forms' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Entries of an email address.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (1-based).
	 * @return object[]
	 */
	private static function entries_for( $email, $page ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id LIMIT 50 OFFSET %d', AARYE_Entries::table(), $email, ( $page - 1 ) * 50 ) );
	}

	/**
	 * Export.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$rows = self::entries_for( $email, $page );
		$data = array();
		foreach ( $rows as $e ) {
			$items = array(
				array( 'name' => __( 'Date', 'aarye-marketing-addon-for-forms' ), 'value' => $e->created_at ),
				array( 'name' => __( 'Form', 'aarye-marketing-addon-for-forms' ), 'value' => $e->form_name ),
				array( 'name' => __( 'Came from', 'aarye-marketing-addon-for-forms' ), 'value' => $e->source_label . ( '' !== $e->campaign ? ' · ' . $e->campaign : '' ) ),
				array( 'name' => __( 'IP address', 'aarye-marketing-addon-for-forms' ), 'value' => $e->ip ),
			);
			foreach ( AARYE_Entries::json( $e, 'fields' ) as $field ) {
				$items[] = array( 'name' => $field['label'], 'value' => $field['value'] );
			}
			$data[] = array(
				'group_id'    => 'aarye-entries',
				'group_label' => __( 'Form entries', 'aarye-marketing-addon-for-forms' ),
				'item_id'     => 'aarye-entry-' . $e->id,
				'data'        => $items,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 50,
		);
	}

	/**
	 * Erase.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		$rows = self::entries_for( $email, 1 );
		foreach ( $rows as $e ) {
			AARYE_Entries::delete( $e->id );
		}
		return array(
			'items_removed'  => count( $rows ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $rows ) < 50,
		);
	}
}
