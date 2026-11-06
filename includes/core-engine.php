<?php
/**
 * Core Engine
 * Contains: Database, Tracking, Auto-Block, Admin Assets & Menu
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/ip-utils.php';

/**
 * ============================================================================
 * 1. DATABASE & HELPERS
 * ============================================================================
 */

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
        KEY visit_time (visit_time),
        KEY gclid (gclid),
        KEY time_on_page (time_on_page)
    ) $charset_collate;";

    // Bảng IP bị chặn
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    $sql_blocked = "CREATE TABLE IF NOT EXISTS $table_blocked (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(255) NOT NULL,
        blocked_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reason TEXT DEFAULT NULL,
        visit_count INT DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY ip_address (ip_address)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_stats);
    dbDelta($sql_blocked);

    tkgadm_ensure_stats_indexes($table_stats);
    tkgadm_ensure_stats_columns($table_stats);
    if (function_exists('tkgadm_create_log_table')) {
        tkgadm_create_log_table();
    }
}

/**
 * Columns added after the first release (dbDelta cannot alter "IF NOT EXISTS" tables).
 * has_js: NULL = recorded server side only, 1 = the beacon ran (JavaScript executed).
 */
function tkgadm_ensure_stats_columns($table_stats) {
    global $wpdb;
    $table_name = str_replace('`', '``', $table_stats);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $columns = $wpdb->get_col("SHOW COLUMNS FROM `$table_name`", 0);
    if (is_array($columns) && $columns && !in_array('has_js', $columns, true)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("ALTER TABLE `$table_name` ADD COLUMN `has_js` TINYINT(1) DEFAULT NULL");
    }
}

/**
 * Ensure query-critical indexes exist on older installs.
 */
function tkgadm_ensure_stats_indexes($table_stats) {
    global $wpdb;

    $table_name = str_replace('`', '``', $table_stats);
    $existing_indexes = tkgadm_get_table_index_names($table_name);

    $required_indexes = [
        'ip_address'   => 'ip_address',
        'visit_time'   => 'visit_time',
        'gclid'        => 'gclid',
        'time_on_page' => 'time_on_page',
    ];

    foreach ($required_indexes as $index_name => $column_name) {
        if (in_array($index_name, $existing_indexes, true)) {
            continue;
        }

        $safe_index = str_replace('`', '``', $index_name);
        $safe_column = str_replace('`', '``', $column_name);
        $wpdb->query("ALTER TABLE `$table_name` ADD KEY `$safe_index` (`$safe_column`)");
    }
}

function tkgadm_stats_indexes_exist() {
    global $wpdb;

    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $table_name = str_replace('`', '``', $table_stats);
    $existing_indexes = tkgadm_get_table_index_names($table_name);
    if (empty($existing_indexes)) {
        return false;
    }

    foreach (['ip_address', 'visit_time', 'gclid', 'time_on_page'] as $index_name) {
        if (!in_array($index_name, $existing_indexes, true)) {
            return false;
        }
    }

    return true;
}

function tkgadm_get_table_index_names($table_name) {
    global $wpdb;

    $indexes = $wpdb->get_results("SHOW INDEX FROM `$table_name`");
    if (!is_array($indexes)) {
        return [];
    }

    $index_names = [];
    foreach ($indexes as $index) {
        if (isset($index->Key_name)) {
            $index_names[] = $index->Key_name;
        }
    }

    return array_unique($index_names);
}

/**
 * Kiểm tra và cập nhật DB khi update version
 */
// Schema must be current before tracking runs, also after auto-updates / WP-CLI
// when nobody opens wp-admin: compare the stored version on every request (autoloaded option).
add_action('plugins_loaded', 'tkgadm_maybe_upgrade_schema', 5);
function tkgadm_maybe_upgrade_schema() {
    $installed = get_option('tkgadm_version');
    if ($installed === GADS_TOOLKIT_VERSION) {
        return;
    }
    // Sites upgrading from < 4.4 keep all existing traffic data unless the owner opts in.
    if ($installed && version_compare($installed, '4.4.0', '<') && get_option('tkgadm_stats_retention_days', null) === null) {
        add_option('tkgadm_stats_retention_days', 0);
    }
    tkgadm_create_tables();
    update_option('tkgadm_version', GADS_TOOLKIT_VERSION);
    tkgadm_schedule_core_events();
}

