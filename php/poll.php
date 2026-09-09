<?php
// The agent's dashboard tab calls this every couple of seconds asking
// "anything new for me?" — much simpler and more compatible with shared
// hosting than a long-held streaming connection.
require __DIR__ . '/store.php';

header('Content-Type: application/json');

$agentId = $_GET['agent'] ?? 'default';
$since = (float) ($_GET['since'] ?? 0);

$event = store_get_event($agentId);

if ($event && ($event['receivedAt'] ?? 0) > $since) {
    echo json_encode(['event' => $event]);
} else {
    echo json_encode(['event' => null]);
}
