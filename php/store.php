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
