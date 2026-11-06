<?php
/**
 * Insights: per-IP fraud score and /24 merge suggestions.
 *
 * Fraud score (0-100) over the last 30 days of ad traffic for an IP:
 *   - Ad clicks (unique gclid)              up to 40  (8 per click)
 *   - Clicks per active day >= 3           +10
 *   - Share of ad visits under 3 seconds    up to 25
 *   - Share of ad visits without JavaScript up to 15  (only rows recorded since 4.4)
 *   - Automation / empty user agent         +10
 * Levels: >= 70 high, >= 40 medium, else low. Signals explain each point source.
 */

if (!defined('ABSPATH')) exit;

/**
 * @param string[] $ips
 * @return array ip => ['score' => int, 'level' => string, 'signals' => string[], ...]
 */
function tkgadm_get_fraud_scores(array $ips, $days = 30) {
    $ips = array_values(array_unique(array_filter($ips, function ($ip) {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP);
    })));
    if (!$ips) {
        return [];
    }
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    $since = wp_date('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    $results = [];

    foreach (array_chunk($ips, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ip_address,
                    COUNT(DISTINCT gclid) AS clicks,
                    COUNT(*) AS ad_rows,
                    SUM(CASE WHEN time_on_page < 3 THEN 1 ELSE 0 END) AS short_rows,
                    SUM(CASE WHEN has_js IS NOT NULL THEN 1 ELSE 0 END) AS tracked_rows,
                    SUM(CASE WHEN has_js = 1 THEN 1 ELSE 0 END) AS js_rows,
                    COUNT(DISTINCT SUBSTRING(visit_time, 1, 10)) AS active_days,
                    MAX(user_agent) AS sample_ua,
                    SUM(CASE WHEN user_agent = '' OR user_agent LIKE '%%HeadlessChrome%%' OR user_agent LIKE '%%PhantomJS%%'
                              OR user_agent LIKE '%%python%%' OR user_agent LIKE '%%curl%%' THEN 1 ELSE 0 END) AS bot_rows
             FROM $table
             WHERE ip_address IN ($placeholders) AND visit_time >= %s AND gclid IS NOT NULL AND gclid != ''
             GROUP BY ip_address",
            array_merge($chunk, [$since])
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            $results[$row['ip_address']] = tkgadm_score_from_row($row);
        }
    }

    foreach ($ips as $ip) {
        if (!isset($results[$ip])) {
            $results[$ip] = ['score' => 0, 'level' => 'low', 'signals' => [], 'clicks' => 0];
        }
    }
    return $results;
}

function tkgadm_score_from_row(array $row) {
    $clicks = (int) $row['clicks'];
    $ad_rows = max(1, (int) $row['ad_rows']);
    $days = max(1, (int) $row['active_days']);
    $score = 0;
    $signals = [];

    $click_points = min(40, $clicks * 8);
    if ($click_points) {
        $score += $click_points;
        $signals[] = "$clicks click quảng cáo";
    }
    if ($clicks / $days >= 3) {
        $score += 10;
        $signals[] = round($clicks / $days, 1) . ' click/ngày';
    }
    $short_share = (int) $row['short_rows'] / $ad_rows;
    if ($short_share > 0) {
        $score += (int) round(25 * $short_share);
        if ($short_share >= 0.5) {
            $signals[] = round($short_share * 100) . '% lượt dưới 3 giây';
        }
    }
    $tracked = (int) $row['tracked_rows'];
    if ($tracked > 0) {
        $no_js_share = 1 - ((int) $row['js_rows'] / $tracked);
        $score += (int) round(15 * $no_js_share);
        if ($no_js_share >= 0.5) {
            $signals[] = round($no_js_share * 100) . '% lượt không chạy JavaScript';
        }
    }
    if ((int) $row['bot_rows'] > 0) {
        $score += 10;
        $signals[] = 'Trình duyệt tự động / không có User-Agent';
    }
    $score = min(100, $score);
    return [
        'score' => $score,
        'level' => $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low'),
        'signals' => $signals,
        'clicks' => $clicks,
    ];
}

function tkgadm_fraud_level_label($level) {
    return ['high' => 'Cao', 'medium' => 'Trung bình', 'low' => 'Thấp'][$level] ?? 'Thấp';
}

/**
 * Groups of >= $min blocked single IPv4 addresses inside one /24 not yet blocked as a range.
 * Also reports IPv6 /64 groups (informational: Google Ads ranges are IPv4 /24 here).
 */
