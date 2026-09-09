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

function store_safe_agent_id($agentId) {
    return preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $agentId);
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
