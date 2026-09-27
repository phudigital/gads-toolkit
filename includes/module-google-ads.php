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
    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');

    if (empty($service_url)) {
        $service_url = 'https://pdl.vn/gads-toolkit/';
    }

    // Try to get credentials with this key (health check)
    $url = add_query_arg('api_key', $api_key, trailingslashit($service_url) . 'api/?action=health');

    $response = wp_remote_get($url, array(
        'timeout' => 10
    ));

    if (is_wp_error($response)) {
        return new WP_Error('connection_error', 'Không thể kết nối đến pdl.vn. Vui lòng kiểm tra kết nối internet.');
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 401 || $code === 403) {
        return new WP_Error('invalid_key', 'API Key không hợp lệ hoặc đã hết hạn. Vui lòng liên hệ phu@pdl.vn để gia hạn.');
    }

    if ($code !== 200 || !isset($data['success']) || !$data['success']) {
        return new WP_Error('validation_failed', 'Không thể xác thực API Key. Vui lòng thử lại.');
    }

    return true;
}


/**
 * Get credentials from Central Service
 *
 * @return array|WP_Error Credentials or error
 */
function tkgadm_get_central_service_credentials() {
    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $api_key = tkgadm_get_central_service_api_key();

    if (empty($service_url) || empty($api_key)) {
        return new WP_Error('missing_service_config', 'Central service not configured');
    }

    // Send API Key via URL parameter for better server compatibility
    $url = add_query_arg('api_key', $api_key, trailingslashit($service_url) . 'api/?action=get_credentials');

    $response = wp_remote_get($url, array(
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
    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $api_key = tkgadm_get_central_service_api_key();

    $url = add_query_arg('api_key', $api_key, trailingslashit($service_url) . 'api/?action=exchange_code');

    $response = wp_remote_post($url, array(
        'headers' => array(
            'Content-Type' => 'application/json'
        ),
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

    return filter_var($clean_ip, FILTER_VALIDATE_IP) ? $clean_ip : null;
}

/**
 * Sync IPs to Google Ads (Account Level)
 */
function tkgadm_sync_ip_to_google_ads($ips_to_block, $skip_auto_rotate = false) {
    if (empty($ips_to_block)) {
        return ['success' => true, 'message' => 'Không có IP nào cần đồng bộ.'];
    }

    // ── PRE-EMPTIVE AUTO CLEANUP (Dọn dẹp DB local nếu vượt ngưỡng) ──
    $ar_enabled = get_option('tkgadm_ar_enabled', '0');
    if (!$skip_auto_rotate && $ar_enabled === '1') {
        $ar_threshold = (int) get_option('tkgadm_ar_threshold', 450);
        $ar_amount    = (int) get_option('tkgadm_ar_amount', 50);

        global $wpdb;
        $blocked_table = $wpdb->prefix . 'gads_toolkit_blocked';
        $local_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $blocked_table");

        if ($local_count >= $ar_threshold) {
            // 1. Tìm và xóa IP cũ nhất trong DB local
            $oldest_ips = $wpdb->get_col($wpdb->prepare("SELECT ip_address FROM $blocked_table ORDER BY blocked_time ASC LIMIT %d", $ar_amount));
            if (!empty($oldest_ips)) {
                foreach ($oldest_ips as $ip) {
                    $ip_key = preg_replace('/^(\d+\.\d+\.\d+)\.0\/24$/', '$1.*', $ip);
                    $wpdb->delete($blocked_table, ['ip_address' => $ip]);
                    $wpdb->delete($blocked_table, ['ip_address' => $ip_key]);
                }
            }
            
            // 2. Thực hiện Full Sync nếu Auto-Sync đang bật
            if (get_option('tkgadm_gads_auto_sync', '1') === '1') {
                tkgadm_do_full_sync_google_ads();
                return ['success' => true, 'message' => "Đã kích hoạt Full Sync do danh sách vượt ngưỡng {$ar_threshold}."];
            }
        }
    }

    // Nếu không phải là gọi thủ công từ Full Sync ($skip_auto_rotate) và Đồng bộ tự động đang tắt -> Bỏ qua
    if (!$skip_auto_rotate && get_option('tkgadm_gads_auto_sync', '1') !== '1') {
        return ['success' => true, 'message' => 'Đồng bộ tự động đang tắt.'];
    }

    if (tkgadm_get_gads_connection_mode() === 'central') {
        return tkgadm_sync_via_central_service($ips_to_block);
    }

    // Original direct API method
    $access_token = tkgadm_get_google_access_token();
    if (is_wp_error($access_token)) {
        return ['success' => false, 'message' => $access_token->get_error_message()];
    }

    $ids = tkgadm_get_gads_ids();
    $customer_id = $ids['customer_id'];
    $developer_token = $ids['developer_token'];

    if (!$customer_id || !$developer_token) {
        return ['success' => false, 'message' => 'Thiếu Customer ID hoặc Developer Token.'];
    }
    // ───────────────────────────────────────────────────────────────────────────────

    // 1. Prepare operations & Validate IPs
    $operations = [];
    $skipped_count = 0;

    foreach ($ips_to_block as $ip) {
        $clean_ip = tkgadm_normalize_google_ads_ip($ip);

        if ($clean_ip) {
            $operations[] = [
                'create' => [
                    'ip_block' => [
                        'ip_address' => $clean_ip
                    ]
                ]
            ];
        } else {
            $skipped_count++;
        }
    }

    if (empty($operations)) {
        return [
            'success' => true,
            'message' => $skipped_count > 0
                ? "Không có IP hợp lệ để đồng bộ ($skipped_count IP bị bỏ qua do sai định dạng)."
                : "Danh sách IP trống."
        ];
    }

    // Google Ads API Endpoint
    $api_version = 'v25';
    $url = "https://googleads.googleapis.com/{$api_version}/customers/{$customer_id}/customerNegativeCriteria:mutate";

    $manager_id = $ids['manager_id'];

    $headers = array(
        'Authorization' => 'Bearer ' . $access_token,
        'developer-token' => $developer_token,
        'Content-Type' => 'application/json'
    );

    if (!empty($manager_id)) {
        $headers['login-customer-id'] = $manager_id;
    }

    $payload = [
        'operations' => $operations,
        'partialFailure' => true,
        'validateOnly' => false
    ];

    $response = wp_remote_post($url, array(
        'headers' => $headers,
        'body' => json_encode($payload),
        'timeout' => 60
    ));

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Lỗi kết nối Google: ' . $response->get_error_message()];
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    // Helper kiểm tra lỗi đầy IP
    $is_quota_error = false;
    $error_str = '';

    if ($response_code !== 200) {
        $error_str = isset($body['error']['message']) ? $body['error']['message'] : '';
        if (isset($body['error']['details'])) {
            $error_str .= json_encode($body['error']['details'], JSON_UNESCAPED_UNICODE);
        }
    } elseif (isset($body['partialFailureError'])) {
        $error_str = json_encode($body['partialFailureError'], JSON_UNESCAPED_UNICODE);
    }

    if ($error_str !== '' && (stripos($error_str, 'LIMIT_EXCEEDED') !== false || stripos($error_str, 'TOO_MANY') !== false || stripos($error_str, 'RESOURCE_EXHAUSTED') !== false)) {
        $is_quota_error = true;
    }

    // Nếu bị lỗi đầy quota -> Tự động xóa bớt IP cũ và thử lại
    if ($is_quota_error && function_exists('tkgadm_smart_rotate_google_ads_ips')) {
        // Cần giải phóng: số IP đang định thêm + 50 slot dư phòng hờ
        $slots_to_free = count($ips_to_block) + 50;
        $rotate_res = tkgadm_smart_rotate_google_ads_ips($slots_to_free);
        
        if (!empty($rotate_res['freed']) && $rotate_res['freed'] > 0) {
            // Thử đồng bộ lại lần 2
            $retry = wp_remote_post($url, array(
                'headers' => $headers,
                'body' => json_encode($payload),
                'timeout' => 60
            ));
            
            if (!is_wp_error($retry)) {
                $retry_code = wp_remote_retrieve_response_code($retry);
                $retry_body = json_decode(wp_remote_retrieve_body($retry), true);
                
                if ($retry_code === 200 && !isset($retry_body['partialFailureError'])) {
                    $success_count = isset($retry_body['results']) ? count($retry_body['results']) : 0;
                    return [
                        'success' => true,
                        'message' => "Đã tự động xóa {$rotate_res['freed']} IP cũ (do đầy 500 IP) và đồng bộ thành công $success_count IP mới."
                    ];
                }
            }
        }
    }

    // Xử lý lỗi bình thường nếu không phải quota error hoặc rotate thất bại
    if ($response_code !== 200) {
        $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Lỗi không xác định.';
        if (isset($body['error']['details'])) {
            $details = json_encode($body['error']['details'], JSON_UNESCAPED_UNICODE);
            $error_msg .= " (Details: $details)";
        }
        return ['success' => false, 'message' => "Google API Error ($response_code): $error_msg"];
    }

    // 200 OK - Check results
    $success_count = 0;
    if (isset($body['results'])) {
        // partialFailureError có thể làm kết quả rỗng
        foreach ($body['results'] as $res) {
            if (!empty($res)) $success_count++;
        }
    }

    $msg = "Đã đồng bộ thành công $success_count IP";
    if ($skipped_count > 0) {
        $msg .= " (Bỏ qua $skipped_count IP sai định dạng)";
    }
    if (isset($body['partialFailureError'])) {
        $msg .= " (Kèm một số lỗi: " . $body['partialFailureError']['message'] . ")";
    }
    $msg .= ".";

    return ['success' => true, 'message' => $msg];
}

/**
 * Sync IPs via Central Service
 *
 * @param array $ips_to_block List of IPs to block
 * @return array Result array with success status and message
 */
function tkgadm_sync_via_central_service($ips_to_block) {
    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $api_key = tkgadm_get_central_service_api_key();
    $ids = tkgadm_get_gads_ids();
    $customer_id   = $ids['customer_id'];
    $manager_id    = $ids['manager_id'];
    $refresh_token = $ids['refresh_token'];


    if (!$customer_id || !preg_match('/^\d{10}$/', $customer_id) || !$refresh_token) {
        return ['success' => false, 'message' => 'Thiếu Customer ID hoặc chưa kết nối Google Ads.'];
    }

    if ($manager_id && !preg_match('/^\d{10}$/', $manager_id)) {
        return ['success' => false, 'message' => 'Manager ID không hợp lệ. Hãy nhập đủ 10 chữ số, có thể có dấu gạch ngang.'];
    }

    $url = add_query_arg('api_key', $api_key, trailingslashit($service_url) . 'api/?action=sync_ips');

    $response = wp_remote_post($url, array(
        'headers' => array(
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode(array(
            'customer_id' => $customer_id,
            'manager_id' => $manager_id,
            'refresh_token' => $refresh_token,
            'ips' => $ips_to_block
        )),
        'timeout' => 60
    ));

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Lỗi kết nối Central Service: ' . $response->get_error_message()];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($data['success']) || !$data['success']) {
        $error_msg = isset($data['error']) ? $data['error'] : 'Unknown error from central service';
        return ['success' => false, 'message' => $error_msg];
    }

    return [
        'success' => true,
        'message' => $data['data']['message'] ?? $data['message'] ?? 'Đã đồng bộ IP.'
    ];
}

/**
 * List IPs currently blocked on Google Ads via Central Service.
 *
 * @return array|WP_Error Array of ['resource_name'=>string,'ip_address'=>string] or WP_Error
 */
function tkgadm_list_ips_via_central_service() {
    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $api_key = tkgadm_get_central_service_api_key();
    $ids = tkgadm_get_gads_ids();

    if (!$ids['customer_id'] || !$ids['refresh_token']) {
        return new WP_Error('missing_config', 'Thiếu Customer ID hoặc chưa kết nối Google Ads.');
    }

    $url = add_query_arg('api_key', $api_key, trailingslashit($service_url) . 'api/?action=list_ips');

    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
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
    if (empty($resource_names)) return true;

    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $api_key = tkgadm_get_central_service_api_key();
    $ids = tkgadm_get_gads_ids();

    $url = add_query_arg('api_key', $api_key, trailingslashit($service_url) . 'api/?action=remove_ips');

    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
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
 * Main Sync Function (Called by Cron or Manual)
 */
function tkgadm_do_sync_process() {
    global $wpdb;
    $blocking_table = $wpdb->prefix . 'gads_toolkit_blocked';

    // Lấy giới hạn IP từ cài đặt Smart Rotation (mặc định 500)
    $max_ips = (int) get_option('tkgadm_rotation_max_ips', 500);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $blocked_ips = $wpdb->get_col("SELECT ip_address FROM $blocking_table ORDER BY blocked_time DESC LIMIT $max_ips");

    // The blocked table has a unique IP constraint, but de-duplicate defensively
    // so a manual upload can never send the same address more than once.
    $blocked_ips = array_values(array_unique(array_filter(array_map('trim', $blocked_ips))));

    if (empty($blocked_ips)) {
        return ['success' => true, 'message' => 'Danh sách chặn trống.'];
    }

    // === WHITELIST FILTER: Loại bỏ IP trong whitelist trước khi sync ===
    if (function_exists('tkgadm_is_ip_whitelisted')) {
        $before_count = count($blocked_ips);
        $blocked_ips  = array_values(array_filter($blocked_ips, function($ip) {
            return !tkgadm_is_ip_whitelisted($ip);
        }));
        $skipped_wl = $before_count - count($blocked_ips);
        if ($skipped_wl > 0 && empty($blocked_ips)) {
            return ['success' => true, 'message' => "Toàn bộ $skipped_wl IP đều nằm trong whitelist, không có IP nào cần đồng bộ."];
        }
    }

    return tkgadm_sync_ip_to_google_ads($blocked_ips);
}


/**
 * ============================================================================
 * 2. ADMIN UI (SETTINGS PAGE)
 * ============================================================================
 */

function tkgadm_render_google_ads_page() {
    // 1. Handle OAuth Callback
    // Only process if NOT saving settings to avoid double-processing expired codes
    if (isset($_GET['code']) && !isset($_POST['tkgadm_gads_save'])) {
        $code = sanitize_text_field($_GET['code']);

        // Always use central service in client mode (or fallback to error)
        if (tkgadm_is_using_central_service()) {
            $tokens = tkgadm_exchange_code_via_service($code);

            if (is_wp_error($tokens)) {
                echo '<div class="notice notice-error"><p>Lỗi kết nối Central Service: ' . $tokens->get_error_message() . '</p></div>';
            } else {
                if (isset($tokens['refresh_token'])) {
                    update_option('tkgadm_gads_refresh_token', $tokens['refresh_token']);

                    echo '<div class="notice notice-success is-dismissible"><p>✅ Đã kết nối thành công với tài khoản Google! (via Central Service)</p></div>';

                    // Clean URL to prevent re-submission of auth code
                    echo '<script>
                        if (window.history.replaceState) {
                            window.history.replaceState(null, null, "' . admin_url('admin.php?page=tkgad-google-ads') . '");
                        }
                    </script>';
                } else {
                    echo '<div class="notice notice-error"><p>Không nhận được refresh token từ Central Service.</p></div>';
                }
            }
        } else {
            echo '<div class="notice notice-error"><p>Vui lòng nhập <strong>Secure API Key</strong> và lưu lại để kích hoạt kết nối.</p></div>';
        }
    }

    // Handle OAuth Error from central redirect
    if (isset($_GET['oauth_error'])) {
        $error = sanitize_text_field($_GET['oauth_error']);
        $error_desc = isset($_GET['oauth_error_description']) ? sanitize_text_field($_GET['oauth_error_description']) : 'Unknown error';
        echo '<div class="notice notice-error"><p>❌ Lỗi OAuth: ' . esc_html($error_desc) . '</p></div>';
    }

    // Handle Disconnect OAuth
    if (isset($_POST['tkgadm_disconnect_oauth']) && check_admin_referer('tkgadm_disconnect_oauth')) {
        delete_option('tkgadm_gads_refresh_token');
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã hủy kết nối Google Ads.</p></div>';
    }

    // 2. Save Settings
    if (isset($_POST['tkgadm_gads_save']) && check_admin_referer('tkgadm_gads_options')) {
        $validation_error = null;

        if (!defined('GADS_API_KEY') && isset($_POST['api_key'])) {
            $new_api_key = sanitize_text_field($_POST['api_key']);

            // Validate API Key with Central Service
            if (!empty($new_api_key)) {
                $validation = tkgadm_validate_api_key($new_api_key);

                if (is_wp_error($validation)) {
                    $validation_error = $validation->get_error_message();
                    echo '<div class="notice notice-error is-dismissible"><p>❌ ' . esc_html($validation_error) . '</p></div>';
                } else {
                    update_option('tkgadm_central_service_api_key', $new_api_key);
                    // Register Heartbeat immediately when saving key
                    tkgadm_register_site_heartbeat($new_api_key);
                }
            }
        } else {
             // If key is hardcoded or unchanged, still try to register using current key
             tkgadm_register_site_heartbeat();
        }

        // Only save other settings if API key validation passed (or wasn't changed)
        if (!$validation_error) {

        $raw_cid = sanitize_text_field($_POST['customer_id']);
        $raw_mid = sanitize_text_field($_POST['manager_id']);

        if ($raw_cid !== '') {
            $check_cid = tkgadm_validate_gads_id_format($raw_cid, 'Customer ID');
            if (is_wp_error($check_cid)) {
                $validation_error = $check_cid->get_error_message();
                echo '<div class="notice notice-error is-dismissible"><p>❌ ' . esc_html($validation_error) . '</p></div>';
            }
        }
        if (!$validation_error && $raw_mid !== '') {
            $check_mid = tkgadm_validate_gads_id_format($raw_mid, 'Manager ID (MCC)');
            if (is_wp_error($check_mid)) {
                $validation_error = $check_mid->get_error_message();
                echo '<div class="notice notice-error is-dismissible"><p>❌ ' . esc_html($validation_error) . '</p></div>';
            }
        }

        if (!$validation_error) {
            update_option('tkgadm_gads_customer_id', $raw_cid);
            update_option('tkgadm_gads_manager_id', $raw_mid);
        }

        $auto_sync = isset($_POST['auto_sync']) ? 1 : 0;
        update_option('tkgadm_auto_sync_hourly', $auto_sync);

        $sync_on_block = isset($_POST['sync_on_block']) ? 1 : 0;
        update_option('tkgadm_auto_sync_on_block', $sync_on_block);

        // Handle Cron Schedule
        $timestamp = wp_next_scheduled('tkgadm_hourly_sync_event');
        if ($auto_sync && !$timestamp) {
            wp_schedule_event(time(), 'hourly', 'tkgadm_hourly_sync_event');
        } elseif (!$auto_sync && $timestamp) {
            wp_unschedule_event($timestamp, 'tkgadm_hourly_sync_event');
        }

        // Save Auto Block Settings
        $auto_block = isset($_POST['tkgadm_auto_block_enabled']) ? 1 : 0;
        update_option('tkgadm_auto_block_enabled', $auto_block);

        $rules = [];
        if (isset($_POST['rules']) && is_array($_POST['rules'])) {
            foreach ($_POST['rules'] as $rule) {
                if (!empty($rule['limit']) && !empty($rule['duration'])) {
                        $rules[] = [
                            'limit' => intval($rule['limit']),
                            'duration' => intval($rule['duration']),
                            'unit' => sanitize_text_field($rule['unit'])
                        ];
                }
            }
        }
        update_option('tkgadm_auto_block_rules', array_values($rules));

        // Handle Auto Block Cron
        $cron_hook_block = 'tkgadm_auto_block_scan_event';
        $blocked_timestamp = wp_next_scheduled($cron_hook_block);
        if ($auto_block && !$blocked_timestamp) {
            wp_schedule_event(time(), 'tkgadm_15_minutes', $cron_hook_block);
        } elseif (!$auto_block && $blocked_timestamp) {
            wp_unschedule_event($blocked_timestamp, $cron_hook_block);
        }
        }

        if (!$validation_error) {
            echo '<div class="notice notice-success is-dismissible"><p>Đã lưu cài đặt.</p></div>';
        }
    }

    // 3. Prepare Data
    $refresh_token = get_option('tkgadm_gads_refresh_token');
    $customer_id = get_option('tkgadm_gads_customer_id');
    $manager_id = get_option('tkgadm_gads_manager_id');
    $auto_sync = get_option('tkgadm_auto_sync_hourly');
    $sync_on_block = get_option('tkgadm_auto_sync_on_block');

    // Get API Key value
    if (defined('GADS_API_KEY')) {
        $api_key_readonly = true;
    } else {
        $api_key_readonly = false;
    }
    $api_key = tkgadm_get_central_service_api_key();

    // Auth URL Logic for Client
    $auth_url = '';
    $connect_error = null;

    if (tkgadm_is_using_central_service()) {
        $credentials = tkgadm_get_central_service_credentials();
        if (!is_wp_error($credentials)) {
            $client_id = $credentials['client_id'];
            $redirect_uri = $credentials['oauth_redirect_uri'];

            // Generate State for security
            $state = tkgadm_get_oauth_state();

            $params = array(
                'client_id' => $client_id,
                'redirect_uri' => $redirect_uri,
                'response_type' => 'code',
                'scope' => 'https://www.googleapis.com/auth/adwords',
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => $state
            );
            $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        } else {
            $connect_error = $credentials->get_error_message();
        }
    } else {
         $connect_error = 'Vui lòng nhập API Key để kết nối.';
    }

    // Render HTML
    ?>
    <div class="wrap">
        <div class="tkgadm-wrap">
            <div class="tkgadm-header">
                <h1>🔌 Cấu hình Đồng Bộ Google Ads</h1>
                <p style="color: #666; margin-top: 10px;">Kết nối API để tự động đẩy IP bị chặn vào danh sách loại trừ cấp tài khoản Google Ads.</p>
            </div>

            <div class="tkgadm-main-content" style="display: grid; grid-template-columns: 1fr 350px; gap: 20px;">

                <!-- Settings Form -->
                <div>
                    <form method="post" action="" style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #ddd;">
                        <?php wp_nonce_field('tkgadm_gads_options'); ?>

                        <!-- API Settings & Connection Combined -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <h2 style="margin: 0;">🔑 Thiết lập API</h2>
                                    <?php if ($api_key): ?>
                                        <button type="button" id="edit-api-btn" class="button button-small">✏️ Chỉnh sửa</button>
                                    <?php endif; ?>
                                </div>

                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Secure API Key</label>
                                    <input type="password" name="api_key" id="api_key_field" value="<?php echo esc_attr($api_key); ?>" class="widefat api-input" style="padding: 6px; font-size: 13px; background-color: #f0f0f1;" <?php echo ($api_key || $api_key_readonly) ? 'readonly' : ''; ?>>
                                    <p class="description" style="margin-top: 3px; font-size: 12px;"><?php echo $api_key_readonly ? 'Key được cấu hình cứng trong code.' : 'Liên hệ <a href="mailto:phu@pdl.vn">phu@pdl.vn</a> để nhận API key.'; ?></p>
                                </div>

                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Customer ID</label>
                                    <input type="text" name="customer_id" id="customer_id_field" value="<?php echo esc_attr($customer_id); ?>" class="widefat api-input" placeholder="xxx-xxx-xxxx" style="padding: 6px; font-size: 13px; background-color: <?php echo $api_key ? '#f0f0f1' : '#fff'; ?>;" <?php echo $api_key ? 'readonly' : ''; ?>>
                                    <p class="description" style="margin-top: 3px; font-size: 12px;">ID tài khoản Google Ads</p>
                                </div>

                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Manager ID (MCC)</label>
                                    <input type="text" name="manager_id" id="manager_id_field" value="<?php echo esc_attr($manager_id); ?>" class="widefat api-input" placeholder="xxx-xxx-xxxx" style="padding: 6px; font-size: 13px; background-color: <?php echo $api_key ? '#f0f0f1' : '#fff'; ?>;" <?php echo $api_key ? 'readonly' : ''; ?>>
                                    <p class="description" style="margin-top: 3px; font-size: 12px;">Chỉ cần nếu dùng MCC</p>
                                </div>
                            </div>

                            <div>
                                <h2 style="margin: 0 0 15px 0;">🔗 Kết nối Google</h2>

                                <?php if ($refresh_token): ?>
                                    <div style="padding: 12px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px; color: #155724; margin-bottom: 10px; font-size: 13px;">
                                        <strong>✅ Đã kết nối thành công!</strong>
                                    </div>
                                    <?php if ($auth_url): ?>
                                        <a href="<?php echo esc_url($auth_url); ?>" class="button button-secondary" style="font-size: 13px; padding: 6px 12px;">Kết nối lại</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($connect_error): ?>
                                        <div class="notice notice-error inline" style="margin: 0 0 10px 0; padding: 8px; font-size: 13px;"><p style="margin: 0;"><?php echo esc_html($connect_error); ?></p></div>
                                        <?php if (!$api_key): ?>
                                            <p style="color: #666; font-size: 12px;">Nhập API Key ở bên và lưu lại</p>
                                        <?php endif; ?>
                                    <?php elseif ($auth_url): ?>
                                        <div style="padding: 12px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 5px; margin-bottom: 10px;">
                                            <p style="margin: 0; font-size: 12px; color: #555;">Cấp quyền để đồng bộ IP</p>
                                        </div>
                                        <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary" style="font-size: 13px; padding: 8px 16px;">
                                            👉 Kết nối Google
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div id="save-actions" style="<?php echo $api_key ? 'display: none;' : ''; ?>">
                            <button type="submit" name="tkgadm_gads_save" class="button button-primary" style="margin-bottom: 20px;">💾 Lưu Thông Tin</button>
                            <?php if ($api_key): ?>
                                <button type="button" id="cancel-edit-btn" class="button button-secondary" style="margin-bottom: 20px; margin-left: 10px;">Hủy bỏ</button>
                            <?php endif; ?>
                        </div>

                        <script>
                        jQuery(document).ready(function($) {
                            // Edit Mode Toggle
                            $('#edit-api-btn').on('click', function() {
                                $('.api-input').prop('readonly', false).css('background-color', '#fff');
                                <?php if ($api_key_readonly): ?>
                                    $('#api_key_field').prop('readonly', true).css('background-color', '#f0f0f1'); // Keep hardcoded key readonly
                                <?php endif; ?>
                                $('#save-actions').slideDown();
                                $(this).hide();
                            });

                            // Cancel Edit
                            $('#cancel-edit-btn').on('click', function() {
                                $('.api-input').prop('readonly', true).css('background-color', '#f0f0f1');
                                $('#save-actions').slideUp();
                                $('#edit-api-btn').show();
                                // Reset values to original (optional but good UX)
                                location.reload();
                            });
                        });
                        </script>

                        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">

                        <!-- Sync Options & Auto Block Combined -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div>
                                <h3 style="margin: 0 0 12px 0; font-size: 15px;">⚙️ Tùy chọn Đồng bộ</h3>

                                <div style="margin-bottom: 10px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                                        <span class="tkgadm-switch">
                                            <input type="checkbox" name="auto_sync" value="1" class="tkgadm-switch__input" aria-label="Bật tự động đồng bộ mỗi giờ" <?php checked($auto_sync, 1); ?>>
                                            <span class="tkgadm-switch__track" aria-hidden="true"></span>
                                        </span>
                                        <span>Tự động mỗi giờ (Cron)</span>
                                    </label>
                                </div>

                                <div style="margin-bottom: 10px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                                        <span class="tkgadm-switch">
                                            <input type="checkbox" name="sync_on_block" value="1" class="tkgadm-switch__input" aria-label="Bật đồng bộ ngay khi chặn" <?php checked($sync_on_block, 1); ?>>
                                            <span class="tkgadm-switch__track" aria-hidden="true"></span>
                                        </span>
                                        <span>Đồng bộ ngay khi chặn</span>
                                    </label>
                                </div>
                            </div>

                            <div>
                                <?php
                                $auto_block_enabled = get_option('tkgadm_auto_block_enabled');
                                $auto_block_rules = get_option('tkgadm_auto_block_rules', []);
                                if (!is_array($auto_block_rules)) $auto_block_rules = [];
                                $is_connected = !empty($refresh_token);
                                ?>

                                <h3 style="margin: 0 0 12px 0; font-size: 15px; display: flex; align-items: center; gap: 8px;">
                                    🛡️ Chặn Tự Động
                                    <?php if ($is_connected): ?>
                                        <?php
                                            $cron_active = wp_next_scheduled('tkgadm_auto_block_scan_event');
                                            if ($cron_active && $auto_block_enabled):
                                        ?>
                                            <span style="font-size: 11px; background: #d4edda; color: #155724; padding: 2px 6px; border-radius: 3px; font-weight: normal;" title="Lần chạy tiếp: <?php echo wp_date('H:i:s d/m', $cron_active); ?>">✅ Hoạt động</span>
                                        <?php elseif ($auto_block_enabled): ?>
                                            <span style="font-size: 11px; background: #fff3cd; color: #856404; padding: 2px 6px; border-radius: 3px; font-weight: normal;">⚠️ Lưu để kích hoạt</span>
                                        <?php else: ?>
                                            <span style="font-size: 11px; background: #f8f9fa; color: #666; padding: 2px 6px; border-radius: 3px; font-weight: normal;">❌ Tắt</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </h3>

                                <?php if ($is_connected): ?>
                                    <div style="margin-bottom: 10px;">
                                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                                            <span class="tkgadm-switch">
                                                <input type="checkbox" name="tkgadm_auto_block_enabled" value="1" class="tkgadm-switch__input" aria-label="Bật chặn theo hành vi" <?php checked($auto_block_enabled, 1); ?>>
                                                <span class="tkgadm-switch__track" aria-hidden="true"></span>
                                            </span>
                                            <span>Kích hoạt chặn theo hành vi</span>
                                        </label>
                                    </div>
                                <?php else: ?>
                                    <p style="font-size: 12px; color: #856404; background: #fff3cd; padding: 8px; border-radius: 4px; margin: 0;">Cần kết nối Google Ads</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($is_connected && $auto_block_enabled): ?>
                            <div id="tkgadm-auto-block-rules" style="background: #f9f9f9; padding: 15px; border-radius: 5px; border: 1px solid #ddd; margin-top: 15px;">
                                <label style="display: block; font-weight: 500; margin-bottom: 10px; font-size: 13px;">📋 Quy tắc chặn:</label>

                                <div id="rules-container">
                                    <?php foreach ($auto_block_rules as $index => $rule): ?>
                                        <div class="rule-row" style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px; font-size: 13px;">
                                            <span>Đạt</span>
                                            <input type="number" name="rules[<?php echo $index; ?>][limit]" value="<?php echo esc_attr($rule['limit']); ?>" style="width: 60px; padding: 4px;" min="1" required>
                                            <span>click trong</span>
                                            <input type="number" name="rules[<?php echo $index; ?>][duration]" value="<?php echo esc_attr($rule['duration']); ?>" style="width: 60px; padding: 4px;" min="1" required>
                                            <select name="rules[<?php echo $index; ?>][unit]" style="width: 80px; padding: 4px;">
                                                <option value="hour" <?php selected($rule['unit'], 'hour'); ?>>Giờ</option>
                                                <option value="day" <?php selected($rule['unit'], 'day'); ?>>Ngày</option>
                                                <option value="week" <?php selected($rule['unit'], 'week'); ?>>Tuần</option>
                                            </select>
                                            <button type="button" class="button remove-rule" style="color: #a00; border-color: #a00; padding: 2px 8px; font-size: 12px;">Xóa</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <button type="button" id="add-rule-btn" class="button button-small" style="font-size: 12px; padding: 4px 10px;">+ Thêm điều kiện</button>
                            </div>

                            <script>
                            jQuery(document).ready(function($) {
                                // Add Rule
                                $('#add-rule-btn').on('click', function() {
                                    const index = $('#rules-container .rule-row').length + Math.floor(Math.random() * 1000);
                                    const row = `
                                        <div class="rule-row" style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px; font-size: 13px;">
                                            <span>Đạt</span>
                                            <input type="number" name="rules[${index}][limit]" value="3" style="width: 60px; padding: 4px;" min="1" required>
                                            <span>click trong</span>
                                            <input type="number" name="rules[${index}][duration]" value="1" style="width: 60px; padding: 4px;" min="1" required>
                                            <select name="rules[${index}][unit]" style="width: 80px; padding: 4px;">
                                                <option value="hour">Giờ</option>
                                                <option value="day">Ngày</option>
                                                <option value="week">Tuần</option>
                                            </select>
                                            <button type="button" class="button remove-rule" style="color: #a00; border-color: #a00; padding: 2px 8px; font-size: 12px;">Xóa</button>
                                        </div>
                                    `;
                                    $('#rules-container').append(row);
                                });

                                // Remove Rule
                                $(document).on('click', '.remove-rule', function() {
                                    $(this).closest('.rule-row').remove();
                                });
                            });
                            </script>
                        <?php endif; ?>

                        <div style="margin-top: 20px;">
                            <button type="submit" name="tkgadm_gads_save" class="button button-secondary">💾 Cập nhật tùy chọn</button>
                        </div>
                    </form>
                </div>

                <!-- Sync Action & Status Sidebar -->
                <div>
                    <div style="background: white; padding: 20px; border-radius: 10px; border: 1px solid #ddd; position: sticky; top: 50px;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px;">🚀 Thao tác nhanh</h3>

                        <button id="manual-sync-btn" class="button button-primary" style="width: 100%; text-align: center; margin-bottom: 12px; padding: 8px; font-size: 13px;" <?php disabled(!$refresh_token); ?>>
                            ☁️ Upload IP lên Google Ads
                        </button>

                        <?php if (!$refresh_token): ?>
                            <p style="color: #d63638; font-size: 12px; margin: 0 0 15px 0;">* Cần kết nối Google trước</p>
                        <?php endif; ?>

                        <div id="sync-status" style="display: none; padding: 12px; background: #f8f9fa; border-radius: 5px; border: 1px solid #eee; margin-bottom: 15px;">
                            <div id="sync-spinner" style="display: none; text-align: center; font-size: 13px;">
                                <span class="spinner is-active" style="float: none;"></span> Đang xử lý...
                            </div>
                            <div id="sync-message" style="font-size: 13px;"></div>
                        </div>

                        <hr style="margin: 15px 0; border: 0; border-top: 1px solid #eee;">

                        <h4 style="margin: 0 0 10px 0; font-size: 14px;">🔍 Trạng thái gần nhất</h4>
                        <?php
                            $last_sync = get_option('tkgadm_last_sync_time');
                            $last_msg = get_option('tkgadm_last_sync_message');
                        ?>
                        <p style="font-size: 12px; margin: 0 0 8px 0;"><strong>Lần chạy cuối:</strong><br><?php echo $last_sync ? date_i18n('d/m/Y H:i:s', $last_sync) : 'Chưa chạy'; ?></p>
                        <p style="font-size: 12px; margin: 0;"><strong>Kết quả:</strong><br><?php echo $last_msg ? esc_html($last_msg) : '---'; ?></p>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#manual-sync-btn').on('click', function(e) {
            e.preventDefault();
            const btn = $(this);
            const statusBox = $('#sync-status');
            const spinner = $('#sync-spinner');
            const msg = $('#sync-message');

            if (confirm('Bạn có chắc muốn đồng bộ danh sách IP lên Google Ads ngay không?')) {
                btn.prop('disabled', true);
                statusBox.show();
                spinner.show();
                msg.text('');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'tkgadm_manual_sync_gads',
                        nonce: '<?php echo wp_create_nonce("tkgadm_sync_gads"); ?>'
                    },
                    success: function(response) {
                        spinner.hide();
                        btn.prop('disabled', false);

                        if (response.success) {
                            msg.html('<span style="color: green;">✅ ' + response.data.message + '</span>');
                        } else {
                            msg.html('<span style="color: red;">❌ ' + response.data + '</span>');
                        }
                    },
                    error: function() {
                        spinner.hide();
                        btn.prop('disabled', false);
                        msg.html('<span style="color: red;">❌ Lỗi kết nối Server.</span>');
                    }
                });
            }
        });
    });
    </script>
    <?php
}

