<?php
/**
 * Module: Notifications
 * Manages Email & Telegram alerts, Cron jobs, and Admin UI for notifications.
 */

if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * 1. NOTIFICATION HELPERS
 * ============================================================================
 */

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
 * ============================================================================
 * 2. CRON JOBS
 * ============================================================================
 */

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


/**
 * ============================================================================
 * 3. ADMIN UI (SETTINGS PAGE)
 * ============================================================================
 */
function tkgadm_render_notifications_page() {
    // Lưu settings
    if (isset($_POST['tkgadm_save_notifications']) && check_admin_referer('tkgadm_notifications_nonce')) {
        update_option('tkgadm_notification_emails', sanitize_text_field(wp_unslash($_POST['notification_emails'])));
        update_option('tkgadm_telegram_bot_token', sanitize_text_field(wp_unslash($_POST['telegram_bot_token'])));
        update_option('tkgadm_telegram_chat_id', sanitize_text_field(wp_unslash($_POST['telegram_chat_id'])));
        update_option('tkgadm_alert_threshold', intval($_POST['alert_threshold']));
        update_option('tkgadm_enable_hourly_alerts', isset($_POST['enable_hourly_alerts']) ? '1' : '0');
        update_option('tkgadm_enable_daily_reports', isset($_POST['enable_daily_reports']) ? '1' : '0');
        
        // Alert platform & frequency settings
        update_option('tkgadm_alert_platform_email', isset($_POST['alert_platform_email']) ? '1' : '0');
        update_option('tkgadm_alert_platform_telegram', isset($_POST['alert_platform_telegram']) ? '1' : '0');
        update_option('tkgadm_alert_frequency', sanitize_text_field(wp_unslash($_POST['alert_frequency'] ?? 'hourly')));
        update_option('tkgadm_daily_report_time', sanitize_text_field(wp_unslash($_POST['daily_report_time'] ?? '08:00')));
        
        // SMTP settings
        update_option('tkgadm_use_custom_smtp', isset($_POST['use_custom_smtp']) ? '1' : '0');
        update_option('tkgadm_smtp_host', sanitize_text_field(wp_unslash($_POST['smtp_host'] ?? '')));
        update_option('tkgadm_smtp_port', intval($_POST['smtp_port'] ?? 587));
        update_option('tkgadm_smtp_secure', sanitize_text_field(wp_unslash($_POST['smtp_secure'] ?? 'tls')));
        update_option('tkgadm_smtp_auth', isset($_POST['smtp_auth']) ? '1' : '0');
        update_option('tkgadm_smtp_username', sanitize_text_field(wp_unslash($_POST['smtp_username'] ?? '')));
        update_option('tkgadm_smtp_password', sanitize_text_field(wp_unslash($_POST['smtp_password'] ?? '')));
        update_option('tkgadm_smtp_from_email', sanitize_email(wp_unslash($_POST['smtp_from_email'] ?? '')));
        update_option('tkgadm_smtp_from_name', sanitize_text_field(wp_unslash($_POST['smtp_from_name'] ?? '')));
        
        // Reschedule cron jobs với settings mới
        tkgadm_schedule_notifications();
        
        echo '<div class="notice notice-success"><p>✅ Đã lưu cấu hình thành công!</p></div>';
    }
    
    // Get current settings
    $emails = get_option('tkgadm_notification_emails', '');
    $telegram_token = get_option('tkgadm_telegram_bot_token', '');
    $telegram_chat_id = get_option('tkgadm_telegram_chat_id', '');
    $threshold = get_option('tkgadm_alert_threshold', 5);
    $hourly_enabled = get_option('tkgadm_enable_hourly_alerts', '1');
    $daily_enabled = get_option('tkgadm_enable_daily_reports', '1');
    
    // Alert platform & frequency
    $alert_platform_email = get_option('tkgadm_alert_platform_email', '1');
    $alert_platform_telegram = get_option('tkgadm_alert_platform_telegram', '1');
    $alert_frequency = get_option('tkgadm_alert_frequency', 'hourly');
    $daily_report_time = get_option('tkgadm_daily_report_time', '08:00');
    
    // SMTP settings
    $use_custom_smtp = get_option('tkgadm_use_custom_smtp', '0');
    $smtp_host = get_option('tkgadm_smtp_host', '');
    $smtp_port = get_option('tkgadm_smtp_port', 587);
    $smtp_secure = get_option('tkgadm_smtp_secure', 'tls');
    $smtp_auth = get_option('tkgadm_smtp_auth', '1');
    $smtp_username = get_option('tkgadm_smtp_username', '');
    $smtp_password = get_option('tkgadm_smtp_password', '');
    $smtp_from_email = get_option('tkgadm_smtp_from_email', '');
    $smtp_from_name = get_option('tkgadm_smtp_from_name', 'GAds Toolkit');
    
    ?>
    <div class="wrap">
        <div class="tkgadm-wrap">
            <div class="tkgadm-header">
                <h1>🔔 Cấu Hình Thông Báo</h1>
                <p style="color: #666; margin-top: 10px;">Nhận cảnh báo về IP nghi ngờ và báo cáo traffic hàng ngày</p>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('tkgadm_notifications_nonce'); ?>
                
                <div style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #ddd; margin-bottom: 20px;">
                    
                    <!-- Email & Telegram Settings in 2 columns -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px;">
                        
                        <!-- Email Settings -->
                        <div>
                            <h2 style="margin: 0 0 15px 0; font-size: 16px;">📧 Cấu hình Email</h2>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Email nhận thông báo</label>
                                <input type="text" name="notification_emails" value="<?php echo esc_attr($emails); ?>" class="widefat" placeholder="email1@example.com, email2@example.com" style="padding: 6px; font-size: 13px;">
                                <p class="description" style="margin-top: 3px; font-size: 12px;">Phân tách bằng dấu phẩy. Để trống nếu không dùng.</p>
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                                    <input type="checkbox" name="use_custom_smtp" value="1" <?php checked($use_custom_smtp, '1'); ?> id="use-custom-smtp">
                                    <span>Sử dụng SMTP riêng</span>
                                </label>
                                <p class="description" style="margin-top: 3px; font-size: 12px;">Bật để cấu hình SMTP riêng cho plugin</p>
                            </div>

                            <!-- SMTP Config (Collapsible) -->
                            <div id="smtp-config-section" style="<?php echo $use_custom_smtp === '1' ? '' : 'display:none;'; ?> background: #f9f9f9; padding: 12px; border-radius: 5px; border: 1px solid #ddd;">
                                <h4 style="margin: 0 0 10px 0; font-size: 13px;">⚙️ SMTP Server</h4>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; font-size: 12px; margin-bottom: 3px;">Host</label>
                                        <input type="text" name="smtp_host" value="<?php echo esc_attr($smtp_host); ?>" class="widefat" placeholder="smtp.gmail.com" style="padding: 4px; font-size: 12px;">
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 12px; margin-bottom: 3px;">Port</label>
                                        <input type="number" name="smtp_port" value="<?php echo esc_attr($smtp_port); ?>" class="widefat" style="padding: 4px; font-size: 12px;">
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; font-size: 12px; margin-bottom: 3px;">Encryption</label>
                                        <select name="smtp_secure" class="widefat" style="padding: 4px; font-size: 12px;">
                                            <option value="tls" <?php selected($smtp_secure, 'tls'); ?>>TLS</option>
                                            <option value="ssl" <?php selected($smtp_secure, 'ssl'); ?>>SSL</option>
                                            <option value="" <?php selected($smtp_secure, ''); ?>>None</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display: flex; align-items: center; gap: 5px; font-size: 12px; padding-top: 20px;">
                                            <input type="checkbox" name="smtp_auth" value="1" <?php checked($smtp_auth, '1'); ?>>
                                            <span>Yêu cầu auth</span>
                                        </label>
                                    </div>
                                </div>

                                <div style="margin-bottom: 10px;">
                                    <label style="display: block; font-size: 12px; margin-bottom: 3px;">Username</label>
                                    <input type="text" name="smtp_username" value="<?php echo esc_attr($smtp_username); ?>" class="widefat" placeholder="your-email@gmail.com" style="padding: 4px; font-size: 12px;">
                                </div>

                                <div style="margin-bottom: 10px;">
                                    <label style="display: block; font-size: 12px; margin-bottom: 3px;">Password</label>
                                    <input type="password" name="smtp_password" value="<?php echo esc_attr($smtp_password); ?>" class="widefat" placeholder="App Password" style="padding: 4px; font-size: 12px;">
                                    <p class="description" style="margin-top: 2px; font-size: 11px;">⚠ Gmail yêu cầu App Password</p>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <div>
                                        <label style="display: block; font-size: 12px; margin-bottom: 3px;">From Email</label>
                                        <input type="email" name="smtp_from_email" value="<?php echo esc_attr($smtp_from_email); ?>" class="widefat" placeholder="noreply@domain.com" style="padding: 4px; font-size: 12px;">
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 12px; margin-bottom: 3px;">From Name</label>
                                        <input type="text" name="smtp_from_name" value="<?php echo esc_attr($smtp_from_name); ?>" class="widefat" placeholder="GAds Toolkit" style="padding: 4px; font-size: 12px;">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Telegram Settings -->
                        <div>
                            <h2 style="margin: 0 0 15px 0; font-size: 16px;">📱 Cấu hình Telegram</h2>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Bot Token</label>
                                <input type="text" name="telegram_bot_token" value="<?php echo esc_attr($telegram_token); ?>" class="widefat" placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz" style="padding: 6px; font-size: 13px;">
                                <p class="description" style="margin-top: 3px; font-size: 12px;">Lấy từ <a href="https://t.me/BotFather" target="_blank">@BotFather</a></p>
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Chat ID</label>
                                <input type="text" name="telegram_chat_id" value="<?php echo esc_attr($telegram_chat_id); ?>" class="widefat" placeholder="-1001234567890" style="padding: 6px; font-size: 13px;">
                                <p class="description" style="margin-top: 3px; font-size: 12px;">ID group/channel. Lấy từ <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a></p>
                            </div>
                        </div>
                    </div>

                    <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">

                    <!-- Alert Settings & Cron Status in 2 columns -->
                    <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 25px;">
                        
                        <!-- Alert Settings -->
                        <div>
                            <h2 style="margin: 0 0 15px 0; font-size: 16px;">⚙️ Cấu hình Cảnh báo</h2>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Ngưỡng cảnh báo</label>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <input type="number" name="alert_threshold" value="<?php echo esc_attr($threshold); ?>" min="1" max="100" style="width: 80px; padding: 6px; font-size: 13px;">
                                    <span style="font-size: 13px;">clicks từ Google Ads</span>
                                </div>
                                <p class="description" style="margin-top: 3px; font-size: 12px;">Cảnh báo khi IP vượt ngưỡng mà chưa bị chặn</p>
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Nền tảng nhận cảnh báo</label>
                                <div style="display: flex; gap: 15px;">
                                    <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer;">
                                        <input type="checkbox" name="alert_platform_email" value="1" <?php checked($alert_platform_email, '1'); ?>>
                                        <span>📧 Email</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer;">
                                        <input type="checkbox" name="alert_platform_telegram" value="1" <?php checked($alert_platform_telegram, '1'); ?>>
                                        <span>📱 Telegram</span>
                                    </label>
                                </div>
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Tần suất kiểm tra IP nghi ngờ</label>
                                <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 13px; cursor: pointer;">
                                    <input type="checkbox" name="enable_hourly_alerts" value="1" <?php checked($hourly_enabled, '1'); ?>>
                                    <span>Bật cảnh báo IP nghi ngờ</span>
                                </label>
                                <select name="alert_frequency" style="width: 200px; padding: 6px; font-size: 13px;">
                                    <option value="hourly" <?php selected($alert_frequency, 'hourly'); ?>>Mỗi giờ</option>
                                    <option value="twicedaily" <?php selected($alert_frequency, 'twicedaily'); ?>>2 lần/ngày</option>
                                    <option value="daily" <?php selected($alert_frequency, 'daily'); ?>>Mỗi ngày</option>
                                </select>
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label style="display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px;">Báo cáo hàng ngày</label>
                                <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 13px; cursor: pointer;">
                                    <input type="checkbox" name="enable_daily_reports" value="1" <?php checked($daily_enabled, '1'); ?>>
                                    <span>Bật báo cáo tổng hợp traffic</span>
                                </label>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <label style="font-size: 13px;">Thời gian gửi:</label>
                                    <input type="time" name="daily_report_time" value="<?php echo esc_attr($daily_report_time); ?>" style="width: 120px; padding: 6px; font-size: 13px;">
                                </div>
                            </div>
                        </div>

                        <!-- Cron Status -->
                        <div>
                            <h2 style="margin: 0 0 15px 0; font-size: 16px;">⏰ Trạng thái Cron</h2>
                            <?php
                            $hourly_next = wp_next_scheduled('tkgadm_hourly_alert');
                            $daily_next = wp_next_scheduled('tkgadm_daily_report');
                            ?>
                            
                            <div style="background: #f9f9f9; padding: 12px; border-radius: 5px; border: 1px solid #ddd; margin-bottom: 12px;">
                                <div style="font-weight: 500; font-size: 13px; margin-bottom: 5px;">🔍 Kiểm tra IP nghi ngờ</div>
                                <div style="font-size: 12px; color: #666; margin-bottom: 3px;">
                                    <?php echo $hourly_next ? '✅ Đang hoạt động' : '❌ Chưa kích hoạt'; ?>
                                </div>
                                <div style="font-size: 11px; color: #999;">
                                    <?php echo $hourly_next ? 'Lần chạy tiếp: ' . wp_date('H:i d/m', $hourly_next) : 'Chưa lên lịch'; ?>
                                </div>
                            </div>

                            <div style="background: #f9f9f9; padding: 12px; border-radius: 5px; border: 1px solid #ddd;">
                                <div style="font-weight: 500; font-size: 13px; margin-bottom: 5px;">📊 Báo cáo hàng ngày</div>
                                <div style="font-size: 12px; color: #666; margin-bottom: 3px;">
                                    <?php echo $daily_next ? '✅ Đang hoạt động' : '❌ Chưa kích hoạt'; ?>
                                </div>
                                <div style="font-size: 11px; color: #999;">
                                    <?php echo $daily_next ? 'Lần chạy tiếp: ' . wp_date('H:i d/m', $daily_next) : 'Chưa lên lịch'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" name="tkgadm_save_notifications" class="button button-primary">💾 Lưu cấu hình</button>
                    </div>
                </div>
            </form>

            <script>
            jQuery(document).ready(function($) {
                $('#use-custom-smtp').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#smtp-config-section').slideDown();
                    } else {
                        $('#smtp-config-section').slideUp();
                    }
                });
            });
            </script>

            <!-- Deep Test Module -->
            <div class="tkgadm-table-container" style="margin-top: 30px; border-left: 4px solid #9c27b0;">
                <h2>🕵️ Test Case Độc Lập (Debug Mode)</h2>
                <p>Chạy kiểm tra chuyên sâu để xem log chi tiết lỗi kết nối (nếu có).</p>
                
                <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                    <button type="button" id="btn-deep-test-email" class="button button-secondary">📧 Kiểm tra Email Chi tiết</button>
                    <button type="button" id="btn-deep-test-telegram" class="button button-secondary">📱 Kiểm tra Telegram Chi tiết</button>
                </div>
                
                <div id="deep-test-result" style="display: none; background: #23282d; color: #fff; padding: 15px; border-radius: 4px; font-family: monospace; max-height: 300px; overflow-y: auto;">
                    <div id="test-log-content"></div>
                </div>
            </div>
        </div>

        <!-- IPv6 Diagnostic -->
        <div style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #ddd; margin-top: 20px; border-left: 4px solid #2196F3;">
            <h2 style="margin: 0 0 15px 0; font-size: 18px;">🌐 Chẩn đoán IPv6</h2>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <h3 style="margin: 0 0 10px 0; font-size: 14px;">📊 Thông tin IP hiện tại</h3>
                    <div style="background: #f9f9f9; padding: 12px; border-radius: 5px; border: 1px solid #ddd; font-family: monospace; font-size: 13px;">
                        <?php
                        $current_ip = tkgadm_get_real_user_ip();
                        $is_ipv6 = filter_var($current_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
                        $is_ipv4 = filter_var($current_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
                        ?>
                        <div style="margin-bottom: 8px;">
                            <strong>IP của bạn:</strong><br>
                            <span style="color: <?php echo $is_ipv6 ? '#2196F3' : '#4CAF50'; ?>; font-size: 14px;">
                                <?php echo esc_html($current_ip); ?>
                            </span>
                        </div>
                        <div style="margin-bottom: 8px;">
                            <strong>Loại:</strong> 
                            <?php if ($is_ipv6): ?>
                                <span style="color: #2196F3;">✅ IPv6</span>
                            <?php elseif ($is_ipv4): ?>
                                <span style="color: #4CAF50;">✅ IPv4</span>
                            <?php else: ?>
                                <span style="color: #999;">❓ Unknown</span>
                            <?php endif; ?>
                        </div>
                        <?php
                        // Check if server has IPv6 capability using cURL (more reliable)
                        $ipv6_enabled = false;
                        if (function_exists('curl_init')) {
                            $ch = curl_init('https://ipv6.google.com');
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V6);
                            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                            curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
                            
                            $result = curl_exec($ch);
                            if (!curl_errno($ch) && $result !== false) {
                                $ipv6_enabled = true;
                            }
                            curl_close($ch);
                        } else {
                            // Fallback if cURL is missing
                            $ipv6_enabled = @file_get_contents('https://ipv6.google.com', false, stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 3]])) !== false;
                        }
                        ?>
                        <div>
                            <strong>Server hỗ trợ IPv6:</strong> 
                            <?php if ($ipv6_enabled): ?>
                                <span style="color: #4CAF50;">✅ Đã kích hoạt (OK)</span>
                            <?php else: ?>
                                <span style="color: #ff9800;">⚠️ Code PHP chưa kết nối được IPv6</span>
                                <p style="font-size: 11px; color: #666; margin: 5px 0 0 0; font-style: italic;">
                                    (Nếu terminal đã OK mà ở đây vẫn báo vàng thì do cấu hình PHP chặn kết nối ra ngoài, nhưng server vẫn hoạt động bình thường).
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div>
                    <h3 style="margin: 0 0 10px 0; font-size: 14px;">💡 Hướng dẫn kích hoạt IPv6</h3>
                    <div style="background: #fff3cd; padding: 12px; border-radius: 5px; border: 1px solid #ffc107; font-size: 12px;">
                        <p style="margin: 0 0 8px 0;"><strong>Nếu server chưa hỗ trợ IPv6:</strong></p>
                        <ol style="margin: 0; padding-left: 20px;">
                            <li style="margin-bottom: 5px;">Liên hệ nhà cung cấp hosting yêu cầu kích hoạt IPv6</li>
                            <li style="margin-bottom: 5px;">Hoặc tự cấu hình trên VPS:
                                <ul style="margin: 5px 0; padding-left: 15px; font-size: 11px;">
                                    <li>Ubuntu/Debian: Sửa <code>/etc/netplan/</code></li>
                                    <li>CentOS: Sửa <code>/etc/sysconfig/network-scripts/</code></li>
                                </ul>
                            </li>
                            <li>Khởi động lại network: <code style="background: #333; color: #0f0; padding: 2px 4px; border-radius: 3px;">systemctl restart networking</code></li>
                        </ol>
                        <p style="margin: 10px 0 0 0; font-size: 11px; color: #856404;">
                            <strong>Lưu ý:</strong> Plugin tự động thu thập cả IPv4 và IPv6. Không cần cấu hình thêm.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        
        function runDeepTest(type) {
            const btn = type === 'email' ? $('#btn-deep-test-email') : $('#btn-deep-test-telegram');
            const originalText = btn.text();
            
            btn.text('⏳ Đang chạy...').prop('disabled', true);
            $('#deep-test-result').slideDown();
            $('#test-log-content').html('<span style="color: #aaa;">> Đang khởi tạo test case: ' + type + '...</span><br>');
            
            // Lấy giá trị hiện tại từ input để test (kể cả chưa lưu)
            const customEmail = $('input[name="notification_emails"]').val();
            const customToken = $('input[name="telegram_bot_token"]').val();
            const customChatId = $('input[name="telegram_chat_id"]').val();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tkgadm_run_deep_test',
                    nonce: '<?php echo wp_create_nonce("tkgadm_test_nonce"); ?>',
                    test_type: type,
                    custom_email: customEmail,
                    custom_token: customToken,
                    custom_chat_id: customChatId
                },
                success: function(response) {
                    btn.text(originalText).prop('disabled', false);
                    
                    if (response.success) {
                        const data = response.data;
                        let logHtml = '';
                        
                        // Status Header
                        if (data.success) {
                            logHtml += '<span style="color: #46b450; font-weight: bold;">[SUCCESS] Test case passed!</span><br>';
                        } else {
                            logHtml += '<span style="color: #dc3232; font-weight: bold;">[FAILED] Test case failed!</span><br>';
                        }
                        
                        logHtml += '<hr style="border-color: #555;">';
                        
                        // Logs
                        if (data.log && data.log.length > 0) {
                            data.log.forEach(function(line) {
                                let style = 'color: #fff;';
                                if (line.indexOf('✅') !== -1) style = 'color: #46b450;';
                                if (line.indexOf('❌') !== -1) style = 'color: #ff6b6b;';
                                if (line.indexOf('⚠️') !== -1) style = 'color: #fca311;';
                                if (line.indexOf('ℹ️') !== -1) style = 'color: #88c0d0;'; 
                                if (line.indexOf('💡') !== -1) style = 'color: #e5e9f0; font-style: italic;'; 
                                
                                logHtml += '<span style="' + style + '">' + line + '</span><br>';
                            });
                        }
                        
                        $('#test-log-content').html(logHtml);
                    } else {
                        $('#test-log-content').html('<span style="color: #ff6b6b;">❌ Lỗi AJAX System: ' + response.data + '</span>');
                    }
                },
                error: function(xhr, status, error) {
                    btn.text(originalText).prop('disabled', false);
                    $('#test-log-content').html('<span style="color: #ff6b6b;">❌ Lỗi Kết Nối Server: ' + error + '</span>');
                }
            });
        }

        $('#btn-deep-test-email').on('click', function() { runDeepTest('email'); });
        $('#btn-deep-test-telegram').on('click', function() { runDeepTest('telegram'); });
    });
    </script>
    <?php
}

