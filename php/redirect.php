<?php
// Small bounce page: the extension opens this (on our own domain) instead
// of Zenoti's URL directly, then this redirects onward via a real
// navigation. This gives the browser a "referrer" (this page) when it
// arrives at Zenoti, matching what happens when a person clicks a link -
// unlike a tab opened directly with no originating page, which Zenoti's
// own app seems to treat as an untrusted direct link and bounces to its
// dashboard.
$url = $_GET['url'] ?? '';
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo 'Missing or invalid url parameter.';
    exit;
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Opening profile...</title></head>
<body>
<p>Opening profile... <a id="link" href="<?= htmlspecialchars($url) ?>">Click here if this doesn't happen automatically.</a></p>
<script>
  window.location.replace(<?= json_encode($url) ?>);
</script>
</body>
</html>
