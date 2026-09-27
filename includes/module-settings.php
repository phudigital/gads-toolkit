<?php
/**
 * Module Settings (Gộp Cấu hình Google Ads + Thông báo)
 */

if (!defined('ABSPATH')) exit;

/** Handle OAuth before admin output so redirects also remove single-use codes. */
add_action('admin_init', 'tkgadm_settings_oauth_actions');
function tkgadm_settings_oauth_actions() {
    if (($_GET['page'] ?? '') !== 'tkgad-settings' || !current_user_can('manage_options')) {
        return;
    }
    $return_url = admin_url('admin.php?page=tkgad-settings');
    $notice_key = 'tkgadm_oauth_notice_' . get_current_user_id();
    $pending_key = 'tkgadm_oauth_pending_' . get_current_user_id();
    $error = '';

    if (isset($_POST['tkgadm_connect_oauth'])) {
        check_admin_referer('tkgadm_settings_nonce');
        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        if ($api_key && $api_key !== '**********************') {
            update_option('tkgadm_central_service_api_key', $api_key);
            update_option('tkgadm_gads_api_key', $api_key);
        }
        update_option('tkgadm_gads_customer_id', sanitize_text_field(wp_unslash($_POST['customer_id'] ?? '')));
        update_option('tkgadm_gads_manager_id', sanitize_text_field(wp_unslash($_POST['manager_id'] ?? '')));
        $credentials = tkgadm_get_central_service_credentials();
        if (is_wp_error($credentials)) {
            $error = $credentials->get_error_message();
        } elseif (empty($credentials['client_id']) || empty($credentials['oauth_redirect_uri'])) {
            $error = 'Dịch vụ chưa cấu hình đầy đủ thông tin OAuth.';
        } else {
            $state_nonce = wp_generate_password(32, false);
            set_transient($pending_key, $state_nonce, HOUR_IN_SECONDS);
            // Central Service preserves return_url query parameters in its callback.
            $state = base64_encode(wp_json_encode(array(
                'return_url' => add_query_arg('tkgadm_oauth_state', $state_nonce, $return_url),
                'nonce' => wp_create_nonce('tkgadm_oauth_state'),
                'timestamp' => time(),
            )));
            $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(array(
                'client_id' => $credentials['client_id'],
                'redirect_uri' => $credentials['oauth_redirect_uri'],
                'response_type' => 'code',
                'scope' => 'https://www.googleapis.com/auth/adwords',
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => $state,
            ));
            wp_redirect($auth_url);
            exit;
        }
    } elseif (isset($_GET['code']) || isset($_GET['oauth_error'])) {
        $pending = get_transient($pending_key);
        $state = sanitize_text_field(wp_unslash($_GET['tkgadm_oauth_state'] ?? ''));
        if (!$pending || !hash_equals($pending, $state)) {
            $error = 'Phiên kết nối không hợp lệ hoặc đã hết hạn. Vui lòng kết nối lại.';
        } else {
            delete_transient($pending_key);
            if (isset($_GET['oauth_error'])) {
                $error = 'Google chưa cấp quyền. Vui lòng thử kết nối lại.';
            } else {
                $tokens = tkgadm_exchange_code_via_service(sanitize_text_field(wp_unslash($_GET['code'])));
                if (is_wp_error($tokens)) {
                    $error = $tokens->get_error_message();
                } elseif (empty($tokens['refresh_token'])) {
                    $error = 'Không nhận được refresh token. Vui lòng kết nối lại và cấp quyền Google Ads.';
                } else {
                    update_option('tkgadm_gads_refresh_token', $tokens['refresh_token']);
                }
            }
        }
    } else {
        return;
    }
    set_transient($notice_key, array('error' => $error), 60);
    wp_safe_redirect($return_url);
    exit;
}

