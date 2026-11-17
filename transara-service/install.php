<?php
/**
 * Create the database tables. Run once from the command line (Hostinger: SSH or the cron job tool):
 *   php install.php
 *
 * @package TransaraService
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'Run from the command line.' );
}
require __DIR__ . '/app/bootstrap.php';
$sql = (string) file_get_contents( __DIR__ . '/app/schema.sql' );
foreach ( array_filter( array_map( 'trim', explode( ';', preg_replace( '/^--.*$/m', '', $sql ) ) ) ) as $stmt ) {
	trs_db()->exec( $stmt );
}
echo "Tables are ready.\n";
if ( '' === (string) trs_config( 'admin_hash' ) ) {
	echo "Next: set admin_hash in config.php (see the comment there).\n";
}
