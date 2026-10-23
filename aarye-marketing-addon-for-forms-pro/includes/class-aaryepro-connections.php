<?php
/**
 * Connections: settings of every destination, the Connections screen, the delivery log and the
 * "Sent to" card on each entry.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connections.
 */
class AARYEPRO_Connections {

	const OPTION  = 'aaryepro_connections';
	const STOPPED = 'aaryepro_stopped';
	const PAGE    = 'aarye-leads-connections';

	/**
	 * Destination objects.
	 *
	 * @var AARYEPRO_Destination[]|null
	 */
	private static $dests = null;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'aarye_admin_menu', array( __CLASS__, 'menu' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_aaryepro_connection', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_aaryepro_retry', array( __CLASS__, 'retry' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'aarye_entry_cards', array( __CLASS__, 'entry_card' ), 20 );
	}

	/**
	 * Every destination, by key.
	 *
	 * @return AARYEPRO_Destination[]
	 */
	public static function destinations() {
		if ( null === self::$dests ) {
			$list = array( new AARYEPRO_Dest_Hub(), new AARYEPRO_Dest_Zoho_Crm(), new AARYEPRO_Dest_Hubspot(), new AARYEPRO_Dest_Airtable(), new AARYEPRO_Dest_Notion(), new AARYEPRO_Dest_Mysql(), new AARYEPRO_Dest_Webhook() );
			/**
			 * Add destinations (objects extending AARYEPRO_Destination).
			 *
			 * @param AARYEPRO_Destination[] $list Destinations.
			 */
			$list        = (array) apply_filters( 'aaryepro_destinations', $list );
			self::$dests = array();
			foreach ( $list as $dest ) {
				if ( $dest instanceof AARYEPRO_Destination ) {
					self::$dests[ $dest->key() ] = $dest;
				}
			}
		}
		return self::$dests;
	}

	/**
	 * Saved settings of one destination.
	 *
	 * @param string $dest Destination key.
	 * @return array
	 */
	public static function settings( $dest ) {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && isset( $all[ $dest ] ) && is_array( $all[ $dest ] ) ? $all[ $dest ] : array();
	}

	/**
	 * One setting.
	 *
	 * @param string $dest Destination key.
	 * @param string $key  Setting.
	 * @return string
	 */
	public static function get( $dest, $key ) {
		$s = self::settings( $dest );
		return isset( $s[ $key ] ) ? (string) $s[ $key ] : '';
	}

	/**
	 * Switched on and set up.
	 *
	 * @return AARYEPRO_Destination[]
	 */
	public static function active() {
		$out = array();
		foreach ( self::destinations() as $key => $dest ) {
			if ( 'yes' === self::get( $key, 'enabled' ) && $dest->configured() ) {
				$out[ $key ] = $dest;
			}
		}
		return $out;
	}

	/**
	 * Why sending to a destination stopped, or ''.
	 *
	 * @param string $dest Destination key.
	 * @return string
	 */
	public static function stopped( $dest ) {
		$s = get_option( self::STOPPED, array() );
		return is_array( $s ) && isset( $s[ $dest ] ) ? (string) $s[ $dest ] : '';
	}

	/**
	 * Stop sending to a destination until its settings are saved again.
	 *
	 * @param string $dest    Destination key.
	 * @param string $message Why.
	 */
	public static function stop( $dest, $message ) {
		$s          = (array) get_option( self::STOPPED, array() );
		$s[ $dest ] = '' !== $message ? $message : __( 'The service refused the connection.', 'aarye-marketing-addon-for-forms-pro' );
		update_option( self::STOPPED, $s, false );
	}

	/**
	 * Start sending again.
	 *
	 * @param string $dest Destination key.
	 */
	public static function resume( $dest ) {
		$s = (array) get_option( self::STOPPED, array() );
		unset( $s[ $dest ] );
		update_option( self::STOPPED, $s, false );
	}

