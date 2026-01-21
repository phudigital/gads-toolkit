<?php
/**
 * Module: Analytics & Dashboard
 * Pages: Dashboard (Home), Traffic Analytics
 * Functions: Renders UI, Chart AJAX, Data Processing
 */

if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * 1. DASHBOARD PAGE (Thống kê IP Ads)
 * ============================================================================
 */
function tkgadm_render_dashboard_page() {
    global $wpdb;
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    
    // Lấy ngày cũ nhất và mới nhất từ IP Ads
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $date_range = $wpdb->get_row("SELECT 
        DATE(MIN(visit_time)) as oldest,
        DATE(MAX(visit_time)) as newest
        FROM $table_stats
        WHERE gclid IS NOT NULL AND gclid != ''");
    
    // Mặc định hiển thị 30 ngày gần nhất (theo giờ WordPress)
    $default_from = date('Y-m-d', strtotime('-30 days', current_time('timestamp')));
    $default_to = current_time('Y-m-d');
    
    // Lấy tham số filter (nếu user đã chọn thì dùng, không thì dùng default)
    $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : $default_from;
    $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : $default_to;
    $show_blocked_only = isset($_GET['show_blocked']) && $_GET['show_blocked'] === '1';
    
    // Build query
    $where = "1=1";
    $params = [];
    
    if ($date_from) {
        $where .= " AND visit_time >= %s";
        $params[] = $date_from . ' 00:00:00';
    }
    if ($date_to) {
        $where .= " AND visit_time <= %s";
        $params[] = $date_to . ' 23:59:59';
    }
    
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $query = "SELECT 
                ip_address,
                MAX(visit_time) as last_visit,
                SUM(visit_count) as total_visits,
                COUNT(DISTINCT CASE WHEN gclid IS NOT NULL AND gclid != '' THEN gclid END) as ad_clicks,
                GROUP_CONCAT(DISTINCT url_visited SEPARATOR '|||') as urls
              FROM $table_stats
              WHERE $where AND (gclid IS NOT NULL AND gclid != '')
              GROUP BY ip_address
              ORDER BY last_visit DESC";
    
    if (!empty($params)) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $query = $wpdb->prepare($query, ...$params);
    }
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $results = $wpdb->get_results($query);
    
    // Lấy danh sách IP bị chặn
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $blocked_ips = $wpdb->get_col("SELECT ip_address FROM $table_blocked");
    
    // Nếu chỉ xem IP blocked, query lại để lấy TẤT CẢ IP blocked (kể cả không có trong stats)
    if ($show_blocked_only) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query = "SELECT 
                    b.ip_address,
                    COALESCE(MAX(s.visit_time), b.blocked_time) as last_visit,
                    COALESCE(SUM(s.visit_count), 0) as total_visits,
                    COUNT(DISTINCT CASE WHEN s.gclid IS NOT NULL AND s.gclid != '' THEN s.gclid END) as ad_clicks,
                    GROUP_CONCAT(DISTINCT s.url_visited SEPARATOR '|||') as urls
                  FROM $table_blocked b
                  LEFT JOIN $table_stats s ON b.ip_address = s.ip_address AND $where
                  GROUP BY b.ip_address
                  ORDER BY last_visit DESC";
        
        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $query = $wpdb->prepare($query, ...$params);
        }
        
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results($query);
    }
    
    // Get plugin version
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugin_data = get_plugin_data(GADS_TOOLKIT_PATH . 'gads-toolkit.php');
    $plugin_version = $plugin_data['Version'];
    
    ?>
    <?php
    // Calculate Diff Days for Select Option
    $dt1 = new DateTime($date_from);
    $dt2 = new DateTime($date_to);
    $diff = $dt2->diff($dt1)->days + 1; // Inclusive
    
    $selected_period = 'custom';
    $valid_periods = [1, 7, 15, 30, 60, 180];
    if (in_array($diff, $valid_periods)) {
        $selected_period = $diff;
    }
    
    $custom_style = ($selected_period === 'custom') ? 'display: inline-flex;' : 'display: none;';
    $chart_style = ($diff === 1) ? 'display: none;' : 'display: block;';
    ?>
    <div class="wrap">
        <div class="tkgadm-wrap">
            <div class="tkgadm-header">
                <h1>📊 Thống Kê IP Ads <span style="font-size: 0.5em; color: #666; font-weight: normal; vertical-align: middle;">v<?php echo esc_html($plugin_version); ?></span></h1>

                <div class="tkgadm-filters">
                    <!-- Time Period Selector -->
                    <div style="display: inline-flex; gap: 8px; align-items: center; background: white; padding: 8px 12px; border-radius: 8px;">
                        <label for="time-period" style="color: #666; font-size: 13px; margin: 0;">Thời gian:</label>
                        <select id="time-period" class="tkgadm-input" style="padding: 6px 12px; border-radius: 6px; border: 1px solid #ddd; font-size: 13px;">
                            <option value="1" <?php selected($selected_period, 1); ?>>Hôm nay</option>
                            <option value="7" <?php selected($selected_period, 7); ?>>7 ngày gần nhất</option>
                            <option value="15" <?php selected($selected_period, 15); ?>>15 ngày gần nhất</option>
                            <option value="30" <?php selected($selected_period, 30); ?>>30 ngày gần nhất</option>
                            <option value="60" <?php selected($selected_period, 60); ?>>60 ngày gần nhất</option>
                            <option value="180" <?php selected($selected_period, 180); ?>>180 ngày gần nhất</option>
                            <option value="custom" <?php selected($selected_period, 'custom'); ?>>📅 Tùy chỉnh...</option>
                        </select>
                    </div>
                    
                    <!-- Custom Date Range -->
                    <div id="custom-date-range" style="<?php echo $custom_style; ?> gap: 5px; align-items: center; background: white; padding: 8px 12px; border-radius: 8px;">
                        <input type="date" id="date-from" class="tkgadm-input" style="width: 150px; padding: 6px; font-size: 13px;" value="<?php echo esc_attr($date_from); ?>" title="Từ ngày">
                        <span style="color: #999;">→</span>
                        <input type="date" id="date-to" class="tkgadm-input" style="width: 150px; padding: 6px; font-size: 13px;" value="<?php echo esc_attr($date_to); ?>" title="Đến ngày">
                        <button id="apply-custom-range" class="tkgadm-btn tkgadm-btn-primary" style="padding: 6px 12px;">🔍 Áp dụng</button>
                    </div>
                    
                    <!-- Action Buttons -->
                    <button id="open-manage-ip" class="tkgadm-btn tkgadm-btn-primary" style="background: #28a745;">➕ Chặn IP</button>
                    
                    <!-- Copy Blocked IPs -->
                    <button id="copy-blocked-ips" class="tkgadm-btn tkgadm-btn-secondary" title="Copy toàn bộ danh sách IP đã chặn">
                        📋 Copy IP chặn (<?php echo count($blocked_ips); ?>)
                    </button>
                    <textarea id="blocked-ips-textarea" style="position: absolute; left: -9999px;"><?php echo implode("\n", $blocked_ips); ?></textarea>
                    
                    <!-- Toggle Blocked IPs -->
                    <button id="toggle-blocked-view" class="tkgadm-btn <?php echo $show_blocked_only ? 'tkgadm-btn-primary' : 'tkgadm-btn-secondary'; ?>" data-show="<?php echo $show_blocked_only ? '1' : '0'; ?>">
                        🚫 <?php echo $show_blocked_only ? 'Hiện tất cả' : 'Chỉ IP chặn'; ?> (<?php echo count($blocked_ips); ?>)
                    </button>
                </div>
            </div>

            <!-- Biểu đồ thống kê hàng ngày -->
            <div id="chart-container" class="tkgadm-card" style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #ddd; margin-bottom: 30px;">
                <!-- Summary Cards -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px;">
                    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 13px; opacity: 0.9;">📊 Tổng người Ads</div>
                        <div id="daily-total-ads" style="font-size: 32px; font-weight: bold; margin-top: 8px;">-</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #4caf50 0%, #8bc34a 100%); color: white; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 13px; opacity: 0.9;">🌱 Tổng người Organic</div>
                        <div id="daily-total-organic" style="font-size: 32px; font-weight: bold; margin-top: 8px;">-</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 13px; opacity: 0.9;">🚫 Tổng lượt chặn</div>
                        <div id="daily-total-blocked" style="font-size: 32px; font-weight: bold; margin-top: 8px;">-</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 13px; opacity: 0.9;">📈 TB người/ngày</div>
                        <div id="daily-avg-ads" style="font-size: 32px; font-weight: bold; margin-top: 8px;">-</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 13px; opacity: 0.9;">⚡ Tỷ lệ chặn</div>
                        <div id="daily-block-rate" style="font-size: 32px; font-weight: bold; margin-top: 8px;">-</div>
                    </div>
                </div>
                
                <!-- Chart -->
                <div style="position: relative; height: 400px; <?php echo $chart_style; ?>">
                    <canvas id="daily-stats-chart"></canvas>
                </div>
                

                
                <!-- Loading -->
                <div id="daily-stats-loading" style="display: none; text-align: center; padding: 40px;">
                    <div style="font-size: 48px;">⏳</div>
                    <div style="margin-top: 10px; color: #666;">Đang tải dữ liệu...</div>
                </div>
            </div>

            <div class="tkgadm-table-container">
                <table class="tkgadm-table">
                    <thead>
                        <tr>
                            <th>🌐 IP Address</th>
                            <th>🏷️ UTM Term</th>
                            <th>⏰ Lần truy cập cuối</th>
                            <th style="cursor:pointer;" class="sortable" data-sort="visits" title="Click để sắp xếp">📊 Thống kê truy cập <span class="sort-icon">⇅</span></th>
                            <th>⚙️ Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($results)): ?>
                            <tr><td colspan="5" style="text-align: center; padding: 40px;">Không có dữ liệu</td></tr>
                        <?php else: ?>
                            <?php foreach ($results as $row): 
                                $is_blocked = in_array($row->ip_address, $blocked_ips);
                                $row_class = $is_blocked ? 'tkgadm-blocked' : '';
                                
                                // Lấy utm_term từ URL đầu tiên
                                $urls = explode('|||', $row->urls);
                                $first_url = $urls[0];
                                $parsed = wp_parse_url($first_url);
                                $utm_term = '-';
                                if (isset($parsed['query'])) {
                                    parse_str($parsed['query'], $params);
                                    $utm_term = isset($params['utm_term']) ? $params['utm_term'] : '-';
                                }
                                $utm_display = strlen($utm_term) > 30 ? substr($utm_term, 0, 30) . '...' : $utm_term;
                            ?>
                                <tr class="<?php echo esc_attr($row_class); ?>" data-ip="<?php echo esc_attr($row->ip_address); ?>" data-visits="<?php echo intval($row->total_visits); ?>" data-ad-clicks="<?php echo intval($row->ad_clicks); ?>">
                                    <td><strong><?php echo esc_html($row->ip_address); ?></strong>
                                        <?php if ($is_blocked): ?>
                                            <span class="tkgadm-badge tkgadm-badge-danger">🚫</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($utm_display); ?></td>
                                    <td><a href="#" class="view-details" data-ip="<?php echo esc_attr($row->ip_address); ?>" data-urls="<?php echo esc_attr($row->urls); ?>" style="text-decoration: none; font-weight: bold; color: #007cba;"><?php echo esc_html($row->last_visit); ?> <span class="dashicons dashicons-visibility" style="font-size: 16px; vertical-align: middle;"></span></a></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span class="tkgadm-badge tkgadm-badge-warning" style="background: #ff9800; color: white;" title="Click quảng cáo (Google Click ID unique)">🎯 <?php echo intval($row->ad_clicks); ?></span>
                                            <span style="color: #ccc;">|</span>
                                            <span title="Tổng lượt truy cập" style="color: #666;">📈 <?php echo intval($row->total_visits); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $toggle_label = $is_blocked ? 'Đã chặn' : 'Hoạt động';
                                        $toggle_class = $is_blocked ? 'blocked' : 'active';
                                        ?>
                                        <label class="tkgadm-toggle-switch">
                                            <input type="checkbox" class="toggle-block" data-ip="<?php echo esc_attr($row->ip_address); ?>" <?php checked($is_blocked); ?>>
                                            <span class="tkgadm-toggle-slider"></span>
                                        </label>
                                        <span class="tkgadm-toggle-label <?php echo esc_attr($toggle_class); ?>"><?php echo esc_html($toggle_label); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal quản lý IP -->
        <div id="manage-ip-modal" class="tkgadm-modal">
            <div class="tkgadm-modal-content" style="max-width: 500px;">
                <div class="tkgadm-modal-header">
                    <h2>➕ Chặn IP</h2>
                    <span class="tkgadm-modal-close">&times;</span>
                </div>
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Nhập danh sách IP (mỗi IP một dòng):</label>
                    <textarea id="ip-to-block" rows="6" placeholder="Ví dụ:&#10;192.168.1.1&#10;192.168.1.*&#10;103.82.36.122&#10;2402:800:6310:c2ff:c91c:18eb:f87c:75a3" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-family: monospace; font-size: 13px;"></textarea>
                    <small style="color: #666; display: block; margin-top: 5px;">✓ Hỗ trợ IPv4, IPv6 và wildcard (*) cho IPv4<br>✓ Mỗi IP một dòng, có thể nhập nhiều IP cùng lúc</small>
                </div>
                <button id="confirm-block-ip" class="tkgadm-btn tkgadm-btn-primary" style="width: 100%;">🚫 Chặn tất cả IP</button>
            </div>
        </div>

        <!-- Modal chi tiết IP -->
        <div id="url-modal" class="tkgadm-modal">
            <span class="tkgadm-modal-close">&times;</span>
            <div class="tkgadm-modal-content">
                <div class="tkgadm-modal-header">
                    <h2 id="modal-title">Chi tiết IP</h2>
                </div>
                <div style="margin: 20px 0;">
                    <canvas id="visit-chart" width="400" height="200"></canvas>
                </div>
                <div id="url-list"></div>
            </div>
        </div>

        <!-- Modal chi tiết ngày (Daily Stats) -->
        <div id="daily-details-modal" class="tkgadm-modal">
            <span class="tkgadm-modal-close">&times;</span>
            <div class="tkgadm-modal-content">
                <div class="tkgadm-modal-header">
                    <h2 id="daily-modal-title">Chi tiết ngày</h2>
                </div>
                <div id="daily-details-content" style="max-height: 500px; overflow-y: auto;"></div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * ============================================================================
 * 2. ANALYTICS PAGE (Thống kê Traffic)
 * ============================================================================
 */




