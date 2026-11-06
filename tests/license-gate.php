<?php
// Isolated runtime harness: no WordPress DB, Google Ads, email or Telegram access.
define('ABSPATH', __DIR__);
foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800] as $c => $v) { define($c, $v); }
class WP_Error { public function __construct($code, $message = '') {} }
function is_wp_error($v) { return $v instanceof WP_Error; }
$options = []; $cache = []; $calls = 0; $reply = null;
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_option($key, $value, ...$args) { global $options; if (isset($options[$key])) return false; $options[$key] = $value; return true; }
function update_option($key, $value, ...$args) { global $options; $options[$key] = $value; return true; }
function delete_option($key) { global $options; unset($options[$key]); return true; }
$scheduled = [];
function wp_next_scheduled($hook) { global $scheduled; return $scheduled[$hook] ?? false; }
function wp_schedule_single_event($ts, $hook) { global $scheduled; $scheduled[$hook] = $ts; return true; }
$is_admin = true;
function is_admin() { global $is_admin; return $is_admin; }
function wp_doing_cron() { return false; }
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
foreach (['module-license','core-engine','module-google-ads','module-sync','module-activity-log','module-insights','module-tracking','module-whitelist','module-notifications','module-dashboard','module-gads-manager','module-data'] as $module) { require __DIR__ . '/../includes/' . $module . '.php'; }
check(!tkgadm_license_is_valid() && $calls === 0, 'Missing key must lock without network');
// Any access to local data while locked is a failure.
$wpdb = new class { public function __get($name) { throw new Exception('DB accessed while locked'); } public function __call($name, $args) { throw new Exception('DB accessed while locked'); } };
tkgadm_track_visit(); tkgadm_enqueue_time_tracker(); tkgadm_check_ip_instant('203.0.113.1'); tkgadm_handle_sync_now();
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
check(tkgadm_license_is_valid() && $calls === $n, 'Cached state avoids extra fetch');
// Service outage after a success: keep working within the grace window.
$reply = new WP_Error('timeout');
check(tkgadm_license_status(true)['valid'], 'Outage keeps a recent success valid (grace)');
$reply = ['status' => 200, 'body' => ['success' => true]];
check(tkgadm_license_status(true)['valid'], 'Malformed reply is treated as outage, not success');
// Explicit rejection locks immediately.
$reply = ['status' => 403, 'body' => ['success' => false, 'error' => 'revoked']];
check(!tkgadm_license_status(true)['valid'], 'Explicit rejection locks');
$reply = new WP_Error('timeout');
check(!tkgadm_license_status(true)['valid'], 'Outage after rejection stays locked');
$reply = ['status' => 200, 'body' => ['success' => true, 'data' => ['valid' => true, 'expires_at' => '2020-01-01', 'site_url' => 'https://licensed.com/']]];
check(!tkgadm_license_status(true)['valid'], 'Expired license locks');
$reply['body']['data']['expires_at'] = gmdate('c', time() + 30);
check(tkgadm_license_status(true)['valid'], 'Dated license unlocks');
// Visitor requests never call the service; a stale state schedules a background refresh.
$saved_key = $options['tkgadm_central_service_api_key'];
$options['tkgadm_central_service_api_key'] = 'frontend-key';
$state_key = 'tkgadm_license_state_' . substr(hash('sha256', 'https://service.test|frontend-key|' . tkgadm_license_site_url() . '|' . tkgadm_license_site_token()), 0, 32);
$options[$state_key] = ['valid' => true, 'message' => 'ok', 'checked_at' => time() - 13 * 3600, 'last_ok' => time() - 13 * 3600, 'expires' => null];
$is_admin = false; $n = $calls; $scheduled = [];
check(tkgadm_license_is_valid() && $calls === $n, 'Visitor request uses stored state without calling the service');
check(isset($scheduled['tkgadm_license_refresh_event']), 'Stale state schedules a background refresh');
$options[$state_key]['last_ok'] = time() - 73 * 3600;
$options['tkgadm_central_service_api_key'] = 'frontend-key-2';
$state_key2 = 'tkgadm_license_state_' . substr(hash('sha256', 'https://service.test|frontend-key-2|' . tkgadm_license_site_url() . '|' . tkgadm_license_site_token()), 0, 32);
$options[$state_key2] = ['valid' => true, 'message' => 'ok', 'checked_at' => time() - 80 * 3600, 'last_ok' => time() - 80 * 3600, 'expires' => null];
check(!tkgadm_license_is_valid() && $calls === $n, 'Success older than the grace window locks');
$is_admin = true; $options['tkgadm_central_service_api_key'] = $saved_key;
// Unauthorized admin AJAX must not consult the Worker even with a saved key.
$allowed = false; $n = $calls;
try { tkgadm_ajax_gads_full_sync(); } catch (RuntimeException $e) {}
check($calls === $n, 'Authorization must precede license request'); $allowed = true;
$reply = ['status' => 200, 'body' => ['success' => true, 'data' => 'wrong-type']];
tkgadm_license_status(true); // Malformed data must not raise a PHP error.
$options['tkgadm_central_service_api_key'] = 'different'; $reply = ['status' => 403, 'body' => []];
check(!tkgadm_license_is_valid(), 'Changing key cannot reuse previous success');
$options['tkgadm_central_service_api_key'] = '';
check(!tkgadm_license_is_valid(), 'Removing key immediately locks');
// Licensed public tracking still rejects malformed payloads before DB access.
$options['tkgadm_central_service_api_key'] = 'payload-test';
$reply = ['status' => 200, 'body' => ['success' => true, 'data' => ['valid' => true, 'expires_at' => null, 'site_url' => 'https://licensed.com/']]];
foreach ([['url' => ['bad'], 'time' => '5'], ['url' => '/', 'time' => '-1'], ['url' => str_repeat('x', 4097), 'time' => '5']] as $payload) {
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
