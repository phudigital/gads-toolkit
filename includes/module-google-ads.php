<?php
/**
 * Module: Google Ads Integration
 * Connects to Google Ads API, manages settings, and handles IP synchronization.
 */

if (!defined('ABSPATH')) exit;

/**
 * Get sanitized Google Ads credentials from options.
 *
 * Returns customer_id, manager_id (digits only, whitespace/dashes stripped)
 * plus refresh_token and developer_token when available.
 *
 * @return array{customer_id: string, manager_id: string, developer_token: string, refresh_token: string}
 */
function tkgadm_get_gads_ids() {
    return [
        'customer_id'   => preg_replace('/[\s-]+/', '', (string) get_option('tkgadm_gads_customer_id')),
        'manager_id'    => preg_replace('/[\s-]+/', '', (string) get_option('tkgadm_gads_manager_id')),
        'developer_token' => (string) get_option('tkgadm_gads_developer_token'),
        'refresh_token' => (string) get_option('tkgadm_gads_refresh_token'),
    ];
}

/** Select a usable transport without exposing the service's Developer Token. */
function tkgadm_get_gads_connection_mode() {
    $ids = tkgadm_get_gads_ids();
    if (!preg_match('/^\d{10}$/', $ids['customer_id']) || !$ids['refresh_token']) return '';
    if ($ids['manager_id'] && !preg_match('/^\d{10}$/', $ids['manager_id'])) return '';
    if ($ids['developer_token'] && get_option('tkgadm_gads_client_id') && get_option('tkgadm_gads_client_secret')) {
        return 'direct';
    }
    return tkgadm_is_using_central_service() ? 'central' : '';
}

/** List blocked IPs using the same connection as uploads. */
function tkgadm_list_connected_google_ads_ips() {
    if (!tkgadm_license_is_valid()) { return new WP_Error('license_required', tkgadm_license_message()); }

    $mode = tkgadm_get_gads_connection_mode();
    if ($mode === 'central') return tkgadm_list_ips_via_central_service();
    if (!$mode) return new WP_Error('missing_config', 'Vui lòng kiểm tra kết nối Google Ads và Customer ID trong Cấu hình & Tích hợp.');
    $token = tkgadm_get_google_access_token();
    if (is_wp_error($token)) return $token;
    $ids = tkgadm_get_gads_ids();
    return tkgadm_get_google_ads_blocked_ips($token, $ids['customer_id'], $ids['developer_token'], $ids['manager_id']);
}

/** Remove selected criteria using the configured connection. */
function tkgadm_remove_connected_google_ads_ips($resource_names) {
    if (!tkgadm_license_is_valid()) { return new WP_Error('license_required', tkgadm_license_message()); }

    $mode = tkgadm_get_gads_connection_mode();
    if ($mode === 'central') return tkgadm_remove_ips_via_central_service($resource_names);
    if (!$mode) return new WP_Error('missing_config', 'Vui lòng kiểm tra kết nối Google Ads và Customer ID trong Cấu hình & Tích hợp.');
    $token = tkgadm_get_google_access_token();
    if (is_wp_error($token)) return $token;
    $ids = tkgadm_get_gads_ids();
    return tkgadm_remove_google_ads_ips($token, $ids['customer_id'], $ids['developer_token'], $resource_names, $ids['manager_id']);
}

/**
 * Validate Google Ads Account ID format (xxx-xxx-xxxx or 10 digits).
 *
 * @param string $id   Raw ID value
 * @param string $label Human-readable label for error messages
 * @return string|WP_Error Sanitized 10-digit ID or WP_Error
 */
function tkgadm_validate_gads_id_format($id, $label = 'ID') {
    $clean = preg_replace('/[\s-]+/', '', trim((string) $id));
    if ($clean === '') {
        return ''; // empty is allowed (optional field)
    }
    if (!preg_match('/^\d{10}$/', $clean)) {
        return new WP_Error(
            'invalid_id_format',
            sprintf('%s không hợp lệ — cần đúng 10 chữ số (VD: 123-456-7890).', $label)
        );
    }
    return $clean;
}

/**
 * ============================================================================
 * 1. API & OAUTH FUNCTIONS
 * ============================================================================
 */

/**
 * Get OAuth Redirect URI
 *
 * Returns the redirect URI to use for OAuth flow.
 * Can be configured to use a central handler or direct WordPress admin URL.
 *
 * @return string Redirect URI
 */
