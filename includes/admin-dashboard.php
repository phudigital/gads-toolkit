<?php
/**
 * Admin Dashboard - Thống kê IP
 * Menu chính: Thống kê IP từ Google Ads
 */

if (!defined('ABSPATH')) exit;

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
    
    $default_from = $date_range && $date_range->oldest ? $date_range->oldest : date('Y-m-d', strtotime('-30 days'));
    $default_to = $date_range && $date_range->newest ? $date_range->newest : date('Y-m-d');
    
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
    
    // Filter nếu chỉ xem IP blocked
    if ($show_blocked_only) {
        $results = array_filter($results, function($row) use ($blocked_ips) {
            return in_array($row->ip_address, $blocked_ips);
        });
    }
    
    // Get plugin version
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugin_data = get_plugin_data(GADS_TOOLKIT_PATH . 'gads-toolkit.php');
    $plugin_version = $plugin_data['Version'];
    
    ?>
    <div class="wrap">
        <div class="tkgadm-wrap">
            <div class="tkgadm-header">
                <h1>📊 Thống Kê IP Ads <span style="font-size: 0.5em; color: #666; font-weight: normal; vertical-align: middle;">v<?php echo esc_html($plugin_version); ?></span></h1>

                <div class="tkgadm-filters">
                    <!-- Date Range Picker -->
                    <div style="display: inline-flex; gap: 5px; align-items: center; background: white; padding: 8px 12px; border-radius: 8px;">
                        <input type="date" id="date-from" class="tkgadm-input" style="width: 150px; padding: 6px; font-size: 13px;" value="<?php echo esc_attr($date_from); ?>" title="Từ ngày" placeholder="dd/mm/yyyy">
                        <span style="color: #999;">→</span>
                        <input type="date" id="date-to" class="tkgadm-input" style="width: 150px; padding: 6px; font-size: 13px;" value="<?php echo esc_attr($date_to); ?>" title="Đến ngày" placeholder="dd/mm/yyyy">
                        <button id="apply-date-range" class="tkgadm-btn tkgadm-btn-primary" style="padding: 6px 12px;" title="Lọc">🔍</button>
                        <?php if (!empty($date_from) || !empty($date_to) || $show_blocked_only): ?>
                            <button id="clear-date-range" class="tkgadm-btn tkgadm-btn-secondary" style="padding: 6px 12px;" title="Xóa bộ lọc">✖️</button>
                        <?php endif; ?>
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

            <div class="tkgadm-table-container">
                <table class="tkgadm-table">
                    <thead>
                        <tr>
                            <th>🌐 IP Address</th>
                            <th>🏷️ UTM Term</th>
                            <th>⏰ Lần truy cập cuối</th>
                            <th>📊 Thống kê truy cập</th>
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
                                <tr class="<?php echo esc_attr($row_class); ?>" data-ip="<?php echo esc_attr($row->ip_address); ?>">
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
                                            <input type="checkbox" class="toggle-block-ip" data-ip="<?php echo esc_attr($row->ip_address); ?>" <?php checked($is_blocked); ?>>
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
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Nhập IP hoặc dải IP (hỗ trợ wildcard):</label>
                    <input type="text" id="ip-to-block" placeholder="Ví dụ: 192.168.1.1 hoặc 192.168.1.* hoặc 2402:800:6310:c2ff:c91c:18eb:f87c:75a3" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                    <small style="color: #666; display: block; margin-top: 5px;">Hỗ trợ IPv4, IPv6 và wildcard (*) cho IPv4</small>
                </div>
                <button id="confirm-block-ip" class="tkgadm-btn tkgadm-btn-primary" style="width: 100%;">Chặn IP</button>
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
    </div>
    <?php
}
