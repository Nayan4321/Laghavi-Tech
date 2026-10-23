<?php
/**
 * Automations and spam protection.
 *
 * Rules: "when the lead came from Meta Ads and the form is Quote → status Qualified, email sales@, send to
 * HubSpot". Spam checks: disposable emails, links, blocked words, emails, IPs and repeats. Both are checked
 * before the entry is saved, so spam never reaches your CRM or Google Sheet.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Automations.
 */
class AARYEPRO_Automations {

	const RULES = 'aaryepro_rules';
	const SPAM  = 'aaryepro_spam';
	const PAGE  = 'aarye-leads-automations';

	/**
	 * Rules matched by the entry being saved, run after it is saved.
	 *
	 * @var array
	 */
	private static $pending = array();

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'aarye_entry_before_save', array( __CLASS__, 'before_save' ), 10, 2 );
		add_action( 'aarye_entry_saved', array( __CLASS__, 'after_save' ), 5, 2 );
		add_action( 'aarye_admin_menu', array( __CLASS__, 'menu' ), 20, 2 );
		add_action( 'admin_post_aaryepro_rule', array( __CLASS__, 'save_rule' ) );
		add_action( 'admin_post_aaryepro_spam', array( __CLASS__, 'save_spam' ) );
		add_action( 'aarye_entry_cards', array( __CLASS__, 'entry_card' ), 30 );
	}

	// ------------------------------------------------------------------ Data.

	/**
	 * Saved rules.
	 *
	 * @return array[]
	 */
	public static function rules() {
		$rules = get_option( self::RULES, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Spam settings.
	 *
	 * @return array
	 */
	public static function spam() {
		$s = get_option( self::SPAM, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'disposable' => 'yes',
				'links'      => 3,
				'repeat'     => 5,
				'words'      => '',
				'emails'     => '',
				'ips'        => '',
				'bots'       => 'yes',
			)
		);
	}

	/**
	 * What a condition can look at.
	 *
	 * @return array<string, string>
	 */
	public static function subjects() {
		return array(
			'channel'   => __( 'Source type', 'aarye-marketing-addon-for-forms-pro' ),
			'source'    => __( 'Source / ad network', 'aarye-marketing-addon-for-forms-pro' ),
			'campaign'  => __( 'Campaign', 'aarye-marketing-addon-for-forms-pro' ),
			'ad_group'  => __( 'Ad set / ad group', 'aarye-marketing-addon-for-forms-pro' ),
			'ad'        => __( 'Ad', 'aarye-marketing-addon-for-forms-pro' ),
			'keyword'   => __( 'Keyword', 'aarye-marketing-addon-for-forms-pro' ),
			'form'      => __( 'Form name', 'aarye-marketing-addon-for-forms-pro' ),
			'language'  => __( 'Language', 'aarye-marketing-addon-for-forms-pro' ),
			'device'    => __( 'Device', 'aarye-marketing-addon-for-forms-pro' ),
			'email'     => __( 'Email', 'aarye-marketing-addon-for-forms-pro' ),
			'phone'     => __( 'Phone', 'aarye-marketing-addon-for-forms-pro' ),
			'page'      => __( 'Form page address', 'aarye-marketing-addon-for-forms-pro' ),
			'any_field' => __( 'Any form field', 'aarye-marketing-addon-for-forms-pro' ),
			'field'     => __( 'Form field named…', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * Operators.
	 *
	 * @return array<string, string>
	 */
	public static function operators() {
		return array(
			'is'           => __( 'is', 'aarye-marketing-addon-for-forms-pro' ),
			'is_not'       => __( 'is not', 'aarye-marketing-addon-for-forms-pro' ),
			'contains'     => __( 'contains', 'aarye-marketing-addon-for-forms-pro' ),
			'not_contains' => __( 'does not contain', 'aarye-marketing-addon-for-forms-pro' ),
			'empty'        => __( 'is empty', 'aarye-marketing-addon-for-forms-pro' ),
			'not_empty'    => __( 'is not empty', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * Values of a subject in an entry (a list for "any field").
	 *
	 * @param array  $row     Entry row.
	 * @param string $subject Subject.
	 * @param string $field   Field label for "field".
	 * @return string[]
	 */
	private static function values( $row, $subject, $field = '' ) {
		$fields = json_decode( isset( $row['fields'] ) ? (string) $row['fields'] : '[]', true );
		$fields = is_array( $fields ) ? $fields : array();
		switch ( $subject ) {
			case 'channel':
				return array( (string) $row['channel'], AARYE_Sources::channel_label( (string) $row['channel'] ) );
			case 'source':
				return array( (string) $row['source'], (string) $row['source_label'] );
			case 'form':
				return array( (string) $row['form_name'] );
			case 'page':
				return array( (string) $row['page_url'] );
			case 'any_field':
				return array_map( 'strval', wp_list_pluck( $fields, 'value' ) );
			case 'field':
				$want = mb_strtolower( trim( $field ) );
				foreach ( $fields as $f ) {
					if ( mb_strtolower( (string) $f['label'] ) === $want || mb_strtolower( (string) $f['name'] ) === $want ) {
						return array( (string) $f['value'] );
					}
				}
				return array( '' );
			default:
				return array( isset( $row[ $subject ] ) ? (string) $row[ $subject ] : '' );
		}
	}

	/**
	 * Whether a condition matches.
	 *
	 * @param array $row  Entry row.
	 * @param array $cond Condition: subject, field, op, value.
	 * @return bool
	 */
	private static function matches( $row, $cond ) {
		$values = self::values( $row, $cond['subject'], isset( $cond['field'] ) ? $cond['field'] : '' );
		$want   = mb_strtolower( trim( (string) $cond['value'] ) );
		$any    = static function ( $test ) use ( $values ) {
			foreach ( $values as $v ) {
				if ( $test( mb_strtolower( trim( $v ) ) ) ) {
					return true;
				}
			}
			return false;
		};
		$options = array_filter( array_map( 'trim', explode( ',', $want ) ), 'strlen' );
		switch ( $cond['op'] ) {
			case 'is':
				return $any(
					static function ( $v ) use ( $options ) {
						return in_array( $v, $options, true );
					}
				);
			case 'is_not':
				return ! $any(
					static function ( $v ) use ( $options ) {
						return in_array( $v, $options, true );
					}
				);
			case 'contains':
				return $any(
					static function ( $v ) use ( $options ) {
						foreach ( $options as $o ) {
							if ( false !== mb_strpos( $v, $o ) ) {
								return true;
							}
						}
						return false;
					}
				);
			case 'not_contains':
				return ! self::matches( $row, array_merge( $cond, array( 'op' => 'contains' ) ) );
			case 'empty':
				return ! $any( 'strlen' );
			case 'not_empty':
				return $any( 'strlen' );
		}
		return false;
	}

	// ------------------------------------------------------------------ Running.

	/**
	 * Spam check and rules, before the entry is saved.
	 *
	 * @param array $row  Entry row.
	 * @param array $args What the form integration passed.
	 * @return array
	 */
	public static function before_save( $row, $args ) {
		$attr    = json_decode( (string) $row['attribution'], true );
		$attr    = is_array( $attr ) ? $attr : array();
		$reason  = self::spam_reason( $row );
		$matched = array();
		if ( '' !== $reason ) {
			$row['status']       = 'spam';
			$attr['spam_reason'] = $reason;
		}
		foreach ( self::rules() as $rule ) {
			if ( empty( $rule['on'] ) || empty( $rule['when'] ) ) {
				continue;
			}
			$ok = true;
			foreach ( $rule['when'] as $cond ) {
				if ( ! self::matches( $row, $cond ) ) {
					$ok = false;
					break;
				}
			}
			if ( ! $ok ) {
				continue;
			}
			$matched[] = $rule;
			foreach ( $rule['then'] as $action ) {
				if ( 'status' === $action['do'] && isset( AARYE_Entries::statuses()[ $action['value'] ] ) ) {
					$row['status'] = $action['value'];
				}
			}
		}
		if ( $matched ) {
			$attr['rules'] = wp_list_pluck( $matched, 'name' );
		}
		$row['attribution'] = wp_json_encode( $attr );
		self::$pending      = $matched;
		return $row;
	}

	/**
	 * Email and send-to actions, after the entry is saved.
	 *
	 * @param int   $id  Entry ID.
	 * @param array $row Entry.
	 */
	public static function after_save( $id, $row ) {
		$matched       = self::$pending;
		self::$pending = array();
		if ( ! $matched ) {
			return;
		}
		$entry = AARYE_Entries::get( $id );
		if ( ! $entry ) {
			return;
		}
		$counts = (array) get_option( 'aaryepro_rule_counts', array() );
		foreach ( $matched as $rule ) {
			$counts[ $rule['id'] ] = array(
				'n'    => ( isset( $counts[ $rule['id'] ]['n'] ) ? (int) $counts[ $rule['id'] ]['n'] : 0 ) + 1,
				'last' => time(),
			);
			foreach ( $rule['then'] as $action ) {
				if ( 'email' === $action['do'] && '' !== $action['value'] ) {
					self::email( $entry, $action['value'], $rule['name'] );
				} elseif ( 'send' === $action['do'] ) {
					$dests = AARYEPRO_Connections::destinations();
					if ( isset( $dests[ $action['value'] ] ) && $dests[ $action['value'] ]->configured() ) {
						AARYEPRO_Queue::add( $id, $action['value'] );
					}
				}
			}
		}
		update_option( 'aaryepro_rule_counts', $counts, false );
	}

	/**
	 * Email about a lead.
	 *
	 * @param object $e    Entry.
	 * @param string $to   Addresses, comma separated.
	 * @param string $rule Rule name.
	 */
	private static function email( $e, $to, $rule ) {
		$to = array_filter( array_map( 'sanitize_email', explode( ',', $to ) ), 'is_email' );
		if ( ! $to ) {
			return;
		}
		/* translators: 1: source, 2: name or email. */
		$subject = sprintf( __( 'New lead from %1$s: %2$s', 'aarye-marketing-addon-for-forms-pro' ), $e->source_label, '' !== $e->name ? $e->name : ( '' !== $e->email ? $e->email : $e->form_name ) );
		$lines   = array(
			/* translators: %s: rule name. */
			sprintf( __( 'Automation: %s', 'aarye-marketing-addon-for-forms-pro' ), $rule ),
			'',
			/* translators: %s: form name. */
			sprintf( __( 'Form: %s', 'aarye-marketing-addon-for-forms-pro' ), $e->form_name ),
			/* translators: %s: source. */
			sprintf( __( 'Came from: %s', 'aarye-marketing-addon-for-forms-pro' ), implode( ' › ', array_filter( array( $e->source_label, $e->campaign, $e->ad_group, $e->ad ) ) ) ),
		);
		if ( '' !== $e->keyword ) {
			/* translators: %s: keyword. */
			$lines[] = sprintf( __( 'Keyword: %s', 'aarye-marketing-addon-for-forms-pro' ), $e->keyword );
		}
		$lines[] = '';
		$lines[] = AARYEPRO_Payload::tokens( '{all_fields}', $e );
		$lines[] = '';
		$lines[] = AARYEPRO_Payload::admin_link( $e );
		$headers = array();
		if ( '' !== $e->email ) {
			$headers[] = 'Reply-To: ' . ( '' !== $e->name ? str_replace( array( "\r", "\n", ',', '<', '>' ), '', $e->name ) . ' <' . $e->email . '>' : $e->email );
		}
		wp_mail( $to, wp_specialchars_decode( $subject ), implode( "\n", $lines ), $headers );
	}

	/**
	 * Why an entry is spam, or ''.
	 *
	 * @param array $row Entry row.
	 * @return string
	 */
	public static function spam_reason( $row ) {
		$s      = self::spam();
		$fields = json_decode( (string) $row['fields'], true );
		$text   = mb_strtolower( implode( "\n", array_map( 'strval', wp_list_pluck( is_array( $fields ) ? $fields : array(), 'value' ) ) ) );
		$email  = mb_strtolower( (string) $row['email'] );
		$domain = false !== strpos( $email, '@' ) ? substr( strrchr( $email, '@' ), 1 ) : '';

		if ( 'yes' === $s['bots'] && ! empty( $row['is_bot'] ) ) {
			return __( 'Sent by a bot (its browser said so).', 'aarye-marketing-addon-for-forms-pro' );
		}
		foreach ( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', mb_strtolower( (string) $s['emails'] ) ) ) ) as $blocked ) {
			if ( $email === $blocked || ( '' !== $domain && ( '@' . $domain === $blocked || $domain === ltrim( $blocked, '@' ) ) ) ) {
				/* translators: %s: email or domain. */
				return sprintf( __( 'Blocked email or domain: %s', 'aarye-marketing-addon-for-forms-pro' ), $blocked );
			}
		}
		if ( '' !== (string) $row['ip'] || '' !== $s['ips'] ) {
			$ip = '' !== (string) $row['ip'] ? (string) $row['ip'] : AARYE_Entries::ip();
			foreach ( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $s['ips'] ) ) ) as $blocked ) {
				if ( $ip === $blocked || ( '*' === substr( $blocked, -1 ) && 0 === strpos( $ip, rtrim( $blocked, '*' ) ) ) ) {
					/* translators: %s: IP address. */
					return sprintf( __( 'Blocked IP address: %s', 'aarye-marketing-addon-for-forms-pro' ), $ip );
				}
			}
		}
		if ( 'yes' === $s['disposable'] && '' !== $domain && in_array( $domain, self::disposable_domains(), true ) ) {
			/* translators: %s: email domain. */
			return sprintf( __( 'Disposable email address (%s).', 'aarye-marketing-addon-for-forms-pro' ), $domain );
		}
		foreach ( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', mb_strtolower( (string) $s['words'] ) ) ) ) as $word ) {
			if ( false !== mb_strpos( $text, $word ) ) {
				/* translators: %s: blocked word. */
				return sprintf( __( 'Contains a blocked word: %s', 'aarye-marketing-addon-for-forms-pro' ), $word );
			}
		}
		$links = (int) $s['links'];
		if ( $links > 0 && preg_match_all( '#https?://|www\.#i', $text ) >= $links ) {
			/* translators: %d: number of links. */
			return sprintf( __( 'Contains %d or more links.', 'aarye-marketing-addon-for-forms-pro' ), $links );
		}
		$repeat = (int) $s['repeat'];
		if ( $repeat > 0 && '' !== $email ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email = %s AND created_at > %s', AARYE_Entries::table(), $email, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
			if ( $n >= $repeat ) {
				/* translators: %d: number of entries. */
				return sprintf( __( 'Same email sent %d times within an hour.', 'aarye-marketing-addon-for-forms-pro' ), $n + 1 );
			}
		}
		/**
		 * Custom spam check.
		 *
		 * @param string $reason Reason, '' when not spam.
		 * @param array  $row    Entry row.
		 */
		return (string) apply_filters( 'aaryepro_spam_reason', '', $row );
	}

	/**
	 * Common throwaway email domains.
	 *
	 * @return string[]
	 */
	public static function disposable_domains() {
		return (array) apply_filters(
			'aaryepro_disposable_domains',
			array( 'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com', '10minutemail.net', 'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'yopmail.com', 'yopmail.net', 'trashmail.com', 'getnada.com', 'nada.email', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mintemail.com', 'mohmal.com', 'emailondeck.com', 'tempail.com', 'mailnesia.com', 'mytemp.email', 'tempr.email', 'discard.email', 'spamgourmet.com', 'mailcatch.com', 'burnermail.io', 'moakt.com', 'tmpmail.org', 'tmail.ws', 'inboxkitten.com', 'mailpoof.com', 'linshiyouxiang.net', 'emltmp.com', 'dropmail.me', '1secmail.com', '1secmail.org', '1secmail.net' )
		);
	}

	// ------------------------------------------------------------------ Screens.

	/**
	 * Submenu.
	 *
	 * @param string $parent Parent.
	 */
	public static function menu( $parent ) {
		add_submenu_page( $parent, __( 'Automations', 'aarye-marketing-addon-for-forms-pro' ), __( 'Automations', 'aarye-marketing-addon-for-forms-pro' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Actions a rule can take.
	 *
	 * @return array<string, string>
	 */
	public static function actions() {
		return array(
			'status' => __( 'Set lead status to', 'aarye-marketing-addon-for-forms-pro' ),
			'email'  => __( 'Email the lead to', 'aarye-marketing-addon-for-forms-pro' ),
			'send'   => __( 'Send it to', 'aarye-marketing-addon-for-forms-pro' ),
		);
	}

	/**
	 * The screen.
	 */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation and messages.
		$edit = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : '';
		$msg  = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:enable
		if ( '' !== $edit ) {
			self::page_rule( $edit );
			return;
		}
		$rules  = self::rules();
		$counts = (array) get_option( 'aaryepro_rule_counts', array() );
		$spam   = self::spam();
		?>
		<div class="wrap aarye">
			<h1><?php esc_html_e( 'Automations', 'aarye-marketing-addon-for-forms-pro' ); ?> <a class="page-title-action" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'rule' => 'new' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Add rule', 'aarye-marketing-addon-for-forms-pro' ); ?></a></h1>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<p class="aarye-muted"><?php esc_html_e( 'Rules run on every new entry, in this order. Example: when the source is Meta Ads and the form is "Quote", set the status to Qualified, email sales@yourcompany.com and send it to HubSpot.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
			<?php if ( ! $rules ) : ?>
				<div class="aarye-card"><p><?php esc_html_e( 'No rules yet.', 'aarye-marketing-addon-for-forms-pro' ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped aarye-table">
					<thead><tr>
						<th><?php esc_html_e( 'Rule', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'When', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Then', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
						<th><?php esc_html_e( 'Matched', 'aarye-marketing-addon-for-forms-pro' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $rules as $rule ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'rule' => $rule['id'] ), admin_url( 'admin.php' ) ) ); ?>"><b><?php echo esc_html( $rule['name'] ); ?></b></a><?php echo empty( $rule['on'] ) ? ' <span class="aaryepro-pill aaryepro-pill--off">' . esc_html__( 'Off', 'aarye-marketing-addon-for-forms-pro' ) . '</span>' : ''; ?></td>
								<td><?php echo esc_html( self::describe_when( $rule ) ); ?></td>
								<td><?php echo esc_html( self::describe_then( $rule ) ); ?></td>
								<td>
								<?php
								$c = isset( $counts[ $rule['id'] ] ) ? $counts[ $rule['id'] ] : null;
								echo esc_html( $c ? sprintf( /* translators: 1: count, 2: date. */ __( '%1$d · last %2$s', 'aarye-marketing-addon-for-forms-pro' ), $c['n'], wp_date( get_option( 'date_format' ), $c['last'] ) ) : '—' );
								?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Spam and bot protection', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
			<div class="aarye-grid aarye-grid--entry">
				<div class="aarye-card">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="aaryepro_spam" />
						<?php wp_nonce_field( 'aaryepro_spam' ); ?>
						<p class="aarye-muted"><?php esc_html_e( 'Entries caught here are saved with status Spam (so nothing is lost), but are not sent to your CRM, database or webhook, and not counted as leads.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
						<p><label><input type="checkbox" name="bots" value="yes" <?php checked( $spam['bots'], 'yes' ); ?> /> <?php esc_html_e( 'Mark entries from bots and scripts as spam', 'aarye-marketing-addon-for-forms-pro' ); ?></label></p>
						<p><label><input type="checkbox" name="disposable" value="yes" <?php checked( $spam['disposable'], 'yes' ); ?> /> <?php esc_html_e( 'Mark disposable email addresses (Mailinator, Yopmail, 10 Minute Mail…) as spam', 'aarye-marketing-addon-for-forms-pro' ); ?></label></p>
						<p><label><?php esc_html_e( 'Spam when the message has this many links or more (0 = off)', 'aarye-marketing-addon-for-forms-pro' ); ?> <input type="number" min="0" max="50" class="small-text" name="links" value="<?php echo esc_attr( (string) $spam['links'] ); ?>" /></label></p>
						<p><label><?php esc_html_e( 'Spam when the same email sends more than this many times in an hour (0 = off)', 'aarye-marketing-addon-for-forms-pro' ); ?> <input type="number" min="0" max="100" class="small-text" name="repeat" value="<?php echo esc_attr( (string) $spam['repeat'] ); ?>" /></label></p>
						<p><label><?php esc_html_e( 'Blocked words or phrases (one per line)', 'aarye-marketing-addon-for-forms-pro' ); ?><br /><textarea class="large-text" rows="3" name="words" placeholder="seo services&#10;crypto&#10;casino"><?php echo esc_textarea( $spam['words'] ); ?></textarea></label></p>
						<p><label><?php esc_html_e( 'Blocked emails or domains (one per line)', 'aarye-marketing-addon-for-forms-pro' ); ?><br /><textarea class="large-text" rows="2" name="emails" placeholder="spammer@example.com&#10;@spamdomain.com"><?php echo esc_textarea( $spam['emails'] ); ?></textarea></label></p>
						<p><label><?php esc_html_e( 'Blocked IP addresses (one per line, * at the end for a range)', 'aarye-marketing-addon-for-forms-pro' ); ?><br /><textarea class="large-text" rows="2" name="ips" placeholder="203.0.113.7&#10;198.51.100.*"><?php echo esc_textarea( $spam['ips'] ); ?></textarea></label></p>
						<p><button class="button button-primary"><?php esc_html_e( 'Save', 'aarye-marketing-addon-for-forms-pro' ); ?></button></p>
					</form>
				</div>
				<div class="aarye-card">
					<h2><?php esc_html_e( 'Latest spam and bot entries', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
					<?php self::spam_list(); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Recent spam and bots.
	 */
	private static function spam_list() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'spam' OR is_bot = 1 ORDER BY id DESC LIMIT 25", AARYE_Entries::table() ) );
		if ( ! $rows ) {
			echo '<p class="aarye-muted">' . esc_html__( 'None so far.', 'aarye-marketing-addon-for-forms-pro' ) . '</p>';
			return;
		}
		echo '<table class="aarye-details aarye-details--small">';
		foreach ( $rows as $e ) {
			$attr = AARYE_Entries::json( $e, 'attribution' );
			$why  = ! empty( $attr['spam_reason'] ) ? $attr['spam_reason'] : ( $e->is_bot ? __( 'Bot', 'aarye-marketing-addon-for-forms-pro' ) : __( 'Marked as spam', 'aarye-marketing-addon-for-forms-pro' ) );
			echo '<tr><th><a href="' . esc_url( AARYEPRO_Payload::admin_link( $e ) ) . '">' . esc_html( get_date_from_gmt( $e->created_at, 'M j, H:i' ) . ' · ' . ( '' !== $e->email ? $e->email : ( '' !== $e->name ? $e->name : '#' . $e->id ) ) ) . '</a></th><td>' . esc_html( $why ) . '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * "When" in words.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	private static function describe_when( $rule ) {
		$subjects = self::subjects();
		$ops      = self::operators();
		$parts    = array();
		foreach ( $rule['when'] as $c ) {
			$what    = 'field' === $c['subject'] ? '"' . $c['field'] . '"' : ( isset( $subjects[ $c['subject'] ] ) ? $subjects[ $c['subject'] ] : $c['subject'] );
			$parts[] = trim( $what . ' ' . ( isset( $ops[ $c['op'] ] ) ? $ops[ $c['op'] ] : $c['op'] ) . ( in_array( $c['op'], array( 'empty', 'not_empty' ), true ) ? '' : ' "' . $c['value'] . '"' ) );
		}
		return implode( ' ' . __( 'and', 'aarye-marketing-addon-for-forms-pro' ) . ' ', $parts );
	}

	/**
	 * "Then" in words.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	private static function describe_then( $rule ) {
		$acts     = self::actions();
		$statuses = AARYE_Entries::statuses();
		$dests    = AARYEPRO_Connections::destinations();
		$parts    = array();
		foreach ( $rule['then'] as $a ) {
			$value = $a['value'];
			if ( 'status' === $a['do'] && isset( $statuses[ $value ] ) ) {
				$value = $statuses[ $value ];
			} elseif ( 'send' === $a['do'] && isset( $dests[ $value ] ) ) {
				$value = $dests[ $value ]->name();
			}
			$parts[] = ( isset( $acts[ $a['do'] ] ) ? $acts[ $a['do'] ] : $a['do'] ) . ' ' . $value;
		}
		return implode( '; ', $parts );
	}

	/**
	 * Add or edit a rule.
	 *
	 * @param string $id Rule ID or "new".
	 */
	private static function page_rule( $id ) {
		$rule = array(
			'id'   => 'new',
			'name' => '',
			'on'   => true,
			'when' => array(),
			'then' => array(),
		);
		foreach ( self::rules() as $r ) {
			if ( $r['id'] === $id ) {
				$rule = $r;
			}
		}
		$subjects = self::subjects();
		$ops      = self::operators();
		$acts     = self::actions();
		?>
		<div class="wrap aarye">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">← <?php esc_html_e( 'Automations', 'aarye-marketing-addon-for-forms-pro' ); ?></a></p>
			<h1><?php echo 'new' === $rule['id'] ? esc_html__( 'Add rule', 'aarye-marketing-addon-for-forms-pro' ) : esc_html__( 'Edit rule', 'aarye-marketing-addon-for-forms-pro' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aarye-card aaryepro-rule">
				<input type="hidden" name="action" value="aaryepro_rule" />
				<input type="hidden" name="rule" value="<?php echo esc_attr( $rule['id'] ); ?>" />
				<?php wp_nonce_field( 'aaryepro_rule' ); ?>
				<p><label><b><?php esc_html_e( 'Name', 'aarye-marketing-addon-for-forms-pro' ); ?></b><br /><input type="text" class="regular-text" name="name" required value="<?php echo esc_attr( $rule['name'] ); ?>" placeholder="<?php esc_attr_e( 'Meta leads to sales', 'aarye-marketing-addon-for-forms-pro' ); ?>" /></label>
				<label class="aaryepro-inline"><input type="checkbox" name="on" value="1" <?php checked( ! empty( $rule['on'] ) ); ?> /> <?php esc_html_e( 'On', 'aarye-marketing-addon-for-forms-pro' ); ?></label></p>
				<h2><?php esc_html_e( 'When all of these are true', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
				<?php for ( $i = 0; $i < 5; $i++ ) : ?>
					<?php $c = isset( $rule['when'][ $i ] ) ? $rule['when'][ $i ] : array( 'subject' => '', 'field' => '', 'op' => 'is', 'value' => '' ); ?>
					<p class="aaryepro-row">
						<select name="when[<?php echo esc_attr( (string) $i ); ?>][subject]">
							<option value="">—</option>
							<?php foreach ( $subjects as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['subject'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="text" name="when[<?php echo esc_attr( (string) $i ); ?>][field]" value="<?php echo esc_attr( $c['field'] ); ?>" placeholder="<?php esc_attr_e( 'field label (for "Form field named…")', 'aarye-marketing-addon-for-forms-pro' ); ?>" />
						<select name="when[<?php echo esc_attr( (string) $i ); ?>][op]">
							<?php foreach ( $ops as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['op'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="text" name="when[<?php echo esc_attr( (string) $i ); ?>][value]" value="<?php echo esc_attr( $c['value'] ); ?>" placeholder="<?php esc_attr_e( 'value (several: comma separated)', 'aarye-marketing-addon-for-forms-pro' ); ?>" />
					</p>
				<?php endfor; ?>
				<p class="description"><?php esc_html_e( 'Values are not case sensitive. Source type values: paid, organic_search, organic_social, ai, email, referral, direct (or their names, such as "Paid ads"). Ad network values: meta_ads, google_ads, tiktok_ads… or their names, such as "Meta Ads".', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
				<h2><?php esc_html_e( 'Then', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
				<?php for ( $i = 0; $i < 3; $i++ ) : ?>
					<?php $a = isset( $rule['then'][ $i ] ) ? $rule['then'][ $i ] : array( 'do' => '', 'value' => '' ); ?>
					<p class="aaryepro-row">
						<select name="then[<?php echo esc_attr( (string) $i ); ?>][do]">
							<option value="">—</option>
							<?php foreach ( $acts as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $a['do'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
						<select name="then[<?php echo esc_attr( (string) $i ); ?>][status]">
							<option value=""><?php esc_html_e( '(status)', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
							<?php foreach ( AARYE_Entries::statuses() as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( 'status' === $a['do'] ? $a['value'] : '', $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
						<select name="then[<?php echo esc_attr( (string) $i ); ?>][dest]">
							<option value=""><?php esc_html_e( '(connection)', 'aarye-marketing-addon-for-forms-pro' ); ?></option>
							<?php foreach ( AARYEPRO_Connections::destinations() as $k => $d ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( 'send' === $a['do'] ? $a['value'] : '', $k ); ?>><?php echo esc_html( $d->name() ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="text" name="then[<?php echo esc_attr( (string) $i ); ?>][email]" value="<?php echo esc_attr( 'email' === $a['do'] ? $a['value'] : '' ); ?>" placeholder="<?php esc_attr_e( 'email addresses (comma separated)', 'aarye-marketing-addon-for-forms-pro' ); ?>" />
					</p>
				<?php endfor; ?>
				<p class="description"><?php esc_html_e( '"Send it to" works even when that connection does not send all new entries: switch "Send new entries" off there to send only the leads that match this rule.', 'aarye-marketing-addon-for-forms-pro' ); ?></p>
				<p>
					<button class="button button-primary" name="todo" value="save"><?php esc_html_e( 'Save rule', 'aarye-marketing-addon-for-forms-pro' ); ?></button>
					<?php if ( 'new' !== $rule['id'] ) : ?>
						<button class="button aarye-danger" name="todo" value="delete" data-confirm="<?php esc_attr_e( 'Delete this rule?', 'aarye-marketing-addon-for-forms-pro' ); ?>"><?php esc_html_e( 'Delete', 'aarye-marketing-addon-for-forms-pro' ); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save or delete a rule.
	 */
	public static function save_rule() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'aaryepro_rule' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		$id    = isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : 'new';
		$todo  = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : 'save';
		$rules = self::rules();
		if ( 'delete' === $todo ) {
			$rules = array_values(
				array_filter(
					$rules,
					static function ( $r ) use ( $id ) {
						return $r['id'] !== $id;
					}
				)
			);
			update_option( self::RULES, $rules, false );
			self::back( __( 'Rule deleted.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		$subjects = self::subjects();
		$ops      = self::operators();
		$when     = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned field by field below.
		foreach ( isset( $_POST['when'] ) && is_array( $_POST['when'] ) ? wp_unslash( $_POST['when'] ) : array() as $c ) {
			$subject = isset( $c['subject'] ) ? sanitize_key( $c['subject'] ) : '';
			if ( ! isset( $subjects[ $subject ] ) ) {
				continue;
			}
			$when[] = array(
				'subject' => $subject,
				'field'   => isset( $c['field'] ) ? sanitize_text_field( $c['field'] ) : '',
				'op'      => isset( $c['op'] ) && isset( $ops[ $c['op'] ] ) ? $c['op'] : 'is',
				'value'   => isset( $c['value'] ) ? sanitize_text_field( $c['value'] ) : '',
			);
		}
		$then = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
		foreach ( isset( $_POST['then'] ) && is_array( $_POST['then'] ) ? wp_unslash( $_POST['then'] ) : array() as $a ) {
			$do    = isset( $a['do'] ) ? sanitize_key( $a['do'] ) : '';
			$value = '';
			if ( 'status' === $do ) {
				$value = isset( $a['status'] ) && isset( AARYE_Entries::statuses()[ $a['status'] ] ) ? $a['status'] : '';
			} elseif ( 'send' === $do ) {
				$value = isset( $a['dest'] ) && isset( AARYEPRO_Connections::destinations()[ $a['dest'] ] ) ? $a['dest'] : '';
			} elseif ( 'email' === $do ) {
				$value = implode( ', ', array_filter( array_map( 'sanitize_email', explode( ',', isset( $a['email'] ) ? (string) $a['email'] : '' ) ), 'is_email' ) );
			}
			if ( '' !== $value ) {
				$then[] = array(
					'do'    => $do,
					'value' => $value,
				);
			}
		}
		$rule = array(
			'id'   => 'new' === $id ? 'r' . strtolower( wp_generate_password( 8, false, false ) ) : $id,
			'name' => isset( $_POST['name'] ) && '' !== trim( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : __( 'Rule', 'aarye-marketing-addon-for-forms-pro' ),
			'on'   => ! empty( $_POST['on'] ),
			'when' => $when,
			'then' => $then,
		);
		$found = false;
		foreach ( $rules as $i => $r ) {
			if ( $r['id'] === $rule['id'] ) {
				$rules[ $i ] = $rule;
				$found       = true;
			}
		}
		if ( ! $found ) {
			$rules[] = $rule;
		}
		update_option( self::RULES, array_values( $rules ), false );
		self::back( $when && $then ? __( 'Rule saved.', 'aarye-marketing-addon-for-forms-pro' ) : __( 'Rule saved, but it needs at least one condition and one action to do anything.', 'aarye-marketing-addon-for-forms-pro' ) );
	}

	/**
	 * Save spam settings.
	 */
	public static function save_spam() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'aaryepro_spam' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'aarye-marketing-addon-for-forms-pro' ) );
		}
		update_option(
			self::SPAM,
			array(
				'bots'       => isset( $_POST['bots'] ) ? 'yes' : 'no',
				'disposable' => isset( $_POST['disposable'] ) ? 'yes' : 'no',
				'links'      => isset( $_POST['links'] ) ? min( 50, absint( $_POST['links'] ) ) : 3,
				'repeat'     => isset( $_POST['repeat'] ) ? min( 100, absint( $_POST['repeat'] ) ) : 5,
				'words'      => isset( $_POST['words'] ) ? sanitize_textarea_field( wp_unslash( $_POST['words'] ) ) : '',
				'emails'     => isset( $_POST['emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['emails'] ) ) : '',
				'ips'        => isset( $_POST['ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ips'] ) ) : '',
			),
			false
		);
		self::back( __( 'Spam protection saved.', 'aarye-marketing-addon-for-forms-pro' ) );
	}

	/**
	 * Back to the screen.
	 *
	 * @param string $msg Message.
	 */
	private static function back( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Card on an entry: spam reason and rules that ran.
	 *
	 * @param object $e Entry.
	 */
	public static function entry_card( $e ) {
		$attr = AARYE_Entries::json( $e, 'attribution' );
		if ( empty( $attr['spam_reason'] ) && empty( $attr['rules'] ) ) {
			return;
		}
		?>
		<div class="aarye-card">
			<h2><?php esc_html_e( 'Automations', 'aarye-marketing-addon-for-forms-pro' ); ?></h2>
			<?php if ( ! empty( $attr['spam_reason'] ) ) : ?>
				<p><span class="aaryepro-pill aaryepro-pill--bad"><?php esc_html_e( 'Spam', 'aarye-marketing-addon-for-forms-pro' ); ?></span> <?php echo esc_html( $attr['spam_reason'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $attr['rules'] ) ) : ?>
				<p><?php esc_html_e( 'Rules that ran:', 'aarye-marketing-addon-for-forms-pro' ); ?> <?php echo esc_html( implode( ', ', (array) $attr['rules'] ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