/**
 * ============================================================================
 * 3. AJAX FUNCTIONS FOR ANALYTICS
 * ============================================================================
 */

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
    $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : 'Chặn thủ công bởi Admin';
    
    if (!function_exists('tkgadm_validate_ip_pattern')) {
         // Should be loaded from core-engine, but just in case
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
        $inserted = $wpdb->insert($table, [
            'ip_address' => $ip,
            'blocked_time' => current_time('mysql'),
            'reason' => $reason
        ]);
        
        // Auto Sync on Block Logic
        if ($inserted && get_option('tkgadm_auto_sync_on_block')) {
             if (function_exists('tkgadm_sync_ip_to_google_ads')) {
                tkgadm_sync_ip_to_google_ads([$ip]);
             }
        }

        wp_send_json_success(['message' => 'Đã chặn IP: ' . $ip, 'blocked' => true]);
    }
}

/**
 * Lấy dữ liệu biểu đồ theo IP (Modal Detail)
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
 * Cập nhật time on page (Heartbeat)
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
 * Lấy dữ liệu traffic analytics
 */


/**
 * Lấy dữ liệu thống kê hàng ngày (Ads visits + Blocked count)
 */
add_action('wp_ajax_tkgadm_get_daily_stats', 'tkgadm_ajax_get_daily_stats');
function tkgadm_ajax_get_daily_stats() {
    check_ajax_referer('tkgadm_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền');
    }
    
    global $wpdb;
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    
    // Lấy date range từ request, mặc định 30 ngày gần nhất
    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : current_time('Y-m-d', strtotime('-30 days'));
    $date_to = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : current_time('Y-m-d');
    
    // Lấy dữ liệu Ads visits theo ngày
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $ads_query = $wpdb->prepare(
        "SELECT DATE(visit_time) as date, 
                COUNT(DISTINCT ip_address) as unique_ips,
                SUM(visit_count) as total_visits
         FROM $table_stats
         WHERE gclid IS NOT NULL AND gclid != ''
         AND DATE(visit_time) >= %s AND DATE(visit_time) <= %s
         GROUP BY DATE(visit_time)
         ORDER BY date ASC",
        $date_from,
        $date_to
    );
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $ads_data = $wpdb->get_results($ads_query);
    
    // Lấy dữ liệu IP bị chặn theo ngày
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $blocked_query = $wpdb->prepare(
        "SELECT DATE(blocked_time) as date, 
                COUNT(*) as blocked_count
         FROM $table_blocked
         WHERE DATE(blocked_time) >= %s AND DATE(blocked_time) <= %s
         GROUP BY DATE(blocked_time)
         ORDER BY date ASC",
        $date_from,
        $date_to
    );
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $blocked_data = $wpdb->get_results($blocked_query);
    
    // Lấy dữ liệu Organic traffic theo ngày
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $organic_query = $wpdb->prepare(
        "SELECT DATE(t1.visit_time) as date, 
                COUNT(DISTINCT t1.ip_address) as unique_ips
         FROM $table_stats t1
         LEFT JOIN $table_stats t2 ON t1.ip_address = t2.ip_address AND t2.gclid IS NOT NULL AND t2.gclid != ''
         WHERE t1.time_on_page > 0 
         AND t2.id IS NULL
         AND DATE(t1.visit_time) >= %s AND DATE(t1.visit_time) <= %s
         GROUP BY DATE(t1.visit_time)
         ORDER BY date ASC",
        $date_from,
        $date_to
    );
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $organic_data = $wpdb->get_results($organic_query);
    

    
    // Map dữ liệu vào mảng ngày
    // --- TỐI ƯU PHP GROUPING (Hash Map) ---
    
    // Helper convert array to map by date
    $map_data = function($data, $key_val) {
        $map = [];
        foreach ($data as $item) {
            $map[$item->date] = intval($item->$key_val);
        }
        return $map;
    };
    
    $ads_map = $map_data($ads_data, 'unique_ips');
    $blocked_map = $map_data($blocked_data, 'blocked_count');
    $organic_map = $map_data($organic_data, 'unique_ips');
    
    // Tạo mảng ngày đầy đủ
    $result = [];
    $current = strtotime($date_from);
    $end = strtotime($date_to);
    
    while ($current <= $end) {
        $date = date('Y-m-d', $current);
        $result[] = [
            'date' => $date,
            'ads_visits' => isset($ads_map[$date]) ? $ads_map[$date] : 0,
            'organic_visits' => isset($organic_map[$date]) ? $organic_map[$date] : 0,
            'blocked_count' => isset($blocked_map[$date]) ? $blocked_map[$date] : 0
        ];
        $current = strtotime('+1 day', $current);
    }
    
    wp_send_json_success(['data' => $result]);
}

