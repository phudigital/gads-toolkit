<?php
// Isolated runtime harness: no WordPress DB, Google Ads, email or Telegram access.
define('ABSPATH', __DIR__);
class WP_Error { public function __construct($code, $message = '') {} }
function is_wp_error($v) { return $v instanceof WP_Error; }
$options = []; $cache = []; $calls = 0; $reply = null;
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_option($key, $value, ...$args) { global $options; if (isset($options[$key])) return false; $options[$key] = $value; return true; }
function home_url($path = '') { global $site_url; return ($site_url ?? 'https://licensed.com') . $path; }
function get_transient($key) { global $cache; return $cache[$key] ?? false; }
function set_transient($key, $value, $ttl) { global $cache; $cache[$key] = $value; }
function add_action(...$args) {}
function add_filter(...$args) {}
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function wp_remote_post($url, $args) {
    global $transport_calls; $transport_calls[] = array($url, $args);
    return array('status' => 200, 'body' => array('success' => true, 'data' => array('ips' => array(), 'message' => 'ok', 'refresh_token' => 'mock')));
}
function wp_remote_get($url, $args) {
    global $calls, $reply;
    ++$calls;
    if ($url === 'https://service.test/api?action=get_credentials') {
        global $transport_calls; $transport_calls[] = array($url, $args);
        return array('status' => 200, 'body' => array('success' => true, 'data' => array('client_id' => 'mock')));
    }
    if ($url !== 'https://service.test/api?action=validate_license' || !isset($args['headers']['X-API-Key']) || $args['redirection'] !== 0 || $args['headers']['X-GAds-Site'] !== tkgadm_license_site_url() || !preg_match('/^[a-f0-9]{64}$/D', $args['headers']['X-GAds-Site-Token'])) { throw new Exception('Unexpected outbound request'); }
    return $reply;
}
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_remote_retrieve_body($r) { return json_encode($r['body']); }
class WP_REST_Response { public $data; public $headers = array(); public function __construct($data) { $this->data = $data; } public function header($key, $value) { $this->headers[$key] = $value; } }
function wp_send_json_error($message, $code) { throw new RuntimeException('locked:' . $code); }
function current_user_can($cap) { global $allowed; return $allowed ?? true; }
function check($condition, $message) { if (!$condition) { throw new Exception($message); } }
foreach (['module-license','core-engine','module-google-ads','module-whitelist','module-notifications','module-dashboard','module-gads-manager','module-data'] as $module) { require __DIR__ . '/../includes/' . $module . '.php'; }
check(!tkgadm_license_is_valid() && $calls === 0, 'Missing key must lock without network');
// Any access to local data while locked is a failure.
$wpdb = new class { public function __get($name) { throw new Exception('DB accessed while locked'); } public function __call($name, $args) { throw new Exception('DB accessed while locked'); } };
tkgadm_track_visit(); tkgadm_enqueue_time_tracker(); tkgadm_check_ip_instant('203.0.113.1');
tkgadm_run_auto_block_scan(); tkgadm_handle_hourly_sync(); tkgadm_check_suspicious_ips(); tkgadm_send_daily_report(); tkgadm_hook_smart_whitelist_in_cron();
check(tkgadm_block_ip_internal('203.0.113.1') === false, 'Local block locked');
check(tkgadm_is_ip_blocked('203.0.113.1') === false, 'Existing local block must be inactive');
check(is_wp_error(tkgadm_list_connected_google_ads_ips()), 'List locked');
foreach (['tkgadm_do_full_sync_google_ads','tkgadm_do_sync_process'] as $fn) { check($fn()['success'] === false, $fn . ' array contract'); }
check(tkgadm_smart_rotate_google_ads_ips()['freed'] === 0, 'Rotation array contract');
check(tkgadm_run_smart_whitelist_scan()['added'] === 0, 'Whitelist scan array contract');
check(tkgadm_sync_ip_to_google_ads(['203.0.113.1'])['success'] === false, 'Upload array contract');
check(tkgadm_send_email_notification('subject', 'body') === false, 'Email locked');
check(tkgadm_send_telegram_message('body') === false, 'Telegram locked');
foreach (['tkgadm_ajax_update_time_on_page','tkgadm_ajax_toggle_block_ip','tkgadm_ajax_gads_full_sync','tkgadm_ajax_delete_data'] as $fn) {
    try { $fn(); throw new Exception('AJAX not locked'); } catch (RuntimeException $e) { check($e->getMessage() === 'locked:403', 'AJAX 403'); }
}
$options = ['tkgadm_central_service_api_key' => 'paid', 'tkgadm_central_service_url' => 'https://service.test'];
$reply = ['status' => 200, 'body' => ['success' => true, 'data' => ['valid' => true, 'expires_at' => null, 'site_url' => 'https://licensed.com/']]];
check(tkgadm_license_is_valid(), 'Valid key unlocks'); $n = $calls;
check(tkgadm_license_is_valid() && $calls === $n, 'Bounded cache avoids extra fetch');
foreach ($cache as &$entry) { $entry['until'] = time() - 1; } unset($entry);
$reply = new WP_Error('timeout');
check(!tkgadm_license_status(true)['valid'], 'Network failure must lock without old success');
$reply = ['status' => 200, 'body' => ['success' => true]];
check(!tkgadm_license_status(true)['valid'], 'Health-like malformed success cannot unlock');
$reply = ['status' => 200, 'body' => ['success' => true, 'data' => ['valid' => true, 'expires_at' => '2020-01-01', 'site_url' => 'https://licensed.com/']]];
check(!tkgadm_license_status(true)['valid'], 'Expired license locks');
$reply['body']['data']['expires_at'] = gmdate('c', time() + 30);
$status = tkgadm_license_status(true);
check($status['valid'] && $status['until'] <= time() + 30, 'Cache cannot outlive license expiry');
// Unauthorized admin AJAX must not consult the Worker even with a saved key.
$allowed = false; $n = $calls;
try { tkgadm_ajax_gads_full_sync(); } catch (RuntimeException $e) {}
check($calls === $n, 'Authorization must precede license request'); $allowed = true;
$reply = ['status' => 200, 'body' => ['success' => true, 'data' => 'wrong-type']];
check(!tkgadm_license_status(true)['valid'], 'Malformed data must lock without a PHP error');
$options['tkgadm_central_service_api_key'] = 'different'; $reply = ['status' => 403, 'body' => []];
check(!tkgadm_license_is_valid(), 'Changing key cannot reuse previous success');
$options['tkgadm_central_service_api_key'] = '';
check(!tkgadm_license_is_valid(), 'Removing key immediately locks');
// Licensed public tracking still rejects malformed payloads before DB access.
$options['tkgadm_central_service_api_key'] = 'payload-test';
$reply = ['status' => 200, 'body' => ['success' => true, 'data' => ['valid' => true, 'expires_at' => null, 'site_url' => 'https://licensed.com/']]];
foreach ([['ip' => ['bad'], 'url' => '/', 'time' => '5'], ['ip' => '203.0.113.1', 'url' => '/', 'time' => '-1'], ['ip' => '203.0.113.1', 'url' => str_repeat('x', 4097), 'time' => '5']] as $payload) {
    $_POST = $payload;
    try { tkgadm_ajax_update_time_on_page(); throw new Exception('Malformed tracking accepted'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'locked:400', 'Tracking schema rejection'); }
}
$_POST = [];
// Public challenge returns only an HMAC; rejects malformed input and does no HTTP work.
$n = $calls;
$request = new class { public $challenge; public function get_param($key) { return $this->challenge; } };
$request->challenge = 'c' . str_repeat('a', 63);
$proof_response = tkgadm_license_proof($request);
check($proof_response->data['proof'] === hash_hmac('sha256', $request->challenge, tkgadm_license_site_token()), 'Challenge HMAC matches installation');
check(count($proof_response->data) === 2 && $proof_response->headers['Cache-Control'] === 'no-store, private, max-age=0', 'Proof response does not expose secrets or get cached');
$request->challenge = array('bad'); check(is_wp_error(tkgadm_license_proof($request)), 'Array challenge rejected');
check($calls === $n, 'Challenge must not recursively validate license');
// All actual Central transport callers send site credentials in headers and disable redirects.
$options['tkgadm_gads_customer_id'] = '1234567890'; $options['tkgadm_gads_refresh_token'] = 'mock';
$transport_calls = array();
tkgadm_get_central_service_credentials(); tkgadm_exchange_code_via_service('mock-code');
tkgadm_sync_via_central_service(array('203.0.113.1')); tkgadm_list_ips_via_central_service();
tkgadm_remove_ips_via_central_service(array('customers/1234567890/customerNegativeCriteria/1'));
tkgadm_register_site_heartbeat();
check(count($transport_calls) === 6, 'All six service transports exercised');
foreach ($transport_calls as $call) {
    check(strpos($call[0], 'api_key=') === false, 'No credentials in URL');
    check($call[1]['headers']['X-API-Key'] === 'payload-test' && $call[1]['headers']['X-GAds-Site'] === 'https://licensed.com/'
        && $call[1]['headers']['X-GAds-Site-Token'] === tkgadm_license_site_token() && $call[1]['redirection'] === 0, 'Shared domain headers on every transport');
}
$old_token = tkgadm_license_site_token(); $n = $calls; $site_url = 'https://clone.com';
check(tkgadm_license_site_token() !== $old_token, 'Changing configured site changes installation identity');
check(!tkgadm_license_is_valid() && $calls === $n + 1, 'Clone cannot reuse prior site license cache or mismatched site response');
echo "License gate runtime checks passed (no DB or external side effects).\n";