add_action('admin_init', 'tkgadm_check_upgrade');
function tkgadm_check_upgrade() {
    if (get_option('tkgadm_version') !== GADS_TOOLKIT_VERSION || !tkgadm_stats_indexes_exist()) {
        // 1. Cập nhật cấu trúc bảng
        tkgadm_create_tables();
        update_option('tkgadm_version', GADS_TOOLKIT_VERSION);
        tkgadm_schedule_core_events();
    }

    // Tạo bảng whitelist nếu chưa có (cho cài đặt cũ)
    if (function_exists('tkgadm_create_whitelist_table')) {
        global $wpdb;
        $wl_table = $wpdb->prefix . 'gads_toolkit_whitelist';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if (!$wpdb->get_var("SHOW TABLES LIKE '$wl_table'")) {
            tkgadm_create_whitelist_table();
        }
    }
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
 * Is $ip covered by a local block (exact, a.b.c.* or IPv6 /64)?
 */
function tkgadm_is_ip_blocked($ip) {
    if (!tkgadm_license_is_valid()) { return false; }
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $patterns = $wpdb->get_col($wpdb->prepare(
        "SELECT ip_address FROM $table WHERE ip_address = %s OR ip_address LIKE %s OR ip_address LIKE %s",
        $ip, '%' . $wpdb->esc_like('*'), '%' . $wpdb->esc_like('/') . '%'
    ));
    foreach ((array) $patterns as $pattern) {
        if (tkgadm_pattern_matches_ip($pattern, $ip)) {
            return true;
        }
    }
    return false;
}

/**
 * Insert an IP/range into the local block list, log it and queue Google Ads sync.
 * Returns true if newly blocked; false if whitelisted, already blocked or on error.
 *
 * @param string $actor 'auto' or a user login.
 */
function tkgadm_block_ip_internal($ip, $reason = '', $actor = 'auto') {
    if (!tkgadm_license_is_valid()) { return false; }

    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    $stats_table = $wpdb->prefix . 'gads_toolkit_stats';

    if (function_exists('tkgadm_is_ip_whitelisted') && tkgadm_is_ip_whitelisted($ip)) {
        return false;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE ip_address = %s", $ip))) {
        return false;
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    $visit_count = $wpdb->get_var($wpdb->prepare("SELECT SUM(visit_count) FROM $stats_table WHERE ip_address = %s", $ip));

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $result = $wpdb->insert($table, [
        'ip_address' => $ip,
        'blocked_time' => current_time('mysql'),
        'reason' => sanitize_text_field($reason),
        'visit_count' => intval($visit_count)
    ]);
    if ($result === false) {
        return false;
    }

    if (function_exists('tkgadm_log_action')) {
        tkgadm_log_action('block', $ip, $reason, $actor);
    }
    if (function_exists('tkgadm_queue_sync')) {
        tkgadm_queue_sync();
    }
    return true;
}

/**
 * Auto-block rules, validated. Each: limit (>=1), duration (>=1), unit HOUR|DAY|WEEK.
 */
function tkgadm_get_block_rules() {
    $rules = get_option('tkgadm_auto_block_rules', []);
    if (!is_array($rules)) {
        return [];
    }
    $clean = [];
    foreach ($rules as $rule) {
        $rule = tkgadm_sanitize_block_rule($rule);
        if ($rule) {
            $clean[] = $rule;
        }
    }
    return $clean;
}

function tkgadm_sanitize_block_rule($rule) {
    if (!is_array($rule)) {
        return null;
    }
    $limit = isset($rule['limit']) ? (int) $rule['limit'] : 0;
    $duration = isset($rule['duration']) ? (int) $rule['duration'] : 0;
    $unit = isset($rule['unit']) ? strtoupper((string) $rule['unit']) : 'HOUR';
    if ($limit < 1 || $duration < 1) {
        return null;
    }
    if (!in_array($unit, ['HOUR', 'DAY', 'WEEK'], true)) {
        $unit = 'HOUR';
    }
    return ['limit' => min($limit, 10000), 'duration' => min($duration, 365), 'unit' => $unit];
}

/** Local-time (same clock as visit_time) start of a rule's window. */
function tkgadm_rule_window_start($rule) {
    $seconds = ['HOUR' => HOUR_IN_SECONDS, 'DAY' => DAY_IN_SECONDS, 'WEEK' => WEEK_IN_SECONDS];
    return wp_date('Y-m-d H:i:s', time() - $rule['duration'] * $seconds[$rule['unit']]);
}

function tkgadm_rule_text($rule) {
    $units = ['HOUR' => 'giờ', 'DAY' => 'ngày', 'WEEK' => 'tuần'];
    return $rule['limit'] . ' click trong ' . $rule['duration'] . ' ' . $units[$rule['unit']];
}

/**
 * Notices for real-time blocks are sent from cron, not from the visitor's request.
 */
function tkgadm_queue_block_notice($ip, $rule_text) {
    $pending = get_option('tkgadm_pending_block_notices', []);
    if (!is_array($pending)) {
        $pending = [];
    }
    if (count($pending) < 200) {
        $pending[] = ['ip' => $ip, 'rule_text' => $rule_text];
        update_option('tkgadm_pending_block_notices', $pending, false);
    }
    if (function_exists('tkgadm_queue_sync')) {
        tkgadm_queue_sync();
    }
}

function tkgadm_flush_block_notices() {
    $pending = get_option('tkgadm_pending_block_notices', []);
    if (empty($pending) || !is_array($pending)) {
        return;
    }
    delete_option('tkgadm_pending_block_notices');
    tkgadm_send_auto_block_notification($pending);
}

/**
 * Core recurring events (idempotent).
 */
function tkgadm_schedule_core_events() {
    if (!wp_next_scheduled('tkgadm_daily_maintenance')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tkgadm_daily_maintenance');
    }
    $auto_sync = get_option('tkgadm_gads_auto_sync', '1') === '1';
    $next_sync = wp_next_scheduled('tkgadm_hourly_sync_event');
    if ($auto_sync && !$next_sync) {
        wp_schedule_event(time() + 300, 'hourly', 'tkgadm_hourly_sync_event');
    } elseif (!$auto_sync && $next_sync) {
        wp_clear_scheduled_hook('tkgadm_hourly_sync_event');
    }
    $has_rules = !empty(tkgadm_get_block_rules());
    $next_scan = wp_next_scheduled('tkgadm_auto_block_scan_event');
    if ($has_rules && !$next_scan) {
        wp_schedule_event(time() + 60, 'tkgadm_15_minutes', 'tkgadm_auto_block_scan_event');
    } elseif (!$has_rules && $next_scan) {
        wp_clear_scheduled_hook('tkgadm_auto_block_scan_event');
    }
}

/** Every hook this plugin schedules; cleared on deactivation. */
function tkgadm_all_cron_hooks() {
    return ['tkgadm_auto_block_scan_event', 'tkgadm_hourly_sync_event', 'tkgadm_sync_now_event',
        'tkgadm_daily_maintenance', 'tkgadm_license_refresh_event', 'tkgadm_hourly_alert', 'tkgadm_daily_report'];
}

/**
 * Daily housekeeping: stats retention and activity log trimming.
 */
add_action('tkgadm_daily_maintenance', 'tkgadm_run_daily_maintenance');
function tkgadm_run_daily_maintenance() {
    global $wpdb;
    $days = (int) get_option('tkgadm_stats_retention_days', 180);
    if ($days > 0) {
        $table = $wpdb->prefix . 'gads_toolkit_stats';
        $cutoff = wp_date('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        // Delete in batches to keep each statement short on large tables.
        for ($i = 0; $i < 50; $i++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE visit_time < %s LIMIT 5000", $cutoff));
            if (!$deleted) {
                break;
            }
        }
    }
    if (function_exists('tkgadm_trim_activity_log')) {
        tkgadm_trim_activity_log();
    }
}


/**
 * ============================================================================
 * 3. ADMIN INIT (MENU & ASSETS)
 * ============================================================================
 */

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
        'Quản lý dữ liệu',
        'Quản lý dữ liệu',
        'manage_options',
        'tkgad-maintenance',
        'tkgadm_render_maintenance_page'
    );


    
    add_submenu_page(
        'tkgad-moi',
        'Cấu hình & Tích hợp',
        'Cấu hình & Tích hợp',
        'manage_options',
        'tkgad-settings',
        'tkgadm_render_settings_page'
    );
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

    // Prebuilt Tailwind utilities (tools/tailwind), scoped to .wp-wrap, no preflight.
    wp_enqueue_style(
        'tkgadm-tailwind',
        GADS_TOOLKIT_URL . 'assets/admin-tailwind.css',
        array(),
        GADS_TOOLKIT_VERSION
    );

    wp_enqueue_style(
        'tkgadm-inter-font',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
        array(),
        null
    );

    wp_enqueue_style(
        'tkgadm-font-awesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
        array(),
        '6.0.0'
    );

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
 * ============================================================================
 * 4. AUTO-BLOCK SYSTEM
 * ============================================================================
 */

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
    if (!tkgadm_license_is_valid()) { return; }

    if (!get_option('tkgadm_auto_block_enabled')) {
        return;
    }

    $rules = tkgadm_get_block_rules();
    if (empty($rules)) {
        return;
    }

    global $wpdb;
    $stats_table = $wpdb->prefix . 'gads_toolkit_stats';
    $new_blocked = [];

    foreach ($rules as $rule) {
        // COUNT(DISTINCT gclid): reloads of the same click do not inflate the count.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ip_address, COUNT(DISTINCT gclid) AS click_count
             FROM $stats_table
             WHERE visit_time >= %s AND gclid IS NOT NULL AND gclid != ''
             GROUP BY ip_address
             HAVING COUNT(DISTINCT gclid) >= %d",
            tkgadm_rule_window_start($rule), $rule['limit']
        ));

        foreach ((array) $results as $row) {
            $reason = sprintf('Chặn Tự Động: %d GCLID unique (Quy tắc: %s)', $row->click_count, tkgadm_rule_text($rule));
            if (tkgadm_block_ip_internal($row->ip_address, $reason, 'auto')) {
                $new_blocked[] = ['ip' => $row->ip_address, 'rule_text' => tkgadm_rule_text($rule)];
            }
        }
    }

    // Real-time blocks waiting for a notice go out together with this run.
    $pending = get_option('tkgadm_pending_block_notices', []);
    if (is_array($pending) && $pending) {
        delete_option('tkgadm_pending_block_notices');
        $new_blocked = array_merge($pending, $new_blocked);
    }
    if ($new_blocked) {
        tkgadm_send_auto_block_notification($new_blocked);
    }
}