/**
 * Lấy chi tiết IP theo ngày
 */
add_action('wp_ajax_tkgadm_get_daily_details', 'tkgadm_ajax_get_daily_details');
function tkgadm_ajax_get_daily_details() {
    check_ajax_referer('tkgadm_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền');
    }
    
    global $wpdb;
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    
    $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
    $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'ads';
    
    if (empty($date)) {
        wp_send_json_error('Thiếu tham số ngày');
    }
    
    // 1. Get List of Target IPs first
    $target_ips = [];
    if ($type === 'ads') {
        // IPs with Ads clicks
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query_ips = $wpdb->prepare(
            "SELECT DISTINCT ip_address 
             FROM $table_stats 
             WHERE DATE(visit_time) = %s 
             AND gclid IS NOT NULL AND gclid != ''", 
            $date
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $target_ips = $wpdb->get_col($query_ips);
    } elseif ($type === 'organic') {
        // IPs with ONLY Organic traffic (no Ads clicks ever or just no Ads clicks this day? 
        // Logic from chart: Organic = No gclid + Time > 0)
        // Let's stick to the chart logic: 
        // "Organic Traffic" in chart was: time_on_page > 0 AND ip NOT IN (SELECT ... WHERE gclid...)
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query_ips = $wpdb->prepare(
            "SELECT DISTINCT ip_address 
             FROM $table_stats 
             WHERE DATE(visit_time) = %s 
             AND time_on_page IS NOT NULL AND time_on_page > 0
             AND ip_address NOT IN (
                 SELECT DISTINCT ip_address 
                 FROM $table_stats 
                 WHERE gclid IS NOT NULL AND gclid != ''
             )", 
            $date
        );
         // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $target_ips = $wpdb->get_col($query_ips);
    } else {
        // Blocked IPs
         // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query_ips = $wpdb->prepare(
            "SELECT DISTINCT ip_address FROM $table_blocked WHERE DATE(blocked_time) = %s",
            $date
        );
         // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $target_ips = $wpdb->get_col($query_ips);
    }
    
    if (empty($target_ips)) {
        wp_send_json_success(['ips' => [], 'type' => $type]);
    }
    
    // 2. Fetch ALL sessions for these IPs on that specific date
    $placeholders = implode(',', array_fill(0, count($target_ips), '%s'));
    
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $query_sessions = $wpdb->prepare(
        "SELECT * 
         FROM $table_stats
         WHERE ip_address IN ($placeholders)
         AND DATE(visit_time) = %s
         ORDER BY visit_time DESC",
        array_merge($target_ips, [$date])
    );
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $sessions = $wpdb->get_results($query_sessions);
    
    // 3. Check Blocked Status for all these IPs
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $all_blocked_ips = $wpdb->get_col("SELECT ip_address FROM $table_blocked");
    
    // 4. Group by IP
    $grouped_data = [];
    foreach ($target_ips as $ip) {
        $ip_sessions = array_filter($sessions, function($s) use ($ip) {
            return $s->ip_address === $ip;
        });
        
        if (empty($ip_sessions) && $type !== 'blocked') continue;
        
        // Find blocked info if exists
        $blocked_info = null;
        if ($type === 'blocked') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $blocked_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_blocked WHERE ip_address = %s", $ip));
        }

        $formatted_sessions = [];
        foreach ($ip_sessions as $s) {
            $is_ad = !empty($s->gclid);
            $formatted_sessions[] = [
                'time' => date('H:i:s', strtotime($s->visit_time)),
                'full_time' => $s->visit_time,
                'url' => $s->url_visited,
                'type' => $is_ad ? 'Ads' : 'Organic',
                'time_on_page' => intval($s->time_on_page),
                'visit_count' => intval($s->visit_count), // Số lượt trong phiên (nếu có logic gộp)
                'gclid' => $s->gclid
            ];
        }
        
        $total_sessions = count($formatted_sessions);
        $last_visit = !empty($formatted_sessions) ? $formatted_sessions[0]['full_time'] : ($blocked_info ? $blocked_info->blocked_time : 'N/A');
        
        $grouped_data[] = [
            'ip_address' => $ip,
            'is_blocked' => in_array($ip, $all_blocked_ips),
            'session_count' => $total_sessions,
            'last_visit' => $last_visit,
            'sessions' => $formatted_sessions,
            'blocked_time' => $blocked_info ? $blocked_info->blocked_time : null
        ];
    }
    
    // Sort logic
    usort($grouped_data, function($a, $b) {
        return strtotime($b['last_visit']) - strtotime($a['last_visit']); // Mới nhất lên đầu
    });

    wp_send_json_success(['ips' => array_values($grouped_data), 'type' => $type]);
}