function tkgadm_get_oauth_redirect_uri() {
    // Check if custom redirect URI is configured
    $custom_redirect = get_option('tkgadm_oauth_redirect_uri');

    if (!empty($custom_redirect)) {
        // Use custom central redirect handler
        return $custom_redirect;
    }

    // Fallback to direct WordPress admin URL (requires adding to Google Console)
    return admin_url('admin.php?page=tkgad-google-ads');
}

/**
 * Get OAuth State Parameter
 *
 * Creates a state parameter containing the return URL and security nonce.
 *
 * @return string Base64 encoded state parameter
 */
function tkgadm_get_oauth_state() {
    $state_data = array(
        'return_url' => admin_url('admin.php?page=tkgad-google-ads'),
        'nonce' => wp_create_nonce('tkgadm_oauth_state'),
        'timestamp' => time()
    );

    return base64_encode(json_encode($state_data));
}

/**
 * Verify OAuth State Parameter
 *
 * @param string $state Base64 encoded state parameter
 * @return bool True if valid
 */
function tkgadm_verify_oauth_state($state) {
    $state_data = json_decode(base64_decode($state), true);

    if (!$state_data || !isset($state_data['nonce']) || !isset($state_data['timestamp'])) {
        return false;
    }

    // Verify nonce
    if (!wp_verify_nonce($state_data['nonce'], 'tkgadm_oauth_state')) {
        return false;
    }

    // Check if state is not too old (1 hour max)
    if ((time() - $state_data['timestamp']) > 3600) {
        return false;
    }

    return true;
}

/**
 * Check if using Central Service
 *
 * @return bool True if central service is configured
 */
function tkgadm_is_using_central_service() {
    // 1. Check URL (Priority: Constant > Option)
    if (defined('GADS_SERVICE_URL')) {
        $url = GADS_SERVICE_URL;
    } else {
        $url = get_option('tkgadm_central_service_url');
    }

    // 2. Check API Key (Priority: Constant > Option)
    $key = tkgadm_get_central_service_api_key();

    return !empty($url) && !empty($key);
}

/**
 * Get Central Service API key with backward compatibility for the redesigned settings page.
 */
function tkgadm_get_central_service_api_key() {
    if (defined('GADS_API_KEY')) {
        return GADS_API_KEY;
    }

    $key = get_option('tkgadm_central_service_api_key');
    if (empty($key)) {
        $key = get_option('tkgadm_gads_api_key');
    }

    return $key;
}

/**
 * Validate API Key with Central Service
 *
 * @param string $api_key API key to validate
 * @return bool|WP_Error True if valid, WP_Error if invalid
 */
function tkgadm_validate_api_key($api_key) {
    $status = tkgadm_license_status(true, $api_key);
    return $status['valid'] ? true : new WP_Error('invalid_key', $status['message']);
}


/**
 * Get credentials from Central Service
 *
 * @return array|WP_Error Credentials or error
 */