function tkgadm_get_subnet_suggestions($min = 3) {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_col("SELECT ip_address FROM $table");
    $existing = array_fill_keys((array) $rows, true);
    $groups = [];
    foreach ((array) $rows as $ip) {
        $key = tkgadm_subnet_key($ip);
        if ($key === '' || isset($existing[$key])) {
            continue;
        }
        $groups[$key][] = $ip;
    }
    $suggestions = [];
    foreach ($groups as $key => $members) {
        if (count($members) < $min) {
            continue;
        }
        $suggestions[] = [
            'subnet' => $key,
            'count' => count($members),
            'ips' => array_slice($members, 0, 50),
            'mergeable' => substr($key, -2) === '.*',
        ];
    }
    usort($suggestions, function ($a, $b) { return $b['count'] - $a['count']; });
    return array_slice($suggestions, 0, 50);
}

/**
 * Replace the single IPs of an IPv4 /24 with one "a.b.c.*" block.
 * @return array|WP_Error ['merged' => int]
 */
function tkgadm_merge_subnet($subnet) {
    if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.\*$/', $subnet, $m)
        || max((int) $m[1], (int) $m[2], (int) $m[3]) > 255) {
        return new WP_Error('invalid_subnet', 'Dải IP không hợp lệ.');
    }
    if (function_exists('tkgadm_is_ip_whitelisted') && tkgadm_is_ip_whitelisted($subnet)) {
        return new WP_Error('whitelisted', 'Dải này nằm trong whitelist.');
    }
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    $prefix = substr($subnet, 0, -1); // "a.b.c."
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $members = $wpdb->get_results($wpdb->prepare(
        "SELECT ip_address, blocked_time, reason, visit_count FROM $table WHERE ip_address LIKE %s",
        $wpdb->esc_like($prefix) . '%'
    ), ARRAY_A);
    $members = array_values(array_filter((array) $members, function ($row) use ($subnet) {
        return $row['ip_address'] !== $subnet && filter_var($row['ip_address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }));
    if (count($members) < 2) {
        return new WP_Error('too_few', 'Dải này chưa có đủ IP để gộp.');
    }
    // A whitelisted address inside the range would be blocked by the range: refuse.
    if (function_exists('tkgadm_get_whitelist')) {
        foreach (tkgadm_get_whitelist() as $entry) {
            if (strpos($entry, $prefix) === 0) {
                return new WP_Error('whitelist_conflict', "Không gộp được: $entry trong dải này đang được whitelist.");
            }
        }
    }

    $total_visits = array_sum(array_map(function ($r) { return (int) $r['visit_count']; }, $members));
    $reason = sprintf('Gộp dải: %d IP cùng %s', count($members), $subnet);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if (!$wpdb->insert($table, [
        'ip_address' => $subnet, 'blocked_time' => current_time('mysql'),
        'reason' => $reason, 'visit_count' => $total_visits,
    ])) {
        return new WP_Error('db_error', 'Không ghi được dải vào danh sách chặn.');
    }
    foreach ($members as $row) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($table, ['ip_address' => $row['ip_address']]);
    }
    tkgadm_log_action('merge', $subnet, $reason, tkgadm_current_actor(), ['members' => $members]);
    tkgadm_queue_sync(0);
    return ['merged' => count($members)];
}

/* ---------------------------------------------------------------------------
 * AJAX
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_tkgadm_get_subnet_suggestions', 'tkgadm_ajax_get_subnet_suggestions');
function tkgadm_ajax_get_subnet_suggestions() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }
    check_ajax_referer('tkgadm_nonce', 'nonce');
    wp_send_json_success(['suggestions' => tkgadm_get_subnet_suggestions()]);
}

add_action('wp_ajax_tkgadm_merge_subnet', 'tkgadm_ajax_merge_subnet');
function tkgadm_ajax_merge_subnet() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }
    check_ajax_referer('tkgadm_nonce', 'nonce');
    $result = tkgadm_merge_subnet(sanitize_text_field(wp_unslash($_POST['subnet'] ?? '')));
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success(['message' => sprintf('Đã gộp %d IP thành một dải. Google Ads sẽ được đồng bộ trong ít phút.', $result['merged'])]);
}

add_action('wp_ajax_tkgadm_get_fraud_scores', 'tkgadm_ajax_get_fraud_scores');
function tkgadm_ajax_get_fraud_scores() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }
    check_ajax_referer('tkgadm_nonce', 'nonce');
    $ips = isset($_POST['ips']) && is_array($_POST['ips']) ? array_map('sanitize_text_field', wp_unslash($_POST['ips'])) : [];
    $scores = tkgadm_get_fraud_scores(array_slice($ips, 0, 500));
    foreach ($scores as &$s) {
        $s['level_label'] = tkgadm_fraud_level_label($s['level']);
    }
    wp_send_json_success(['scores' => $scores]);
}
