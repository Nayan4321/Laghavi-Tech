<?php
/** The app's API, used by the admin pages (assets/app.js), the account block and Shopify's webhooks. */

const SARALA_VIEWS = ['pending' => SARALA_PENDING, 'suspicious' => SARALA_SUSPICIOUS, 'approved' => SARALA_APPROVED];

function api_store(SaralaShop $s): string
{
    return preg_replace('/\.myshopify\.com$/', '', $s->shop);
}

/** The plans, charged through Shopify's Billing API. */
const SARALA_PLANS = [
    'monthly' => ['name' => 'Sarala monthly', 'amount' => 9.99, 'interval' => 'EVERY_30_DAYS'],
    'yearly' => ['name' => 'Sarala yearly', 'amount' => 99.0, 'interval' => 'ANNUAL'],
];
const SARALA_TRIAL_DAYS = 15;

/** Every page needs an active plan (the 15-day trial counts). Without one, the app shows the plans. */
function api_boot(SaralaShop $s, ?array $body): array
{
    $inst = sd_installation($s);
    if ($inst['subscribed'] && !$inst['access']['active']) sd_set_active($s, true);
    return ['ok' => true, 'subscribed' => $inst['subscribed'], 'trialDays' => SARALA_TRIAL_DAYS];
}

/** Starts a plan: Shopify shows the merchant the charge to approve, then returns to the app. */
function api_subscribe(SaralaShop $s, ?array $body): array
{
    $plan = SARALA_PLANS[$body['plan'] ?? ''] ?? null;
    if (!$plan) return ['ok' => false, 'error' => 'Unknown plan.'];
    $shop = $s->gql('query SaralaPlan { shop { plan { partnerDevelopment } } }')['shop'];
    $handle = sarala_config()['app_handle'] ?? 'sarala-user-access';
    $d = $s->gql('mutation SaralaSubscribe($name: String!, $returnUrl: URL!, $trialDays: Int, $test: Boolean, $lineItems: [AppSubscriptionLineItemInput!]!) {
      appSubscriptionCreate(name: $name, returnUrl: $returnUrl, trialDays: $trialDays, test: $test, lineItems: $lineItems) {
        confirmationUrl userErrors { field message } } }', [
        'name' => $plan['name'],
        'returnUrl' => 'https://admin.shopify.com/store/' . api_store($s) . "/apps/$handle",
        'trialDays' => SARALA_TRIAL_DAYS,
        // Development stores are never charged.
        'test' => (bool) ($shop['plan']['partnerDevelopment'] ?? false),
        'lineItems' => [['plan' => ['appRecurringPricingDetails' => [
            'price' => ['amount' => $plan['amount'], 'currencyCode' => 'USD'],
            'interval' => $plan['interval'],
        ]]]],
    ]);
    sd_user_errors($d['appSubscriptionCreate']['userErrors']);
    return ['ok' => true, 'confirmationUrl' => $d['appSubscriptionCreate']['confirmationUrl']];
}

function api_home(SaralaShop $s, ?array $body): array
{
    $inst = sd_installation($s);
    $shop = sd_shop($s);
    $since = gmdate('Y-m-d', time() - 30 * 86400);
    return [
        'store' => api_store($s),
        'apiKey' => sarala_config()['api_key'],
        'fields' => count($shop['account']['fields']),
        'access' => $inst['access'],
        'stats' => [
            'signups' => sd_count_customers($s, "created_at:>='$since'"),
            'pending' => sd_count_customers($s, "tag:'" . SARALA_PENDING . "'"),
            'approved' => sd_count_customers($s, "tag:'" . SARALA_APPROVED . "'"),
            'suspicious' => sd_count_customers($s, "tag:'" . SARALA_SUSPICIOUS . "'"),
        ],
    ];
}

function api_fields(SaralaShop $s, ?array $body): array
{
    $shop = sd_shop($s);
    if ($body === null) return ['account' => $shop['account']];
    $before = [];
    foreach ($shop['account']['fields'] as $f) $before[$f['key']] = $f;
    // A field whose type changed gets a new key, so old answers of the other type stay readable.
    $incoming = array_map(function ($f) use ($before) {
        if (!is_array($f)) return $f;
        $old = !empty($f['key']) ? ($before[$f['key']] ?? null) : null;
        return ($old && $old['type'] !== ($f['type'] ?? '')) ? array_merge($f, ['key' => '']) : $f;
    }, is_array($body['fields'] ?? null) ? array_values($body['fields']) : []);
    $account = s_clean_account(array_merge($shop['account'], [
        'fields' => $incoming, 'fieldsTitle' => $body['fieldsTitle'] ?? '', 'fieldsIntro' => $body['fieldsIntro'] ?? '',
    ]));
    sd_save_account($s, $shop['id'], $account);
    return ['ok' => true, 'account' => $account];
}

