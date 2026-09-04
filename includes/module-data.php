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
        <div class="wp-wrap tkgadm-page tkgadm-data-page space-y-6" style="padding: 20px 0;">
        <?php tkgadm_render_admin_workspace('data'); ?>
        <div class="space-y-6">
            <!-- Header -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2 m-0 pb-1">
                        <i class="fa-solid fa-database text-blue-600"></i> Quản Lý Dữ Liệu
                    </h1>
                    <p class="text-sm text-gray-500 m-0">Tra cứu IP bị chặn và tối ưu hóa cơ sở dữ liệu</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column: Database Stats & Cleanup -->
                <div class="space-y-6">
                    <!-- Stats -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                        <h3 class="text-base font-bold text-gray-800 mb-4 flex items-center gap-2 m-0 pb-2">
                            <i class="fa-solid fa-chart-pie text-gray-400"></i> Dung Lượng Bảng
                        </h3>
                        <div class="space-y-4">
                            <div class="bg-blue-50/50 p-4 rounded-lg border border-blue-100">
                                <div class="flex justify-between items-center mb-1">
                                    <strong class="text-blue-700 text-sm">Bảng Thống Kê (Logs)</strong>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600 mb-1">
                                    <span>Số dòng:</span> <span class="font-semibold text-gray-800"><?php echo number_format($stats_count); ?></span>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600">
                                    <span>Dung lượng:</span> <span class="font-semibold text-gray-800"><?php echo esc_html($stats_size); ?></span>
                                </div>
                            </div>
                            <div class="bg-red-50/50 p-4 rounded-lg border border-red-100">
                                <div class="flex justify-between items-center mb-1">
                                    <strong class="text-red-700 text-sm">Bảng IP Bị Chặn</strong>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600 mb-1">
                                    <span>Số IP:</span> <span class="font-semibold text-gray-800"><?php echo number_format($blocked_count); ?></span>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600">
                                    <span>Dung lượng:</span> <span class="font-semibold text-gray-800"><?php echo esc_html($blocked_size); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cleanup Tools -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 border-t-4 border-t-orange-400">
                        <h3 class="text-base font-bold text-gray-800 mb-4 flex items-center gap-2 m-0 pb-2">
                            <i class="fa-solid fa-broom text-orange-500"></i> Dọn Dẹp Dữ Liệu
                        </h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Xóa theo thời gian</label>
                                <div class="flex gap-2 mb-2 items-center">
                                    <input type="date" id="delete-from" class="w-full text-sm border border-gray-300 rounded-lg p-2 text-gray-700 focus:ring-2 focus:ring-blue-500 focus:outline-none h-10">
                                    <span class="text-gray-400"><i class="fa-solid fa-arrow-right"></i></span>
                                    <input type="date" id="delete-to" class="w-full text-sm border border-gray-300 rounded-lg p-2 text-gray-700 focus:ring-2 focus:ring-blue-500 focus:outline-none h-10">
                                </div>
                                <button type="button" id="btn-delete-range" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium py-2 rounded-lg transition border-none cursor-pointer">
                                    Thực hiện xóa
                                </button>
                            </div>
                            <div class="pt-3 border-t border-gray-100">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Xóa dữ liệu cũ hơn</label>
                                <select id="delete-age" class="w-full mb-2 text-sm border border-gray-300 rounded-lg p-2 text-gray-700 focus:ring-2 focus:ring-blue-500 focus:outline-none h-10 bg-white">
                                    <option value="365">1 năm</option>
                                    <option value="730">2 năm</option>
                                    <option value="1095" selected>3 năm</option>
                                    <option value="all">Toàn bộ Logs</option>
                                </select>
                                <button type="button" id="btn-delete-old" class="w-full bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 text-sm font-medium py-2 rounded-lg transition flex justify-center items-center gap-2 cursor-pointer">
                                    <i class="fa-solid fa-fire"></i> Xóa Nhanh
                                </button>
                            </div>
                            <div id="delete-status" class="hidden mt-2 p-2 rounded text-sm text-center"></div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: IP Management -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 h-full flex flex-col">
                        <div class="p-5 border-b border-gray-100 flex justify-between items-center">
                            <h3 class="text-base font-bold text-gray-800 flex items-center gap-2 m-0">
                                <i class="fa-solid fa-shield-halved text-red-500"></i> Quản Lý IP Bị Chặn
                                <span id="blocked-count-badge" class="text-xs font-medium bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">0 IP</span>
                            </h3>
                            <button type="button" id="btn-copy-blocked" class="bg-white hover:bg-gray-50 text-gray-700 text-sm font-medium py-1.5 px-3 rounded border border-gray-200 transition shadow-sm flex items-center gap-2 cursor-pointer" disabled>
                                <i class="fa-regular fa-copy text-blue-500"></i> Copy Danh Sách
                            </button>
                        </div>
                        
                        <!-- Filters -->
                        <div class="p-4 bg-gray-50 border-b border-gray-100 flex flex-wrap gap-4 items-end">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Số phiên tối thiểu</label>
                                <input type="number" id="filter-visit-count" value="0" min="0" class="w-24 text-sm border border-gray-300 rounded p-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 h-8">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Từ ngày</label>
                                <input type="date" id="filter-date-start" value="<?php echo esc_attr($default_start_date); ?>" class="text-sm border border-gray-300 rounded p-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 h-8">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Đến ngày</label>
                                <input type="date" id="filter-date-end" value="<?php echo esc_attr($default_end_date); ?>" class="text-sm border border-gray-300 rounded p-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 h-8">
                            </div>
                            <button type="button" id="btn-filter-blocked" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-1.5 px-4 rounded transition border-none cursor-pointer h-8">
                                Lọc
                            </button>
                        </div>

                        <!-- Table -->
                        <div class="overflow-x-auto flex-1 p-0 min-h-[300px] max-h-[500px]">
                            <table class="w-full text-left text-sm text-gray-600 border-collapse m-0">
                                <thead class="bg-white text-gray-500 text-xs uppercase font-semibold sticky top-0 border-b border-gray-200">
                                    <tr>
                                        <th class="px-6 py-3 font-semibold">IP Address</th>
                                        <th class="px-6 py-3 text-center font-semibold">Số phiên</th>
                                        <th class="px-6 py-3 font-semibold">Thời gian chặn</th>
                                        <th class="px-6 py-3 font-semibold">Lý do</th>
                                        <th class="px-6 py-3 text-right font-semibold">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody id="blocked-ip-list" class="divide-y divide-gray-100">
                                    <tr><td colspan="5" class="text-center py-10">Đang tải dữ liệu...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- Pagination -->
                        <div class="p-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between text-xs text-gray-500 rounded-b-xl">
                            <div id="blocked-table-info">Hiển thị 0-0 của 0 IP</div>
                            <div class="flex gap-1">
                                <button type="button" class="px-2 py-1 rounded border border-gray-200 hover:bg-white disabled:opacity-50 cursor-pointer" disabled>Trước</button>
                                <button type="button" class="px-2 py-1 rounded bg-blue-600 text-white font-medium border-none cursor-pointer">1</button>
                                <button type="button" class="px-2 py-1 rounded border border-gray-200 hover:bg-white cursor-pointer" disabled>2</button>
                                <button type="button" class="px-2 py-1 rounded border border-gray-200 hover:bg-white cursor-pointer" disabled>Tiếp</button>
                            </div>
                        </div>
                    </div>
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

        function escapeHtml(value) {
            return $('<div>').text(value || '').html();
        }

        function copyText(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }

            const fallback = document.createElement('textarea');
            fallback.value = text;
            fallback.setAttribute('readonly', '');
            fallback.style.cssText = 'position:fixed;left:-9999px;top:0;';
            document.body.appendChild(fallback);
            fallback.select();
            const copied = document.execCommand('copy');
            document.body.removeChild(fallback);

            return copied ? Promise.resolve() : Promise.reject(new Error('Trình duyệt từ chối thao tác copy.'));
        }

        // Handle Filter Button
        $('#btn-filter-blocked').on('click', function() {
            const minVisits = $('#filter-visit-count').val();
            const startDate = $('#filter-date-start').val();
            const endDate = $('#filter-date-end').val();
            const btn = $(this);
            
            btn.prop('disabled', true).text('Đang tải...');
            
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
                    btn.prop('disabled', false).text('Lọc');
                    
                    if (response.success) {
                        const ips = response.data;
                        currentIps = ips.map(item => item.ip_address); // Save for copy
                        
                        $('#blocked-count-badge').text(formatNumber(ips.length) + ' IP');
                        
                        if (ips.length > 0) {
                            let html = '';
                            ips.forEach(ip => {
                                const badgeClass = ip.visit_count > 10 ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600';
                                const blockedTime = ip.blocked_time_display || ip.blocked_time || '-';
                                html += `<tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 font-medium text-gray-900">${escapeHtml(ip.ip_address)}</td>
                                    <td class="px-6 py-3 text-center"><span class="${badgeClass} py-0.5 px-2 rounded font-medium text-xs">${ip.visit_count}</span></td>
                                    <td class="px-6 py-3 text-gray-500">${escapeHtml(blockedTime)}</td>
                                    <td class="px-6 py-3 text-gray-500 text-xs">${escapeHtml(ip.reason || '-')}</td>
                                    <td class="px-6 py-3 text-right"><button type="button" class="btn-unblock-ip text-red-500 hover:text-red-700 text-xs font-medium border-none bg-transparent cursor-pointer" data-ip="${escapeHtml(ip.ip_address)}"><i class="fa-solid fa-unlock"></i> Bỏ chặn</button></td>
                                </tr>`;
                            });
                            $('#blocked-ip-list').html(html);
                            $('#blocked-table-info').text('Hiển thị 1-' + ips.length + ' của ' + formatNumber(ips.length) + ' IP');
                            $('#btn-copy-blocked').prop('disabled', false).html('<i class="fa-regular fa-copy text-blue-500"></i> Copy Danh Sách');
                        } else {
                            $('#blocked-ip-list').html('<tr><td colspan="5" style="text-align:center; padding:20px;">Không tìm thấy IP nào thỏa mãn điều kiện.</td></tr>');
                            $('#blocked-table-info').text('Hiển thị 0-0 của 0 IP');
                            $('#btn-copy-blocked').prop('disabled', true).html('<i class="fa-regular fa-copy text-blue-500"></i> Copy Danh Sách');
                        }
                    } else {
                        $('#blocked-ip-list').html('<tr><td colspan="5" style="color:red; text-align:center; padding:20px;">Lỗi: ' + escapeHtml(response.data) + '</td></tr>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Lọc');
                    $('#blocked-ip-list').html('<tr><td colspan="5" style="color:red; text-align:center; padding:20px;">Lỗi kết nối Server.</td></tr>');
                }
            });
        });

        // Auto load on init
        $('#btn-filter-blocked').trigger('click');

        // Handle Copy Button
        $('#btn-copy-blocked').on('click', function() {
            if (currentIps.length === 0) return;
            
            const textToCopy = currentIps.join('\n');
            copyText(textToCopy).then(function() {
                const originalHtml = $('#btn-copy-blocked').html();
                $('#btn-copy-blocked').html('<i class="fa-regular fa-copy text-blue-500"></i> Đã Copy!');
                setTimeout(() => $('#btn-copy-blocked').html(originalHtml), 2000);
            }, function(err) {
                alert('Không thể copy: ' + err);
            });
        });

        $(document).on('click', '.btn-unblock-ip', function() {
            const button = $(this);
            const ip = button.data('ip');

            if (!confirm('Bỏ chặn IP ' + ip + '?')) {
                return;
            }

            button.prop('disabled', true).text('Đang xử lý...');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tkgadm_toggle_block_ip',
                    ip: ip,
                    block_action: 'unblock',
                    nonce: '<?php echo wp_create_nonce("tkgadm_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('#btn-filter-blocked').trigger('click');
                    } else {
                        alert('Không thể bỏ chặn IP: ' + response.data);
                        button.prop('disabled', false).html('<i class="fa-solid fa-unlock"></i> Bỏ chặn');
                    }
                },
                error: function() {
                    alert('Lỗi kết nối Server.');
                    button.prop('disabled', false).html('<i class="fa-solid fa-unlock"></i> Bỏ chặn');
                }
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
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    
    $min_visits = isset($_POST['min_visits']) ? intval($_POST['min_visits']) : 0;
    $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
    $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

    if (!empty($start_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
        $start_date = '';
    }

    if (!empty($end_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        $end_date = '';
    }

    // Build Where Clause. The visit total is calculated from live traffic data,
    // rather than the snapshot saved when the IP was first blocked.
    $where_clauses = array();
    $params = array();

    if (!empty($start_date)) {
        $where_clauses[] = "b.blocked_time >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    
    if (!empty($end_date)) {
        $where_clauses[] = "b.blocked_time <= %s";
        $params[] = $end_date . ' 23:59:59';
    }

    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    $params[] = $min_visits;
    
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $query = $wpdb->prepare(
        "SELECT b.ip_address, b.blocked_time, b.reason,
                COALESCE(SUM(s.visit_count), b.visit_count, 0) AS visit_count
         FROM $table_blocked b
         LEFT JOIN $table_stats s ON s.ip_address = b.ip_address
         $where_sql
         GROUP BY b.id, b.ip_address, b.blocked_time, b.reason, b.visit_count
         HAVING visit_count >= %d
         ORDER BY visit_count DESC, b.blocked_time DESC
         LIMIT 1000", // Giới hạn 1000 IP để tránh treo trình duyệt
        $params
    );
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $results = $wpdb->get_results($query);

    foreach ($results as $row) {
        $row->visit_count = intval($row->visit_count);
        $row->blocked_time_display = $row->blocked_time ? wp_date('d/m/Y H:i:s', strtotime($row->blocked_time)) : '';
    }
    
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
