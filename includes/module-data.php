<?php
/**
 * Module: Data Maintenance
 * Manages Database size, clearing old logs, and maintenance tools.
 */

if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * 1. HELPER FUNCTIONS
 * ============================================================================
 */

/**
 * Lấy kích thước bảng trong database
 */
function tkgadm_get_table_size($table_name) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $row = $wpdb->get_row($wpdb->prepare("SHOW TABLE STATUS LIKE %s", $table_name));
    
    if ($row) {
        $size = $row->Data_length + $row->Index_length;
        return size_format($size);
    }
    return '0 B';
}

/**
 * Đếm số lượng record
 */
function tkgadm_get_table_count($table_name) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
}

/**
 * ============================================================================
 * 2. ADMIN UI (MAINTENANCE PAGE)
 * ============================================================================
 */

function tkgadm_render_maintenance_page() {
    global $wpdb;
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    
    $stats_size = tkgadm_get_table_size($table_stats);
    $stats_count = tkgadm_get_table_count($table_stats);
    
    $blocked_size = tkgadm_get_table_size($table_blocked);
    $blocked_count = tkgadm_get_table_count($table_blocked);

    // Get Min/Max Block Request date
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $date_range = $wpdb->get_row("SELECT MIN(blocked_time) as min_date, MAX(blocked_time) as max_date FROM $table_blocked");
    
    $default_start_date = $date_range && $date_range->min_date ? date('Y-m-d', strtotime($date_range->min_date)) : '';
    $default_end_date = $date_range && $date_range->max_date ? date('Y-m-d', strtotime($date_range->max_date)) : '';
    
    ?>
    <div class="wrap">
        <div class="tkgadm-wrap">
            <div class="tkgadm-header">
                <h1>🗄️ Quản Lý Dữ Liệu</h1>
                <p style="color: #666; margin-top: 10px;">Quản lý danh sách chặn và tối ưu hóa cơ sở dữ liệu.</p>
            </div>

            <!-- 1. Blocked IP Management Section (Main Feature) -->
            <div class="tkgadm-card" style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #ddd; margin-bottom: 25px;">
                <h2 style="margin-top: 0; display:flex; align-items:center; justify-content:space-between;">
                    <span>🛡️ Quản Lý IP Bị Chặn</span>
                    <span id="blocked-count-badge" class="tkgadm-badge" style="background:#eee; color:#333; font-size:14px;">0 IP</span>
                </h2>
                <p>Tra cứu và sao chép danh sách IP chặn theo điều kiện.</p>
                
                <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
                
                <!-- Filter Controls -->
                <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 20px;">
                    <div>
                        <label for="filter-visit-count" style="font-weight:bold; display:block; margin-bottom:5px;">Số phiên tối thiểu:</label>
                        <input type="number" id="filter-visit-count" class="tkgadm-input" value="0" min="0" style="width: 100px;">
                    </div>

                    <div>
                        <label for="filter-date-start" style="font-weight:bold; display:block; margin-bottom:5px;">Từ ngày:</label>
                        <input type="date" id="filter-date-start" class="tkgadm-input" value="<?php echo esc_attr($default_start_date); ?>">
                    </div>

                    <div>
                        <label for="filter-date-end" style="font-weight:bold; display:block; margin-bottom:5px;">Đến ngày:</label>
                        <input type="date" id="filter-date-end" class="tkgadm-input" value="<?php echo esc_attr($default_end_date); ?>">
                    </div>
                    
                    <button id="btn-filter-blocked" class="button button-primary">🔍 Lọc danh sách</button>
                    
                    <div style="margin-left: auto;">
                        <button id="btn-copy-blocked" class="button button-secondary" disabled>📋 Copy danh sách IP</button>
                    </div>
                </div>

                <!-- Results Table -->
                <div style="max-height: 400px; overflow-y: auto; border: 1px solid #eee; border-radius: 4px;">
                    <table class="wp-list-table widefat fixed striped" style="border:0;">
                        <thead>
                            <tr>
                                <th width="200">IP Address</th>
                                <th width="100">Số phiên</th>
                                <th>Thời gian chặn</th>
                                <th>Lý do</th>
                            </tr>
                        </thead>
                        <tbody id="blocked-ip-list">
                            <tr><td colspan="4" style="text-align:center; padding: 20px;">Đang tải dữ liệu...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 2. Database Maintenance Section (Stats + Cleanup) -->
            <div class="tkgadm-card" style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #ddd;">
                <h2 style="margin-top: 0;">🧹 Quản Trị & Dọn Dẹp Database</h2>
                <p>Theo dõi dung lượng và xóa dữ liệu cũ.</p>
                
                <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
                
                <!-- Info Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; background: #f9f9f9; padding: 15px; border-radius: 8px;">
                    <!-- Stats Info -->
                    <div>
                        <strong style="color: #0073aa;">📊 Bảng Thống Kê (Logs)</strong>
                        <div style="display: flex; justify-content: space-between; margin-top: 10px; border-bottom: 1px dashed #ddd; padding-bottom: 5px;">
                            <span>Số dòng:</span> <strong><?php echo number_format($stats_count); ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                            <span>Dung lượng:</span> <strong><?php echo esc_html($stats_size); ?></strong>
                        </div>
                    </div>
                    
                    <!-- Blocked Info -->
                    <div>
                        <strong style="color: #d63638;">🚫 Bảng IP Bị Chặn</strong>
                        <div style="display: flex; justify-content: space-between; margin-top: 10px; border-bottom: 1px dashed #ddd; padding-bottom: 5px;">
                            <span>Số IP:</span> <strong><?php echo number_format($blocked_count); ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                            <span>Dung lượng:</span> <strong><?php echo esc_html($blocked_size); ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Cleanup Tools -->
                <h3 style="font-size: 14px; margin-bottom: 15px;">🗑️ Công cụ xóa dữ liệu</h3>
                <div style="display: flex; align-items: flex-end; gap: 20px; flex-wrap: wrap;">
                    
                    <!-- Option 1: Delete by Date Range -->
                    <div style="flex: 1; min-width: 300px;">
                        <label style="font-weight: 500; display:block; margin-bottom:5px;">Xóa theo khoảng thời gian:</label>
                        <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                            <input type="date" id="delete-from" class="tkgadm-input" title="Từ ngày">
                            <span>→</span>
                            <input type="date" id="delete-to" class="tkgadm-input" title="Đến ngày">
                        </div>
                        <button id="btn-delete-range" class="button button-secondary">Thực hiện xóa</button>
                    </div>

                    <!-- Option 2: Delete older than X days -->
                    <div style="flex: 1; min-width: 300px;">
                        <label style="font-weight: 500; display:block; margin-bottom:5px;">Xóa dữ liệu cũ hơn:</label>
                        <div style="margin-bottom: 10px;">
                            <select id="delete-age" class="tkgadm-input" style="width: 200px;">
                                <option value="365">1 năm</option>
                                <option value="730">2 năm</option>
                                <option value="1095" selected>3 năm</option>
                            </select>
                        </div>
                        <button id="btn-delete-old" class="button button-link-delete" style="color: #d63638; border-color: #d63638;">🔥 Xóa nhanh</button>
                    </div>

                </div>

                <!-- Status Message -->
                <div id="delete-status" style="margin-top: 20px; padding: 10px; background: #fff; border: 1px solid #eee; display: none; border-radius: 4px;"></div>
                
                <!-- Auto Cleanup Setting (Placeholder) -->
                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee; font-size: 12px; color: #888;">
                    <label>
                        <input type="checkbox" disabled> Tự động xóa logs cũ hơn 90 ngày (Tính năng Cron Job sắp ra mắt)
                    </label>
                </div>
            </div>

        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        let currentIps = []; // Store current filtered IPs for copying

        // Format number
        function formatNumber(num) {
            return num.toString().replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1,');
        }

        // Handle Filter Button
        $('#btn-filter-blocked').on('click', function() {
            const minVisits = $('#filter-visit-count').val();
            const startDate = $('#filter-date-start').val();
            const endDate = $('#filter-date-end').val();
            const btn = $(this);
            
            btn.prop('disabled', true).text('⏳ Đang tải...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tkgadm_get_blocked_ips',
                    min_visits: minVisits,
                    start_date: startDate,
                    end_date: endDate,
                    nonce: '<?php echo wp_create_nonce("tkgadm_data_nonce"); ?>'
                },
                success: function(response) {
                    btn.prop('disabled', false).text('🔍 Lọc danh sách');
                    
                    if (response.success) {
                        const ips = response.data;
                        currentIps = ips.map(item => item.ip_address); // Save for copy
                        
                        $('#blocked-count-badge').text(formatNumber(ips.length) + ' IP');
                        
                        if (ips.length > 0) {
                            let html = '';
                            ips.forEach(ip => {
                                html += `<tr>
                                    <td><strong>${ip.ip_address}</strong></td>
                                    <td><span class="tkgadm-badge" style="background:${ip.visit_count > 10 ? '#ffebee' : '#f0f0f1'}; color:${ip.visit_count > 10 ? '#c00' : '#444'}">${ip.visit_count}</span></td>
                                    <td>${ip.blocked_time}</td>
                                    <td style="color:#666;">${ip.reason || '-'}</td>
                                </tr>`;
                            });
                            $('#blocked-ip-list').html(html);
                            $('#btn-copy-blocked').prop('disabled', false).text('📋 Copy danh sách IP (' + ips.length + ')');
                        } else {
                            $('#blocked-ip-list').html('<tr><td colspan="4" style="text-align:center; padding:20px;">Không tìm thấy IP nào thỏa mãn điều kiện.</td></tr>');
                            $('#btn-copy-blocked').prop('disabled', true).text('📋 Copy danh sách IP');
                        }
                    } else {
                        $('#blocked-ip-list').html('<tr><td colspan="4" style="color:red; text-align:center; padding:20px;">Lỗi: ' + response.data + '</td></tr>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('🔍 Lọc danh sách');
                    $('#blocked-ip-list').html('<tr><td colspan="4" style="color:red; text-align:center; padding:20px;">Lỗi kết nối Server.</td></tr>');
                }
            });
        });

        // Auto load on init
        setTimeout(function() {
            console.log('Auto-triggering filter...');
            $('#btn-filter-blocked').trigger('click');
        }, 100);

        // Handle Copy Button
        $('#btn-copy-blocked').on('click', function() {
            if (currentIps.length === 0) return;
            
            const textToCopy = currentIps.join('\n');
            navigator.clipboard.writeText(textToCopy).then(function() {
                const originalText = $('#btn-copy-blocked').text();
                $('#btn-copy-blocked').text('✅ Đã Copy!');
                setTimeout(() => $('#btn-copy-blocked').text(originalText), 2000);
            }, function(err) {
                alert('Không thể copy: ' + err);
            });
        });
        
        // --- Existing Cleanup Logic Below ---
        function callDeleteApi(data, confirmMsg) {
            if (!confirm(confirmMsg)) {
                return;
            }

            $('#delete-status').show().html('⏳ Đang xử lý...');
            $('button').prop('disabled', true);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: Object.assign(data, {
                    action: 'tkgadm_delete_data',
                    nonce: '<?php echo wp_create_nonce("tkgadm_delete_nonce"); ?>'
                }),
                success: function(response) {
                    $('button').prop('disabled', false);
                    if (response.success) {
                        $('#delete-status').html('<span style="color: green;">✅ ' + response.data.message + '</span>');
                        
                        // Reload sau 2s để cập nhật số liệu
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#delete-status').html('<span style="color: red;">❌ ' + response.data + '</span>');
                    }
                },
                error: function() {
                    $('button').prop('disabled', false);
                    $('#delete-status').html('<span style="color: red;">❌ Lỗi kết nối Server.</span>');
                }
            });
        }

        // Handle Delete Range
        $('#btn-delete-range').on('click', function() {
            const from = $('#delete-from').val();
            const to = $('#delete-to').val();

            if (!from || !to) {
                alert('Vui lòng chọn đầy đủ ngày bắt đầu và kết thúc.');
                return;
            }

            callDeleteApi(
                { type: 'range', from: from, to: to },
                '⚠️ CẢNH BÁO: Hành động này không thể hoàn tác.\nBạn có chắc muốn xóa logs từ ' + from + ' đến ' + to + '?'
            );
        });

        // Handle Delete Old
        $('#btn-delete-old').on('click', function() {
            const age = $('#delete-age').val();
            let msg = '';
            
            if (age === 'all') {
                msg = '⚠️ CẢNH BÁO NGUY HIỂM: \nBạn sắp xóa TOÀN BỘ dữ liệu thống kê (Logs).\nHành động này KHÔNG THỂ khôi phục.\n\nBạn có chắc chắn không?';
            } else {
                msg = '⚠️ Bạn có chắc muốn xóa logs cũ hơn ' + age + ' ngày?';
            }

            callDeleteApi(
                { type: 'age', days: age },
                msg
            );
        });

    });
    </script>
    <?php
}

