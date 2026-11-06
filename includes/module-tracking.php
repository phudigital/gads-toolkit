<?php
/**
 * Visitor tracking.
 *
 * - Server side: records uncached page views on template_redirect (no JS needed).
 * - Beacon: assets/time-tracker.js posts to REST on load (covers page-cached HTML)
 *   and again on leave with time on page. The IP always comes from the server.
 * - Real-time rules only touch the local DB; Google Ads sync and notifications
 *   run later in cron (tkgadm_queue_sync), never inside a visitor's request.
 */

if (!defined('ABSPATH')) exit;

/** Same-page window: a beacon within this many seconds belongs to the server-recorded view. */
if (!defined('TKGADM_BEACON_WINDOW')) { define('TKGADM_BEACON_WINDOW', 120); }

function tkgadm_bot_signatures() {
    return array('bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'facebookexternalhit', 'whatsapp',
        'curl', 'wget', 'python', 'java', 'go-http', 'headlesschrome', 'phantomjs', 'lighthouse');
}

function tkgadm_is_bot_user_agent($user_agent) {
    if ($user_agent === '') {
        return true;
    }
    foreach (tkgadm_bot_signatures() as $bot) {
        if (stripos($user_agent, $bot) !== false) {
            return true;
        }
    }
    return false;
}

/** Canonical URL for storage: site host + path/query, no fragment. */
function tkgadm_normalize_tracked_url($url) {
    $url = (string) $url;
    $hash = strpos($url, '#');
    if ($hash !== false) {
        $url = substr($url, 0, $hash);
    }
    return substr(esc_url_raw($url), 0, 2048);
}

function tkgadm_current_request_url() {
    $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
    $home = wp_parse_url(home_url('/'));
    $scheme = is_ssl() ? 'https' : (isset($home['scheme']) ? $home['scheme'] : 'http');
    $host = isset($home['host']) ? $home['host'] : '';
    if (!empty($home['port'])) {
        $host .= ':' . $home['port'];
    }
    return tkgadm_normalize_tracked_url($scheme . '://' . $host . '/' . ltrim($uri, '/'));
}

function tkgadm_click_id_from_query($query) {
    if (!is_array($query)) {
        return '';
    }
    foreach (array('gclid', 'gbraid', 'wbraid') as $param) {
        if (!empty($query[$param]) && is_string($query[$param])) {
            return substr(sanitize_text_field(wp_unslash($query[$param])), 0, 255);
        }
    }
    return '';
}

/**
 * Insert or update one visit row.
 *
 * @param array $visit ip, url, user_agent, gclid, has_js (0|1|null)
 * @param bool  $from_beacon Beacon calls never double count a server-recorded view.
 * @return int|false Row id.
 */
function tkgadm_record_visit(array $visit, $from_beacon = false) {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    $now = current_time('mysql');

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, visit_count, visit_time FROM $table
         WHERE ip_address = %s AND url_visited = %s AND user_agent = %s AND gclid = %s
           AND visit_time >= %s
         ORDER BY visit_time DESC LIMIT 1",
        $visit['ip'], $visit['url'], $visit['user_agent'], $visit['gclid'],
        wp_date('Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS)
    ));

    if ($existing) {
        $data = array();
        $recent = abs(strtotime($now) - strtotime($existing->visit_time)) <= TKGADM_BEACON_WINDOW;
        if (!$from_beacon || !$recent) {
            $data['visit_count'] = (int) $existing->visit_count + 1;
            $data['visit_time'] = $now;
        }
        if ($visit['has_js'] !== null) {
            $data['has_js'] = (int) $visit['has_js'];
        }
        if ($data) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update($table, $data, array('id' => $existing->id));
        }
        return (int) $existing->id;
    }

    $row = array(
        'ip_address' => $visit['ip'],
        'visit_time' => $now,
        'url_visited' => $visit['url'],
        'user_agent' => $visit['user_agent'],
        'gclid' => $visit['gclid'],
        'time_on_page' => 0,
    );
    if ($visit['has_js'] !== null) {
        $row['has_js'] = (int) $visit['has_js'];
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return $wpdb->insert($table, $row) ? (int) $wpdb->insert_id : false;
}

