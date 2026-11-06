<?php
/**
 * Activity log: who blocked/unblocked what, when and why — with undo.
 *
 * Actions: block, unblock, rotate (auto unblock to free slots), whitelist (smart
 * auto-whitelist), merge (IPs folded into a /24 range), sync (summary only).
 */

if (!defined('ABSPATH')) exit;

function tkgadm_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'gads_toolkit_log';
}

function tkgadm_create_log_table() {
    global $wpdb;
    $table = tkgadm_log_table();
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        log_time DATETIME NOT NULL,
        action VARCHAR(20) NOT NULL,
        ip_address VARCHAR(255) NOT NULL DEFAULT '',
        reason TEXT DEFAULT NULL,
        actor VARCHAR(100) NOT NULL DEFAULT 'auto',
        undone TINYINT(1) NOT NULL DEFAULT 0,
        meta LONGTEXT DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY log_time (log_time),
        KEY ip_address (ip_address(64))
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * @return int|false Log id
 */
function tkgadm_log_action($action, $ip, $reason = '', $actor = null, array $meta = []) {
    global $wpdb;
    if ($actor === null) {
        $actor = tkgadm_current_actor();
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $ok = $wpdb->insert(tkgadm_log_table(), [
        'log_time' => current_time('mysql'),
        'action' => substr((string) $action, 0, 20),
        'ip_address' => substr((string) $ip, 0, 255),
        'reason' => (string) $reason,
        'actor' => substr((string) $actor, 0, 100),
        'meta' => $meta ? wp_json_encode($meta) : null,
    ]);
    return $ok ? (int) $wpdb->insert_id : false;
}

/**
 * Remove one entry from the local block list, keeping what is needed to undo.
 *
 * @param string $action unblock | rotate | whitelist | merge
 * @return bool
 */
function tkgadm_unblock_ip_internal($ip, $reason = '', $actor = null, $action = 'unblock') {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $row = $wpdb->get_row($wpdb->prepare("SELECT ip_address, blocked_time, reason, visit_count FROM $table WHERE ip_address = %s", $ip), ARRAY_A);
    if (!$row) {
        return false;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if (!$wpdb->delete($table, ['ip_address' => $ip])) {
        return false;
    }
    tkgadm_log_action($action, $ip, $reason, $actor, ['blocked' => $row]);
    tkgadm_queue_sync();
    return true;
}

/** Put back a block row exactly as it was (undo of unblock/rotate/whitelist). */
function tkgadm_restore_block_row(array $row) {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_blocked';
    if (empty($row['ip_address'])) {
        return false;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE ip_address = %s", $row['ip_address']))) {
        return true;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return (bool) $wpdb->insert($table, [
        'ip_address' => $row['ip_address'],
        'blocked_time' => !empty($row['blocked_time']) ? $row['blocked_time'] : current_time('mysql'),
        'reason' => isset($row['reason']) ? $row['reason'] : '',
        'visit_count' => isset($row['visit_count']) ? (int) $row['visit_count'] : 0,
    ]);
}

/**
 * Undo one log entry.
 * @return true|WP_Error
 */
function tkgadm_undo_log_entry($id) {
    global $wpdb;
    $table = tkgadm_log_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
    if (!$entry) {
        return new WP_Error('not_found', 'Không tìm thấy mục nhật ký.');
    }
    if ((int) $entry['undone'] === 1) {
        return new WP_Error('already_undone', 'Mục này đã được hoàn tác.');
    }
    $meta = $entry['meta'] ? json_decode($entry['meta'], true) : [];
    $ip = $entry['ip_address'];
    $actor = tkgadm_current_actor();

    switch ($entry['action']) {
        case 'block':
            if (!tkgadm_unblock_ip_internal($ip, 'Hoàn tác chặn #' . $id, $actor, 'unblock')) {
                return new WP_Error('state_changed', 'IP không còn trong danh sách chặn.');
            }
            break;
        case 'unblock':
        case 'rotate':
            if (empty($meta['blocked']) || !tkgadm_restore_block_row($meta['blocked'])) {
                return new WP_Error('restore_failed', 'Không khôi phục được IP vào danh sách chặn.');
            }
            tkgadm_log_action('block', $ip, 'Hoàn tác #' . $id . ': ' . ($meta['blocked']['reason'] ?? ''), $actor);
            tkgadm_queue_sync();
            break;
        case 'whitelist':
            if (function_exists('tkgadm_remove_whitelist_ip')) {
                tkgadm_remove_whitelist_ip($ip);
            }
            if (!empty($meta['blocked'])) {
                tkgadm_restore_block_row($meta['blocked']);
            }
            tkgadm_log_action('block', $ip, 'Hoàn tác whitelist #' . $id, $actor);
            tkgadm_queue_sync();
            break;
        case 'merge':
            $range_table = $wpdb->prefix . 'gads_toolkit_blocked';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($range_table, ['ip_address' => $ip]);
            foreach ((array) ($meta['members'] ?? []) as $row) {
                tkgadm_restore_block_row($row);
            }
            tkgadm_log_action('unblock', $ip, 'Hoàn tác gộp dải #' . $id, $actor);
            tkgadm_queue_sync();
            break;
        default:
            return new WP_Error('not_undoable', 'Mục này không thể hoàn tác.');
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->update($table, ['undone' => 1], ['id' => $id]);
    return true;
}

/** Keep 180 days and at most 20,000 rows. */
function tkgadm_trim_activity_log() {
    global $wpdb;
    $table = tkgadm_log_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE log_time < %s", wp_date('Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS)));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $cut = $wpdb->get_var("SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET 20000");
    if ($cut) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id <= %d", $cut));
    }
}

function tkgadm_log_action_label($action) {
    $labels = [
        'block' => 'Chặn', 'unblock' => 'Bỏ chặn', 'rotate' => 'Xoay vòng',
        'whitelist' => 'Whitelist', 'merge' => 'Gộp dải', 'sync' => 'Đồng bộ',
    ];
    return isset($labels[$action]) ? $labels[$action] : $action;
}

/* ---------------------------------------------------------------------------
 * AJAX
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_tkgadm_get_activity_log', 'tkgadm_ajax_get_activity_log');
function tkgadm_ajax_get_activity_log() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }
    check_ajax_referer('tkgadm_nonce', 'nonce');

    global $wpdb;
    $table = tkgadm_log_table();
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $per_page = 25;
    $action = sanitize_key(wp_unslash($_POST['filter_action'] ?? ''));
    $search = sanitize_text_field(wp_unslash($_POST['search'] ?? ''));

    $where = ['1=1'];
    $params = [];
    if ($action !== '' && in_array($action, ['block', 'unblock', 'rotate', 'whitelist', 'merge', 'sync'], true)) {
        $where[] = 'action = %s';
        $params[] = $action;
    }
    if ($search !== '') {
        $where[] = 'ip_address LIKE %s';
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }
    $where_sql = implode(' AND ', $where);
    $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
    $list_sql = "SELECT id, log_time, action, ip_address, reason, actor, undone FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($params, [$per_page, ($page - 1) * $per_page])), ARRAY_A);

    $items = [];
    foreach ((array) $rows as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'time' => mysql2date('d/m/Y H:i', $row['log_time']),
            'action' => $row['action'],
            'action_label' => tkgadm_log_action_label($row['action']),
            'ip' => $row['ip_address'],
            'reason' => $row['reason'],
            'actor' => $row['actor'],
            'undone' => (int) $row['undone'] === 1,
            'undoable' => in_array($row['action'], ['block', 'unblock', 'rotate', 'whitelist', 'merge'], true),
        ];
    }
    wp_send_json_success(['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per_page))]);
}

add_action('wp_ajax_tkgadm_undo_activity', 'tkgadm_ajax_undo_activity');
function tkgadm_ajax_undo_activity() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Không có quyền truy cập.', 403); return; }
    if (!tkgadm_license_is_valid()) { wp_send_json_error(tkgadm_license_message(), 403); return; }
    check_ajax_referer('tkgadm_nonce', 'nonce');

    $result = tkgadm_undo_log_entry((int) ($_POST['id'] ?? 0));
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success(['message' => 'Đã hoàn tác. Google Ads sẽ được đồng bộ trong ít phút.']);
}
