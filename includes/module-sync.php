<?php
/**
 * Google Ads IP exclusion sync (reconcile).
 *
 * The local block list is the source of truth. Each run:
 *   1. Takes the newest N local blocks (N = tkgadm_rotation_max_ips, max 500), minus whitelist.
 *   2. Lists the IP_BLOCK criteria on the Google Ads account.
 *   3. Removes remote IPs that this plugin manages but are no longer wanted locally.
 *      IPs the advertiser added by hand are never removed.
 *   4. Adds the local IPs that are missing remotely, within the free slots.
 * Nothing is ever wiped first, so a failed upload cannot leave the account empty.
 * Runs in cron (queued after blocks, hourly) or from an admin action, under a lock.
 */

if (!defined('ABSPATH')) exit;

if (!defined('TKGADM_GADS_LIMIT')) { define('TKGADM_GADS_LIMIT', 500); }

/* ---------------------------------------------------------------------------
 * Managed set: normalized IPs this plugin put (or found matching) on Google Ads.
 * ------------------------------------------------------------------------- */

function tkgadm_get_managed_ips() {
    $ips = get_option('tkgadm_gads_managed_ips', []);
    return is_array($ips) ? array_values(array_unique(array_filter($ips, 'is_string'))) : [];
}

function tkgadm_set_managed_ips(array $ips) {
    update_option('tkgadm_gads_managed_ips', array_values(array_unique($ips)), false);
}

/* ---------------------------------------------------------------------------
 * Queue
 * ------------------------------------------------------------------------- */

/** Ask cron to reconcile soon (debounced). Safe to call from a visitor request. */
function tkgadm_queue_sync($delay = 30) {
    if (!wp_next_scheduled('tkgadm_sync_now_event')) {
        wp_schedule_single_event(time() + max(0, (int) $delay), 'tkgadm_sync_now_event');
    }
}

add_action('tkgadm_sync_now_event', 'tkgadm_handle_sync_now');
function tkgadm_handle_sync_now() {
    if (!tkgadm_license_is_valid()) { return; }
    tkgadm_flush_block_notices();
    if (get_option('tkgadm_gads_auto_sync', '1') !== '1' || !tkgadm_get_gads_connection_mode()) {
        return;
    }
    tkgadm_record_sync_result(tkgadm_reconcile_google_ads(), true);
}

add_action('tkgadm_hourly_sync_event', 'tkgadm_handle_hourly_sync');
function tkgadm_handle_hourly_sync() {
    if (!tkgadm_license_is_valid()) { return; }
    if (get_option('tkgadm_gads_auto_sync', '1') !== '1' || !tkgadm_get_gads_connection_mode()) {
        return;
    }
    tkgadm_record_sync_result(tkgadm_reconcile_google_ads(), true);
}

function tkgadm_record_sync_result($result, $auto = false) {
    update_option('tkgadm_last_sync_time', time());
    update_option('tkgadm_last_sync_message', ($auto ? '(Tự động) ' : '') . $result['message']);
    update_option('tkgadm_last_sync_success', $result['success'] ? '1' : '0');
}

/* ---------------------------------------------------------------------------
 * Local targets
 * ------------------------------------------------------------------------- */

/** Max IPs this site may keep on Google Ads (leaves room for manual entries). */
function tkgadm_sync_capacity_setting() {
    return max(1, min(TKGADM_GADS_LIMIT, (int) get_option('tkgadm_rotation_max_ips', TKGADM_GADS_LIMIT)));
}

/**
 * Newest local blocks to keep on Google Ads.
 * @return array|WP_Error normalized => local pattern, newest first
 */
