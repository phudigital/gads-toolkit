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

// Re-check a valid license every 12h; keep working up to 72h while the service is unreachable.
if (!defined('TKGADM_LICENSE_FRESH')) { define('TKGADM_LICENSE_FRESH', 12 * HOUR_IN_SECONDS); }
if (!defined('TKGADM_LICENSE_GRACE')) { define('TKGADM_LICENSE_GRACE', 72 * HOUR_IN_SECONDS); }
if (!defined('TKGADM_LICENSE_RETRY')) { define('TKGADM_LICENSE_RETRY', 15 * MINUTE_IN_SECONDS); }

/** Remote checks may block only in admin, cron and CLI — never in a visitor's request. */
function tkgadm_license_can_check_now() {
    if (function_exists('wp_doing_cron') && wp_doing_cron()) { return true; }
    if (defined('WP_CLI') && WP_CLI) { return true; }
    if (!is_admin()) { return false; }
    // admin-ajax is "admin" also for anonymous visitors (beacons): only logged-in users may wait.
    return !(function_exists('wp_doing_ajax') && wp_doing_ajax()) || (function_exists('is_user_logged_in') && is_user_logged_in());
}

/**
 * Ask the Central Service. Returns outcome valid | invalid (explicit rejection) | error (transient).
 */
function tkgadm_license_remote_check($service, $key) {
    $response = wp_remote_get(trailingslashit($service) . 'api?action=validate_license', array(
        'headers' => tkgadm_service_headers($key), 'timeout' => 12, 'redirection' => 0,
    ));
    if (is_wp_error($response)) {
        return array('outcome' => 'error', 'message' => 'Không thể xác thực license/domain. Kiểm tra kết nối HTTPS và REST API của website.');
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $message = (is_array($data) && isset($data['error']) && is_string($data['error'])) ? substr($data['error'], 0, 500) : '';
    if ($code === 200 && is_array($data) && isset($data['success'], $data['data']) && $data['success'] === true
        && is_array($data['data']) && isset($data['data']['valid']) && $data['data']['valid'] === true
        && isset($data['data']['site_url']) && $data['data']['site_url'] === tkgadm_license_site_url()
        && array_key_exists('expires_at', $data['data'])) {
        $expiry = $data['data']['expires_at'];
        $expires = is_string($expiry) ? strtotime($expiry) : false;
        if ($expiry === null || ($expires !== false && $expires > time())) {
            return array('outcome' => 'valid', 'message' => 'License Key hợp lệ.', 'expires' => $expiry === null ? null : $expires);
        }
        return array('outcome' => 'invalid', 'message' => tkgadm_license_message());
    }
    if (in_array($code, array(400, 401, 403), true)) {
        return array('outcome' => 'invalid', 'message' => $message ?: tkgadm_license_message());
    }
    return array('outcome' => 'error', 'message' => $message ?: 'Dịch vụ xác thực tạm thời không phản hồi.');
}

/** Effective status of a stored state at $now (honours grace and license expiry). */
function tkgadm_license_effective($state, $now) {
    $valid = !empty($state['valid'])
        && (!isset($state['expires']) || $state['expires'] === null || $state['expires'] > $now)
        && isset($state['last_ok']) && $state['last_ok'] + TKGADM_LICENSE_GRACE > $now;
    return array(
        'valid' => $valid,
        'message' => $valid ? (string) $state['message'] : (empty($state['valid']) ? (string) $state['message'] : tkgadm_license_message()),
        'until' => $now + TKGADM_LICENSE_RETRY,
    );
}

function tkgadm_license_status($force = false, $key_override = null) {
    $key = $key_override === null ? tkgadm_get_central_service_api_key() : $key_override;
    if (!is_string($key) || $key === '') {
        return array('valid' => false, 'message' => tkgadm_license_message());
    }
    $service = defined('GADS_SERVICE_URL') ? GADS_SERVICE_URL : get_option('tkgadm_central_service_url');
    $state_key = 'tkgadm_license_state_' . substr(hash('sha256', $service . '|' . $key . '|' . tkgadm_license_site_url() . '|' . tkgadm_license_site_token()), 0, 32);
    static $request_cache = array();
    $now = time();
    if (!$force && isset($request_cache[$state_key])) {
        return $request_cache[$state_key];
    }

    $state = get_option($state_key);
    $has_state = is_array($state) && isset($state['checked_at'], $state['valid']);
    if (!$has_state && !$force) {
        // First request after upgrading from <= 4.3: seed from the old 5-minute cache.
        $legacy = get_transient('tkgadm_license_' . hash('sha256', $service . '|' . $key . '|' . tkgadm_license_site_url() . '|' . tkgadm_license_site_token()));
        if (is_array($legacy) && isset($legacy['valid'], $legacy['until']) && $legacy['valid'] === true && $legacy['until'] > $now) {
            $state = array('valid' => true, 'message' => 'License Key hợp lệ.', 'checked_at' => $now - TKGADM_LICENSE_FRESH + TKGADM_LICENSE_RETRY,
                'last_ok' => $now, 'expires' => null);
            update_option($state_key, $state, false);
            $has_state = true;
        }
    }
    if (!$force && $has_state) {
        $ttl = !empty($state['valid']) ? TKGADM_LICENSE_FRESH : TKGADM_LICENSE_RETRY;
        $fresh = $state['checked_at'] + $ttl > $now;
        if ($fresh || !tkgadm_license_can_check_now()) {
            if (!$fresh && !wp_next_scheduled('tkgadm_license_refresh_event')) {
                wp_schedule_single_event($now, 'tkgadm_license_refresh_event');
            }
            return $request_cache[$state_key] = tkgadm_license_effective($state, $now);
        }
    }

    if (!$service) {
        $result = array('outcome' => 'invalid', 'message' => tkgadm_license_message());
    } else {
        $result = tkgadm_license_remote_check($service, $key);
    }

    if ($result['outcome'] === 'valid') {
        $state = array('valid' => true, 'message' => $result['message'], 'checked_at' => $now,
            'last_ok' => $now, 'expires' => $result['expires']);
    } elseif ($result['outcome'] === 'invalid') {
        $state = array('valid' => false, 'message' => $result['message'], 'checked_at' => $now, 'last_ok' => 0, 'expires' => null);
    } else {
        // Service unreachable: keep the last success within the grace window, retry soon.
        $prev_ok = $has_state && !empty($state['valid']) ? (int) $state['last_ok'] : 0;
        $state = array('valid' => $prev_ok > 0, 'message' => $prev_ok > 0
                ? 'License hợp lệ (dùng kết quả xác thực trước; dịch vụ tạm thời không phản hồi).'
                : $result['message'],
            'checked_at' => $prev_ok > 0 ? $now - TKGADM_LICENSE_FRESH + TKGADM_LICENSE_RETRY : $now, 'last_ok' => $prev_ok,
            'expires' => $has_state && isset($state['expires']) ? $state['expires'] : null);
    }
    update_option($state_key, $state, false);
    return $request_cache[$state_key] = tkgadm_license_effective($state, $now);
}

add_action('tkgadm_license_refresh_event', 'tkgadm_license_refresh');
function tkgadm_license_refresh() {
    tkgadm_license_status(true);
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