/**
 * ============================================================================
 * 3. AJAX HANDLERS
 * ============================================================================
 */

/**
 * Handle Manual Sync from Admin Settings
 */
add_action('wp_ajax_tkgadm_manual_sync_gads', 'tkgadm_ajax_manual_sync_gads');
function tkgadm_ajax_manual_sync_gads() {
    check_ajax_referer('tkgadm_sync_gads', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền truy cập.');
    }

    $result = tkgadm_do_sync_process();

    // Update last sync status
    update_option('tkgadm_last_sync_time', time());
    update_option('tkgadm_last_sync_message', $result['message']);

    if ($result['success']) {
        wp_send_json_success(['message' => $result['message']]);
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * Handle Hourly Sync Cron Job
 */
add_action('tkgadm_hourly_sync_event', 'tkgadm_handle_hourly_sync');
function tkgadm_handle_hourly_sync() {
    if (!get_option('tkgadm_auto_sync_hourly')) {
        return;
    }

    $result = tkgadm_do_sync_process();

    // Log result
    update_option('tkgadm_last_sync_time', time());
    update_option('tkgadm_last_sync_message', '(Auto) ' . $result['message']);
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
    $url = add_query_arg('api_key', $api_key, trailingslashit($service_url) . 'api/?action=register_site');

    wp_remote_post($url, array(
        'headers' => array('Content-Type' => 'application/json'),
        'body' => json_encode(array('site_url' => home_url())),
        'timeout' => 5,
        'blocking' => false // Fire and forget
    ));
}

/**
 * Xóa sạch IP trên Google Ads và đồng bộ lại toàn bộ danh sách từ local DB
 */
function tkgadm_do_full_sync_google_ads() {
    if (!tkgadm_get_gads_connection_mode()) {
        return ['success' => false, 'message' => 'Vui lòng kiểm tra kết nối Google Ads và Customer ID.'];
    }

    // Read and validate local data before removing anything from Google Ads.
    global $wpdb;
    $blocked_table = $wpdb->prefix . 'gads_toolkit_blocked';
    $local_ips = $wpdb->get_col("SELECT ip_address FROM $blocked_table");
    if ($wpdb->last_error) {
        return ['success' => false, 'message' => 'Không đọc được danh sách IP local; chưa thay đổi Google Ads.'];
    }
    if (function_exists('tkgadm_is_ip_whitelisted')) {
        $local_ips = array_filter($local_ips, function($ip) { return !tkgadm_is_ip_whitelisted($ip); });
    }
    $normalized_ips = array_map('tkgadm_normalize_google_ads_ip', $local_ips);
    if (count(array_filter($normalized_ips)) !== count($local_ips)) {
        return ['success' => false, 'message' => 'Danh sách local có IP không hợp lệ; chưa thay đổi Google Ads.'];
    }
    $local_ips = $local_ips ? array_values(array_combine($normalized_ips, $local_ips)) : [];
    if (count($local_ips) > 500) {
        return ['success' => false, 'message' => 'Danh sách local vượt 500 IP. Hãy giảm danh sách trước khi đồng bộ lại.'];
    }

    $gads_ips = tkgadm_list_connected_google_ads_ips();
    if (is_wp_error($gads_ips)) {
        return ['success' => false, 'message' => 'Lỗi lấy danh sách từ Google Ads: ' . $gads_ips->get_error_message()];
    }

    $resource_names = array_column($gads_ips, 'resource_name');
    if (!empty($resource_names)) {
        $remove_result = tkgadm_remove_connected_google_ads_ips($resource_names);
        if (is_wp_error($remove_result)) {
            return ['success' => false, 'message' => 'Lỗi khi xóa IP trên Google Ads: ' . $remove_result->get_error_message()];
        }
    }

    if (empty($local_ips)) {
        return ['success' => true, 'message' => 'Đã xóa toàn bộ IP trên GAds. Danh sách local rỗng nên không thêm mới.'];
    }

    // 4. Đồng bộ (thêm mới) lại toàn bộ danh sách local lên GAds
    // Gọi hàm sync (chú ý: phải tắt tính năng auto-rotate khi gọi để tránh loop)
    $sync_result = tkgadm_sync_ip_to_google_ads($local_ips, true); 
    
    if ($sync_result['success']) {
        $sync_result['message'] = 'Đã làm sạch Google Ads và ' . lcfirst($sync_result['message']);
    }

    return $sync_result;
}