function tkgadm_sync_local_targets() {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    $limit = tkgadm_sync_capacity_setting();
    // Over-fetch a little so whitelisted/invalid rows don't shrink the set.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_col($wpdb->prepare("SELECT ip_address FROM $table ORDER BY blocked_time DESC, id DESC LIMIT %d", $limit + 200));
    if ($wpdb->last_error || !is_array($rows)) {
        return new WP_Error('db_error', 'Không đọc được danh sách IP local; chưa thay đổi Google Ads.');
    }
    $targets = [];
    foreach ($rows as $ip) {
        if (count($targets) >= $limit) {
            break;
        }
        if (function_exists('tkgadm_is_ip_whitelisted') && tkgadm_is_ip_whitelisted($ip)) {
            continue;
        }
        $norm = tkgadm_normalize_google_ads_ip($ip);
        if ($norm && !isset($targets[$norm])) {
            $targets[$norm] = $ip;
        }
    }
    return $targets;
}

/**
 * Auto-rotate setting: keep the local list at or under the threshold by dropping
 * the oldest (and least suspicious) blocks. Logged, so it can be undone.
 */
function tkgadm_apply_auto_rotate() {
    if (get_option('tkgadm_ar_enabled', '0') !== '1') {
        return 0;
    }
    list($threshold, $amount) = tkgadm_get_auto_rotate_config();
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    if ($count < $threshold) {
        return 0;
    }
    return tkgadm_rotate_oldest_local($amount, 'Tự động xoay vòng: danh sách đạt ngưỡng ' . $threshold . ' IP');
}

function tkgadm_get_auto_rotate_config() {
    $threshold = max(50, min(TKGADM_GADS_LIMIT, (int) get_option('tkgadm_ar_threshold', 450)));
    $amount = max(1, min($threshold, (int) get_option('tkgadm_ar_amount', 50)));
    return [$threshold, $amount];
}

/**
 * Unblock the $count oldest local entries (lowest fraud score first among the oldest).
 * @return int number removed
 */
function tkgadm_rotate_oldest_local($count, $reason, $actor = 'auto') {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    $count = max(1, min(TKGADM_GADS_LIMIT, (int) $count));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $candidates = $wpdb->get_col($wpdb->prepare("SELECT ip_address FROM $table ORDER BY blocked_time ASC, id ASC LIMIT %d", $count * 2));
    if (empty($candidates)) {
        return 0;
    }
    // Among the oldest 2N, drop the N with the lowest fraud score first.
    if (function_exists('tkgadm_get_fraud_scores')) {
        $scores = tkgadm_get_fraud_scores($candidates);
        $order = array_flip($candidates);
        usort($candidates, function ($a, $b) use ($scores, $order) {
            // Ranges (a.b.c.*) cover many addresses: keep them longest.
            $sa = isset($scores[$a]) ? $scores[$a]['score'] : (filter_var($a, FILTER_VALIDATE_IP) ? 0 : 101);
            $sb = isset($scores[$b]) ? $scores[$b]['score'] : (filter_var($b, FILTER_VALIDATE_IP) ? 0 : 101);
            return $sa === $sb ? $order[$a] - $order[$b] : $sa - $sb;
        });
    }
    $removed = 0;
    foreach (array_slice($candidates, 0, $count) as $ip) {
        if (tkgadm_unblock_ip_internal($ip, $reason, $actor, 'rotate')) {
            $removed++;
        }
    }
    return $removed;
}

/* ---------------------------------------------------------------------------
 * Reconcile
 * ------------------------------------------------------------------------- */

/**
 * @return array{success: bool, message: string, added?: int, removed?: int, skipped?: int}
 */
