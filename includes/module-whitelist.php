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
function tkgadm_is_ip_whitelisted($ip) {
    static $whitelist = null;
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

    return true;
}

/**
 * Xóa IP khỏi whitelist theo ID.
 *
 * @param int $id
 * @return bool
 */
function tkgadm_remove_from_whitelist($id) {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_whitelist';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return (bool) $wpdb->delete($table, ['id' => (int) $id], ['%d']);
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
 * Smart Rotation: Khi Google Ads đã đủ 500 IP, xóa N IP cũ nhất để nhường chỗ.
 *
 * Thứ tự ưu tiên xóa:
 *   1. IP có ngày bị chặn (blocked_time) xa nhất trong DB plugin.
 *   2. Nếu IP không có trong DB plugin (manual add), xóa theo thứ tự ngẫu nhiên.
 *
 * @param int $slots_needed    Số slot cần giải phóng (default 50 để có buffer)
 * @param int $gads_limit      Giới hạn của Google Ads (default 500)
 * @return array               ['freed' => int, 'message' => string]
 */
function tkgadm_smart_rotate_google_ads_ips($slots_needed = 50, $gads_limit = 500) {
    $use_central = tkgadm_is_using_central_service();

    // Central Service path: dùng list_ips / remove_ips qua proxy
    if ($use_central) {
        return tkgadm_smart_rotate_via_central_service($slots_needed, $gads_limit);
    }

    // Direct API path
    $access_token = tkgadm_get_google_access_token();
    if (is_wp_error($access_token)) {
        return ['freed' => 0, 'message' => 'Không thể lấy access token: ' . $access_token->get_error_message()];
    }

    $ids = tkgadm_get_gads_ids();
    $customer_id     = $ids['customer_id'];
    $developer_token = $ids['developer_token'];
    $manager_id      = $ids['manager_id'];

    if (!$customer_id || !$developer_token) {
        return ['freed' => 0, 'message' => 'Thiếu Customer ID hoặc Developer Token.'];
    }

    // Lấy danh sách IP đang bị chặn trên Google Ads
    $gads_ips = tkgadm_get_google_ads_blocked_ips($access_token, $customer_id, $developer_token, $manager_id);
    if (is_wp_error($gads_ips)) {
        return ['freed' => 0, 'message' => 'Lỗi lấy danh sách IP từ Google Ads: ' . $gads_ips->get_error_message()];
    }

    $current_count = count($gads_ips);
    if ($current_count < $gads_limit) {
        return ['freed' => 0, 'message' => "Google Ads hiện có $current_count/$gads_limit IP. Chưa cần xóa."];
    }

    // Tính số IP cần xóa
    $to_free = max($slots_needed, $current_count - $gads_limit + $slots_needed);

    // Sắp xếp: ưu tiên xóa IP có blocked_time cũ nhất trong DB plugin
    global $wpdb;
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';

    // Map: ip_address => blocked_time từ DB plugin
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $db_ips = $wpdb->get_results(
        "SELECT ip_address, blocked_time FROM $table_blocked ORDER BY blocked_time ASC",
        ARRAY_A
    );
    $db_map = [];
    foreach ((array) $db_ips as $row) {
        $db_map[$row['ip_address']] = $row['blocked_time'];
    }

    // Gán timestamp cho từng IP trên Google Ads
    $scored = [];
    foreach ($gads_ips as $entry) {
        $ip = $entry['ip_address'];
        // Normalize CIDR -> plugin format (x.x.x.0/24 -> x.x.x.*)
        $ip_key = $ip;
        if (preg_match('/^(\d+\.\d+\.\d+)\.0\/24$/', $ip, $m)) {
            $ip_key = $m[1] . '.*';
        }
        $entry['db_time'] = $db_map[$ip] ?? ($db_map[$ip_key] ?? null);
        $scored[] = $entry;
    }

    // Sắp xếp: IP có db_time sớm nhất lên đầu; IP không có trong DB (null) xuống cuối
    usort($scored, function($a, $b) {
        if ($a['db_time'] === null && $b['db_time'] === null) return 0;
        if ($a['db_time'] === null) return 1;  // null về cuối
        if ($b['db_time'] === null) return -1;
        return strcmp($a['db_time'], $b['db_time']); // cũ nhất lên đầu
    });

    // Lấy $to_free IP cần xóa
    $to_remove  = array_slice($scored, 0, $to_free);
    $rn_to_remove = array_column($to_remove, 'resource_name');

    if (empty($rn_to_remove)) {
        return ['freed' => 0, 'message' => 'Không tìm thấy IP nào để xóa.'];
    }

    $remove_result = tkgadm_remove_google_ads_ips($access_token, $customer_id, $developer_token, $rn_to_remove, $manager_id);
    if (is_wp_error($remove_result)) {
        return ['freed' => 0, 'message' => 'Lỗi xóa IP: ' . $remove_result->get_error_message()];
    }

    $freed = count($rn_to_remove);

    // Ghi log
    update_option('tkgadm_last_rotation', [
        'time'    => time(),
        'freed'   => $freed,
        'was'     => $current_count,
        'ips'     => array_column($to_remove, 'ip_address'),
    ]);

    return [
        'freed'   => $freed,
        'message' => "Đã xóa $freed IP cũ nhất khỏi Google Ads (trước đó: $current_count/$gads_limit). Còn lại: " . ($current_count - $freed) . " IP.",
    ];
}

/**
 * Smart Rotation via Central Service.
 * Wraps list_ips + remove_ips central endpoints.
 *
 * @param int $slots_needed
 * @param int $gads_limit
 * @return array
 */
function tkgadm_smart_rotate_via_central_service($slots_needed = 50, $gads_limit = 500) {
    $gads_ips = tkgadm_list_ips_via_central_service();
    if (is_wp_error($gads_ips)) {
        return ['freed' => 0, 'message' => 'Lỗi lấy danh sách IP: ' . $gads_ips->get_error_message()];
    }

    $current_count = count($gads_ips);
    if ($current_count < $gads_limit) {
        return ['freed' => 0, 'message' => "Google Ads hiện có $current_count/$gads_limit IP. Chưa cần xóa."];
    }

    $to_free = max($slots_needed, $current_count - $gads_limit + $slots_needed);

    // Sắp xếp theo DB blocked_time (tái sử dụng logic hiện tại)
    global $wpdb;
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $db_ips = $wpdb->get_results(
        "SELECT ip_address, blocked_time FROM $table_blocked ORDER BY blocked_time ASC",
        ARRAY_A
    );
    $db_map = [];
    foreach ((array) $db_ips as $row) {
        $db_map[$row['ip_address']] = $row['blocked_time'];
    }

    $scored = [];
    foreach ($gads_ips as $entry) {
        $ip = $entry['ip_address'];
        $ip_key = $ip;
        if (preg_match('/^(\d+\.\d+\.\d+)\.0\/24$/', $ip, $m)) {
            $ip_key = $m[1] . '.*';
        }
        $entry['db_time'] = $db_map[$ip] ?? ($db_map[$ip_key] ?? null);
        $scored[] = $entry;
    }

    usort($scored, function($a, $b) {
        if ($a['db_time'] === null && $b['db_time'] === null) return 0;
        if ($a['db_time'] === null) return 1;
        if ($b['db_time'] === null) return -1;
        return strcmp($a['db_time'], $b['db_time']);
    });

    $to_remove = array_slice($scored, 0, $to_free);
    $rn_to_remove = array_column($to_remove, 'resource_name');

    if (empty($rn_to_remove)) {
        return ['freed' => 0, 'message' => 'Không tìm thấy IP nào để xóa.'];
    }

    $remove_result = tkgadm_remove_ips_via_central_service($rn_to_remove);
    if (is_wp_error($remove_result)) {
        return ['freed' => 0, 'message' => 'Lỗi xóa IP: ' . $remove_result->get_error_message()];
    }

    $freed = count($rn_to_remove);
    update_option('tkgadm_last_rotation', [
        'time'  => time(),
        'freed' => $freed,
        'was'   => $current_count,
        'ips'   => array_column($to_remove, 'ip_address'),
    ]);

    return [
        'freed'   => $freed,
        'message' => "Đã xóa $freed IP cũ nhất khỏi Google Ads (trước đó: $current_count/$gads_limit). Còn lại: " . ($current_count - $freed) . " IP.",
    ];
}

/**
 * ============================================================================
 * 3b. SMART AUTO-WHITELIST
 *
 * Logic: IP đã bị chặn mà sau đó vẫn tiếp tục click Ads (tổng click
 * sau ngày bị chặn ≥ ngưỡng) → đây là Google Verification Bot →
 * tự động thêm vào whitelist để giải phóng slot Google Ads.
 * ============================================================================
 */

/**
 * Quét tìm IP cần auto-whitelist và thực hiện.
 *
 * @param int $threshold   Số click tối thiểu sau khi bị chặn (default từ option)
 * @param bool $remove_from_gads  Có xóa khỏi Google Ads không (Direct API)
 * @return array  ['added' => int, 'ips' => string[], 'message' => string]
 */
function tkgadm_run_smart_whitelist_scan($threshold = null, $remove_from_gads = true) {
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

    // Truy vấn mới: IP đã chặn + tổng GCLID unique TOÀN THỜI GIAN >= ngưỡng + chưa trong whitelist
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
    $candidates = $wpdb->get_results($wpdb->prepare(
        "SELECT b.id AS blocked_id,
                b.ip_address,
                b.blocked_time,
                COUNT(DISTINCT CASE WHEN s.gclid IS NOT NULL AND s.gclid != '' THEN s.gclid END) AS total_gclids,
                COUNT(DISTINCT CASE WHEN s.gclid IS NOT NULL AND s.gclid != '' AND s.visit_time > b.blocked_time THEN s.gclid END) AS post_block_gclids,
                COUNT(DISTINCT CASE WHEN s.gclid IS NOT NULL AND s.gclid != '' AND s.visit_time <= b.blocked_time THEN s.gclid END) AS pre_block_gclids
         FROM   $blocked_table b
         LEFT JOIN $stats_table s
                 ON s.ip_address = b.ip_address
         WHERE  b.ip_address NOT IN (SELECT ip_address FROM $wl_table)
         GROUP  BY b.id, b.ip_address, b.blocked_time
         HAVING total_gclids >= %d
         ORDER  BY total_gclids DESC
         LIMIT  100",
        $threshold
    ));

    if (empty($candidates)) {
        return ['added' => 0, 'ips' => [], 'message' => "Không tìm thấy IP nào đủ điều kiện (ngưỡng: $threshold GCLID unique)."];
    }

    $added_ips  = [];
    $gads_to_remove = []; // resource_names để xóa khỏi Google Ads

    foreach ($candidates as $row) {
        $ip     = $row->ip_address;
        $total_clicks    = (int) $row->total_gclids;
        $pre_clicks      = (int) $row->pre_block_gclids;
        $post_clicks     = (int) $row->post_block_gclids;
        $reason = "Auto-Whitelist: Bot tạo $total_clicks GCLID unique ($pre_clicks trước + $post_clicks sau khi chặn ngày " . date_i18n('d/m/Y', strtotime($row->blocked_time)) . ")";

        $result = tkgadm_add_to_whitelist($ip, $reason, 'auto');
        if ($result === true) {
            $added_ips[] = $ip;

            // Xóa khỏi blocked table (không cần giữ vì đã whitelist)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($blocked_table, ['id' => (int) $row->blocked_id], ['%d']);
        }
    }

    // Xóa khỏi Google Ads nếu có thể (Central Service hoặc Direct API)
    $gads_msg = '';
    if ($remove_from_gads && !empty($added_ips)) {
        if (tkgadm_is_using_central_service() && function_exists('tkgadm_list_ips_via_central_service')) {
            // Central Service path
            $gads_list = tkgadm_list_ips_via_central_service();
            if (!is_wp_error($gads_list)) {
                $rns_to_del = [];
                foreach ($gads_list as $entry) {
                    $ip_raw = $entry['ip_address'];
                    $ip_key = preg_replace('/^(\d+\.\d+\.\d+)\.0\/24$/', '$1.*', $ip_raw);
                    if (in_array($ip_raw, $added_ips, true) || in_array($ip_key, $added_ips, true)) {
                        $rns_to_del[] = $entry['resource_name'];
                    }
                }
                if (!empty($rns_to_del)) {
                    $del = tkgadm_remove_ips_via_central_service($rns_to_del);
                    $gads_msg = is_wp_error($del)
                        ? ' (Lỗi xóa Google Ads: ' . $del->get_error_message() . ')'
                        : ' + đã xóa ' . count($rns_to_del) . ' IP khỏi Google Ads.';
                }
            }
        } elseif (function_exists('tkgadm_get_google_access_token')) {
            // Direct API path
            $access_token    = tkgadm_get_google_access_token();
            $wl_ids = tkgadm_get_gads_ids();
            $developer_token = $wl_ids['developer_token'];
            $customer_id     = $wl_ids['customer_id'];
            $manager_id      = $wl_ids['manager_id'];

            if (!is_wp_error($access_token) && $developer_token && $customer_id) {
                $gads_list = tkgadm_get_google_ads_blocked_ips($access_token, $customer_id, $developer_token, $manager_id);
                if (!is_wp_error($gads_list)) {
                    $rns_to_del = [];
                    foreach ($gads_list as $entry) {
                        $ip_raw = $entry['ip_address'];
                        $ip_key = preg_replace('/^(\d+\.\d+\.\d+)\.0\/24$/', '$1.*', $ip_raw);
                        if (in_array($ip_raw, $added_ips, true) || in_array($ip_key, $added_ips, true)) {
                            $rns_to_del[] = $entry['resource_name'];
                        }
                    }
                    if (!empty($rns_to_del)) {
                        $del = tkgadm_remove_google_ads_ips($access_token, $customer_id, $developer_token, $rns_to_del, $manager_id);
                        $gads_msg = is_wp_error($del)
                            ? ' (Lỗi xóa Google Ads: ' . $del->get_error_message() . ')'
                            : ' + đã xóa ' . count($rns_to_del) . ' IP khỏi Google Ads.';
                    }
                }
            }
        }
    }

    $count   = count($added_ips);
    $log_entry = [
        'time'      => time(),
        'added'     => $count,
        'threshold' => $threshold,
        'ips'       => $added_ips,
    ];
    update_option('tkgadm_last_smart_wl_scan', $log_entry);

    return [
        'added'   => $count,
        'ips'     => $added_ips,
        'message' => "Đã tự động whitelist $count IP (ngưỡng ≥ $threshold GCLID unique toàn thời gian)$gads_msg",
    ];
}

/**
 * Hook vào cron auto-block để chạy smart whitelist scan song song.
 */
add_action('tkgadm_auto_block_scan_event', 'tkgadm_hook_smart_whitelist_in_cron');
function tkgadm_hook_smart_whitelist_in_cron() {
    if (get_option('tkgadm_smart_wl_enabled', '1')) {
        tkgadm_run_smart_whitelist_scan();
    }
}

/**
 * AJAX: Chạy smart whitelist scan thủ công
 */
add_action('wp_ajax_tkgadm_smart_whitelist_scan', 'tkgadm_ajax_smart_whitelist_scan');
function tkgadm_ajax_smart_whitelist_scan() {
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
    check_ajax_referer('tkgadm_whitelist_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $enabled   = isset($_POST['enabled'])   ? '1' : '0';
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
    check_ajax_referer('tkgadm_whitelist_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('ID không hợp lệ.');

    if (tkgadm_remove_from_whitelist($id)) {
        wp_send_json_success(['message' => 'Đã xóa khỏi whitelist.']);
    } else {
        wp_send_json_error('Không thể xóa.');
    }
}

/** Chạy Smart Rotation thủ công */
add_action('wp_ajax_tkgadm_smart_rotate', 'tkgadm_ajax_smart_rotate');
function tkgadm_ajax_smart_rotate() {
    check_ajax_referer('tkgadm_whitelist_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $slots = max(1, intval($_POST['slots'] ?? 50));
    $result = tkgadm_smart_rotate_google_ads_ips($slots);
    wp_send_json_success($result);
}

/**
 * ============================================================================
 * 5. ADMIN UI
 * ============================================================================
 */

function tkgadm_render_whitelist_page() {
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $table    = $wpdb->prefix . 'gads_toolkit_whitelist';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $entries  = $wpdb->get_results("SELECT * FROM $table ORDER BY added_time DESC");
    $count    = count($entries);

    $last_rotation = get_option('tkgadm_last_rotation');
    $rotation_max  = (int) get_option('tkgadm_rotation_max_ips', 500);
    $rotation_free = (int) get_option('tkgadm_rotation_free_slots', 50);

    // Save Smart Rotation settings
    if (isset($_POST['tkgadm_save_rotation']) && check_admin_referer('tkgadm_whitelist_settings_nonce')) {
        $rotation_max  = max(1, intval($_POST['rotation_max_ips'] ?? 500));
        $rotation_free = max(1, intval($_POST['rotation_free_slots'] ?? 50));
        update_option('tkgadm_rotation_max_ips', $rotation_max);
        update_option('tkgadm_rotation_free_slots', $rotation_free);
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu cấu hình Smart Rotation.</p></div>';
    }

    $nonce = wp_create_nonce('tkgadm_whitelist_nonce');
    ?>
    <div class="wp-wrap space-y-6" style="padding: 20px 20px 40px 0;">

        <!-- Header -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2 m-0 pb-1">
                <i class="fa-solid fa-shield-check text-green-600"></i> Whitelist IP & Smart Rotation
            </h1>
            <p class="text-sm text-gray-500 m-0">
                IP trong whitelist sẽ <strong>không bị chặn</strong> và <strong>không được đẩy lên Google Ads</strong>.
                Smart Rotation tự động xóa IP cũ nhất khi tài khoản Google Ads đầy 500 IP.
            </p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- LEFT: Whitelist Management -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Add IP Form -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 border-t-4 border-t-green-500">
                    <h3 class="text-base font-bold text-gray-800 flex items-center gap-2 m-0 mb-4">
                        <i class="fa-solid fa-plus-circle text-green-500"></i> Thêm IP vào Whitelist
                    </h3>
                    <div class="flex gap-3 flex-wrap">
                        <input type="text" id="wl-ip-input" placeholder="VD: 66.249.64.1 hoặc 66.249.64.*"
                               class="flex-1 min-w-[200px] text-sm border border-gray-300 rounded-lg p-2 focus:ring-2 focus:ring-green-500 focus:outline-none font-mono">
                        <input type="text" id="wl-reason-input" placeholder="Ghi chú (tùy chọn)"
                               class="flex-1 min-w-[160px] text-sm border border-gray-300 rounded-lg p-2 focus:ring-2 focus:ring-green-500 focus:outline-none">
                        <button id="btn-wl-add"
                                class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium py-2 px-4 rounded-lg transition border-none cursor-pointer flex items-center gap-2">
                            <i class="fa-solid fa-plus"></i> Thêm vào Whitelist
                        </button>
                    </div>
                    <p class="text-xs text-gray-400 mt-2 mb-0">
                        💡 Hỗ trợ IPv4, IPv6, và dải IP wildcard (VD: <code>66.249.64.*</code> để whitelist cả dải /24).
                        Thêm IP của Google Verification Bot tại đây để không bị chặn.
                    </p>
                    <div id="wl-add-status" class="hidden mt-3 p-2 rounded text-sm"></div>
                </div>

                <!-- IP List Table -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-bold text-gray-800 flex items-center gap-2 m-0">
                            <i class="fa-solid fa-list-check text-blue-500"></i>
                            Danh sách Whitelist
                            <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-normal"><?php echo $count; ?> IP</span>
                        </h3>
                        <input type="text" id="wl-search" placeholder="🔍 Tìm IP..."
                               class="text-sm border border-gray-300 rounded-lg p-2 w-48 focus:ring-2 focus:ring-blue-400 focus:outline-none">
                    </div>

                    <?php if (empty($entries)): ?>
                        <div class="text-center py-10 text-gray-400">
                            <i class="fa-solid fa-shield text-4xl mb-3"></i>
                            <p class="text-sm m-0">Chưa có IP nào trong whitelist.</p>
                            <p class="text-xs m-0 mt-1">Thêm IP Google Bot hoặc IP nội bộ để tránh bị chặn nhầm.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm" id="wl-table">
                                <thead>
                                    <tr class="text-left text-xs text-gray-500 border-b border-gray-100">
                                        <th class="pb-2 font-medium">IP / Dải IP</th>
                                        <th class="pb-2 font-medium">Ghi chú</th>
                                        <th class="pb-2 font-medium">Thêm bởi</th>
                                        <th class="pb-2 font-medium">Ngày thêm</th>
                                        <th class="pb-2 font-medium text-right">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody id="wl-tbody">
                                    <?php foreach ($entries as $entry): ?>
                                        <tr class="wl-row border-b border-gray-50 hover:bg-gray-50 transition"
                                            data-ip="<?php echo esc_attr($entry->ip_address); ?>"
                                            data-id="<?php echo esc_attr($entry->id); ?>">
                                            <td class="py-2.5 font-mono font-medium text-gray-800">
                                                <?php echo esc_html($entry->ip_address); ?>
                                            </td>
                                            <td class="py-2.5 text-gray-500 max-w-[200px] truncate"
                                                title="<?php echo esc_attr($entry->reason); ?>">
                                                <?php echo esc_html($entry->reason ?: '—'); ?>
                                            </td>
                                            <td class="py-2.5">
                                                <span class="text-xs px-2 py-0.5 rounded-full <?php echo $entry->added_by === 'auto' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600'; ?>">
                                                    <?php echo esc_html($entry->added_by === 'auto' ? '🤖 Tự động' : '👤 Thủ công'); ?>
                                                </span>
                                            </td>
                                            <td class="py-2.5 text-gray-500 text-xs whitespace-nowrap">
                                                <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($entry->added_time))); ?>
                                            </td>
                                            <td class="py-2.5 text-right">
                                                <button class="btn-wl-remove text-red-400 hover:text-red-600 border-none bg-transparent cursor-pointer text-xs py-1 px-2 rounded hover:bg-red-50 transition"
                                                        data-id="<?php echo esc_attr($entry->id); ?>"
                                                        title="Xóa khỏi whitelist">
                                                    <i class="fa-solid fa-trash-can"></i> Xóa
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Google Bot IP Reference -->
                <div class="bg-blue-50 rounded-xl border border-blue-100 p-5">
                    <h3 class="text-sm font-bold text-blue-800 flex items-center gap-2 m-0 mb-3">
                        <i class="fa-brands fa-google text-blue-600"></i> IP Google Bot phổ biến nên whitelist
                    </h3>
                    <p class="text-xs text-blue-700 mb-3">
                        Google dùng các dải IP sau để xác minh quảng cáo (Ads Verification Bot). Nếu bạn chặn các IP này, Google có thể gặp vấn đề khi xác minh landing page.
                    </p>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-xs font-mono">
                        <?php
                        $google_bot_ranges = [
                            '66.249.64.*', '66.249.65.*', '66.249.66.*',
                            '66.249.68.*', '66.249.72.*', '66.249.74.*',
                            '66.249.76.*', '64.233.160.*', '64.233.172.*',
                            '216.58.192.*', '216.239.32.*', '209.85.128.*',
                        ];
                        foreach ($google_bot_ranges as $range): ?>
                            <button type="button" class="btn-quick-add text-left bg-white border border-blue-200 rounded px-2 py-1.5 text-blue-700 hover:bg-blue-100 cursor-pointer transition"
                                    data-ip="<?php echo esc_attr($range); ?>"
                                    data-reason="Google Ads Verification Bot">
                                <?php echo esc_html($range); ?>
                                <i class="fa-solid fa-plus text-blue-400 float-right mt-0.5"></i>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Smart Rotation -->
            <div class="space-y-6">

                <!-- Smart Rotation Status -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 border-t-4 border-t-orange-500">
                    <h3 class="text-base font-bold text-gray-800 flex items-center gap-2 m-0 mb-4">
                        <i class="fa-solid fa-rotate text-orange-500"></i> Smart IP Rotation
                    </h3>
                    <p class="text-xs text-gray-500 mb-4">
                        Khi Google Ads đã đạt giới hạn 500 IP, tự động xóa những IP cũ nhất để nhường chỗ cho IP mới bị chặn.
                    </p>

                    <?php if ($last_rotation): ?>
                        <div class="bg-orange-50 border border-orange-100 rounded-lg p-3 mb-4 text-xs">
                            <p class="font-semibold text-orange-700 m-0 mb-1">
                                <i class="fa-solid fa-clock-rotate-left"></i> Lần xóa gần nhất:
                            </p>
                            <p class="text-gray-600 m-0"><?php echo date_i18n('d/m/Y H:i', $last_rotation['time']); ?></p>
                            <p class="text-gray-600 m-0">Đã xóa: <?php echo intval($last_rotation['freed']); ?> IP (từ <?php echo intval($last_rotation['was']); ?>/500)</p>
                        </div>
                    <?php endif; ?>

                    <!-- Rotation Settings -->
                    <form method="POST" action="" class="mb-4">
                        <?php wp_nonce_field('tkgadm_whitelist_settings_nonce'); ?>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">
                                    Giới hạn Google Ads
                                </label>
                                <input type="number" name="rotation_max_ips" value="<?php echo esc_attr($rotation_max); ?>"
                                       min="1" max="1000"
                                       class="w-full text-sm border border-gray-300 rounded-lg p-2 focus:ring-2 focus:ring-orange-400 focus:outline-none">
                                <p class="text-xs text-gray-400 mt-1 mb-0">Mặc định 500 (giới hạn Google Ads)</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">
                                    Số slot cần giải phóng
                                </label>
                                <input type="number" name="rotation_free_slots" value="<?php echo esc_attr($rotation_free); ?>"
                                       min="1" max="200"
                                       class="w-full text-sm border border-gray-300 rounded-lg p-2 focus:ring-2 focus:ring-orange-400 focus:outline-none">
                                <p class="text-xs text-gray-400 mt-1 mb-0">Xóa N IP cũ nhất mỗi lần rotation (nên để 50)</p>
                            </div>
                        </div>
                        <button type="submit" name="tkgadm_save_rotation"
                                class="mt-3 w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium py-1.5 px-3 rounded-lg border-none cursor-pointer transition">
                            <i class="fa-regular fa-floppy-disk"></i> Lưu cấu hình
                        </button>
                    </form>

                    <!-- Manual Trigger -->
                    <button id="btn-smart-rotate"
                            class="w-full bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium py-2.5 px-4 rounded-lg transition border-none cursor-pointer flex items-center justify-center gap-2">
                        <i class="fa-solid fa-rotate"></i> Chạy Smart Rotation ngay
                    </button>
                    <p class="text-xs text-gray-400 mt-2 mb-0 text-center">
                        ⚠️ Chỉ hoạt động với chế độ Direct API (không qua Central Service)
                    </p>
                    <div id="rotate-status" class="hidden mt-3 p-3 rounded-lg text-xs"></div>
                </div>

                <!-- Info Box -->
                <div class="bg-gray-800 rounded-xl p-5 text-white">
                    <h3 class="text-sm font-bold mb-3 flex items-center gap-2 m-0 pb-1">
                        <i class="fa-solid fa-lightbulb text-yellow-400"></i> Cách hoạt động
                    </h3>
                    <ul class="text-xs text-gray-300 space-y-2 m-0 pl-4 list-disc">
                        <li>IP trong <strong class="text-white">Whitelist</strong> sẽ KHÔNG bao giờ bị block hay đồng bộ lên Google Ads</li>
                        <li><strong class="text-white">Smart Rotation</strong> kiểm tra số lượng IP trên Google Ads và xóa IP có <em>blocked_time</em> cũ nhất khi đầy</li>
                        <li>IP bị xóa khỏi Google Ads vẫn còn trong <strong class="text-white">database plugin</strong> để báo cáo</li>
                        <li>Nên thêm whitelist cho IP Google Bot để tránh lãng phí slot</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function($) {
        var nonce = '<?php echo esc_js($nonce); ?>';

        // === Search filter ===
        $('#wl-search').on('input', function() {
            var q = $(this).val().toLowerCase();
            $('.wl-row').each(function() {
                var ip = $(this).data('ip').toLowerCase();
                $(this).toggle(ip.indexOf(q) !== -1);
            });
        });

        // === Add IP ===
        $('#btn-wl-add, .btn-quick-add').on('click', function() {
            var ip, reason;
            if ($(this).hasClass('btn-quick-add')) {
                ip     = $(this).data('ip');
                reason = $(this).data('reason');
            } else {
                ip     = $('#wl-ip-input').val().trim();
                reason = $('#wl-reason-input').val().trim();
            }

            if (!ip) { alert('Vui lòng nhập địa chỉ IP.'); return; }

            var btn = $('#btn-wl-add');
            btn.prop('disabled', true);
            var status = $('#wl-add-status').removeClass('hidden bg-green-50 text-green-700 bg-red-50 text-red-700 border')
                         .addClass('bg-sky-50 text-sky-700 border border-sky-200').text('Đang thêm ' + ip + '...');

            $.post(ajaxurl, {
                action: 'tkgadm_whitelist_add',
                nonce: nonce,
                ip: ip,
                reason: reason
            }, function(res) {
                btn.prop('disabled', false);
                status.removeClass('bg-sky-50 text-sky-700 border-sky-200');
                if (res.success) {
                    status.addClass('bg-green-50 text-green-700 border border-green-200').text('✅ ' + res.data.message);
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    status.addClass('bg-red-50 text-red-700 border border-red-200').text('❌ ' + res.data);
                }
            }).fail(function() {
                btn.prop('disabled', false);
                status.addClass('bg-red-50 text-red-700 border border-red-200').text('❌ Lỗi kết nối máy chủ.');
            });
        });

        // === Remove IP ===
        $(document).on('click', '.btn-wl-remove', function() {
            var id  = $(this).data('id');
            var row = $(this).closest('tr');
            var ip  = row.data('ip');

            if (!confirm('Xóa ' + ip + ' khỏi whitelist?')) return;

            $.post(ajaxurl, {
                action: 'tkgadm_whitelist_remove',
                nonce: nonce,
                id: id
            }, function(res) {
                if (res.success) {
                    row.fadeOut(300, function() { $(this).remove(); });
                } else {
                    alert('Lỗi: ' + res.data);
                }
            });
        });

        // === Smart Rotation ===
        $('#btn-smart-rotate').on('click', function() {
            var btn  = $(this);
            var status = $('#rotate-status').removeClass('hidden bg-green-50 text-green-700 bg-red-50 text-red-700 border')
                         .addClass('bg-sky-50 text-sky-700 border border-sky-200').text('Đang kết nối Google Ads và kiểm tra...');

            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Đang xử lý...');

            $.post(ajaxurl, {
                action: 'tkgadm_smart_rotate',
                nonce: nonce,
                slots: <?php echo intval($rotation_free); ?>
            }, function(res) {
                btn.prop('disabled', false).html('<i class="fa-solid fa-rotate"></i> Chạy Smart Rotation ngay');
                status.removeClass('bg-sky-50 text-sky-700 border-sky-200');
                if (res.success) {
                    var freed = res.data.freed || 0;
                    var cls   = freed > 0 ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-gray-50 text-gray-700 border border-gray-200';
                    status.addClass(cls).text((freed > 0 ? '✅ ' : 'ℹ️ ') + res.data.message);
                } else {
                    status.addClass('bg-red-50 text-red-700 border border-red-200').text('❌ ' + res.data);
                }
            }).fail(function() {
                btn.prop('disabled', false).html('<i class="fa-solid fa-rotate"></i> Chạy Smart Rotation ngay');
                status.addClass('bg-red-50 text-red-700 border border-red-200').text('❌ Lỗi kết nối máy chủ.');
            });
        });
    })(jQuery);
    </script>
    <?php
}
