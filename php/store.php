<?php
// Tiny file-based store: one JSON file per agent holding their latest call event.
// No database needed — fine for this app's volume (one active call per agent).

function store_dir() {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

// Normalizes case, whitespace, and anything past the first name before
// sanitizing. Employees only ever type their first name into the extension
// (e.g. "hadeer") - but the &agent=... value in CallGear's Authorization
// URLs was typed by hand across many duplicated scenarios, so it might
// carry a last name, a stray character, or other extra text tacked on
// ("hadeer_gaber", "hadeer.g", "HadeerG" ...). Keeping only the leading
// run of letters means all of those still resolve to the same stored
// agent as the plain first name, as long as that first name itself is
// spelled the same.
function store_safe_agent_id($agentId) {
    $normalized = strtolower(trim((string) $agentId));
    if (preg_match('/^[a-z]+/', $normalized, $match)) {
        $normalized = $match[0];
    }
    return preg_replace('/[^a-z0-9_.-]/', '_', $normalized);
}

function store_save_event($agentId, $event) {
    $file = store_dir() . '/' . store_safe_agent_id($agentId) . '.json';
    file_put_contents($file, json_encode($event), LOCK_EX);
}

function store_get_event($agentId) {
    $file = store_dir() . '/' . store_safe_agent_id($agentId) . '.json';
    if (!file_exists($file)) return null;
    $content = file_get_contents($file);
    return $content ? json_decode($content, true) : null;
}

function store_timeline_file() {
    return store_dir() . '/timeline.json';
}

// Records every event, across all agents, with phone + agent + time.
// CallGear calls each employee's branch in turn as a call cascades through
// the team - when an employee is busy/on break/unavailable, CallGear moves
// to the next employee within a few seconds; when someone is genuinely
// being rung, it takes much longer (the full "Dialing duration"). This
// timeline lets poll.php tell the two apart: if a DIFFERENT agent's event
// for the SAME phone number shows up shortly after this one, this employee
// was skipped, not actually rung. Trimmed to the last couple of minutes so
// the file never grows unbounded.
function store_log_timeline($phone, $agentId, $receivedAt) {
    $file = store_timeline_file();
    $entries = [];
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $entries = $content ? (json_decode($content, true) ?: []) : [];
    }
    $entries[] = ['phone' => $phone, 'agent' => $agentId, 'receivedAt' => $receivedAt];
    $cutoff = $receivedAt - (2 * 60 * 1000);
    $entries = array_values(array_filter($entries, function ($e) use ($cutoff) {
        return ($e['receivedAt'] ?? 0) >= $cutoff;
    }));
    file_put_contents($file, json_encode($entries), LOCK_EX);
}

// True if some OTHER agent got an event for the same phone number after
// this one - meaning this employee's branch was skipped quickly rather
// than genuinely rung.
function store_was_skipped($phone, $agentId, $receivedAt) {
    $file = store_timeline_file();
    if (!file_exists($file)) return false;
    $content = file_get_contents($file);
    $entries = $content ? (json_decode($content, true) ?: []) : [];
    foreach ($entries as $e) {
        if (($e['phone'] ?? null) === $phone
            && ($e['agent'] ?? null) !== $agentId
            && ($e['receivedAt'] ?? 0) > $receivedAt) {
            return true;
        }
    }
    return false;
}
