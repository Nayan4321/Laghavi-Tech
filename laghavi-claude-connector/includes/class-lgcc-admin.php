<?php
/**
 * Tools > Claude Connector: create, change and revoke the key, see how to connect, and read the log.
 *
 * @package LaghaviClaudeConnector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen.
 */
class LGCC_Admin {

	const PAGE = 'laghavi-claude-connector';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		foreach ( array( 'create', 'revoke', 'mode', 'clear_log' ) as $action ) {
			add_action( 'admin_post_lgcc_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( LGCC_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_management_page( __( 'Claude Connector', 'laghavi-claude-connector' ), __( 'Claude Connector', 'laghavi-claude-connector' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'laghavi-claude-connector' ) . '</a>' );
		return $links;
	}

	/**
	 * While a key works, every administrator sees it.
	 */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) || ! LGCC_Access::active() ) {
			return;
		}
		$a    = LGCC_Access::get();
		$mode = 'fix' === $a['mode'] ? __( 'can look and fix', 'laghavi-claude-connector' ) : __( 'can only look', 'laghavi-claude-connector' );
		/* translators: 1: mode, 2: end date */
		$text = sprintf( __( 'Claude Connector is on: Claude %1$s on this site, %2$s.', 'laghavi-claude-connector' ), $mode, $a['expires'] ? sprintf( /* translators: %s: date */ __( 'until %s', 'laghavi-claude-connector' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $a['expires'] ) ) : __( 'with no end date', 'laghavi-claude-connector' ) );
		echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . ' <a href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Manage or switch off', 'laghavi-claude-connector' ) . '</a></p></div>';
	}

	/**
	 * Check a form.
	 *
	 * @param string $action Action.
	 */
	private static function check( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'laghavi-claude-connector' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the screen.
	 */
	private static function back() {
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Create a key.
	 */
	public static function handle_create() {
		self::check( 'lgcc_create' );
		$mode = isset( $_POST['mode'] ) && 'fix' === $_POST['mode'] ? 'fix' : 'read';
		$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 7;
		$ips  = isset( $_POST['ips'] ) ? preg_split( '/[\s,]+/', sanitize_textarea_field( wp_unslash( $_POST['ips'] ) ) ) : array();
		$key  = LGCC_Access::create( $mode, $days, $ips );
		// Shown once, to the admin who created it.
		set_transient( 'lgcc_new_key_' . get_current_user_id(), $key, 10 * MINUTE_IN_SECONDS );
		self::back();
	}

	/**
	 * Revoke the key.
	 */
	public static function handle_revoke() {
		self::check( 'lgcc_revoke' );
		LGCC_Access::revoke();
		self::back();
	}

	/**
	 * Change the mode.
	 */
	public static function handle_mode() {
		self::check( 'lgcc_mode' );
		$mode = isset( $_POST['mode'] ) && 'fix' === $_POST['mode'] ? 'fix' : 'read';
		LGCC_Access::update( array( 'mode' => $mode ) );
		LGCC_Access::log( 'key', 'Mode changed to ' . ( 'fix' === $mode ? 'look and fix' : 'look only' ), true );
		self::back();
	}

	/**
	 * Clear the log.
	 */
	public static function handle_clear_log() {
		self::check( 'lgcc_clear_log' );
		delete_option( LGCC_Access::LOG );
		self::back();
	}

	/**
	 * The screen.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$a      = LGCC_Access::get();
		$active = LGCC_Access::active();
		$new    = get_transient( 'lgcc_new_key_' . get_current_user_id() );
		if ( $new ) {
			delete_transient( 'lgcc_new_key_' . get_current_user_id() );
		}
		$https = 0 === strpos( rest_url(), 'https://' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Claude Connector', 'laghavi-claude-connector' ); ?></h1>
			<p><?php esc_html_e( 'Connect Claude to this site so it can find and fix problems: error logs, site health, plugins, files and the database. Every change is backed up first, and everything Claude does is listed below.', 'laghavi-claude-connector' ); ?></p>
			<?php if ( ! $https ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'This site does not use HTTPS: the key would travel unencrypted. Switch the site to HTTPS before connecting.', 'laghavi-claude-connector' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $new ) : ?>
				<div class="notice notice-success inline" style="padding:12px 16px">
					<p><strong><?php esc_html_e( 'Your connector address. Copy it now: it is shown only once.', 'laghavi-claude-connector' ); ?></strong></p>
					<p><input type="text" readonly="readonly" class="large-text code" value="<?php echo esc_attr( LGCC_Server::url( $new ) ); ?>" onclick="this.select()" /></p>
					<p><?php esc_html_e( 'Anyone with this address can use the connector, so keep it private.', 'laghavi-claude-connector' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Status', 'laghavi-claude-connector' ); ?></h2>
			<?php if ( $active ) : ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Connector', 'laghavi-claude-connector' ); ?></th><td><strong style="color:#00a32a"><?php esc_html_e( 'On', 'laghavi-claude-connector' ); ?></strong></td></tr>
					<tr><th><?php esc_html_e( 'Ends', 'laghavi-claude-connector' ); ?></th><td><?php echo esc_html( $a['expires'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $a['expires'] ) : __( 'No end date', 'laghavi-claude-connector' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Allowed addresses', 'laghavi-claude-connector' ); ?></th><td><?php echo esc_html( $a['ips'] ? implode( ', ', $a['ips'] ) : __( 'Any', 'laghavi-claude-connector' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Claude may', 'laghavi-claude-connector' ); ?></th><td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="lgcc_mode" /><?php wp_nonce_field( 'lgcc_mode' ); ?>
							<label><input type="radio" name="mode" value="read" <?php checked( 'read', $a['mode'] ); ?> /> <?php esc_html_e( 'Only look (find problems and explain them)', 'laghavi-claude-connector' ); ?></label><br />
							<label><input type="radio" name="mode" value="fix" <?php checked( 'fix', $a['mode'] ); ?> /> <?php esc_html_e( 'Look and fix (edit files with backups, activate, deactivate and update plugins, change settings, turn on error logging, clear caches)', 'laghavi-claude-connector' ); ?></label><br />
							<?php submit_button( __( 'Save', 'laghavi-claude-connector' ), 'secondary', 'submit', false ); ?>
						</form>
					</td></tr>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="lgcc_revoke" /><?php wp_nonce_field( 'lgcc_revoke' ); ?>
					<?php submit_button( __( 'Switch off now (revoke the key)', 'laghavi-claude-connector' ), 'delete' ); ?>
				</form>
			<?php else : ?>
				<p><?php echo esc_html( $a['hash'] && $a['expires'] && $a['expires'] < time() ? __( 'Off: the last key has expired.', 'laghavi-claude-connector' ) : __( 'Off.', 'laghavi-claude-connector' ) ); ?></p>
			<?php endif; ?>

			<h2><?php echo esc_html( $active ? __( 'Replace the key', 'laghavi-claude-connector' ) : __( 'Switch on', 'laghavi-claude-connector' ) ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lgcc_create" /><?php wp_nonce_field( 'lgcc_create' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Claude may', 'laghavi-claude-connector' ); ?></th><td>
						<label><input type="radio" name="mode" value="read" checked="checked" /> <?php esc_html_e( 'Only look', 'laghavi-claude-connector' ); ?></label><br />
						<label><input type="radio" name="mode" value="fix" /> <?php esc_html_e( 'Look and fix', 'laghavi-claude-connector' ); ?></label>
					</td></tr>
					<tr><th><?php esc_html_e( 'Works for', 'laghavi-claude-connector' ); ?></th><td><select name="days">
						<option value="1"><?php esc_html_e( '1 day', 'laghavi-claude-connector' ); ?></option>
						<option value="7" selected="selected"><?php esc_html_e( '7 days', 'laghavi-claude-connector' ); ?></option>
						<option value="30"><?php esc_html_e( '30 days', 'laghavi-claude-connector' ); ?></option>
						<option value="0"><?php esc_html_e( 'No end date', 'laghavi-claude-connector' ); ?></option>
					</select></td></tr>
					<tr><th><?php esc_html_e( 'Only from these IP addresses', 'laghavi-claude-connector' ); ?></th><td><textarea name="ips" rows="2" class="regular-text code" placeholder="<?php esc_attr_e( 'Optional, one per line', 'laghavi-claude-connector' ); ?>"></textarea><p class="description"><?php esc_html_e( 'Leave empty for Claude.ai connectors: their addresses change.', 'laghavi-claude-connector' ); ?></p></td></tr>
				</table>
				<?php submit_button( $active ? __( 'Create a new key (the current one stops working)', 'laghavi-claude-connector' ) : __( 'Create the key', 'laghavi-claude-connector' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'How to connect', 'laghavi-claude-connector' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Claude.ai or the Claude app: Settings > Connectors > Add custom connector. Give it a name (e.g. the client\'s site) and paste the connector address. Then, in a chat, turn the connector on and ask Claude to check the site.', 'laghavi-claude-connector' ); ?></li>
				<li><?php esc_html_e( 'Claude Code: claude mcp add --transport http my-site "connector address"', 'laghavi-claude-connector' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'What Claude did', 'laghavi-claude-connector' ); ?></h2>
			<?php $log = LGCC_Access::entries(); ?>
			<?php if ( ! $log ) : ?>
				<p><?php esc_html_e( 'Nothing yet.', 'laghavi-claude-connector' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'When', 'laghavi-claude-connector' ); ?></th><th><?php esc_html_e( 'Tool', 'laghavi-claude-connector' ); ?></th><th><?php esc_html_e( 'Details', 'laghavi-claude-connector' ); ?></th><th><?php esc_html_e( 'Result', 'laghavi-claude-connector' ); ?></th><th><?php esc_html_e( 'From', 'laghavi-claude-connector' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $log, 0, 100 ) as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $row['time'] ) ); ?></td>
							<td><code><?php echo esc_html( $row['tool'] ); ?></code></td>
							<td style="word-break:break-all"><?php echo esc_html( $row['what'] ); ?></td>
							<td><?php echo $row['ok'] ? '<span style="color:#00a32a">✓</span>' : '<span style="color:#d63638">✕</span>'; ?></td>
							<td><?php echo esc_html( $row['ip'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
					<input type="hidden" name="action" value="lgcc_clear_log" /><?php wp_nonce_field( 'lgcc_clear_log' ); ?>
					<?php submit_button( __( 'Clear the log', 'laghavi-claude-connector' ), 'secondary small', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
