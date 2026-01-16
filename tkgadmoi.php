<?php
/**
 * Plugin Name: Fraud Prevention for Google Ads
 * Plugin URI:  https://github.com/phudigital/gads-toolkit
 * Description: Giải pháp toàn diện giúp theo dõi và ngăn chặn click ảo (Fraud Click) từ Google Ads. Plugin tự động ghi log IP truy cập từ quảng cáo (chứa tham số gad_source), phân tích hành vi truy cập và cho phép chặn IP/dải IP thông minh bằng Wildcard.
 * Version:     2.1.5
 * Author:      Phú Digital
 * Author URI:  https://pdl.vn
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gads-toolkit
 */

/**
 * Hàm kiểm tra IP có khớp với pattern wildcard không
 * Ví dụ: 193.186.4.* sẽ match 193.186.4.1, 193.186.4.255, etc.
 */
function tkgadm_ip_matches_pattern($ip, $pattern) {
    // Chuyển pattern thành regex
    $regex = str_replace(['.', '*'], ['\.', '[0-9]+'], $pattern);
    $regex = '/^' . $regex . '$/';
    return preg_match($regex, $ip) === 1;
}

/**
 * Hàm kiểm tra IP có bị chặn không (hỗ trợ wildcard)
 */
function tkgadm_is_ip_blocked($ip) {
    global $wpdb;
    $blocked_table_name = $wpdb->prefix . 'tkgad_moi_blocked_ips';
    
    $blocked_patterns = $wpdb->get_col("SELECT ip_address FROM $blocked_table_name");
    
    foreach ($blocked_patterns as $pattern) {
        if (tkgadm_ip_matches_pattern($ip, $pattern)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Hàm validate IP pattern (cho phép wildcard)
 */
function tkgadm_validate_ip_pattern($pattern) {
    // Cho phép dạng: 192.168.1.1, 192.168.1.*, 192.168.**, 192.***, etc.
    $regex = '/^(\d{1,3}|\*)\.(\d{1,3}|\*)\.(\d{1,3}|\*)\.(\d{1,3}|\*)$/';
    if (!preg_match($regex, $pattern)) {
        return false;
    }
    
    // Kiểm tra từng octet (nếu không phải *)
    $parts = explode('.', $pattern);
    foreach ($parts as $part) {
        if ($part !== '*' && ($part < 0 || $part > 255)) {
            return false;
        }
    }
    
    return true;
}

// Hook tạo bảng khi kích hoạt plugin
register_activation_hook(__FILE__, 'tkgadm_tao_bang');

/**
 * Hàm tạo bảng cơ sở dữ liệu khi kích hoạt plugin
 */
function tkgadm_tao_bang() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Bảng thống kê mới
    $table_name = $wpdb->prefix . 'tkgad_moi';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(100) NOT NULL,
        visit_time DATETIME NOT NULL,
        url_visited TEXT NOT NULL,
        visit_count BIGINT(20) NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Bảng IP bị chặn mới
    $blocked_table_name = $wpdb->prefix . 'tkgad_moi_blocked_ips';
    $sql_blocked = "CREATE TABLE IF NOT EXISTS $blocked_table_name (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(100) NOT NULL,
        blocked_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY ip_address (ip_address)
    ) $charset_collate;";
    dbDelta($sql_blocked);
}

// Ghi log dữ liệu truy cập
add_action('wp_head', 'tkgadm_ghi_log_truy_cap');

/**
 * Hàm ghi lại dữ liệu người truy cập vào bảng thống kê
 */
function tkgadm_ghi_log_truy_cap() {
    if (is_admin()) return;

    global $wpdb;
    $table_name = $wpdb->prefix . 'tkgad_moi';

    $ip_address = $_SERVER['REMOTE_ADDR'];
    $visit_time = current_time('mysql');
    $url_visited = esc_url_raw(home_url($_SERVER['REQUEST_URI']));

    if (strpos($url_visited, 'gad_source') === false) return;

    $existing_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE ip_address = %s AND url_visited = %s",
        $ip_address, $url_visited
    ));

    if ($existing_entry) {
        $wpdb->update(
            $table_name,
            ['visit_count' => $existing_entry->visit_count + 1, 'visit_time' => $visit_time],
            ['id' => $existing_entry->id]
        );
    } else {
        $wpdb->insert(
            $table_name,
            ['ip_address' => $ip_address, 'visit_time' => $visit_time, 'url_visited' => $url_visited]
        );
    }
}