/**
 * ============================================================================
 * 4. DEEP TEST MODULE W/ AJAX
 * ============================================================================
 */

/**
 * Class xử lý test case thông báo với log chi tiết (Included Inline)
 */
class TKGADM_Notification_Tester {
    public static function run_email_test($email_string) {
        $result = [
            'success' => false,
            'log' => [],
            'input' => $email_string,
            'smtp_info' => []
        ];

        if (empty($email_string)) {
            $result['log'][] = "❌ Lỗi: Danh sách email trống.";
            return $result;
        }

        // 1. Kiểm tra SMTP plugins
        $result['log'][] = "🔍 Kiểm tra cấu hình SMTP...";
        $smtp_plugins = [
            'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP',
            'easy-wp-smtp/easy-wp-smtp.php' => 'Easy WP SMTP',
            'post-smtp/postman-smtp.php' => 'Post SMTP',
            'wp-ses/wp-ses.php' => 'WP SES'
        ];
        
        $active_smtp = [];
        foreach ($smtp_plugins as $plugin_path => $plugin_name) {
            if (is_plugin_active($plugin_path)) {
                $active_smtp[] = $plugin_name;
            }
        }
        
        if (empty($active_smtp)) {
            $result['log'][] = "⚠️ Không phát hiện SMTP plugin nào đang active.";
            $result['log'][] = "💡 WordPress đang dùng PHP mail() function - có thể bị từ chối hoặc vào spam.";
            $result['log'][] = "💡 Khuyến nghị: Cài đặt WP Mail SMTP hoặc Easy WP SMTP.";
        } else {
            $result['log'][] = "✅ Phát hiện SMTP plugin: " . implode(', ', $active_smtp);
        }

        // 2. Phân tích danh sách email
        $emails = array_filter(array_map('trim', preg_split('/[,\n\r]+/', $email_string)));
        $result['log'][] = "ℹ️ Đã tìm thấy " . count($emails) . " email hợp lệ: " . implode(', ', $emails);

        if (empty($emails)) {
            $result['log'][] = "❌ Lỗi: Không có email nào hợp lệ sau khi xử lý.";
            return $result;
        }

        // 3. Hook vào PHPMailer
        $phpmailer_info = [];
        add_action('phpmailer_init', function($phpmailer) use (&$phpmailer_info, &$result) {
            $phpmailer_info['mailer'] = $phpmailer->Mailer; 
            
            if ($phpmailer->Mailer === 'smtp') {
                $phpmailer_info['host'] = $phpmailer->Host;
                $phpmailer_info['port'] = $phpmailer->Port;
                $phpmailer_info['secure'] = $phpmailer->SMTPSecure; 
                $phpmailer_info['auth'] = $phpmailer->SMTPAuth;
                $phpmailer_info['username'] = $phpmailer->Username;
                
                // Enable debug output
                $phpmailer->SMTPDebug = 2; 
                $phpmailer->Debugoutput = function($str, $level) use (&$result) {
                    $result['smtp_info']['debug'][] = trim($str);
                };
            }
        }, 999);

        // 4. Hook để bắt lỗi wp_mail_failed
        add_action('wp_mail_failed', function ($error) use (&$result) {
            $result['log'][] = "❌ WP_Mail Failed: " . $error->get_error_message();
            $error_data = $error->get_error_data();
            if ($error_data) {
                $result['log'][] = "ℹ️ Error Data: " . print_r($error_data, true);
            }
        });

        // 5. Gửi thử
        $subject = '🧪 [Deep Test] Kiểm tra Email GAds Toolkit';
        $message = "Xin chào,\n\nĐây là email kiểm tra từ module Test Case Độc Lập của GAds Toolkit.\nThời gian: " . current_time('mysql') . "\n\nNếu bạn nhận được email này, SMTP đã hoạt động tốt!";
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        $mail_sent = wp_mail($emails, $subject, $message, $headers);

        // 6. Phân tích kết quả
        if (!empty($phpmailer_info)) {
            $result['log'][] = "📧 Thông tin PHPMailer:";
            $result['log'][] = "  ├─ Mailer: " . strtoupper($phpmailer_info['mailer']);
            
            if ($phpmailer_info['mailer'] === 'smtp') {
                $result['log'][] = "  ├─ SMTP Host: " . $phpmailer_info['host'];
                $result['log'][] = "  ├─ SMTP Port: " . $phpmailer_info['port'];
                $result['log'][] = "  ├─ Encryption: " . ($phpmailer_info['secure'] ?: 'None');
                $result['log'][] = "  ├─ Authentication: " . ($phpmailer_info['auth'] ? 'Yes' : 'No');
                $result['log'][] = "  └─ Username: " . ($phpmailer_info['username'] ?: 'N/A');
                
                if (!empty($result['smtp_info']['debug'])) {
                    $result['log'][] = "📝 SMTP Debug Log:";
                    foreach ($result['smtp_info']['debug'] as $debug_line) {
                        if (stripos($debug_line, 'error') !== false || stripos($debug_line, 'failed') !== false) {
                            $result['log'][] = "  ❌ " . $debug_line;
                        } elseif (stripos($debug_line, 'success') !== false || stripos($debug_line, '250') !== false) {
                            $result['log'][] = "  ✅ " . $debug_line;
                        } else {
                            $result['log'][] = "  ℹ️ " . $debug_line;
                        }
                    }
                }
            }
        }

        if ($mail_sent) {
            $result['success'] = true;
            $result['log'][] = "✅ Hàm wp_mail trả về TRUE. Email đã được chấp nhận bởi WordPress.";
        } else {
            $result['success'] = false;
            $result['log'][] = "❌ Hàm wp_mail trả về FALSE. Email không được gửi.";
        }

        return $result;
    }

