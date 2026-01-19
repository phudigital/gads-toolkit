<?php
/**
 * AJAX Functions
 * Tất cả AJAX handlers
 */

if (!defined('ABSPATH')) exit;

/**
 * Toggle block/unblock IP
 */
add_action('wp_ajax_tkgadm_toggle_block_ip', 'tkgadm_ajax_toggle_block_ip');
function tkgadm_ajax_toggle_block_ip() {
    check_ajax_referer('tkgadm_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền');
    }
    
    if (!isset($_POST['ip'])) {
        wp_send_json_error('Thiếu tham số IP');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    $ip = sanitize_text_field(wp_unslash($_POST['ip']));
    
    if (!tkgadm_validate_ip_pattern($ip)) {
        wp_send_json_error('IP không hợp lệ');
    }
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE ip_address = %s", $ip));
    
    if ($existing) {
        // Unblock
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($table, ['ip_address' => $ip]);
        wp_send_json_success(['message' => 'Đã bỏ chặn IP: ' . $ip, 'blocked' => false]);
    } else {
        // Block
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert($table, ['ip_address' => $ip]);
        wp_send_json_success(['message' => 'Đã chặn IP: ' . $ip, 'blocked' => true]);
    }
}

/**
 * Lấy dữ liệu biểu đồ theo IP
 */
