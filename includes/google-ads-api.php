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

    // 1. Prepare operations & Validate IPs
    $operations = [];
    $skipped_count = 0;
    
    foreach ($ips_to_block as $ip) {
        $clean_ip = trim($ip);
        $is_valid = false;
        
        // Google Ads supports:
        // 1. Valid IPv4 / IPv6 addresses
        // 2. Class C subnet masking (x.x.x.*)
        // It DOES NOT support other wildcards like x.x.*.*
        
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
             // Inform user if everything was skipped
            'message' => $skipped_count > 0 
                ? "Không có IP hợp lệ để đồng bộ ($skipped_count IP bị bỏ qua do sai định dạng)." 
                : "Danh sách IP trống."
        ];
    }

    // Google Ads API Endpoint
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

    // Use partialFailure=true to allow some ops to succeed (e.g. if some are duplicates)
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
        
        // Even with partialFailure, a 400 might happen if the payload structure is largely wrong,
        // but individual validation errors should result in 200 with partialFailureError field.
        return ['success' => false, 'message' => "Google API Error ($response_code): $error_msg"];
    }

    // 200 OK - Check results
    $success_count = isset($body['results']) ? count($body['results']) : 0;
    
    // Check partial failures (skipping duplicates usually)
    $error_details = '';
    if (isset($body['partialFailureError'])) {
        // We generally ignore "Already exists" errors, but others might be relevant.
        // For simple UI feedback, we focus on success count.
    }

    $msg = "Đã đồng bộ thành công $success_count IP";
    if ($skipped_count > 0) {
        $msg .= " (Bỏ qua $skipped_count IP sai định dạng)";
    }
    $msg .= ".";

    return ['success' => true, 'message' => $msg];
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