function tkgadm_reconcile_google_ads() {
    if (!tkgadm_license_is_valid()) { return ['success' => false, 'message' => tkgadm_license_message()]; }

    $mode = tkgadm_get_gads_connection_mode();
    if (!$mode) {
        return ['success' => false, 'message' => 'Vui lòng kiểm tra kết nối Google Ads và Customer ID trong Cấu hình & Tích hợp.'];
    }

    // One run at a time (cron + admin button can overlap).
    if (get_transient('tkgadm_sync_lock')) {
        return ['success' => false, 'message' => 'Đang có một lượt đồng bộ khác chạy. Vui lòng thử lại sau ít phút.'];
    }
    set_transient('tkgadm_sync_lock', time(), 5 * MINUTE_IN_SECONDS);

    try {
        $rotated = tkgadm_apply_auto_rotate();

        $targets = tkgadm_sync_local_targets();
        if (is_wp_error($targets)) {
            return ['success' => false, 'message' => $targets->get_error_message()];
        }

        $remote = tkgadm_list_connected_google_ads_ips();
        if (is_wp_error($remote)) {
            return ['success' => false, 'message' => 'Lỗi lấy danh sách từ Google Ads: ' . $remote->get_error_message()];
        }

        $remote_map = []; // normalized => resource_name
        foreach ($remote as $entry) {
            if (empty($entry['ip_address']) || empty($entry['resource_name'])) {
                continue;
            }
            $norm = tkgadm_normalize_google_ads_ip($entry['ip_address']);
            $remote_map[$norm ?: $entry['ip_address']] = $entry['resource_name'];
        }

        // Remote entries that match local blocks are ours (seeds installs from before 4.4).
        $managed = array_fill_keys(tkgadm_get_managed_ips(), true);
        foreach ($remote_map as $norm => $rn) {
            if (isset($targets[$norm])) {
                $managed[$norm] = true;
            }
        }

        $to_remove = [];
        foreach ($remote_map as $norm => $rn) {
            if (isset($managed[$norm]) && !isset($targets[$norm])) {
                $to_remove[$norm] = $rn;
            }
        }
        $to_add = array_values(array_diff(array_keys($targets), array_keys($remote_map)));

        $free = TKGADM_GADS_LIMIT - (count($remote_map) - count($to_remove));
        $skipped = 0;
        if (count($to_add) > $free) {
            $skipped = count($to_add) - max(0, $free);
            $to_add = array_slice($to_add, 0, max(0, $free)); // targets are newest first
        }

        $removed = 0;
        if ($to_remove) {
            $result = tkgadm_remove_connected_google_ads_ips(array_values($to_remove));
            if (is_wp_error($result)) {
                tkgadm_set_managed_ips(array_keys($managed));
                return ['success' => false, 'message' => 'Lỗi khi gỡ IP trên Google Ads: ' . $result->get_error_message()];
            }
            foreach (array_keys($to_remove) as $norm) {
                unset($managed[$norm]);
            }
            $removed = count($to_remove);
        }

        $added = 0;
        $add_error = '';
        if ($to_add) {
            $result = tkgadm_gads_create_ip_blocks($to_add);
            foreach ($result['added'] as $norm) {
                $managed[$norm] = true;
            }
            $added = count($result['added']);
            if (!$result['success']) {
                $add_error = $result['message'];
            }
        }

        tkgadm_set_managed_ips(array_keys($managed));

        $parts = [];
        $parts[] = "thêm $added IP";
        $parts[] = "gỡ $removed IP";
        if ($rotated) {
            $parts[] = "xoay vòng $rotated IP cũ";
        }
        $message = 'Đồng bộ Google Ads: ' . implode(', ', $parts) . '. Đang chặn ' . (count($remote_map) - $removed + $added) . '/' . TKGADM_GADS_LIMIT . ' IP.';
        if ($skipped) {
            $message .= " $skipped IP chưa đưa lên vì tài khoản đã đầy (bật Tự động xoay vòng hoặc giảm danh sách).";
        }
        if ($add_error) {
            return ['success' => false, 'message' => $message . ' Lỗi khi thêm: ' . $add_error, 'added' => $added, 'removed' => $removed];
        }
        if ($added || $removed) {
            tkgadm_log_action('sync', '', $message, 'auto');
        }
        return ['success' => true, 'message' => $message, 'added' => $added, 'removed' => $removed, 'skipped' => $skipped];
    } finally {
        delete_transient('tkgadm_sync_lock');
    }
}

/* ---------------------------------------------------------------------------
 * Transport: create IP_BLOCK criteria
 * ------------------------------------------------------------------------- */

/**
 * Create IP blocks for already-normalized IPs/CIDRs.
 * @return array{success: bool, message: string, added: string[]}
 */
