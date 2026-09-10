<?php
// The dashboard tab calls this every couple of seconds asking "anything new?"
// CallGear doesn't tell us which agent will take a call, so there's one
// shared feed — every open dashboard sees every incoming call.
require __DIR__ . '/store.php';

header('Content-Type: application/json');

$since = (float) ($_GET['since'] ?? 0);
$event = store_get_event('shared');

if ($event && ($event['receivedAt'] ?? 0) > $since) {
    echo json_encode(['event' => $event]);
} else {
    echo json_encode(['event' => null]);
}
