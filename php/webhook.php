<?php
// CallGear's Interactive Call Processing feature calls this while a call is
// ringing and WAITS for a JSON reply telling it how to continue routing the
// call. Because of that, this always replies with HTTP 200 and a valid
// {"returned_code": ...} — never an error status — so a problem here (bad
// token, Zenoti down, no phone number) can never block or drop a real call.
// Extra debug fields (matched/guest/error) are included for our own testing;
// CallGear only reads "returned_code" and ignores the rest.
require __DIR__ . '/zenoti.php';
require __DIR__ . '/store.php';

header('Content-Type: application/json');

function respond($extra = []) {
    global $config;
    $code = $config['callgear_returned_code'] ?? 1;
    echo json_encode(array_merge(['returned_code' => $code], $extra));
    exit;
}

try {
    $config = zenoti_config();
} catch (Exception $e) {
    // Even a config problem must not break the phone call.
    $config = ['callgear_returned_code' => 1];
    respond(['error' => $e->getMessage()]);
}

$data = array_merge($_GET, $_POST);

// Some webhook senders post raw JSON instead of form fields.
$rawBody = file_get_contents('php://input');
if ($rawBody) {
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        $data = array_merge($data, $json);
    }
}

// Temporary diagnostic log: records every single webhook hit CallGear
// actually makes, with a precise timestamp, so we can see directly whether
// one real call triggers exactly one request (expected) or several with
// different &agent= values (would mean CallGear itself is firing multiple
// scenario branches for one call - not something fixable by adjusting the
// matching logic here). Protected by data/.htaccess like everything else
// in that folder. Safe to remove once the multi-popup issue is understood.
file_put_contents(
    __DIR__ . '/data/webhook_log.txt',
    date('Y-m-d H:i:s') . '.' . substr(microtime(), 2, 3) . ' ' . json_encode($data) . "\n",
    FILE_APPEND | LOCK_EX
);

if (!empty($config['webhook_token']) && ($data['token'] ?? null) !== $config['webhook_token']) {
    respond(['error' => 'invalid or missing token']);
}

// numa = caller's number, per CallGear's Interactive Call Processing docs.
$rawPhone = $data['numa'] ?? $data['phone'] ?? $data['caller_phone_number']
    ?? $data['contact_phone_number'] ?? $data['communication_number']
    ?? $data['from'] ?? $data['caller_number'] ?? null;
$phone = zenoti_normalize_phone($rawPhone);

if (!$phone) {
    respond(['error' => 'no phone number found in webhook payload', 'received' => $data]);
}

// Which employee this specific branch of the CallGear scenario is about to
// ring. Set per-branch in each Interactive Call Handling node's Authorization
// URL (e.g. "&agent=ryhem") - CallGear doesn't tell us this dynamically, but
// since each branch only rings one specific employee, we already know it
// from which URL was configured on that branch. Falls back to a shared feed
// (everyone sees it) if not set, e.g. for manual testing.
$agentId = $data['agent'] ?? 'shared';

try {
    $guest = zenoti_search_guest_by_phone($phone);
    store_save_event($agentId, [
        'phone' => $phone,
        'guest' => $guest,
        'receivedAt' => round(microtime(true) * 1000),
    ]);
    respond(['matched' => $guest !== null, 'guest' => $guest, 'agent' => $agentId]);
} catch (Exception $e) {
    respond(['error' => 'zenoti lookup failed', 'detail' => $e->getMessage()]);
}
