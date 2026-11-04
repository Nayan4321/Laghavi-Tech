<?php
/**
 * Plugin Name:       Laghavi Claude Connector
 * Description:       Lets Claude look into this site and fix problems through a secure, time-limited connector (MCP): error logs, site health, plugins, files and database, with backups before every change and a log of everything it does.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       laghavi-claude-connector
 *
 * @package LaghaviClaudeConnector
 */

defined( 'ABSPATH' ) || exit;

define( 'LGCC_VERSION', '1.1.0' );
define( 'LGCC_FILE', __FILE__ );
define( 'LGCC_DIR', plugin_dir_path( __FILE__ ) );

require_once LGCC_DIR . 'includes/class-lgcc-access.php';
require_once LGCC_DIR . 'includes/class-lgcc-tools.php';
require_once LGCC_DIR . 'includes/class-lgcc-server.php';
require_once LGCC_DIR . 'includes/class-lgcc-admin.php';

LGCC_Server::init();
if ( is_admin() ) {
	LGCC_Admin::init();
}
