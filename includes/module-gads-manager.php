<?php
/**
 * Module: Google Ads IP Manager
 *
 * Quản lý trực tiếp danh sách IP đang bị chặn trên tài khoản Google Ads.
 * - Xem danh sách IP hiện tại (kèm thông tin trong DB plugin nếu có)
 * - Xóa IP chọn lọc hoặc xóa IP cũ nhất
 * - Hỗ trợ Central Service (OAuth) và Direct API
 */

if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * AJAX: Lấy danh sách IP từ Google Ads
 * ============================================================================
 */

add_action("wp_ajax_tkgadm_ar_save_config", "tkgadm_ajax_ar_save_config");
function tkgadm_ajax_ar_save_config() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer("tkgadm_gads_manager_nonce", "nonce");
    if (!current_user_can("manage_options")) wp_send_json_error("Không có quyền.");

    $enabled = (isset($_POST["enabled"]) && $_POST["enabled"] === "1") ? "1" : "0";
    $threshold = isset($_POST["threshold"]) ? intval($_POST["threshold"]) : 450;
    $amount = isset($_POST["amount"]) ? intval($_POST["amount"]) : 50;
    if ($threshold < 50 || $threshold > TKGADM_GADS_LIMIT) {
        wp_send_json_error("Ngưỡng phải từ 50 đến " . TKGADM_GADS_LIMIT . " IP.");
    }
    if ($amount < 1 || $amount > $threshold) {
        wp_send_json_error("Số IP xoay vòng mỗi lần phải từ 1 đến $threshold.");
    }

    update_option("tkgadm_ar_enabled", $enabled);
    update_option("tkgadm_ar_threshold", $threshold);
    update_option("tkgadm_ar_amount", $amount);

    wp_send_json_success(["message" => "Đã lưu thành công."]);
}

add_action("wp_ajax_tkgadm_gads_save_auto_sync", "tkgadm_ajax_gads_save_auto_sync");
function tkgadm_ajax_gads_save_auto_sync() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer("tkgadm_gads_manager_nonce", "nonce");
    if (!current_user_can("manage_options")) wp_send_json_error("Không có quyền.");

    $auto_sync = (isset($_POST["auto_sync"]) && $_POST["auto_sync"] === "0") ? "0" : "1";
    update_option("tkgadm_gads_auto_sync", $auto_sync);
    tkgadm_schedule_core_events();

    wp_send_json_success(["message" => "Đã lưu thành công."]);
}
add_action('wp_ajax_tkgadm_gads_list_ips', 'tkgadm_ajax_gads_list_ips');
function tkgadm_ajax_gads_list_ips() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer('tkgadm_gads_manager_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $gads_ips = tkgadm_list_connected_google_ads_ips();
    if (is_wp_error($gads_ips)) {
        wp_send_json_error($gads_ips->get_error_message());
    }

    // Enrich với dữ liệu từ DB plugin
    global $wpdb;
    $blocked_table = $wpdb->prefix . 'gads_toolkit_blocked';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $db_rows = $wpdb->get_results(
        "SELECT ip_address, blocked_time, reason, visit_count FROM $blocked_table",
        ARRAY_A
    );
    $db_map = [];
    $subnet_map = []; // Map 3 octet đầu tiên sang DB row
    foreach ((array) $db_rows as $row) {
        $db_map[$row['ip_address']] = $row;
        if (preg_match('/^(\d+\.\d+\.\d+)/', $row['ip_address'], $m)) {
            $subnet_map[$m[1]] = $row; // Lưu IP đầu tiên tìm thấy trong dải /24
        }
    }

    // Normalize và enrich
    $enriched = [];
    foreach ($gads_ips as $entry) {
        $ip_raw = $entry['ip_address']; // Có thể là CIDR: 1.2.3.0/24
        
        $db_info = $db_map[$ip_raw] ?? null;
        
        if (!$db_info && preg_match('/^(\d+\.\d+\.\d+)\.0\/24$/', $ip_raw, $m)) {
            $subnet = $m[1];
            if (isset($subnet_map[$subnet])) {
                $db_info = $subnet_map[$subnet];
            } elseif (isset($db_map[$subnet . '.*'])) {
                $db_info = $db_map[$subnet . '.*'];
            }
        }

        $enriched[] = [
            'resource_name' => $entry['resource_name'],
            'ip_address'    => $ip_raw,
            'blocked_time'  => $db_info ? $db_info['blocked_time'] : null,
            'reason'        => $db_info ? $db_info['reason']       : null,
            'visit_count'   => $db_info ? intval($db_info['visit_count']) : 0,
            'in_db'         => (bool) $db_info,
        ];
    }

    // Sắp xếp: IP cũ nhất (null = không có trong DB) lên đầu để dễ xóa
    usort($enriched, function($a, $b) {
        if (!$a['blocked_time'] && !$b['blocked_time']) return 0;
        if (!$a['blocked_time']) return 1;
        if (!$b['blocked_time']) return -1;
        return strcmp($a['blocked_time'], $b['blocked_time']); // cũ nhất lên đầu
    });

    wp_send_json_success([
        'total' => count($enriched),
        'limit' => 500,
        'ips'   => $enriched,
    ]);
}

