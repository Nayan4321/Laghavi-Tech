<?php
// Checks the PHP twins of web/lib/config.ts and signup-check.ts, and the token checks. Run: php tests/php-tests.php
require __DIR__ . '/../php/lib/settings.php';
require __DIR__ . '/../php/lib/signup-check.php';
require __DIR__ . '/../php/lib/shopify.php';
function sarala_config(): array { return ['api_key' => 'key123', 'api_secret' => 'secret456']; }

$fails = 0;
function eq($got, $want, string $what): void {
    global $fails;
    if ($got !== $want) { $fails++; echo "FAIL $what\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; }
}

eq(s_handle('Wholesale'), 'wholesale', 'handle');
eq(s_handle('https://shop.com/products/blue-shirt?variant=1'), 'blue-shirt', 'handle url');
eq(s_handle('/pages/vip-area'), 'vip-area', 'handle path');
eq(s_url('/collections/wholesale'), '/collections/wholesale', 'url path');
eq(s_url('//evil.com'), '', 'url protocol-relative');
eq(s_url('javascript:alert(1)'), '', 'url js');
eq(s_url('http://a.com'), '', 'url http');
eq(s_url('https://a.com/x'), 'https://a.com/x', 'url https');
eq(s_selectors('.price > span', 'x'), '.price > span', 'selectors ok');
eq(s_selectors('</style><script>', 'x'), 'x', 'selectors style');
eq(s_selectors('.a{color:red}', 'x'), 'x', 'selectors braces');

$c = s_clean_account(['fields' => [
    ['label' => 'Company', 'type' => 'text'], ['label' => 'Company', 'type' => 'text'], ['label' => '', 'type' => 'text'],
    ['label' => 'Size', 'type' => 'dropdown', 'options' => ['S', 'M', '', 'M']], ['label' => 'Bad', 'type' => 'script'],
]]);
eq(array_column($c['fields'], 'key'), ['company', 'company_2', 'size', 'bad'], 'unique keys');
eq($c['fields'][2]['options'], ['S', 'M'], 'options');
eq($c['fields'][3]['type'], 'text', 'unknown type -> text');
eq(s_clean_access(null)['lockedPages'], [], 'access default');
eq(s_clean_access(['lockedPages' => "wholesale\n/pages/vip, vip"])['lockedPages'], ['wholesale', 'vip'], 'locked lines');

$color = ['key' => 'color', 'type' => 'checkboxes', 'tagRules' => [['answer' => 'Red', 'tag' => 'Likes red']]];
$trade = ['key' => 'trade', 'type' => 'yes_no', 'tagRules' => [['answer' => 'yes', 'tag' => 'Wholesale']]];
$r = s_tags_from_answers([$color, $trade], ['f_color' => '["Red"]', 'f_trade' => 'false']);
eq($r['add'], ['Likes red'], 'tags add');
eq($r['managed'], ['Likes red', 'Wholesale'], 'tags managed');
eq(s_tags_from_answers([$trade], ['f_trade' => 'true'])['add'], ['Wholesale'], 'yes tag');

eq(sarala_check_signup('priya.sharma@gmail.com', 'Priya', 'Sharma')['suspicious'], false, 'normal');
eq(sarala_check_signup('john1985@outlook.com', 'John', 'Smith')['suspicious'], false, 'normal 2');
eq(sarala_check_signup('a@mailinator.com', null, null)['reasons'], ['Throwaway email service'], 'disposable');
eq(sarala_check_signup('x@sub.yopmail.com', null, null)['suspicious'], true, 'disposable sub');
eq(sarala_check_signup('user84736251@gmail.com', null, null)['suspicious'], true, 'numbers');
eq(sarala_check_signup('xkqzvtrwplmn@gmail.com', null, null)['suspicious'], true, 'random');
eq(sarala_check_signup('a@b.com', 'Win money www.spam.ru', null)['suspicious'], true, 'link');
eq(sarala_check_signup('a@b.com', 'Robert', 'robert')['suspicious'], true, 'same name');

// Session tokens.
function jwt(array $claims, string $secret = 'secret456'): string {
    $b = fn($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $h = $b(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $p = $b(json_encode($claims));
    return "$h.$p." . $b(hash_hmac('sha256', "$h.$p", $secret, true));
}
$good = ['dest' => 'https://demo.myshopify.com', 'aud' => 'key123', 'exp' => time() + 60, 'nbf' => time() - 5, 'sub' => '42'];
eq(sarala_shop_from_dest(sarala_verify_jwt(jwt($good))['dest']), 'demo.myshopify.com', 'jwt ok');
foreach ([
    'wrong secret' => jwt($good, 'nope'),
    'expired' => jwt(array_merge($good, ['exp' => time() - 60])),
    'wrong app' => jwt(array_merge($good, ['aud' => 'other'])),
    'garbage' => 'a.b',
] as $what => $t) {
    try { sarala_verify_jwt($t); eq('accepted', 'rejected', "jwt $what"); } catch (SaralaAuthError $e) { }
}
try { sarala_shop_from_dest('https://evil.com'); eq('accepted', 'rejected', 'dest evil'); } catch (SaralaAuthError $e) { }

echo $fails ? "$fails failed\n" : "PHP tests passed\n";
exit($fails ? 1 : 0);