	/**
	 * Address of the screen.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Submenu.
	 *
	 * @param string $parent Parent slug.
	 */
	public static function menu( $parent ) {
		add_submenu_page( $parent, __( 'Connections', 'aarye-marketing-addon-for-forms-pro' ), __( 'Connections', 'aarye-marketing-addon-for-forms-pro' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Styles for Pro screens.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'aarye-leads' ) ) {
			return;
		}
		wp_enqueue_style( 'aaryepro-admin', AARYEPRO_URL . 'assets/css/pro-admin.css', array(), aaryepro_asset_version( 'assets/css/pro-admin.css' ) );
	}

	/**
	 * Warn when a destination stopped.
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$dests = self::destinations();
		foreach ( (array) get_option( self::STOPPED, array() ) as $key => $why ) {
			if ( ! isset( $dests[ $key ] ) ) {
				continue;
			}
			printf(
				'<div class="notice notice-error"><p><b>%1$s</b> %2$s <a href="%3$s">%4$s</a></p></div>',
				/* translators: %s: service name. */
				esc_html( sprintf( __( 'Aarye stopped sending entries to %s:', 'aarye-marketing-addon-for-forms-pro' ), $dests[ $key ]->name() ) ),
				esc_html( $why ),
				esc_url( self::url( array( 'dest' => $key ) ) ),
				esc_html__( 'Fix the connection', 'aarye-marketing-addon-for-forms-pro' )
			);
		}
	}

	/**
	 * Status label of a destination.
	 *
	 * @param string $key Destination key.
	 * @return array{0: string, 1: string} Label and CSS modifier.
	 */
	private static function status( $key ) {
		$dests = self::destinations();
		if ( '' !== self::stopped( $key ) ) {
			return array( __( 'Stopped', 'aarye-marketing-addon-for-forms-pro' ), 'bad' );
		}
		if ( 'yes' === self::get( $key, 'enabled' ) && $dests[ $key ]->configured() ) {
			return array( __( 'On', 'aarye-marketing-addon-for-forms-pro' ), 'ok' );
		}
		if ( $dests[ $key ]->configured() ) {
			return array( __( 'Off', 'aarye-marketing-addon-for-forms-pro' ), 'off' );
		}
		return array( __( 'Not set up', 'aarye-marketing-addon-for-forms-pro' ), 'off' );
	}

	/**
	 * Labels of job statuses.
	 *
	 * @return array<string, string>
	 */
	public static function job_labels() {
		return array(
			'pending' => __( 'Waiting', 'aarye-marketing-addon-for-forms-pro' ),
			'done'    => __( 'Sent', 'aarye-marketing-addon-for-forms-pro' ),
			'failed'  => __( 'Failed', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * The screen.
	 */
	public static function page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation.
		$key   = isset( $_GET['dest'] ) ? sanitize_key( wp_unslash( $_GET['dest'] ) ) : '';
		$dests = self::destinations();
		if ( isset( $dests[ $key ] ) ) {
			self::page_dest( $dests[ $key ] );
			return;
		}
		if ( AARYEPRO_Zoho_Forms::KEY === $key ) {
			AARYEPRO_Zoho_Forms::page();
			return;
		}
		?>
		<div class="wrap aarye">
			<h1><?php esc_html_e( 'Connections', 'aarye-marketing-addon-for-forms-pro' ); ?></h1>
			<p class="aarye-muted"><?php esc_html_e( 'Every new entry is sent to each connection that is switched on, a few seconds after the form is sent. If a service is busy or down, Aarye tries again for 7 days.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
			<div class="aaryepro-dests">
				<?php foreach ( $dests as $k => $dest ) : ?>
					<?php
					list( $label, $mod ) = self::status( $k );
					$counts              = AARYEPRO_Queue::counts( $k );
					?>
					<div class="aarye-card aaryepro-dest">
						<h2><?php echo esc_html( $dest->name() ); ?> <span class="aaryepro-pill aaryepro-pill--<?php echo esc_attr( $mod ); ?>"><?php echo esc_html( $label ); ?></span></h2>
						<p><?php echo esc_html( $dest->description() ); ?></p>
						<?php if ( array_sum( $counts ) ) : ?>
							<p class="aarye-muted">
								<?php
								/* translators: 1: sent, 2: waiting, 3: failed. */
								echo esc_html( sprintf( __( '%1$d sent · %2$d waiting · %3$d failed', 'aarye-marketing-addon-for-forms-pro' ), $counts['done'], $counts['pending'], $counts['failed'] ) );
								?>
							</p>
						<?php endif; ?>
						<p><a class="button <?php echo 'off' === $mod && ! $dest->configured() ? 'button-primary' : ''; ?>" href="<?php echo esc_url( self::url( array( 'dest' => $k ) ) ); ?>"><?php echo $dest->configured() ? esc_html__( 'Settings', 'aarye-marketing-addon-for-forms-pro' ) : esc_html__( 'Set up', 'aarye-marketing-addon-for-forms-pro' ); ?></a></p>
					</div>
				<?php endforeach; ?>
				<div class="aarye-card aaryepro-dest">
					<h2><?php esc_html_e( 'Zoho Forms', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
					<p><?php esc_html_e( 'Receive entries of Zoho Forms embedded on your site, with their ad source, in Aarye (and from here in every connection above).', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( self::url( array( 'dest' => AARYEPRO_Zoho_Forms::KEY ) ) ); ?>"><?php esc_html_e( 'Set up', 'aarye-marketing-addon-for-forms-pro' ); ?></a></p>
				</div>
			</div>
			<?php self::log_table( AARYEPRO_Queue::latest( '', '', 30 ), true ); ?>
		</div>
		<?php
	}

	/**
	 * Settings of one destination.
	 *
	 * @param AARYEPRO_Destination $dest Destination.
	 */
	private static function page_dest( $dest ) {
		$key = $dest->key();
		$s   = self::settings( $key );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Messages after redirect.
		$msg  = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
		$ok   = isset( $_GET['ok'] ) ? '1' === $_GET['ok'] : true;
		$test = get_transient( 'aaryepro_test_' . get_current_user_id() );
		// phpcs:enable
		delete_transient( 'aaryepro_test_' . get_current_user_id() );
		list( $label, $mod ) = self::status( $key );
		?>
		<div class="wrap aarye">
			<p><a href="<?php echo esc_url( self::url() ); ?>">← <?php esc_html_e( 'All connections', 'aarye-marketing-addon-for-forms-pro' ); ?></a></p>
			<h1><?php echo esc_html( $dest->name() ); ?> <span class="aaryepro-pill aaryepro-pill--<?php echo esc_attr( $mod ); ?>"><?php echo esc_html( $label ); ?></span></h1>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_array( $test ) ) : ?>
				<div class="notice <?php echo $test['ok'] ? 'notice-success' : 'notice-error'; ?> inline"><p><b><?php echo $test['ok'] ? esc_html__( 'Test passed:', 'aarye-marketing-addon-for-forms-pro' ) : esc_html__( 'Test failed:', 'aarye-marketing-addon-for-forms-pro' ); ?></b> <?php echo esc_html( $test['message'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== self::stopped( $key ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( self::stopped( $key ) ); ?> <?php esc_html_e( 'Entries are kept and sent once you save working settings.', 'aarye-marketing-addon-for-forms-pro' ); ?></p></div>
			<?php endif; ?>
			<div class="aarye-grid aarye-grid--entry">
				<div class="aarye-card">
					<h2><?php esc_html_e( 'Settings', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
					<p><?php echo esc_html( $dest->description() ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
						<input type="hidden" name="action" value="aaryepro_connection" />
						<input type="hidden" name="dest" value="<?php echo esc_attr( $key ); ?>" />
						<?php wp_nonce_field( 'aaryepro_connection_' . $key ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Send new entries', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
								<td><label><input type="checkbox" name="s[enabled]" value="yes" <?php checked( isset( $s['enabled'] ) ? $s['enabled'] : '', 'yes' ); ?> /> <?php esc_html_e( 'On', 'aarye-marketing-addon-for-forms-pro' ); ?></label></td>
							</tr>
							<?php foreach ( $dest->fields() as $fk => $field ) : ?>
								<?php
								$value  = isset( $s[ $fk ] ) ? (string) $s[ $fk ] : '';
								$secret = 'password' === $field['type'] || ! empty( $field['secret'] );
								$id     = 'aaryepro-' . $key . '-' . $fk;
								?>
								<tr>
									<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
									<td>
										<?php if ( 'select' === $field['type'] ) : ?>
											<select id="<?php echo esc_attr( $id ); ?>" name="s[<?php echo esc_attr( $fk ); ?>]">
												<?php foreach ( $field['options'] as $ov => $ol ) : ?>
													<option value="<?php echo esc_attr( $ov ); ?>" <?php selected( $value, (string) $ov ); ?>><?php echo esc_html( $ol ); ?></option>
												<?php endforeach; ?>
											</select>
										<?php elseif ( 'checkbox' === $field['type'] ) : ?>
											<label><input id="<?php echo esc_attr( $id ); ?>" type="checkbox" name="s[<?php echo esc_attr( $fk ); ?>]" value="yes" <?php checked( $value, 'yes' ); ?> /> <?php echo isset( $field['help'] ) ? esc_html( $field['help'] ) : ''; ?></label>
										<?php elseif ( 'textarea' === $field['type'] ) : ?>
											<textarea id="<?php echo esc_attr( $id ); ?>" class="large-text code" rows="4" name="s[<?php echo esc_attr( $fk ); ?>]" placeholder="<?php echo esc_attr( $secret && '' !== $value ? __( 'Saved. Leave empty to keep, or type new values to replace.', 'aarye-marketing-addon-for-forms-pro' ) : ( isset( $field['placeholder'] ) ? $field['placeholder'] : '' ) ); ?>"><?php echo $secret ? '' : esc_textarea( $value ); ?></textarea>
										<?php else : ?>
											<input id="<?php echo esc_attr( $id ); ?>" class="regular-text<?php echo $secret ? ' code' : ''; ?>" type="<?php echo $secret ? 'password' : esc_attr( 'number' === $field['type'] ? 'number' : ( 'url' === $field['type'] ? 'url' : 'text' ) ); ?>" <?php echo 'number' === $field['type'] ? 'step="any" min="0"' : ''; ?> name="s[<?php echo esc_attr( $fk ); ?>]" value="<?php echo $secret ? '' : esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $secret && '' !== $value ? '•••••••• ' . __( 'saved (leave empty to keep)', 'aarye-marketing-addon-for-forms-pro' ) : ( isset( $field['placeholder'] ) ? $field['placeholder'] : '' ) ); ?>" />
										<?php endif; ?>
										<?php if ( ! empty( $field['help'] ) && 'checkbox' !== $field['type'] ) : ?>
											<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
						<p>
							<button type="submit" name="todo" value="save" class="button button-primary"><?php esc_html_e( 'Save', 'aarye-marketing-addon-for-forms-pro' ); ?></button>
							<?php if ( $dest->configured() ) : ?>
								<button type="submit" name="todo" value="test" class="button"><?php esc_html_e( 'Save and test connection', 'aarye-marketing-addon-for-forms-pro' ); ?></button>
							<?php endif; ?>
						</p>
						<?php if ( $dest->configured() && 'yes' === self::get( $key, 'enabled' ) ) : ?>
							<p class="aaryepro-backfill">
								<?php esc_html_e( 'Also send earlier entries:', 'aarye-marketing-addon-for-forms-pro' ); ?>
								<select name="days">
									<option value="7"><?php esc_html_e( 'last 7 days', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
									<option value="30"><?php esc_html_e( 'last 30 days', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
									<option value="90"><?php esc_html_e( 'last 90 days', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
									<option value="3650"><?php esc_html_e( 'all', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
								</select>
								<button type="submit" name="todo" value="backfill" class="button"><?php esc_html_e( 'Send', 'aarye-marketing-addon-for-forms-pro' ); ?></button>
							</p>
						<?php endif; ?>
					</form>
				</div>
				<div class="aarye-card">
					<h2><?php esc_html_e( 'How to connect', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
					<ol class="aarye-steps">
						<?php foreach ( $dest->steps() as $step ) : ?>
							<li><?php echo wp_kses( $step, array( 'b' => array(), 'code' => array(), 'br' => array(), 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></li>
						<?php endforeach; ?>
					</ol>
				</div>
			</div>
			<?php self::log_table( AARYEPRO_Queue::latest( $key, '', 50 ), false ); ?>
		</div>
		<?php
	}

	/**
	 * Delivery log.
	 *
	 * @param object[] $jobs     Jobs.
	 * @param bool     $show_dest Show the destination column.
	 */
	private static function log_table( $jobs, $show_dest ) {
		$dests  = self::destinations();
		$labels = self::job_labels();
		?>
		<h2><?php esc_html_e( 'Recent deliveries', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
		<?php if ( ! $jobs ) : ?>
			<p class="aarye-muted"><?php esc_html_e( 'Nothing sent yet.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped aarye-table">
			<thead><tr>
				<th><?php esc_html_e( 'Time', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
				<th><?php esc_html_e( 'Entry', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
				<?php if ( $show_dest ) : ?>
					<th><?php esc_html_e( 'Connection', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
				<?php endif; ?>
				<th><?php esc_html_e( 'Status', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
				<th><?php esc_html_e( 'Details', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $jobs as $job ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $job->updated_at, get_option( 'date_format' ) . ' H:i' ) ); ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=aarye-leads-entries&entry=' . (int) $job->entry_id ) ); ?>">#<?php echo esc_html( (string) $job->entry_id ); ?></a></td>
						<?php if ( $show_dest ) : ?>
							<td><?php echo esc_html( isset( $dests[ $job->dest ] ) ? $dests[ $job->dest ]->name() : $job->dest ); ?></td>
						<?php endif; ?>
						<td><span class="aaryepro-pill aaryepro-pill--<?php echo esc_attr( 'done' === $job->status ? 'ok' : ( 'failed' === $job->status ? 'bad' : 'off' ) ); ?>"><?php echo esc_html( isset( $labels[ $job->status ] ) ? $labels[ $job->status ] : $job->status ); ?></span>
							<?php if ( 'pending' === $job->status && (int) $job->tries > 0 ) : ?>
								<span class="aarye-muted">
								<?php
								/* translators: %s: time. */
								echo esc_html( sprintf( __( 'next try %s', 'aarye-marketing-addon-for-forms-pro' ), get_date_from_gmt( $job->next_at, 'H:i' ) ) );
								?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( (string) $job->last_message ); ?>
							<?php if ( 'failed' === $job->status ) : ?>
								<?php self::retry_button( $job ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * "Try again" button.
	 *
	 * @param object $job Job.
	 */
	private static function retry_button( $job ) {
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'aaryepro_retry', 'job' => (int) $job->id ), admin_url( 'admin-post.php' ) ), 'aaryepro_retry_' . (int) $job->id );
		echo ' <a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Try again', 'aarye-marketing-addon-for-forms-pro' ) . '</a>';
	}

	/**
	 * Save, test or backfill.
	 */
	public static function save() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below with the destination's own nonce.
		$key   = isset( $_POST['dest'] ) ? sanitize_key( wp_unslash( $_POST['dest'] ) ) : '';
		$dests = self::destinations();
		if ( ! current_user_can( 'manage_options' ) || ! isset( $dests[ $key ] ) || ! check_admin_referer( 'aaryepro_connection_' . $key ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		$dest = $dests[ $key ];
		$todo = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : 'save';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cleaned below by its type.
		$input = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array();
		$old   = self::settings( $key );

		if ( 'backfill' === $todo ) {
			$days = isset( $_POST['days'] ) ? max( 1, min( 3650, absint( $_POST['days'] ) ) ) : 30;
			$n    = AARYEPRO_Queue::backfill( $key, $days );
			/* translators: %d: number of entries. */
			self::back( $key, sprintf( _n( '%d entry will be sent in the next minutes.', '%d entries will be sent in the next minutes.', $n, 'aarye-marketing-addon-for-forms-pro' ), $n ) );
		}

		$values = array( 'enabled' => isset( $input['enabled'] ) && 'yes' === $input['enabled'] ? 'yes' : '' );
		foreach ( $dest->fields() as $fk => $field ) {
			$raw    = isset( $input[ $fk ] ) ? (string) $input[ $fk ] : '';
			$secret = 'password' === $field['type'] || ! empty( $field['secret'] );
			switch ( $field['type'] ) {
				case 'url':
					$v = esc_url_raw( trim( $raw ) );
					break;
				case 'textarea':
					$v = sanitize_textarea_field( $raw );
					break;
				case 'checkbox':
					$v = 'yes' === $raw ? 'yes' : '';
					break;
				case 'select':
					$v = isset( $field['options'][ $raw ] ) ? $raw : (string) key( $field['options'] );
					break;
				case 'number':
					$v = '' !== trim( $raw ) && is_numeric( $raw ) ? (string) max( 0, (float) $raw ) : '';
					break;
				default:
					$v = trim( sanitize_text_field( $raw ) );
			}
			if ( $secret && '' === $v && isset( $old[ $fk ] ) ) {
				$v = $old[ $fk ]; // Empty secret field: keep the saved one.
			}
			$values[ $fk ] = $v;
		}
		// Keep values a destination stores itself (tokens).
		foreach ( $old as $ok => $ov ) {
			if ( ! isset( $values[ $ok ] ) && ! array_key_exists( $ok, $dest->fields() ) ) {
				$values[ $ok ] = $ov;
			}
		}
		$values = $dest->on_save( $values, $old );
		if ( is_wp_error( $values ) ) {
			self::back( $key, $values->get_error_message(), false );
		}
		$all         = (array) get_option( self::OPTION, array() );
		$all[ $key ] = $values;
		update_option( self::OPTION, $all, false );
		self::resume( $key );
		self::$dests = null;
		AARYE_Async::kick(); // Send what waited while the connection was stopped.

		if ( 'test' === $todo ) {
			$dest = self::destinations()[ $key ];
			$res  = $dest->configured() ? $dest->test() : array( 'ok' => false, 'message' => __( 'Fill in the required fields first.', 'aarye-marketing-addon-for-forms-pro' ) );
			set_transient( 'aaryepro_test_' . get_current_user_id(), array( 'ok' => $res['ok'], 'message' => $res['message'] ), 120 );
			self::back( $key );
		}
		self::back( $key, __( 'Settings saved.', 'aarye-marketing-addon-for-forms-pro' ) );
	}

	/**
	 * Back to the settings with a message.
	 *
	 * @param string $key Destination.
	 * @param string $msg Message.
	 * @param bool   $ok  Success.
	 */
	private static function back( $key, $msg = '', $ok = true ) {
		$args = array( 'dest' => $key );
		if ( '' !== $msg ) {
			$args['msg'] = rawurlencode( $msg );
			$args['ok']  = $ok ? '1' : '0';
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Try a job again.
	 */
	public static function retry() {
		$id = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'aaryepro_retry_' . $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		AARYEPRO_Queue::retry( $id );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url() );
		exit;
	}

	/**
	 * "Sent to" card on an entry.
	 *
	 * @param object $e Entry.
	 */
	public static function entry_card( $e ) {
		$jobs   = AARYEPRO_Queue::for_entry( $e->id );
		$dests  = self::destinations();
		$labels = self::job_labels();
		?>
		<div class="aarye-card">
			<h2><?php esc_html_e( 'Sent to', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
			<?php if ( ! $jobs ) : ?>
				<p class="aarye-muted">
					<?php esc_html_e( 'Not sent anywhere.', 'aarye-marketing-addon-for-forms-pro' ); ?>
					<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Connections', 'aarye-marketing-addon-for-forms-pro' ); ?></a>
				</p>
			<?php else : ?>
				<table class="aarye-details aarye-details--small">
					<?php foreach ( $jobs as $job ) : ?>
						<tr>
							<th><?php echo esc_html( isset( $dests[ $job->dest ] ) ? $dests[ $job->dest ]->name() : $job->dest ); ?></th>
							<td>
								<span class="aaryepro-pill aaryepro-pill--<?php echo esc_attr( 'done' === $job->status ? 'ok' : ( 'failed' === $job->status ? 'bad' : 'off' ) ); ?>"><?php echo esc_html( isset( $labels[ $job->status ] ) ? $labels[ $job->status ] : $job->status ); ?></span>
								<span class="aarye-muted"><?php echo esc_html( (string) $job->last_message ); ?></span>
								<?php
								if ( 'failed' === $job->status ) {
									self::retry_button( $job );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
