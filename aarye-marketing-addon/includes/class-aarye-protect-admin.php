<?php
/**
 * Screens for form protection, and the journey and blocking cards on an entry.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Protection admin.
 */
class AARYE_Protect_Admin {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_aarye_protect', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_aarye_block', array( __CLASS__, 'entry_block' ) );
		add_action( 'aarye_entry_cards', array( __CLASS__, 'journey_card' ), 5 );
		add_action( 'aarye_entry_cards', array( __CLASS__, 'block_card' ), 20 );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( AARYE_Admin::SLUG, __( 'Spam protection', 'aarye-marketing-addon' ), __( 'Spam protection', 'aarye-marketing-addon' ), 'manage_options', AARYE_Admin::SLUG . '-protect', array( __CLASS__, 'page' ) );
	}

	/**
	 * Name of a form ("plugin:id").
	 *
	 * @param string $key Form key.
	 * @return string
	 */
	private static function form_name( $key ) {
		foreach ( AARYE_Integrations::all_forms() as $form ) {
			if ( $form['plugin'] . ':' . $form['id'] === $key ) {
				return $form['title'] . ' (' . $form['plugin_name'] . ')';
			}
		}
		return $key;
	}

	/**
	 * Protection screen.
	 */
	public static function page() {
		$s      = AARYE_Settings::all();
		$forms  = AARYE_Integrations::all_forms();
		$chosen = (array) $s['question_forms'];
		$blocks = AARYE_Protect::blocks();
		$log    = AARYE_Protect::log_entries();
		$action = esc_url( admin_url( 'admin-post.php' ) );
		$ok     = array( 'elementor', 'cf7', 'wpforms', 'gravity', 'fluentform', 'formidable', 'forminator' );
		?>
		<div class="wrap aarye">
			<h1><?php esc_html_e( 'Spam protection', 'aarye-marketing-addon' ); ?></h1>
			<?php AARYE_Admin::show_flash(); ?>
			<p class="aarye-muted">
				<?php
				/* translators: %s: number of refused submissions. */
				echo esc_html( sprintf( _n( '%s submission refused so far.', '%s submissions refused so far.', (int) get_option( 'aarye_blocked_count', 0 ), 'aarye-marketing-addon' ), number_format_i18n( (int) get_option( 'aarye_blocked_count', 0 ) ) ) );
				?>
				<?php esc_html_e( 'Refused submissions never reach your inbox or your entries, and the rest of the website stays open to everyone.', 'aarye-marketing-addon' ); ?>
			</p>
			<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>" class="aarye-card">
				<input type="hidden" name="action" value="aarye_protect" />
				<input type="hidden" name="todo" value="settings" />
				<?php wp_nonce_field( 'aarye_protect' ); ?>
				<h2><?php esc_html_e( 'Security question before sending', 'aarye-marketing-addon' ); ?></h2>
				<p><?php esc_html_e( 'A short sum (for example "What is 7 + 4?") is shown right above the submit button, to everyone, you included. It is printed into the form by your website itself, so it shows even when other plugins delay or block scripts, and it keeps working with page caching. Scripts that post forms directly never get a question, so their messages are refused.', 'aarye-marketing-addon' ); ?></p>
				<p>
					<label><input type="radio" name="question" value="off" <?php checked( $s['question'], 'off' ); ?> /> <?php esc_html_e( 'Off', 'aarye-marketing-addon' ); ?></label><br />
					<label><input type="radio" name="question" value="all" <?php checked( $s['question'], 'all' ); ?> /> <?php esc_html_e( 'On every form', 'aarye-marketing-addon' ); ?></label><br />
					<label><input type="radio" name="question" value="chosen" <?php checked( $s['question'], 'chosen' ); ?> /> <?php esc_html_e( 'Only on the forms I choose:', 'aarye-marketing-addon' ); ?></label>
				</p>
				<div class="aarye-form-list">
					<?php if ( ! $forms ) : ?>
						<p class="aarye-muted"><?php esc_html_e( 'No forms found yet.', 'aarye-marketing-addon' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $forms as $form ) : ?>
						<?php $key = $form['plugin'] . ':' . $form['id']; ?>
						<label class="aarye-form-choice"><input type="checkbox" name="question_forms[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $chosen, true ) ); ?> <?php disabled( ! in_array( $form['plugin'], $ok, true ) ); ?> /> <?php echo esc_html( $form['title'] ); ?> <span class="aarye-muted">· <?php echo esc_html( $form['plugin_name'] ); ?><?php echo in_array( $form['plugin'], $ok, true ) ? '' : ' · ' . esc_html__( 'not supported', 'aarye-marketing-addon' ); ?></span></label>
					<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Works with Elementor Pro, Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Formidable Forms and Forminator.', 'aarye-marketing-addon' ); ?></p>
				<?php
				/**
				 * More protection settings (Aarye Pro adds reCAPTCHA here).
				 *
				 * @param array $s Settings.
				 */
				do_action( 'aarye_protect_settings', $s );
				?>
				<h2><?php esc_html_e( 'Automated browsers', 'aarye-marketing-addon' ); ?></h2>
				<p><label><input type="checkbox" name="block_automation" value="yes" <?php checked( $s['block_automation'], 'yes' ); ?> /> <?php esc_html_e( 'Refuse forms sent by browsers that report being controlled by automation software (Selenium, Puppeteer, Playwright)', 'aarye-marketing-addon' ); ?></label></p>
				<h2><?php esc_html_e( 'Your team', 'aarye-marketing-addon' ); ?></h2>
				<p><?php esc_html_e( 'Entries from your team are tests: they are kept under "Your team" and not counted as leads or as spam. Logged-in editors always count as team.', 'aarye-marketing-addon' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th><label for="aarye-team-domains"><?php esc_html_e( 'Team email domains', 'aarye-marketing-addon' ); ?></label></th><td><textarea id="aarye-team-domains" name="team_domains" rows="2" class="large-text code"><?php echo esc_textarea( is_array( $s['team_domains'] ) ? implode( "\n", $s['team_domains'] ) : (string) $s['team_domains'] ); ?></textarea><p class="description"><?php esc_html_e( 'One per line, for example example.com.', 'aarye-marketing-addon' ); ?></p></td></tr>
					<tr><th><label for="aarye-team-ips"><?php esc_html_e( 'Team IP addresses', 'aarye-marketing-addon' ); ?></label></th><td><textarea id="aarye-team-ips" name="team_ips" rows="2" class="large-text code"><?php echo esc_textarea( is_array( $s['team_ips'] ) ? implode( "\n", $s['team_ips'] ) : (string) $s['team_ips'] ); ?></textarea><p class="description"><?php esc_html_e( 'Your office connection, one per line (ranges like 192.0.2.0/24 work too).', 'aarye-marketing-addon' ); ?></p></td></tr>
				</table>
				<p><button class="button button-primary"><?php esc_html_e( 'Save', 'aarye-marketing-addon' ); ?></button></p>
			</form>

			<div class="aarye-card">
				<h2><?php esc_html_e( 'Blocked IP addresses', 'aarye-marketing-addon' ); ?></h2>
				<p><?php esc_html_e( 'These addresses cannot send the chosen form (or any form); they can still open every page. Open an entry to block its address with one click (needs "Save the visitor\'s IP address" in Settings).', 'aarye-marketing-addon' ); ?></p>
				<?php if ( $blocks ) : ?>
					<table class="widefat striped aarye-table">
						<thead><tr><th><?php esc_html_e( 'IP address', 'aarye-marketing-addon' ); ?></th><th><?php esc_html_e( 'Form', 'aarye-marketing-addon' ); ?></th><th><?php esc_html_e( 'Note', 'aarye-marketing-addon' ); ?></th><th><?php esc_html_e( 'Added', 'aarye-marketing-addon' ); ?></th><th></th></tr></thead>
						<tbody>
							<?php foreach ( $blocks as $i => $rule ) : ?>
								<tr>
									<td><code><?php echo esc_html( $rule['ip'] ); ?></code></td>
									<td><?php echo esc_html( '' === $rule['form'] ? __( 'All forms', 'aarye-marketing-addon' ) : ( '' !== $rule['form_name'] ? $rule['form_name'] : $rule['form'] ) ); ?></td>
									<td><?php echo esc_html( $rule['note'] ); ?></td>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ), $rule['added'] ) ); ?></td>
									<td>
										<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>">
											<input type="hidden" name="action" value="aarye_protect" />
											<input type="hidden" name="todo" value="unblock" />
											<input type="hidden" name="index" value="<?php echo esc_attr( (string) $i ); ?>" />
											<?php wp_nonce_field( 'aarye_protect' ); ?>
											<button class="button-link aarye-danger"><?php esc_html_e( 'Remove', 'aarye-marketing-addon' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>" class="aarye-inline-form">
					<input type="hidden" name="action" value="aarye_protect" />
					<input type="hidden" name="todo" value="block" />
					<?php wp_nonce_field( 'aarye_protect' ); ?>
					<input type="text" name="ip" placeholder="203.0.113.7 / 203.0.113.0/24 / 203.0.113.*" class="regular-text code" required />
					<select name="form">
						<option value=""><?php esc_html_e( 'All forms', 'aarye-marketing-addon' ); ?></option>
						<?php foreach ( $forms as $form ) : ?>
							<option value="<?php echo esc_attr( $form['plugin'] . ':' . $form['id'] ); ?>"><?php echo esc_html( $form['title'] . ' · ' . $form['plugin_name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" name="note" placeholder="<?php esc_attr_e( 'Note (optional)', 'aarye-marketing-addon' ); ?>" />
					<button class="button"><?php esc_html_e( 'Block', 'aarye-marketing-addon' ); ?></button>
				</form>
			</div>

			<div class="aarye-card">
				<h2><?php esc_html_e( 'Recently refused', 'aarye-marketing-addon' ); ?></h2>
				<?php if ( ! $log ) : ?>
					<p class="aarye-muted"><?php esc_html_e( 'Nothing refused yet.', 'aarye-marketing-addon' ); ?></p>
				<?php else : ?>
					<table class="widefat striped aarye-table">
						<thead><tr><th><?php esc_html_e( 'When', 'aarye-marketing-addon' ); ?></th><th><?php esc_html_e( 'Form', 'aarye-marketing-addon' ); ?></th><th><?php esc_html_e( 'Why', 'aarye-marketing-addon' ); ?></th><th><?php esc_html_e( 'IP address', 'aarye-marketing-addon' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( array_slice( $log, 0, 50 ) as $row ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $row['time'] ) ); ?></td>
									<td><?php echo esc_html( self::form_name( $row['form'] ) ); ?></td>
									<td><?php echo esc_html( $row['reason'] ); ?></td>
									<td><code><?php echo esc_html( $row['ip'] ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Save the protection screen.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'aarye_protect' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon' ) );
		}
		$todo = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';
		if ( 'block' === $todo ) {
			$ip   = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
			$form = isset( $_POST['form'] ) ? sanitize_text_field( wp_unslash( $_POST['form'] ) ) : '';
			$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
			if ( AARYE_Protect::add_block( $ip, $form, '' !== $form ? self::form_name( $form ) : '', $note ) ) {
				AARYE_Admin::flash( __( 'Blocked.', 'aarye-marketing-addon' ) );
			} else {
				AARYE_Admin::flash( __( 'That is not an IP address or range.', 'aarye-marketing-addon' ), 'error' );
			}
		} elseif ( 'unblock' === $todo ) {
			AARYE_Protect::remove_block( isset( $_POST['index'] ) ? absint( $_POST['index'] ) : -1 );
			AARYE_Admin::flash( __( 'Removed.', 'aarye-marketing-addon' ) );
		} else {
			$mode  = isset( $_POST['question'] ) ? sanitize_key( wp_unslash( $_POST['question'] ) ) : 'off';
			$forms = isset( $_POST['question_forms'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['question_forms'] ) ) : array();
			$clean = static function ( $key, $valid ) {
				$raw = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
				$out = array();
				foreach ( preg_split( '/[\r\n,]+/', $raw ) as $line ) {
					$line = strtolower( trim( $line ) );
					if ( '' !== $line && $valid( $line ) ) {
						$out[] = $line;
					}
				}
				return implode( "\n", array_unique( $out ) );
			};
			$values = array(
				'question'         => in_array( $mode, array( 'off', 'all', 'chosen' ), true ) ? $mode : 'off',
				'question_forms'   => array_values( array_filter( $forms, static function ( $f ) {
					return (bool) preg_match( '/^[a-z0-9_]+:[A-Za-z0-9_-]+$/', $f );
				} ) ),
				'block_automation' => ! empty( $_POST['block_automation'] ) ? 'yes' : 'no',
				'team_domains'     => $clean( 'team_domains', static function ( $d ) {
					return (bool) preg_match( '/^@?[a-z0-9.-]+\.[a-z]{2,}$/', $d );
				} ),
				'team_ips'         => $clean( 'team_ips', array( 'AARYE_Protect', 'valid_rule' ) ),
			);
			/**
			 * Protection settings before saving.
			 *
			 * @param array $values Values.
			 */
			AARYE_Settings::update( (array) apply_filters( 'aarye_save_protect_settings', $values ) );
			AARYE_Admin::flash( __( 'Settings saved.', 'aarye-marketing-addon' ) );
		}
		wp_safe_redirect( AARYE_Admin::url( 'protect' ) );
		exit;
	}

	/**
	 * Block an entry's IP address (on its form or on all forms), or mark it as the team's.
	 */
	public static function entry_block() {
		$id = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'aarye_block_' . $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon' ) );
		}
		$e    = AARYE_Entries::get( $id );
		$todo = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';
		if ( $e && '' !== $e->ip ) {
			if ( 'team' === $todo ) {
				$ips = trim( (string) AARYE_Settings::get( 'team_ips' ) );
				AARYE_Settings::update( array( 'team_ips' => trim( $ips . "\n" . $e->ip ) ) );
				AARYE_Entries::set_quality( $id, 'team' );
				AARYE_Admin::flash( __( 'This address now counts as your team.', 'aarye-marketing-addon' ) );
			} else {
				$form = 'form' === $todo ? $e->form_plugin . ':' . $e->form_id : '';
				/* translators: %d: entry number. */
				AARYE_Protect::add_block( $e->ip, $form, '' !== $form ? $e->form_name : '', sprintf( __( 'From entry #%d', 'aarye-marketing-addon' ), $id ) );
				AARYE_Entries::set_status( $id, 'spam' );
				AARYE_Admin::flash( __( 'Blocked. The entry was marked as spam.', 'aarye-marketing-addon' ) );
			}
		}
		wp_safe_redirect( AARYE_Admin::url( 'entries', array( 'entry' => $id ) ) );
		exit;
	}

	/**
	 * Short duration.
	 *
	 * @param int $s Seconds.
	 * @return string
	 */
	private static function duration( $s ) {
		if ( $s < 60 ) {
			/* translators: %d: seconds. */
			return sprintf( __( '%d s', 'aarye-marketing-addon' ), $s );
		}
		/* translators: 1: minutes, 2: seconds. */
		return sprintf( __( '%1$d min %2$d s', 'aarye-marketing-addon' ), intdiv( $s, 60 ), $s % 60 );
	}

	/**
	 * Where a visit came from, in words.
	 *
	 * @param string $from Tracker value.
	 * @return string
	 */
	private static function from_label( $from ) {
		if ( '-' === $from ) {
			return __( 'Direct (typed, bookmark or app)', 'aarye-marketing-addon' );
		}
		if ( 'gclid' === $from ) {
			return __( 'Google Ads click', 'aarye-marketing-addon' );
		}
		if ( 'fbclid' === $from ) {
			return __( 'Facebook / Instagram link', 'aarye-marketing-addon' );
		}
		if ( 0 === strpos( $from, 'utm:' ) ) {
			$parts = explode( '|', substr( $from, 4 ), 2 );
			/* translators: 1: utm_source, 2: ad name. */
			return isset( $parts[1] ) ? sprintf( __( 'Tagged link: %1$s · %2$s', 'aarye-marketing-addon' ), $parts[0], $parts[1] ) : sprintf( /* translators: %s: utm_source. */ __( 'Tagged link: %s', 'aarye-marketing-addon' ), $parts[0] );
		}
		return $from;
	}

	/**
	 * The visitor's journey on an entry.
	 *
	 * @param object $e Entry.
	 */
	public static function journey_card( $e ) {
		$attr    = AARYE_Entries::json( $e, 'attribution' );
		$journey = isset( $attr['journey'] ) && is_array( $attr['journey'] ) ? $attr['journey'] : array();
		if ( ! empty( $attr['imported'] ) || ( class_exists( 'AARYEPRO_Journey' ) && AARYE_Settings::on( 'pro_journey' ) ) ) {
			return; // Aarye Pro shows the full history of pages instead.
		}
		$sent = ! empty( $attr['sent_at'] ) ? (int) $attr['sent_at'] : strtotime( $e->created_at . ' UTC' );
		?>
		<div class="aarye-card aarye-journey">
			<h2><?php esc_html_e( 'Customer journey', 'aarye-marketing-addon' ); ?></h2>
			<?php if ( ! $journey ) : ?>
				<p class="aarye-muted"><?php esc_html_e( 'No pages recorded: the entry was sent before Aarye 1.3 recorded journeys, the visitor did not allow cookies, or the form was sent without opening the website.', 'aarye-marketing-addon' ); ?></p>
			<?php else : ?>
				<ol class="aarye-steps">
					<?php
					$last = count( $journey ) - 1;
					foreach ( $journey as $i => $step ) :
						$seconds = (int) $step['seconds'];
						if ( $i === $last ) {
							$seconds = max( $seconds, $sent - (int) $step['time'] );
						} elseif ( ! $seconds && isset( $journey[ $i + 1 ] ) && $journey[ $i + 1 ]['time'] - $step['time'] < 1800 ) {
							$seconds = (int) $journey[ $i + 1 ]['time'] - (int) $step['time'];
						}
						if ( '' !== $step['from'] ) :
							?>
							<li class="aarye-step aarye-step--from"><span class="dashicons dashicons-migrate" aria-hidden="true"></span> <?php echo esc_html( self::from_label( $step['from'] ) ); ?> <span class="aarye-muted">· <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $step['time'] ) ); ?></span></li>
						<?php endif; ?>
						<li class="aarye-step"><code class="aarye-break"><?php echo esc_html( $step['path'] ); ?></code> <span class="aarye-step__time"><?php echo esc_html( self::duration( max( 0, $seconds ) ) ); ?></span></li>
					<?php endforeach; ?>
					<li class="aarye-step aarye-step--sent"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php echo esc_html( sprintf( /* translators: %s: form name. */ __( 'Sent the form "%s"', 'aarye-marketing-addon' ), $e->form_name ) ); ?></li>
				</ol>
				<p class="aarye-muted"><?php esc_html_e( 'Time on a page counts only while it was open on screen. The last 15 pages are kept.', 'aarye-marketing-addon' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Block buttons on an entry.
	 *
	 * @param object $e Entry.
	 */
	public static function block_card( $e ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rule = '' !== $e->ip ? AARYE_Protect::blocked_by( $e->ip, $e->form_plugin . ':' . $e->form_id ) : null;
		?>
		<div class="aarye-card">
			<h2><?php esc_html_e( 'Block this sender', 'aarye-marketing-addon' ); ?></h2>
			<?php if ( '' === $e->ip ) : ?>
				<p class="aarye-muted"><?php esc_html_e( 'No IP address was saved with this entry. Turn on "Save the visitor\'s IP address" in Settings to block senders from their entries.', 'aarye-marketing-addon' ); ?></p>
			<?php elseif ( $rule ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: IP address. */ __( '%s is blocked.', 'aarye-marketing-addon' ), $e->ip ) ); ?> <a href="<?php echo esc_url( AARYE_Admin::url( 'protect' ) ); ?>"><?php esc_html_e( 'Manage blocked addresses', 'aarye-marketing-addon' ); ?></a></p>
			<?php else : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: IP address. */ __( 'Stop %s from sending forms. They can still open your website.', 'aarye-marketing-addon' ), $e->ip ) ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="aarye_block" />
					<input type="hidden" name="entry" value="<?php echo esc_attr( (string) $e->id ); ?>" />
					<?php wp_nonce_field( 'aarye_block_' . $e->id ); ?>
					<button class="button" name="todo" value="form"><?php echo esc_html( sprintf( /* translators: %s: form name. */ __( 'Block on "%s"', 'aarye-marketing-addon' ), $e->form_name ) ); ?></button>
					<button class="button" name="todo" value="all"><?php esc_html_e( 'Block on all forms', 'aarye-marketing-addon' ); ?></button>
					<button class="button-link" name="todo" value="team"><?php esc_html_e( 'This is my team', 'aarye-marketing-addon' ); ?></button>
				</form>
				<p class="description"><?php esc_html_e( 'Mobile networks share addresses between many people: block single addresses, not whole ranges, unless you are sure.', 'aarye-marketing-addon' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
