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

try {
    $guest = zenoti_search_guest_by_phone($phone);
    // No agent identifier comes from CallGear at this stage, so every open
    // dashboard tab sees every call — whoever picks up sees the popup.
    store_save_event('shared', [
        'phone' => $phone,
        'guest' => $guest,
        'receivedAt' => round(microtime(true) * 1000),
    ]);
    respond(['matched' => $guest !== null]);
} catch (Exception $e) {
    respond(['error' => 'zenoti lookup failed', 'detail' => $e->getMessage()]);
}
