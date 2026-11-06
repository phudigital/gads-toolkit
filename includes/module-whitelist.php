<?php
/**
 * Module: IP Whitelist
 *
 * Quản lý danh sách IP được tin cậy (whitelist).
 * - IP trong whitelist sẽ KHÔNG bị chặn và KHÔNG được đẩy lên Google Ads.
 * - Khi danh sách IP trên Google Ads đầy 500, tự động xóa IP cũ nhất.
 * - Cung cấp giao diện quản lý trong Admin.
 */

if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * 1. DATABASE
 * ============================================================================
 */

/**
 * Tạo bảng whitelist khi activate plugin hoặc upgrade.
 */
function tkgadm_create_whitelist_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'gads_toolkit_whitelist';

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(255) NOT NULL,
        reason TEXT DEFAULT NULL,
        added_by VARCHAR(100) DEFAULT 'manual',
        added_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY ip_address (ip_address),
        KEY added_time (added_time)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * ============================================================================
 * 2. HELPER FUNCTIONS
 * ============================================================================
 */

/**
 * Lấy tất cả IP trong whitelist (dạng mảng string).
 *
 * @return string[]
 */
function tkgadm_get_whitelist() {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_whitelist';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return $wpdb->get_col("SELECT ip_address FROM $table ORDER BY added_time DESC");
}

/**
 * Kiểm tra một IP có nằm trong whitelist không (hỗ trợ wildcard x.x.x.*).
 *
 * @param string $ip
 * @return bool
 */
