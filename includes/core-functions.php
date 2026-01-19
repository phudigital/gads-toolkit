<?php
/**
 * Core Functions
 * Database, Tracking, Validation, Helpers
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'google-ads-api.php';
require_once plugin_dir_path(__FILE__) . 'admin-google-ads.php';

/**
 * Tạo bảng database khi activate plugin
 */
function tkgadm_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Bảng thống kê
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $sql_stats = "CREATE TABLE IF NOT EXISTS $table_stats (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(255) NOT NULL,
        visit_time DATETIME NOT NULL,
        url_visited TEXT NOT NULL,
        user_agent TEXT DEFAULT NULL,
        gclid VARCHAR(255) DEFAULT NULL,
        time_on_page INT DEFAULT 0,
        visit_count BIGINT(20) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY ip_address (ip_address),
        KEY visit_time (visit_time)
    ) $charset_collate;";

    // Bảng IP bị chặn
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    $sql_blocked = "CREATE TABLE IF NOT EXISTS $table_blocked (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(255) NOT NULL,
        blocked_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY ip_address (ip_address)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_stats);
    dbDelta($sql_blocked);
}

/**
 * Validate IP pattern (IPv4 với wildcard, IPv6)
 */
function tkgadm_validate_ip_pattern($pattern) {
    // IPv6
    if (strpos($pattern, ':') !== false) {
        return filter_var($pattern, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }
    
    // IPv4 với wildcard
    $regex = '/^(\d{1,3}|\*)\.(\d{1,3}|\*)\.(\d{1,3}|\*)\.(\d{1,3}|\*)$/';
    if (!preg_match($regex, $pattern)) {
        return false;
    }
    
    $parts = explode('.', $pattern);
    foreach ($parts as $part) {
        if ($part !== '*' && ($part < 0 || $part > 255)) {
            return false;
        }
    }
    
    return true;
}

/**
 * Kiểm tra IP có bị chặn không
 */
function tkgadm_is_ip_blocked($ip) {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $blocked_ips = $wpdb->get_col("SELECT ip_address FROM $table");
    
    foreach ($blocked_ips as $blocked_ip) {
        if (strpos($blocked_ip, '*') !== false) {
            $pattern = str_replace('.', '\.', $blocked_ip);
            $pattern = str_replace('*', '\d{1,3}', $pattern);
            if (preg_match('/^' . $pattern . '$/', $ip)) {
                return true;
            }
        } elseif ($blocked_ip === $ip) {
            return true;
        }
    }
    
    return false;
}

/**
 * Ghi log truy cập (hook vào wp_head)
 */
add_action('wp_head', 'tkgadm_track_visit');
function tkgadm_track_visit() {
    if (is_admin()) return;

    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';

    if (!isset($_SERVER['REMOTE_ADDR']) || !isset($_SERVER['REQUEST_URI'])) {
        return;
    }

    $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    $visit_time = current_time('mysql');
    $url = esc_url_raw(home_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))));
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    
    // Trích xuất gclid hoặc gbraid
    $gclid = '';
    $parsed = wp_parse_url($url);
    if (isset($parsed['query'])) {
        parse_str($parsed['query'], $params);
        $gclid = isset($params['gclid']) ? sanitize_text_field($params['gclid']) : '';
        if (empty($gclid) && isset($params['gbraid'])) {
            $gclid = sanitize_text_field($params['gbraid']);
        }
    }

    // Xác định loại truy cập
    $has_gad_source = strpos($url, 'gad_source') !== false;
    $has_click_id = !empty($gclid);
    
    // Nếu là Organic (không phải Ads), kiểm tra và loại bỏ Bot
    if (!$has_gad_source && !$has_click_id) {
        // Danh sách Bot phổ biến
        $bots = array('bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'facebookexternalhit', 'whatsapp', 'curl', 'wget', 'python', 'java', 'go-http');
        foreach ($bots as $bot) {
            if (stripos($user_agent, $bot) !== false) {
                return; // Bỏ qua Bot
            }
        }
    }

    // Kiểm tra IP có bị chặn không
    if (tkgadm_is_ip_blocked($ip)) {
        wp_die('Access Denied', 'Blocked', array('response' => 403));
    }

    // Kiểm tra entry đã tồn tại trong vòng 30 phút gần đây
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table 
         WHERE ip_address = %s 
         AND url_visited = %s 
         AND user_agent = %s 
         AND gclid = %s
         AND visit_time >= DATE_SUB(%s, INTERVAL 30 MINUTE)
         ORDER BY visit_time DESC
         LIMIT 1",
        $ip, $url, $user_agent, $gclid, $visit_time
    ));

    if ($existing) {
        // Cập nhật record hiện tại (cùng phiên trong 30 phút)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            ['visit_count' => $existing->visit_count + 1, 'visit_time' => $visit_time],
            ['id' => $existing->id]
        );
    } else {
        // Tạo record mới (phiên mới hoặc lần đầu)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert($table, [
            'ip_address' => $ip,
            'visit_time' => $visit_time,
            'url_visited' => $url,
            'user_agent' => $user_agent,
            'gclid' => $gclid,
            'time_on_page' => 0
        ]);
    }
}

