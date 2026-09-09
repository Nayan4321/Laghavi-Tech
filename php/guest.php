<?php
// Fallback profile page, used when zenoti_guest_url_template isn't set yet.
require __DIR__ . '/zenoti.php';

$id = $_GET['id'] ?? '';
if (!$id) {
    http_response_code(400);
    echo 'Missing guest id.';
    exit;
}

try {
    $g = zenoti_get_guest_details($id);
} catch (Exception $e) {
    http_response_code(502);
    echo 'Could not load guest details: ' . htmlspecialchars($e->getMessage());
    exit;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($g['firstName'] . ' ' . $g['lastName']) ?></title>
<style>
body{font-family:system-ui,sans-serif;max-width:480px;margin:48px auto;padding:0 16px}
h1{margin-bottom:4px}
dl{display:grid;grid-template-columns:auto 1fr;gap:6px 12px}
dt{color:#666}
</style>
</head>
<body>
<h1><?= htmlspecialchars($g['firstName'] . ' ' . $g['lastName']) ?></h1>
<dl>
<dt>Phone</dt><dd><?= htmlspecialchars($g['phone']) ?></dd>
<dt>Email</dt><dd><?= htmlspecialchars($g['email'] ?: '—') ?></dd>
<dt>Guest ID</dt><dd><?= htmlspecialchars($g['id']) ?></dd>
<dt>Center ID</dt><dd><?= htmlspecialchars((string) $g['centerId']) ?></dd>
</dl>
</body>
</html>