function tkgadm_is_ip_whitelisted($ip, $reset = false) {
    static $whitelist = null;
    if ($reset) {
        $whitelist = null;
        return false;
    }
    if ($whitelist === null) {
        $whitelist = tkgadm_get_whitelist();
    }

    foreach ($whitelist as $entry) {
        // Exact match
        if ($entry === $ip) {
            return true;
        }

        // Wildcard: x.x.x.*
        if (strpos($entry, '*') !== false) {
            $prefix = rtrim($entry, '*');
            // Kiểm tra IP có bắt đầu bằng prefix (VD: 192.168.1.)
            if (strpos($ip, $prefix) === 0) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Thêm IP vào whitelist.
 *
 * @param string $ip       IP hoặc dải IP (x.x.x.*)
 * @param string $reason   Ghi chú
 * @param string $added_by Nguồn thêm: 'manual' | 'auto'
 * @return bool|WP_Error   True nếu thêm thành công
 */
function tkgadm_add_to_whitelist($ip, $reason = '', $added_by = 'manual') {
    if (!tkgadm_license_is_valid()) { return new WP_Error('license_required', tkgadm_license_message()); }

    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_whitelist';

    $ip = trim($ip);
    if (empty($ip)) {
        return new WP_Error('empty_ip', 'IP không được để trống.');
    }

    // Validate: hỗ trợ IP thông thường và wildcard x.x.x.*
    $valid = filter_var($ip, FILTER_VALIDATE_IP)
        || preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.\*$/', $ip);

    if (!$valid) {
        return new WP_Error('invalid_ip', "IP không hợp lệ: $ip");
    }

    if (tkgadm_is_ip_whitelisted($ip)) {
        return new WP_Error('already_exists', "IP $ip đã có trong whitelist (hoặc đã nằm trong dải IP được whitelist).");
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $result = $wpdb->insert($table, [
        'ip_address' => $ip,
        'reason'     => sanitize_text_field($reason),
        'added_by'   => sanitize_text_field($added_by),
        'added_time' => current_time('mysql'),
    ]);

    if ($result === false) {
        return new WP_Error('db_error', 'Lỗi khi ghi vào database.');
    }

    tkgadm_is_ip_whitelisted('', true);
    if (function_exists('tkgadm_queue_sync')) {
        tkgadm_queue_sync(); // a whitelisted IP is removed from Google Ads by the next sync
    }
    return true;
}

/** Remove a whitelist entry by its IP/pattern (used by undo). */
function tkgadm_remove_whitelist_ip($ip) {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_whitelist';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $deleted = (bool) $wpdb->delete($table, ['ip_address' => $ip]);
    tkgadm_is_ip_whitelisted('', true);
    return $deleted;
}

/**
 * Xóa IP khỏi whitelist theo ID.
 *
 * @param int $id
 * @return bool
 */
function tkgadm_remove_from_whitelist($id) {
    if (!tkgadm_license_is_valid()) { return false; }

    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_whitelist';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $deleted = (bool) $wpdb->delete($table, ['id' => (int) $id], ['%d']);
    tkgadm_is_ip_whitelisted('', true);
    return $deleted;
}

/**
 * ============================================================================
 * 3. SMART IP ROTATION (Xóa IP cũ khi Google Ads đầy 500)
 * ============================================================================
 */

/**
 * Lấy danh sách tất cả IP đang bị chặn trên Google Ads (customerNegativeCriteria).
 * Trả về mảng ['resource_name' => string, 'ip_address' => string].
 *
 * @param string $access_token
 * @param string $customer_id     10 chữ số
 * @param string $developer_token
 * @param string $manager_id      10 chữ số hoặc rỗng
 * @return array|WP_Error
 */
function tkgadm_get_google_ads_blocked_ips($access_token, $customer_id, $developer_token, $manager_id = '') {
    if (!tkgadm_license_is_valid()) { return new WP_Error('license_required', tkgadm_license_message()); }

    $api_version = 'v25';
    $url = "https://googleads.googleapis.com/{$api_version}/customers/{$customer_id}/googleAds:searchStream";

    $headers = [
        'Authorization'  => 'Bearer ' . $access_token,
        'developer-token' => $developer_token,
        'Content-Type'   => 'application/json',
    ];

    if (!empty($manager_id)) {
        $headers['login-customer-id'] = $manager_id;
    }

    $query = 'SELECT customer_negative_criterion.resource_name, customer_negative_criterion.ip_block.ip_address FROM customer_negative_criterion WHERE customer_negative_criterion.type = \'IP_BLOCK\'';

    $response = wp_remote_post($url, [
        'headers' => $headers,
        'body'    => json_encode(['query' => $query]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200) {
        $msg = isset($body[0]['error']['message']) ? $body[0]['error']['message'] : 'Lỗi API không xác định.';
        return new WP_Error('api_error', "Google API ($code): $msg");
    }

    $results = [];
    // searchStream trả về array of batch objects, mỗi object có 'results'
    foreach ((array) $body as $batch) {
        if (!isset($batch['results'])) continue;
        foreach ($batch['results'] as $row) {
            $rn       = $row['customerNegativeCriterion']['resourceName'] ?? '';
            $ip_block = $row['customerNegativeCriterion']['ipBlock']['ipAddress'] ?? '';
            if ($rn && $ip_block) {
                $results[] = [
                    'resource_name' => $rn,
                    'ip_address'    => $ip_block,
                ];
            }
        }
    }

    return $results;
}

/**
 * Xóa các criterion (IP) khỏi Google Ads theo resource_name.
 *
 * @param string   $access_token
 * @param string   $customer_id
 * @param string   $developer_token
 * @param string[] $resource_names
 * @param string   $manager_id
 * @return bool|WP_Error
 */
function tkgadm_remove_google_ads_ips($access_token, $customer_id, $developer_token, $resource_names, $manager_id = '') {
    if (!tkgadm_license_is_valid()) { return new WP_Error('license_required', tkgadm_license_message()); }

    if (empty($resource_names)) return true;

    $api_version = 'v25';
    $url = "https://googleads.googleapis.com/{$api_version}/customers/{$customer_id}/customerNegativeCriteria:mutate";

    $headers = [
        'Authorization'   => 'Bearer ' . $access_token,
        'developer-token' => $developer_token,
        'Content-Type'    => 'application/json',
    ];

    if (!empty($manager_id)) {
        $headers['login-customer-id'] = $manager_id;
    }

    $operations = array_map(function($rn) {
        return ['remove' => $rn];
    }, array_values($resource_names));

    $response = wp_remote_post($url, [
        'headers' => $headers,
        'body'    => json_encode([
            'operations'    => $operations,
            'partialFailure' => false,
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $msg  = isset($body['error']['message']) ? $body['error']['message'] : 'Lỗi xóa IP.';
        return new WP_Error('remove_error', "Google API ($code): $msg");
    }

    return true;
}

/**
 * Quét tìm IP cần auto-whitelist và thực hiện.
 *
 * @param int $threshold   Số click tối thiểu sau khi bị chặn (default từ option)
 * @param bool $remove_from_gads  Có xóa khỏi Google Ads không (Direct API)
 * @return array  ['added' => int, 'ips' => string[], 'message' => string]
 */
function tkgadm_run_smart_whitelist_scan($threshold = null, $unused = true) {
    if (!tkgadm_license_is_valid()) { return array('added' => 0, 'ips' => array(), 'message' => tkgadm_license_message()); }

    // Lấy ngưỡng từ option nếu không truyền
    if ($threshold === null) {
        $threshold = (int) get_option('tkgadm_smart_wl_threshold', 30);
    }
    $threshold = max(1, $threshold);

    // Chỉ chạy khi tính năng được bật
    if (!get_option('tkgadm_smart_wl_enabled', '1')) {
        return ['added' => 0, 'ips' => [], 'message' => 'Tính năng Smart Whitelist đang tắt.'];
    }

    global $wpdb;
    $blocked_table = $wpdb->prefix . 'gads_toolkit_blocked';
    $stats_table   = $wpdb->prefix . 'gads_toolkit_stats';
    $wl_table      = $wpdb->prefix . 'gads_toolkit_whitelist';

    // IPs that keep producing NEW ad clicks after being blocked are usually shared
    // addresses (carrier NAT, corporate proxy) where the block cannot work: whitelist
    // them to free Google Ads slots. Only clicks AFTER the block count.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $candidates = $wpdb->get_results($wpdb->prepare(
        "SELECT b.ip_address, b.blocked_time,
                COUNT(DISTINCT s.gclid) AS post_block_gclids
         FROM   $blocked_table b
         INNER JOIN $stats_table s
                 ON s.ip_address = b.ip_address
                AND s.visit_time > b.blocked_time
                AND s.gclid IS NOT NULL AND s.gclid != ''
         WHERE  NOT EXISTS (SELECT 1 FROM $wl_table w WHERE w.ip_address = b.ip_address)
         GROUP  BY b.ip_address, b.blocked_time
         HAVING COUNT(DISTINCT s.gclid) >= %d
         ORDER  BY post_block_gclids DESC
         LIMIT  100",
        $threshold
    ));

    if (empty($candidates)) {
        return ['added' => 0, 'ips' => [], 'message' => "Không có IP nào vượt ngưỡng ($threshold click mới sau khi đã chặn)."];
    }

    $added_ips = [];
    foreach ($candidates as $row) {
        $ip = $row->ip_address;
        $reason = sprintf('Auto-Whitelist: %d click mới sau khi chặn ngày %s (IP dùng chung, chặn không hiệu quả)',
            (int) $row->post_block_gclids, mysql2date('d/m/Y', $row->blocked_time));
        if (tkgadm_add_to_whitelist($ip, $reason, 'auto') === true) {
            // Logged as "whitelist" with the original block row, so it can be undone.
            tkgadm_unblock_ip_internal($ip, $reason, 'auto', 'whitelist');
            $added_ips[] = $ip;
        }
    }

    $count = count($added_ips);
    update_option('tkgadm_last_smart_wl_scan', [
        'time' => time(), 'added' => $count, 'threshold' => $threshold, 'ips' => $added_ips,
    ]);

    return [
        'added'   => $count,
        'ips'     => $added_ips,
        'message' => "Đã tự động whitelist $count IP (≥ $threshold click mới sau khi chặn). Google Ads sẽ được gỡ trong lượt đồng bộ kế tiếp.",
    ];
}

/**
 * Hook vào cron auto-block để chạy smart whitelist scan song song.
 */
add_action('tkgadm_auto_block_scan_event', 'tkgadm_hook_smart_whitelist_in_cron');
function tkgadm_hook_smart_whitelist_in_cron() {
    if (!tkgadm_license_is_valid()) { return; }

    if (get_option('tkgadm_smart_wl_enabled', '1')) {
        tkgadm_run_smart_whitelist_scan();
    }
}

/**
 * AJAX: Chạy smart whitelist scan thủ công
 */
add_action('wp_ajax_tkgadm_smart_whitelist_scan', 'tkgadm_ajax_smart_whitelist_scan');
function tkgadm_ajax_smart_whitelist_scan() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer('tkgadm_whitelist_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $threshold = max(1, intval($_POST['threshold'] ?? get_option('tkgadm_smart_wl_threshold', 30)));

    // Lưu ngưỡng nếu được truyền
    update_option('tkgadm_smart_wl_threshold', $threshold);

    $result = tkgadm_run_smart_whitelist_scan($threshold);
    wp_send_json_success($result);
}

/**
 * AJAX: Lưu cài đặt Smart Whitelist
 */
add_action('wp_ajax_tkgadm_smart_wl_save', 'tkgadm_ajax_smart_wl_save');
function tkgadm_ajax_smart_wl_save() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer('tkgadm_whitelist_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $enabled   = (!empty($_POST['enabled']) && $_POST['enabled'] !== '0' && $_POST['enabled'] !== 'false') ? '1' : '0';
    $threshold = max(1, intval($_POST['threshold'] ?? 30));

    update_option('tkgadm_smart_wl_enabled',   $enabled);
    update_option('tkgadm_smart_wl_threshold', $threshold);

    wp_send_json_success(['message' => 'Đã lưu cài đặt.']);
}

/**
 * ============================================================================
 * 4. AJAX HANDLERS
 * ============================================================================
 */

/** Thêm IP vào whitelist */
add_action('wp_ajax_tkgadm_whitelist_add', 'tkgadm_ajax_whitelist_add');
function tkgadm_ajax_whitelist_add() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer('tkgadm_whitelist_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $ip     = sanitize_text_field(wp_unslash($_POST['ip'] ?? ''));
    $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));

    $result = tkgadm_add_to_whitelist($ip, $reason, 'manual');
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success(['message' => "Đã thêm $ip vào whitelist."]);
}

/** Xóa IP khỏi whitelist */
add_action('wp_ajax_tkgadm_whitelist_remove', 'tkgadm_ajax_whitelist_remove');
function tkgadm_ajax_whitelist_remove() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer('tkgadm_whitelist_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('ID không hợp lệ.');

    global $wpdb;
    $wl_table = $wpdb->prefix . 'gads_toolkit_whitelist';
    $ip_to_remove = $wpdb->get_var($wpdb->prepare("SELECT ip_address FROM $wl_table WHERE id = %d", $id));

    if (tkgadm_remove_from_whitelist($id)) {
        $msg = 'Đã xóa khỏi whitelist.';
        // Kiểm tra xem IP này có đang nằm trong danh sách đen không, nếu có thì chặn lại trên GAds
        if ($ip_to_remove) {
            $blocked_table = $wpdb->prefix . 'gads_toolkit_blocked';
            $is_in_blacklist = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $blocked_table WHERE ip_address = %s", $ip_to_remove));
            if ($is_in_blacklist && function_exists('tkgadm_queue_sync')) {
                tkgadm_queue_sync(0);
                $msg .= ' Hệ thống đã tự động đưa IP này vào danh sách chặn trên Google Ads trở lại.';
            }
        }
        wp_send_json_success(['message' => $msg]);
    } else {
        wp_send_json_error('Không thể xóa.');
    }
}

/** Chạy Smart Rotation thủ công */
add_action('wp_ajax_tkgadm_smart_rotate', 'tkgadm_ajax_smart_rotate');
function tkgadm_ajax_smart_rotate() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer('tkgadm_whitelist_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $slots = max(1, min(TKGADM_GADS_LIMIT, intval($_POST['slots'] ?? 50)));
    $result = tkgadm_smart_rotate_google_ads_ips($slots);
    wp_send_json_success($result);
}
