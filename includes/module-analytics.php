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
    
    // Mặc định hiển thị 30 ngày gần nhất
    $default_from = date('Y-m-d', strtotime('-30 days'));
    $default_to = date('Y-m-d');
    
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
    </div>
    <?php
}

/**
 * ============================================================================
 * 2. ANALYTICS PAGE (Thống kê Traffic)
 * ============================================================================
 */
function tkgadm_render_analytics_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    
    // Lấy ngày cũ nhất và mới nhất từ database
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $date_range = $wpdb->get_row("SELECT 
        DATE(MIN(visit_time)) as oldest,
        DATE(MAX(visit_time)) as newest
        FROM $table");
    
    // Mặc định hiển thị 30 ngày gần đây
    $default_from = date('Y-m-d', strtotime('-30 days'));
    $default_to = date('Y-m-d');
    
    ?>
    <div class="wrap">
        <div class="tkgadm-wrap">
            <div class="tkgadm-header">
                <h1>📈 Thống Kê Traffic</h1>
                <p style="color: #666; margin-top: 10px;">So sánh lượt truy cập từ Google Ads (có gclid/gbraid) và lượt truy cập tự nhiên (Organic - đã lọc Bot)</p>

                <div class="tkgadm-filters" style="margin-top: 20px;">
                    <!-- Period Selector -->
                    <select id="analytics-period" class="tkgadm-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #ddd;">
                        <option value="day">Theo ngày</option>
                        <option value="week">Theo tuần</option>
                        <option value="month">Theo tháng</option>
                        <option value="quarter">Theo quý</option>
                    </select>

                    <!-- Date Range -->
                    <div style="display: inline-flex; gap: 5px; align-items: center; background: white; padding: 8px 12px; border-radius: 8px;">
                        <input type="date" id="analytics-from" class="tkgadm-input" style="width: 150px; padding: 6px; font-size: 13px;" title="Từ ngày">
                        <span style="color: #999;">→</span>
                        <input type="date" id="analytics-to" class="tkgadm-input" style="width: 150px; padding: 6px; font-size: 13px;" title="Đến ngày">
                        <button id="analytics-apply" class="tkgadm-btn tkgadm-btn-primary" style="padding: 6px 12px;">🔍 Xem</button>
                    </div>

                    <!-- Quick filters -->
                    <button class="analytics-quick-filter tkgadm-btn tkgadm-btn-secondary" data-days="7">7 ngày</button>
                    <button class="analytics-quick-filter tkgadm-btn tkgadm-btn-secondary" data-days="30">30 ngày</button>
                    <button class="analytics-quick-filter tkgadm-btn tkgadm-btn-secondary" data-days="90">90 ngày</button>
                </div>
            </div>

            <!-- Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9;">📊 Tổng lượt truy cập</div>
                    <div id="total-visits" style="font-size: 36px; font-weight: bold; margin-top: 10px;">-</div>
                </div>
                <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9;">🎯 Từ Google Ads</div>
                    <div id="ads-visits" style="font-size: 36px; font-weight: bold; margin-top: 10px;">-</div>
                </div>
                <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9;">🌱 Organic Traffic</div>
                    <div id="organic-visits" style="font-size: 36px; font-weight: bold; margin-top: 10px;">-</div>
                </div>
                <div style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9;">📈 Tỷ lệ Ads/Total</div>
                    <div id="ads-ratio" style="font-size: 36px; font-weight: bold; margin-top: 10px;">-</div>
                </div>
            </div>

            <!-- Chart Container -->
            <div class="tkgadm-table-container" style="padding: 30px;">
                <canvas id="traffic-chart" style="max-height: 400px;"></canvas>
            </div>

            <!-- Loading Indicator -->
            <div id="analytics-loading" style="display: none; text-align: center; padding: 40px;">
                <div style="font-size: 48px;">⏳</div>
                <div style="margin-top: 10px; color: #666;">Đang tải dữ liệu...</div>
            </div>
        </div>

        <!-- Modal chi tiết visits -->
        <div id="period-details-modal" class="tkgadm-modal">
            <span class="tkgadm-modal-close">&times;</span>
            <div class="tkgadm-modal-content">
                <div class="tkgadm-modal-header">
                    <h2 id="period-modal-title">Chi tiết lượt truy cập</h2>
                </div>
                <div id="period-details-content" style="max-height: 500px; overflow-y: auto;"></div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        let trafficChart = null;

        // Load traffic data
        function loadTrafficData() {
            const period = $('#analytics-period').val();
            const from = $('#analytics-from').val();
            const to = $('#analytics-to').val();

            $('#analytics-loading').show();

            $.ajax({
                url: tkgadm_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'tkgadm_get_traffic_data',
                    nonce: tkgadm_vars.nonce,
                    period: period,
                    from: from,
                    to: to
                },
                success: function(response) {
                    $('#analytics-loading').hide();
                    
                    if (response.success) {
                        renderTrafficChart(response.data, period);
                    } else {
                        alert('Lỗi: ' + response.data);
                    }
                },
                error: function() {
                    $('#analytics-loading').hide();
                    alert('Lỗi kết nối');
                }
            });
        }

        // Render chart
        function renderTrafficChart(data, period) {
            const adsData = data.ads || [];
            const organicData = data.organic || [];

            // Merge periods
            const allPeriods = [...new Set([
                ...adsData.map(d => d.period),
                ...organicData.map(d => d.period)
            ])].sort();

            const adsValues = allPeriods.map(p => {
                const found = adsData.find(d => d.period === p);
                return found ? parseInt(found.total) : 0;
            });

            const organicValues = allPeriods.map(p => {
                const found = organicData.find(d => d.period === p);
                return found ? parseInt(found.total) : 0;
            });

            // Update summary
            const totalAds = adsValues.reduce((a, b) => a + b, 0);
            const totalOrganic = organicValues.reduce((a, b) => a + b, 0);
            const total = totalAds + totalOrganic;
            const ratio = total > 0 ? ((totalAds / total) * 100).toFixed(1) : 0;

            $('#total-visits').text(total.toLocaleString());
            $('#ads-visits').text(totalAds.toLocaleString());
            $('#organic-visits').text(totalOrganic.toLocaleString());
            $('#ads-ratio').text(ratio + '%');

            // Destroy old chart
            if (trafficChart) {
                trafficChart.destroy();
            }

            // Create new chart
            const ctx = document.getElementById('traffic-chart').getContext('2d');
            trafficChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: allPeriods,
                    datasets: [
                        {
                            label: '🎯 Google Ads',
                            data: adsValues,
                            backgroundColor: 'rgba(255, 152, 0, 0.8)',
                            borderColor: 'rgba(255, 152, 0, 1)',
                            borderWidth: 1
                        },
                        {
                            label: '🌱 Organic',
                            data: organicValues,
                            backgroundColor: 'rgba(76, 175, 80, 0.8)',
                            borderColor: 'rgba(76, 175, 80, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        title: {
                            display: true,
                            text: 'Biểu đồ Traffic: Google Ads vs Organic',
                            font: { size: 16, weight: 'bold' }
                        }
                    },
                    scales: {
                        x: {
                            stacked: true,
                            title: { display: true, text: getPeriodLabel(period) }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: { display: true, text: 'Số lượt truy cập' }
                        }
                    },
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const element = elements[0];
                            const datasetIndex = element.datasetIndex;
                            const index = element.index;
                            const periodValue = allPeriods[index];
                            const type = datasetIndex === 0 ? 'ads' : 'organic';
                            
                            loadPeriodDetails(periodValue, type, period);
                        }
                    }
                }
            });
        }

        // Load chi tiết visits theo period
        function loadPeriodDetails(periodValue, type, periodType) {
            const typeLabel = type === 'ads' ? '🎯 Google Ads' : '🌱 Organic';
            $('#period-modal-title').text(`Chi tiết ${typeLabel} - ${periodValue}`);
            $('#period-details-content').html('<div style="text-align:center; padding:20px;">⏳ Đang tải...</div>');
            $('#period-details-modal').fadeIn();

            $.ajax({
                url: tkgadm_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'tkgadm_get_period_details',
                    nonce: tkgadm_vars.nonce,
                    period_value: periodValue,
                    type: type,
                    period_type: periodType
                },
                success: function(response) {
                    if (response.success && response.data.visits) {
                        renderVisitsTable(response.data.visits);
                    } else {
                        $('#period-details-content').html('<p style="color:red;">Lỗi tải dữ liệu</p>');
                    }
                },
                error: function() {
                    $('#period-details-content').html('<p style="color:red;">Lỗi kết nối</p>');
                }
            });
        }

        // Render bảng visits với collapse theo IP
        function renderVisitsTable(visits) {
            if (visits.length === 0) {
                $('#period-details-content').html('<p>Không có dữ liệu</p>');
                return;
            }

            // Group visits theo IP
            const groupedByIP = {};
            visits.forEach(visit => {
                if (!groupedByIP[visit.ip_address]) {
                    groupedByIP[visit.ip_address] = [];
                }
                groupedByIP[visit.ip_address].push(visit);
            });

            let html = '<table class="tkgadm-table" style="width:100%;">';
            html += '<thead><tr>';
            html += '<th style="width:30px;"></th>'; // Expand icon
            html += '<th>🌐 IP Address</th>';
            html += '<th>📊 Số phiên</th>';
            html += '<th>⏰ Lần truy cập cuối</th>';
            html += '</tr></thead><tbody>';

            Object.keys(groupedByIP).forEach((ip, index) => {
                const sessions = groupedByIP[ip];
                const sessionCount = sessions.length;
                const lastVisit = sessions[0].visit_time; // Đã sort DESC từ server
                
                // Row chính (IP summary)
                html += `<tr class="ip-row" data-ip-index="${index}" style="cursor:pointer; background:#f9f9f9;">`;
                html += `<td><span class="expand-icon">▶</span></td>`;
                html += `<td><strong>${ip}</strong></td>`;
                html += `<td><span class="tkgadm-badge tkgadm-badge-info">${sessionCount} phiên</span></td>`;
                html += `<td>${lastVisit}</td>`;
                html += '</tr>';

                // Rows chi tiết (ẩn mặc định)
                sessions.forEach((visit, sessionIndex) => {
                    const timeOnPage = visit.time_on_page > 0 ? visit.time_on_page + 's' : '-';
                    const url = visit.url_visited.length > 60 ? visit.url_visited.substring(0, 60) + '...' : visit.url_visited;
                    const isAds = visit.gclid && visit.gclid !== '';
                    const typeBadge = isAds 
                        ? '<span class="tkgadm-badge tkgadm-badge-warning" style="background:#ff9800;">🎯 Ads</span>' 
                        : '<span class="tkgadm-badge tkgadm-badge-success" style="background:#4caf50;">🌱 Organic</span>';
                    
                    html += `<tr class="session-detail session-${index}" style="display:none; background:#fff;">`;
                    html += '<td></td>'; // Empty expand column
                    html += `<td colspan="3" style="padding-left:30px;">`;
                    html += '<div style="display:flex; gap:15px; align-items:center; font-size:13px;">';
                    html += typeBadge;
                    html += `<span style="color:#666;">Phiên ${sessionIndex + 1}</span>`;
                    html += `<span>⏰ ${visit.visit_time}</span>`;
                    html += `<span>⏱️ ${timeOnPage}</span>`;
                    html += `<span>📊 ${visit.visit_count} lượt</span>`;
                    html += `<span title="${visit.url_visited}" style="color:#007cba; max-width:400px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">🔗 ${url}</span>`;
                    html += '</div>';
                    html += '</td>';
                    html += '</tr>';
                });
            });

            html += '</tbody></table>';
            $('#period-details-content').html(html);

            // Add click handler for expand/collapse
            $('.ip-row').on('click', function() {
                const index = $(this).data('ip-index');
                const $sessions = $(`.session-${index}`);
                const $icon = $(this).find('.expand-icon');
                
                if ($sessions.is(':visible')) {
                    $sessions.slideUp(200);
                    $icon.text('▶');
                } else {
                    $sessions.slideDown(200);
                    $icon.text('▼');
                }
            });
        }

        // Close modal
        $('.tkgadm-modal-close').on('click', function() {
            $(this).closest('.tkgadm-modal').fadeOut();
        });

        function getPeriodLabel(period) {
            const labels = {
                'day': 'Ngày',
                'week': 'Tuần',
                'month': 'Tháng',
                'quarter': 'Quý'
            };
            return labels[period] || 'Thời gian';
        }

        // Event handlers
        $('#analytics-apply').on('click', loadTrafficData);
        $('#analytics-period').on('change', loadTrafficData);

        $('.analytics-quick-filter').on('click', function() {
            const days = $(this).data('days');
            const to = new Date();
            const from = new Date();
            from.setDate(from.getDate() - days);

            $('#analytics-from').val(from.toISOString().split('T')[0]);
            $('#analytics-to').val(to.toISOString().split('T')[0]);
            loadTrafficData();
        });

        // Load initial data (từ ngày cũ nhất đến mới nhất trong DB)
        <?php
        echo "$('#analytics-from').val('" . esc_js($default_from) . "');";
        echo "$('#analytics-to').val('" . esc_js($default_to) . "');";
        ?>
        loadTrafficData();
    });
    </script>
    <?php
}

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
        $inserted = $wpdb->insert($table, ['ip_address' => $ip]);
        
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
    
    // Query cho ads traffic
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
    
    // Query cho organic traffic
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
 * Lấy chi tiết visits theo period
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
    
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    
    // Xác định điều kiện WHERE dựa trên period_type
    $where_period = '';
    if ($period_type === 'day') {
        $where_period = "DATE(visit_time) = %s";
        $params = [$period_value];
    } elseif ($period_type === 'week') {
        list($year, $week) = explode('-', $period_value);
        $where_period = "YEAR(visit_time) = %d AND WEEK(visit_time, 1) = %d";
        $params = [(int)$year, (int)$week];
    } elseif ($period_type === 'month') {
        $where_period = "DATE_FORMAT(visit_time, '%%Y-%%m') = %s";
        $params = [$period_value];
    } elseif ($period_type === 'quarter') {
        list($year, $quarter) = explode('-Q', $period_value);
        $where_period = "YEAR(visit_time) = %d AND QUARTER(visit_time) = %d";
        $params = [(int)$year, (int)$quarter];
    }
    
    if ($type === 'ads') {
        $where_type = "AND ip_address IN (
            SELECT DISTINCT ip_address 
            FROM $table 
            WHERE gclid IS NOT NULL AND gclid != ''
        )";
    } else {
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
