<?php
/**
 * Admin Screen: Cấu hình Google Ads
 */

if (!defined('ABSPATH')) exit;

function tkgadm_render_google_ads_page() {
    // 1. Handle OAuth Callback
    if (isset($_GET['code'])) {
        $code = sanitize_text_field($_GET['code']);
        $client_id = get_option('tkgadm_gads_client_id');
        $client_secret = get_option('tkgadm_gads_client_secret');
        
        if ($client_id && $client_secret) {
            $token_url = 'https://oauth2.googleapis.com/token';
            $body = array(
                'code' => $code,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri' => admin_url('admin.php?page=tkgad-google-ads'),
                'grant_type' => 'authorization_code'
            );
            
            $response = wp_remote_post($token_url, array('body' => $body));
            
            if (is_wp_error($response)) {
                echo '<div class="notice notice-error"><p>Lỗi kết nối Google: ' . $response->get_error_message() . '</p></div>';
            } else {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($data['refresh_token'])) {
                    update_option('tkgadm_gads_refresh_token', $data['refresh_token']);
                    echo '<div class="notice notice-success is-dismissible"><p>✅ Đã kết nối thành công với tài khoản Google!</p></div>';
                } elseif (isset($data['error'])) {
                    echo '<div class="notice notice-error"><p>Lỗi OAuth: ' . ($data['error_description'] ?? $data['error']) . '</p></div>';
                }
            }
        }
    }

    // 2. Save Settings
    if (isset($_POST['tkgadm_gads_save']) && check_admin_referer('tkgadm_gads_options')) {
        update_option('tkgadm_gads_client_id', sanitize_text_field($_POST['client_id']));
        update_option('tkgadm_gads_client_secret', sanitize_text_field($_POST['client_secret']));
        update_option('tkgadm_gads_developer_token', sanitize_text_field($_POST['developer_token']));
        update_option('tkgadm_gads_customer_id', sanitize_text_field($_POST['customer_id']));
        update_option('tkgadm_gads_manager_id', sanitize_text_field($_POST['manager_id']));
        
        $auto_sync = isset($_POST['auto_sync']) ? 1 : 0;
        update_option('tkgadm_auto_sync_hourly', $auto_sync);
        
        $sync_on_block = isset($_POST['sync_on_block']) ? 1 : 0;
        update_option('tkgadm_auto_sync_on_block', $sync_on_block);
        
        // Handle Cron Schedule
        $timestamp = wp_next_scheduled('tkgadm_hourly_sync_event');
        if ($auto_sync && !$timestamp) {
            wp_schedule_event(time(), 'hourly', 'tkgadm_hourly_sync_event');
        } elseif (!$auto_sync && $timestamp) {
            wp_unschedule_event($timestamp, 'tkgadm_hourly_sync_event');
        }

        echo '<div class="notice notice-success is-dismissible"><p>Đã lưu cài đặt.</p></div>';
    }

    // 3. Prepare Data
    $client_id = get_option('tkgadm_gads_client_id');
    $client_secret = get_option('tkgadm_gads_client_secret');
    $refresh_token = get_option('tkgadm_gads_refresh_token'); // Hidden, managed via OAuth
    $developer_token = get_option('tkgadm_gads_developer_token');
    $customer_id = get_option('tkgadm_gads_customer_id');
    $manager_id = get_option('tkgadm_gads_manager_id');
    $auto_sync = get_option('tkgadm_auto_sync_hourly');
    $sync_on_block = get_option('tkgadm_auto_sync_on_block');
    
    // Auth URL
    $auth_url = '';
    if ($client_id) {
        $redirect_uri = admin_url('admin.php?page=tkgad-google-ads');
        $params = array(
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/adwords',
            'access_type' => 'offline',
            'prompt' => 'consent'
        );
        $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
    ?>

    <div class="wrap">
        <div class="tkgadm-wrap">
            <div class="tkgadm-header">
                <h1>🔌 Cấu hình Đồng Bộ Google Ads</h1>
                <p style="color: #666; margin-top: 10px;">Kết nối API để tự động đẩy IP bị chặn vào danh sách loại trừ cấp tài khoản Google Ads.</p>
            </div>

            <div class="tkgadm-main-content" style="display: flex; gap: 20px; flex-wrap: wrap;">
                
                <!-- Settings Form -->
                <div style="flex: 2; min-width: 300px;">
                    <form method="post" action="" style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #ddd;">
                        <?php wp_nonce_field('tkgadm_gads_options'); ?>
                        
                        <h2 style="margin-top: 0; margin-bottom: 20px;">🔑 Thiết lập API</h2>
                        
                        <!-- Step 1: Client ID/Secret -->
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: block; font-weight: 500; margin-bottom: 5px;">Client ID</label>
                            <input type="text" name="client_id" value="<?php echo esc_attr($client_id); ?>" class="widefat" style="padding: 8px;">
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: block; font-weight: 500; margin-bottom: 5px;">Client Secret</label>
                            <input type="password" name="client_secret" value="<?php echo esc_attr($client_secret); ?>" class="widefat" style="padding: 8px;">
                        </div>
                        
                         <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: block; font-weight: 500; margin-bottom: 5px;">Developer Token</label>
                            <input type="text" name="developer_token" value="<?php echo esc_attr($developer_token); ?>" class="widefat" style="padding: 8px;">
                        </div>

                         <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: block; font-weight: 500; margin-bottom: 5px;">Customer ID (Target Account)</label>
                            <input type="text" name="customer_id" value="<?php echo esc_attr($customer_id); ?>" class="widefat" placeholder="xxx-xxx-xxxx" style="padding: 8px;">
                            <p class="description">ID tài khoản Google Ads bạn muốn chặn IP vào.</p>
                        </div>

                         <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: block; font-weight: 500; margin-bottom: 5px;">Manager Account ID (Nếu dùng MCC)</label>
                            <input type="text" name="manager_id" value="<?php echo esc_attr($manager_id); ?>" class="widefat" placeholder="xxx-xxx-xxxx" style="padding: 8px;">
                            <p class="description">Nếu bạn đăng nhập bằng tài khoản MCC, hãy nhập ID của MCC vào đây (Login-Customer-Id). Nếu dùng tài khoản thường thì để trống.</p>
                        </div>
                        
                        <div style="margin-top: 20px; margin-bottom: 30px;">
                            <button type="submit" name="tkgadm_gads_save" class="button button-primary" style="padding: 5px 20px;">Lưu Thông Tin</button>
                        </div>

                        <hr style="margin: 25px 0; border: 0; border-top: 1px solid #eee;">
                        
                        <!-- Step 2: Connect Google -->
                        <h2 style="margin-bottom: 15px;">🔗 Kết nối Google Ads</h2>
                        
                        <?php if ($refresh_token): ?>
                            <div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px; color: #155724; margin-bottom: 15px;">
                                <strong>✅ Đã kết nối thành công!</strong>
                                <p style="margin: 5px 0 0;">Plugin đã có quyền truy cập API của bạn.</p>
                            </div>
                            <?php if ($auth_url): ?>
                                <a href="<?php echo esc_url($auth_url); ?>" class="button button-secondary">Kết nối lại (Re-connect)</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($client_id): ?>
                                <p>Vui lòng thêm Redirect URI này vào Console Google Cloud của bạn:</p>
                                <code style="display: block; padding: 10px; background: #f0f0f1; margin-bottom: 15px; overflow-wrap: break-word;">
                                    <?php echo esc_url(admin_url('admin.php?page=tkgad-google-ads')); ?>
                                </code>
                                <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary" style="font-size: 14px; padding: 5px 15px;">
                                    👉 Kết nối tài khoản Google
                                </a>
                            <?php else: ?>
                                <p style="color: #cc0000;">⚠️ Vui lòng nhập Client ID và lưu lại trước khi kết nối.</p>
                            <?php endif; ?>
                        <?php endif; ?>

                        <hr style="margin: 25px 0; border: 0; border-top: 1px solid #eee;">
                        
                        <h2 style="margin-bottom: 20px;">⚙️ Tùy chọn Đồng bộ</h2>
                        
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="auto_sync" value="1" <?php checked($auto_sync, 1); ?>>
                                <span style="font-weight: 500;">Tự động đồng bộ mỗi giờ (Cron Job)</span>
                            </label>
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="sync_on_block" value="1" <?php checked($sync_on_block, 1); ?>>
                                <span style="font-weight: 500;">Đồng bộ ngay khi phát hiện nghi vấn</span>
                            </label>
                        </div>
                        
                         <div style="margin-top: 20px;">
                            <button type="submit" name="tkgadm_gads_save" class="button button-secondary">Cập nhật tùy chọn</button>
                        </div>
                    </form>
                </div>

                <!-- Sync Action & Status -->
                <div style="flex: 1; min-width: 250px;">
                    <div style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #ddd; position: sticky; top: 50px;">
                        <h3 style="margin-top: 0;">🚀 Thao tác nhanh</h3>
                        
                        <button id="manual-sync-btn" class="button button-secondary" style="width: 100%; text-align: center; margin-bottom: 15px; padding: 10px;" <?php disabled(!$refresh_token); ?>>
                            🔄 Đồng bộ ngay
                        </button>
                        
                        <?php if (!$refresh_token): ?>
                            <p style="color: red; font-size: 13px;">* Cần kết nối Google trước khi đồng bộ.</p>
                        <?php endif; ?>

                        <div id="sync-status" style="display: none; padding: 15px; background: #f8f9fa; border-radius: 5px; border: 1px solid #eee;">
                            <div id="sync-spinner" style="display: none; text-align: center;">
                                <span class="spinner is-active" style="float: none;"></span> Đang xử lý...
                            </div>
                            <div id="sync-message"></div>
                        </div>

                        <hr>
                        
                        <h4>🔍 Trạng thái gần nhất</h4>
                        <?php
                            $last_sync = get_option('tkgadm_last_sync_time');
                            $last_msg = get_option('tkgadm_last_sync_message');
                        ?>
                        <p><strong>Lần chạy cuối:</strong> <?php echo $last_sync ? date_i18n('d/m/Y H:i:s', $last_sync) : 'Chưa chạy'; ?></p>
                        <p><strong>Kết quả:</strong> <?php echo $last_msg ? esc_html($last_msg) : '---'; ?></p>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#manual-sync-btn').on('click', function(e) {
            e.preventDefault();
            const btn = $(this);
            const statusBox = $('#sync-status');
            const spinner = $('#sync-spinner');
            const msg = $('#sync-message');

            if (confirm('Bạn có chắc muốn đồng bộ danh sách IP lên Google Ads ngay không?')) {
                btn.prop('disabled', true);
                statusBox.show();
                spinner.show();
                msg.text('');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'tkgadm_manual_sync_gads',
                        nonce: '<?php echo wp_create_nonce("tkgadm_sync_gads"); ?>'
                    },
                    success: function(response) {
                        spinner.hide();
                        btn.prop('disabled', false);
                        
                        if (response.success) {
                            msg.html('<span style="color: green;">✅ ' + response.data.message + '</span>');
                        } else {
                            msg.html('<span style="color: red;">❌ ' + response.data + '</span>');
                        }
                    },
                    error: function() {
                        spinner.hide();
                        btn.prop('disabled', false);
                        msg.html('<span style="color: red;">❌ Lỗi kết nối Server.</span>');
                    }
                });
            }
        });
    });
    </script>
    <?php
}