/**
 * ============================================================================
 * 3. AJAX HANDLERS
 * ============================================================================
 */

add_action('wp_ajax_tkgadm_get_blocked_ips', 'tkgadm_ajax_get_blocked_ips');
function tkgadm_ajax_get_blocked_ips() {
    check_ajax_referer('tkgadm_data_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền truy cập.');
    }
    
    global $wpdb;
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    
    $min_visits = isset($_POST['min_visits']) ? intval($_POST['min_visits']) : 0;
    $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

    // Build Where Clause
    $where_clauses = array("visit_count >= %d");
    $params = array($min_visits);

    if (!empty($start_date)) {
        $where_clauses[] = "blocked_time >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    
    if (!empty($end_date)) {
        $where_clauses[] = "blocked_time <= %s";
        $params[] = $end_date . ' 23:59:59';
    }

    $where_sql = implode(' AND ', $where_clauses);
    
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $query = $wpdb->prepare(
        "SELECT ip_address, blocked_time, reason, visit_count 
         FROM $table_blocked 
         WHERE $where_sql 
         ORDER BY visit_count DESC, blocked_time DESC
         LIMIT 1000", // Giới hạn 1000 IP để tránh treo trình duyệt
        $params
    );
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $results = $wpdb->get_results($query);
    
    wp_send_json_success($results);
}

