<?php
/**
 * Admin Maintenance - Quản lý dữ liệu
 * Submenu: Xóa dữ liệu thống kê, quản lý dung lượng
 */

if (!defined('ABSPATH')) exit;

function tkgadm_render_maintenance_page() {
    global $wpdb;
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    
    // Get database size
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $stats_size = $wpdb->get_var("SELECT 
        ROUND(((data_length + index_length) / 1024 / 1024), 2) 
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE() 
        AND table_name = '$table_stats'");
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $blocked_size = $wpdb->get_var("SELECT 
        ROUND(((data_length + index_length) / 1024 / 1024), 2) 
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE() 
        AND table_name = '$table_blocked'");
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $total_records = $wpdb->get_var("SELECT COUNT(*) FROM $table_stats");
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $blocked_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_blocked");
    
    // Get oldest and newest record
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $oldest = $wpdb->get_var("SELECT MIN(visit_time) FROM $table_stats");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $newest = $wpdb->get_var("SELECT MAX(visit_time) FROM $table_stats");
    
    ?>
    <div class="wrap">
        <div class="tkgadm-wrap">
            <div class="tkgadm-header">
                <h1>🗄️ Quản Lý Dữ Liệu</h1>
                <p style="color: #666; margin-top: 10px;">Quản lý dung lượng database và dọn dẹp dữ liệu cũ</p>
            </div>

            <!-- Database Info Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-left: 4px solid #667eea;">
                    <div style="color: #666; font-size: 13px;">📊 Tổng số bản ghi</div>
                    <div style="font-size: 28px; font-weight: bold; color: #667eea; margin-top: 8px;"><?php echo number_format($total_records); ?></div>
                </div>
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-left: 4px solid #28a745;">
                    <div style="color: #666; font-size: 13px;">💾 Dung lượng Stats</div>
                    <div style="font-size: 28px; font-weight: bold; color: #28a745; margin-top: 8px;"><?php echo $stats_size; ?> MB</div>
                </div>
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-left: 4px solid #dc3545;">
                    <div style="color: #666; font-size: 13px;">🚫 IP Blocked</div>
                    <div style="font-size: 28px; font-weight: bold; color: #dc3545; margin-top: 8px;"><?php echo number_format($blocked_count); ?></div>
                </div>
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-left: 4px solid #ffc107;">
                    <div style="color: #666; font-size: 13px;">� Dung lượng Blocked</div>
                    <div style="font-size: 28px; font-weight: bold; color: #ffc107; margin-top: 8px;"><?php echo $blocked_size; ?> MB</div>
                </div>
            </div>

            <!-- Data Range Info -->
            <?php if ($oldest && $newest): ?>
            <div style="background: #f8f9ff; padding: 15px; border-radius: 8px; margin-bottom: 30px; border-left: 4px solid #667eea;">
                <strong>📅 Khoảng thời gian dữ liệu:</strong> 
                Từ <code><?php echo esc_html($oldest); ?></code> 
                đến <code><?php echo esc_html($newest); ?></code>
            </div>
            <?php endif; ?>

            <!-- Delete Options -->
            <div class="tkgadm-table-container">
                <h2 style="margin-bottom: 20px;">�️ Xóa Dữ Liệu Thống Kê</h2>
                <p style="color: #dc3545; margin-bottom: 20px;"><strong>⚠️ Cảnh báo:</strong> Hành động này không thể hoàn tác. Chỉ xóa dữ liệu thống kê, không xóa danh sách IP bị chặn.</p>

                <!-- Delete by Date Range -->
                <div style="background: white; padding: 25px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #ddd;">
                    <h3 style="margin-top: 0;">� Xóa theo khoảng ngày</h3>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <label>Từ ngày:</label>
                        <input type="date" id="delete-from" class="tkgadm-input" style="padding: 8px; border-radius: 5px; border: 1px solid #ddd;">
                        <label>Đến ngày:</label>
                        <input type="date" id="delete-to" class="tkgadm-input" style="padding: 8px; border-radius: 5px; border: 1px solid #ddd;">
                        <button id="delete-by-range" class="tkgadm-btn" style="background: #dc3545; color: white; padding: 8px 20px;">🗑️ Xóa</button>
                    </div>
                </div>

                <!-- Delete by Age -->
                <div style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #ddd;">
                    <h3 style="margin-top: 0;">⏰ Xóa dữ liệu cũ</h3>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button class="delete-older-than tkgadm-btn tkgadm-btn-secondary" data-days="180">Xóa cũ hơn 180 ngày</button>
                        <button class="delete-older-than tkgadm-btn tkgadm-btn-secondary" data-days="365">Xóa cũ hơn 1 năm</button>
                        <button class="delete-older-than tkgadm-btn tkgadm-btn-secondary" data-days="730">Xóa cũ hơn 2 năm</button>
                    </div>
                </div>
            </div>

            <!-- Activity Log -->
            <div id="maintenance-log" style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; display: none;">
                <h3>📋 Nhật ký hoạt động</h3>
                <div id="log-content" style="font-family: monospace; font-size: 13px; color: #333;"></div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        function addLog(message, type = 'info') {
            const colors = {
                'info': '#007bff',
                'success': '#28a745',
                'error': '#dc3545',
                'warning': '#ffc107'
            };
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = `<div style="padding: 8px; margin: 5px 0; background: white; border-left: 4px solid ${colors[type]}; border-radius: 4px;">[${timestamp}] ${message}</div>`;
            $('#log-content').prepend(logEntry);
            $('#maintenance-log').show();
        }

        function confirmDelete(message, callback) {
            if (confirm('⚠️ XÁC NHẬN XÓA DỮ LIỆU\n\n' + message + '\n\nBạn có chắc chắn muốn tiếp tục?')) {
                callback();
            }
        }

        // Delete by date range
        $('#delete-by-range').on('click', function() {
            const from = $('#delete-from').val();
            const to = $('#delete-to').val();

            if (!from || !to) {
                alert('Vui lòng chọn khoảng ngày');
                return;
            }

            confirmDelete(`Xóa tất cả dữ liệu từ ${from} đến ${to}`, function() {
                $.ajax({
                    url: tkgadm_vars.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'tkgadm_delete_data',
                        nonce: tkgadm_vars.nonce,
                        from: from,
                        to: to
                    },
                    success: function(response) {
                        if (response.success) {
                            addLog(response.data.message, 'success');
                            alert('✅ ' + response.data.message);
                            location.reload();
                        } else {
                            addLog('Lỗi: ' + response.data, 'error');
                            alert('❌ Lỗi: ' + response.data);
                        }
                    },
                    error: function() {
                        addLog('Lỗi kết nối server', 'error');
                        alert('❌ Lỗi kết nối');
                    }
                });
            });
        });

        // Delete older than X days
        $('.delete-older-than').on('click', function() {
            const days = $(this).data('days');
            const label = $(this).text();

            confirmDelete(`${label}\n\nSẽ xóa tất cả dữ liệu cũ hơn ${days} ngày`, function() {
                $.ajax({
                    url: tkgadm_vars.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'tkgadm_delete_data',
                        nonce: tkgadm_vars.nonce,
                        older_than: days
                    },
                    success: function(response) {
                        if (response.success) {
                            addLog(response.data.message, 'success');
                            alert('✅ ' + response.data.message);
                            location.reload();
                        } else {
                            addLog('Lỗi: ' + response.data, 'error');
                            alert('❌ Lỗi: ' + response.data);
                        }
                    },
                    error: function() {
                        addLog('Lỗi kết nối server', 'error');
                        alert('❌ Lỗi kết nối');
                    }
                });
            });
        });
    });
    </script>
    <?php
}
