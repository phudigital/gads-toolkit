<?php
/**
 * Notification Functions
 * Email & Telegram alerts, Cron jobs
 */

if (!defined('ABSPATH')) exit;

/**
 * Hook vào phpmailer_init để cấu hình SMTP riêng cho plugin
 */
add_action('phpmailer_init', 'tkgadm_configure_smtp', 10, 1);
function tkgadm_configure_smtp($phpmailer) {
    // Chỉ áp dụng nếu user bật "use custom SMTP"
    if (get_option('tkgadm_use_custom_smtp', '0') !== '1') {
        return; // Dùng cấu hình WordPress mặc định
    }
    
    $smtp_host = get_option('tkgadm_smtp_host', '');
    $smtp_port = get_option('tkgadm_smtp_port', 587);
    $smtp_secure = get_option('tkgadm_smtp_secure', 'tls');
    $smtp_auth = get_option('tkgadm_smtp_auth', '1');
    $smtp_username = get_option('tkgadm_smtp_username', '');
    $smtp_password = get_option('tkgadm_smtp_password', '');
    $smtp_from_email = get_option('tkgadm_smtp_from_email', '');
    $smtp_from_name = get_option('tkgadm_smtp_from_name', 'GAds Toolkit');
    
    // Chỉ cấu hình nếu có đủ thông tin
    if (empty($smtp_host) || empty($smtp_username)) {
        return;
    }
    
    $phpmailer->isSMTP();
    $phpmailer->Host = $smtp_host;
    $phpmailer->Port = $smtp_port;
    $phpmailer->SMTPSecure = $smtp_secure;
    $phpmailer->SMTPAuth = ($smtp_auth === '1');
    $phpmailer->Username = $smtp_username;
    $phpmailer->Password = $smtp_password;
    
    if (!empty($smtp_from_email)) {
        $phpmailer->From = $smtp_from_email;
        $phpmailer->FromName = $smtp_from_name;
    }
}

/**
 * Gửi tin nhắn Telegram
 */
function tkgadm_send_telegram_message($message) {
    $token = get_option('tkgadm_telegram_bot_token', '');
    $chat_id = get_option('tkgadm_telegram_chat_id', '');
    
    if (empty($token) || empty($chat_id)) {
        return false;
    }
    
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    
    $response = wp_remote_post($url, array(
        'body' => array(
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown'
        )
    ));
    
    return !is_wp_error($response);
}

/**
 * Gửi email thông báo
 */
function tkgadm_send_email_notification($subject, $message) {
    $emails = get_option('tkgadm_notification_emails', '');
    if (empty($emails)) {
        return false;
    }
    
    // Hỗ trợ cả dấu phẩy và xuống dòng
    $email_list = array_filter(array_map('trim', preg_split('/[,\n\r]+/', $emails)));
    
    return wp_mail($email_list, $subject, $message);
}

/**
 * Cron job: Kiểm tra IP nghi ngờ mỗi giờ
 */