/**
 * ============================================================================
 * AJAX: Xóa các IP được chọn khỏi Google Ads
 * ============================================================================
 */
add_action('wp_ajax_tkgadm_gads_delete_ips', 'tkgadm_ajax_gads_delete_ips');
function tkgadm_ajax_gads_delete_ips() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer('tkgadm_gads_manager_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $raw_rns = $_POST['resource_names'] ?? [];
    if (!is_array($raw_rns) || empty($raw_rns)) {
        wp_send_json_error('Không có resource_name nào được chọn.');
    }

    // Sanitize: resource_name có dạng customers/xxx/customerNegativeCriteria/yyy
    $resource_names = array_filter(array_map('sanitize_text_field', $raw_rns));
    if (empty($resource_names)) {
        wp_send_json_error('Danh sách resource_name không hợp lệ.');
    }

    // Map resource names to IPs first so the local list can follow.
    $remote = tkgadm_list_connected_google_ads_ips();
    $ip_by_rn = [];
    if (!is_wp_error($remote)) {
        foreach ($remote as $entry) {
            $ip_by_rn[$entry['resource_name']] = $entry['ip_address'];
        }
    }

    $result = tkgadm_remove_connected_google_ads_ips(array_values($resource_names));

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    // Without this the next sync would upload the same IPs again.
    $local_removed = 0;
    $managed = array_fill_keys(tkgadm_get_managed_ips(), true);
    foreach ($resource_names as $rn) {
        if (empty($ip_by_rn[$rn])) {
            continue;
        }
        $remote_ip = $ip_by_rn[$rn];
        unset($managed[tkgadm_normalize_google_ads_ip($remote_ip) ?: $remote_ip]);
        $local_ip = preg_replace('/^(\d+\.\d+\.\d+)\.0\/24$/', '$1.*', $remote_ip);
        if (tkgadm_unblock_ip_internal($local_ip, 'Xóa khỏi Google Ads (trang Quản lý IP)', tkgadm_current_actor())) {
            $local_removed++;
        }
    }
    tkgadm_set_managed_ips(array_keys($managed));

    $count = count($resource_names);
    wp_send_json_success([
        'deleted' => $count,
        'message' => "Đã xóa $count IP khỏi Google Ads" . ($local_removed ? " và bỏ chặn $local_removed IP trên website" : '') . '.',
    ]);
}

/**
 * ============================================================================
 * AJAX: Xóa N IP cũ nhất khỏi Google Ads
 * ============================================================================
 */
add_action('wp_ajax_tkgadm_gads_delete_oldest', 'tkgadm_ajax_gads_delete_oldest');
function tkgadm_ajax_gads_delete_oldest() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer('tkgadm_gads_manager_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $count = max(1, min(TKGADM_GADS_LIMIT, intval($_POST['count'] ?? 50)));
    $freed = tkgadm_rotate_oldest_local($count, 'Xóa IP cũ nhất (thủ công)', tkgadm_current_actor());
    if (!$freed) {
        wp_send_json_success(['message' => 'Danh sách IP chặn đang trống.', 'freed' => 0]);
    }
    $sync = tkgadm_reconcile_google_ads();
    wp_send_json_success([
        'message' => "Đã bỏ chặn {$freed} IP cũ nhất (có thể hoàn tác trong Nhật ký). " . $sync['message'],
        'freed' => $freed,
    ]);
}

add_action('wp_ajax_tkgadm_gads_full_sync', 'tkgadm_ajax_gads_full_sync');
function tkgadm_ajax_gads_full_sync() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    check_ajax_referer('tkgadm_gads_manager_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    if (!function_exists('tkgadm_do_full_sync_google_ads')) {
        require_once plugin_dir_path(__FILE__) . 'module-google-ads.php';
    }

    $result = tkgadm_reconcile_google_ads();
    tkgadm_record_sync_result($result);

    if ($result['success']) {
        wp_send_json_success(['message' => $result['message']]);
    } else {
        wp_send_json_error($result['message']);
    }
}