function tkgadm_gads_create_ip_blocks(array $ips) {
    $ips = array_values(array_unique(array_filter($ips)));
    if (!$ips) {
        return ['success' => true, 'message' => 'Không có IP cần thêm.', 'added' => []];
    }
    if (tkgadm_get_gads_connection_mode() === 'central') {
        return tkgadm_sync_via_central_service($ips);
    }
    return tkgadm_create_ip_blocks_direct($ips);
}

function tkgadm_create_ip_blocks_direct(array $ips) {
    $access_token = tkgadm_get_google_access_token();
    if (is_wp_error($access_token)) {
        return ['success' => false, 'message' => $access_token->get_error_message(), 'added' => []];
    }
    $ids = tkgadm_get_gads_ids();
    if (!$ids['customer_id'] || !$ids['developer_token']) {
        return ['success' => false, 'message' => 'Thiếu Customer ID hoặc Developer Token.', 'added' => []];
    }
    $operations = array_map(function ($ip) {
        return ['create' => ['ip_block' => ['ip_address' => $ip]]];
    }, $ips);

    $headers = [
        'Authorization' => 'Bearer ' . $access_token,
        'developer-token' => $ids['developer_token'],
        'Content-Type' => 'application/json',
    ];
    if (!empty($ids['manager_id'])) {
        $headers['login-customer-id'] = $ids['manager_id'];
    }
    $response = wp_remote_post(
        "https://googleads.googleapis.com/v25/customers/{$ids['customer_id']}/customerNegativeCriteria:mutate",
        ['headers' => $headers, 'timeout' => 60, 'body' => wp_json_encode([
            'operations' => $operations, 'partialFailure' => true, 'validateOnly' => false,
        ])]
    );
    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Lỗi kết nối Google: ' . $response->get_error_message(), 'added' => []];
    }
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code !== 200) {
        $msg = isset($body['error']['message']) ? $body['error']['message'] : 'Lỗi không xác định.';
        return ['success' => false, 'message' => "Google API Error ($code): $msg", 'added' => []];
    }
    $added = [];
    foreach ((array) ($body['results'] ?? []) as $index => $res) {
        if (!empty($res) && isset($ips[$index])) {
            $added[] = $ips[$index];
        }
    }
    if (isset($body['partialFailureError'])) {
        return ['success' => count($added) > 0, 'added' => $added,
            'message' => 'Một số IP lỗi: ' . ($body['partialFailureError']['message'] ?? '')];
    }
    return ['success' => true, 'message' => 'Đã thêm ' . count($added) . ' IP.', 'added' => $added];
}

/* ---------------------------------------------------------------------------
 * Legacy entry points (kept for callers and add-ons)
 * ------------------------------------------------------------------------- */

/** Queue a sync; the IPs themselves come from the local list. */
function tkgadm_sync_ip_to_google_ads($ips_to_block = [], $unused = false) {
    if (!tkgadm_license_is_valid()) { return ['success' => false, 'message' => tkgadm_license_message()]; }
    tkgadm_queue_sync(0);
    return ['success' => true, 'message' => 'Đã xếp lịch đồng bộ Google Ads (chạy trong vòng 1 phút).'];
}

function tkgadm_do_sync_process() {
    return tkgadm_reconcile_google_ads();
}

function tkgadm_do_full_sync_google_ads() {
    return tkgadm_reconcile_google_ads();
}

/** Free $slots by unblocking the oldest local entries, then sync. */
function tkgadm_smart_rotate_google_ads_ips($slots_needed = 50, $unused = TKGADM_GADS_LIMIT) {
    if (!tkgadm_license_is_valid()) { return ['freed' => 0, 'message' => tkgadm_license_message()]; }
    $freed = tkgadm_rotate_oldest_local($slots_needed, 'Xoay vòng thủ công', tkgadm_current_actor());
    $sync = tkgadm_reconcile_google_ads();
    return ['freed' => $freed, 'message' => "Đã bỏ chặn $freed IP cũ nhất. " . $sync['message']];
}

function tkgadm_current_actor() {
    $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    return ($user && $user->exists()) ? $user->user_login : 'auto';
}
