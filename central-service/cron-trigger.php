<?php
/**
 * Centralized Cron Trigger
 * 
 * Run this script via system cron on the Central Service server.
 * Usage: php /path/to/central-service/cron-trigger.php
 * Frequency: Every 5-10 minutes
 */

// Security: Strictly enforce CLI execution
$is_cli = (php_sapi_name() === 'cli' || defined('STDIN'));
$is_web = (isset($_SERVER['REMOTE_ADDR']) || isset($_SERVER['HTTP_USER_AGENT']));

if (!$is_cli || $is_web) {
    http_response_code(403);
    die("Access Denied: This script serves as a background cron trigger and cannot be executed via web browser.\n");
}

// Define as central service to bypass direct access checks if included
define('GADS_CENTRAL_SERVICE', true);

// Load Config
require_once __DIR__ . '/config.php';

// Path to clients database
$clients_file = __DIR__ . '/logs/clients.json';

echo "[Heartbeat] Starting cron trigger process...\n";

if (!file_exists($clients_file)) {
    die("[Error] No clients registered yet.\n");
}

$clients = json_decode(file_get_contents($clients_file), true);

if (empty($clients) || !is_array($clients)) {
    die("[Info] Client list is empty.\n");
}

// Multi-curl handler for parallel requests
$mh = curl_multi_init();
$curl_handles = [];
$start_time = microtime(true);

echo "[Heartbeat] Triggering " . count($clients) . " clients...\n";

foreach ($clients as $url => $info) {
    if (isset($info['status']) && $info['status'] !== 'active') {
        continue;
    }
    
    // Construct Cron URL
    // Append ?doing_wp_cron to force execution even if visited by browser
    $cron_url = rtrim($url, '/') . '/wp-cron.php?doing_wp_cron=' . time();
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $cron_url);
    curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only, don't download body
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Short timeout
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); // Self-signed certs OK
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GadsToolkit-CentralHeartbeat/1.0');
    
    curl_multi_add_handle($mh, $ch);
    $curl_handles[$url] = $ch;
}

// Execute all requests
$active = null;
do {
    $mrc = curl_multi_exec($mh, $active);
} while ($active > 0);

// Check results
$success = 0;
$fail = 0;

foreach ($curl_handles as $url => $ch) {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code >= 200 && $code < 400) {
        $success++;
    } else {
        echo "[Fail] {$url} returned HTTP {$code}\n";
        $fail++;
    }
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}

curl_multi_close($mh);

$duration = round(microtime(true) - $start_time, 2);
echo "[Heartbeat] Completed in {$duration}s. Success: {$success}, Fail: {$fail}.\n";