function tkgadm_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $message = '';
    $notice_key = 'tkgadm_oauth_notice_' . get_current_user_id();
    $oauth_notice = get_transient($notice_key);
    if (is_array($oauth_notice)) {
        delete_transient($notice_key);
        $message = '<div class="notice ' . ($oauth_notice['error'] ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($oauth_notice['error'] ?: 'Đã kết nối tài khoản Google Ads.') . '</p></div>';
    }
    
    // Xử lý ngắt kết nối Google Ads
    if (isset($_POST['tkgadm_disconnect_oauth']) && check_admin_referer('tkgadm_settings_nonce')) {
        delete_option('tkgadm_gads_refresh_token');
        delete_transient('tkgadm_oauth_pending_' . get_current_user_id());
        $message = '<div class="bg-emerald-50 text-emerald-600 p-3 rounded-lg border border-emerald-200 mb-6 font-medium text-sm">Đã hủy kết nối tài khoản Google Ads.</div>';
    }

    // Xử lý lưu form
    if (isset($_POST['tkgadm_save_settings']) && check_admin_referer('tkgadm_settings_nonce')) {
        // --- Lưu cấu hình Google Ads ---
        $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        if (!empty($api_key)) {
            if ($api_key === '**********************') {
                // Không đổi
            } else {
                update_option('tkgadm_central_service_api_key', $api_key);
                update_option('tkgadm_gads_api_key', $api_key);

                if (function_exists('tkgadm_register_site_heartbeat')) {
                    tkgadm_register_site_heartbeat($api_key);
                }
            }
        }
        update_option('tkgadm_gads_customer_id', isset($_POST['customer_id']) ? sanitize_text_field(wp_unslash($_POST['customer_id'])) : '');
        update_option('tkgadm_gads_manager_id', isset($_POST['manager_id']) ? sanitize_text_field(wp_unslash($_POST['manager_id'])) : '');
        
        $auto_sync = isset($_POST['auto_sync']) ? 1 : 0;
        update_option('tkgadm_auto_sync_hourly', $auto_sync);
        update_option('tkgadm_auto_sync', (string) $auto_sync);
        
        $sync_on_block = isset($_POST['sync_on_block']) ? 1 : 0;
        update_option('tkgadm_auto_sync_on_block', $sync_on_block);
        update_option('tkgadm_sync_on_block', (string) $sync_on_block);

        $sync_timestamp = wp_next_scheduled('tkgadm_hourly_sync_event');
        if ($auto_sync && !$sync_timestamp) {
            wp_schedule_event(time(), 'hourly', 'tkgadm_hourly_sync_event');
        } elseif (!$auto_sync && $sync_timestamp) {
            wp_unschedule_event($sync_timestamp, 'tkgadm_hourly_sync_event');
        }
        
        // Rules
        $rules = [];
        if (isset($_POST['rules']) && is_array($_POST['rules'])) {
            foreach ($_POST['rules'] as $rule) {
                if (!empty($rule['limit']) && !empty($rule['duration'])) {
                    $rules[] = [
                        'limit' => intval($rule['limit']),
                        'duration' => intval($rule['duration']),
                        'unit' => sanitize_text_field($rule['unit'])
                    ];
                }
            }
        }
        update_option('tkgadm_auto_block_rules', $rules);
        $auto_block_enabled = !empty($rules) ? '1' : '0';
        update_option('tkgadm_auto_block_enabled', $auto_block_enabled);

        $block_timestamp = wp_next_scheduled('tkgadm_auto_block_scan_event');
        if (!empty($rules) && !$block_timestamp) {
            wp_schedule_event(time(), 'tkgadm_15_minutes', 'tkgadm_auto_block_scan_event');
        } elseif (empty($rules) && $block_timestamp) {
            wp_unschedule_event($block_timestamp, 'tkgadm_auto_block_scan_event');
        }

        // --- Lưu cấu hình Notifications ---
        update_option('tkgadm_notification_emails', isset($_POST['notification_emails']) ? sanitize_textarea_field(wp_unslash($_POST['notification_emails'])) : '');
        update_option('tkgadm_telegram_bot_token', isset($_POST['telegram_bot_token']) ? sanitize_text_field(wp_unslash($_POST['telegram_bot_token'])) : '');
        update_option('tkgadm_telegram_chat_id', isset($_POST['telegram_chat_id']) ? sanitize_text_field(wp_unslash($_POST['telegram_chat_id'])) : '');
        
        update_option('tkgadm_alert_threshold', isset($_POST['alert_threshold']) ? intval($_POST['alert_threshold']) : 5);
        update_option('tkgadm_alert_frequency', isset($_POST['alert_frequency']) ? sanitize_text_field($_POST['alert_frequency']) : 'hourly');
        
        update_option('tkgadm_enable_daily_reports', isset($_POST['enable_daily_reports']) ? '1' : '0');
        update_option('tkgadm_daily_report_time', isset($_POST['daily_report_time']) ? sanitize_text_field($_POST['daily_report_time']) : '08:00');
        
        $message = '<div class="bg-emerald-50 text-emerald-600 p-3 rounded-lg border border-emerald-200 mb-6 font-medium text-sm">Đã lưu cấu hình thành công!</div>';
    }

    // Lấy dữ liệu hiện tại
    $saved_api_key = get_option('tkgadm_central_service_api_key', '');
    if (empty($saved_api_key)) {
        $saved_api_key = get_option('tkgadm_gads_api_key', '');
    }
    $api_key_hidden = $saved_api_key ? '**********************' : '';
    $customer_id = get_option('tkgadm_gads_customer_id', '');
    $manager_id = get_option('tkgadm_gads_manager_id', '');
    $refresh_token = get_option('tkgadm_gads_refresh_token', '');
    $auto_sync = get_option('tkgadm_auto_sync_hourly', get_option('tkgadm_auto_sync', '0'));
    $sync_on_block = get_option('tkgadm_auto_sync_on_block', get_option('tkgadm_sync_on_block', '1'));
    $rules = get_option('tkgadm_auto_block_rules', []);
    $can_upload_blocked_ips = !empty($refresh_token) && !empty($customer_id);

    $emails = get_option('tkgadm_notification_emails', get_option('admin_email'));
    $bot_token = get_option('tkgadm_telegram_bot_token', '');
    $chat_id = get_option('tkgadm_telegram_chat_id', '');
    $threshold = get_option('tkgadm_alert_threshold', 5);
    $frequency = get_option('tkgadm_alert_frequency', 'hourly');
    $daily_reports = get_option('tkgadm_enable_daily_reports', '1');
    $report_time = get_option('tkgadm_daily_report_time', '08:00');

    ?>
    <div class="wp-wrap tkgadm-settings-page space-y-6" style="padding: 20px 0;">
        <form method="POST" action="">
            <?php wp_nonce_field('tkgadm_settings_nonce'); ?>
            <div class="space-y-6">
                
                <?php echo $message; ?>

                <!-- Header -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2 m-0 pb-1">
                            <i class="fa-solid fa-gear text-blue-600"></i> Cấu Hình & Tích Hợp
                        </h1>
                        <p class="text-sm text-gray-500 m-0">Cấu hình đồng bộ Google Ads, chặn tự động và nhận thông báo.</p>
                    </div>
                    <button type="submit" name="tkgadm_save_settings" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-5 rounded-lg transition shadow-sm flex items-center gap-2 border-none cursor-pointer">
                        <i class="fa-regular fa-floppy-disk"></i> Lưu Cấu Hình
                    </button>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    
                    <!-- COL 1: GOOGLE ADS -->
                    <div class="space-y-6">
                        <!-- Section: Google Ads API -->
                        <div class="tkgadm-account-card bg-white rounded-xl shadow-sm border border-gray-100 p-6 border-t-4 border-t-blue-500">
                            <div class="tkgadm-account-header">
                                <h3><img src="https://upload.wikimedia.org/wikipedia/commons/c/c7/Google_Ads_logo.svg" alt="" width="20" height="20"> Tài khoản Google Ads</h3>
                                <span class="tkgadm-account-status <?php echo $refresh_token ? 'is-connected' : 'is-disconnected'; ?>">
                                    <span aria-hidden="true">●</span> <?php echo $refresh_token ? 'Đã kết nối' : 'Chưa kết nối'; ?>
                                </span>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <label for="api-key-field" class="block text-sm font-medium text-gray-700 mb-1">Secure API Key</label>
                                    <div class="relative">
                                        <input type="password" name="api_key" id="api-key-field" value="<?php echo esc_attr($api_key_hidden); ?>" <?php echo $saved_api_key ? 'readonly' : ''; ?> class="w-full text-sm bg-gray-50 border border-gray-300 rounded-lg p-2 text-gray-600 focus:outline-none" placeholder="Nhập API Key">
                                        <button type="button" id="edit-api-key" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-blue-600 hover:underline border-none bg-transparent cursor-pointer <?php echo $saved_api_key ? '' : 'hidden'; ?>">Chỉnh sửa</button>
                                    </div>
                                </div>
                                <div class="tkgadm-account-ids">
                                    <div>
                                        <label for="gads-customer-id" class="block text-sm font-medium text-gray-700 mb-1">Customer ID</label>
                                        <input type="text" id="gads-customer-id" name="customer_id" value="<?php echo esc_attr($customer_id); ?>" class="w-full text-sm border border-gray-300 rounded-lg p-2 text-gray-800 focus:ring-2 focus:ring-blue-500 focus:outline-none placeholder:text-gray-400" placeholder="123-456-7890">
                                    </div>
                                    <div>
                                        <label for="gads-manager-id" class="block text-sm font-medium text-gray-700 mb-1">Manager ID (MCC)</label>
                                        <input type="text" id="gads-manager-id" name="manager_id" value="<?php echo esc_attr($manager_id); ?>" placeholder="Trống nếu không dùng" class="w-full text-sm border border-gray-300 rounded-lg p-2 text-gray-800 focus:ring-2 focus:ring-blue-500 focus:outline-none placeholder:text-gray-400">
                                    </div>
                                </div>
                                <div class="tkgadm-account-footer">
                                    <div class="tkgadm-account-actions">
                                        <button type="submit" name="tkgadm_connect_oauth" class="tkgadm-account-connect"><i class="fa-solid fa-link" aria-hidden="true"></i> <?php echo $refresh_token ? 'Kết nối lại tài khoản' : 'Kết nối tài khoản'; ?></button>
                                        <?php if ($refresh_token): ?>
                                            <button type="submit" name="tkgadm_disconnect_oauth" class="tkgadm-account-disconnect"><i class="fa-solid fa-link-slash" aria-hidden="true"></i> Hủy kết nối</button>
                                        <?php endif; ?>
                                    </div>
                                    <p class="tkgadm-account-help"><?php echo $refresh_token ? 'Kết nối lại để đổi tài khoản Google hoặc cấp lại quyền truy cập.' : 'Kết nối để cấp quyền truy cập Google Ads.'; ?> Thông tin tài khoản ở trên sẽ được lưu khi kết nối.</p>
                                    <p class="tkgadm-account-note">Các thiết lập khác: bấm <strong>Lưu Cấu Hình</strong> ở đầu trang.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Manual IP Upload -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 border-t-4 border-t-sky-500">
                            <h3 class="text-lg font-bold text-gray-800 mb-2 flex items-center gap-2 m-0 pb-1">
                                <i class="fa-solid fa-cloud-arrow-up text-sky-500"></i> Upload IP lên Google Ads
                            </h3>
                            <p class="text-xs text-gray-500 mt-0 mb-4">Gửi toàn bộ IP đang có trong danh sách bị chặn lên tài khoản Google Ads đã kết nối.</p>
                            <button type="button" id="btn-upload-blocked-ips" class="bg-sky-600 hover:bg-sky-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white text-sm font-medium py-2 px-4 rounded-lg transition border-none cursor-pointer flex items-center gap-2" <?php disabled(!$can_upload_blocked_ips); ?>>
                                <i class="fa-solid fa-cloud-arrow-up"></i> Upload toàn bộ IP bị chặn
                            </button>
                            <?php if (!$can_upload_blocked_ips): ?>
                                <p class="text-xs text-amber-600 mt-3 mb-0">Cần kết nối Google Ads và nhập Customer ID trước khi upload.</p>
                            <?php endif; ?>
                            <div id="blocked-ips-upload-status" class="hidden mt-3 p-3 rounded-lg text-xs" role="status" aria-live="polite"></div>
                        </div>

                        <!-- Section: Auto Block & Sync -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2 m-0 pb-2">
                                <i class="fa-solid fa-shield-virus text-indigo-500"></i> Chặn & Đồng Bộ
                            </h3>

                            <div class="space-y-4">
                                <!-- Auto Sync -->
                                <div class="flex items-start justify-between border-b border-gray-100 pb-4">
                                    <div>
                                        <p class="text-sm font-medium text-gray-800 m-0">Đồng bộ tự động</p>
                                        <p class="text-xs text-gray-500 mt-0.5 mb-0">Tự động đồng bộ IP lên GAds mỗi giờ</p>
                                    </div>
                                    <div class="tkgadm-switch mt-1 mr-2">
                                        <input type="checkbox" name="auto_sync" id="toggle1" <?php checked($auto_sync, '1'); ?> class="tkgadm-switch__input" aria-label="Bật đồng bộ tự động" />
                                        <label for="toggle1" class="tkgadm-switch__track"></label>
                                    </div>
                                </div>

                                <!-- Sync on block -->
                                <div class="flex items-start justify-between border-b border-gray-100 pb-4">
                                    <div>
                                        <p class="text-sm font-medium text-gray-800 m-0">Đồng bộ ngay khi chặn</p>
                                        <p class="text-xs text-gray-500 mt-0.5 mb-0">Gửi IP lên Google Ads ngay khi IP bị chặn thủ công/tự động</p>
                                    </div>
                                    <div class="tkgadm-switch mt-1 mr-2">
                                        <input type="checkbox" name="sync_on_block" id="toggle2" <?php checked($sync_on_block, '1'); ?> class="tkgadm-switch__input" aria-label="Bật đồng bộ ngay khi chặn" />
                                        <label for="toggle2" class="tkgadm-switch__track"></label>
                                    </div>
                                </div>

                                <!-- Rules -->
                                <div class="pt-2">
                                    <div class="flex justify-between items-center mb-3">
                                        <p class="text-sm font-medium text-gray-800 m-0">Quy tắc chặn tự động</p>
                                        <button type="button" onclick="addRule()" class="text-xs bg-indigo-50 text-indigo-600 px-2 py-1 rounded font-medium hover:bg-indigo-100 border-none cursor-pointer"><i class="fa-solid fa-plus"></i> Thêm luật</button>
                                    </div>
                                    <div id="rules-container" class="bg-gray-50 p-3 rounded-lg border border-gray-200 space-y-2">
                                        <?php if(empty($rules)): ?>
                                            <p class="text-sm text-gray-500 italic" id="no-rules-msg">Chưa có quy tắc nào.</p>
                                        <?php else: ?>
                                            <?php foreach ($rules as $index => $rule): ?>
                                                <div class="rule-row flex items-center gap-2 text-sm bg-white p-2 rounded border border-gray-100 shadow-sm">
                                                    <span class="text-gray-500">Đạt</span>
                                                    <input type="number" name="rules[<?php echo $index; ?>][limit]" value="<?php echo esc_attr($rule['limit']); ?>" class="tkgadm-rule-control w-14 border border-gray-300 rounded p-1 text-center font-semibold focus:ring-1 focus:ring-blue-500 outline-none">
                                                    <span class="text-gray-500">click Ads trong</span>
                                                    <input type="number" name="rules[<?php echo $index; ?>][duration]" value="<?php echo esc_attr($rule['duration']); ?>" class="tkgadm-rule-control w-14 border border-gray-300 rounded p-1 text-center font-semibold focus:ring-1 focus:ring-blue-500 outline-none">
                                                    <select name="rules[<?php echo $index; ?>][unit]" class="tkgadm-rule-control border border-gray-300 rounded p-1 text-gray-700 bg-white text-sm max-w-[100px]">
                                                        <option value="HOUR" <?php selected($rule['unit'], 'HOUR'); ?>>Giờ</option>
                                                        <option value="DAY" <?php selected($rule['unit'], 'DAY'); ?>>Ngày</option>
                                                        <option value="WEEK" <?php selected($rule['unit'], 'WEEK'); ?>>Tuần</option>
                                                    </select>
                                                    <button type="button" onclick="this.parentElement.remove()" class="ml-auto text-red-400 hover:text-red-600 border-none bg-transparent cursor-pointer"><i class="fa-solid fa-trash-can"></i></button>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- COL 2: NOTIFICATIONS -->
                    <div class="space-y-6">
                        <!-- Section: Notification Configs -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 border-t-4 border-t-emerald-500 relative">
                            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2 m-0 pb-2">
                                <i class="fa-solid fa-bell text-emerald-500"></i> Kênh Thông Báo
                            </h3>
                            
                            <div class="space-y-5">
                                <!-- Email Config -->
                                <div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <i class="fa-regular fa-envelope text-gray-400"></i>
                                        <label class="text-sm font-medium text-gray-700">Gửi qua Email</label>
                                    </div>
                                    <input type="text" name="notification_emails" value="<?php echo esc_attr($emails); ?>" class="w-full text-sm border border-gray-300 rounded-lg p-2 text-gray-800 focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="Nhập các email cách nhau dấu phẩy">
                                </div>

                                <!-- Telegram Config -->
                                <div class="bg-blue-50/30 p-4 rounded-lg border border-blue-50">
                                    <div class="flex items-center gap-2 mb-3">
                                        <i class="fa-brands fa-telegram text-blue-500"></i>
                                        <label class="text-sm font-medium text-blue-800">Gửi qua Telegram Bot</label>
                                    </div>
                                    <div class="space-y-3">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">Bot Token</label>
                                            <input type="text" name="telegram_bot_token" value="<?php echo esc_attr($bot_token); ?>" class="w-full text-sm border border-gray-300 rounded p-2 text-gray-800 focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono placeholder:text-gray-400" placeholder="123456:ABC-DEF...">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">Chat ID</label>
                                            <input type="text" name="telegram_chat_id" value="<?php echo esc_attr($chat_id); ?>" class="w-full text-sm border border-gray-300 rounded p-2 text-gray-800 focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono placeholder:text-gray-400" placeholder="-10012345678">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Alert Rules -->
                        <div class="tkgadm-report-card bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <h3 class="tkgadm-report-title text-lg font-bold text-gray-800 mb-4 flex items-center gap-2 m-0 pb-2">
                                <i class="fa-solid fa-tower-broadcast text-orange-500"></i> Lịch Gửi & Báo Cáo
                            </h3>
                            
                            <div class="tkgadm-report-content space-y-4">
                                <!-- Alert Threshold -->
                                <div class="tkgadm-report-row tkgadm-report-alert-row flex items-start justify-between border-b border-gray-100 pb-4">
                                    <div>
                                        <p class="tkgadm-report-label text-sm font-medium text-gray-800 m-0">Cảnh báo IP nghi ngờ</p>
                                        <p class="tkgadm-report-description text-xs text-gray-500 mt-1 mb-0 flex items-center gap-1">
                                            Khi có IP đạt 
                                            <input type="number" name="alert_threshold" value="<?php echo esc_attr($threshold); ?>" class="tkgadm-alert-threshold w-12 border border-gray-300 rounded text-center text-xs py-0.5 focus:outline-none">
                                            click ads nhưng chưa bị chặn
                                        </p>
                                    </div>
                                    <select name="alert_frequency" class="tkgadm-alert-frequency text-sm border border-gray-300 rounded p-1.5 focus:outline-none text-gray-700 bg-white shadow-sm mt-1">
                                        <option value="hourly" <?php selected($frequency, 'hourly'); ?>>Kiểm tra mỗi giờ</option>
                                        <option value="twice_daily" <?php selected($frequency, 'twice_daily'); ?>>2 lần/ngày</option>
                                        <option value="daily" <?php selected($frequency, 'daily'); ?>>Mỗi ngày</option>
                                    </select>
                                </div>

                                <!-- Daily Report -->
                                <div class="tkgadm-report-row tkgadm-report-daily-row flex items-start justify-between">
                                    <div>
                                        <p class="tkgadm-report-label text-sm font-medium text-gray-800 m-0">Báo cáo traffic tổng hợp</p>
                                        <p class="tkgadm-report-description text-xs text-gray-500 mt-1 mb-0">Gửi số liệu tóm tắt của ngày hôm trước</p>
                                    </div>
                                    <div class="tkgadm-report-actions flex flex-col items-end gap-2 mt-1">
                                        <div class="tkgadm-report-toggle">
                                            <input type="checkbox" name="enable_daily_reports" id="toggle3" <?php checked($daily_reports, '1'); ?> class="tkgadm-report-toggle__input" aria-label="Bật báo cáo traffic tổng hợp" />
                                            <label for="toggle3" class="tkgadm-report-toggle__track"></label>
                                        </div>
                                        <div class="tkgadm-report-time flex items-center gap-1 text-xs text-gray-600 bg-gray-100 px-2 py-1 rounded">
                                            Lần gửi: <input type="time" name="daily_report_time" value="<?php echo esc_attr($report_time); ?>" class="tkgadm-inline-time bg-transparent border-none font-medium focus:ring-0 text-xs w-16">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Deep Test Module -->
                        <div class="bg-gray-800 rounded-xl shadow-sm p-4 text-white">
                            <h3 class="text-sm font-bold mb-3 flex items-center gap-2 m-0 pb-1">
                                <i class="fa-solid fa-flask text-purple-400"></i> Debug Mode: Test Connection
                            </h3>
                            <div class="flex gap-2">
                                <button type="button" id="btn-test-email" class="bg-gray-700 hover:bg-gray-600 border border-gray-600 text-xs font-medium py-1.5 px-3 rounded transition flex items-center gap-1 cursor-pointer">
                                    <i class="fa-regular fa-envelope"></i> Test Email
                                </button>
                                <button type="button" id="btn-test-telegram" class="bg-blue-600 hover:bg-blue-500 text-white border-none text-xs font-medium py-1.5 px-3 rounded transition flex items-center gap-1 cursor-pointer">
                                    <i class="fa-brands fa-telegram"></i> Test Telegram
                                </button>
                            </div>
                            <div id="test-result" class="mt-2 text-xs hidden p-2 rounded"></div>
                        </div>

                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
    let ruleIndex = <?php echo empty($rules) ? 0 : count($rules); ?>;
    function addRule() {
        const container = document.getElementById('rules-container');
        const noRulesMsg = document.getElementById('no-rules-msg');
        if (noRulesMsg) noRulesMsg.remove();
        
        const html = `
            <div class="rule-row flex items-center gap-2 text-sm bg-white p-2 rounded border border-gray-100 shadow-sm">
                <span class="text-gray-500">Đạt</span>
                <input type="number" name="rules[${ruleIndex}][limit]" value="5" class="tkgadm-rule-control w-14 border border-gray-300 rounded p-1 text-center font-semibold focus:ring-1 focus:ring-blue-500 outline-none">
                <span class="text-gray-500">click Ads trong</span>
                <input type="number" name="rules[${ruleIndex}][duration]" value="1" class="tkgadm-rule-control w-14 border border-gray-300 rounded p-1 text-center font-semibold focus:ring-1 focus:ring-blue-500 outline-none">
                <select name="rules[${ruleIndex}][unit]" class="tkgadm-rule-control border border-gray-300 rounded p-1 text-gray-700 bg-white text-sm max-w-[100px]">
                    <option value="HOUR">Giờ</option>
                    <option value="DAY">Ngày</option>
                    <option value="WEEK">Tuần</option>
                </select>
                <button type="button" onclick="this.parentElement.remove()" class="ml-auto text-red-400 hover:text-red-600 border-none bg-transparent cursor-pointer"><i class="fa-solid fa-trash-can"></i></button>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', html);
        ruleIndex++;
    }

    jQuery(document).ready(function($) {
        $('#edit-api-key').on('click', function() {
            $('#api-key-field').prop('readonly', false).val('').removeClass('bg-gray-50 text-gray-600').addClass('bg-white text-gray-800').focus();
            $(this).hide();
        });

        $('#btn-test-email').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Đang test...');
            $('#test-result').removeClass('hidden bg-green-900 bg-red-900 text-green-100 text-red-100').text('Đang gửi...');
            
            $.post(ajaxurl, {
                action: 'tkgadm_test_email_connection',
                nonce: tkgadm_vars.nonce
            }, function(res) {
                btn.prop('disabled', false).html('<i class="fa-regular fa-envelope"></i> Test Email');
                if (res.success) {
                    $('#test-result').addClass('bg-green-900 text-green-100').text('Thành công! Kiểm tra hộp thư.');
                } else {
                    $('#test-result').addClass('bg-red-900 text-red-100').text('Lỗi: ' + res.data);
                }
            }).fail(function() {
                btn.prop('disabled', false).html('<i class="fa-regular fa-envelope"></i> Test Email');
                $('#test-result').addClass('bg-red-900 text-red-100').text('Không thể kết nối máy chủ.');
            });
        });

        $('#btn-test-telegram').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Đang test...');
            $('#test-result').removeClass('hidden bg-green-900 bg-red-900 text-green-100 text-red-100').text('Đang gửi...');
            
            $.post(ajaxurl, {
                action: 'tkgadm_test_telegram_connection',
                nonce: tkgadm_vars.nonce
            }, function(res) {
                btn.prop('disabled', false).html('<i class="fa-brands fa-telegram"></i> Test Telegram');
                if (res.success) {
                    $('#test-result').addClass('bg-green-900 text-green-100').text(res.data || 'Thành công! Kiểm tra Telegram.');
                } else {
                    $('#test-result').addClass('bg-red-900 text-red-100').text('Lỗi: ' + res.data);
                }
            }).fail(function() {
                btn.prop('disabled', false).html('<i class="fa-brands fa-telegram"></i> Test Telegram');
                $('#test-result').addClass('bg-red-900 text-red-100').text('Không thể kết nối máy chủ.');
            });
        });

        $('#btn-upload-blocked-ips').on('click', function() {
            var btn = $(this);
            var status = $('#blocked-ips-upload-status');
            var originalHtml = '<i class="fa-solid fa-cloud-arrow-up"></i> Upload toàn bộ IP bị chặn';

            if (!confirm('Bạn có chắc muốn upload toàn bộ IP đang bị chặn lên Google Ads không?')) {
                return;
            }

            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Đang upload...');
            status.removeClass('hidden bg-emerald-50 text-emerald-700 border border-emerald-200 bg-red-50 text-red-700 border-red-200').addClass('bg-sky-50 text-sky-700 border border-sky-200').text('Đang lấy danh sách IP và gửi lên Google Ads...');

            $.post(ajaxurl, {
                action: 'tkgadm_manual_sync_gads',
                nonce: '<?php echo esc_js(wp_create_nonce("tkgadm_sync_gads")); ?>'
            }, function(res) {
                btn.prop('disabled', false).html(originalHtml);
                status.removeClass('bg-sky-50 text-sky-700 border-sky-200');

                if (res.success) {
                    status.addClass('bg-emerald-50 text-emerald-700 border border-emerald-200').text(res.data.message || 'Đã upload danh sách IP thành công.');
                } else {
                    status.addClass('bg-red-50 text-red-700 border border-red-200').text('Lỗi: ' + (res.data || 'Không thể upload danh sách IP.'));
                }
            }).fail(function() {
                btn.prop('disabled', false).html(originalHtml);
                status.removeClass('bg-sky-50 text-sky-700 border-sky-200').addClass('bg-red-50 text-red-700 border border-red-200').text('Lỗi kết nối máy chủ.');
            });
        });
    });
    </script>
    <?php
}
