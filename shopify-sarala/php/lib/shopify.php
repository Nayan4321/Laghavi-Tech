<?php
/**
 * Shopify plumbing: session tokens, token exchange, the shop's access token, GraphQL and webhooks.
 * The app's database holds only one row per shop (its access token). Everything else lives in Shopify.
 */

const SARALA_API_VERSION = '2026-10';

class SaralaError extends RuntimeException {}
/** The session token is missing or invalid: App Bridge fetches a new one and retries. */
class SaralaAuthError extends RuntimeException {}

function sarala_db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = sarala_config()['db'];
    $pdo = new PDO("mysql:host={$c['host']};port=" . ($c['port'] ?? 3306) . ";dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS sarala_shops (
        shop VARCHAR(255) NOT NULL PRIMARY KEY,
        access_token TEXT NOT NULL,
        expires_at INT NULL,
        refresh_token TEXT NULL,
        refresh_expires_at INT NULL,
        scope TEXT NULL,
        updated_at INT NOT NULL
    ) DEFAULT CHARSET=utf8mb4');
    return $pdo;
}

function sarala_b64url_decode(string $s): string|false
{
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** Checks a Shopify session token (admin or customer account) and returns its claims. */
function sarala_verify_jwt(string $jwt): array
{
    $cfg = sarala_config();
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new SaralaAuthError('Bad token');
    [$h, $p, $sig] = $parts;
    $header = json_decode((string) sarala_b64url_decode($h), true);
    if (($header['alg'] ?? '') !== 'HS256') throw new SaralaAuthError('Bad token');
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", $cfg['api_secret'], true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $sig)) throw new SaralaAuthError('Bad signature');
    $claims = json_decode((string) sarala_b64url_decode($p), true);
    if (!is_array($claims)) throw new SaralaAuthError('Bad token');
    $now = time();
    if (($claims['exp'] ?? 0) < $now - 5 || ($claims['nbf'] ?? 0) > $now + 5) throw new SaralaAuthError('Expired token');
    $aud = $claims['aud'] ?? '';
    if (is_array($aud) ? !in_array($cfg['api_key'], $aud, true) : $aud !== $cfg['api_key']) throw new SaralaAuthError('Wrong app');
    return $claims;
}

function sarala_shop_from_dest(string $dest): string
{
    $host = strtolower(parse_url(str_contains($dest, '://') ? $dest : "https://$dest", PHP_URL_HOST) ?: '');
    if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $host)) throw new SaralaAuthError('Unknown shop');
    return $host;
}

function sarala_bearer(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $h = $v;
    }
    return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
}

function sarala_http(string $url, array $body, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new SaralaError('Could not reach Shopify: ' . $err);
    return [$status, json_decode($raw, true)];
}

function sarala_save_token(string $shop, array $t): void
{
    $now = time();
    sarala_db()->prepare('REPLACE INTO sarala_shops (shop, access_token, expires_at, refresh_token, refresh_expires_at, scope, updated_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([
            $shop, $t['access_token'],
            isset($t['expires_in']) ? $now + (int) $t['expires_in'] : null,
            $t['refresh_token'] ?? null,
            isset($t['refresh_token_expires_in']) ? $now + (int) $t['refresh_token_expires_in'] : null,
            $t['scope'] ?? null, $now,
        ]);
}

/** Swap a session token for an (expiring) offline access token. */
function sarala_exchange(string $shop, string $idToken): string
{
    $c = sarala_config();
    [$status, $res] = sarala_http("https://$shop/admin/oauth/access_token", [
        'client_id' => $c['api_key'],
        'client_secret' => $c['api_secret'],
        'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
        'subject_token' => $idToken,
        'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
        'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
        'expiring' => '1',
    ]);
    if ($status !== 200 || empty($res['access_token'])) throw new SaralaAuthError('Token exchange failed');
    sarala_save_token($shop, $res);
    return $res['access_token'];
}

function sarala_forget(string $shop): void
{
    sarala_db()->prepare('DELETE FROM sarala_shops WHERE shop = ?')->execute([$shop]);
}

/** The shop's access token, refreshed when it is about to expire. Null when the shop must open the app again. */
function sarala_token(string $shop): ?string
{
    $st = sarala_db()->prepare('SELECT * FROM sarala_shops WHERE shop = ?');
    $st->execute([$shop]);
    $row = $st->fetch();
    if (!$row) return null;
    if ($row['expires_at'] === null || (int) $row['expires_at'] > time() + 120) return $row['access_token'];
    if (!$row['refresh_token'] || ($row['refresh_expires_at'] && (int) $row['refresh_expires_at'] < time())) return null;
    $c = sarala_config();
    [$status, $res] = sarala_http("https://$shop/admin/oauth/access_token", [
        'client_id' => $c['api_key'],
        'client_secret' => $c['api_secret'],
        'grant_type' => 'refresh_token',
        'refresh_token' => $row['refresh_token'],
    ]);
    if ($status !== 200 || empty($res['access_token'])) return null;
    sarala_save_token($shop, $res);
    return $res['access_token'];
}

/** A shop the app can call: from an admin session token (embedded app) or from the database (webhooks). */
final class SaralaShop
{
    public function __construct(public string $shop, private ?string $idToken = null) {}

    public function gql(string $query, array $variables = []): array
    {
        for ($try = 0; $try < 2; $try++) {
            $token = $try === 0 ? sarala_token($this->shop) : null;
            if (!$token) {
                if (!$this->idToken) throw new SaralaError('The app has no access to this shop. Open the app in the Shopify admin once.');
                $token = sarala_exchange($this->shop, $this->idToken);
            }
            $body = ['query' => $query];
            if ($variables) $body['variables'] = $variables;
            [$status, $res] = sarala_http("https://{$this->shop}/admin/api/" . SARALA_API_VERSION . '/graphql.json', $body, ["X-Shopify-Access-Token: $token"]);
            if ($status === 401) {
                sarala_forget($this->shop);
                continue;
            }
            if (!empty($res['errors']) || !isset($res['data'])) {
                throw new SaralaError('Shopify returned an error: ' . substr(json_encode($res['errors'] ?? 'no data'), 0, 300));
            }
            return $res['data'];
        }
        throw new SaralaError('The app has no access to this shop. Open the app in the Shopify admin once.');
    }
}

/** The embedded app's request: verified session token -> shop. */
function sarala_admin(): SaralaShop
{
    $jwt = sarala_bearer();
    if ($jwt === '') throw new SaralaAuthError('No session token');
    $claims = sarala_verify_jwt($jwt);
    return new SaralaShop(sarala_shop_from_dest((string) ($claims['dest'] ?? '')), $jwt);
}

/** A webhook from Shopify: checks the signature, returns [topic, shop, payload]. */
function sarala_webhook(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $hmac = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
    $calc = base64_encode(hash_hmac('sha256', $raw, sarala_config()['api_secret'], true));
    if ($hmac === '' || !hash_equals($calc, $hmac)) throw new SaralaAuthError('Bad webhook signature');
    $shop = sarala_shop_from_dest($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '');
    $topic = strtolower($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '');
    return [$topic, $shop, json_decode($raw, true) ?: []];
}