/**
 * Gửi thông báo khi có IP bị chặn tự động
 */
function tkgadm_send_auto_block_notification($blocked_ips_data) {
    if (!tkgadm_license_is_valid()) { return; }

    if (empty($blocked_ips_data)) return;

    $count = count($blocked_ips_data);
    
    $customer_id = get_option('tkgadm_gads_customer_id', 'Chưa kết nối');
    
    // Email message
    $message_email = "Đã chặn {$count} IP click ảo GAds {$customer_id}\n";
    $message_telegram = "Đã chặn {$count} IP click ảo GAds {$customer_id}\n";
    
    // Danh sách IP kèm rule
    foreach ($blocked_ips_data as $data) {
        $ip = $data['ip'];
        $rule_text = $data['rule_text']; // Ví dụ: "2 click trong 1 ngày"
        $message_email .= "├ {$ip}\n├ {$rule_text}\n";
        $message_telegram .= "├ `{$ip}`\n├ {$rule_text}\n";
    }
    
    $message_email .= "Mở Dashboard (" . admin_url('admin.php?page=tkgad-moi') . ")";
    $message_telegram .= "[Mở Dashboard](" . admin_url('admin.php?page=tkgad-moi') . ")";
    
    // Gửi thông báo theo platform đã chọn
    if (get_option('tkgadm_alert_platform_email', '1') === '1') {
        if (function_exists('tkgadm_send_email_notification')) {
            tkgadm_send_email_notification('🛡️ Chặn click ảo tự động - GAds Toolkit', $message_email);
        }
    }
    if (get_option('tkgadm_alert_platform_telegram', '1') === '1') {
        if (function_exists('tkgadm_send_telegram_message')) {
            tkgadm_send_telegram_message($message_telegram);
        }
    }
}
