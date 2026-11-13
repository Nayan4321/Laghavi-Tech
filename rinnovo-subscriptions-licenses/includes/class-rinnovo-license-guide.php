<?php
/**
 * WooCommerce > License guide: how any product sold with a license key checks it against this
 * store, per kind of product, with code examples.
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * License guide screen.
 */
class RINNOVO_License_Guide {

	const PAGE = 'rinnovo-license-guide';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 56 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'License guide', 'rinnovo-subscriptions-licenses' ), __( 'License guide', 'rinnovo-subscriptions-licenses' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Styles.
	 *
	 * @param string $hook Admin page.
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::PAGE ) ) {
			wp_enqueue_style( 'rinnovo-license-guide', RINNOVO_URL . 'assets/css/license-guide.css', array(), rinnovo_asset_version( 'assets/css/license-guide.css' ) );
		}
	}

	/**
	 * A code block.
	 *
	 * @param string $code Code.
	 */
	private static function code( $code ) {
		echo '<pre class="rinnovo-guide__code"><code>' . esc_html( $code ) . '</code></pre>';
	}

	/**
	 * Kinds of products: title => array( what to send as instance, where the key goes, tips ).
	 *
	 * @return array
	 */
	private static function kinds() {
		return array(
			__( 'WordPress plugins and themes', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'The website address (site_url). The ready-made license file sends it for you.', 'rinnovo-subscriptions-licenses' ),
				__( 'In the product\'s License tab, choose WordPress plugin or theme and click "Download the license file". Add the file and the two lines at its top: customers get a license screen, a daily check and updates in Dashboard > Updates. Use YourClass::is_active() to switch paid features on or off.', 'rinnovo-subscriptions-licenses' ),
			),
			__( 'Desktop applications (Windows, macOS, Linux)', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'A computer ID: a hash of the Windows MachineGuid, the macOS hardware UUID or Linux /etc/machine-id, or a random ID your installer saves once. Hash it so you never send the raw ID.', 'rinnovo-subscriptions-licenses' ),
				__( 'Ask for the key on first start or in a "License" menu, activate it, save the key and status in the app\'s settings, and check once a day. Offer "Deactivate" so customers can move to a new computer, and allow a few days offline before limiting the app.', 'rinnovo-subscriptions-licenses' ),
			),
			__( 'Mobile applications (Android, iOS)', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'A random ID created on first start and kept in the app\'s storage (or Android\'s ANDROID_ID, iOS identifierForVendor).', 'rinnovo-subscriptions-licenses' ),
				__( 'Same flow as desktop apps. Before you unlock features inside a mobile app with a key bought on your website, read the App Store and Google Play rules: in many cases digital features sold for use inside the app must use their in-app purchases. Keys are usually fine for business apps, companion apps of a service people buy elsewhere, and apps distributed outside the stores.', 'rinnovo-subscriptions-licenses' ),
			),
			__( 'Browser extensions (Chrome, Edge, Firefox, Safari)', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'A random ID created when the extension is installed and kept in chrome.storage.local (or browser.storage.local).', 'rinnovo-subscriptions-licenses' ),
				__( 'Put a key field on the options page and call the store from the background script or service worker. Add your store\'s address to host_permissions in manifest.json. Check once a day with an alarm.', 'rinnovo-subscriptions-licenses' ),
			),
			__( 'SaaS and web applications', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'The customer\'s account ID in your app.', 'rinnovo-subscriptions-licenses' ),
				__( 'For apps where the customer types in a license key: call the store from your server, never from the browser, and check the key once a day. Selling access to a hosted app (plans with features and usage limits, sign-in with the shop account, devices, webhooks when a subscription changes) is what Rinnovo Media Archives Pro does.', 'rinnovo-subscriptions-licenses' ),
			),
			__( 'Developer products (SDKs, libraries, CLI tools, APIs)', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'For a CLI tool: the computer ID. For a library or SDK: the project, domain or app ID it is used in. For an API you host: the customer\'s account ID.', 'rinnovo-subscriptions-licenses' ),
				__( 'A CLI tool can ask for the key with a "login" or "activate" command and keep it in a config file. Code that runs on the customer\'s side can be edited, so for anything valuable, also check the key on your own server (for example, in your hosted API or update service).', 'rinnovo-subscriptions-licenses' ),
			),
			__( 'Games and game development products', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'For a game: a device ID (Unity\'s SystemInfo.deviceUniqueIdentifier, or a random ID saved on first start). For editor tools and assets for Unity, Unreal or Godot: the computer ID or the project name.', 'rinnovo-subscriptions-licenses' ),
				__( 'Check at start-up and cache the answer so the game also works offline for a while. Games sold on Steam, consoles or the mobile stores use those stores\' own licensing; keys from your shop suit games and tools you sell directly.', 'rinnovo-subscriptions-licenses' ),
			),
			__( 'Digital assets and creative products', 'rinnovo-subscriptions-licenses' ) => array(
				__( 'Usually nothing: fonts, templates, presets, brushes, stock media, 3D models and ebooks are files, and files can\'t call a store. Set "Activations per license" to 0.', 'rinnovo-subscriptions-licenses' ),
				__( 'The key is the customer\'s proof of license, and only customers with an active key can download the files (and new versions) from My Account. (Streaming and view-only access to media is Rinnovo Media Archives Pro.) Sell each license type (personal, commercial, extended) as its own product or variation. Plugins for creative apps (Photoshop, Illustrator, Figma, Blender, After Effects…) can run code, so they can check the key like a desktop application.', 'rinnovo-subscriptions-licenses' ),
			),
		);
	}

	/**
	 * The screen.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$api = RINNOVO_License_Product::api_url();
		?>
		<div class="wrap rinnovo-guide">
			<h1><?php esc_html_e( 'License guide', 'rinnovo-subscriptions-licenses' ); ?></h1>
			<p class="rinnovo-guide__lead"><?php esc_html_e( 'How the products you sell check their license keys against this store: WordPress plugins and themes, desktop and mobile apps, browser extensions, SaaS, developer tools, games and digital assets.', 'rinnovo-subscriptions-licenses' ); ?></p>

			<div class="rinnovo-guide__card">
				<h2><?php esc_html_e( '1. How it works', 'rinnovo-subscriptions-licenses' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'In the product\'s License tab, tick "Sell a license", choose what you sell and give it a Product ID (for example photo-editor-pro).', 'rinnovo-subscriptions-licenses' ); ?></li>
					<li><?php esc_html_e( 'Every paid order gets a license key. The customer sees it on the thank-you page, in the order email and in My Account > Licenses.', 'rinnovo-subscriptions-licenses' ); ?></li>
					<li><?php esc_html_e( 'The customer enters the key in your product. Your product sends the key, the Product ID and an ID of where it runs (a website, computer, device or account) to this store.', 'rinnovo-subscriptions-licenses' ); ?></li>
					<li><?php esc_html_e( 'The store answers whether the key is active. Each website, computer, device or account counts as one activation, up to "Activations per license". Customers free an activation themselves in My Account.', 'rinnovo-subscriptions-licenses' ); ?></li>
					<li><?php esc_html_e( 'Keys sold with a subscription stay active while it is paid. You can revoke, extend or reissue any key in WooCommerce > Licenses.', 'rinnovo-subscriptions-licenses' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'No password or secret goes into your product: the license key is the only credential, and wrong keys are limited to 30 an hour per address.', 'rinnovo-subscriptions-licenses' ); ?></p>
			</div>

			<div class="rinnovo-guide__card">
				<h2><?php esc_html_e( '2. Your store\'s license addresses', 'rinnovo-subscriptions-licenses' ); ?></h2>
				<?php
				self::code(
					'# ' . __( 'Use the key here (once, when the customer enters it)', 'rinnovo-subscriptions-licenses' ) . "\nPOST {$api}license/activate\n\n" .
					'# ' . __( 'Is the key still active? (once a day)', 'rinnovo-subscriptions-licenses' ) . "\nPOST {$api}license/check\n\n" .
					'# ' . __( 'Free this activation (moving to another computer)', 'rinnovo-subscriptions-licenses' ) . "\nPOST {$api}license/deactivate\n\n" .
					'# ' . __( 'Latest version, changelog and download link', 'rinnovo-subscriptions-licenses' ) . "\nGET  {$api}license/update\n\n" .
					'# ' . __( 'The file (or download link), for activated keys', 'rinnovo-subscriptions-licenses' ) . "\nGET  {$api}license/download"
				);
				?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Send', 'rinnovo-subscriptions-licenses' ); ?></th><th><?php esc_html_e( 'What it is', 'rinnovo-subscriptions-licenses' ); ?></th></tr></thead>
					<tbody>
						<tr><td><code>license_key</code></td><td><?php esc_html_e( 'The key the customer entered, e.g. ABCDE-FGHJK-LMNPQ-RSTUV.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
						<tr><td><code>slug</code></td><td><?php esc_html_e( 'The Product ID from the License tab, so a key only works for its own product.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
						<tr><td><code>instance</code></td><td><?php esc_html_e( 'Where it runs: a computer, device, account or project ID (letters, numbers, dots, dashes; up to 191 characters).', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
						<tr><td><code>site_url</code></td><td><?php esc_html_e( 'Instead of instance, for anything that runs on a website. http/https, "www." and a trailing slash don\'t count as different sites.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
					</tbody>
				</table>
				<h3><?php esc_html_e( 'Answers (JSON)', 'rinnovo-subscriptions-licenses' ); ?></h3>
				<?php
				self::code(
					'{"success":true,"status":"active","expires":"2027-01-31 00:00:00","sites_allowed":3,"activations":1}' . "\n" .
					'{"success":false,"code":"limit","status":"limit","message":"This key is already used on 3 sites or devices. ..."}'
				);
				?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'status', 'rinnovo-subscriptions-licenses' ); ?></th><th><?php esc_html_e( 'Meaning and what your product should do', 'rinnovo-subscriptions-licenses' ); ?></th></tr></thead>
					<tbody>
						<tr><td><code>active</code></td><td><?php esc_html_e( 'Valid and activated here. Turn everything on.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
						<tr><td><code>inactive</code></td><td><?php esc_html_e( 'Valid, but not activated here (check only). Ask the customer to activate.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
						<tr><td><code>expired</code></td><td><?php esc_html_e( 'The license or its subscription ended. Show a renew link; decide whether the product keeps working without updates.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
						<tr><td><code>revoked</code></td><td><?php esc_html_e( 'You revoked it (refund, abuse). Turn paid features off.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
						<tr><td><code>invalid</code></td><td><?php esc_html_e( 'Unknown key, or a key for another product.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
						<tr><td><code>limit</code></td><td><?php esc_html_e( 'Activation limit reached. Show the message: it tells the customer how to free an activation.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
						<tr><td><code>too_many</code></td><td><?php esc_html_e( 'Too many wrong keys from this address; try again in an hour.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Every error answer has a "message" written for the customer: show it as it is.', 'rinnovo-subscriptions-licenses' ); ?></p>
			</div>

			<div class="rinnovo-guide__card">
				<h2><?php esc_html_e( '3. The recommended flow', 'rinnovo-subscriptions-licenses' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Customer enters the key: call activate. On success, save the key, the status and the date.', 'rinnovo-subscriptions-licenses' ); ?></li>
					<li><?php esc_html_e( 'Once a day: call check and save the new status. If the store can\'t be reached, keep the last status (allow for example 7 days offline).', 'rinnovo-subscriptions-licenses' ); ?></li>
					<li><?php esc_html_e( 'Customer moves to a new computer or site: call deactivate on the old one (or they remove it in My Account), then activate on the new one.', 'rinnovo-subscriptions-licenses' ); ?></li>
					<li><?php esc_html_e( 'Updates: call update, compare "version" with yours, and offer the "package" link when it is newer.', 'rinnovo-subscriptions-licenses' ); ?></li>
				</ol>
			</div>

			<div class="rinnovo-guide__card">
				<h2><?php esc_html_e( '4. By kind of product', 'rinnovo-subscriptions-licenses' ); ?></h2>
				<?php foreach ( self::kinds() as $title => $kind ) : ?>
					<h3><?php echo esc_html( $title ); ?></h3>
					<p><strong><?php esc_html_e( 'instance:', 'rinnovo-subscriptions-licenses' ); ?></strong> <?php echo esc_html( $kind[0] ); ?></p>
					<p><?php echo esc_html( $kind[1] ); ?></p>
				<?php endforeach; ?>
			</div>

			<div class="rinnovo-guide__card">
				<h2><?php esc_html_e( '5. Code examples', 'rinnovo-subscriptions-licenses' ); ?></h2>
				<p><?php esc_html_e( 'Replace photo-editor-pro with your Product ID. Each example activates a key; call check and deactivate the same way.', 'rinnovo-subscriptions-licenses' ); ?></p>

				<h3><?php esc_html_e( 'Command line (cURL)', 'rinnovo-subscriptions-licenses' ); ?></h3>
				<?php self::code( "curl -X POST {$api}license/activate \\\n  -d license_key=ABCDE-FGHJK-LMNPQ-RSTUV \\\n  -d slug=photo-editor-pro \\\n  -d instance=LAPTOP-01" ); ?>

				<h3><?php esc_html_e( 'JavaScript: browser extensions, Electron, Node.js, web app servers', 'rinnovo-subscriptions-licenses' ); ?></h3>
				<?php
				self::code(
					"async function activateLicense(key, instance) {\n" .
					"  const res = await fetch('{$api}license/activate', {\n" .
					"    method: 'POST',\n" .
					"    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },\n" .
					"    body: new URLSearchParams({ license_key: key, slug: 'photo-editor-pro', instance })\n" .
					"  });\n" .
					"  const data = await res.json();\n" .
					"  if (!data.success) throw new Error(data.message);\n" .
					"  return data; // { status: 'active', expires: '...' }\n" .
					"}\n\n" .
					"// Browser extension: a random ID kept for this install\n" .
					"const { instance = crypto.randomUUID() } = await chrome.storage.local.get('instance');\n" .
					"await chrome.storage.local.set({ instance });"
				);
				?>

				<h3><?php esc_html_e( 'Python: desktop apps, CLI tools, Blender add-ons', 'rinnovo-subscriptions-licenses' ); ?></h3>
				<?php
				self::code(
					"import hashlib, json, urllib.error, urllib.parse, urllib.request, uuid\n\n" .
					"API = '{$api}'\n" .
					"INSTANCE = hashlib.sha256(str(uuid.getnode()).encode()).hexdigest()[:32]  # this computer\n\n" .
					"def license_call(route, key):\n" .
					"    body = urllib.parse.urlencode({'license_key': key, 'slug': 'photo-editor-pro', 'instance': INSTANCE}).encode()\n" .
					"    try:\n" .
					"        with urllib.request.urlopen(API + 'license/' + route, data=body, timeout=15) as r:\n" .
					"            return json.load(r)\n" .
					"    except urllib.error.HTTPError as e:\n" .
					"        return json.load(e)  # error answers carry a message for the customer\n\n" .
					"result = license_call('activate', 'ABCDE-FGHJK-LMNPQ-RSTUV')\n" .
					"print(result['status'] if result.get('success') else result['message'])"
				);
				?>

				<h3><?php esc_html_e( 'C#: Unity games and tools, Windows (.NET) applications', 'rinnovo-subscriptions-licenses' ); ?></h3>
				<?php
				self::code(
					"using System.Collections.Generic;\nusing System.Net.Http;\nusing System.Threading.Tasks;\n\n" .
					"public static class License\n{\n" .
					"    static readonly HttpClient Http = new HttpClient();\n" .
					"    const string Api = \"{$api}\";\n\n" .
					"    public static async Task<string> Activate(string key, string instance)\n    {\n" .
					"        var form = new FormUrlEncodedContent(new Dictionary<string, string> {\n" .
					"            { \"license_key\", key }, { \"slug\", \"photo-editor-pro\" }, { \"instance\", instance }\n" .
					"        });\n" .
					"        var response = await Http.PostAsync(Api + \"license/activate\", form);\n" .
					"        return await response.Content.ReadAsStringAsync(); // JSON: success, status, message\n" .
					"    }\n}\n\n" .
					"// Unity: var json = await License.Activate(key, SystemInfo.deviceUniqueIdentifier);"
				);
				?>

				<h3><?php esc_html_e( 'PHP: web applications (on your server)', 'rinnovo-subscriptions-licenses' ); ?></h3>
				<?php
				self::code(
					"\$ch = curl_init('{$api}license/check');\n" .
					"curl_setopt_array(\$ch, [\n" .
					"    CURLOPT_POST => true,\n" .
					"    CURLOPT_RETURNTRANSFER => true,\n" .
					"    CURLOPT_POSTFIELDS => http_build_query([\n" .
					"        'license_key' => \$account->license_key,\n" .
					"        'slug'        => 'photo-editor-pro',\n" .
					"        'instance'    => 'account-' . \$account->id,\n" .
					"    ]),\n" .
					"]);\n" .
					"\$data = json_decode(curl_exec(\$ch), true);\n" .
					"\$account->license_active = ('active' === (\$data['status'] ?? ''));"
				);
				?>
			</div>
		</div>
		<?php
	}
}