/** Shared pipeline for server and beacon hits. */
function tkgadm_handle_hit($url, $gclid, $has_js, $from_beacon) {
    $ip = tkgadm_get_real_user_ip();
    if ($ip === '') {
        return false;
    }
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
        ? substr(sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 1024) : '';
    $is_ad = $gclid !== '' || strpos($url, 'gad_source') !== false;

    // Organic bots are noise; ad clicks are always kept (that is what we police).
    if (!$is_ad && tkgadm_is_bot_user_agent($user_agent)) {
        return false;
    }

    $id = tkgadm_record_visit(array(
        'ip' => $ip, 'url' => $url, 'user_agent' => $user_agent, 'gclid' => $gclid, 'has_js' => $has_js,
    ), $from_beacon);

    if ($gclid !== '' && get_option('tkgadm_auto_block_enabled')) {
        tkgadm_check_ip_instant($ip);
    }
    return $id;
}

/**
 * Server-side record for uncached HTML views.
 */
add_action('template_redirect', 'tkgadm_track_visit', 1);
function tkgadm_track_visit() {
    if (!tkgadm_license_is_valid()) { return; }
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)
        || is_feed() || is_robots() || is_404() || is_preview()) {
        return;
    }
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    if ($method !== 'GET') {
        return;
    }
    // has_js stays NULL here; the beacon flips it to 1 when JavaScript runs.
    tkgadm_handle_hit(tkgadm_current_request_url(), tkgadm_click_id_from_query($_GET), null, false);
}

/**
 * Real-time rule check for one IP (local DB only).
 */
function tkgadm_check_ip_instant($ip) {
    if (!tkgadm_license_is_valid()) { return; }

    $rules = tkgadm_get_block_rules();
    if (empty($rules)) {
        return;
    }

    global $wpdb;
    $stats_table = $wpdb->prefix . 'gads_toolkit_stats';

    foreach ($rules as $rule) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $click_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT gclid) FROM $stats_table
             WHERE ip_address = %s AND visit_time >= %s AND gclid IS NOT NULL AND gclid != ''",
            $ip, tkgadm_rule_window_start($rule)
        ));

        if ($click_count >= $rule['limit']) {
            $reason = sprintf('Chặn Tức Thì: %d click (Quy tắc: %s)', $click_count, tkgadm_rule_text($rule));
            if (tkgadm_block_ip_internal($ip, $reason, 'auto')) {
                tkgadm_queue_block_notice($ip, tkgadm_rule_text($rule));
            }
            break;
        }
    }
}

/**
 * Front-end beacon script.
 */
add_action('wp_enqueue_scripts', 'tkgadm_enqueue_time_tracker');
function tkgadm_enqueue_time_tracker() {
    if (!tkgadm_license_is_valid()) { return; }

    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    if ($user_agent !== '' && tkgadm_is_bot_user_agent($user_agent)) {
        return;
    }

    wp_enqueue_script('tkgadm-time-tracker', GADS_TOOLKIT_URL . 'assets/time-tracker.js', array(), GADS_TOOLKIT_VERSION, true);
    // No per-visitor data here: the HTML may be served from a page cache.
    wp_localize_script('tkgadm-time-tracker', 'tkgadm_tracker', array(
        'rest' => esc_url_raw(rest_url('gads-toolkit/v1/')),
        'ajaxurl' => admin_url('admin-ajax.php'),
    ));
}

/**
 * REST endpoints used by the beacon. Public by design; payloads are bounded.
 */
add_action('rest_api_init', 'tkgadm_register_tracking_routes');
function tkgadm_register_tracking_routes() {
    register_rest_route('gads-toolkit/v1', '/hit', array(
        'methods' => 'POST', 'callback' => 'tkgadm_rest_hit', 'permission_callback' => '__return_true',
    ));
    register_rest_route('gads-toolkit/v1', '/time', array(
        'methods' => 'POST', 'callback' => 'tkgadm_rest_time', 'permission_callback' => '__return_true',
    ));
}