/**
 * Enqueue time tracker script
 */
add_action('wp_enqueue_scripts', 'tkgadm_enqueue_time_tracker');
function tkgadm_enqueue_time_tracker() {
    // Kiểm tra và loại bỏ Bot
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    $bots = array('bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'facebookexternalhit', 'whatsapp', 'curl', 'wget', 'python', 'java', 'go-http');
    
    foreach ($bots as $bot) {
        if (stripos($user_agent, $bot) !== false) {
            return; // Không load script cho Bot
        }
    }
    
    wp_enqueue_script(
        'tkgadm-time-tracker',
        GADS_TOOLKIT_URL . 'assets/time-tracker.js',
        array(),
        GADS_TOOLKIT_VERSION,
        true
    );
    
    $user_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    wp_localize_script('tkgadm-time-tracker', 'tkgadm_tracker', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'user_ip' => $user_ip
    ));
}

/**
 * Enqueue admin assets
 */
add_action('admin_enqueue_scripts', 'tkgadm_enqueue_admin_assets');
function tkgadm_enqueue_admin_assets($hook) {
    // Load trên tất cả trang của plugin
    if (strpos($hook, 'tkgad') === false) {
        return;
    }

    wp_enqueue_style(
        'tkgadm-admin-style',
        GADS_TOOLKIT_URL . 'assets/admin-style.css',
        array(),
        GADS_TOOLKIT_VERSION
    );

    wp_enqueue_script(
        'tkgadm-chart',
        GADS_TOOLKIT_URL . 'assets/chart.umd.min.js',
        array(),
        '4.4.0',
        true
    );

    wp_enqueue_script(
        'tkgadm-admin-script',
        GADS_TOOLKIT_URL . 'assets/admin-script.js',
        array('jquery', 'tkgadm-chart'),
        GADS_TOOLKIT_VERSION,
        true
    );

    wp_localize_script('tkgadm-admin-script', 'tkgadm_vars', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('tkgadm_nonce'),
        'nonce_chart' => wp_create_nonce('tkgadm_chart'),
        'nonce_block' => wp_create_nonce('tkgadm_nonce') // Dùng chung nonce
    ));
}

/**
 * Thêm menu admin
 */
add_action('admin_menu', 'tkgadm_add_admin_menu');
function tkgadm_add_admin_menu() {
    add_menu_page(
        'Google Ads Fraud Toolkit',
        'GAds Toolkit',
        'manage_options',
        'tkgad-moi',
        'tkgadm_render_dashboard_page',
        'dashicons-chart-bar'
    );
    
    add_submenu_page(
        'tkgad-moi',
        'Thống kê IP Ads',
        'Thống kê IP Ads',
        'manage_options',
        'tkgad-moi',
        'tkgadm_render_dashboard_page'
    );
    
    add_submenu_page(
        'tkgad-moi',
        'Thống kê Traffic',
        'Thống kê Traffic',
        'manage_options',
        'tkgad-analytics',
        'tkgadm_render_analytics_page'
    );
    
    add_submenu_page(
        'tkgad-moi',
        'Quản lý dữ liệu',
        'Quản lý dữ liệu',
        'manage_options',
        'tkgad-maintenance',
        'tkgadm_render_maintenance_page'
    );
    
    add_submenu_page(
        'tkgad-moi',
        'Cấu hình Thông báo',
        'Cấu hình Thông báo',
        'manage_options',
        'tkgad-notifications',
        'tkgadm_render_notifications_page'
    );

    add_submenu_page(
        'tkgad-moi',
        'Cấu hình Google Ads',
        'Cấu hình Google Ads',
        'manage_options',
        'tkgad-google-ads',
        'tkgadm_render_google_ads_page'
    );
}