    public static function run_telegram_test($token, $chat_id) {
        $result = [
            'success' => false,
            'log' => [],
            'input' => ['token' => '*** hidden ***', 'chat_id' => $chat_id]
        ];

        if (empty($token) || empty($chat_id)) {
            $result['log'][] = "❌ Lỗi: Thiếu Token hoặc Chat ID.";
            return $result;
        }

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $message = "🧪 *[Deep Test]* Kiểm tra Telegram GAds Toolkit\n\nThời gian: `" . current_time('mysql') . "`\nKết nối thành công! ✅";

        $body = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ];

        $response = wp_remote_post($url, [
            'body' => $body,
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
             $result['log'][] = "❌ Lỗi kết nối HTTP đến Telegram API: " . $response->get_error_message();
             return $result;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        $result['log'][] = "ℹ️ HTTP Response Code: " . $response_code;

        if ($response_code == 200 && isset($data['ok']) && $data['ok'] === true) {
            $result['success'] = true;
            $result['log'][] = "✅ Telegram API trả về Success.";
            $result['log'][] = "ℹ️ Message ID: " . $data['result']['message_id'];
            $result['log'][] = "ℹ️ Người nhận: " . $data['result']['chat']['title'] . " (@" . ($data['result']['chat']['username'] ?? 'N/A') . ")";
        } else {
            $result['success'] = false;
            $result['log'][] = "❌ Telegram API trả về Lỗi.";
            $result['log'][] = "ℹ️ Raw Response: " . $response_body;
            
            if ($response_code == 401) {
                $result['log'][] = "💡 Gợi ý: Bot Token không đúng.";
            } elseif ($response_code == 400) {
                 $result['log'][] = "💡 Gợi ý: Chat ID sai hoặc Bot chưa được thêm vào Group/chưa Chat với người dùng.";
            }
        }

        return $result;
    }
}

// AJAX Handler
add_action('wp_ajax_tkgadm_run_deep_test', 'tkgadm_ajax_run_deep_test');
function tkgadm_ajax_run_deep_test() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("Không có quyền truy cập.");
    }
    
    check_ajax_referer('tkgadm_test_nonce', 'nonce');

    $type = sanitize_text_field($_POST['test_type']);
    $output = [];

    if ($type === 'email') {
        $emails = isset($_POST['custom_email']) && !empty($_POST['custom_email']) 
                  ? sanitize_text_field($_POST['custom_email']) 
                  : get_option('tkgadm_notification_emails', '');
        
        $output = TKGADM_Notification_Tester::run_email_test($emails);
    } 
    elseif ($type === 'telegram') {
        $token = isset($_POST['custom_token']) && !empty($_POST['custom_token'])
                 ? sanitize_text_field($_POST['custom_token'])
                 : get_option('tkgadm_telegram_bot_token', '');
                 
        $chat_id = isset($_POST['custom_chat_id']) && !empty($_POST['custom_chat_id'])
                 ? sanitize_text_field($_POST['custom_chat_id'])
                 : get_option('tkgadm_telegram_chat_id', '');

        $output = TKGADM_Notification_Tester::run_telegram_test($token, $chat_id);
    }
    else {
        wp_send_json_error("Loại test không hợp lệ.");
    }

    wp_send_json_success($output);
}
