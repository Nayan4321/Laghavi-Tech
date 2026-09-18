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

// If an employee was away (on break, computer off) when a call cascaded
// past their branch, an event still got saved for them - CallGear calls
// their webhook branch regardless of whether they're actually available.
// Without this check, their screen would pop that stale call the moment
// they're back and their extension starts polling again, even though the
// call is long over. 60s comfortably covers one ring cycle (see the
// "Dialing duration" on each Redirect step) plus normal network delay.
$maxAgeMs = 60 * 1000;
$now = round(microtime(true) * 1000);
$isFresh = $event && ($now - ($event['receivedAt'] ?? 0)) <= $maxAgeMs;

if ($isFresh && ($event['receivedAt'] ?? 0) > $since) {
    echo json_encode(['event' => $event]);
} else {
    echo json_encode(['event' => null]);
}
