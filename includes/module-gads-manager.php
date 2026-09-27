<?php
/**
 * Module: Google Ads IP Manager
 *
 * Quản lý trực tiếp danh sách IP đang bị chặn trên tài khoản Google Ads.
 * - Xem danh sách IP hiện tại (kèm thông tin trong DB plugin nếu có)
 * - Xóa IP chọn lọc hoặc xóa IP cũ nhất
 * - Chỉ hoạt động với chế độ Direct API (Customer ID + Developer Token)
 */

if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * AJAX: Lấy danh sách IP từ Google Ads
 * ============================================================================
 */
add_action('wp_ajax_tkgadm_gads_list_ips', 'tkgadm_ajax_gads_list_ips');
function tkgadm_ajax_gads_list_ips() {
    check_ajax_referer('tkgadm_gads_manager_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền.');

    $access_token = tkgadm_get_google_access_token();
    if (is_wp_error($access_token)) {
        wp_send_json_error('Không thể lấy access token: ' . $access_token->get_error_message());
    }

    $ids = tkgadm_get_gads_ids();
    $customer_id     = $ids['customer_id'];
    $developer_token = $ids['developer_token'];
    $manager_id      = $ids['manager_id'];

    if (!$customer_id || !$developer_token) {
        wp_send_json_error('Thiếu Customer ID hoặc Developer Token. Tính năng này yêu cầu chế độ Direct API.');
    }

    $gads_ips = tkgadm_get_google_ads_blocked_ips($access_token, $customer_id, $developer_token, $manager_id);
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
    foreach ((array) $db_rows as $row) {
        $db_map[$row['ip_address']] = $row;
    }

    // Normalize và enrich
    $enriched = [];
    foreach ($gads_ips as $entry) {
        $ip_raw = $entry['ip_address']; // Có thể là CIDR: 1.2.3.0/24
        // Tìm trong db_map theo IP gốc hoặc format wildcard
        $db_key = $ip_raw;
        if (!isset($db_map[$ip_raw])) {
            // Thử CIDR -> wildcard
            if (preg_match('/^(\d+\.\d+\.\d+)\.0\/24$/', $ip_raw, $m)) {
                $db_key = $m[1] . '.*';
            }
        }
        $db_info = $db_map[$db_key] ?? null;

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

    $access_token = tkgadm_get_google_access_token();
    if (is_wp_error($access_token)) {
        wp_send_json_error('Không thể lấy access token: ' . $access_token->get_error_message());
    }

    $ids = tkgadm_get_gads_ids();
    $customer_id     = $ids['customer_id'];
    $developer_token = $ids['developer_token'];
    $manager_id      = $ids['manager_id'];

    $result = tkgadm_remove_google_ads_ips(
        $access_token,
        $customer_id,
        $developer_token,
        array_values($resource_names),
        $manager_id
    );

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

    $result = tkgadm_smart_rotate_google_ads_ips($count, 999999); // force rotation bất kể số lượng
    wp_send_json_success($result);
}

/**
 * ============================================================================
 * ADMIN PAGE RENDER
 * ============================================================================
 */
function tkgadm_render_gads_manager_page() {
    if (!current_user_can('manage_options')) return;

    $has_direct_api = !empty(get_option('tkgadm_gads_developer_token'))
                   && !empty(get_option('tkgadm_gads_customer_id'))
                   && !empty(get_option('tkgadm_gads_refresh_token'));

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
                    Chỉ khả dụng với chế độ <strong>Direct API</strong>.
                </p>
            </div>
            <?php if ($has_direct_api): ?>
                <button id="btn-load-ips"
                        class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-5 rounded-lg transition shadow-sm flex items-center gap-2 border-none cursor-pointer">
                    <i class="fa-solid fa-rotate"></i> Tải danh sách IP
                </button>
            <?php endif; ?>
        </div>

        <?php if (!$has_direct_api): ?>
            <!-- No Direct API Warning -->
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 flex gap-4">
                <i class="fa-solid fa-triangle-exclamation text-amber-500 text-2xl mt-0.5 flex-shrink-0"></i>
                <div>
                    <h3 class="text-base font-bold text-amber-800 m-0 mb-1">Cần cấu hình Direct API</h3>
                    <p class="text-sm text-amber-700 m-0">
                        Tính năng này yêu cầu bạn nhập đầy đủ <strong>Developer Token</strong>, <strong>Customer ID</strong>
                        và kết nối Google OAuth trong trang <a href="<?php echo esc_url(admin_url('admin.php?page=tkgad-google-ads')); ?>"
                        class="underline">Cấu hình Google Ads (cũ)</a> hoặc
                        <a href="<?php echo esc_url(admin_url('admin.php?page=tkgad-settings')); ?>" class="underline">Cấu hình & Tích hợp</a>.
                    </p>
                    <p class="text-sm text-amber-700 mt-2 mb-0">
                        Nếu bạn đang dùng Central Service (pdl.vn), tính năng xem/xóa IP trực tiếp từ Google Ads hiện chưa được hỗ trợ qua Central Service.
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