// Thêm menu admin
add_action('admin_menu', 'tkgadm_them_menu_admin');

/**
 * Hàm đăng ký menu quản trị với submenu
 */
function tkgadm_them_menu_admin() {
    add_menu_page(
        'Google Ads Fraud Toolkit',
        'GAds Toolkit',
        'manage_options',
        'tkgad-moi',
        'tkgadm_hien_thi_trang_thong_ke',
        'dashicons-chart-bar'
    );
    
    // Submenu: Statistics
    // add_submenu_page(
    //     'tkgad-moi',
    //     'Statistics',
    //     'Statistics',
    //     'manage_options',
    //     'tkgad-moi',
    //     'tkgadm_hien_thi_trang_thong_ke'
    // );
    
    // Submenu: Blocked IPs
    // add_submenu_page(
    //     'tkgad-moi',
    //     'Blocked IPs',
    //     'Blocked IPs',
    //     'manage_options',
    //     'tkgad-blocked-ips',
    //     'tkgadm_hien_thi_trang_blocked_ips'
    // );
}

/**
 * Enqueue scripts và styles
 */
function tkgadm_enqueue_admin_assets($hook) {
    // Chỉ load trên trang của plugin (check theo page hook suffix)
    // admin_page_tkgad-moi là hook name thường thấy, nhưng để chắc chắn ta check strpos
    if (strpos($hook, 'tkgad-moi') === false && strpos($hook, 'tkgad-blocked-ips') === false) {
        return;
    }

    wp_enqueue_style('tkgadm-admin-style', plugins_url('assets/admin-style.css', __FILE__));
    
    // Thêm Chart.js trước custom script
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true);
    
    wp_enqueue_script('tkgadm-admin-script', plugins_url('assets/admin-script.js', __FILE__), array('jquery', 'chart-js'), '1.0', true);

    wp_localize_script('tkgadm-admin-script', 'tkgadm_vars', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce_block' => wp_create_nonce('tkgadm_toggle_block'),
        'nonce_chart' => wp_create_nonce('tkgadm_chart')
    ));
}
add_action('admin_enqueue_scripts', 'tkgadm_enqueue_admin_assets');

/**
 * Hàm trích xuất utm_term từ URL
 */
function tkgadm_extract_utm_term($url) {
    $parsed = parse_url($url);
    if (isset($parsed['query'])) {
        parse_str($parsed['query'], $params);
        return isset($params['utm_term']) ? $params['utm_term'] : '-';
    }
    return '-';
}

/**
 * Xử lý AJAX toggle chặn/bỏ chặn IP
 */
add_action('wp_ajax_tkgadm_toggle_block_ip', 'tkgadm_toggle_block_ip');
function tkgadm_toggle_block_ip() {
    check_ajax_referer('tkgadm_toggle_block', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền');
    }
    
    global $wpdb;
    $blocked_table_name = $wpdb->prefix . 'tkgad_moi_blocked_ips';
    $ip = sanitize_text_field($_POST['ip']);
    $action = sanitize_text_field($_POST['block_action']); // 'block' hoặc 'unblock'
    
    // Validate IP pattern (hỗ trợ wildcard)
    if (!tkgadm_validate_ip_pattern($ip)) {
        wp_send_json_error('IP/Pattern không hợp lệ');
    }
    
    if ($action === 'block') {
        // Chặn IP
        $result = $wpdb->insert(
            $blocked_table_name,
            ['ip_address' => $ip],
            ['%s']
        );
        
        if ($result) {
            wp_send_json_success([
                'message' => 'Đã chặn IP: ' . $ip,
                'blocked' => true
            ]);
        } else {
            wp_send_json_error('IP đã tồn tại hoặc lỗi');
        }
    } else {
        // Bỏ chặn IP
        $result = $wpdb->delete(
            $blocked_table_name,
            ['ip_address' => $ip],
            ['%s']
        );
        
        if ($result) {
            wp_send_json_success([
                'message' => 'Đã bỏ chặn IP: ' . $ip,
                'blocked' => false
            ]);
        } else {
            wp_send_json_error('Không tìm thấy IP hoặc lỗi');
        }
    }
}

