<?php
// Talks to the Zenoti API: guest search by phone, and guest details lookup.

function zenoti_config() {
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/config.php';
        if (!file_exists($path)) {
            throw new Exception('config.php is missing. Copy config.example.php to config.php and fill in your Zenoti values.');
        }
        $config = require $path;
    }
    return $config;
}

function zenoti_normalize_phone($raw) {
    if (!$raw) return null;
    $digits = preg_replace('/[^\d+]/', '', $raw);
    return $digits !== '' ? $digits : null;
}

function zenoti_request($path, $query = []) {
    $config = zenoti_config();
    if (empty($config['zenoti_api_key'])) {
        throw new Exception('zenoti_api_key is not set in config.php');
    }
    $base = rtrim($config['zenoti_api_url'], '/');
    $url = $base . $path;
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: apikey ' . $config['zenoti_api_key'],
    ]);
    // Kept short: CallGear holds the live call waiting on our reply, so a
    // slow or unreachable Zenoti API must not stall a real phone call.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Zenoti request failed: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new Exception("Zenoti request failed ($status): $body");
    }
    $data = json_decode($body, true);
    if ($data === null) {
        throw new Exception('Zenoti returned invalid JSON: ' . $body);
    }
    return $data;
}

function zenoti_build_profile_url($guest) {
    $config = zenoti_config();
    $template = $config['zenoti_guest_url_template'] ?? '';
    if (!$template) return null;
    return str_replace(
        ['{guest_id}', '{center_id}'],
        [$guest['id'], $guest['centerId'] ?? ''],
        $template
    );
}

function zenoti_search_guest_by_phone($phone) {
    $config = zenoti_config();
    $query = ['phone' => $phone];
    if (!empty($config['zenoti_center_id'])) {
        $query['center_id'] = $config['zenoti_center_id'];
    }
    $data = zenoti_request('/v1/guests/search', $query);
    $raw = $data['guests'][0] ?? null;
    if (!$raw) return null;

    $personal = $raw['personal_info'] ?? [];
    $guest = [
        'id' => $raw['id'] ?? null,
        'firstName' => $personal['first_name'] ?? '',
        'lastName' => $personal['last_name'] ?? '',
        'phone' => $personal['mobile_phone']['number'] ?? $phone,
        'email' => $personal['email'] ?? '',
        'centerId' => $raw['center_id'] ?? null,
    ];
    $guest['profileUrl'] = zenoti_build_profile_url($guest);
    return $guest;
}

function zenoti_get_guest_details($guestId) {
    $data = zenoti_request('/v1/guests/' . rawurlencode($guestId));
    $personal = $data['personal_info'] ?? [];
    return [
        'id' => $data['id'] ?? $guestId,
        'firstName' => $personal['first_name'] ?? '',
        'lastName' => $personal['last_name'] ?? '',
        'phone' => $personal['mobile_phone']['number'] ?? '',
        'email' => $personal['email'] ?? '',
        'centerId' => $data['center_id'] ?? null,
    ];
}

// Full raw guest record — used to show every field Zenoti has, not just the
// short summary above.
function zenoti_get_guest_raw($guestId) {
    return zenoti_request('/v1/guests/' . rawurlencode($guestId));
}

function zenoti_get_guest_memberships($guestId, $centerId) {
    $query = [];
    if ($centerId) $query['center_id'] = $centerId;
    $data = zenoti_request('/v1/guests/' . rawurlencode($guestId) . '/memberships', $query);
    return $data['guest_memberships'] ?? [];
}

function zenoti_get_guest_packages($guestId, $centerId) {
    $query = [];
    if ($centerId) $query['center_id'] = $centerId;
    $data = zenoti_request('/v1/guests/' . rawurlencode($guestId) . '/Packages', $query);
    return $data['user_packages'] ?? $data['packages'] ?? [];
}

function zenoti_get_guest_products($guestId) {
    $data = zenoti_request('/v1/guests/' . rawurlencode($guestId) . '/products');
    return $data['products'] ?? [];
}

function zenoti_get_guest_giftcards($guestId) {
    $data = zenoti_request('/v1/guests/' . rawurlencode($guestId) . '/gift_cards');
    return $data['gift_cards'] ?? [];
}

function zenoti_get_guest_prepaidcards($guestId) {
    $data = zenoti_request('/v1/guests/' . rawurlencode($guestId) . '/prepaidcards');
    return $data['guest_prepaid_cards'] ?? [];
}

function zenoti_get_guest_loyalty($guestId) {
    // Note: this is a different endpoint from /points/{type} (earned vs.
    // redeemed breakdown) — this one returns the overall points balance.
    $data = zenoti_request('/v1/guests/' . rawurlencode($guestId) . '/points');
    return $data['guest_points'] ?? null;
}

function zenoti_get_guest_appointments($guestId, $limit = 10) {
    $data = zenoti_request('/v1/guests/' . rawurlencode($guestId) . '/appointments', [
        'page' => 1,
        'size' => $limit,
    ]);
    $rows = $data['appointments'] ?? [];
    $out = [];
    foreach ($rows as $row) {
        $services = $row['appointment_services'] ?? [];
        $serviceNames = array_map(fn($s) => $s['service']['name'] ?? '', $services);
        $start = $services[0]['start_time'] ?? null;
        $out[] = [
            'date' => $start,
            'services' => implode(', ', array_filter($serviceNames)) ?: '—',
            'status' => $row['invoice_status'] ?? null,
            'notes' => $row['notes'] ?? '',
        ];
    }
    return $out;
}
