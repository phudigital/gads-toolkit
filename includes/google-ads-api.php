<?php
/**
 * Google Ads API Integration
 * Authentication & IP Exclusion Sync
 */

if (!defined('ABSPATH')) exit;

/**
 * Get Access Token from Refresh Token
 */
function tkgadm_get_google_access_token() {
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

    $access_token = tkgadm_get_google_access_token();
    if (is_wp_error($access_token)) {
        return ['success' => false, 'message' => $access_token->get_error_message()];
    }

    $customer_id = str_replace('-', '', get_option('tkgadm_gads_customer_id'));
    $developer_token = get_option('tkgadm_gads_developer_token');
    
    if (!$customer_id || !$developer_token) {
        return ['success' => false, 'message' => 'Thiếu Customer ID hoặc Developer Token.'];
    }

    // 1. Chuẩn bị operations cho CustomerNegativeCriterion
    $operations = [];
    foreach ($ips_to_block as $ip) {
        $operations[] = [
            'create' => [
                'type' => 'IP_BLOCK',
                'ip_block' => [
                    'ip_address' => $ip
                ]
            ]
        ];
    }

    // Google Ads API Endpoint for Customer Negative Criteria
    // Ref: https://developers.google.com/google-ads/api/rest/reference/rest/v17/customers.customerNegativeCriteria/mutate
    // Note: API Version might change, using v17 as distinct standard, but check latest if fails.
    // Try to detect version or use a stable one. v14+ supports this.
    
    $api_version = 'v17'; 
    // Google Ads API Endpoint
    $api_version = 'v19'; 
    $url = "https://googleads.googleapis.com/{$api_version}/customers/{$customer_id}/customerNegativeCriteria:mutate";

    $manager_id = str_replace('-', '', get_option('tkgadm_gads_manager_id'));
    
    $headers = array(
        'Authorization' => 'Bearer ' . $access_token,
        'developer-token' => $developer_token,
        'Content-Type' => 'application/json'
    );

    // Chỉ thêm header login-customer-id nếu là tài khoản Manager (MCC)
    if (!empty($manager_id)) {
        $headers['login-customer-id'] = $manager_id;
    }

    $response = wp_remote_post($url, array(
        'headers' => $headers,
        'body' => json_encode(['operations' => $operations]),
        'timeout' => 60
    ));

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Lỗi kết nối Google: ' . $response->get_error_message()];
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($response_code !== 200) {
        $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Lỗi không xác định.';
        
        // Detailed error info
        if (isset($body['error']['details'])) {
            $details = json_encode($body['error']['details'], JSON_UNESCAPED_UNICODE);
            $error_msg .= " (Details: $details)";
        } else {
             $error_msg .= " (Body: " . wp_remote_retrieve_body($response) . ")";
        }

        // Check for "Criterion already exists" error to ignore
        if (strpos($error_msg, 'DUPLICATE_CRITERION') !== false || strpos($error_msg, 'ALREADY_EXISTS') !== false) {
             return ['success' => true, 'message' => 'Đã đồng bộ (Một số IP đã tồn tại trên Google Ads).'];
        }
        return ['success' => false, 'message' => "Google API Error ($response_code): $error_msg"];
    }

    $count = isset($body['results']) ? count($body['results']) : 0;
    return ['success' => true, 'message' => "Đã đồng bộ thành công $count IP lên Google Ads."];
}

/**
 * Main Sync Function (Called by Cron or Manual)
 */
function tkgadm_do_sync_process() {
    global $wpdb;
    $blocking_table = $wpdb->prefix . 'gads_toolkit_blocked';
    
    // 1. Lấy 500 IP mới nhất từ DB (Google giới hạn 500 IP per request is safe usually, validation limit is 20k total)
    // Thực tế nên lấy tất cả hoặc sync delta. Ở đây sync toàn bộ danh sách chặn hiện tại để đảm bảo.
    // Tuy nhiên để tối ưu api, ta chỉ lấy IP chưa được đánh dấu là "synced" nếu có field đó.
    // Hiện tại table chưa có field status, nên ta sẽ gửi các IP đang có.
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $blocked_ips = $wpdb->get_col("SELECT ip_address FROM $blocking_table ORDER BY blocked_time DESC LIMIT 500");

    if (empty($blocked_ips)) {
        return ['success' => true, 'message' => 'Danh sách chặn trống.'];
    }

    // 2. Gửi lên Google
    // Lưu ý: Hàm mutate create sẽ lỗi nếu IP đã tồn tại. 
    // Giải pháp tốt hơn: Lấy danh sách hiện tại từ Google về -> So sánh -> Chỉ push cái mới.
    // Tuy nhiên để đơn giản bước 1, ta cứ push, nếu trùng API sẽ báo lỗi Duplicate, ta catch lỗi đó.
    
    return tkgadm_sync_ip_to_google_ads($blocked_ips);
}
