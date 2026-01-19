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
            // Wildcard matching - compare each octet
            $blocked_parts = explode('.', $blocked_ip);
            $ip_parts = explode('.', $ip);
            
            // Both must be valid IPv4 (4 octets)
            if (count($blocked_parts) !== 4 || count($ip_parts) !== 4) {
                continue;
            }
            
            $match = true;
            for ($i = 0; $i < 4; $i++) {
                if ($blocked_parts[$i] !== '*' && $blocked_parts[$i] !== $ip_parts[$i]) {
                    $match = false;
                    break;
                }
            }
            
            if ($match) {
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
    
    // FIX: Construct URL đúng cách để giữ query string
    $request_uri = wp_unslash($_SERVER['REQUEST_URI']);
    $url = esc_url_raw(home_url($request_uri));
    
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    
    // FIX: Trích xuất gclid/gbraid trực tiếp từ $_GET (reliable hơn parse URL)
    $gclid = '';
    if (isset($_GET['gclid'])) {
        $gclid = sanitize_text_field(wp_unslash($_GET['gclid']));
    } elseif (isset($_GET['gbraid'])) {
        $gclid = sanitize_text_field(wp_unslash($_GET['gbraid']));
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

    // NOTE: Plugin CHỈ đồng bộ IP lên Google Ads để chặn quảng cáo
    // KHÔNG chặn truy cập website - người dùng vẫn vào trang bình thường

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

/**
 * Thêm custom cron interval (15 phút)
 */
add_filter('cron_schedules', 'tkgadm_add_cron_interval');
function tkgadm_add_cron_interval($schedules) {
    $schedules['tkgadm_15_minutes'] = array(
        'interval' => 900, // 15 phút
        'display'  => 'Mỗi 15 phút'
    );
    return $schedules;
}

/**
 * Thực hiện quét và chặn tự động (Cron Job)
 */
add_action('tkgadm_auto_block_scan_event', 'tkgadm_run_auto_block_scan');
function tkgadm_run_auto_block_scan() {
    if (!get_option('tkgadm_auto_block_enabled')) {
        return;
    }
    
    $rules = get_option('tkgadm_auto_block_rules', []);
    if (empty($rules) || !is_array($rules)) {
        return;
    }
    
    global $wpdb;
    $stats_table = $wpdb->prefix . 'gads_toolkit_stats';
    $blocked_table = $wpdb->prefix . 'gads_toolkit_blocked';
    
    $new_blocked_ips = [];
    
    foreach ($rules as $rule) {
        $limit = intval($rule['limit']);
        $duration = intval($rule['duration']);
        $unit = strtoupper($rule['unit']); // HOUR, DAY, WEEK
        
        // Validate unit to prevent SQL injection or errors
        if (!in_array($unit, ['HOUR', 'DAY', 'WEEK', 'MONTH'])) $unit = 'HOUR';
        if ($unit === 'WEEK') {
            $duration *= 7;
            $unit = 'DAY';
        }
        
        // Query IPs thỏa mãn điều kiện (Có gclid = Click Ad)
        // phpcs:ignore WordPress.DB.PreparedSQL.StartWithParens
        $sql = "SELECT ip_address, COUNT(*) as click_count 
                FROM $stats_table 
                WHERE visit_time >= DATE_SUB(NOW(), INTERVAL %d $unit)
                AND gclid IS NOT NULL AND gclid != ''
                GROUP BY ip_address 
                HAVING click_count >= %d";
                
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results($wpdb->prepare($sql, $duration, $limit));
        
        foreach ($results as $row) {
            $ip = $row->ip_address;
            
            // Block IP
            if (tkgadm_block_ip_internal($ip, "Auto Block: $rule[limit] clicks in $rule[duration] $rule[unit]")) {
                $new_blocked_ips[] = $ip;
            }
        }
    }
    
    // Đồng bộ lên Google Ads nếu có IP mới bị chặn
    if (!empty($new_blocked_ips)) {
        require_once plugin_dir_path(__FILE__) . 'google-ads-api.php';
        $sync_result = tkgadm_sync_ip_to_google_ads($new_blocked_ips);
        
        // Log result
        update_option('tkgadm_last_auto_block_sync', [
            'time' => time(), 
            'count' => count($new_blocked_ips),
            'result' => $sync_result
        ]);
        
        // Gửi thông báo
        tkgadm_send_auto_block_notification($new_blocked_ips, $rules);
    }
}

/**
 * Gửi thông báo khi có IP bị chặn tự động
 */
function tkgadm_send_auto_block_notification($blocked_ips, $rules) {
    $count = count($blocked_ips);
    
    // Email message
    $message_email = "🛡️ CHẶN CLICK ẢO TỰ ĐỘNG\n\n";
    $message_email .= "Hệ thống đã tự động chặn {$count} IP vi phạm quy tắc:\n\n";
    
    // Telegram message
    $message_telegram = "🛡️ *CHẶN CLICK ẢO TỰ ĐỘNG*\n\n";
    $message_telegram .= "Hệ thống đã tự động chặn *{$count} IP* vi phạm quy tắc:\n\n";
    
    // Danh sách IP
    foreach ($blocked_ips as $ip) {
        $message_email .= "• {$ip}\n";
        $message_telegram .= "• `{$ip}`\n";
    }
    
    $message_email .= "\n=== QUY TẮC ÁP DỤNG ===\n";
    $message_telegram .= "\n*Quy tắc áp dụng:*\n";
    
    foreach ($rules as $rule) {
        $unit_text = $rule['unit'] === 'hour' ? 'giờ' : ($rule['unit'] === 'day' ? 'ngày' : 'tuần');
        $message_email .= "- {$rule['limit']} click trong {$rule['duration']} {$unit_text}\n";
        $message_telegram .= "├ {$rule['limit']} click trong {$rule['duration']} {$unit_text}\n";
    }
    
    $message_email .= "\nCác IP này đã được đồng bộ lên Google Ads.\n";
    $message_email .= "Dashboard: " . admin_url('admin.php?page=tkgad-moi');
    
    $message_telegram .= "\n✅ Đã đồng bộ lên Google Ads\n";
    $message_telegram .= "👉 [Xem Dashboard](" . admin_url('admin.php?page=tkgad-moi') . ")";
    
    // Gửi thông báo theo platform đã chọn
    if (get_option('tkgadm_alert_platform_email', '1') === '1') {
        require_once plugin_dir_path(__FILE__) . 'notification-functions.php';
        tkgadm_send_email_notification('🛡️ Chặn click ảo tự động - GAds Toolkit', $message_email);
    }
    if (get_option('tkgadm_alert_platform_telegram', '1') === '1') {
        require_once plugin_dir_path(__FILE__) . 'notification-functions.php';
        tkgadm_send_telegram_message($message_telegram);
    }
}

/**
 * Helper: Insert IP into Blocked List safely
 * Returns true if newly blocked, false if already blocked or error
 */
function tkgadm_block_ip_internal($ip, $reason = '') {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    
    // Check exist
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE ip_address = %s", $ip));
    
    if ($exists) {
        return false;
    }
    
    // Insert
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $result = $wpdb->insert($table, [
        'ip_address' => $ip,
        'blocked_time' => current_time('mysql')
    ]);
    
    return $result !== false;
}