add_action('wp_ajax_tkgadm_get_chart_data', 'tkgadm_ajax_get_chart_data');
function tkgadm_ajax_get_chart_data() {
    check_ajax_referer('tkgadm_chart', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền');
    }
    
    if (!isset($_POST['ip'])) {
        wp_send_json_error('Thiếu tham số IP');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    $ip = sanitize_text_field(wp_unslash($_POST['ip']));
    
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $query = $wpdb->prepare(
        "SELECT DATE_FORMAT(visit_time, '%%Y-%%m-%%d %%H:00:00') as hour,
                SUM(visit_count) as total
         FROM $table
         WHERE ip_address = %s
         GROUP BY hour
         ORDER BY hour ASC",
        $ip
    );
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $results = $wpdb->get_results($query);
    
    $labels = [];
    $data = [];
    
    foreach ($results as $row) {
        $labels[] = wp_date('d/m H:00', strtotime($row->hour));
        $data[] = (int)$row->total;
    }
    
    wp_send_json_success(['labels' => $labels, 'data' => $data]);
}

/**
 * Lấy chi tiết phiên truy cập theo IP
 */
add_action('wp_ajax_tkgadm_get_visit_details', 'tkgadm_ajax_get_visit_details');
function tkgadm_ajax_get_visit_details() {
    check_ajax_referer('tkgadm_chart', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền');
    }
    
    if (!isset($_POST['ip'])) {
        wp_send_json_error('Thiếu tham số IP');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    $ip = sanitize_text_field(wp_unslash($_POST['ip']));
    
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $query = $wpdb->prepare(
        "SELECT visit_time, url_visited, gclid, time_on_page, visit_count
         FROM $table
         WHERE ip_address = %s
         ORDER BY visit_time DESC",
        $ip
    );
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $results = $wpdb->get_results($query);
    
    $visits = [];
    foreach ($results as $row) {
        $parsed = wp_parse_url($row->url_visited);
        $utm_term = '-';
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
            $utm_term = isset($params['utm_term']) ? $params['utm_term'] : '-';
        }
        
        $visits[] = [
            'visit_time' => $row->visit_time,
            'url' => $row->url_visited,
            'gclid' => $row->gclid ? $row->gclid : '-',
            'utm_term' => $utm_term,
            'time_on_page' => (int)$row->time_on_page,
            'visit_count' => (int)$row->visit_count
        ];
    }
    
    wp_send_json_success(['visits' => $visits]);
}

/**
 * Cập nhật time on page
 */
add_action('wp_ajax_nopriv_tkgadm_update_time_on_page', 'tkgadm_ajax_update_time_on_page');
add_action('wp_ajax_tkgadm_update_time_on_page', 'tkgadm_ajax_update_time_on_page');
function tkgadm_ajax_update_time_on_page() {
    if (!isset($_POST['ip']) || !isset($_POST['url']) || !isset($_POST['time'])) {
        wp_send_json_error('Missing parameters');
        return;
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    
    $ip = sanitize_text_field(wp_unslash($_POST['ip']));
    $url = esc_url_raw(wp_unslash($_POST['url']));
    $time = intval($_POST['time']);
    $user_agent = isset($_POST['user_agent']) ? sanitize_textarea_field(wp_unslash($_POST['user_agent'])) : '';
    $gclid = isset($_POST['gclid']) ? sanitize_text_field(wp_unslash($_POST['gclid'])) : '';
    
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $query = $wpdb->prepare(
        "SELECT id, time_on_page FROM $table 
         WHERE ip_address = %s 
         AND url_visited = %s 
         AND user_agent = %s 
         AND gclid = %s
         ORDER BY visit_time DESC 
         LIMIT 1",
        $ip, $url, $user_agent, $gclid
    );
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $record = $wpdb->get_row($query);
    
    if ($record) {
        $new_time = max($record->time_on_page, $time);
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            ['time_on_page' => $new_time],
            ['id' => $record->id],
            ['%d'],
            ['%d']
        );
        
        wp_send_json_success(['updated' => true, 'time' => $new_time]);
    } else {
        wp_send_json_error('Record not found');
    }
}

/**
 * Lấy dữ liệu traffic analytics (cho submenu mới)
 */
add_action('wp_ajax_tkgadm_get_traffic_data', 'tkgadm_ajax_get_traffic_data');
function tkgadm_ajax_get_traffic_data() {
    check_ajax_referer('tkgadm_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền');
    }
    
    $period = isset($_POST['period']) ? sanitize_text_field(wp_unslash($_POST['period'])) : 'day';
    $from = isset($_POST['from']) ? sanitize_text_field(wp_unslash($_POST['from'])) : '';
    $to = isset($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : '';
    
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    
    // Xác định format theo period
    if ($period === 'week') {
        $date_format = '%%Y-%%u';
    } elseif ($period === 'month') {
        $date_format = '%%Y-%%m';
    } elseif ($period === 'quarter') {
        $date_format = "CONCAT(YEAR(visit_time), '-Q', QUARTER(visit_time))";
    } else {
        $date_format = '%%Y-%%m-%%d';
    }
    
    $where = "1=1";
    $params = [];
    
    if ($from) {
        $where .= " AND visit_time >= %s";
        $params[] = $from . ' 00:00:00';
    }
    if ($to) {
        $where .= " AND visit_time <= %s";
        $params[] = $to . ' 23:59:59';
    }
    
    // Query cho ads traffic: Đếm unique IP có ít nhất 1 phiên có gclid
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $query_ads = "SELECT DATE_FORMAT(visit_time, '$date_format') as period,
                         COUNT(DISTINCT ip_address) as total
                  FROM $table
                  WHERE $where 
                    AND ip_address IN (
                        SELECT DISTINCT ip_address 
                        FROM $table 
                        WHERE gclid IS NOT NULL AND gclid != ''
                    )
                  GROUP BY period
                  ORDER BY period ASC";
    
    // Query cho organic traffic: Đếm unique IP KHÔNG CÓ phiên nào có gclid VÀ có time_on_page hợp lệ
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $query_organic = "SELECT DATE_FORMAT(visit_time, '$date_format') as period,
                             COUNT(DISTINCT ip_address) as total
                      FROM $table
                      WHERE $where 
                        AND time_on_page IS NOT NULL
                        AND time_on_page > 0
                        AND ip_address NOT IN (
                            SELECT DISTINCT ip_address 
                            FROM $table 
                            WHERE gclid IS NOT NULL AND gclid != ''
                        )
                      GROUP BY period
                      ORDER BY period ASC";
    
    if (!empty($params)) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $query_ads = $wpdb->prepare($query_ads, ...$params);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $query_organic = $wpdb->prepare($query_organic, ...$params);
    }
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $ads_data = $wpdb->get_results($query_ads);
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $organic_data = $wpdb->get_results($query_organic);
    
    wp_send_json_success([
        'ads' => $ads_data,
        'organic' => $organic_data
    ]);
}

