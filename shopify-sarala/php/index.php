<?php
/**
 * Sarala User Access - Shopify app server (PHP, runs on shared hosting).
 * Every request comes here through .htaccess.
 */
declare(strict_types=1);

require __DIR__ . '/lib/settings.php';
require __DIR__ . '/lib/signup-check.php';
require __DIR__ . '/lib/shopify.php';
require __DIR__ . '/lib/shop-data.php';
require __DIR__ . '/lib/api.php';

function sarala_config(): array
{
    static $c = null;
    if ($c === null) {
        $file = __DIR__ . '/private/config.php';
        $c = is_file($file) ? require $file : [];
    }
    return $c;
}

function sarala_json($data, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $h) header($h);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function sarala_body(): array
{
    $b = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($b) ? $b : [];
}

$path = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/' && empty($_GET['shop']) && empty($_GET['host'])) {
    require __DIR__ . '/lib/landing.php';
    exit;
}
$secret = (string) (sarala_config()['api_secret'] ?? '');
if ($secret === '' || str_starts_with($secret, 'PASTE')) {
    http_response_code(503);
    echo 'Sarala User Access is not set up yet: add the Client ID and secret to private/config.php.';
    exit;
}

try {
    // Called by the customer account block (another origin), with the customer's session token.
    if ($path === '/api/account/saved') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        if ($method === 'OPTIONS') { http_response_code(204); exit; }
        sarala_json(api_account_saved());
    }

    if ($path === '/webhooks' && $method === 'POST') {
        [$topic, $shop, $payload] = sarala_webhook();
        api_webhook($topic, $shop, $payload);
        http_response_code(200);
        exit;
    }

    if (str_starts_with($path, '/api/')) {
        $s = sarala_admin();
        $name = substr($path, 5);
        $handlers = [
            'boot' => 'api_boot', 'home' => 'api_home', 'fields' => 'api_fields', 'access' => 'api_access',
            'account' => 'api_account', 'customers' => 'api_customers',
        ];
        if (!isset($handlers[$name])) sarala_json(['error' => 'Not found'], 404);
        sarala_json($handlers[$name]($s, $method === 'POST' ? sarala_body() : null));
    }

    if ($path === '/' || $path === '/app' || str_starts_with($path, '/app/')) {
        require __DIR__ . '/lib/shell.php';
        exit;
    }
    http_response_code(404);
    echo 'Not found';
} catch (SaralaAuthError $e) {
    // App Bridge gets a fresh session token and sends the request again.
    sarala_json(['error' => 'Please reload the app.'], 401, ['X-Shopify-Retry-Invalid-Session-Request: 1']);
} catch (SaralaError $e) {
    sarala_json(['ok' => false, 'error' => $e->getMessage()], 200);
} catch (Throwable $e) {
    error_log('[sarala] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    sarala_json(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}
