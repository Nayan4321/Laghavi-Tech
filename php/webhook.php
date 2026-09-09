<?php
// CallGear calls this on every incoming call (configure as GET or POST).
require __DIR__ . '/zenoti.php';
require __DIR__ . '/store.php';

header('Content-Type: application/json');

try {
    $config = zenoti_config();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
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

if (!empty($config['webhook_token'])) {
    if (($data['token'] ?? null) !== $config['webhook_token']) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid or missing token']);
        exit;
    }
}

$rawPhone = $data['phone'] ?? $data['caller_phone_number'] ?? $data['contact_phone_number']
    ?? $data['communication_number'] ?? $data['from'] ?? $data['caller_number'] ?? null;
$phone = zenoti_normalize_phone($rawPhone);
$agentId = (string) ($data['agent_id'] ?? $data['employee_id'] ?? $data['agent'] ?? $data['employee_ext'] ?? 'default');

if (!$phone) {
    http_response_code(400);
    echo json_encode(['error' => 'no phone number found in webhook payload', 'received' => $data]);
    exit;
}

try {
    $guest = zenoti_search_guest_by_phone($phone);
    store_save_event($agentId, [
        'phone' => $phone,
        'guest' => $guest,
        'receivedAt' => round(microtime(true) * 1000),
    ]);
    echo json_encode(['ok' => true, 'matched' => $guest !== null]);
} catch (Exception $e) {
    http_response_code(502);
    echo json_encode(['error' => 'zenoti lookup failed', 'detail' => $e->getMessage()]);
}
