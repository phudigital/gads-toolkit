<?php
/**
 * Module: Google Ads IP Manager
 *
 * Quản lý trực tiếp danh sách IP đang bị chặn trên tài khoản Google Ads.
 * - Xem danh sách IP hiện tại (kèm thông tin trong DB plugin nếu có)
 * - Xóa IP chọn lọc hoặc xóa IP cũ nhất
 * - Hỗ trợ Central Service (OAuth) và Direct API
 */

if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * AJAX: Lấy danh sách IP từ Google Ads
 * ============================================================================
 */

add_action("wp_ajax_tkgadm_ar_save_config", "tkgadm_ajax_ar_save_config");
function tkgadm_ajax_ar_save_config() {
    check_ajax_referer("tkgadm_gads_manager_nonce", "nonce");
    if (!current_user_can("manage_options")) wp_send_json_error("Không có quyền.");

    $enabled = isset($_POST["enabled"]) ? sanitize_text_field($_POST["enabled"]) : "0";
    $threshold = isset($_POST["threshold"]) ? intval($_POST["threshold"]) : 450;
    $amount = isset($_POST["amount"]) ? intval($_POST["amount"]) : 50;

    update_option("tkgadm_ar_enabled", $enabled);
    update_option("tkgadm_ar_threshold", $threshold);
    update_option("tkgadm_ar_amount", $amount);

    wp_send_json_success(["message" => "Đã lưu thành công."]);
}

