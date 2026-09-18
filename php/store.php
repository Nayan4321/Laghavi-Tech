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

// Normalizes case and surrounding whitespace before sanitizing, so
// "Ryhem", "ryhem ", and "RYHEM" all resolve to the same stored agent -
// CallGear's Authorization URLs got typed by hand across many duplicated
// scenarios, so small case/whitespace differences from copy-pasting are
// expected and shouldn't cause a mismatch with what's saved in the
// extension (which already lowercases/trims what's typed there).
function store_safe_agent_id($agentId) {
    $normalized = strtolower(trim((string) $agentId));
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
