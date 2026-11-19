<?php
/**
 * Sarala settings and their validation (PHP twin of web/lib/config.ts).
 *
 * Where each setting lives in Shopify:
 * - Fields and account page messages: shop metafield $app/account, read by the account blocks.
 * - Access rules: app installation metafield sarala/access, read by the theme app embed.
 * - Each customer's answers: customer metafield $app/f_<key>.
 */

const SARALA_PENDING = 'sarala-pending';
const SARALA_APPROVED = 'sarala-approved';
const SARALA_SUSPICIOUS = 'sarala-suspicious';
const SARALA_FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'dropdown', 'radio', 'checkboxes', 'yes_no'];
const SARALA_METAFIELD_TYPES = [
    'text' => 'single_line_text_field', 'textarea' => 'multi_line_text_field', 'number' => 'number_decimal',
    'date' => 'date', 'dropdown' => 'single_line_text_field', 'radio' => 'single_line_text_field',
    'checkboxes' => 'list.single_line_text_field', 'yes_no' => 'boolean',
];
const SARALA_PRICE_SELECTORS = '.price, .price__container, .product__price, [data-product-price], .card-information .price, product-price';
const SARALA_CART_SELECTORS = 'form[action*="/cart/add"], .product-form, product-form, .shopify-payment-button, [name="add"], .quick-add';

function s_default_account(): array
{
    return [
        'version' => 1, 'active' => false, 'fields' => [], 'fieldsTitle' => 'About you', 'fieldsIntro' => '',
        'pendingMessage' => 'Thank you for signing up. Your account is waiting for approval; we will let you know as soon as it is ready.',
        'announcements' => [],
    ];
}

function s_default_access(): array
{
    return [
        'version' => 1, 'active' => false, 'approval' => false, 'storeLock' => false,
        'lockedPages' => [], 'lockedCollections' => [], 'lockedProducts' => [], 'allowedTags' => [],
        'hidePrices' => false, 'lockMessage' => 'Please sign in to see this page.', 'lockButton' => 'Sign in',
        'prompt' => false, 'promptText' => "Sign in to see members' prices and offers.", 'promptButton' => 'Sign in',
        'redirects' => [], 'priceSelectors' => SARALA_PRICE_SELECTORS, 'cartSelectors' => SARALA_CART_SELECTORS,
    ];
}

