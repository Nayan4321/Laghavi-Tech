<?php
// Client profile page opened on an incoming call — shows every detail Zenoti
// has plus history, so the agent doesn't need to switch apps mid-call.
require __DIR__ . '/zenoti.php';

$id = $_GET['id'] ?? '';
if (!$id) {
    http_response_code(400);
    echo 'Missing guest id.';
    exit;
}

try {
    $raw = zenoti_get_guest_raw($id);
} catch (Exception $e) {
    http_response_code(502);
    echo 'Could not load guest details: ' . htmlspecialchars($e->getMessage());
    exit;
}

$config = zenoti_config();
$centerId = $raw['center_id'] ?? null;
$name = trim(($raw['personal_info']['first_name'] ?? '') . ' ' . ($raw['personal_info']['last_name'] ?? ''));

// Where the "Open in Zenoti Dashboard" button goes.
$dashboardUrl = null;
if (!empty($config['zenoti_guest_url_template'])) {
    $dashboardUrl = str_replace(['{guest_id}', '{center_id}'], [$id, $centerId], $config['zenoti_guest_url_template']);
} elseif (!empty($config['zenoti_webapp_url'])) {
    $dashboardUrl = rtrim($config['zenoti_webapp_url'], '/');
}

// Turns a phone sub-object into "e.g. +91 98765 43210".
function fmt_phone($p) {
    if (empty($p) || empty($p['number'])) return null;
    return trim(($p['country_code'] ? '+' . $p['country_code'] . ' ' : '') . $p['number']);
}

function humanize_key($key) {
    return ucwords(str_replace('_', ' ', $key));
}

function fmt_value($value) {
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if ($value === null || $value === '') return null;
    if (is_scalar($value)) return (string) $value;
    return null; // nested arrays are handled separately per section
}

// Renders a flat associative array as a label/value list, skipping empties.
function render_kv($assoc) {
    $rows = '';
    foreach ($assoc as $key => $value) {
        $formatted = fmt_value($value);
        if ($formatted === null) continue;
        $rows .= '<dt>' . htmlspecialchars(humanize_key($key)) . '</dt><dd>' . htmlspecialchars($formatted) . '</dd>';
    }
    return $rows ? "<dl>$rows</dl>" : '<p class="muted">No details on file.</p>';
}

// Renders a list of records as a table, using the union of scalar (and
// one-level-flattened) fields found across all rows as columns.
function render_table($rows) {
    if (empty($rows)) return '<p class="muted">None found.</p>';

    $flat = [];
    foreach ($rows as $row) {
        $item = [];
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (is_scalar($subValue)) {
                        $item["$key.$subKey"] = $subValue;
                    }
                }
            } elseif (is_scalar($value)) {
                $item[$key] = $value;
            }
        }
        $flat[] = $item;
    }

    $columns = [];
    foreach ($flat as $item) {
        foreach ($item as $key => $value) {
            if (fmt_value($value) !== null) $columns[$key] = true;
        }
    }
    $columns = array_keys($columns);
    if (empty($columns)) return '<p class="muted">None found.</p>';

    $html = '<table><tr>';
    foreach ($columns as $col) $html .= '<th>' . htmlspecialchars(humanize_key(str_replace('.', ' ', $col))) . '</th>';
    $html .= '</tr>';
    foreach ($flat as $item) {
        $html .= '<tr>';
        foreach ($columns as $col) {
            $val = fmt_value($item[$col] ?? null);
            $html .= '<td>' . htmlspecialchars($val ?? '—') . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</table>';
}

// Each history section is fetched independently so one failing/unscoped API
// group (see php/README.md about Zenoti app scopes) doesn't break the page.
function fetch_section($fn) {
    try {
        return ['data' => $fn(), 'error' => null];
    } catch (Exception $e) {
        return ['data' => null, 'error' => $e->getMessage()];
    }
}

$appointments = fetch_section(fn() => zenoti_get_guest_appointments($id));
$memberships = fetch_section(fn() => zenoti_get_guest_memberships($id, $centerId));
$packages = fetch_section(fn() => zenoti_get_guest_packages($id, $centerId));
$products = fetch_section(fn() => zenoti_get_guest_products($id));
$giftCards = fetch_section(fn() => zenoti_get_guest_giftcards($id));
$prepaidCards = fetch_section(fn() => zenoti_get_guest_prepaidcards($id));
$loyalty = fetch_section(fn() => zenoti_get_guest_loyalty($id));

function render_section($title, $section, $isTable = true) {
    echo '<h2>' . htmlspecialchars($title) . '</h2>';
    if ($section['error']) {
        echo '<p class="muted">Could not load (' . htmlspecialchars($section['error']) . '). '
           . 'This usually means the Zenoti app\'s API key needs the matching scope enabled — see php/README.md.</p>';
        return;
    }
    echo $isTable ? render_table($section['data']) : render_kv($section['data'] ?? []);
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($name ?: 'Client Profile') ?></title>
<style>
body{font-family:system-ui,sans-serif;max-width:720px;margin:32px auto;padding:0 16px;color:#222}
h1{margin-bottom:4px}
h2{margin-top:32px;font-size:16px;color:#444;border-bottom:1px solid #eee;padding-bottom:6px}
dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0}
dt{color:#666}
table{width:100%;border-collapse:collapse;margin-top:8px;font-size:14px}
th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #eee}
th{color:#666;font-weight:600}
.muted{color:#888;font-size:14px}
.dash-btn{display:inline-block;margin-top:12px;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-size:14px}
</style>
</head>
<body>
<h1><?= htmlspecialchars($name ?: 'Client Profile') ?></h1>
<?php if ($dashboardUrl): ?>
  <a class="dash-btn" href="<?= htmlspecialchars($dashboardUrl) ?>" target="_blank">Open in Zenoti Dashboard ↗</a>
<?php else: ?>
  <p class="muted">Set <code>zenoti_webapp_url</code> (or <code>zenoti_guest_url_template</code>) in config.php to show a dashboard button here.</p>
<?php endif; ?>

<?php
render_section('Personal Info', ['data' => array_merge(
    $raw['personal_info'] ?? [],
    ['mobile_phone' => fmt_phone($raw['personal_info']['mobile_phone'] ?? null)],
    ['work_phone' => fmt_phone($raw['personal_info']['work_phone'] ?? null)],
    ['home_phone' => fmt_phone($raw['personal_info']['home_phone'] ?? null)]
), 'error' => null], false);

render_section('Address', ['data' => $raw['address_info'] ?? [], 'error' => null], false);
render_section('Preferences', ['data' => $raw['preferences'] ?? [], 'error' => null], false);

if (!empty($raw['tags'])) {
    echo '<h2>Tags</h2><p>' . htmlspecialchars(implode(', ', (array) $raw['tags'])) . '</p>';
}

render_section('Appointment History', $appointments);
render_section('Memberships', $memberships);
render_section('Packages', $packages);
render_section('Products Purchased', $products);
render_section('Gift Cards', $giftCards);
render_section('Prepaid Cards', $prepaidCards);

if (!$loyalty['error'] && $loyalty['data']) {
    render_section('Loyalty Points', ['data' => $loyalty['data'], 'error' => null], false);
} elseif ($loyalty['error']) {
    render_section('Loyalty Points', $loyalty, false);
}
?>
</body>
</html>
