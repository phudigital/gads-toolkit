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
    $default_from = date('Y-m-d', strtotime('-29 days', current_time('timestamp')));
    $default_to = current_time('Y-m-d');
    
    // Lấy tham số filter (nếu user đã chọn thì dùng, không thì dùng default)
    $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : $default_from;
    $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : $default_to;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $date_from = $default_from;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $date_to = $default_to;
    }
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
        <style>
        /* From Prototype */
        .wp-wrap {
            max-width: 1200px;
            margin: 0 auto;
            font-family: 'Inter', sans-serif;
        }
    </style>

    <div class="wp-wrap tkgadm-page tkgadm-dashboard-page space-y-6" style="padding: 20px 0;">
        <?php tkgadm_render_admin_workspace('dashboard'); ?>
        <!-- Header & Filters -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col md:flex-row justify-between items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2 m-0 pb-1">
                    <i class="fa-solid fa-shield-halved text-blue-600"></i>
                    Thống Kê IP Ads
                    <span class="text-xs font-medium bg-blue-100 text-blue-600 px-2 py-1 rounded-full">v<?php echo esc_html($plugin_version); ?></span>
                </h1>
                <p class="text-sm text-gray-500 m-0 mt-1">Quản lý và ngăn chặn click tặc Google Ads</p>
            </div>
            
            <div class="flex flex-wrap items-center gap-3">
                <!-- Time Filter -->
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-1 flex items-center">
                    <select id="time-period" class="bg-transparent border-none text-sm text-gray-700 font-medium focus:ring-0 cursor-pointer pr-8 py-1.5 pl-3">
                        <option value="1" <?php selected($selected_period, 1); ?>>Hôm nay</option>
                        <option value="7" <?php selected($selected_period, 7); ?>>7 ngày gần nhất</option>
                        <option value="30" <?php selected($selected_period, 30); ?>>30 ngày gần nhất</option>
                        <option value="custom" <?php selected($selected_period, 'custom'); ?>>Tùy chỉnh...</option>
                    </select>
                </div>
                
                <div id="custom-date-range" style="<?php echo $custom_style; ?> gap: 5px; align-items: center;" class="bg-gray-50 border border-gray-200 rounded-lg p-1 h-10 px-2">
                    <input type="date" id="date-from" class="bg-transparent border-none text-sm p-1" value="<?php echo esc_attr($date_from); ?>">
                    <span class="text-gray-400">→</span>
                    <input type="date" id="date-to" class="bg-transparent border-none text-sm p-1" value="<?php echo esc_attr($date_to); ?>">
                    <button type="button" id="apply-custom-range" class="bg-blue-600 text-white text-xs px-2 py-1 rounded border-none cursor-pointer">Lọc</button>
                </div>

                <!-- Actions -->
                <button type="button" id="open-manage-ip" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded-lg transition shadow-sm flex items-center gap-2 border-none cursor-pointer">
                    <i class="fa-solid fa-plus"></i> Chặn IP
                </button>
                <button type="button" id="copy-blocked-ips" class="bg-white hover:bg-gray-50 text-gray-700 text-sm font-medium py-2 px-4 rounded-lg border border-gray-200 transition shadow-sm flex items-center gap-2 cursor-pointer">
                    <i class="fa-regular fa-copy"></i> Copy IP (<span id="copy-count-badge"><?php echo count($blocked_ips); ?></span>)
                </button>
                <textarea id="blocked-ips-textarea" style="position: absolute; left: -9999px;"><?php echo implode("\n", $blocked_ips); ?></textarea>
                
                <button type="button" id="toggle-blocked-view" data-show="<?php echo $show_blocked_only ? '1' : '0'; ?>" class="bg-white hover:bg-gray-50 text-gray-700 text-sm font-medium py-2 px-4 rounded-lg border border-gray-200 transition shadow-sm flex items-center gap-2 cursor-pointer">
                    <i class="fa-solid fa-filter"></i> <span id="toggle-blocked-text"><?php echo $show_blocked_only ? 'Hiện tất cả' : 'Chỉ IP chặn'; ?></span>
                </button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4" id="chart-container">
            <!-- Card 1 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col justify-center">
                <div class="flex justify-between items-start mb-2">
                    <p class="text-sm font-medium text-gray-500 m-0">Tổng người Ads</p>
                    <div class="p-2 bg-blue-50 rounded-lg text-blue-600"><i class="fa-solid fa-users"></i></div>
                </div>
                <h3 id="daily-total-ads" class="text-3xl font-bold text-gray-800 m-0">-</h3>
                <p class="text-xs text-green-600 mt-2 m-0 font-medium"><i class="fa-solid fa-arrow-trend-up"></i> +12% so với kỳ trước</p>
            </div>
            
            <!-- Card 2 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col justify-center">
                <div class="flex justify-between items-start mb-2">
                    <p class="text-sm font-medium text-gray-500 m-0">Tổng Organic</p>
                    <div class="p-2 bg-emerald-50 rounded-lg text-emerald-600"><i class="fa-solid fa-leaf"></i></div>
                </div>
                <h3 id="daily-total-organic" class="text-3xl font-bold text-gray-800 m-0">-</h3>
                <p class="text-xs text-green-600 mt-2 m-0 font-medium"><i class="fa-solid fa-arrow-trend-up"></i> +5% so với kỳ trước</p>
            </div>

            <!-- Card 3 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col justify-center">
                <div class="flex justify-between items-start mb-2">
                    <p class="text-sm font-medium text-gray-500 m-0">Tổng lượt chặn</p>
                    <div class="p-2 bg-red-50 rounded-lg text-red-600"><i class="fa-solid fa-ban"></i></div>
                </div>
                <h3 id="daily-total-blocked" class="text-3xl font-bold text-gray-800 m-0">-</h3>
                <p class="text-xs text-red-600 mt-2 m-0 font-medium"><i class="fa-solid fa-arrow-trend-up"></i> +24% so với kỳ trước</p>
            </div>

            <!-- Card 4 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col justify-center">
                <div class="flex justify-between items-start mb-2">
                    <p class="text-sm font-medium text-gray-500 m-0">TB người/ngày</p>
                    <div class="p-2 bg-purple-50 rounded-lg text-purple-600"><i class="fa-solid fa-chart-line"></i></div>
                </div>
                <h3 id="daily-avg-ads" class="text-3xl font-bold text-gray-800 m-0">-</h3>
                <p class="text-xs text-gray-400 mt-2 m-0 font-medium">Trung bình 30 ngày</p>
            </div>

            <!-- Card 5 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col justify-center">
                <div class="flex justify-between items-start mb-2">
                    <p class="text-sm font-medium text-gray-500 m-0">Tỷ lệ chặn</p>
                    <div class="p-2 bg-orange-50 rounded-lg text-orange-600"><i class="fa-solid fa-bolt"></i></div>
                </div>
                <h3 id="daily-block-rate" class="text-3xl font-bold text-gray-800 m-0">-</h3>
                <p class="text-xs text-gray-400 mt-2 m-0 font-medium">Trên tổng click Ads</p>
            </div>
            
            <!-- Loading -->
            <div id="daily-stats-loading" class="col-span-5 text-center p-10 hidden">
                <i class="fa-solid fa-circle-notch fa-spin text-3xl text-gray-400"></i>
                <p class="text-gray-500 mt-2">Đang tải biểu đồ...</p>
            </div>
        </div>

        <!-- Chart Section -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5" style="<?php echo $chart_style; ?>">
            <h3 class="text-base font-bold text-gray-800 mb-4 m-0 pb-2">Biểu đồ Traffic & Lượt chặn</h3>
            <div class="h-80 w-full relative">
                <canvas id="daily-stats-chart"></canvas>
            </div>
        </div>

        <!-- Data Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="p-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <h3 class="text-base font-bold text-gray-800 m-0">Chi tiết IP truy cập</h3>
                <div class="relative">
                    <i class="fa-solid fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input type="text" id="search-ip-input" placeholder="Tìm kiếm IP..." class="pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-64 m-0">
                </div>
            </div>
            <div class="overflow-x-auto min-h-[300px]">
                <table class="w-full text-left text-sm text-gray-600 border-collapse m-0" id="ip-data-table">
                    <thead class="bg-gray-50 text-gray-700 text-xs uppercase font-semibold">
                        <tr>
                            <th class="px-6 py-4 border-b border-gray-100">IP Address</th>
                            <th class="px-6 py-4 border-b border-gray-100">UTM Term</th>
                            <th class="px-6 py-4 border-b border-gray-100">Lần truy cập cuối</th>
                            <th class="px-6 py-4 border-b border-gray-100 sortable cursor-pointer" data-sort="visits">Thống kê <span class="sort-icon">⇅</span></th>
                            <th class="px-6 py-4 border-b border-gray-100 text-center">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100" id="ip-table-body">
                        <?php if (empty($results)): ?>
                            <tr class="no-data-row"><td colspan="5" class="text-center py-10">Không có dữ liệu</td></tr>
                        <?php else: ?>
                            <?php foreach ($results as $index => $row): 
                                $is_blocked = in_array($row->ip_address, $blocked_ips);
                                $row_bg = $is_blocked ? 'bg-red-50/30' : 'hover:bg-gray-50';
                                
                                $urls = !empty($row->urls) ? explode('|||', $row->urls) : [];
                                $first_url = isset($urls[0]) ? $urls[0] : '';
                                $parsed = wp_parse_url($first_url);
                                $utm_term = '-';
                                if (isset($parsed['query'])) {
                                    parse_str($parsed['query'], $params);
                                    $utm_term = isset($params['utm_term']) ? $params['utm_term'] : '-';
                                }
                                $utm_display = strlen($utm_term) > 30 ? substr($utm_term, 0, 30) . '...' : $utm_term;
                                $toggle_id = "toggle" . $index;
                            ?>
                                <tr class="ip-row <?php echo $row_bg; ?>" data-ip="<?php echo esc_attr($row->ip_address); ?>" data-visits="<?php echo intval($row->total_visits); ?>" data-ad-clicks="<?php echo intval($row->ad_clicks); ?>">
                                    <td class="px-6 py-4 font-medium text-gray-900">
                                        <?php echo esc_html($row->ip_address); ?>
                                        <?php if ($is_blocked): ?>
                                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 status-badge">Banned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-gray-500"><?php echo esc_html($utm_display); ?></td>
                                    <td class="px-6 py-4">
                                        <a href="#" class="view-details text-blue-600 hover:underline font-medium flex items-center gap-1" data-ip="<?php echo esc_attr($row->ip_address); ?>" data-urls="<?php echo esc_attr((string) $row->urls); ?>">
                                            <?php echo esc_html(wp_date('d/m/Y, H:i', strtotime($row->last_visit))); ?> <i class="fa-regular fa-eye text-xs"></i>
                                        </a>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex items-center gap-1 text-orange-600 font-medium" title="Click Ads">
                                                <i class="fa-solid fa-bullseye"></i> <?php echo intval($row->ad_clicks); ?>
                                            </div>
                                            <div class="w-px h-4 bg-gray-300"></div>
                                            <div class="flex items-center gap-1 text-gray-500" title="Tổng lượt">
                                                <i class="fa-solid fa-chart-simple"></i> <?php echo intval($row->total_visits); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="tkgadm-switch mr-2">
                                            <input type="checkbox" id="<?php echo esc_attr($toggle_id); ?>" class="toggle-block tkgadm-switch__input" data-ip="<?php echo esc_attr($row->ip_address); ?>" aria-label="Chặn địa chỉ IP <?php echo esc_attr($row->ip_address); ?>" <?php checked($is_blocked); ?>>
                                            <label for="<?php echo esc_attr($toggle_id); ?>" class="tkgadm-switch__track"></label>
                                        </div>
                                        <span class="status-label text-xs font-semibold <?php echo $is_blocked ? 'text-red-600' : 'text-emerald-600'; ?>">
                                            <?php echo $is_blocked ? 'Bị chặn' : 'Hoạt động'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div class="p-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500 bg-white">
                <div id="table-info">Hiển thị <?php echo empty($results) ? '0' : '1-10'; ?> của <span id="total-ips-count"><?php echo count($results); ?></span> IPs</div>
                <div class="flex gap-1" id="pagination-controls">
                    <button class="px-3 py-1 rounded border border-gray-200 hover:bg-gray-50 disabled:opacity-50 h-8 border-solid cursor-pointer">Trước</button>
                    <button class="px-3 py-1 rounded bg-blue-600 text-white font-medium h-8 border-none cursor-pointer">1</button>
                    <button class="px-3 py-1 rounded border border-gray-200 hover:bg-gray-50 h-8 border-solid cursor-pointer">2</button>
                    <button class="px-3 py-1 rounded border border-gray-200 hover:bg-gray-50 h-8 border-solid cursor-pointer">3</button>
                    <button class="px-3 py-1 rounded border border-gray-200 hover:bg-gray-50 h-8 border-solid cursor-pointer">Tiếp</button>
                </div>
            </div>
        </div>

        <!-- Keep Modals -->
        <div id="manage-ip-modal" class="tkgadm-modal">
            <div class="tkgadm-modal-content" style="max-width: 500px; border-radius: 12px; padding: 24px;">
                <div class="tkgadm-modal-header border-b-0 pb-0 mb-4">
                    <h2 class="text-xl font-bold m-0 flex items-center gap-2"><i class="fa-solid fa-ban text-red-500"></i> Chặn IP</h2>
                    <span class="tkgadm-modal-close text-2xl text-gray-400 hover:text-gray-700 cursor-pointer">&times;</span>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Nhập danh sách IP (mỗi IP một dòng):</label>
                    <textarea id="ip-to-block" rows="6" class="w-full border border-gray-300 rounded p-2 text-sm font-mono focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Ví dụ:&#10;192.168.1.1&#10;192.168.1.*"></textarea>
                </div>
                <button id="confirm-block-ip" class="w-full bg-red-600 hover:bg-red-700 text-white font-medium py-2 rounded transition border-none cursor-pointer">🚫 Thực hiện chặn</button>
            </div>
        </div>

        <div id="url-modal" class="tkgadm-modal">
            <span class="tkgadm-modal-close text-white right-5 top-5 text-4xl cursor-pointer absolute">&times;</span>
            <div class="tkgadm-modal-content rounded-xl">
                <div class="tkgadm-modal-header border-b pb-3 mb-4">
                    <h2 id="modal-title" class="text-xl font-bold m-0">Chi tiết IP</h2>
                </div>
                <div class="mb-4"><canvas id="visit-chart" width="400" height="200"></canvas></div>
                <div id="url-list"></div>
            </div>
        </div>

        <div id="daily-details-modal" class="tkgadm-modal">
            <span class="tkgadm-modal-close text-white right-5 top-5 text-4xl cursor-pointer absolute">&times;</span>
            <div class="tkgadm-modal-content tkgadm-modal-lg rounded-xl">
                <div class="tkgadm-modal-header border-b pb-3 mb-4">
                    <h2 id="daily-modal-title" class="text-xl font-bold m-0">Chi tiết ngày</h2>
                </div>
                <div id="daily-details-content" class="max-h-[500px] overflow-y-auto"></div>
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
    $stats_table = $wpdb->prefix . 'gads_toolkit_stats';
    $ip = sanitize_text_field(wp_unslash($_POST['ip']));
    $block_action = isset($_POST['block_action']) ? sanitize_text_field(wp_unslash($_POST['block_action'])) : 'toggle';
    $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : 'Chặn thủ công bởi Admin';
    
    if (function_exists('tkgadm_validate_ip_pattern') && !tkgadm_validate_ip_pattern($ip)) {
        wp_send_json_error('IP không hợp lệ: ' . $ip);
    }
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE ip_address = %s", $ip));

    if ($block_action === 'toggle') {
        $block_action = $existing ? 'unblock' : 'block';
    }
    
    if ($block_action === 'unblock') {
        if (!$existing) {
            wp_send_json_success(['message' => 'IP chưa nằm trong danh sách chặn: ' . $ip, 'blocked' => false]);
        }

        // Unblock
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($table, ['ip_address' => $ip]);
        wp_send_json_success(['message' => 'Đã bỏ chặn IP: ' . $ip, 'blocked' => false]);
    }

    if ($block_action !== 'block') {
        wp_send_json_error('Hành động không hợp lệ.');
    }

    if ($existing) {
        wp_send_json_success(['message' => 'IP đã nằm trong danh sách chặn: ' . $ip, 'blocked' => true]);
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $visit_count = $wpdb->get_var($wpdb->prepare("SELECT SUM(visit_count) FROM $stats_table WHERE ip_address = %s", $ip));

    // Block
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $inserted = $wpdb->insert($table, [
        'ip_address' => $ip,
        'blocked_time' => current_time('mysql'),
        'reason' => $reason,
        'visit_count' => intval($visit_count)
    ]);

    if (!$inserted) {
        wp_send_json_error('Không thể thêm IP vào danh sách chặn.');
    }
        
    $sync_message = '';
    $sync_status = 'not_synced';
        
    // Try to sync if option is enabled
    if (get_option('tkgadm_auto_sync_on_block')) {
        if (function_exists('tkgadm_sync_ip_to_google_ads')) {
            $sync_result = tkgadm_sync_ip_to_google_ads([$ip]);
                
            if (isset($sync_result['success']) && $sync_result['success']) {
                $sync_message = 'Đã chặn trên Google Ads';
                $sync_status = 'synced';
            } else {
                $sync_message = 'Chỉ chặn ở website, chưa đồng bộ Google Ads';
                $sync_status = 'not_synced';
            }
        } else {
            $sync_message = 'Chỉ chặn ở website, chưa đồng bộ Google Ads';
        }
    } else {
        // Sync option is disabled
        $sync_message = 'Chỉ chặn ở website, chưa đồng bộ Google Ads';
    }

    wp_send_json_success([
        'message' => 'Đã chặn IP: ' . $ip,
        'blocked' => true,
        'sync_message' => $sync_message,
        'sync_status' => $sync_status
    ]);
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
    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : date('Y-m-d', strtotime('-29 days', current_time('timestamp')));
    $date_to = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : current_time('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $date_from = date('Y-m-d', strtotime('-29 days', current_time('timestamp')));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $date_to = current_time('Y-m-d');
    }
    
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