add_action('tkgadm_hourly_alert', 'tkgadm_check_suspicious_ips');
function tkgadm_check_suspicious_ips() {
    if (get_option('tkgadm_enable_hourly_alerts', '1') !== '1') {
        return;
    }
    
    global $wpdb;
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    $threshold = get_option('tkgadm_alert_threshold', 5);
    
    // Lấy IP có nhiều clicks Ads nhưng chưa bị chặn
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $suspicious_ips = $wpdb->get_results($wpdb->prepare("
        SELECT 
            s.ip_address,
            COUNT(DISTINCT s.gclid) as ad_clicks,
            SUM(s.visit_count) as total_visits,
            MAX(s.visit_time) as last_visit
        FROM $table_stats s
        LEFT JOIN $table_blocked b ON s.ip_address = b.ip_address
        WHERE s.gclid IS NOT NULL 
        AND s.gclid != ''
        AND b.ip_address IS NULL
        AND s.visit_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        GROUP BY s.ip_address
        HAVING ad_clicks >= %d
        ORDER BY ad_clicks DESC
    ", $threshold));
    
    if (empty($suspicious_ips)) {
        return; // Không có IP nghi ngờ
    }
    
    // Tạo message
    $count = count($suspicious_ips);
    $message_email = "🚨 CẢNH BÁO IP NGI NGỜ\n\n";
    $message_email .= "Phát hiện {$count} IP có hành vi bất thường trong 1 giờ qua:\n\n";
    
    $message_telegram = "🚨 *CẢNH BÁO IP NGI NGỜ*\n\n";
    $message_telegram .= "Phát hiện *{$count} IP* có hành vi bất thường trong 1 giờ qua:\n\n";
    
    foreach ($suspicious_ips as $ip_data) {
        $message_email .= sprintf(
            "IP: %s\n- Ads Clicks: %d\n- Tổng visits: %d\n- Lần cuối: %s\n\n",
            $ip_data->ip_address,
            $ip_data->ad_clicks,
            $ip_data->total_visits,
            $ip_data->last_visit
        );
        
        $message_telegram .= sprintf(
            "🔴 `%s`\n├ Ads Clicks: *%d*\n├ Tổng visits: %d\n└ Lần cuối: %s\n\n",
            $ip_data->ip_address,
            $ip_data->ad_clicks,
            $ip_data->total_visits,
            $ip_data->last_visit
        );
    }
    
    $message_email .= "Vui lòng kiểm tra và chặn IP nếu cần thiết.\n";
    $message_email .= "Dashboard: " . admin_url('admin.php?page=tkgad-moi');
    
    $message_telegram .= "👉 [Xem Dashboard](" . admin_url('admin.php?page=tkgad-moi') . ")";
    
    // Gửi thông báo theo platform đã chọn
    if (get_option('tkgadm_alert_platform_email', '1') === '1') {
        tkgadm_send_email_notification('🚨 Cảnh báo IP nghi ngờ - GAds Toolkit', $message_email);
    }
    if (get_option('tkgadm_alert_platform_telegram', '1') === '1') {
        tkgadm_send_telegram_message($message_telegram);
    }
}

/**
 * Cron job: Báo cáo hàng ngày
 */
add_action('tkgadm_daily_report', 'tkgadm_send_daily_report');
function tkgadm_send_daily_report() {
    if (get_option('tkgadm_enable_daily_reports', '1') !== '1') {
        return;
    }
    
    global $wpdb;
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    
    // Thống kê hôm qua (Organic chỉ đếm records có time_on_page hợp lệ để lọc bot)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $stats = $wpdb->get_row("
        SELECT 
            COUNT(DISTINCT CASE WHEN gclid IS NOT NULL AND gclid != '' THEN ip_address END) as ads_ips,
            COUNT(DISTINCT CASE WHEN (gclid IS NULL OR gclid = '') AND time_on_page IS NOT NULL AND time_on_page > 0 THEN ip_address END) as organic_ips,
            SUM(CASE WHEN gclid IS NOT NULL AND gclid != '' THEN visit_count ELSE 0 END) as ads_visits,
            SUM(CASE WHEN (gclid IS NULL OR gclid = '') AND time_on_page IS NOT NULL AND time_on_page > 0 THEN visit_count ELSE 0 END) as organic_visits,
            COUNT(DISTINCT CASE WHEN gclid IS NOT NULL AND gclid != '' THEN gclid END) as unique_clicks
        FROM $table_stats
        WHERE DATE(visit_time) = CURDATE() - INTERVAL 1 DAY
    ");
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $blocked_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_blocked");
    
    $total_visits = $stats->ads_visits + $stats->organic_visits;
    $ads_ratio = $total_visits > 0 ? round(($stats->ads_visits / $total_visits) * 100, 1) : 0;
    
    // Email message
    $message_email = "📊 BÁO CÁO TRAFFIC HÀNG NGÀY\n";
    $message_email .= "Ngày: " . wp_date('d/m/Y', strtotime('-1 day')) . "\n\n";
    $message_email .= "=== TỔNG QUAN ===\n";
    $message_email .= sprintf("Tổng lượt truy cập: %d\n", $total_visits);
    $message_email .= sprintf("- Google Ads: %d (%s%%)\n", $stats->ads_visits, $ads_ratio);
    $message_email .= sprintf("- Organic: %d\n\n", $stats->organic_visits);
    $message_email .= "=== CHI TIẾT ===\n";
    $message_email .= sprintf("IP từ Ads: %d\n", $stats->ads_ips);
    $message_email .= sprintf("IP Organic: %d\n", $stats->organic_ips);
    $message_email .= sprintf("Unique Ads Clicks: %d\n", $stats->unique_clicks);
    $message_email .= sprintf("IP đã chặn: %d\n\n", $blocked_count);
    $message_email .= "Dashboard: " . admin_url('admin.php?page=tkgad-analytics');
    
    // Telegram message
    $message_telegram = "📊 *BÁO CÁO TRAFFIC HÀNG NGÀY*\n";
    $message_telegram .= "_" . wp_date('d/m/Y', strtotime('-1 day')) . "_\n\n";
    $message_telegram .= "📈 *Tổng lượt truy cập:* " . number_format($total_visits) . "\n";
    $message_telegram .= sprintf("├ 🎯 Google Ads: *%s* (%s%%)\n", number_format($stats->ads_visits), $ads_ratio);
    $message_telegram .= sprintf("└ 🌱 Organic: %s\n\n", number_format($stats->organic_visits));
    $message_telegram .= "📊 *Chi tiết:*\n";
    $message_telegram .= sprintf("├ IP từ Ads: %d\n", $stats->ads_ips);
    $message_telegram .= sprintf("├ IP Organic: %d\n", $stats->organic_ips);
    $message_telegram .= sprintf("├ Unique Clicks: %d\n", $stats->unique_clicks);
    $message_telegram .= sprintf("└ 🚫 IP đã chặn: %d\n\n", $blocked_count);
    $message_telegram .= "👉 [Xem Dashboard](" . admin_url('admin.php?page=tkgad-analytics') . ")";
    
    // Gửi thông báo theo platform đã chọn
    if (get_option('tkgadm_alert_platform_email', '1') === '1') {
        tkgadm_send_email_notification('📊 Báo cáo traffic hàng ngày - GAds Toolkit', $message_email);
    }
    if (get_option('tkgadm_alert_platform_telegram', '1') === '1') {
        tkgadm_send_telegram_message($message_telegram);
    }
}


/**
 * Kích hoạt cron jobs khi activate plugin hoặc khi settings thay đổi
 */
function tkgadm_schedule_notifications() {
    // Clear existing schedules
    wp_clear_scheduled_hook('tkgadm_hourly_alert');
    wp_clear_scheduled_hook('tkgadm_daily_report');
    
    // Schedule alert check theo frequency setting
    $frequency = get_option('tkgadm_alert_frequency', 'hourly');
    if (!wp_next_scheduled('tkgadm_hourly_alert')) {
        wp_schedule_event(time(), $frequency, 'tkgadm_hourly_alert');
    }
    
    // Schedule daily report theo time setting
    if (!wp_next_scheduled('tkgadm_daily_report')) {
        $report_time = get_option('tkgadm_daily_report_time', '08:00');
        list($hour, $minute) = explode(':', $report_time);
        
        // Tính timestamp cho lần chạy đầu tiên
        $now = current_time('timestamp');
        $scheduled_time = strtotime("today {$hour}:{$minute}:00");
        
        // Nếu giờ hôm nay đã qua, schedule cho ngày mai
        if ($scheduled_time < $now) {
            $scheduled_time = strtotime("tomorrow {$hour}:{$minute}:00");
        }
        
        wp_schedule_event($scheduled_time, 'daily', 'tkgadm_daily_report');
    }
}

/**
 * Hủy cron jobs khi deactivate plugin
 */
function tkgadm_unschedule_notifications() {
    wp_clear_scheduled_hook('tkgadm_hourly_alert');
    wp_clear_scheduled_hook('tkgadm_daily_report');
}
