<?php
/**
 * TEST SCRIPT: Verify Organic Traffic Logic
 * 
 * Cách chạy:
 * 1. Truy cập: https://bookingreal.com/wp-content/plugins/gads-toolkit/test-organic-logic.php
 * 2. Script sẽ show kết quả queries và giải thích
 */

// Load WordPress
require_once(__DIR__ . '/../../../wp-load.php');

// Kiểm tra quyền admin
if (!current_user_can('manage_options')) {
    die('❌ Bạn cần đăng nhập với quyền admin để chạy test này.');
}

global $wpdb;
$table = $wpdb->prefix . 'gads_toolkit_stats';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test Organic Traffic Logic</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #0073aa; padding-bottom: 10px; }
        h2 { color: #0073aa; margin-top: 30px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 14px; }
        th, td { padding: 12px; text-align: left; border: 1px solid #ddd; }
        th { background: #0073aa; color: white; font-weight: 600; }
        tr:nth-child(even) { background: #f9f9f9; }
        .badge { padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; }
        .badge-ads { background: #ff6b6b; color: white; }
        .badge-organic { background: #51cf66; color: white; }
        .badge-bot { background: #868e96; color: white; }
        .badge-excluded { background: #ffd43b; color: #333; }
        .sql-box { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto; margin: 15px 0; }
        .sql-box code { font-family: 'Courier New', monospace; font-size: 13px; }
        .info-box { background: #e7f5ff; border-left: 4px solid #339af0; padding: 15px; margin: 15px 0; }
        .warning-box { background: #fff3bf; border-left: 4px solid #fab005; padding: 15px; margin: 15px 0; }
        .success-box { background: #d3f9d8; border-left: 4px solid #37b24d; padding: 15px; margin: 15px 0; }
        .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .summary-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; }
        .summary-card h3 { margin: 0 0 10px 0; font-size: 14px; opacity: 0.9; }
        .summary-card .number { font-size: 36px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Test Organic Traffic Logic</h1>
        <p><strong>Plugin Version:</strong> <?php echo GADS_TOOLKIT_VERSION; ?> | <strong>Time:</strong> <?php echo current_time('Y-m-d H:i:s'); ?></p>

        <?php
        // ==================== 1. HIỂN THỊ TẤT CẢ DATA ====================
        echo '<h2>📊 1. Toàn bộ dữ liệu trong Database</h2>';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $all_data = $wpdb->get_results("SELECT * FROM $table ORDER BY visit_time DESC LIMIT 50");
        
        if (empty($all_data)) {
            echo '<div class="warning-box">⚠️ Chưa có dữ liệu nào trong bảng stats.</div>';
        } else {
            echo '<table>';
            echo '<tr><th>#</th><th>IP</th><th>Visit Time</th><th>URL</th><th>gclid</th><th>Time on Page (s)</th><th>Phân loại</th></tr>';
            
            foreach ($all_data as $row) {
                $classification = '';
                $has_gclid = !empty($row->gclid);
                $has_time = $row->time_on_page > 0;
                
                if ($has_gclid) {
                    $classification = '<span class="badge badge-ads">ADS</span>';
                } elseif ($has_time) {
                    $classification = '<span class="badge badge-organic">ORGANIC?</span>';
                } else {
                    $classification = '<span class="badge badge-bot">BOT (time=0)</span>';
                }
                
                $gclid_display = $has_gclid ? $row->gclid : '<em>null</em>';
                $parsed = wp_parse_url($row->url_visited);
                $path = isset($parsed['path']) ? $parsed['path'] : '/';
                
                echo "<tr>";
                echo "<td>#{$row->id}</td>";
                echo "<td><code>{$row->ip_address}</code></td>";
                echo "<td>{$row->visit_time}</td>";
                echo "<td>{$path}</td>";
                echo "<td>{$gclid_display}</td>";
                echo "<td>{$row->time_on_page}</td>";
                echo "<td>{$classification}</td>";
                echo "</tr>";
            }
            
            echo '</table>';
        }
        
        // ==================== 2. IPs CÓ GCLID ====================
        echo '<h2>🎯 2. Danh sách IP đã từng có gclid (ADS Traffic)</h2>';
        echo '<div class="info-box">Đây là các IP sẽ bị <strong>LOẠI KHỎI Organic</strong> vì đã có ít nhất 1 phiên với gclid.</div>';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ips_with_gclid = $wpdb->get_results(
            "SELECT DISTINCT ip_address, 
                    COUNT(*) as total_sessions,
                    SUM(CASE WHEN gclid IS NOT NULL AND gclid != '' THEN 1 ELSE 0 END) as ads_sessions,
                    SUM(CASE WHEN gclid IS NULL OR gclid = '' THEN 1 ELSE 0 END) as non_ads_sessions
             FROM $table 
             WHERE ip_address IN (
                 SELECT DISTINCT ip_address 
                 FROM $table 
                 WHERE gclid IS NOT NULL AND gclid != ''
             )
             GROUP BY ip_address"
        );
        
        if (empty($ips_with_gclid)) {
            echo '<div class="success-box">✅ Không có IP nào có gclid.</div>';
        } else {
            echo '<table>';
            echo '<tr><th>IP Address</th><th>Tổng phiên</th><th>Phiên có gclid</th><th>Phiên không gclid</th></tr>';
            
            foreach ($ips_with_gclid as $row) {
                echo "<tr>";
                echo "<td><code>{$row->ip_address}</code></td>";
                echo "<td>{$row->total_sessions}</td>";
                echo "<td><span class=\"badge badge-ads\">{$row->ads_sessions}</span></td>";
                echo "<td>{$row->non_ads_sessions}</td>";
                echo "</tr>";
            }
            
            echo '</table>';
        }
        
        // ==================== 3. ORGANIC TRAFFIC CANDIDATES ====================
        echo '<h2>🌱 3. IP ứng cử viên cho Organic Traffic</h2>';
        echo '<div class="info-box">IP phải thỏa: <strong>KHÔNG BAO GIỜ</strong> có gclid VÀ có <strong>time_on_page > 0</strong>.</div>';
        
        // Exact query từ includes/module-analytics.php
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $organic_candidates = $wpdb->get_results(
            "SELECT DISTINCT ip_address,
                    COUNT(*) as total_sessions,
                    SUM(time_on_page) as total_time,
                    MAX(time_on_page) as max_time
             FROM $table
             WHERE time_on_page IS NOT NULL
               AND time_on_page > 0
               AND ip_address NOT IN (
                   SELECT DISTINCT ip_address 
                   FROM $table 
                   WHERE gclid IS NOT NULL AND gclid != ''
               )
             GROUP BY ip_address"
        );
        
        if (empty($organic_candidates)) {
            echo '<div class="warning-box">❌ Không có IP nào đủ điều kiện Organic Traffic.<br><br><strong>Lý do:</strong> Tất cả IP hoặc là đã có gclid, hoặc là time_on_page = 0 (bot).</div>';
        } else {
            echo '<div class="success-box">✅ Tìm thấy ' . count($organic_candidates) . ' IP đủ điều kiện Organic!</div>';
            echo '<table>';
            echo '<tr><th>IP Address</th><th>Tổng phiên</th><th>Tổng thời gian (s)</th><th>Max time (s)</th></tr>';
            
            foreach ($organic_candidates as $row) {
                echo "<tr>";
                echo "<td><code>{$row->ip_address}</code></td>";
                echo "<td>{$row->total_sessions}</td>";
                echo "<td>{$row->total_time}</td>";
                echo "<td>{$row->max_time}</td>";
                echo "</tr>";
            }
            
            echo '</table>';
        }
        
        // ==================== 4. SUMMARY ====================
        echo '<h2>📈 4. Tổng kết Traffic</h2>';
        
        // Count unique IPs cho Ads
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ads_count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT ip_address)
             FROM $table
             WHERE ip_address IN (
                 SELECT DISTINCT ip_address 
                 FROM $table 
                 WHERE gclid IS NOT NULL AND gclid != ''
             )"
        );
        
        // Count unique IPs cho Organic
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $organic_count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT ip_address)
             FROM $table
             WHERE time_on_page IS NOT NULL
               AND time_on_page > 0
               AND ip_address NOT IN (
                   SELECT DISTINCT ip_address 
                   FROM $table 
                   WHERE gclid IS NOT NULL AND gclid != ''
               )"
        );
        
        // Count total unique IPs
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_ips = $wpdb->get_var("SELECT COUNT(DISTINCT ip_address) FROM $table");
        
        echo '<div class="summary">';
        echo '<div class="summary-card"><h3>Total Unique IPs</h3><div class="number">' . $total_ips . '</div></div>';
        echo '<div class="summary-card"><h3>🎯 Ads Traffic IPs</h3><div class="number">' . $ads_count . '</div></div>';
        echo '<div class="summary-card"><h3>🌱 Organic Traffic IPs</h3><div class="number">' . $organic_count . '</div></div>';
        echo '</div>';
        
        // ==================== 5. SQL QUERIES ====================
        echo '<h2>💻 5. SQL Queries được sử dụng</h2>';
        
        echo '<h3>Query cho Ads Traffic:</h3>';
        echo '<div class="sql-box"><code>';
        echo "SELECT COUNT(DISTINCT ip_address) as total\n";
        echo "FROM {$table}\n";
        echo "WHERE ip_address IN (\n";
        echo "    SELECT DISTINCT ip_address \n";
        echo "    FROM {$table} \n";
        echo "    WHERE gclid IS NOT NULL AND gclid != ''\n";
        echo ")";
        echo '</code></div>';
        
        echo '<h3>Query cho Organic Traffic:</h3>';
        echo '<div class="sql-box"><code>';
        echo "SELECT COUNT(DISTINCT ip_address) as total\n";
        echo "FROM {$table}\n";
        echo "WHERE time_on_page IS NOT NULL\n";
        echo "  AND time_on_page > 0\n";
        echo "  AND ip_address NOT IN (\n";
        echo "      SELECT DISTINCT ip_address \n";
        echo "      FROM {$table} \n";
        echo "      WHERE gclid IS NOT NULL AND gclid != ''\n";
        echo "  )";
        echo '</code></div>';
        
        echo '<div class="info-box"><strong>💡 Lưu ý:</strong> Đây chính xác là logic trong <code>includes/module-analytics.php</code></div>';
        
        ?>
        
        <hr style="margin: 40px 0;">
        <p style="text-align: center; color: #666;">
            <a href="<?php echo admin_url('admin.php?page=tkgad-analytics'); ?>" style="color: #0073aa;">← Quay lại Traffic Analytics</a> | 
            <a href="<?php echo admin_url('admin.php?page=tkgad-moi'); ?>" style="color: #0073aa;">Dashboard</a>
        </p>
    </div>
</body>
</html>