/**
 * AJAX lấy dữ liệu biểu đồ theo IP
 */
add_action('wp_ajax_tkgadm_get_chart_data', 'tkgadm_get_chart_data');
function tkgadm_get_chart_data() {
    check_ajax_referer('tkgadm_chart', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'tkgad_moi';
    $ip = sanitize_text_field($_POST['ip']);
    
    // Lấy dữ liệu theo ngày/giờ
    $query = $wpdb->prepare(
        "SELECT DATE_FORMAT(visit_time, '%%Y-%%m-%%d %%H:00:00') as hour,
                SUM(visit_count) as total
         FROM $table_name
         WHERE ip_address = %s
         GROUP BY hour
         ORDER BY hour ASC",
        $ip
    );
    
    $results = $wpdb->get_results($query);
    
    $labels = [];
    $data = [];
    
    foreach ($results as $row) {
        $labels[] = date('d/m H:00', strtotime($row->hour));
        $data[] = (int)$row->total;
    }
    
    wp_send_json_success([
        'labels' => $labels,
        'data' => $data
    ]);
}

/**
 * Hàm hiển thị trang thống kê
 */
function tkgadm_hien_thi_trang_thong_ke() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tkgad_moi';
    $blocked_table_name = $wpdb->prefix . 'tkgad_moi_blocked_ips';

    // Xử lý thêm/import IP từ popup
    if (isset($_POST['add_ips_popup']) && check_admin_referer('tkgadm_add_ips_popup')) {
        $ips_text = sanitize_textarea_field($_POST['ip_patterns']);
        $ips = array_filter(array_map('trim', explode("\n", $ips_text)));
        
        $success_count = 0;
        $error_count = 0;
        
        foreach ($ips as $ip) {
            if (tkgadm_validate_ip_pattern($ip)) {
                $result = $wpdb->insert(
                    $blocked_table_name,
                    ['ip_address' => $ip],
                    ['%s']
                );
                if ($result) $success_count++;
                else $error_count++;
            } else {
                $error_count++;
            }
        }
        
        echo '<div class="notice notice-success"><p>✅ Đã thêm ' . $success_count . ' IP/Pattern. Lỗi: ' . $error_count . '</p></div>';
    }

    // Lấy date range và filter từ GET parameters
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
    $show_blocked_only = isset($_GET['show_blocked']) && $_GET['show_blocked'] === '1';
    $search_ip = isset($_GET['search_ip']) ? sanitize_text_field($_GET['search_ip']) : '';
    $order = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';
    $new_order = $order === 'ASC' ? 'desc' : 'asc';

    $where_clause = "";
    
    // Lọc theo khoảng ngày
    if (!empty($date_from) && !empty($date_to)) {
        $where_clause = $wpdb->prepare(
            "WHERE DATE(visit_time) BETWEEN %s AND %s",
            $date_from,
            $date_to
        );
    } elseif (!empty($date_from)) {
        $where_clause = $wpdb->prepare("WHERE DATE(visit_time) >= %s", $date_from);
    } elseif (!empty($date_to)) {
        $where_clause = $wpdb->prepare("WHERE DATE(visit_time) <= %s", $date_to);
    }

    if (!empty($search_ip)) {
        $where_clause .= $where_clause ? " AND " : "WHERE ";
        $where_clause .= $wpdb->prepare("ip_address LIKE %s", '%' . $wpdb->esc_like($search_ip) . '%');
    }

    // Query: gộp theo IP, lấy tất cả URLs
    $query = "SELECT t.ip_address, 
                     GROUP_CONCAT(DISTINCT t.url_visited SEPARATOR '|||') AS urls,
                     MAX(t.visit_time) AS last_visit,
                     MIN(t.visit_time) AS first_visit,
                     SUM(t.visit_count) AS total_visits
              FROM $table_name t 
              $where_clause 
              GROUP BY t.ip_address 
              ORDER BY total_visits $order";

    $results = $wpdb->get_results($query);
    
    // Kiểm tra IP blocked với wildcard cho mỗi kết quả
    foreach ($results as $row) {
        $row->blocked = tkgadm_is_ip_blocked($row->ip_address);
    }
    
    // Filter: Chỉ hiển thị IP bị chặn nếu show_blocked=1
    if ($show_blocked_only) {
        $results = array_filter($results, function($row) {
            return $row->blocked;
        });
        
        // Set date range mặc định nếu chưa có
        if (empty($date_from) && empty($date_to) && !empty($results)) {
            // Lấy ngày cũ nhất và mới nhất từ results
            $dates = array_map(function($row) { return $row->first_visit; }, $results);
            $date_from = !empty($dates) ? date('Y-m-d', strtotime(min($dates))) : '';
            $date_to = date('Y-m-d');
        }
    }


    echo '<div class="tkgadm-wrap">';
    echo '<div class="tkgadm-header">';
    echo '<h1>📊 Thống Kê Google Ads Toolkit</h1>';

    echo '<div class="tkgadm-filters">';
    
    // Date Range Picker - Thu gọn với format dd/mm/yyyy
    echo '<div style="display: inline-flex; gap: 5px; align-items: center; background: white; padding: 8px 12px; border-radius: 8px;">';
    echo '<input type="date" id="date-from" class="tkgadm-input" style="width: 150px; padding: 6px; font-size: 13px;" value="' . esc_attr($date_from) . '" title="Từ ngày (dd/mm/yyyy)" placeholder="dd/mm/yyyy">';
    echo '<span style="color: #999;">→</span>';
    echo '<input type="date" id="date-to" class="tkgadm-input" style="width: 150px; padding: 6px; font-size: 13px;" value="' . esc_attr($date_to) . '" title="Đến ngày (dd/mm/yyyy)" placeholder="dd/mm/yyyy">';
    echo '<button id="apply-date-range" class="tkgadm-btn tkgadm-btn-primary" style="padding: 6px 12px;" title="Lọc">🔍</button>';
    if (!empty($date_from) || !empty($date_to) || $show_blocked_only) {
        echo '<button id="clear-date-range" class="tkgadm-btn tkgadm-btn-secondary" style="padding: 6px 12px;" title="Xóa bộ lọc">✖️</button>';
    }
    echo '</div>';
    
    // Action Buttons
    echo '<button id="open-manage-ip" class="tkgadm-btn tkgadm-btn-primary" style="background: #28a745;">➕ Chặn IP</button>';
    
    // Nút IP Blocked - Load filtered table thay vì popup
    $blocked_url = add_query_arg(['show_blocked' => '1'], '?page=tkgad-moi');
    $blocked_class = $show_blocked_only ? 'tkgadm-btn-primary' : 'tkgadm-btn-secondary';
    echo '<a href="' . esc_url($blocked_url) . '" class="tkgadm-btn ' . $blocked_class . '" style="background: ' . ($show_blocked_only ? '#dc3545' : '#6c757d') . '; text-decoration: none;">🚫 DS IP đã chặn</a>';
    
    // Toggle switch Ẩn/Hiện IP Blocked
    echo '<label class="tkgadm-toggle-container" style="display: inline-flex; align-items: center; cursor: pointer;">';
    echo '<input type="checkbox" id="toggle-blocked-ips" style="display: none;">';
    echo '<span class="tkgadm-toggle-slider" style="position: relative; width: 50px; height: 24px; background: #ccc; border-radius: 24px; transition: 0.3s;"></span>';
    echo '<span style="margin-left: 8px; font-size: 14px; font-weight: 500;">Ẩn IP Blocked</span>';
    echo '</label>';
    
    // Nút Copy IP Chặn
    echo '<button id="copy-blocked-ips-btn" class="tkgadm-btn tkgadm-btn-primary" style="background: #17a2b8;">📋 Copy IP Chặn</button>';
    
    echo '</div>';
    echo '</div>';
    
    // Textarea ẩn chứa danh sách IP bị chặn để copy
    $all_blocked_ips = $wpdb->get_col("SELECT ip_address FROM $blocked_table_name ORDER BY blocked_time DESC");
    $blocked_ips_text = implode("\n", $all_blocked_ips);
    echo '<textarea id="blocked-ips-copy-hidden" style="position: absolute; left: -9999px;">' . esc_textarea($blocked_ips_text) . '</textarea>';

    if (empty($results)) {
        echo '<div class="tkgadm-table-container">';
        echo '<p style="text-align:center; padding: 40px; color: #999;">Chưa có dữ liệu để hiển thị.</p>';
        echo '</div>';
    } else {
        echo '<div class="tkgadm-table-container">';
        echo '<table class="tkgadm-table">';
        echo '<thead><tr>';
        echo '<th>🌐 IP Address</th>';
        echo '<th>🔗 Số URLs</th>';
        echo '<th>🏷️ UTM Terms</th>';
        echo '<th>⏰ Lần truy cập cuối</th>';
        echo '<th><a href="' . esc_url(add_query_arg('order', $new_order)) . '" style="color:white; text-decoration:none;">📈 Tổng lượt truy cập</a></th>';
        echo '<th>⚙️ Hành động</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($results as $row) {
            $is_blocked = $row->blocked ? true : false;
            $row_class = $is_blocked ? 'tkgadm-blocked blocked-ip' : '';
            
            $urls = explode('|||', $row->urls);
            $url_count = count($urls);
            
            // Lấy tất cả utm_term
            $utm_terms = array_unique(array_map('tkgadm_extract_utm_term', $urls));
            $utm_display = implode(', ', array_filter($utm_terms, function($term) { return $term !== '-'; }));
            if (empty($utm_display)) $utm_display = '-';
            
            echo '<tr class="' . $row_class . '" data-ip="' . esc_attr($row->ip_address) . '">';
            echo '<td><strong>' . esc_html($row->ip_address) . '</strong>';
            if ($is_blocked) {
                echo ' <span class="tkgadm-badge tkgadm-badge-danger">🚫 Đã chặn</span>';
            }
            echo '</td>';
            echo '<td><span class="tkgadm-badge tkgadm-badge-success">' . $url_count . ' URLs</span></td>';
            echo '<td>' . esc_html($utm_display) . '</td>';
            echo '<td>' . esc_html($row->last_visit) . '</td>';
            echo '<td><strong>' . esc_html($row->total_visits) . '</strong></td>';
            echo '<td>';
            echo '<button class="tkgadm-btn-details view-details" data-ip="' . esc_attr($row->ip_address) . '" data-urls="' . esc_attr($row->urls) . '">📋 Chi tiết</button>';
            
            // Toggle switch cho chặn/bỏ chặn
            $checked = $is_blocked ? 'checked' : '';
            $label_class = $is_blocked ? 'blocked' : 'active';
            $label_text = $is_blocked ? 'Đã chặn' : 'Hoạt động';
            
            echo '<label class="tkgadm-toggle-switch">';
            echo '<input type="checkbox" class="toggle-block" data-ip="' . esc_attr($row->ip_address) . '" ' . $checked . '>';
            echo '<span class="tkgadm-toggle-slider"></span>';
            echo '</label>';
            echo '<span class="tkgadm-toggle-label ' . $label_class . '">' . $label_text . '</span>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
    }

    // Modal
    echo '<div id="url-modal" class="tkgadm-modal">';
    echo '<div class="tkgadm-modal-content">';
    echo '<div class="tkgadm-modal-header">';
    echo '<h2 id="modal-title">Chi tiết URLs</h2>';
    echo '<span class="tkgadm-modal-close">&times;</span>';
    echo '</div>';
    echo '<div id="modal-body">';
    echo '<canvas id="visit-chart" style="max-height: 300px; margin: 20px 0;"></canvas>';
    echo '<div id="url-list"></div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Thêm Chart.js
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
    
    // Popup Quản lý IP
    echo '<div id="manage-ip-modal" class="tkgadm-modal">';
    echo '<div class="tkgadm-modal-content">';
    echo '<div class="tkgadm-modal-header">';
    echo '<h2>➕ Quản lý IP bị chặn</h2>';
    echo '<span class="tkgadm-modal-close" onclick="document.getElementById(\'manage-ip-modal\').style.display=\'none\';">&times;</span>';
    echo '</div>';
    echo '<div style="padding: 20px;">';
    
    // Hướng dẫn wildcard
    echo '<div style="background: #e7f3ff; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #667eea;">';
    echo '<h4 style="margin-top: 0; color: #667eea;">📌 Hướng dẫn sử dụng Wildcard</h4>';
    echo '<ul style="margin: 10px 0; padding-left: 20px;">';
    echo '<li><code>193.186.4.*</code> - Chặn tất cả IP từ 193.186.4.0 đến 193.186.4.255</li>';
    echo '<li><code>162.120.*.*</code> - Chặn tất cả IP từ 162.120.0.0 đến 162.120.255.255</li>';
    echo '<li><code>10.*.*.*</code> - Chặn tất cả IP từ 10.0.0.0 đến 10.255.255.255</li>';
    echo '<li><code>192.168.1.100</code> - Chặn IP cụ thể</li>';
    echo '</ul>';
    echo '</div>';
    
    // Form nhập IP
    echo '<form method="post">';
    wp_nonce_field('tkgadm_add_ips_popup');
    echo '<label style="font-weight: 600; display: block; margin-bottom: 10px;">Nhập IP/Pattern (mỗi dòng một):</label>';
    echo '<textarea name="ip_patterns" class="tkgadm-textarea" style="width: 100%; min-height: 200px; padding: 12px; border: 2px solid #f0f0f0; border-radius: 8px; font-family: monospace;" placeholder="192.168.1.1&#10;193.186.4.*&#10;162.120.*.*" required></textarea>';
    echo '<div style="margin-top: 15px;">';
    echo '<input type="submit" name="add_ips_popup" class="tkgadm-btn tkgadm-btn-primary" value="➕ Thêm IP/Pattern">';
    echo '<button type="button" class="tkgadm-btn tkgadm-btn-secondary" onclick="document.getElementById(\'manage-ip-modal\').style.display=\'none\';" style="margin-left: 10px;">Đóng</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Popup Danh sách IP bị chặn
    $blocked_ips = $wpdb->get_results("SELECT * FROM $blocked_table_name ORDER BY blocked_time DESC");
    $ip_list = implode("\n", array_map(function($ip) { return $ip->ip_address; }, $blocked_ips));
    
    echo '<div id="blocked-list-modal" class="tkgadm-modal">';
    echo '<div class="tkgadm-modal-content">';
    echo '<div class="tkgadm-modal-header">';
    echo '<h2>📋 Danh sách IP bị chặn (' . count($blocked_ips) . ')</h2>';
    echo '<span class="tkgadm-modal-close" onclick="document.getElementById(\'blocked-list-modal\').style.display=\'none\';">&times;</span>';
    echo '</div>';
    echo '<div style="padding: 20px;">';
    
    // Nút Copy
    echo '<div style="margin-bottom: 15px;">';
    echo '<button id="copy-blocked-ips" class="tkgadm-btn tkgadm-btn-primary" style="background: #28a745;">📋 Copy tất cả</button>';
    echo '</div>';
    
    // Bảng danh sách
    if (empty($blocked_ips)) {
        echo '<p style="text-align: center; color: #999; padding: 40px;">Chưa có IP nào bị chặn</p>';
    } else {
        echo '<div style="max-height: 400px; overflow-y: auto;">';
        echo '<table class="tkgadm-table">';
        echo '<thead><tr><th>🌐 IP/Pattern</th><th>⏰ Thời gian chặn</th><th>⚙️ Hành động</th></tr></thead>';
        echo '<tbody>';
        foreach ($blocked_ips as $ip) {
            echo '<tr>';
            echo '<td><code>' . esc_html($ip->ip_address) . '</code></td>';
            echo '<td>' . esc_html($ip->blocked_time) . '</td>';
            echo '<td><button class="tkgadm-btn-block delete-blocked-ip" data-ip="' . esc_attr($ip->ip_address) . '">🗑️ Xóa</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
    
    echo '<textarea id="blocked-ips-hidden" style="position: absolute; left: -9999px;">' . esc_textarea($ip_list) . '</textarea>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '</div>'; // Đóng .tkgadm-wrap
}

/**
 * Trang Blocked IPs - Redirect về Statistics với filter
 */
function tkgadm_hien_thi_trang_blocked_ips() {
    wp_redirect(admin_url('admin.php?page=tkgad-moi&show_blocked=1'));
    exit;
}
