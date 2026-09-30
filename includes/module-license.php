<?php
/** Paid feature gate. Updater and license settings remain available while locked. */
if (!defined('ABSPATH')) { exit; }

function tkgadm_license_message() {
    return 'GAds Toolkit đang tạm khóa. Cần License Key hợp lệ để theo dõi click, chặn IP và sử dụng các tính năng. Vui lòng nhập hoặc gia hạn giấy phép tại https://gads.pdl.vn.';
}

/** Never derive site identity from the incoming Host/Origin headers. */
function tkgadm_license_site_url() {
    return trailingslashit(home_url('/'));
}

function tkgadm_license_site_token() {
    $secret = get_option('tkgadm_license_site_secret', '');
    if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) {
        // add_option is atomic for first creation; keep the winning secret under concurrency.
        add_option('tkgadm_license_site_secret', bin2hex(random_bytes(32)), '', false);
        $secret = get_option('tkgadm_license_site_secret', '');
    }
    if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) { return ''; }
    return hash_hmac('sha256', tkgadm_license_site_url(), $secret);
}

function tkgadm_service_headers($api_key = null, $extra = array()) {
    return array_merge($extra, array(
        'X-API-Key' => $api_key === null ? tkgadm_get_central_service_api_key() : $api_key,
        'X-GAds-Site' => tkgadm_license_site_url(),
        'X-GAds-Site-Token' => tkgadm_license_site_token(),
    ));
}

// Public read-only HMAC challenge. Never return the installation secret or license key.
// No license/network checks here: the Worker calls this while validating the license.
function tkgadm_license_proof($request) {
    $challenge = $request->get_param('challenge');
    if (!is_string($challenge) || !preg_match('/^[a-f0-9]{64}$/D', $challenge)) {
        return new WP_Error('invalid_challenge', 'Invalid challenge', array('status' => 400));
    }
    $secret = get_option('tkgadm_license_site_secret', '');
    if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) {
        return new WP_Error('uninitialized_site', 'Installation not initialized', array('status' => 403));
    }
    $token = hash_hmac('sha256', tkgadm_license_site_url(), $secret);
    $response = new WP_REST_Response(array(
        'site_url' => tkgadm_license_site_url(),
        'proof' => hash_hmac('sha256', $challenge, $token),
    ));
    $response->header('Cache-Control', 'no-store, private, max-age=0');
    return $response;
}

function tkgadm_register_license_proof() {
    register_rest_route('gads-toolkit/v1', '/license-proof', array(
        'methods' => 'GET', 'callback' => 'tkgadm_license_proof', 'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'tkgadm_register_license_proof');

function tkgadm_license_status($force = false, $key_override = null) {
    $key = $key_override === null ? tkgadm_get_central_service_api_key() : $key_override;
    if (!is_string($key) || $key === '') {
        return array('valid' => false, 'message' => tkgadm_license_message());
    }
    $service = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $cache_key = 'tkgadm_license_' . hash('sha256', $service . '|' . $key . '|' . tkgadm_license_site_url() . '|' . tkgadm_license_site_token());
    // Memoize within this PHP request even when a persistent cache write fails.
    static $request_cache = array();
    $cached = !$force && isset($request_cache[$cache_key]) ? $request_cache[$cache_key] : get_transient($cache_key);
    $now = time();
    if (!$force && is_array($cached) && isset($cached['until'], $cached['valid'], $cached['message'])
        && is_int($cached['until']) && is_bool($cached['valid']) && is_string($cached['message'])
        && $cached['until'] > $now && $cached['until'] <= $now + 300) {
        $request_cache[$cache_key] = $cached;
        return $cached;
    }
    $status = array('valid' => false, 'message' => tkgadm_license_message(), 'until' => $now + 60);
    if ($service) {
        $response = wp_remote_get(trailingslashit($service) . 'api?action=validate_license', array(
            'headers' => tkgadm_service_headers($key), 'timeout' => 12, 'redirection' => 0,
        ));
        if (is_wp_error($response)) {
            $status['message'] = 'Không thể xác thực license/domain. Kiểm tra kết nối HTTPS và REST API của website.';
        } else {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($data) && isset($data['error']) && is_string($data['error'])) {
                $status['message'] = substr($data['error'], 0, 500);
            }
            if (wp_remote_retrieve_response_code($response) === 200 && is_array($data)
                && isset($data['data']) && is_array($data['data'])
                && isset($data['success'], $data['data']['valid']) && $data['success'] === true
                && $data['data']['valid'] === true
                && isset($data['data']['site_url']) && $data['data']['site_url'] === tkgadm_license_site_url()
                && array_key_exists('expires_at', $data['data'])) {
                $expiry = $data['data']['expires_at'];
                $expires = is_string($expiry) ? strtotime($expiry) : false;
                if ($expiry === null || ($expires !== false && $expires > $now)) {
                    $status = array('valid' => true, 'message' => 'License Key hợp lệ.',
                        'until' => $expiry === null ? $now + 300 : min($now + 300, $expires));
                }
            }
        }
    }
    // Fail closed after the bounded cache expires; never extend an old success on failure.
    $request_cache[$cache_key] = $status;
    set_transient($cache_key, $status, max(1, $status['until'] - $now));
    return $status;
}

function tkgadm_license_is_valid() {
    $status = tkgadm_license_status();
    return $status['valid'] === true;
}

function tkgadm_render_license_lock() {
    if (!current_user_can('manage_options')) { return; }
    echo '<div class="wrap"><h1>GAds Toolkit — License Key</h1><div class="notice notice-warning inline"><p>'
        . esc_html(tkgadm_license_message()) . '</p></div><p><a class="button button-primary" href="'
        . esc_url(admin_url('admin.php?page=tkgad-settings')) . '">Nhập License Key</a></p></div>';
}

function tkgadm_license_admin_notice() {
    $page = $_GET['page'] ?? '';
    if (!current_user_can('manage_options') || !is_string($page) || strpos($page, 'tkgad') !== 0) { return; }
    $status = tkgadm_license_status();
    echo '<div class="notice ' . ($status['valid'] ? 'notice-success' : 'notice-warning') . '"><p>'
        . esc_html($status['message']) . '</p></div>';
}
add_action('admin_notices', 'tkgadm_license_admin_notice');
