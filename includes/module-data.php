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
    
    ?>
    <div class="wrap">
        <div class="tkgadm-wrap">
            <div class="tkgadm-header">
                <h1>🗄️ Quản Lý Dữ Liệu</h1>
                <p style="color: #666; margin-top: 10px;">Kiểm tra dung lượng và dọn dẹp dữ liệu cũ để tối ưu database.</p>
            </div>

            <!-- Database Status -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <!-- Stats Table Card -->
                <div class="tkgadm-card" style="background: white; padding: 20px; border-radius: 10px; border: 1px solid #ddd; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                    <h3 style="margin-top: 0; color: #0073aa;">📊 Bảng Thống Kê (Logs)</h3>
                    <p style="font-size: 13px; color: #666;">Chứa lịch sử truy cập, traffic logs.</p>
                    
                    <div style="display: flex; justify-content: space-between; margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                        <span>Số dòng (Rows):</span>
                        <strong><?php echo number_format($stats_count); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 10px; padding-bottom: 10px;">
                        <span>Kích thước:</span>
                        <strong><?php echo esc_html($stats_size); ?></strong>
                    </div>
                </div>

                <!-- Blocked Table Card -->
                <div class="tkgadm-card" style="background: white; padding: 20px; border-radius: 10px; border: 1px solid #ddd; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                    <h3 style="margin-top: 0; color: #d63638;">🚫 Bảng IP Bị Chặn</h3>
                    <p style="font-size: 13px; color: #666;">Chứa danh sách IP đã bị block.</p>
                    
                    <div style="display: flex; justify-content: space-between; margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                        <span>Số IP (Rows):</span>
                        <strong><?php echo number_format($blocked_count); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 10px; padding-bottom: 10px;">
                        <span>Kích thước:</span>
                        <strong><?php echo esc_html($blocked_size); ?></strong>
                    </div>
                </div>
            </div>

            <!-- Cleanup Tools -->
            <div class="tkgadm-card" style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #ddd;">
                <h2 style="margin-top: 0;">🧹 Công cụ Dọn Dẹp</h2>
                <p>Xóa bớt dữ liệu cũ để giải phóng dung lượng.</p>
                
                <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
                
                <div style="display: flex; align-items: flex-end; gap: 20px; flex-wrap: wrap;">
                    
                    <!-- Option 1: Delete by Date Range -->
                    <div style="flex: 1; min-width: 300px;">
                        <h4>Xóa theo khoảng thời gian</h4>
                        <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                            <input type="date" id="delete-from" class="tkgadm-input" title="Từ ngày">
                            <span>→</span>
                            <input type="date" id="delete-to" class="tkgadm-input" title="Đến ngày">
                        </div>
                        <button id="btn-delete-range" class="button button-secondary">🗑️ Xóa logs trong khoảng này</button>
                    </div>

                    <!-- Option 2: Delete older than X days -->
                    <div style="flex: 1; min-width: 300px;">
                        <h4>Xóa dữ liệu cũ hơn</h4>
                        <div style="margin-bottom: 10px;">
                            <select id="delete-age" class="tkgadm-input" style="width: 200px;">
                                <option value="90">90 ngày (3 tháng)</option>
                                <option value="180">180 ngày (6 tháng)</option>
                                <option value="365">365 ngày (1 năm)</option>
                                <option value="all">⚠️ TOÀN BỘ (Reset)</option>
                            </select>
                        </div>
                        <button id="btn-delete-old" class="button button-link-delete" style="color: #d63638; text-decoration: none; border: 1px solid #d63638; padding: 5px 10px; border-radius: 4px;">🔥 Thực hiện xóa</button>
                    </div>

                </div>

                <!-- Status Message -->
                <div id="delete-status" style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #666; display: none;"></div>
            </div>
            
            <!-- Auto Cleanup Setting (Future Feature Placeholder) -->
            <div style="margin-top: 30px; opacity: 0.6;">
                <h3>⚙️ Tự động dọn dẹp (Sắp ra mắt)</h3>
                <label>
                    <input type="checkbox" disabled> Tự động xóa logs cũ hơn 90 ngày (Cron Job)
                </label>
            </div>

        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        
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
