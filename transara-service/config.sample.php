<?php
/**
 * Transara service settings. Copy this file to config.php and fill it in.
 * config.php is never served to visitors (see .htaccess).
 */
return array(
	// MySQL database (Hostinger: Databases > Management). Use 127.0.0.1 or localhost from the app.
	'db_host'        => 'localhost',
	'db_name'        => 'u000000000_transara',
	'db_user'        => 'u000000000_transara',
	'db_pass'        => 'change-me',

	// The address of this service, without a slash at the end.
	'base_url'       => 'https://transara.laghavi.site',

	// A long random secret (64+ characters). Encrypts stored license keys and signs links. Never change it later.
	'app_secret'     => 'paste-a-long-random-string-here',

	// Your login for the owner dashboard (/admin). Make the hash with: php -r "echo password_hash('your password', PASSWORD_DEFAULT);"
	'admin_user'     => 'nayan',
	'admin_hash'     => '',

	// License check on your store (Laghavi License Manager) and the product slug of Transara Pro.
	'license_api'    => 'https://laghavi.site/wp-json/laghavi-licenses/v1/',
	'license_slug'   => 'transara-pro',

	// Emails: sender address on your domain (Hostinger: Emails > create the mailbox first).
	'mail_from'      => 'alerts@laghavi.site',
	'mail_from_name' => 'Transara Alerts',

	// Optional: Telegram alerts. Create a bot with @BotFather and paste its token. Customers add the bot and enter their chat ID.
	'telegram_token' => '',

	// Optional: SMS and WhatsApp alerts through your Twilio account.
	'twilio_sid'     => '',
	'twilio_token'   => '',
	'twilio_from'    => '',      // SMS sender number, e.g. +15005550006
	'twilio_wa_from' => '',      // WhatsApp sender, e.g. +14155238886
	'sms_per_month'  => 50,      // Most SMS + WhatsApp messages per site per month.

	// Name of the region this server checks from (shown in results).
	'region'         => 'Europe',

	// Optional: extra check servers in other regions. name => key. Each runs worker.php with the same key.
	'workers'        => array(
		// 'United States' => 'another-long-random-key',
	),
	// With workers: a monitor is down only when this many regions see it failing.
	'regions_needed' => 2,

	// Limits per site.
	'max_monitors'   => 50,
	'min_interval'   => 60,      // Seconds between checks (60 = every minute).

	// Development only: let checks reach private addresses. NEVER true on the real server.
	'dev_allow_private' => false,
);
