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
 * Gửi tin nhắn Telegram
 */
function tkgadm_send_telegram_message($message) {
    if (!tkgadm_license_is_valid()) { return false; }

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
        ),
        'timeout' => 15
    ));
    
    if (is_wp_error($response)) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return wp_remote_retrieve_response_code($response) === 200 && !empty($data['ok']);
}

/**
 * Gửi email thông báo
 */
function tkgadm_send_email_notification($subject, $message) {
    if (!tkgadm_license_is_valid()) { return false; }

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
    if (!tkgadm_license_is_valid()) { return; }

    if (get_option('tkgadm_enable_hourly_alerts', '1') !== '1') {
        return;
    }
    
    global $wpdb;
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    $threshold = get_option('tkgadm_alert_threshold', 5);

    // visit_time is stored in site-local time: compute the window on the same clock.
    // The window covers the time since the previous run (alerts can be hourly/12h/daily).
    $windows = ['hourly' => HOUR_IN_SECONDS, 'twicedaily' => 12 * HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS];
    $frequency = get_option('tkgadm_alert_frequency', 'hourly');
    $window = isset($windows[$frequency]) ? $windows[$frequency] : HOUR_IN_SECONDS;
    $since_time = wp_date('Y-m-d H:i:s', time() - $window);
    
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
        AND s.visit_time >= %s
        GROUP BY s.ip_address
        HAVING ad_clicks >= %d
        ORDER BY ad_clicks DESC
    ", $since_time, $threshold));
    
    // Skip whitelisted IPs and IPs already alerted in the last 24 hours.
    $alerted = get_option('tkgadm_alerted_ips', []);
    $alerted = is_array($alerted) ? array_filter($alerted, function ($ts) { return $ts > time() - DAY_IN_SECONDS; }) : [];
    $suspicious_ips = array_values(array_filter((array) $suspicious_ips, function ($row) use ($alerted) {
        return !isset($alerted[$row->ip_address])
            && !(function_exists('tkgadm_is_ip_whitelisted') && tkgadm_is_ip_whitelisted($row->ip_address));
    }));
    foreach ($suspicious_ips as $row) {
        $alerted[$row->ip_address] = time();
    }
    update_option('tkgadm_alerted_ips', $alerted, false);

    if (empty($suspicious_ips)) {
        return; // Không có IP nghi ngờ
    }
    
    // Tạo message
    $count = count($suspicious_ips);
    
    $message_email = "[CẢNH BÁO IP] {$count} IP\n-------------------\n";
    $message_telegram = "[CẢNH BÁO IP] {$count} IP\n-------------------\n";
    
    foreach ($suspicious_ips as $ip_data) {
        $message_email .= sprintf(
            "%s (%dcl/%dv)\n",
            $ip_data->ip_address,
            $ip_data->ad_clicks,
            $ip_data->total_visits
        );
        
        $message_telegram .= sprintf(
            "`%s` (%dcl/%dv)\n",
            $ip_data->ip_address,
            $ip_data->ad_clicks,
            $ip_data->total_visits
        );
    }
    
    $window_label = $window >= DAY_IN_SECONDS ? '24h' : ($window >= 12 * HOUR_IN_SECONDS ? '12h' : '1h');
    $message_email .= "\nNgưỡng: {$threshold} click/{$window_label}\n";
    $message_email .= "[Mở Dashboard] " . admin_url('admin.php?page=tkgad-moi');
    
    $message_telegram .= "\nNgưỡng: {$threshold} click/{$window_label}\n";
    $message_telegram .= "[Mở Dashboard](" . admin_url('admin.php?page=tkgad-moi') . ")";
    
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
    if (!tkgadm_license_is_valid()) { return; }

    if (get_option('tkgadm_enable_daily_reports', '1') !== '1') {
        return;
    }
    
    global $wpdb;
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';

    // Calculate yesterday range in WP timezone (avoid MySQL CURDATE() timezone mismatch)
    $tz = wp_timezone();
    $yesterday_start = (new DateTimeImmutable('yesterday', $tz))->setTime(0, 0, 0);
    $yesterday_end = $yesterday_start->modify('+1 day');
    $yesterday_start_mysql = $yesterday_start->format('Y-m-d H:i:s');
    $yesterday_end_mysql = $yesterday_end->format('Y-m-d H:i:s');
    
    // Thống kê hôm qua (Organic chỉ đếm records có time_on_page hợp lệ để lọc bot)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $stats = $wpdb->get_row($wpdb->prepare("
        SELECT 
            COUNT(DISTINCT CASE WHEN gclid IS NOT NULL AND gclid != '' THEN ip_address END) as ads_ips,
            COUNT(DISTINCT CASE WHEN (gclid IS NULL OR gclid = '') AND time_on_page IS NOT NULL AND time_on_page > 0 THEN ip_address END) as organic_ips,
            SUM(CASE WHEN gclid IS NOT NULL AND gclid != '' THEN visit_count ELSE 0 END) as ads_visits,
            SUM(CASE WHEN (gclid IS NULL OR gclid = '') AND time_on_page IS NOT NULL AND time_on_page > 0 THEN visit_count ELSE 0 END) as organic_visits,
            COUNT(DISTINCT CASE WHEN gclid IS NOT NULL AND gclid != '' THEN gclid END) as unique_clicks
        FROM $table_stats
        WHERE visit_time >= %s AND visit_time < %s
    ", $yesterday_start_mysql, $yesterday_end_mysql));
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $blocked_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_blocked");
    
    $total_visits = $stats->ads_visits + $stats->organic_visits;
    $ads_ratio = $total_visits > 0 ? round(($stats->ads_visits / $total_visits) * 100, 1) : 0;
    
    // Email message
    $message_email = "[BÁO CÁO] " . $yesterday_start->format('d/m/Y') . "\n--------------------\n";
    $message_email .= "Tổng: " . number_format($total_visits) . "\n";
    $message_email .= sprintf("- Ads: %s (%s%%)\n", number_format($stats->ads_visits), $ads_ratio);
    $message_email .= sprintf("- Org: %s\n\n", number_format($stats->organic_visits));
    $message_email .= sprintf("Ads IP: %d\n", $stats->ads_ips);
    $message_email .= sprintf("Click: %d\n", $stats->unique_clicks);
    $message_email .= sprintf("Đã chặn: %d\n", $blocked_count);
    $message_email .= "[Mở Dashboard] " . admin_url('admin.php?page=tkgad-moi');
    
    // Telegram message
    $message_telegram = "[BÁO CÁO] " . $yesterday_start->format('d/m/Y') . "\n--------------------\n";
    $message_telegram .= "Tổng: " . number_format($total_visits) . "\n";
    $message_telegram .= sprintf("- Ads: %s (%s%%)\n", number_format($stats->ads_visits), $ads_ratio);
    $message_telegram .= sprintf("- Org: %s\n\n", number_format($stats->organic_visits));
    $message_telegram .= sprintf("Ads IP: %d\n", $stats->ads_ips);
    $message_telegram .= sprintf("Click: %d\n", $stats->unique_clicks);
    $message_telegram .= sprintf("Đã chặn: %d\n", $blocked_count);
    $message_telegram .= "[Mở Dashboard](" . admin_url('admin.php?page=tkgad-moi') . ")";
    
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
    if (!in_array($frequency, ['hourly', 'twicedaily', 'daily'], true)) {
        $frequency = 'hourly';
    }
    if (!wp_next_scheduled('tkgadm_hourly_alert')) {
        wp_schedule_event(time(), $frequency, 'tkgadm_hourly_alert');
    }
    
    // Schedule daily report theo time setting (WP timezone)
    if (!wp_next_scheduled('tkgadm_daily_report')) {
        $report_time = get_option('tkgadm_daily_report_time', '08:00');
        $parts = explode(':', $report_time);
        $hour = isset($parts[0]) ? intval($parts[0]) : 8;
        $minute = isset($parts[1]) ? intval($parts[1]) : 0;

        $tz = wp_timezone();
        $now_ts = time();
        $today = (new DateTimeImmutable('now', $tz))->setTime($hour, $minute, 0);
        $scheduled_ts = $today->getTimestamp();

        if ($scheduled_ts <= $now_ts) {
            $scheduled_ts = $today->modify('+1 day')->getTimestamp();
        }

        wp_schedule_event($scheduled_ts, 'daily', 'tkgadm_daily_report');
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
 * Class xử lý test case thông báo với log chi tiết (Included Inline)
 */
class TKGADM_Notification_Tester {
    public static function run_email_test($email_string) {
        if (!tkgadm_license_is_valid()) { return array('success' => false, 'log' => array(tkgadm_license_message())); }
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
        if (!tkgadm_license_is_valid()) { return array('success' => false, 'log' => array(tkgadm_license_message())); }
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
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

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

/**
 * Lightweight connection tests used by the redesigned Settings screen.
 */
add_action('wp_ajax_tkgadm_test_telegram_connection', 'tkgadm_ajax_test_telegram_connection');
function tkgadm_ajax_test_telegram_connection() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền truy cập.');
    }

    check_ajax_referer('tkgadm_nonce', 'nonce');

    $token = get_option('tkgadm_telegram_bot_token', '');
    $chat_id = get_option('tkgadm_telegram_chat_id', '');
    $result = TKGADM_Notification_Tester::run_telegram_test($token, $chat_id);

    if (!empty($result['success'])) {
        wp_send_json_success('Đã gửi tin nhắn test tới Telegram.');
    }

    $details = !empty($result['log']) ? implode(' ', $result['log']) : 'Không thể kết nối Telegram.';
    wp_send_json_error($details);
}

add_action('wp_ajax_tkgadm_test_email_connection', 'tkgadm_ajax_test_email_connection');
function tkgadm_ajax_test_email_connection() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền truy cập.');
    }

    check_ajax_referer('tkgadm_nonce', 'nonce');

    $emails = get_option('tkgadm_notification_emails', '');
    if (empty($emails)) {
        wp_send_json_error('Chưa cấu hình email nhận thông báo.');
    }

    if (tkgadm_send_email_notification('[GAds Toolkit] Test Email', 'Kết nối email của GAds Toolkit đang hoạt động.')) {
        wp_send_json_success('Đã gửi email test.');
    }

    wp_send_json_error('WordPress không thể gửi email test.');
}