/**
 * Lấy chi tiết visits theo period và type (Ads/Organic)
 */
add_action('wp_ajax_tkgadm_get_period_details', 'tkgadm_ajax_get_period_details');
function tkgadm_ajax_get_period_details() {
    check_ajax_referer('tkgadm_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền');
    }
    
    $period_value = isset($_POST['period_value']) ? sanitize_text_field(wp_unslash($_POST['period_value'])) : '';
    $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'ads';
    $period_type = isset($_POST['period_type']) ? sanitize_text_field(wp_unslash($_POST['period_type'])) : 'day';
    
    if (empty($period_value)) {
        wp_send_json_error('Thiếu tham số');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    
    // Xác định điều kiện WHERE dựa trên period_type
    $where_period = '';
    if ($period_type === 'day') {
        $where_period = "DATE(visit_time) = %s";
        $params = [$period_value];
    } elseif ($period_type === 'week') {
        // Format: 2026-03 (year-week)
        list($year, $week) = explode('-', $period_value);
        $where_period = "YEAR(visit_time) = %d AND WEEK(visit_time, 1) = %d";
        $params = [(int)$year, (int)$week];
    } elseif ($period_type === 'month') {
        // Format: 2026-01
        $where_period = "DATE_FORMAT(visit_time, '%%Y-%%m') = %s";
        $params = [$period_value];
    } elseif ($period_type === 'quarter') {
        // Format: 2026-Q1
        list($year, $quarter) = explode('-Q', $period_value);
        $where_period = "YEAR(visit_time) = %d AND QUARTER(visit_time) = %d";
        $params = [(int)$year, (int)$quarter];
    }
    
    // Điều kiện type: Lấy TẤT CẢ phiên của IP thuộc nhóm
    if ($type === 'ads') {
        // Ads: Lấy tất cả phiên của IP có ít nhất 1 phiên có gclid
        $where_type = "AND ip_address IN (
            SELECT DISTINCT ip_address 
            FROM $table 
            WHERE gclid IS NOT NULL AND gclid != ''
        )";
    } else {
        // Organic: Lấy tất cả phiên của IP KHÔNG CÓ phiên nào có gclid VÀ có time_on_page hợp lệ
        $where_type = "AND ip_address NOT IN (
            SELECT DISTINCT ip_address 
            FROM $table 
            WHERE gclid IS NOT NULL AND gclid != ''
        ) AND ip_address IN (
            SELECT DISTINCT ip_address
            FROM $table
            WHERE time_on_page IS NOT NULL AND time_on_page > 0
        )";
    }
    
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $query = "SELECT ip_address, visit_time, url_visited, time_on_page, visit_count, gclid
              FROM $table
              WHERE $where_period $where_type
              ORDER BY ip_address, visit_time DESC
              LIMIT 500";
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $query = $wpdb->prepare($query, ...$params);
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $results = $wpdb->get_results($query);
    
    wp_send_json_success(['visits' => $results]);
}

/**
 * Xóa dữ liệu theo khoảng thời gian
 */
add_action('wp_ajax_tkgadm_delete_data', 'tkgadm_ajax_delete_data');
function tkgadm_ajax_delete_data() {
    check_ajax_referer('tkgadm_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền');
    }
    
    $from = isset($_POST['from']) ? sanitize_text_field(wp_unslash($_POST['from'])) : '';
    $to = isset($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : '';
    $older_than = isset($_POST['older_than']) ? intval($_POST['older_than']) : 0;
    
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    
    if ($older_than > 0) {
        // Xóa dữ liệu cũ hơn X ngày
        $date = date('Y-m-d H:i:s', strtotime("-$older_than days"));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE visit_time < %s", $date));
    } elseif ($from && $to) {
        // Xóa theo khoảng ngày
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE visit_time >= %s AND visit_time <= %s",
            $from . ' 00:00:00',
            $to . ' 23:59:59'
        ));
    } else {
        wp_send_json_error('Thiếu tham số');
        return;
    }
    
    wp_send_json_success(['deleted' => $deleted, 'message' => "Đã xóa $deleted bản ghi"]);
}
