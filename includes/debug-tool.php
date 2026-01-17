<?php
/**
 * Debug Tool - Kiểm tra dữ liệu traffic
 * Tạm thời để debug, xóa sau khi fix xong
 */

if (!defined('ABSPATH')) exit;

add_action('wp_ajax_tkgadm_debug_traffic', 'tkgadm_debug_traffic');
add_action('wp_ajax_nopriv_tkgadm_debug_traffic', 'tkgadm_debug_traffic'); // Allow non-admin
function tkgadm_debug_traffic() {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    
    header('Content-Type: text/html; charset=utf-8');
    
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Debug Traffic</title></head><body>";
    echo "<h2>Debug Traffic Data</h2>";
    
    // Total records
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    echo "<p><strong>Total records:</strong> " . intval($total) . "</p>";
    
    // Ads vs Organic count
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $stats = $wpdb->get_row("SELECT 
        SUM(CASE WHEN gclid IS NOT NULL AND gclid != '' THEN 1 ELSE 0 END) as ads_count,
        SUM(CASE WHEN gclid IS NULL OR gclid = '' THEN 1 ELSE 0 END) as organic_count,
        SUM(CASE WHEN gclid IS NOT NULL AND gclid != '' THEN visit_count ELSE 0 END) as ads_visits,
        SUM(CASE WHEN gclid IS NULL OR gclid = '' THEN visit_count ELSE 0 END) as organic_visits
    FROM $table");
    
    if ($stats) {
        echo "<p><strong>Ads records:</strong> {$stats->ads_count} (total visits: {$stats->ads_visits})</p>";
        echo "<p><strong>Organic records:</strong> {$stats->organic_count} (total visits: {$stats->organic_visits})</p>";
    } else {
        echo "<p style='color:red;'>Error getting stats</p>";
    }
    
    // Latest 10 records
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $latest = $wpdb->get_results("SELECT id, ip_address, visit_time, gclid, visit_count FROM $table ORDER BY visit_time DESC LIMIT 10");
    
    echo "<h3>Latest 10 records:</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'><th>ID</th><th>IP</th><th>Time</th><th>GCLID</th><th>Visit Count</th><th>Type</th></tr>";
    
    if ($latest) {
        foreach ($latest as $row) {
            $type = (!empty($row->gclid)) ? '<strong style="color:orange;">ADS</strong>' : '<strong style="color:green;">ORGANIC</strong>';
            $gclid_display = $row->gclid ? esc_html($row->gclid) : '-';
            echo "<tr><td>{$row->id}</td><td>{$row->ip_address}</td><td>{$row->visit_time}</td><td>{$gclid_display}</td><td>{$row->visit_count}</td><td>{$type}</td></tr>";
        }
    } else {
        echo "<tr><td colspan='6'>No records found</td></tr>";
    }
    
    echo "</table>";
    echo "</body></html>";
    
    wp_die();
}