function tkgadm_get_central_service_credentials() {
    if (!tkgadm_license_is_valid()) { return new WP_Error('license_required', tkgadm_license_message()); }

    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $api_key = tkgadm_get_central_service_api_key();

    if (empty($service_url) || empty($api_key)) {
        return new WP_Error('missing_service_config', 'Central service not configured');
    }

    // Keep license and installation credentials in headers, never in URLs.
    $url = trailingslashit($service_url) . 'api?action=get_credentials';

    $response = wp_remote_get($url, array(
        'headers' => tkgadm_service_headers($api_key),
        'redirection' => 0,
        'timeout' => 15
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($data['success']) || !$data['success']) {
        return new WP_Error('service_error', isset($data['error']) ? $data['error'] : 'Failed to get credentials');
    }

    return $data['data'];
}

/**
 * Exchange authorization code for tokens via Central Service
 *
 * @param string $code Authorization code from Google
 * @return array|WP_Error Token data or error
 */
function tkgadm_exchange_code_via_service($code) {
    if (!tkgadm_license_is_valid()) { return new WP_Error('license_required', tkgadm_license_message()); }

    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $api_key = tkgadm_get_central_service_api_key();

    $url = trailingslashit($service_url) . 'api?action=exchange_code';

    $response = wp_remote_post($url, array(
        'headers' => tkgadm_service_headers($api_key, array('Content-Type' => 'application/json')),
        'redirection' => 0,
        'body' => json_encode(array('code' => $code)),
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($data['success']) || !$data['success']) {
        return new WP_Error('service_error', isset($data['error']) ? $data['error'] : 'Failed to exchange code');
    }

    return $data['data'];
}

/**
 * Get Access Token from Refresh Token
 */
function tkgadm_get_google_access_token() {
    if (!tkgadm_license_is_valid()) { return new WP_Error('license_required', tkgadm_license_message()); }

    // Note: When using central service, we don't need to get access token separately
    // The central service handles this internally during sync_ips

    $client_id = get_option('tkgadm_gads_client_id');
    $client_secret = get_option('tkgadm_gads_client_secret');
    $refresh_token = get_option('tkgadm_gads_refresh_token');

    if (!$client_id || !$client_secret || !$refresh_token) {
        return new WP_Error('missing_creds', 'Vui lòng nhập đầy đủ thông tin API trong Cấu hình Google Ads.');
    }

    $url = 'https://oauth2.googleapis.com/token';
    $body = array(
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
        'grant_type' => 'refresh_token'
    );

    $response = wp_remote_post($url, array(
        'body' => $body,
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($data['error'])) {
        return new WP_Error('api_error', 'Lỗi lấy Token: ' . ($data['error_description'] ?? $data['error']));
    }

    return $data['access_token'];
}

/**
 * Normalize an IP format accepted by the plugin to one accepted by Google Ads.
 * The UI supports x.x.x.*; Google Ads expects the equivalent CIDR block.
 */
function tkgadm_normalize_google_ads_ip($ip) {
    $clean_ip = trim((string) $ip);

    if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.\*$/', $clean_ip, $matches)) {
        $octets = array_map('intval', array_slice($matches, 1, 3));
        foreach ($octets as $octet) {
            if ($octet < 0 || $octet > 255) {
                return null;
            }
        }
        return implode('.', $octets) . '.0/24';
    }

    if (filter_var($clean_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return inet_ntop(inet_pton($clean_ip)); // canonical compressed form, same as Google returns
    }
    if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.0\/24$/', $clean_ip, $m) && max((int) $m[1], (int) $m[2], (int) $m[3]) <= 255) {
        return (int) $m[1] . '.' . (int) $m[2] . '.' . (int) $m[3] . '.0/24';
    }
    return filter_var($clean_ip, FILTER_VALIDATE_IP) ? $clean_ip : null;
}

/**
 * Sync IPs via Central Service
 *
 * @param array $ips_to_block List of IPs to block
 * @return array Result array with success status and message
 */
function tkgadm_sync_via_central_service($ips_to_block) {
    if (!tkgadm_license_is_valid()) { return array('success' => false, 'message' => tkgadm_license_message(), 'added' => array()); }
    // The Worker accepts single IPs and the "a.b.c.*" range form (every Worker version).
    $wire_to_norm = array();
    foreach (array_values($ips_to_block) as $ip) {
        $wire = preg_replace('/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.0\/24$/', '$1.*', (string) $ip);
        $norm = tkgadm_normalize_google_ads_ip($wire);
        if ($norm) {
            $wire_to_norm[$wire] = $norm;
        }
    }
    $ips_to_block = array_keys($wire_to_norm);
    if (!$ips_to_block) {
        return array('success' => true, 'message' => 'Không có IP hợp lệ để thêm.', 'added' => array());
    }

    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $api_key = tkgadm_get_central_service_api_key();
    $ids = tkgadm_get_gads_ids();
    $customer_id   = $ids['customer_id'];
    $manager_id    = $ids['manager_id'];
    $refresh_token = $ids['refresh_token'];


    if (!$customer_id || !preg_match('/^\d{10}$/', $customer_id) || !$refresh_token) {
        return ['success' => false, 'message' => 'Thiếu Customer ID hoặc chưa kết nối Google Ads.', 'added' => []];
    }

    if ($manager_id && !preg_match('/^\d{10}$/', $manager_id)) {
        return ['success' => false, 'message' => 'Manager ID không hợp lệ. Hãy nhập đủ 10 chữ số, có thể có dấu gạch ngang.', 'added' => []];
    }

    $url = trailingslashit($service_url) . 'api?action=sync_ips';

    $response = wp_remote_post($url, array(
        'headers' => tkgadm_service_headers($api_key, array('Content-Type' => 'application/json')),
        'redirection' => 0,
        'body' => json_encode(array(
            'customer_id' => $customer_id,
            'manager_id' => $manager_id,
            'refresh_token' => $refresh_token,
            'ips' => $ips_to_block
        )),
        'timeout' => 60
    ));

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Lỗi kết nối Central Service: ' . $response->get_error_message(), 'added' => []];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($data['success']) || !$data['success']) {
        $error_msg = isset($data['error']) ? $data['error'] : 'Unknown error from central service';
        return ['success' => false, 'message' => $error_msg, 'added' => []];
    }

    // Worker >= 4.4 reports which IPs were created (normalized, e.g. a.b.c.0/24);
    // older Workers only report success. Without a data block nothing was created.
    if (!isset($data['data']) || !is_array($data['data'])) {
        $added = array();
    } elseif (isset($data['data']['added']) && is_array($data['data']['added'])) {
        $reported = array_map('tkgadm_normalize_google_ads_ip', $data['data']['added']);
        $added = array_values(array_intersect(array_values($wire_to_norm), $reported));
    } else {
        $added = array_values($wire_to_norm);
    }
    return [
        'success' => empty($data['data']['partial_error']) || count($added) > 0,
        'message' => $data['data']['message'] ?? $data['message'] ?? 'Đã đồng bộ IP.',
        'added' => $added,
    ];
}

/**
 * List IPs currently blocked on Google Ads via Central Service.
 *
 * @return array|WP_Error Array of ['resource_name'=>string,'ip_address'=>string] or WP_Error
 */
function tkgadm_list_ips_via_central_service() {
    if (!tkgadm_license_is_valid()) { return new WP_Error('license_required', tkgadm_license_message()); }

    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $api_key = tkgadm_get_central_service_api_key();
    $ids = tkgadm_get_gads_ids();

    if (!$ids['customer_id'] || !$ids['refresh_token']) {
        return new WP_Error('missing_config', 'Thiếu Customer ID hoặc chưa kết nối Google Ads.');
    }

    $url = trailingslashit($service_url) . 'api?action=list_ips';

    $response = wp_remote_post($url, [
        'headers' => tkgadm_service_headers($api_key, array('Content-Type' => 'application/json')),
        'redirection' => 0,
        'body'    => json_encode([
            'customer_id'   => $ids['customer_id'],
            'manager_id'    => $ids['manager_id'],
            'refresh_token' => $ids['refresh_token'],
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($data['success']) || !$data['success']) {
        $msg = isset($data['error']) ? $data['error'] : 'Central Service không hỗ trợ chức năng list_ips.';
        return new WP_Error('service_error', $msg);
    }

    if (!isset($data['data']['ips']) || !is_array($data['data']['ips'])) {
        return new WP_Error('service_error', 'Central Service trả về danh sách IP không hợp lệ.');
    }
    return $data['data']['ips'];
}

/**
 * Remove IPs from Google Ads via Central Service.
 *
 * @param string[] $resource_names Resource names to remove
 * @return true|WP_Error
 */
function tkgadm_remove_ips_via_central_service($resource_names) {
    if (!tkgadm_license_is_valid()) { return new WP_Error('license_required', tkgadm_license_message()); }

    if (empty($resource_names)) return true;

    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $api_key = tkgadm_get_central_service_api_key();
    $ids = tkgadm_get_gads_ids();

    $url = trailingslashit($service_url) . 'api?action=remove_ips';

    $response = wp_remote_post($url, [
        'headers' => tkgadm_service_headers($api_key, array('Content-Type' => 'application/json')),
        'redirection' => 0,
        'body'    => json_encode([
            'customer_id'    => $ids['customer_id'],
            'manager_id'     => $ids['manager_id'],
            'refresh_token'  => $ids['refresh_token'],
            'resource_names' => array_values($resource_names),
        ]),
        'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($data['success']) || !$data['success']) {
        $msg = isset($data['error']) ? $data['error'] : 'Central Service không hỗ trợ chức năng remove_ips.';
        return new WP_Error('service_error', $msg);
    }

    return true;
}

/**
 * Handle Manual Sync from Admin Settings
 */
add_action('wp_ajax_tkgadm_manual_sync_gads', 'tkgadm_ajax_manual_sync_gads');
function tkgadm_ajax_manual_sync_gads() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer('tkgadm_sync_gads', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền truy cập.');
    }

    $result = tkgadm_reconcile_google_ads();
    tkgadm_record_sync_result($result);

    if ($result['success']) {
        wp_send_json_success(['message' => $result['message']]);
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * Register Site for Centralized Heartbeat
 */
function tkgadm_register_site_heartbeat($api_key = null) {
    if (!$api_key) {
        $api_key = tkgadm_get_central_service_api_key();
    }
    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');

    if (empty($api_key) || empty($service_url)) return;

    // Register URL
    $url = trailingslashit($service_url) . 'api?action=register_site';

    wp_remote_post($url, array(
        'headers' => tkgadm_service_headers($api_key, array('Content-Type' => 'application/json')),
        'redirection' => 0,
        'body' => json_encode(array('site_url' => tkgadm_license_site_url())),
        'timeout' => 5,
        'blocking' => false // Fire and forget
    ));
}
