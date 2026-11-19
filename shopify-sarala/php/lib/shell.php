<?php
/** The embedded admin page: App Bridge + Polaris from Shopify's CDN, then the app (assets/app.js). */
$cfg = sarala_config();
$shop = strtolower((string) ($_GET['shop'] ?? ''));
$ancestors = 'https://admin.shopify.com';
if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop)) $ancestors = "https://$shop https://admin.shopify.com";
header("Content-Security-Policy: frame-ancestors $ancestors;");
header('Cache-Control: no-store');
$v = @filemtime(dirname(__DIR__) . '/assets/app.js') ?: 1;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="shopify-api-key" content="<?= htmlspecialchars($cfg['api_key']) ?>">
<title>Sarala User Access</title>
<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
<script src="https://cdn.shopify.com/shopifycloud/polaris-2.js"></script>
</head>
<body>
<div id="root"></div>
<script type="module" src="/assets/app.js?v=<?= (int) $v ?>"></script>
</body>
</html>
