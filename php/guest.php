<?php
// Client profile page opened on an incoming call — shows contact info plus
// recent appointment history so the agent has context without switching apps.
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

$appointments = [];
$appointmentsError = null;
try {
    $appointments = zenoti_get_guest_appointments($id);
} catch (Exception $e) {
    $appointmentsError = $e->getMessage();
}

$invoiceStatusLabels = [0 => 'Open', 1 => 'Processed', 4 => 'Closed', 99 => 'Voided'];
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars(trim($g['firstName'] . ' ' . $g['lastName'])) ?></title>
<style>
body{font-family:system-ui,sans-serif;max-width:640px;margin:48px auto;padding:0 16px;color:#222}
h1{margin-bottom:4px}
h2{margin-top:32px;font-size:16px;color:#444}
dl{display:grid;grid-template-columns:auto 1fr;gap:6px 12px}
dt{color:#666}
table{width:100%;border-collapse:collapse;margin-top:12px}
th,td{text-align:left;padding:8px;border-bottom:1px solid #eee;font-size:14px}
th{color:#666;font-weight:600}
.muted{color:#888;font-size:14px}
</style>
</head>
<body>
<h1><?= htmlspecialchars(trim($g['firstName'] . ' ' . $g['lastName'])) ?: 'Client' ?></h1>
<dl>
<dt>Phone</dt><dd><?= htmlspecialchars($g['phone']) ?></dd>
<dt>Email</dt><dd><?= htmlspecialchars($g['email'] ?: '—') ?></dd>
<dt>Guest ID</dt><dd><?= htmlspecialchars($g['id']) ?></dd>
</dl>

<h2>Recent appointments</h2>
<?php if ($appointmentsError): ?>
  <p class="muted">Could not load appointment history: <?= htmlspecialchars($appointmentsError) ?></p>
<?php elseif (empty($appointments)): ?>
  <p class="muted">No appointment history found.</p>
<?php else: ?>
  <table>
    <tr><th>Date</th><th>Service(s)</th><th>Status</th><th>Notes</th></tr>
    <?php foreach ($appointments as $a): ?>
      <tr>
        <td><?= htmlspecialchars($a['date'] ? date('d M Y', strtotime($a['date'])) : '—') ?></td>
        <td><?= htmlspecialchars($a['services']) ?></td>
        <td><?= htmlspecialchars($invoiceStatusLabels[$a['status']] ?? ($a['status'] ?? '—')) ?></td>
        <td><?= htmlspecialchars($a['notes'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
</body>
</html>
