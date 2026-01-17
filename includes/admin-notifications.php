<?php
/**
 * Admin Notifications - Cấu hình thông báo
 * Submenu: Quản lý email & Telegram alerts
 */

if (!defined('ABSPATH')) exit;

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
    
    // Test notification
    if (isset($_POST['tkgadm_test_notification']) && check_admin_referer('tkgadm_test_nonce')) {
        $test_type = sanitize_text_field(wp_unslash($_POST['test_type']));
        
        if ($test_type === 'email') {
            $result = tkgadm_send_test_email();
            echo $result ? '<div class="notice notice-success"><p>✅ Email test đã gửi!</p></div>' : '<div class="notice notice-error"><p>❌ Lỗi gửi email</p></div>';
        } elseif ($test_type === 'telegram') {
            $result = tkgadm_send_test_telegram();
            echo $result ? '<div class="notice notice-success"><p>✅ Telegram test đã gửi!</p></div>' : '<div class="notice notice-error"><p>❌ Lỗi gửi Telegram</p></div>';
        }
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
                
                <div class="tkgadm-table-container">
                    <!-- Email Settings -->
                    <h2>📧 Cấu hình Email</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Email nhận thông báo</th>
                            <td>
                                <input type="text" name="notification_emails" value="<?php echo esc_attr($emails); ?>" class="large-text" placeholder="email1@example.com, email2@example.com">
                                <p class="description">Phân tách các email bằng dấu phẩy. Để trống nếu không muốn nhận email.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Cấu hình SMTP</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="use_custom_smtp" value="1" <?php checked($use_custom_smtp, '1'); ?> id="use-custom-smtp">
                                    Sử dụng SMTP riêng cho plugin này
                                </label>
                                <p class="description">Bật nếu muốn dùng SMTP riêng. Tắt để dùng cấu hình SMTP từ theme/plugin khác.</p>
                            </td>
                        </tr>
                    </table>

                    <!-- SMTP Configuration (collapsible) -->
                    <div id="smtp-config-section" style="<?php echo $use_custom_smtp === '1' ? '' : 'display:none;'; ?>">
                        <h3 style="margin-top: 20px;">⚙️ Cấu hình SMTP Server</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">SMTP Host</th>
                                <td>
                                    <input type="text" name="smtp_host" value="<?php echo esc_attr($smtp_host); ?>" class="regular-text" placeholder="smtp.gmail.com">
                                    <p class="description">Ví dụ: smtp.gmail.com, smtp.office365.com</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">SMTP Port</th>
                                <td>
                                    <input type="number" name="smtp_port" value="<?php echo esc_attr($smtp_port); ?>" style="width: 100px;">
                                    <p class="description">587 (TLS) hoặc 465 (SSL)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Encryption</th>
                                <td>
                                    <select name="smtp_secure">
                                        <option value="tls" <?php selected($smtp_secure, 'tls'); ?>>TLS</option>
                                        <option value="ssl" <?php selected($smtp_secure, 'ssl'); ?>>SSL</option>
                                        <option value="" <?php selected($smtp_secure, ''); ?>>None</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">SMTP Authentication</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="smtp_auth" value="1" <?php checked($smtp_auth, '1'); ?>>
                                        Yêu cầu authentication
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">SMTP Username</th>
                                <td>
                                    <input type="text" name="smtp_username" value="<?php echo esc_attr($smtp_username); ?>" class="regular-text" placeholder="your-email@gmail.com">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">SMTP Password</th>
                                <td>
                                    <input type="password" name="smtp_password" value="<?php echo esc_attr($smtp_password); ?>" class="regular-text" placeholder="App Password hoặc mật khẩu">
                                    <p class="description">⚠️ Gmail yêu cầu App Password, không dùng mật khẩu thường.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">From Email</th>
                                <td>
                                    <input type="email" name="smtp_from_email" value="<?php echo esc_attr($smtp_from_email); ?>" class="regular-text" placeholder="noreply@yourdomain.com">
                                    <p class="description">Email người gửi (thường phải trùng với SMTP username)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">From Name</th>
                                <td>
                                    <input type="text" name="smtp_from_name" value="<?php echo esc_attr($smtp_from_name); ?>" class="regular-text" placeholder="GAds Toolkit">
                                </td>
                            </tr>
                        </table>
                    </div>

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

                    <!-- Telegram Settings -->
                    <h2 style="margin-top: 30px;">📱 Cấu hình Telegram</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Bot Token</th>
                            <td>
                                <input type="text" name="telegram_bot_token" value="<?php echo esc_attr($telegram_token); ?>" class="large-text" placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz">
                                <p class="description">Lấy từ <a href="https://t.me/BotFather" target="_blank">@BotFather</a></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Chat ID</th>
                            <td>
                                <input type="text" name="telegram_chat_id" value="<?php echo esc_attr($telegram_chat_id); ?>" class="large-text" placeholder="-1001234567890">
                                <p class="description">ID của group/channel nhận thông báo. Lấy từ <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a></p>
                            </td>
                        </tr>
                    </table>

                    <!-- Alert Settings -->
                    <h2 style="margin-top: 30px;">⚙️ Cấu hình Cảnh báo</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Ngưỡng cảnh báo</th>
                            <td>
                                <input type="number" name="alert_threshold" value="<?php echo esc_attr($threshold); ?>" min="1" max="100" style="width: 100px;">
                                <span> clicks từ Google Ads</span>
                                <p class="description">Cảnh báo khi IP có số lượt click Ads vượt ngưỡng này mà chưa bị chặn</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Nền tảng nhận cảnh báo</th>
                            <td>
                                <label style="display: inline-block; margin-right: 20px;">
                                    <input type="checkbox" name="alert_platform_email" value="1" <?php checked($alert_platform_email, '1'); ?>>
                                    📧 Email
                                </label>
                                <label style="display: inline-block;">
                                    <input type="checkbox" name="alert_platform_telegram" value="1" <?php checked($alert_platform_telegram, '1'); ?>>
                                    📱 Telegram
                                </label>
                                <p class="description">Chọn nền tảng nhận thông báo (có thể chọn cả hai)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Tần suất kiểm tra IP nghi ngờ</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_hourly_alerts" value="1" <?php checked($hourly_enabled, '1'); ?>>
                                    Bật cảnh báo IP nghi ngờ
                                </label>
                                <br><br>
                                <select name="alert_frequency" style="width: 200px;">
                                    <option value="hourly" <?php selected($alert_frequency, 'hourly'); ?>>Mỗi giờ</option>
                                    <option value="twicedaily" <?php selected($alert_frequency, 'twicedaily'); ?>>2 lần/ngày (12h một lần)</option>
                                    <option value="daily" <?php selected($alert_frequency, 'daily'); ?>>Mỗi ngày</option>
                                </select>
                                <p class="description">Tần suất kiểm tra và gửi cảnh báo</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Báo cáo hàng ngày</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_daily_reports" value="1" <?php checked($daily_enabled, '1'); ?>>
                                    Bật báo cáo tổng hợp traffic
                                </label>
                                <br><br>
                                <label>Thời gian gửi:</label>
                                <input type="time" name="daily_report_time" value="<?php echo esc_attr($daily_report_time); ?>" style="width: 120px;">
                                <p class="description">Chọn giờ gửi báo cáo hàng ngày (định dạng 24h, ví dụ: 08:00)</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" name="tkgadm_save_notifications" class="button button-primary">💾 Lưu cấu hình</button>
                    </p>
                </div>
            </form>

            <!-- Cron Status -->
            <div class="tkgadm-table-container" style="margin-top: 30px;">
                <h2>⏰ Trạng thái Cron Jobs</h2>
                <?php
                $hourly_next = wp_next_scheduled('tkgadm_hourly_alert');
                $daily_next = wp_next_scheduled('tkgadm_daily_report');
                ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Cron Job</th>
                            <th>Trạng thái</th>
                            <th>Lần chạy tiếp theo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>🔍 Kiểm tra IP nghi ngờ (mỗi giờ)</td>
                            <td><?php echo $hourly_next ? '✅ Đang hoạt động' : '❌ Chưa kích hoạt'; ?></td>
                            <td><?php echo $hourly_next ? wp_date('Y-m-d H:i:s', $hourly_next) : '-'; ?></td>
                        </tr>
                        <tr>
                            <td>📊 Báo cáo hàng ngày</td>
                            <td><?php echo $daily_next ? '✅ Đang hoạt động' : '❌ Chưa kích hoạt'; ?></td>
                            <td><?php echo $daily_next ? wp_date('Y-m-d H:i:s', $daily_next) : '-'; ?></td>
                        </tr>
                </table>
            </div>

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
                                if (line.indexOf('ℹ️') !== -1) style = 'color: #88c0d0;'; // Info color
                                if (line.indexOf('💡') !== -1) style = 'color: #e5e9f0; font-style: italic;'; // Suggestion
                                
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

// Test functions
function tkgadm_send_test_email() {
    $emails = get_option('tkgadm_notification_emails', '');
    if (empty($emails)) return false;
    
    // Hỗ trợ cả dấu phẩy và xuống dòng
    $email_list = array_filter(array_map('trim', preg_split('/[,\n\r]+/', $emails)));
    $subject = '🧪 Test Email - GAds Toolkit';
    $message = "Đây là email test từ GAds Toolkit.\n\nNếu bạn nhận được email này, cấu hình email đã hoạt động!";
    
    return wp_mail($email_list, $subject, $message);
}

function tkgadm_send_test_telegram() {
    $token = get_option('tkgadm_telegram_bot_token', '');
    $chat_id = get_option('tkgadm_telegram_chat_id', '');
    
    if (empty($token) || empty($chat_id)) return false;
    
    $message = "🧪 *Test Telegram - GAds Toolkit*\n\nNếu bạn nhận được tin nhắn này, cấu hình Telegram đã hoạt động!";
    
    return tkgadm_send_telegram_message($message);
}

// ============================================================================
// Test Case Độc Lập (Deep Testing Module)
// ============================================================================

/**
 * Class xử lý test case thông báo với log chi tiết
 */
class TKGADM_Notification_Tester {

    /**
     * Test gửi Email và trả về kết quả chi tiết
     */
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

        // 3. Hook vào PHPMailer để lấy thông tin SMTP
        $phpmailer_info = [];
        add_action('phpmailer_init', function($phpmailer) use (&$phpmailer_info, &$result) {
            $phpmailer_info['mailer'] = $phpmailer->Mailer; // 'smtp', 'mail', 'sendmail'
            
            if ($phpmailer->Mailer === 'smtp') {
                $phpmailer_info['host'] = $phpmailer->Host;
                $phpmailer_info['port'] = $phpmailer->Port;
                $phpmailer_info['secure'] = $phpmailer->SMTPSecure; // 'ssl', 'tls', ''
                $phpmailer_info['auth'] = $phpmailer->SMTPAuth;
                $phpmailer_info['username'] = $phpmailer->Username;
                
                // Enable debug output
                $phpmailer->SMTPDebug = 2; // 0=off, 1=client, 2=client+server
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
                
                // Hiển thị SMTP debug log
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
            
            if (!empty($phpmailer_info) && $phpmailer_info['mailer'] === 'smtp') {
                $result['log'][] = "✅ Email đã được gửi qua SMTP server.";
                $result['log'][] = "💡 Nếu không nhận được email, kiểm tra:";
                $result['log'][] = "   - Thư mục Spam/Junk";
                $result['log'][] = "   - SMTP credentials có đúng không";
                $result['log'][] = "   - Email gửi đi có bị mail server từ chối không (check SMTP debug log ở trên)";
            } else {
                $result['log'][] = "⚠️ Email được gửi qua PHP mail() - không qua SMTP!";
                $result['log'][] = "💡 Khuyến nghị: Cài đặt và cấu hình SMTP plugin để tăng tỷ lệ gửi thành công.";
            }
        } else {
            $result['success'] = false;
            $result['log'][] = "❌ Hàm wp_mail trả về FALSE. Email không được gửi.";
        }

        return $result;
    }

    /**
     * Test gửi Telegram và trả về kết quả chi tiết (bao gồm response từ API)
     */
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

        // Gửi request
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
            
            // Phân tích lỗi phổ biến
            if ($response_code == 401) {
                $result['log'][] = "💡 Gợi ý: Bot Token không đúng.";
            } elseif ($response_code == 400) {
                 $result['log'][] = "💡 Gợi ý: Chat ID sai hoặc Bot chưa được thêm vào Group/chưa Chat với người dùng.";
            }
        }

        return $result;
    }
}

// AJAX Handler cho Deep Test
add_action('wp_ajax_tkgadm_run_deep_test', 'tkgadm_ajax_run_deep_test');
function tkgadm_ajax_run_deep_test() {
    // Check permission
    if (!current_user_can('manage_options')) {
        wp_send_json_error("Không có quyền truy cập.");
    }
    
    // Check nonce
    check_ajax_referer('tkgadm_test_nonce', 'nonce');

    $type = sanitize_text_field($_POST['test_type']);
    $output = [];

    if ($type === 'email') {
        // Lấy value trực tiếp từ form POST nếu có, hoặc fallback về DB
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
