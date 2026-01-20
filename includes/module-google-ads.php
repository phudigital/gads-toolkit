<?php
/**
 * Module: Google Ads Integration
 * Connects to Google Ads API, manages settings, and handles IP synchronization.
 */

if (!defined('ABSPATH')) exit;

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
    if (defined('GADS_API_KEY')) {
        $key = GADS_API_KEY;
    } else {
        $key = get_option('tkgadm_central_service_api_key');
    }
    
    return !empty($url) && !empty($key);
}

/**
 * Get credentials from Central Service
 * 
 * @return array|WP_Error Credentials or error
 */
function tkgadm_get_central_service_credentials() {
    $service_url = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $api_key = defined('GADS_API_KEY') ? GADS_API_KEY : get_option('tkgadm_central_service_api_key');
    
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
    $api_key = defined('GADS_API_KEY') ? GADS_API_KEY : get_option('tkgadm_central_service_api_key');
    
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
 * Sync IPs to Google Ads (Account Level)
 */
function tkgadm_sync_ip_to_google_ads($ips_to_block) {
    if (empty($ips_to_block)) {
        return ['success' => true, 'message' => 'Không có IP nào cần đồng bộ.'];
    }

    // Check if using central service
    if (tkgadm_is_using_central_service()) {
        return tkgadm_sync_via_central_service($ips_to_block);
    }

    // Original direct API method
    $access_token = tkgadm_get_google_access_token();
    if (is_wp_error($access_token)) {
        return ['success' => false, 'message' => $access_token->get_error_message()];
    }

    $customer_id = str_replace('-', '', get_option('tkgadm_gads_customer_id'));
    $developer_token = get_option('tkgadm_gads_developer_token');
    
    if (!$customer_id || !$developer_token) {
        return ['success' => false, 'message' => 'Thiếu Customer ID hoặc Developer Token.'];
    }

    // 1. Prepare operations & Validate IPs
    $operations = [];
    $skipped_count = 0;
    
    foreach ($ips_to_block as $ip) {
        $clean_ip = trim($ip);
        $is_valid = false;
        
        // Google Ads supports:
        // 1. Valid IPv4 / IPv6 addresses
        // 2. Class C subnet masking (x.x.x.*)
        if (filter_var($clean_ip, FILTER_VALIDATE_IP)) {
            $is_valid = true;
        } elseif (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\*$/', $clean_ip)) {
            $is_valid = true;
        }
        
        if ($is_valid) {
            $operations[] = [
                'create' => [
                    'type' => 'IP_BLOCK',
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

    // Google Ads API Endpoint (v19)
    $api_version = 'v19'; 
    $url = "https://googleads.googleapis.com/{$api_version}/customers/{$customer_id}/customerNegativeCriteria:mutate";

    $manager_id = str_replace('-', '', get_option('tkgadm_gads_manager_id'));
    
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

    if ($response_code !== 200) {
        $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Lỗi không xác định.';
        if (isset($body['error']['details'])) {
            $details = json_encode($body['error']['details'], JSON_UNESCAPED_UNICODE);
            $error_msg .= " (Details: $details)";
        }
        return ['success' => false, 'message' => "Google API Error ($response_code): $error_msg"];
    }

    // 200 OK - Check results
    $success_count = isset($body['results']) ? count($body['results']) : 0;
    
    $msg = "Đã đồng bộ thành công $success_count IP";
    if ($skipped_count > 0) {
        $msg .= " (Bỏ qua $skipped_count IP sai định dạng)";
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
    $api_key = defined('GADS_API_KEY') ? GADS_API_KEY : get_option('tkgadm_central_service_api_key');
    $customer_id = get_option('tkgadm_gads_customer_id');
    $manager_id = get_option('tkgadm_gads_manager_id');
    $refresh_token = get_option('tkgadm_gads_refresh_token');
    
    if (!$customer_id || !$refresh_token) {
        return ['success' => false, 'message' => 'Thiếu Customer ID hoặc chưa kết nối Google Ads.'];
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
    
    $result = $data['data'];
    return [
        'success' => true,
        'message' => $result['message']
    ];
}

/**
 * Main Sync Function (Called by Cron or Manual)
 */
function tkgadm_do_sync_process() {
    global $wpdb;
    $blocking_table = $wpdb->prefix . 'gads_toolkit_blocked';
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $blocked_ips = $wpdb->get_col("SELECT ip_address FROM $blocking_table ORDER BY blocked_time DESC LIMIT 500");

    if (empty($blocked_ips)) {
        return ['success' => true, 'message' => 'Danh sách chặn trống.'];
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
    if (isset($_GET['code'])) {
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

    // 2. Save Settings
    if (isset($_POST['tkgadm_gads_save']) && check_admin_referer('tkgadm_gads_options')) {
        if (!defined('GADS_API_KEY') && isset($_POST['api_key'])) {
            $new_api_key = sanitize_text_field($_POST['api_key']);
            update_option('tkgadm_central_service_api_key', $new_api_key);
            
            // Register Heartbeat immediately when saving key
            tkgadm_register_site_heartbeat($new_api_key);
        } else {
             // If key is hardcoded or unchanged, still try to register using current key
             tkgadm_register_site_heartbeat();
        }

        update_option('tkgadm_gads_customer_id', sanitize_text_field($_POST['customer_id']));
        update_option('tkgadm_gads_manager_id', sanitize_text_field($_POST['manager_id']));
        
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

        echo '<div class="notice notice-success is-dismissible"><p>Đã lưu cài đặt.</p></div>';
    }

    // 3. Prepare Data
    $refresh_token = get_option('tkgadm_gads_refresh_token'); 
    $customer_id = get_option('tkgadm_gads_customer_id');
    $manager_id = get_option('tkgadm_gads_manager_id');
    $auto_sync = get_option('tkgadm_auto_sync_hourly');
    $sync_on_block = get_option('tkgadm_auto_sync_on_block');
    
    // Get API Key value
    if (defined('GADS_API_KEY')) {
        $api_key = GADS_API_KEY;
        $api_key_readonly = true;
    } else {
        $api_key = get_option('tkgadm_central_service_api_key');
        $api_key_readonly = false;
    }
    
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
                                <h2 style="margin: 0 0 15px 0;">🔑 Thiết lập API</h2>
                                
                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Secure API Key</label>
                                    <input type="password" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="widefat" style="padding: 6px; font-size: 13px;" <?php echo $api_key_readonly ? 'readonly disabled' : ''; ?>>
                                    <p class="description" style="margin-top: 3px; font-size: 12px;"><?php echo $api_key_readonly ? 'Key được cấu hình cứng' : 'Mã bảo mật từ nhà phát triển'; ?></p>
                                </div>

                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Customer ID</label>
                                    <input type="text" name="customer_id" value="<?php echo esc_attr($customer_id); ?>" class="widefat" placeholder="xxx-xxx-xxxx" style="padding: 6px; font-size: 13px;">
                                    <p class="description" style="margin-top: 3px; font-size: 12px;">ID tài khoản Google Ads</p>
                                </div>

                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Manager ID (MCC)</label>
                                    <input type="text" name="manager_id" value="<?php echo esc_attr($manager_id); ?>" class="widefat" placeholder="xxx-xxx-xxxx" style="padding: 6px; font-size: 13px;">
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

                        <button type="submit" name="tkgadm_gads_save" class="button button-primary" style="margin-bottom: 20px;">💾 Lưu Thông Tin</button>

                        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
                        
                        <!-- Sync Options & Auto Block Combined -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div>
                                <h3 style="margin: 0 0 12px 0; font-size: 15px;">⚙️ Tùy chọn Đồng bộ</h3>
                                
                                <div style="margin-bottom: 10px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                                        <input type="checkbox" name="auto_sync" value="1" <?php checked($auto_sync, 1); ?>>
                                        <span>Tự động mỗi giờ (Cron)</span>
                                    </label>
                                </div>

                                <div style="margin-bottom: 10px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                                        <input type="checkbox" name="sync_on_block" value="1" <?php checked($sync_on_block, 1); ?>>
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
                                            <input type="checkbox" name="tkgadm_auto_block_enabled" value="1" <?php checked($auto_block_enabled, 1); ?>>
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
        $api_key = defined('GADS_API_KEY') ? GADS_API_KEY : get_option('tkgadm_central_service_api_key');
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