function s_str($v, int $max): string
{
    if (!is_string($v)) return '';
    $v = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

function s_multiline($v, int $max): string
{
    if (!is_string($v)) return '';
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[\x00-\x09\x0b-\x1f\x7f]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

function s_tag($v): string
{
    return trim(preg_replace('/\s+/u', ' ', str_replace(',', ' ', s_str($v, 40))));
}

function s_handle($v): string
{
    $h = mb_strtolower(s_str($v, 255));
    if (preg_match('~/(?:pages|products|collections)/([^/?#]+)~u', $h, $m)) $h = $m[1];
    $h = preg_replace('/[^\p{L}\p{N}-]+/u', '-', $h) ?? '';
    return mb_substr(trim($h, '-'), 0, 255);
}

function s_key($v, string $fallback = 'field'): string
{
    $k = mb_strtolower(s_str($v, 60));
    if (class_exists('Normalizer')) $k = Normalizer::normalize($k, Normalizer::FORM_KD) ?: $k;
    $k = substr(trim(preg_replace('/[^a-z0-9]+/', '_', $k) ?? '', '_'), 0, 30);
    if (!preg_match('/^[a-z]/', $k)) $k = substr(rtrim($fallback . '_' . $k, '_'), 0, 30);
    return $k;
}

function s_url($v): string
{
    $u = s_str($v, 500);
    if (preg_match('~^/(?!/)\S*$~', $u)) return $u;
    $p = parse_url($u);
    return ($p && ($p['scheme'] ?? '') === 'https' && !empty($p['host']) && !preg_match('/\s/', $u)) ? $u : '';
}

function s_selectors($v, string $fallback): string
{
    $s = s_str($v, 1000);
    if ($s === '') return $fallback;
    $ok = preg_match('/^[\w\s.#\-\[\]="\'*:,>()~+^$|]+$/', $s) && !preg_match('~[{}<]|/\*~', $s);
    return $ok ? $s : $fallback;
}

function s_list($v, int $max, callable $each): array
{
    if (!is_array($v) || !array_is_list($v)) return [];
    $out = [];
    foreach (array_slice($v, 0, $max * 2) as $x) {
        $c = $each($x);
        if ($c !== null) $out[] = $c;
        if (count($out) >= $max) break;
    }
    return $out;
}

function s_lines($v, int $max, callable $clean): array
{
    $raw = is_array($v) ? $v : (is_string($v) ? preg_split('/[\n,]+/', $v) : []);
    $out = [];
    foreach ($raw as $x) {
        $c = $clean($x);
        if ($c !== '' && !in_array($c, $out, true)) $out[] = $c;
    }
    return array_slice($out, 0, $max);
}

function s_field($v, array &$used): ?array
{
    if (!is_array($v)) return null;
    $label = s_str($v['label'] ?? '', 80);
    if ($label === '') return null;
    $type = in_array($v['type'] ?? '', SARALA_FIELD_TYPES, true) ? $v['type'] : 'text';
    $key = s_key(!empty($v['key']) ? $v['key'] : $label);
    $base = substr($key, 0, 27);
    $n = 2;
    while (isset($used[$key])) $key = $base . '_' . $n++;
    $used[$key] = true;
    $needs = in_array($type, ['dropdown', 'radio', 'checkboxes'], true);
    $options = $needs ? s_lines($v['options'] ?? [], 50, fn($x) => s_str($x, 100)) : [];
    $allowed = $type === 'yes_no' ? ['yes'] : $options;
    $rules = s_list($v['tagRules'] ?? [], 20, function ($r) use ($allowed) {
        if (!is_array($r)) return null;
        $answer = s_str($r['answer'] ?? '', 100);
        $tag = s_tag($r['tag'] ?? '');
        return ($tag !== '' && in_array($answer, $allowed, true)) ? ['answer' => $answer, 'tag' => $tag] : null;
    });
    if ($needs && !$options) return null;
    return ['key' => $key, 'label' => $label, 'type' => $type, 'required' => !empty($v['required']),
        'help' => s_str($v['help'] ?? '', 200), 'options' => $options, 'tagRules' => $rules];
}

function s_clean_account($v): array
{
    $d = s_default_account();
    if (!is_array($v)) return $d;
    $used = [];
    return [
        'version' => 1,
        'active' => !empty($v['active']),
        'fields' => s_list($v['fields'] ?? [], 25, function ($f) use (&$used) { return s_field($f, $used); }),
        'fieldsTitle' => s_str($v['fieldsTitle'] ?? '', 80) ?: $d['fieldsTitle'],
        'fieldsIntro' => s_multiline($v['fieldsIntro'] ?? '', 300),
        'pendingMessage' => s_multiline($v['pendingMessage'] ?? '', 400) ?: $d['pendingMessage'],
        'announcements' => s_list($v['announcements'] ?? [], 10, function ($a) {
            if (!is_array($a)) return null;
            $text = s_multiline($a['text'] ?? '', 600);
            $title = s_str($a['title'] ?? '', 80);
            return ($text === '' && $title === '') ? null : ['tag' => s_tag($a['tag'] ?? ''), 'title' => $title, 'text' => $text];
        }),
    ];
}

function s_clean_access($v): array
{
    $d = s_default_access();
    if (!is_array($v)) return $d;
    return [
        'version' => 1,
        'active' => !empty($v['active']),
        'approval' => !empty($v['approval']),
        'storeLock' => !empty($v['storeLock']),
        'lockedPages' => s_lines($v['lockedPages'] ?? [], 100, 's_handle'),
        'lockedCollections' => s_lines($v['lockedCollections'] ?? [], 100, 's_handle'),
        'lockedProducts' => s_lines($v['lockedProducts'] ?? [], 200, 's_handle'),
        'allowedTags' => s_lines($v['allowedTags'] ?? [], 20, 's_tag'),
        'hidePrices' => !empty($v['hidePrices']),
        'lockMessage' => s_multiline($v['lockMessage'] ?? '', 300) ?: $d['lockMessage'],
        'lockButton' => s_str($v['lockButton'] ?? '', 40) ?: $d['lockButton'],
        'prompt' => !empty($v['prompt']),
        'promptText' => s_str($v['promptText'] ?? '', 200) ?: $d['promptText'],
        'promptButton' => s_str($v['promptButton'] ?? '', 40) ?: $d['promptButton'],
        'redirects' => s_list($v['redirects'] ?? [], 10, function ($r) {
            if (!is_array($r)) return null;
            $url = s_url($r['url'] ?? '');
            return $url === '' ? null : ['tag' => s_tag($r['tag'] ?? ''), 'url' => $url];
        }),
        'priceSelectors' => s_selectors($v['priceSelectors'] ?? '', $d['priceSelectors']),
        'cartSelectors' => s_selectors($v['cartSelectors'] ?? '', $d['cartSelectors']),
    ];
}

function s_answer_key(array $field): string
{
    return 'f_' . $field['key'];
}

/** Tags earned by the saved answers, and every tag the field rules manage. */
function s_tags_from_answers(array $fields, array $answers): array
{
    $add = [];
    $managed = [];
    foreach ($fields as $f) {
        foreach ($f['tagRules'] as $rule) {
            $managed[$rule['tag']] = true;
            $v = $answers[s_answer_key($f)] ?? null;
            if ($v === null) continue;
            if ($f['type'] === 'checkboxes') {
                $list = json_decode($v, true);
                $hit = is_array($list) && in_array($rule['answer'], $list, true);
            } elseif ($f['type'] === 'yes_no') {
                $hit = $v === 'true' && $rule['answer'] === 'yes';
            } else {
                $hit = $v === $rule['answer'];
            }
            if ($hit) $add[$rule['tag']] = true;
        }
    }
    return ['add' => array_keys($add), 'managed' => array_keys($managed)];
}
