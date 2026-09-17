<?php
// The dashboard tab / extension calls this every couple of seconds asking
// "anything new?" Pass ?agent=<name> to only see that specific agent's
// calls (matching the "agent" value set in that employee's CallGear
// scenario branch); omit it to see the shared feed (everyone's calls).
require __DIR__ . '/store.php';

header('Content-Type: application/json');

$agentId = $_GET['agent'] ?? 'shared';
$since = (float) ($_GET['since'] ?? 0);
$event = store_get_event($agentId);

if ($event && ($event['receivedAt'] ?? 0) > $since) {
    echo json_encode(['event' => $event]);
} else {
    echo json_encode(['event' => null]);
}