add_action("wp_ajax_tkgadm_gads_save_auto_sync", "tkgadm_ajax_gads_save_auto_sync");
function tkgadm_ajax_gads_save_auto_sync() {
    check_ajax_referer("tkgadm_gads_manager_nonce", "nonce");
    if (!current_user_can("manage_options")) wp_send_json_error("Không có quyền.");

    $auto_sync = isset($_POST["auto_sync"]) ? sanitize_text_field($_POST["auto_sync"]) : "1";
    update_option("tkgadm_gads_auto_sync", $auto_sync);

    wp_send_json_success(["message" => "Đã lưu thành công."]);
}
add_action('wp_ajax_tkgadm_gads_list_ips', 'tkgadm_ajax_gads_list_ips');
function tkgadm_ajax_gads_list_ips() {
    check_ajax_referer('tkgadm_gads_manager_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $gads_ips = tkgadm_list_connected_google_ads_ips();
    if (is_wp_error($gads_ips)) {
        wp_send_json_error($gads_ips->get_error_message());
    }

    // Enrich với dữ liệu từ DB plugin
    global $wpdb;
    $blocked_table = $wpdb->prefix . 'gads_toolkit_blocked';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $db_rows = $wpdb->get_results(
        "SELECT ip_address, blocked_time, reason, visit_count FROM $blocked_table",
        ARRAY_A
    );
    $db_map = [];
    $subnet_map = []; // Map 3 octet đầu tiên sang DB row
    foreach ((array) $db_rows as $row) {
        $db_map[$row['ip_address']] = $row;
        if (preg_match('/^(\d+\.\d+\.\d+)/', $row['ip_address'], $m)) {
            $subnet_map[$m[1]] = $row; // Lưu IP đầu tiên tìm thấy trong dải /24
        }
    }

    // Normalize và enrich
    $enriched = [];
    foreach ($gads_ips as $entry) {
        $ip_raw = $entry['ip_address']; // Có thể là CIDR: 1.2.3.0/24
        
        $db_info = $db_map[$ip_raw] ?? null;
        
        if (!$db_info && preg_match('/^(\d+\.\d+\.\d+)\.0\/24$/', $ip_raw, $m)) {
            $subnet = $m[1];
            if (isset($subnet_map[$subnet])) {
                $db_info = $subnet_map[$subnet];
            } elseif (isset($db_map[$subnet . '.*'])) {
                $db_info = $db_map[$subnet . '.*'];
            }
        }

        $enriched[] = [
            'resource_name' => $entry['resource_name'],
            'ip_address'    => $ip_raw,
            'blocked_time'  => $db_info ? $db_info['blocked_time'] : null,
            'reason'        => $db_info ? $db_info['reason']       : null,
            'visit_count'   => $db_info ? intval($db_info['visit_count']) : 0,
            'in_db'         => (bool) $db_info,
        ];
    }

    // Sắp xếp: IP cũ nhất (null = không có trong DB) lên đầu để dễ xóa
    usort($enriched, function($a, $b) {
        if (!$a['blocked_time'] && !$b['blocked_time']) return 0;
        if (!$a['blocked_time']) return 1;
        if (!$b['blocked_time']) return -1;
        return strcmp($a['blocked_time'], $b['blocked_time']); // cũ nhất lên đầu
    });

    wp_send_json_success([
        'total' => count($enriched),
        'limit' => 500,
        'ips'   => $enriched,
    ]);
}

/**
 * ============================================================================
 * AJAX: Xóa các IP được chọn khỏi Google Ads
 * ============================================================================
 */
add_action('wp_ajax_tkgadm_gads_delete_ips', 'tkgadm_ajax_gads_delete_ips');
function tkgadm_ajax_gads_delete_ips() {
    check_ajax_referer('tkgadm_gads_manager_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $raw_rns = $_POST['resource_names'] ?? [];
    if (!is_array($raw_rns) || empty($raw_rns)) {
        wp_send_json_error('Không có resource_name nào được chọn.');
    }

    // Sanitize: resource_name có dạng customers/xxx/customerNegativeCriteria/yyy
    $resource_names = array_filter(array_map('sanitize_text_field', $raw_rns));
    if (empty($resource_names)) {
        wp_send_json_error('Danh sách resource_name không hợp lệ.');
    }

    $result = tkgadm_remove_connected_google_ads_ips(array_values($resource_names));

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    $count = count($resource_names);
    wp_send_json_success([
        'deleted' => $count,
        'message' => "Đã xóa $count IP khỏi Google Ads thành công.",
    ]);
}

/**
 * ============================================================================
 * AJAX: Xóa N IP cũ nhất khỏi Google Ads
 * ============================================================================
 */
add_action('wp_ajax_tkgadm_gads_delete_oldest', 'tkgadm_ajax_gads_delete_oldest');
function tkgadm_ajax_gads_delete_oldest() {
    check_ajax_referer('tkgadm_gads_manager_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $count = max(1, intval($_POST['count'] ?? 50));

    global $wpdb;
    $blocked_table = $wpdb->prefix . 'gads_toolkit_blocked';
    
    // Tìm IP cũ nhất
    $oldest_ips = $wpdb->get_col($wpdb->prepare("SELECT ip_address FROM $blocked_table ORDER BY blocked_time ASC LIMIT %d", $count));
    
    if (empty($oldest_ips)) {
        error_log("GADS-TOOLKIT: No IPs found to delete.");
        wp_send_json_success(['message' => 'Danh sách IP chặn đang trống.', 'freed' => 0]);
    }

    $deleted = 0;
    foreach ($oldest_ips as $ip) {
        $ip_key = preg_replace('/^(\d+\.\d+\.\d+)\.0\/24$/', '$1.*', $ip);
        $res1 = $wpdb->delete($blocked_table, ['ip_address' => $ip]);
        $res2 = $wpdb->delete($blocked_table, ['ip_address' => $ip_key]);
        if ($res1 || $res2) {
            $deleted++;
        } else {
            error_log("GADS-TOOLKIT: Failed to delete IP $ip (res1=" . var_export($res1, true) . ", res2=" . var_export($res2, true) . ")");
        }
    }

    error_log("GADS-TOOLKIT: Deleted $deleted out of " . count($oldest_ips) . " oldest IPs.");
    wp_send_json_success([
        'message' => "Đã xóa (bỏ chặn) thành công {$deleted} IP cũ nhất khỏi website.",
        'freed' => $deleted
    ]);
}

/**
 * ============================================================================
 * ADMIN PAGE RENDER
 * ============================================================================
 */
function tkgadm_render_gads_manager_page() {
    if (!current_user_can('manage_options')) return;

    $can_manage_ips = (bool) tkgadm_get_gads_connection_mode();

    $nonce = wp_create_nonce('tkgadm_gads_manager_nonce');
    ?>
    <div class="wp-wrap space-y-6" style="padding: 20px 20px 40px 0;">

        <!-- Header -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-wrap justify-between items-center gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2 m-0 pb-1">
                    <i class="fa-brands fa-google text-blue-600"></i> Quản Lý IP Trên Google Ads
                </h1>
                <p class="text-sm text-gray-500 m-0">
                    Xem và xóa trực tiếp danh sách IP đang bị chặn trên tài khoản Google Ads.
                    Sử dụng tài khoản Google Ads đã kết nối trong Cấu hình & Tích hợp.
                </p>
            </div>
            <?php if ($can_manage_ips): ?>
                <button id="btn-load-ips"
                        class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-5 rounded-lg transition shadow-sm flex items-center gap-2 border-none cursor-pointer">
                    <i class="fa-solid fa-rotate"></i> Tải danh sách IP
                </button>
            <?php endif; ?>
        </div>

        <?php if (!$can_manage_ips): ?>
            <!-- No Direct API Warning -->
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 flex gap-4">
                <i class="fa-solid fa-triangle-exclamation text-amber-500 text-2xl mt-0.5 flex-shrink-0"></i>
                <div>
                    <h3 class="text-base font-bold text-amber-800 m-0 mb-1">Cần kết nối Google Ads</h3>
                    <p class="text-sm text-amber-700 m-0">
                        Kiểm tra API Key, Customer ID và kết nối Google Ads trong
                        <a href="<?php echo esc_url(admin_url('admin.php?page=tkgad-settings')); ?>" class="underline">Cấu hình & Tích hợp</a>.
                        Khi dùng Central Service, không cần nhập Developer Token tại website.
                    </p>
                </div>
            </div>
        <?php else: ?>

        <!-- Stats Bar (filled by JS) -->
        <div id="gads-stats-bar" class="hidden bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="flex flex-wrap gap-6 items-center">
                <div class="flex items-center gap-3">
                    <div class="text-3xl font-bold text-gray-800" id="stat-count">—</div>
                    <div class="text-sm text-gray-500">/ 500 IP<br><span class="text-xs">đang bị chặn</span></div>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <div class="flex justify-between text-xs text-gray-500 mb-1">
                        <span>Đã dùng</span>
                        <span id="stat-pct">0%</span>
                    </div>
                    <div class="bg-gray-200 rounded-full h-3 overflow-hidden">
                        <div id="stat-bar" class="h-3 rounded-full transition-all duration-500 bg-blue-500" style="width:0%"></div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button id="btn-delete-selected"
                            disabled
                            class="bg-red-500 hover:bg-red-600 disabled:bg-gray-300 disabled:cursor-not-allowed text-white text-sm font-medium py-2 px-4 rounded-lg transition border-none cursor-pointer flex items-center gap-2">
                        <i class="fa-solid fa-trash-can"></i>
                        Xóa đã chọn (<span id="selected-count">0</span>)
                    </button>
                    <div class="flex items-center gap-2">
                        <button id="btn-delete-oldest"
                                class="bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium py-2 px-4 rounded-lg transition border-none cursor-pointer flex items-center gap-2">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                            Xóa <input type="number" id="oldest-count" value="50" min="1" max="500"
                                       class="w-14 text-center text-gray-800 bg-white border border-orange-300 rounded px-1 py-0.5 text-sm mx-1"
                                       onclick="event.stopPropagation()">
                            IP cũ nhất
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Status -->
        <div id="gads-action-status" class="hidden p-4 rounded-xl text-sm font-medium"></div>

        <!-- IP Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <!-- Table Controls -->
            <div class="p-4 border-b border-gray-100 flex flex-wrap gap-3 items-center justify-between">
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-600">
                        <input type="checkbox" id="select-all" class="w-4 h-4 rounded accent-blue-600">
                        Chọn tất cả
                    </label>
                    <span class="text-gray-300">|</span>
                    <label class="flex items-center gap-2 cursor-pointer text-xs text-orange-600">
                        <input type="checkbox" id="select-oldest" class="w-4 h-4 rounded accent-orange-500">
                        Chọn IP cũ nhất (không có trong DB)
                    </label>
                </div>
                <div class="flex gap-2 items-center">
                    <input type="text" id="gads-search" placeholder="🔍 Tìm IP..."
                           class="text-sm border border-gray-300 rounded-lg p-2 w-44 focus:ring-2 focus:ring-blue-400 focus:outline-none">
                    <select id="gads-filter" class="text-sm border border-gray-300 rounded-lg p-2 bg-white focus:ring-2 focus:ring-blue-400 focus:outline-none">
                        <option value="all">Tất cả IP</option>
                        <option value="in_db">Có trong DB plugin</option>
                        <option value="no_db">Không có trong DB</option>
                    </select>
                </div>
            </div>

            <!-- Loading placeholder -->
            <div id="gads-loading" class="hidden py-16 text-center text-gray-400">
                <i class="fa-solid fa-spinner fa-spin text-3xl mb-3"></i>
                <p class="text-sm m-0">Đang tải danh sách IP từ Google Ads...</p>
            </div>

            <!-- Empty placeholder -->
            <div id="gads-empty" class="hidden py-16 text-center text-gray-400">
                <i class="fa-solid fa-circle-check text-4xl mb-3 text-green-400"></i>
                <p class="text-sm m-0">Không có IP nào đang bị chặn trên Google Ads.</p>
            </div>

            <!-- Not loaded -->
            <div id="gads-not-loaded" class="py-16 text-center text-gray-400">
                <i class="fa-brands fa-google text-4xl mb-3 text-blue-300"></i>
                <p class="text-sm m-0">Nhấn <strong>Tải danh sách IP</strong> để bắt đầu.</p>
                <p class="text-xs mt-1 m-0 text-gray-400">Plugin sẽ gọi Google Ads API để lấy danh sách IP đang bị chặn.</p>
            </div>

            <!-- Table -->
            <div id="gads-table-wrap" class="hidden overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 bg-gray-50 border-b border-gray-100">
                            <th class="p-3 w-8"></th>
                            <th class="p-3 font-medium">IP Address</th>
                            <th class="p-3 font-medium">Ngày chặn (trong DB)</th>
                            <th class="p-3 font-medium">Lý do</th>
                            <th class="p-3 font-medium text-center">Số click</th>
                            <th class="p-3 font-medium">Trạng thái</th>
                            <th class="p-3 font-medium text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="gads-ip-tbody">
                    </tbody>
                </table>
                <div class="p-3 text-xs text-gray-400 text-right border-t border-gray-50" id="gads-table-footer"></div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script>
    (function($) {
        var nonce   = '<?php echo esc_js($nonce); ?>';
        var allIps  = [];   // raw data từ API
        var visible = [];   // sau khi filter

        // ================================================================
        // LOAD IPs
        // ================================================================
        $('#btn-load-ips').on('click', loadIps);

        function loadIps() {
            $('#gads-not-loaded').hide();
            $('#gads-empty').hide();
            $('#gads-table-wrap').hide();
            $('#gads-stats-bar').hide();
            $('#gads-loading').show();
            $('#gads-action-status').hide();
            $('#btn-load-ips').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Đang tải...');

            $.post(ajaxurl, { action: 'tkgadm_gads_list_ips', nonce: nonce }, function(res) {
                $('#gads-loading').hide();
                $('#btn-load-ips').prop('disabled', false).html('<i class="fa-solid fa-rotate"></i> Tải danh sách IP');

                if (!res.success) {
                    showStatus('error', '❌ ' + res.data);
                    $('#gads-not-loaded').show();
                    return;
                }

                allIps = res.data.ips || [];
                updateStats(res.data.total, res.data.limit);

                if (allIps.length === 0) {
                    $('#gads-empty').show();
                } else {
                    renderTable(allIps);
                    $('#gads-table-wrap').show();
                    $('#gads-stats-bar').show();
                }
            }).fail(function() {
                $('#gads-loading').hide();
                $('#btn-load-ips').prop('disabled', false).html('<i class="fa-solid fa-rotate"></i> Tải danh sách IP');
                showStatus('error', '❌ Lỗi kết nối máy chủ.');
                $('#gads-not-loaded').show();
            });
        }

        // ================================================================
        // STATS BAR
        // ================================================================
        function updateStats(count, limit) {
            var pct = Math.round((count / limit) * 100);
            var color = pct >= 90 ? 'bg-red-500' : (pct >= 70 ? 'bg-orange-500' : 'bg-blue-500');
            $('#stat-count').text(count);
            $('#stat-pct').text(pct + '%');
            $('#stat-bar').css('width', pct + '%').attr('class', 'h-3 rounded-full transition-all duration-500 ' + color);
            updateSelectedCount();
        }

        // ================================================================
        // RENDER TABLE
        // ================================================================
        function renderTable(ips) {
            var q    = $('#gads-search').val().toLowerCase();
            var filt = $('#gads-filter').val();

            visible = ips.filter(function(ip) {
                var matchSearch = !q || ip.ip_address.toLowerCase().indexOf(q) !== -1 ||
                                  (ip.reason && ip.reason.toLowerCase().indexOf(q) !== -1);
                var matchFilter = filt === 'all' ||
                                  (filt === 'in_db' && ip.in_db) ||
                                  (filt === 'no_db' && !ip.in_db);
                return matchSearch && matchFilter;
            });

            var html = '';
            visible.forEach(function(ip, i) {
                var rowClass    = i % 2 === 0 ? '' : 'bg-gray-50/50';
                var statusBadge = ip.in_db
                    ? '<span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Có trong DB</span>'
                    : '<span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">Không có trong DB</span>';
                var dateStr  = ip.blocked_time ? formatDate(ip.blocked_time) : '<span class="text-gray-400 italic">Không rõ</span>';
                var reasonTx = ip.reason ? escHtml(ip.reason.substring(0, 60)) + (ip.reason.length > 60 ? '…' : '') : '<span class="text-gray-400">—</span>';
                var clicks   = ip.visit_count > 0 ? ip.visit_count : '—';

                html += '<tr class="gads-row ' + rowClass + ' hover:bg-blue-50/40 transition border-b border-gray-50" '
                      + 'data-ip="' + escAttr(ip.ip_address) + '" '
                      + 'data-in-db="' + (ip.in_db ? '1' : '0') + '" '
                      + 'data-rn="' + escAttr(ip.resource_name) + '">'
                      + '<td class="p-3">'
                      +   '<input type="checkbox" class="ip-checkbox w-4 h-4 rounded accent-blue-600" '
                      +   'data-rn="' + escAttr(ip.resource_name) + '">'
                      + '</td>'
                      + '<td class="p-3 font-mono font-medium text-gray-800">' + escHtml(ip.ip_address) + '</td>'
                      + '<td class="p-3 text-gray-600 text-xs">' + dateStr + '</td>'
                      + '<td class="p-3 text-gray-500 text-xs max-w-[220px]" title="' + escAttr(ip.reason || '') + '">' + reasonTx + '</td>'
                      + '<td class="p-3 text-center text-gray-600">' + clicks + '</td>'
                      + '<td class="p-3">' + statusBadge + '</td>'
                      + '<td class="p-3 text-right">'
                      +   '<button class="btn-delete-one text-red-400 hover:text-red-600 hover:bg-red-50 border-none bg-transparent cursor-pointer text-xs py-1 px-2 rounded transition" '
                      +   'data-rn="' + escAttr(ip.resource_name) + '" data-ip="' + escAttr(ip.ip_address) + '">'
                      +   '<i class="fa-solid fa-trash-can"></i> Xóa'
                      +   '</button>'
                      + '</td>'
                      + '</tr>';
            });

            $('#gads-ip-tbody').html(html);
            $('#gads-table-footer').text('Hiển thị ' + visible.length + ' / ' + allIps.length + ' IP');
            updateSelectedCount();
        }

        // ================================================================
        // FILTER & SEARCH
        // ================================================================
        $('#gads-search, #gads-filter').on('input change', function() {
            if (allIps.length) renderTable(allIps);
        });

        // ================================================================
        // SELECT ALL / SELECT OLDEST
        // ================================================================
        $('#select-all').on('change', function() {
            var checked = $(this).is(':checked');
            $('#gads-ip-tbody .ip-checkbox:visible').prop('checked', checked);
            if (!checked) $('#select-oldest').prop('checked', false);
            updateSelectedCount();
        });

        $('#select-oldest').on('change', function() {
            var checked = $(this).is(':checked');
            // Chọn những IP không có trong DB (không rõ ngày = cũ / manual trên GAs)
            $('#gads-ip-tbody .gads-row').each(function() {
                var inDb = $(this).data('in-db') === 1 || $(this).data('in-db') === '1';
                if (!inDb) {
                    $(this).find('.ip-checkbox').prop('checked', checked);
                }
            });
            updateSelectedCount();
        });

        $(document).on('change', '.ip-checkbox', function() {
            updateSelectedCount();
            var allChecked = $('#gads-ip-tbody .ip-checkbox:visible').length ===
                             $('#gads-ip-tbody .ip-checkbox:visible:checked').length;
            $('#select-all').prop('checked', allChecked);
        });

        function updateSelectedCount() {
            var count = $('#gads-ip-tbody .ip-checkbox:checked').length;
            $('#selected-count').text(count);
            $('#btn-delete-selected').prop('disabled', count === 0);
        }

        // ================================================================
        // DELETE SELECTED
        // ================================================================
        $('#btn-delete-selected').on('click', function() {
            var rns = [];
            var ips = [];
            $('#gads-ip-tbody .ip-checkbox:checked').each(function() {
                rns.push($(this).data('rn'));
                ips.push($(this).closest('tr').data('ip'));
            });

            if (rns.length === 0) return;

            if (!confirm('Xóa ' + rns.length + ' IP khỏi Google Ads?\n\n' + ips.slice(0, 10).join('\n') + (ips.length > 10 ? '\n...' : '') + '\n\nHành động này không thể hoàn tác!')) return;

            doDeleteIps(rns, rns.length + ' IP đã chọn');
        });

        // DELETE ONE
        $(document).on('click', '.btn-delete-one', function() {
            var rn = $(this).data('rn');
            var ip = $(this).data('ip');
            if (!confirm('Xóa IP ' + ip + ' khỏi Google Ads?\n\nHành động này không thể hoàn tác!')) return;
            doDeleteIps([rn], ip);
        });

        function doDeleteIps(resourceNames, label) {
            showStatus('loading', '<i class="fa-solid fa-spinner fa-spin"></i> Đang xóa ' + label + ' khỏi Google Ads...');
            $('#btn-delete-selected, #btn-delete-oldest, #btn-load-ips').prop('disabled', true);

            $.post(ajaxurl, {
                action: 'tkgadm_gads_delete_ips',
                nonce: nonce,
                resource_names: resourceNames
            }, function(res) {
                $('#btn-delete-selected, #btn-delete-oldest, #btn-load-ips').prop('disabled', false);
                if (res.success) {
                    showStatus('success', '✅ ' + res.data.message);
                    loadIps(); // Reload danh sách
                } else {
                    showStatus('error', '❌ ' + res.data);
                }
            }).fail(function() {
                $('#btn-delete-selected, #btn-delete-oldest, #btn-load-ips').prop('disabled', false);
                showStatus('error', '❌ Lỗi kết nối máy chủ.');
            });
        }

        // ================================================================
        // DELETE OLDEST
        // ================================================================
        $('#btn-delete-oldest').on('click', function() {
            var count = parseInt($('#oldest-count').val()) || 50;
            if (count < 1) { alert('Vui lòng nhập số lượng hợp lệ.'); return; }

            if (!confirm('Xóa ' + count + ' IP cũ nhất khỏi Google Ads?\n\nHành động này không thể hoàn tác!')) return;

            showStatus('loading', '<i class="fa-solid fa-spinner fa-spin"></i> Đang xóa ' + count + ' IP cũ nhất...');
            $('#btn-delete-selected, #btn-delete-oldest, #btn-load-ips').prop('disabled', true);

            $.post(ajaxurl, {
                action: 'tkgadm_gads_delete_oldest',
                nonce: nonce,
                count: count
            }, function(res) {
                $('#btn-delete-selected, #btn-delete-oldest, #btn-load-ips').prop('disabled', false);
                if (res.success) {
                    var freed = res.data.freed || 0;
                    showStatus(freed > 0 ? 'success' : 'info', (freed > 0 ? '✅ ' : 'ℹ️ ') + res.data.message);
                    if (freed > 0) loadIps();
                } else {
                    showStatus('error', '❌ ' + res.data);
                }
            }).fail(function() {
                $('#btn-delete-selected, #btn-delete-oldest, #btn-load-ips').prop('disabled', false);
                showStatus('error', '❌ Lỗi kết nối máy chủ.');
            });
        });

        // ================================================================
        // HELPERS
        // ================================================================
        function showStatus(type, msg) {
            var cls = {
                success : 'bg-green-50 text-green-800 border border-green-200',
                error   : 'bg-red-50 text-red-800 border border-red-200',
                loading : 'bg-sky-50 text-sky-800 border border-sky-200',
                info    : 'bg-gray-50 text-gray-700 border border-gray-200',
            }[type] || '';
            $('#gads-action-status').attr('class', cls + ' p-4 rounded-xl text-sm font-medium').html(msg).show();
        }

        function formatDate(str) {
            if (!str) return '';
            var d = new Date(str.replace(' ', 'T'));
            return ('0' + d.getDate()).slice(-2) + '/' +
                   ('0' + (d.getMonth()+1)).slice(-2) + '/' +
                   d.getFullYear() + ' ' +
                   ('0' + d.getHours()).slice(-2) + ':' +
                   ('0' + d.getMinutes()).slice(-2);
        }

        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function escAttr(s) { return escHtml(s); }

    })(jQuery);
    </script>
    <?php
}

add_action('wp_ajax_tkgadm_gads_full_sync', 'tkgadm_ajax_gads_full_sync');
function tkgadm_ajax_gads_full_sync() {
    check_ajax_referer('tkgadm_gads_manager_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    if (!function_exists('tkgadm_do_full_sync_google_ads')) {
        require_once plugin_dir_path(__FILE__) . 'module-google-ads.php';
    }

    $ids = tkgadm_get_gads_ids();
    $has_direct = !empty($ids['customer_id']) && !empty($ids['developer_token']) && !empty($ids['refresh_token']);
    
    if (!$has_direct && tkgadm_is_using_central_service()) {
        wp_send_json_error('Full Sync hiện chỉ hỗ trợ chế độ Direct API.');
    }

    $result = tkgadm_do_full_sync_google_ads();

    if ($result['success']) {
        wp_send_json_success(['message' => $result['message']]);
    } else {
        wp_send_json_error($result['message']);
    }
}
