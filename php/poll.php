<?php
// The dashboard tab / extension calls this every couple of seconds asking
// "anything new?" Pass ?agent=<name> to only see that specific agent's
// calls (matching the "agent" value set in that employee's CallGear
// scenario branch); omit it to see the shared feed (everyone's calls).
require __DIR__ . '/store.php';

header('Content-Type: application/json');
// Never let a browser/proxy cache this response - it's polled every few
// seconds expecting a fresh answer each time, and a cached "no event" or
// cached stale event would look exactly like a missed or wrong call.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

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

// CallGear fires this employee's webhook the instant their branch is
// tried, before it knows whether they're actually free - if they're busy/
// on break/unavailable, CallGear moves on to the next employee within a
// few seconds; a genuine ring takes much longer (the "Dialing duration").
// So an event is held back for a few seconds before it's ever shown, to
// give a possible "skip" time to happen - if a different agent's event
// for the same phone number shows up in that window, this employee was
// skipped, not actually rung, and the popup never appears at all.
$skipDetectDelayMs = 5000;
$oldEnoughToTrust = $event && ($now - ($event['receivedAt'] ?? 0)) >= $skipDetectDelayMs;
$wasSkipped = $oldEnoughToTrust && store_was_skipped($event['phone'], $agentId, $event['receivedAt']);

if ($isFresh && $oldEnoughToTrust && !$wasSkipped && ($event['receivedAt'] ?? 0) > $since) {
    echo json_encode(['event' => $event]);
} else {
    echo json_encode(['event' => null]);
}