function api_account(SaralaShop $s, ?array $body): array
{
    $shop = sd_shop($s);
    if ($body === null) return ['account' => $shop['account']];
    $account = s_clean_account(array_merge($shop['account'], [
        'pendingMessage' => $body['pendingMessage'] ?? '', 'announcements' => $body['announcements'] ?? [],
    ]));
    sd_save_account($s, $shop['id'], $account);
    return ['ok' => true, 'account' => $account];
}

function api_access(SaralaShop $s, ?array $body): array
{
    $inst = sd_installation($s);
    if ($body === null) return ['access' => $inst['access']];
    // "active" follows the plan, never the form.
    $access = s_clean_access(array_merge($body, ['active' => $inst['access']['active']]));
    sd_save_access($s, $inst['id'], $access);
    return ['ok' => true, 'access' => $access];
}

function api_customers(SaralaShop $s, ?array $body): array
{
    if ($body === null) {
        $view = $_GET['view'] ?? 'pending';
        if (!isset(SARALA_VIEWS[$view])) $view = 'pending';
        return ['view' => $view, 'store' => api_store($s), 'customers' => sd_list_customers($s, "tag:'" . SARALA_VIEWS[$view] . "'", 100)];
    }
    $id = (string) ($body['id'] ?? '');
    if (!preg_match('~^gid://shopify/Customer/\d+$~', $id)) return ['ok' => false, 'message' => 'Unknown customer.'];
    try {
        switch ($body['todo'] ?? '') {
            case 'approve':
                sd_change_tags($s, $id, [SARALA_APPROVED], [SARALA_PENDING]);
                return ['ok' => true, 'message' => "Approved. The customer can now see members' pages and prices."];
            case 'decline':
                sd_change_tags($s, $id, [], [SARALA_PENDING, SARALA_APPROVED]);
                sd_delete_customer($s, $id);
                return ['ok' => true, 'message' => 'Declined and removed.'];
            case 'keep':
                sd_change_tags($s, $id, [], [SARALA_SUSPICIOUS]);
                return ['ok' => true, 'message' => 'Marked as a real customer.'];
            case 'delete':
                sd_delete_customer($s, $id);
                return ['ok' => true, 'message' => 'Customer deleted.'];
        }
    } catch (SaralaError $e) {
        // Customers with orders cannot be deleted in Shopify.
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    return ['ok' => false, 'message' => 'Unknown action.'];
}

/**
 * Called by the account block right after a customer saved their answers. Adds the tags the answers
 * earn and removes the app's tags they no longer earn. Tags the merchant added by hand are kept.
 */
function api_account_saved(): array
{
    $claims = sarala_verify_jwt(sarala_bearer());
    $shop = sarala_shop_from_dest((string) ($claims['dest'] ?? ''));
    $sub = (string) ($claims['sub'] ?? '');
    $customerId = str_starts_with($sub, 'gid://shopify/Customer/') ? $sub : (ctype_digit($sub) ? "gid://shopify/Customer/$sub" : '');
    if ($customerId === '') return ['ok' => false];
    $s = new SaralaShop($shop);
    $account = sd_shop($s)['account'];
    if (!$account['active']) return ['ok' => true];
    $c = sd_customer_answers($s, $customerId);
    if (!$c) return ['ok' => false];
    $r = s_tags_from_answers($account['fields'], $c['answers']);
    $add = array_values(array_diff($r['add'], $c['tags']));
    $remove = array_values(array_filter($r['managed'], fn($t) => !in_array($t, $r['add'], true) && in_array($t, $c['tags'], true)));
    sd_change_tags($s, $customerId, $add, $remove);
    return ['ok' => true];
}

function api_webhook(string $topic, string $shop, array $payload): void
{
    $s = new SaralaShop($shop);
    switch ($topic) {
        case 'app/uninstalled':
        case 'shop/redact':
            sarala_forget($shop);
            return;
        case 'app/scopes_update':
            return;
        case 'app_subscriptions/update':
            $status = strtoupper((string) ($payload['app_subscription']['status'] ?? ''));
            if ($status !== '' && sarala_token($shop)) sd_set_active($s, $status === 'ACTIVE');
            return;
        case 'customers/create':
            if (!sarala_token($shop)) return;
            $inst = sd_installation($s);
            if (!$inst['access']['active']) return;
            $id = (string) ($payload['admin_graphql_api_id'] ?? '');
            if ($id === '') return;
            $tags = array_filter(array_map('trim', explode(',', (string) ($payload['tags'] ?? ''))));
            $add = [];
            if ($inst['access']['approval'] && !in_array(SARALA_PENDING, $tags, true)) $add[] = SARALA_PENDING;
            $check = sarala_check_signup($payload['email'] ?? null, $payload['first_name'] ?? null, $payload['last_name'] ?? null);
            if ($check['suspicious']) $add[] = SARALA_SUSPICIOUS;
            if ($add) sd_change_tags($s, $id, $add, []);
            return;
        case 'customers/redact':
            $cid = $payload['customer']['id'] ?? null;
            if ($cid && sarala_token($shop)) sd_delete_customer_answers($s, "gid://shopify/Customer/$cid");
            return;
        default:
            // customers/data_request: the app keeps no customer data outside the shop.
            return;
    }
}
