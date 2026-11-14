<?php
/**
 * Rinnovo invoices: one invoice engine shared by every Rinnovo Pro plugin. Each Pro carries a copy of
 * this folder; when several Pros are active, only the newest copy is loaded, so an order always gets
 * one invoice, one number sequence and one settings screen (WooCommerce > Invoices).
 *
 * Source of truth: rinnovo-shared/invoices in the repository; copied into each Pro by sync.sh.
 *
 * @package RinnovoInvoices
 */

defined( 'ABSPATH' ) || exit;

// This copy's version: raise it whenever anything in this folder changes.
$rinnovo_invoices_copy = '2.0.0';

if ( ! isset( $GLOBALS['rinnovo_invoice_copies'] ) || ! is_array( $GLOBALS['rinnovo_invoice_copies'] ) ) {
	$GLOBALS['rinnovo_invoice_copies'] = array();
}
$GLOBALS['rinnovo_invoice_copies'][ __DIR__ ] = $rinnovo_invoices_copy;
unset( $rinnovo_invoices_copy );

if ( ! function_exists( 'rinnovo_invoices_enable' ) ) {

	/**
	 * A licensed Rinnovo Pro asks for invoices (call it on rinnovo_loaded, before priority 99).
	 */
	function rinnovo_invoices_enable() {
		$GLOBALS['rinnovo_invoices_wanted'] = true;
	}

	/**
	 * Load the newest copy once, when at least one licensed Pro wants invoices.
	 */
	function rinnovo_invoices_boot() {
		if ( empty( $GLOBALS['rinnovo_invoices_wanted'] ) || class_exists( 'RINNOVO_Invoices', false ) ) {
			return;
		}
		$copies = (array) $GLOBALS['rinnovo_invoice_copies'];
		uasort( $copies, 'version_compare' );
		end( $copies );
		$dir = (string) key( $copies );
		define( 'RINNOVO_INVOICES_VERSION', (string) current( $copies ) );
		define( 'RINNOVO_INVOICES_DIR', trailingslashit( $dir ) );
		require_once RINNOVO_INVOICES_DIR . 'includes/class-rinnovo-inv-pdf.php';
		require_once RINNOVO_INVOICES_DIR . 'includes/class-rinnovo-invoice-pdf.php';
		require_once RINNOVO_INVOICES_DIR . 'includes/class-rinnovo-invoice-docs.php';
		require_once RINNOVO_INVOICES_DIR . 'includes/class-rinnovo-invoices.php';
		// Older add-ons refer to the engine by its first name.
		if ( ! class_exists( 'RINNOVOPRO_Invoices', false ) ) {
			class_alias( 'RINNOVO_Invoices', 'RINNOVOPRO_Invoices' );
		}
		RINNOVO_Invoices::init();
		/**
		 * The shared invoice engine is ready.
		 */
		do_action( 'rinnovo_invoices_loaded' );
	}
	add_action( 'rinnovo_loaded', 'rinnovo_invoices_boot', 99 );
}