function tkgadm_beacon_payload($request) {
    $url = $request->get_param('url');
    if (!is_string($url) || $url === '' || strlen($url) > 4096) {
        return new WP_Error('invalid_payload', 'Invalid tracking payload', array('status' => 400));
    }
    $url = tkgadm_normalize_tracked_url($url);
    $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $host = wp_parse_url($url, PHP_URL_HOST);
    if (!$host || strcasecmp(preg_replace('/^www\./i', '', $host), preg_replace('/^www\./i', '', (string) $home_host)) !== 0) {
        return new WP_Error('invalid_payload', 'Foreign URL', array('status' => 400));
    }
    $query = array();
    $query_string = wp_parse_url($url, PHP_URL_QUERY);
    if ($query_string) {
        parse_str($query_string, $query);
    }
    return array('url' => $url, 'gclid' => tkgadm_click_id_from_query($query));
}

function tkgadm_rest_hit($request) {
    if (!tkgadm_license_is_valid()) {
        return new WP_REST_Response(array('ok' => false), 202);
    }
    $payload = tkgadm_beacon_payload($request);
    if (is_wp_error($payload)) {
        return $payload;
    }
    tkgadm_handle_hit($payload['url'], $payload['gclid'], 1, true);
    $response = new WP_REST_Response(array('ok' => true), 200);
    $response->header('Cache-Control', 'no-store');
    return $response;
}

function tkgadm_rest_time($request) {
    if (!tkgadm_license_is_valid()) {
        return new WP_REST_Response(array('ok' => false), 202);
    }
    $payload = tkgadm_beacon_payload($request);
    if (is_wp_error($payload)) {
        return $payload;
    }
    $time = $request->get_param('time');
    if (!is_scalar($time) || !ctype_digit((string) $time) || (int) $time > 604800) {
        return new WP_Error('invalid_payload', 'Invalid time', array('status' => 400));
    }
    $updated = tkgadm_update_time_on_page(tkgadm_get_real_user_ip(), $payload['url'], $payload['gclid'], (int) $time);
    return new WP_REST_Response(array('ok' => $updated), 200);
}

/** Store the longest observed time on page for the latest matching row. */
function tkgadm_update_time_on_page($ip, $url, $gclid, $time) {
    if ($ip === '') {
        return false;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
        ? substr(sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 1024) : '';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $record = $wpdb->get_row($wpdb->prepare(
        "SELECT id, time_on_page FROM $table
         WHERE ip_address = %s AND url_visited = %s AND user_agent = %s AND gclid = %s
         ORDER BY visit_time DESC LIMIT 1",
        $ip, $url, $user_agent, $gclid
    ));
    if (!$record) {
        return false;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->update($table, array('time_on_page' => max((int) $record->time_on_page, $time), 'has_js' => 1), array('id' => $record->id));
    return true;
}

/**
 * Legacy admin-ajax beacon (HTML cached before 4.4.0). The posted IP is ignored.
 */
add_action('wp_ajax_nopriv_tkgadm_update_time_on_page', 'tkgadm_ajax_update_time_on_page');
add_action('wp_ajax_tkgadm_update_time_on_page', 'tkgadm_ajax_update_time_on_page');
function tkgadm_ajax_update_time_on_page() {
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }

    foreach (array('url' => 4096, 'time' => 10, 'gclid' => 1024) as $field => $limit) {
        if (isset($_POST[$field]) && (!is_string($_POST[$field]) || strlen($_POST[$field]) > $limit)) {
            wp_send_json_error('Invalid tracking payload', 400); return;
        }
    }
    if (!isset($_POST['url'], $_POST['time']) || !ctype_digit($_POST['time']) || (int) $_POST['time'] > 604800) {
        wp_send_json_error('Invalid tracking payload', 400); return;
    }
    $url = tkgadm_normalize_tracked_url(wp_unslash($_POST['url']));
    $gclid = isset($_POST['gclid']) ? substr(sanitize_text_field(wp_unslash($_POST['gclid'])), 0, 255) : '';
    if (tkgadm_update_time_on_page(tkgadm_get_real_user_ip(), $url, $gclid, (int) $_POST['time'])) {
        wp_send_json_success(array('updated' => true));
    }
    wp_send_json_error('Record not found');
}