add_action('wp_ajax_tkgadm_delete_data', 'tkgadm_ajax_delete_data');
function tkgadm_ajax_delete_data() {
    check_ajax_referer('tkgadm_delete_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền truy cập.');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    $type = $_POST['type'];
    $rows_affected = 0;
    
    if ($type === 'range') {
        $from = sanitize_text_field($_POST['from']);
        $to = sanitize_text_field($_POST['to']);
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query = $wpdb->prepare("DELETE FROM $table WHERE visit_time >= %s AND visit_time <= %s", $from . ' 00:00:00', $to . ' 23:59:59');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows_affected = $wpdb->query($query);
        
    } elseif ($type === 'age') {
        $days = $_POST['days'];
        
        if ($days === 'all') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows_affected = $wpdb->query("TRUNCATE TABLE $table");
        } else {
            $days = intval($days);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $query = $wpdb->prepare("DELETE FROM $table WHERE visit_time < DATE_SUB(NOW(), INTERVAL %d DAY)", $days);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows_affected = $wpdb->query($query);
        }
    }
    
    if ($rows_affected !== false) {
        wp_send_json_success(['message' => "Đã xóa thành công $rows_affected dòng dữ liệu."]);
    } else {
        wp_send_json_error("Lỗi khi xóa dữ liệu DB.");
    }
}
