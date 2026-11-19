<?php
/** Everything the app reads and writes in the shop, through the Admin GraphQL API. */

function sd_user_errors(?array $errors, array $allow = []): void
{
    $real = array_filter($errors ?? [], fn($e) => !in_array($e['code'] ?? '', $allow, true));
    if ($real) throw new SaralaError(implode(' ', array_column($real, 'message')));
}

function sd_json_metafield(?array $mf, callable $clean): array
{
    $v = $mf ? json_decode($mf['value'], true) : null;
    return $clean(is_array($v) ? $v : null);
}

function sd_installation(SaralaShop $s): array
{
    $d = $s->gql('query SaralaInstallation {
      currentAppInstallation { id metafield(namespace: "sarala", key: "access") { value } activeSubscriptions { status } }
    }')['currentAppInstallation'];
    return [
        'id' => $d['id'],
        'access' => sd_json_metafield($d['metafield'], 's_clean_access'),
        'subscribed' => (bool) array_filter($d['activeSubscriptions'], fn($x) => $x['status'] === 'ACTIVE'),
    ];
}

function sd_set_metafield(SaralaShop $s, string $owner, string $ns, string $key, array $value): void
{
    $d = $s->gql('mutation SaralaSet($metafields: [MetafieldsSetInput!]!) { metafieldsSet(metafields: $metafields) { userErrors { message code } } }', [
        'metafields' => [['ownerId' => $owner, 'namespace' => $ns, 'key' => $key, 'type' => 'json',
            'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
    ]);
    sd_user_errors($d['metafieldsSet']['userErrors']);
}

function sd_save_access(SaralaShop $s, string $installationId, array $access): void
{
    sd_set_metafield($s, $installationId, 'sarala', 'access', $access);
}

function sd_shop(SaralaShop $s): array
{
    $d = $s->gql('query SaralaShop { shop { id name myshopifyDomain metafield(namespace: "$app", key: "account") { value } } }')['shop'];
    return ['id' => $d['id'], 'name' => $d['name'], 'account' => sd_json_metafield($d['metafield'], 's_clean_account')];
}

/** Definitions that let the account blocks read the settings and read/write each answer. */
function sd_sync_definitions(SaralaShop $s, array $fields): void
{
    $defs = [[
        'name' => 'Sarala account settings', 'namespace' => '$app', 'key' => 'account', 'type' => 'json', 'ownerType' => 'SHOP',
        'access' => ['admin' => 'MERCHANT_READ', 'customerAccount' => 'READ'],
    ]];
    foreach ($fields as $f) {
        $defs[] = [
            'name' => $f['label'], 'namespace' => '$app', 'key' => s_answer_key($f), 'type' => SARALA_METAFIELD_TYPES[$f['type']],
            'ownerType' => 'CUSTOMER', 'description' => 'Answer collected by Sarala User Access',
            'access' => ['admin' => 'MERCHANT_READ_WRITE', 'customerAccount' => 'READ_WRITE'],
        ];
    }
    foreach ($defs as $def) {
        $d = $s->gql('mutation SaralaDefinition($definition: MetafieldDefinitionInput!) {
          metafieldDefinitionCreate(definition: $definition) { createdDefinition { id } userErrors { message code } } }', ['definition' => $def]);
        sd_user_errors($d['metafieldDefinitionCreate']['userErrors'], ['TAKEN']);
    }
}

function sd_save_account(SaralaShop $s, string $shopId, array $account): void
{
    sd_sync_definitions($s, $account['fields']);
    sd_set_metafield($s, $shopId, '$app', 'account', $account);
}

/** Switch the storefront and account parts on or off (off when there is no active plan). */
function sd_set_active(SaralaShop $s, bool $active): void
{
    $inst = sd_installation($s);
    $shop = sd_shop($s);
    if ($inst['access']['active'] !== $active) sd_save_access($s, $inst['id'], array_merge($inst['access'], ['active' => $active]));
    if ($shop['account']['active'] !== $active) sd_save_account($s, $shop['id'], array_merge($shop['account'], ['active' => $active]));
}

function sd_list_customers(SaralaShop $s, string $query, int $first = 50): array
{
    $d = $s->gql('query SaralaCustomers($query: String!, $first: Int!) {
      customers(first: $first, query: $query, sortKey: CREATED_AT, reverse: true) {
        nodes { id displayName defaultEmailAddress { emailAddress } createdAt tags } } }', ['query' => $query, 'first' => $first]);
    return array_map(fn($c) => [
        'id' => $c['id'], 'name' => $c['displayName'], 'email' => $c['defaultEmailAddress']['emailAddress'] ?? '',
        'createdAt' => $c['createdAt'], 'tags' => $c['tags'],
    ], $d['customers']['nodes']);
}

function sd_count_customers(SaralaShop $s, string $query): int
{
    return (int) $s->gql('query SaralaCount($query: String!) { customersCount(query: $query) { count } }', ['query' => $query])['customersCount']['count'];
}

function sd_change_tags(SaralaShop $s, string $id, array $add, array $remove): void
{
    if ($remove) {
        $d = $s->gql('mutation SaralaTagsRemove($id: ID!, $tags: [String!]!) { tagsRemove(id: $id, tags: $tags) { userErrors { message } } }', ['id' => $id, 'tags' => array_values($remove)]);
        sd_user_errors($d['tagsRemove']['userErrors']);
    }
    if ($add) {
        $d = $s->gql('mutation SaralaTagsAdd($id: ID!, $tags: [String!]!) { tagsAdd(id: $id, tags: $tags) { userErrors { message } } }', ['id' => $id, 'tags' => array_values($add)]);
        sd_user_errors($d['tagsAdd']['userErrors']);
    }
}

function sd_delete_customer(SaralaShop $s, string $id): void
{
    $d = $s->gql('mutation SaralaDelete($id: ID!) { customerDelete(input: { id: $id }) { userErrors { message } } }', ['id' => $id]);
    sd_user_errors($d['customerDelete']['userErrors']);
}

/** A customer's tags and their Sarala answers ($app metafields). */
function sd_customer_answers(SaralaShop $s, string $id): ?array
{
    $d = $s->gql('query SaralaCustomer($id: ID!) { customer(id: $id) { tags metafields(first: 50, namespace: "$app") { nodes { key value } } } }', ['id' => $id]);
    if (!$d['customer']) return null;
    $answers = [];
    foreach ($d['customer']['metafields']['nodes'] as $m) $answers[$m['key']] = $m['value'];
    return ['tags' => $d['customer']['tags'], 'answers' => $answers];
}

function sd_delete_customer_answers(SaralaShop $s, string $id): void
{
    $c = sd_customer_answers($s, $id);
    if (!$c || !$c['answers']) return;
    $ids = array_map(fn($k) => ['ownerId' => $id, 'namespace' => '$app', 'key' => $k], array_keys($c['answers']));
    $s->gql('mutation SaralaRedact($metafields: [MetafieldIdentifierInput!]!) { metafieldsDelete(metafields: $metafields) { userErrors { message } } }', ['metafields' => $ids]);
}
