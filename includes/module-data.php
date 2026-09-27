<?php
/**
 * Module: Data Maintenance
 * Manages Database size, clearing old logs, and maintenance tools.
 */

if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * 1. HELPER FUNCTIONS
 * ============================================================================
 */

/**
 * Lấy kích thước bảng trong database
 */
function tkgadm_get_table_size($table_name) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $row = $wpdb->get_row($wpdb->prepare("SHOW TABLE STATUS LIKE %s", $table_name));
    
    if ($row) {
        $size = $row->Data_length + $row->Index_length;
        return size_format($size);
    }
    return '0 B';
}

/**
 * Đếm số lượng record
 */
function tkgadm_get_table_count($table_name) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
}

/**
 * ============================================================================
 * 2. ADMIN UI (MAINTENANCE PAGE)
 * ============================================================================
 */

function tkgadm_render_maintenance_page() {
    global $wpdb;
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    
    $stats_size = tkgadm_get_table_size($table_stats);
    $stats_count = tkgadm_get_table_count($table_stats);
    
    $blocked_size = tkgadm_get_table_size($table_blocked);
    $blocked_count = tkgadm_get_table_count($table_blocked);

    // Get Min/Max Block Request date
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $date_range = $wpdb->get_row("SELECT MIN(blocked_time) as min_date, MAX(blocked_time) as max_date FROM $table_blocked");
    
    $default_start_date = $date_range && $date_range->min_date ? date('Y-m-d', strtotime($date_range->min_date)) : '';
    $default_end_date = $date_range && $date_range->max_date ? date('Y-m-d', strtotime($date_range->max_date)) : '';
    
    ?>
        <div class="wp-wrap space-y-6" style="padding: 20px 20px 40px 0;">
        <div class="space-y-6">
            <!-- Header -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2 m-0 pb-1">
                        <i class="fa-solid fa-database text-blue-600"></i> Quản Lý Dữ Liệu
                    </h1>
                    <p class="text-sm text-gray-500 m-0">Tra cứu IP bị chặn và tối ưu hóa cơ sở dữ liệu</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column: Database Stats & Cleanup -->
                <div class="space-y-6">
                    <!-- Stats -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                        <h3 class="text-base font-bold text-gray-800 mb-4 flex items-center gap-2 m-0 pb-2">
                            <i class="fa-solid fa-chart-pie text-gray-400"></i> Dung Lượng Bảng
                        </h3>
                        <div class="space-y-4">
                            <div class="bg-blue-50/50 p-4 rounded-lg border border-blue-100">
                                <div class="flex justify-between items-center mb-1">
                                    <strong class="text-blue-700 text-sm">Bảng Thống Kê (Logs)</strong>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600 mb-1">
                                    <span>Số dòng:</span> <span class="font-semibold text-gray-800"><?php echo number_format($stats_count); ?></span>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600">
                                    <span>Dung lượng:</span> <span class="font-semibold text-gray-800"><?php echo esc_html($stats_size); ?></span>
                                </div>
                            </div>
                            <div class="bg-red-50/50 p-4 rounded-lg border border-red-100">
                                <div class="flex justify-between items-center mb-1">
                                    <strong class="text-red-700 text-sm">Bảng IP Bị Chặn</strong>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600 mb-1">
                                    <span>Số IP:</span> <span class="font-semibold text-gray-800"><?php echo number_format($blocked_count); ?></span>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600">
                                    <span>Dung lượng:</span> <span class="font-semibold text-gray-800"><?php echo esc_html($blocked_size); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cleanup Tools -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 border-t-4 border-t-orange-400">
                        <h3 class="text-base font-bold text-gray-800 mb-4 flex items-center gap-2 m-0 pb-2">
                            <i class="fa-solid fa-broom text-orange-500"></i> Dọn Dẹp Dữ Liệu
                        </h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Xóa theo thời gian</label>
                                <div class="flex gap-2 mb-2 items-center">
                                    <input type="date" id="delete-from" class="w-full text-sm border border-gray-300 rounded-lg p-2 text-gray-700 focus:ring-2 focus:ring-blue-500 focus:outline-none h-10">
                                    <span class="text-gray-400"><i class="fa-solid fa-arrow-right"></i></span>
                                    <input type="date" id="delete-to" class="w-full text-sm border border-gray-300 rounded-lg p-2 text-gray-700 focus:ring-2 focus:ring-blue-500 focus:outline-none h-10">
                                </div>
                                <button type="button" id="btn-delete-range" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium py-2 rounded-lg transition border-none cursor-pointer">
                                    Thực hiện xóa
                                </button>
                            </div>
                            <div class="pt-3 border-t border-gray-100">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Xóa dữ liệu cũ hơn</label>
                                <select id="delete-age" class="w-full mb-2 text-sm border border-gray-300 rounded-lg p-2 text-gray-700 focus:ring-2 focus:ring-blue-500 focus:outline-none h-10 bg-white">
                                    <option value="365">1 năm</option>
                                    <option value="730">2 năm</option>
                                    <option value="1095" selected>3 năm</option>
                                    <option value="all">Toàn bộ Logs</option>
                                </select>
                                <button type="button" id="btn-delete-old" class="w-full bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 text-sm font-medium py-2 rounded-lg transition flex justify-center items-center gap-2 cursor-pointer">
                                    <i class="fa-solid fa-fire"></i> Xóa Nhanh
                                </button>
                            </div>
                            <div id="delete-status" class="hidden mt-2 p-2 rounded text-sm text-center"></div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: IP Management -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 h-full flex flex-col">
                        <div class="p-5 border-b border-gray-100 flex justify-between items-center">
                            <h3 class="text-base font-bold text-gray-800 flex items-center gap-2 m-0">
                                <i class="fa-solid fa-shield-halved text-red-500"></i> Quản Lý IP Bị Chặn
                                <span id="blocked-count-badge" class="text-xs font-medium bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">0 IP</span>
                            </h3>
                            <button type="button" id="btn-copy-blocked" class="bg-white hover:bg-gray-50 text-gray-700 text-sm font-medium py-1.5 px-3 rounded border border-gray-200 transition shadow-sm flex items-center gap-2 cursor-pointer" disabled>
                                <i class="fa-regular fa-copy text-blue-500"></i> Copy Danh Sách
                            </button>
                        </div>
                        
                        <!-- Filters -->
                        <div class="p-4 bg-gray-50 border-b border-gray-100 flex flex-wrap gap-4 items-end">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Số phiên tối thiểu</label>
                                <input type="number" id="filter-visit-count" value="0" min="0" class="w-24 text-sm border border-gray-300 rounded p-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 h-8">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Từ ngày</label>
                                <input type="date" id="filter-date-start" value="<?php echo esc_attr($default_start_date); ?>" class="text-sm border border-gray-300 rounded p-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 h-8">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Đến ngày</label>
                                <input type="date" id="filter-date-end" value="<?php echo esc_attr($default_end_date); ?>" class="text-sm border border-gray-300 rounded p-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 h-8">
                            </div>
                            <button type="button" id="btn-filter-blocked" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-1.5 px-4 rounded transition border-none cursor-pointer h-8">
                                Lọc
                            </button>
                        </div>

                        <!-- Table -->
                        <div class="overflow-x-auto flex-1 p-0 min-h-[300px] max-h-[500px]">
                            <table class="w-full text-left text-sm text-gray-600 border-collapse m-0">
                                <thead class="bg-white text-gray-500 text-xs uppercase font-semibold sticky top-0 border-b border-gray-200">
                                    <tr>
                                        <th class="px-6 py-3 font-semibold">IP Address</th>
                                        <th class="px-6 py-3 text-center font-semibold">Số phiên</th>
                                        <th class="px-6 py-3 font-semibold">Thời gian chặn</th>
                                        <th class="px-6 py-3 font-semibold">Lý do</th>
                                        <th class="px-6 py-3 text-right font-semibold">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody id="blocked-ip-list" class="divide-y divide-gray-100">
                                    <tr><td colspan="5" class="text-center py-10">Đang tải dữ liệu...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- Pagination -->
                        <div class="p-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between text-xs text-gray-500 rounded-b-xl">
                            <div id="blocked-table-info">Hiển thị 0-0 của 0 IP</div>
                            <div class="flex gap-1">
                                <button type="button" class="px-2 py-1 rounded border border-gray-200 hover:bg-white disabled:opacity-50 cursor-pointer" disabled>Trước</button>
                                <button type="button" class="px-2 py-1 rounded bg-blue-600 text-white font-medium border-none cursor-pointer">1</button>
                                <button type="button" class="px-2 py-1 rounded border border-gray-200 hover:bg-white cursor-pointer" disabled>2</button>
                                <button type="button" class="px-2 py-1 rounded border border-gray-200 hover:bg-white cursor-pointer" disabled>Tiếp</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            /* ----------------------------------------------------------------
             * SECTION: Whitelist IP (shared data & nonce)
             * ---------------------------------------------------------------- */
            global $wpdb;
            $wl_table   = $wpdb->prefix . 'gads_toolkit_whitelist';
            $wl_entries = [];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($wpdb->get_var("SHOW TABLES LIKE '$wl_table'")) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wl_entries = $wpdb->get_results("SELECT * FROM $wl_table ORDER BY added_time DESC");
            }
            $wl_nonce       = wp_create_nonce('tkgadm_whitelist_nonce');
            $swl_enabled    = get_option('tkgadm_smart_wl_enabled', '1');
            $swl_threshold  = (int) get_option('tkgadm_smart_wl_threshold', 30);
            $swl_last       = get_option('tkgadm_last_smart_wl_scan');
            ?>

            <!-- ══════════ WHITELIST IP (gộp Smart + Thủ công) ══════════ -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">

                <!-- Header -->
                <div class="p-5 border-b border-gray-100 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-bold text-gray-800 flex items-center gap-2 m-0 mb-1">
                            <i class="fa-solid fa-shield-halved text-green-500"></i>
                            Whitelist IP
                            <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-normal" id="wl-count-badge"><?php echo count($wl_entries); ?> IP</span>
                        </h3>
                        <p class="text-xs text-gray-400 m-0">IP trong whitelist không bị chặn và không đẩy lên Google Ads</p>
                    </div>
                    <!-- Thêm IP thủ công -->
                    <div class="flex gap-2 items-center flex-wrap">
                        <input type="text" id="wl-ip" placeholder="IP hoặc 66.249.64.*"
                               class="text-sm border border-gray-300 rounded-lg p-2 w-40 font-mono focus:outline-none focus:ring-1 focus:ring-green-500">
                        <input type="text" id="wl-reason" placeholder="Ghi chú (tuỳ chọn)"
                               class="text-sm border border-gray-300 rounded-lg p-2 w-36 focus:outline-none focus:ring-1 focus:ring-green-500">
                        <button id="btn-wl-add"
                                class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium py-2 px-4 rounded-lg border-none cursor-pointer flex items-center gap-1.5 transition">
                            <i class="fa-solid fa-plus"></i> Thêm thủ công
                        </button>
                    </div>
                </div>

                <!-- Status bar thao tác -->
                <div id="wl-status" class="hidden px-5 py-2 text-sm border-b border-gray-100"></div>

                <!-- 2 cột: Smart Auto | Danh sách IP -->
                <div class="grid grid-cols-1 lg:grid-cols-5 divide-y lg:divide-y-0 lg:divide-x divide-gray-100">

                    <!-- ── Cột trái: Smart Auto-Whitelist (2/5) ── -->
                    <div class="lg:col-span-2 p-5 space-y-4 bg-gray-50/50">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-gray-700 flex items-center gap-1.5">
                                <i class="fa-solid fa-wand-magic-sparkles text-purple-500"></i> Smart Auto-Whitelist
                            </span>
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <span class="text-xs text-gray-500"><?php echo $swl_enabled === '1' ? 'Bật' : 'Tắt'; ?></span>
                                <div class="tkgadm-switch">
                                    <input type="checkbox" id="swl-toggle" class="tkgadm-switch__input"
                                           <?php checked($swl_enabled, '1'); ?> aria-label="Bật Smart Auto-Whitelist">
                                    <label for="swl-toggle" class="tkgadm-switch__track"></label>
                                </div>
                            </label>
                        </div>

                        <p class="text-xs text-gray-500 leading-relaxed m-0">
                            IP đã bị chặn mà vẫn tiếp tục click Ads (sau ngày bị chặn) đạt ngưỡng
                            → xác định là <strong>Google Verification Bot</strong>
                            → tự động whitelist + xóa khỏi Google Ads.
                        </p>

                        <!-- Ngưỡng -->
                        <div class="flex items-center justify-between text-sm text-gray-600 bg-white p-3 rounded-lg border border-gray-100">
                            <label class="text-xs font-medium text-gray-600 whitespace-nowrap m-0">Ngưỡng auto-whitelist:</label>
                            <div class="flex items-center gap-2">
                                <input type="number" id="swl-threshold" value="<?php echo esc_attr($swl_threshold); ?>"
                                       min="1" max="9999"
                                       class="w-16 text-center border border-gray-200 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-purple-300">
                                <span class="text-xs text-gray-500">click / IP</span>
                            </div>
                        </div>

                        <!-- Buttons -->
                        <div class="flex gap-2 flex-wrap pt-2">
                            <button id="btn-swl-save"
                                    class="flex-1 text-xs bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 py-2 px-3 rounded-lg cursor-pointer transition flex items-center justify-center gap-1.5 shadow-sm">
                                <i class="fa-solid fa-floppy-disk text-gray-400"></i> Lưu
                            </button>
                            <button id="btn-swl-scan"
                                    class="flex-1 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium py-2 px-3 rounded-lg border-none cursor-pointer transition flex items-center justify-center gap-1.5 shadow-sm">
                                <i class="fa-solid fa-magnifying-glass"></i> Quét ngay
                            </button>
                        </div>

                        <!-- Last scan -->
                        <div class="bg-white rounded-lg p-3 text-xs text-gray-500 space-y-2 border border-gray-100">
                            <?php if ($swl_last): ?>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-400"><i class="fa-regular fa-clock"></i> Lần cuối</span>
                                    <strong><?php echo date_i18n('d/m/Y H:i', $swl_last['time']); ?></strong>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-400"><i class="fa-solid fa-check text-green-500"></i> Đã whitelist</span>
                                    <strong class="text-green-600"><?php echo intval($swl_last['added']); ?> IP</strong>
                                </div>
                                <?php if (!empty($swl_last['ips'])): ?>
                                    <p class="font-mono text-gray-500 text-xs break-all m-0 pt-2 border-t border-gray-50">
                                        <?php echo esc_html(implode(', ', array_slice($swl_last['ips'], 0, 4))); ?>
                                        <?php echo count($swl_last['ips']) > 4 ? ' +' . (count($swl_last['ips']) - 4) . ' khác…' : ''; ?>
                                    </p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-gray-400 m-0">Chưa quét lần nào.</p>
                                <p class="text-gray-400 m-0">Cron tự chạy mỗi 15 phút.</p>
                            <?php endif; ?>
                        </div>

                        <div id="swl-status" class="hidden text-xs font-medium rounded-lg p-3 border"></div>
                    </div>

                    <!-- ── Cột phải: Danh sách Whitelist (3/5) ── -->
                    <div class="lg:col-span-3 flex flex-col">
                        <div class="px-5 py-3 bg-white border-b border-gray-100 text-xs text-gray-500 font-medium flex items-center justify-between">
                            <span><i class="fa-solid fa-list-ul text-gray-400 mr-1"></i> Danh sách IP tin cậy</span>
                            <span class="font-normal text-gray-400 italic">🤖 Tự động = Smart Auto | 👤 Thủ công = Admin</span>
                        </div>
                        <?php if (empty($wl_entries)): ?>
                            <div class="flex-1 flex items-center justify-center py-12 text-gray-400 text-sm bg-white">
                                <div class="text-center">
                                    <i class="fa-solid fa-shield-check text-3xl mb-2 text-gray-200"></i>
                                    <p class="m-0">Chưa có IP nào trong whitelist.</p>
                                </div>
                            </div>
                        <?php else: ?>
                        <div class="overflow-x-auto overflow-y-auto flex-1 max-h-[400px] bg-white">
                            <table class="w-full text-sm text-gray-600 m-0">
                                <thead class="bg-gray-50/80 text-xs text-gray-500 sticky top-0 z-10 backdrop-blur-sm">
                                    <tr>
                                        <th class="px-5 py-3 text-left font-medium border-b border-gray-100">IP / Dải IP</th>
                                        <th class="px-5 py-3 text-left font-medium border-b border-gray-100">Ghi chú</th>
                                        <th class="px-5 py-3 text-center font-medium border-b border-gray-100">Nguồn</th>
                                        <th class="px-5 py-3 text-right font-medium border-b border-gray-100">Xóa</th>
                                    </tr>
                                </thead>
                                <tbody id="wl-tbody">
                                <?php foreach ($wl_entries as $e):
                                    $is_auto = ($e->added_by === 'auto');
                                ?>
                                    <tr class="border-b border-gray-50 hover:bg-gray-50/70 transition-colors" data-id="<?php echo esc_attr($e->id); ?>">
                                        <td class="px-5 py-3 font-mono font-medium text-gray-800"><?php echo esc_html($e->ip_address); ?></td>
                                        <td class="px-5 py-3 text-gray-400 text-xs max-w-[200px] truncate" title="<?php echo esc_attr($e->reason ?: ''); ?>">
                                            <?php echo esc_html($e->reason ?: '—'); ?>
                                        </td>
                                        <td class="px-5 py-3 text-center">
                                            <?php if ($is_auto): ?>
                                                <span class="text-[11px] font-medium bg-purple-50 text-purple-600 border border-purple-100 px-2 py-0.5 rounded-full">🤖 Tự động</span>
                                            <?php else: ?>
                                                <span class="text-[11px] font-medium bg-gray-50 text-gray-500 border border-gray-200 px-2 py-0.5 rounded-full">👤 Thủ công</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-right">
                                            <button class="btn-wl-remove text-red-400 hover:text-red-600 border-none bg-transparent cursor-pointer text-xs p-1.5 rounded hover:bg-red-50 transition-colors"
                                                    data-id="<?php echo esc_attr($e->id); ?>" title="Xóa khỏi whitelist">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                </div><!-- /grid -->
            </div><!-- /whitelist card -->


            <?php
            /* ----------------------------------------------------------------
             * SECTION: Quản lý IP trên Google Ads
             * ---------------------------------------------------------------- */
            $has_direct = !empty(get_option('tkgadm_gads_developer_token'))
                       && !empty(get_option('tkgadm_gads_customer_id'))
                       && !empty(get_option('tkgadm_gads_refresh_token'));
            $gads_nonce = wp_create_nonce('tkgadm_gads_manager_nonce');
            ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 border-t-blue-500">
                <div class="p-5 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex items-center gap-3 flex-wrap">
                        <h3 class="text-base font-bold text-gray-800 flex items-center gap-2 m-0">
                            <i class="fa-brands fa-google text-blue-500"></i> IP trên Google Ads
                            <span id="gads-slot-badge" class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-normal">—/500</span>
                        </h3>
                        <?php if ($has_direct): ?>
                            <button id="btn-gads-load" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded border-none cursor-pointer flex items-center gap-1 transition">
                                <i class="fa-solid fa-rotate"></i> Tải danh sách
                            </button>
                        <?php else: ?>
                            <span class="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded px-2 py-1">
                                ⚠️ Cần nhập Developer Token trong cài đặt để dùng tính năng này
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($has_direct): ?>
                    <div class="flex gap-2 items-center flex-wrap">
                        <button id="btn-gads-del-selected" disabled
                                class="bg-red-500 hover:bg-red-600 disabled:bg-gray-300 disabled:cursor-not-allowed text-white text-xs font-medium py-2 px-3 rounded-lg border-none cursor-pointer flex items-center gap-1.5 transition">
                            <i class="fa-solid fa-trash-can"></i> Xóa đã chọn (<span id="gads-sel-count">0</span>)
                        </button>
                        <div class="flex items-center gap-1">
                            <button id="btn-gads-del-oldest" class="bg-orange-500 hover:bg-orange-600 text-white text-xs font-medium py-2 px-3 rounded-lg border-none cursor-pointer flex items-center gap-1.5 transition">
                                <i class="fa-solid fa-clock-rotate-left"></i> Xóa
                            </button>
                            <div class="flex items-center border border-orange-300 rounded-lg bg-white overflow-hidden focus-within:ring-1 focus-within:ring-orange-400">
                                <input type="number" id="gads-oldest-n" value="50" min="1" max="500"
                                       class="w-16 text-center text-sm border-none py-1.5 px-1 focus:outline-none focus:ring-0 m-0 h-full" style="box-shadow: none;">
                                <span class="text-xs text-gray-500 pr-3 whitespace-nowrap bg-gray-50 border-l border-orange-300 h-full py-2 pl-2">IP cũ nhất</span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="gads-action-msg" class="hidden px-5 py-2 text-sm border-b border-gray-100 font-medium"></div>

                <?php if (!$has_direct): ?>
                    <p class="text-center text-gray-400 text-sm py-8 m-0">
                        Vào <a href="<?php echo esc_url(admin_url('admin.php?page=tkgad-settings')); ?>" class="text-blue-600 underline">Cấu hình & Tích hợp</a>
                        → nhập <strong>Developer Token</strong> và kết nối Google Ads để sử dụng tính năng này.
                    </p>
                <?php else: ?>
                    <!-- Progress bar -->
                    <div id="gads-progress-wrap" class="hidden px-5 py-3 border-b border-gray-100">
                        <div class="flex items-center gap-3">
                            <div class="flex-1 bg-gray-200 rounded-full h-2 overflow-hidden">
                                <div id="gads-progress-bar" class="h-2 rounded-full bg-blue-500 transition-all duration-500" style="width:0%"></div>
                            </div>
                            <span id="gads-progress-text" class="text-xs text-gray-500 whitespace-nowrap">0 / 500</span>
                        </div>
                    </div>

                    <!-- Table controls -->
                    <div id="gads-table-controls" class="hidden px-4 py-2 bg-gray-50 border-b border-gray-100 flex items-center gap-3 flex-wrap">
                        <label class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                            <input type="checkbox" id="gads-select-all" class="w-3.5 h-3.5 accent-blue-600"> Chọn tất cả
                        </label>
                        <label class="flex items-center gap-2 text-xs text-orange-600 cursor-pointer">
                            <input type="checkbox" id="gads-select-no-db" class="w-3.5 h-3.5 accent-orange-500"> Chọn IP không có trong DB
                        </label>
                        <input type="text" id="gads-search" placeholder="🔍 Tìm IP..."
                               class="ml-auto text-xs border border-gray-300 rounded p-1.5 w-36 focus:outline-none focus:ring-1 focus:ring-blue-400">
                    </div>

                    <!-- Not loaded -->
                    <div id="gads-not-loaded" class="py-10 text-center text-gray-400 text-sm">
                        Nhấn <strong>Tải danh sách</strong> để xem IP đang chặn trên Google Ads.
                    </div>
                    <!-- Loading -->
                    <div id="gads-loading" class="hidden py-10 text-center text-gray-400 text-sm">
                        <i class="fa-solid fa-spinner fa-spin mr-1"></i> Đang tải từ Google Ads API...
                    </div>
                    <!-- Table -->
                    <div id="gads-table-wrap" class="hidden overflow-x-auto max-h-[420px]">
                        <table class="w-full text-sm text-gray-600">
                            <thead class="bg-gray-50 text-xs text-gray-500 border-b border-gray-100 sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 w-8"></th>
                                    <th class="px-3 py-2 text-left font-medium">IP Address</th>
                                    <th class="px-3 py-2 text-left font-medium">Ngày chặn (DB)</th>
                                    <th class="px-3 py-2 text-left font-medium">Lý do</th>
                                    <th class="px-3 py-2 text-center font-medium">Click</th>
                                    <th class="px-3 py-2 text-right font-medium">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody id="gads-ip-tbody"></tbody>
                        </table>
                        <p class="text-xs text-gray-400 text-right px-4 py-2 border-t border-gray-50 m-0" id="gads-table-footer"></p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
    <script>
    jQuery(document).ready(function($) {
        let currentIps = []; // Store current filtered IPs for copying

        // Format number
        function formatNumber(num) {
            return num.toString().replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1,');
        }

        function escapeHtml(value) {
            return $('<div>').text(value || '').html();
        }

        function copyText(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }

            const fallback = document.createElement('textarea');
            fallback.value = text;
            fallback.setAttribute('readonly', '');
            fallback.style.cssText = 'position:fixed;left:-9999px;top:0;';
            document.body.appendChild(fallback);
            fallback.select();
            const copied = document.execCommand('copy');
            document.body.removeChild(fallback);

            return copied ? Promise.resolve() : Promise.reject(new Error('Trình duyệt từ chối thao tác copy.'));
        }

        // Handle Filter Button
        $('#btn-filter-blocked').on('click', function() {
            const minVisits = $('#filter-visit-count').val();
            const startDate = $('#filter-date-start').val();
            const endDate = $('#filter-date-end').val();
            const btn = $(this);
            
            btn.prop('disabled', true).text('Đang tải...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tkgadm_get_blocked_ips',
                    min_visits: minVisits,
                    start_date: startDate,
                    end_date: endDate,
                    nonce: '<?php echo wp_create_nonce("tkgadm_data_nonce"); ?>'
                },
                success: function(response) {
                    btn.prop('disabled', false).text('Lọc');
                    
                    if (response.success) {
                        const ips = response.data;
                        currentIps = ips.map(item => item.ip_address); // Save for copy
                        
                        $('#blocked-count-badge').text(formatNumber(ips.length) + ' IP');
                        
                        if (ips.length > 0) {
                            let html = '';
                            ips.forEach(ip => {
                                const badgeClass = ip.visit_count > 10 ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600';
                                const blockedTime = ip.blocked_time_display || ip.blocked_time || '-';
                                html += `<tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 font-medium text-gray-900">${escapeHtml(ip.ip_address)}</td>
                                    <td class="px-6 py-3 text-center"><span class="${badgeClass} py-0.5 px-2 rounded font-medium text-xs">${ip.visit_count}</span></td>
                                    <td class="px-6 py-3 text-gray-500">${escapeHtml(blockedTime)}</td>
                                    <td class="px-6 py-3 text-gray-500 text-xs">${escapeHtml(ip.reason || '-')}</td>
                                    <td class="px-6 py-3 text-right"><button type="button" class="btn-unblock-ip text-red-500 hover:text-red-700 text-xs font-medium border-none bg-transparent cursor-pointer" data-ip="${escapeHtml(ip.ip_address)}"><i class="fa-solid fa-unlock"></i> Bỏ chặn</button></td>
                                </tr>`;
                            });
                            $('#blocked-ip-list').html(html);
                            $('#blocked-table-info').text('Hiển thị 1-' + ips.length + ' của ' + formatNumber(ips.length) + ' IP');
                            $('#btn-copy-blocked').prop('disabled', false).html('<i class="fa-regular fa-copy text-blue-500"></i> Copy Danh Sách');
                        } else {
                            $('#blocked-ip-list').html('<tr><td colspan="5" style="text-align:center; padding:20px;">Không tìm thấy IP nào thỏa mãn điều kiện.</td></tr>');
                            $('#blocked-table-info').text('Hiển thị 0-0 của 0 IP');
                            $('#btn-copy-blocked').prop('disabled', true).html('<i class="fa-regular fa-copy text-blue-500"></i> Copy Danh Sách');
                        }
                    } else {
                        $('#blocked-ip-list').html('<tr><td colspan="5" style="color:red; text-align:center; padding:20px;">Lỗi: ' + escapeHtml(response.data) + '</td></tr>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Lọc');
                    $('#blocked-ip-list').html('<tr><td colspan="5" style="color:red; text-align:center; padding:20px;">Lỗi kết nối Server.</td></tr>');
                }
            });
        });

        // Auto load on init
        $('#btn-filter-blocked').trigger('click');

        // Handle Copy Button
        $('#btn-copy-blocked').on('click', function() {
            if (currentIps.length === 0) return;
            
            const textToCopy = currentIps.join('\n');
            copyText(textToCopy).then(function() {
                const originalHtml = $('#btn-copy-blocked').html();
                $('#btn-copy-blocked').html('<i class="fa-regular fa-copy text-blue-500"></i> Đã Copy!');
                setTimeout(() => $('#btn-copy-blocked').html(originalHtml), 2000);
            }, function(err) {
                alert('Không thể copy: ' + err);
            });
        });

        $(document).on('click', '.btn-unblock-ip', function() {
            const button = $(this);
            const ip = button.data('ip');

            if (!confirm('Bỏ chặn IP ' + ip + '?')) {
                return;
            }

            button.prop('disabled', true).text('Đang xử lý...');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tkgadm_toggle_block_ip',
                    ip: ip,
                    block_action: 'unblock',
                    nonce: '<?php echo wp_create_nonce("tkgadm_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('#btn-filter-blocked').trigger('click');
                    } else {
                        alert('Không thể bỏ chặn IP: ' + response.data);
                        button.prop('disabled', false).html('<i class="fa-solid fa-unlock"></i> Bỏ chặn');
                    }
                },
                error: function() {
                    alert('Lỗi kết nối Server.');
                    button.prop('disabled', false).html('<i class="fa-solid fa-unlock"></i> Bỏ chặn');
                }
            });
        });
        
        // --- Existing Cleanup Logic Below ---
        function callDeleteApi(data, confirmMsg) {
            if (!confirm(confirmMsg)) {
                return;
            }

            $('#delete-status').show().html('⏳ Đang xử lý...');
            $('button').prop('disabled', true);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: Object.assign(data, {
                    action: 'tkgadm_delete_data',
                    nonce: '<?php echo wp_create_nonce("tkgadm_delete_nonce"); ?>'
                }),
                success: function(response) {
                    $('button').prop('disabled', false);
                    if (response.success) {
                        $('#delete-status').html('<span style="color: green;">✅ ' + response.data.message + '</span>');
                        
                        // Reload sau 2s để cập nhật số liệu
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#delete-status').html('<span style="color: red;">❌ ' + response.data + '</span>');
                    }
                },
                error: function() {
                    $('button').prop('disabled', false);
                    $('#delete-status').html('<span style="color: red;">❌ Lỗi kết nối Server.</span>');
                }
            });
        }

        // Handle Delete Range
        $('#btn-delete-range').on('click', function() {
            const from = $('#delete-from').val();
            const to = $('#delete-to').val();

            if (!from || !to) {
                alert('Vui lòng chọn đầy đủ ngày bắt đầu và kết thúc.');
                return;
            }

            callDeleteApi(
                { type: 'range', from: from, to: to },
                '⚠️ CẢNH BÁO: Hành động này không thể hoàn tác.\nBạn có chắc muốn xóa logs từ ' + from + ' đến ' + to + '?'
            );
        });

        // Handle Delete Old
        $('#btn-delete-old').on('click', function() {
            const age = $('#delete-age').val();
            let msg = '';
            
            if (age === 'all') {
                msg = '⚠️ CẢNH BÁO NGUY HIỂM: \nBạn sắp xóa TOÀN BỘ dữ liệu thống kê (Logs).\nHành động này KHÔNG THỂ khôi phục.\n\nBạn có chắc chắn không?';
            } else {
                msg = '⚠️ Bạn có chắc muốn xóa logs cũ hơn ' + age + ' ngày?';
            }

            callDeleteApi(
                { type: 'age', days: age },
                msg
            );
        });

    });

    /* ============================================================
     * JS: Whitelist IP
     * ============================================================ */
    (function($) {
        var wlNonce = '<?php echo esc_js($wl_nonce); ?>';

        // Thêm IP vào whitelist
        $('#btn-wl-add').on('click', function() {
            var ip     = $('#wl-ip').val().trim();
            var reason = $('#wl-reason').val().trim();
            if (!ip) { alert('Vui lòng nhập địa chỉ IP.'); return; }

            var btn = $(this).prop('disabled', true);
            $.post(ajaxurl, { action: 'tkgadm_whitelist_add', nonce: wlNonce, ip: ip, reason: reason }, function(res) {
                btn.prop('disabled', false);
                var s = $('#wl-status').removeClass('hidden text-green-700 text-red-700');
                if (res.success) {
                    s.addClass('text-green-700').text('✅ ' + res.data.message);
                    $('#wl-ip, #wl-reason').val('');
                    setTimeout(function(){ location.reload(); }, 800);
                } else {
                    s.addClass('text-red-700').text('❌ ' + res.data);
                }
            }).fail(function() {
                btn.prop('disabled', false);
                $('#wl-status').removeClass('hidden').addClass('text-red-700').text('❌ Lỗi kết nối.');
            });
        });

        // Xóa IP khỏi whitelist
        $(document).on('click', '.btn-wl-remove', function() {
            var id  = $(this).data('id');
            var row = $(this).closest('tr');
            var ip  = row.find('td:first').text();
            if (!confirm('Xóa ' + ip + ' khỏi whitelist?')) return;
            $.post(ajaxurl, { action: 'tkgadm_whitelist_remove', nonce: wlNonce, id: id }, function(res) {
                if (res.success) { row.fadeOut(200, function(){ $(this).remove(); }); }
                else { alert('Lỗi: ' + res.data); }
            });
        });
    })(jQuery);

    /* ============================================================
     * JS: Smart Auto-Whitelist
     * ============================================================ */
    (function($) {
        var wlNonce = '<?php echo esc_js($wl_nonce); ?>';

        // Lưu cài đặt
        $('#btn-swl-save').on('click', function() {
            var enabled   = $('#swl-toggle').is(':checked') ? 1 : 0;
            var threshold = parseInt($('#swl-threshold').val()) || 30;
            var btn = $(this).prop('disabled', true);
            $.post(ajaxurl, { action: 'tkgadm_smart_wl_save', nonce: wlNonce, enabled: enabled, threshold: threshold }, function(res) {
                btn.prop('disabled', false);
                var s = $('#swl-status').removeClass('hidden text-green-700 text-red-700 text-sky-700');
                if (res.success) {
                    s.addClass('text-green-700').text('✅ Đã lưu. Ngưỡng: ' + threshold + ' click, trạng thái: ' + (enabled ? 'Bật' : 'Tắt'));
                    $('#swl-threshold-label').text(threshold);
                } else {
                    s.addClass('text-red-700').text('❌ ' + res.data);
                }
            }).fail(function() {
                btn.prop('disabled', false);
                $('#swl-status').removeClass('hidden').addClass('text-red-700').text('❌ Lỗi kết nối.');
            });
        });

        // Quét ngay
        $('#btn-swl-scan').on('click', function() {
            var threshold = parseInt($('#swl-threshold').val()) || 30;
            var btn = $(this).prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Đang quét...');
            $('#swl-status').removeClass('hidden text-green-700 text-red-700')
                            .addClass('text-sky-700').text('⏳ Đang phân tích dữ liệu...');
            $.post(ajaxurl, { action: 'tkgadm_smart_whitelist_scan', nonce: wlNonce, threshold: threshold }, function(res) {
                btn.prop('disabled', false).html('<i class="fa-solid fa-magnifying-glass"></i> Quét ngay');
                var s = $('#swl-status').removeClass('text-sky-700');
                if (res.success) {
                    var added = res.data.added || 0;
                    s.addClass(added > 0 ? 'text-green-700' : 'text-gray-500').text((added > 0 ? '✅ ' : 'ℹ️ ') + res.data.message);
                    if (added > 0) setTimeout(function(){ location.reload(); }, 1500);
                } else {
                    s.addClass('text-red-700').text('❌ ' + res.data);
                }
            }).fail(function() {
                btn.prop('disabled', false).html('<i class="fa-solid fa-magnifying-glass"></i> Quét ngay');
                $('#swl-status').removeClass('text-sky-700').addClass('text-red-700').text('❌ Lỗi kết nối.');
            });
        });
    })(jQuery);

    /* ============================================================
     * JS: Quản lý IP trên Google Ads
     * ============================================================ */
    (function($) {
        var gNonce = '<?php echo esc_js($gads_nonce); ?>';
        var allIps = [];

        // Hàm escape HTML
        function esc(s) { return $('<div>').text(s || '').html(); }

        // Format date string
        function fmtDate(s) {
            if (!s) return '<span class="text-gray-400 italic">Không rõ</span>';
            var d = new Date(s.replace(' ', 'T'));
            return ('0'+d.getDate()).slice(-2)+'/'+('0'+(d.getMonth()+1)).slice(-2)+'/'+d.getFullYear()
                 + ' '+('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2);
        }

        function showMsg(type, msg) {
            var cls = { ok:'text-green-700', err:'text-red-700', info:'text-sky-700' }[type] || '';
            $('#gads-action-msg').removeClass('hidden text-green-700 text-red-700 text-sky-700').addClass(cls).html(msg);
        }

        function renderGadsTable(ips) {
            var q = ($('#gads-search').val() || '').toLowerCase();
            var shown = ips.filter(function(ip) {
                return !q || ip.ip_address.toLowerCase().indexOf(q) !== -1;
            });
            var html = '';
            shown.forEach(function(ip) {
                var inDbBadge = ip.in_db
                    ? '<span class="text-xs bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded">DB</span>'
                    : '<span class="text-xs bg-gray-100 text-gray-400 px-1.5 py-0.5 rounded">—</span>';
                html += '<tr class="border-b border-gray-50 hover:bg-blue-50/30 gads-row" data-rn="'+esc(ip.resource_name)+'" data-ip="'+esc(ip.ip_address)+'" data-in-db="'+(ip.in_db?'1':'0')+'">'
                      + '<td class="px-3 py-2"><input type="checkbox" class="gads-cb w-3.5 h-3.5 accent-blue-600" data-rn="'+esc(ip.resource_name)+'"></td>'
                      + '<td class="px-3 py-2 font-mono font-medium text-gray-800">'+esc(ip.ip_address)+' '+inDbBadge+'</td>'
                      + '<td class="px-3 py-2 text-xs text-gray-500">'+fmtDate(ip.blocked_time)+'</td>'
                      + '<td class="px-3 py-2 text-xs text-gray-400 max-w-[180px] truncate" title="'+esc(ip.reason || '')+'">'+esc(ip.reason||'—')+'</td>'
                      + '<td class="px-3 py-2 text-center text-xs">'+(ip.visit_count||'—')+'</td>'
                      + '<td class="px-3 py-2 text-right"><button class="btn-gads-del-one text-red-400 hover:text-red-600 border-none bg-transparent cursor-pointer text-xs px-2 py-1 rounded hover:bg-red-50" data-rn="'+esc(ip.resource_name)+'" data-ip="'+esc(ip.ip_address)+'"><i class="fa-solid fa-trash-can"></i> Xóa</button></td>'
                      + '</tr>';
            });
            $('#gads-ip-tbody').html(html || '<tr><td colspan="6" class="text-center py-6 text-gray-400">Không tìm thấy IP.</td></tr>');
            $('#gads-table-footer').text('Hiển thị ' + shown.length + ' / ' + ips.length + ' IP');
            updateSelCount();
        }

        function updateSelCount() {
            var n = $('#gads-ip-tbody .gads-cb:checked').length;
            $('#gads-sel-count').text(n);
            $('#btn-gads-del-selected').prop('disabled', n === 0);
        }

        function updateProgress(total) {
            var pct = Math.round((total / 500) * 100);
            var color = pct >= 90 ? 'bg-red-500' : (pct >= 70 ? 'bg-orange-500' : 'bg-blue-500');
            $('#gads-slot-badge').text(total + '/500');
            $('#gads-progress-bar').css('width', pct + '%').attr('class', 'h-2 rounded-full transition-all duration-500 ' + color);
            $('#gads-progress-text').text(total + ' / 500 (' + pct + '%)');
            $('#gads-progress-wrap').show();
        }

        // Load danh sách
        $('#btn-gads-load').on('click', function() {
            $('#gads-not-loaded').hide();
            $('#gads-table-wrap, #gads-table-controls').hide();
            $('#gads-loading').show();
            $('#gads-action-msg').addClass('hidden');
            $(this).prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Đang tải...');
            var self = this;

            $.post(ajaxurl, { action: 'tkgadm_gads_list_ips', nonce: gNonce }, function(res) {
                $('#gads-loading').hide();
                $(self).prop('disabled', false).html('<i class="fa-solid fa-rotate"></i> Tải danh sách');
                if (!res.success) { showMsg('err', '❌ ' + res.data); $('#gads-not-loaded').show(); return; }
                allIps = res.data.ips || [];
                updateProgress(res.data.total);
                if (allIps.length === 0) {
                    $('#gads-not-loaded').show().text('✅ Không có IP nào đang bị chặn trên Google Ads.');
                } else {
                    renderGadsTable(allIps);
                    $('#gads-table-wrap, #gads-table-controls').show();
                }
            }).fail(function() {
                $('#gads-loading').hide();
                $(self).prop('disabled', false).html('<i class="fa-solid fa-rotate"></i> Tải danh sách');
                showMsg('err', '❌ Lỗi kết nối máy chủ.');
                $('#gads-not-loaded').show();
            });
        });

        // Search
        $('#gads-search').on('input', function() { if (allIps.length) renderGadsTable(allIps); });

        // Checkbox logic
        $('#gads-select-all').on('change', function() {
            $('#gads-ip-tbody .gads-cb').prop('checked', $(this).is(':checked'));
            updateSelCount();
        });
        $('#gads-select-no-db').on('change', function() {
            var chk = $(this).is(':checked');
            $('#gads-ip-tbody .gads-row').each(function() {
                if ($(this).data('in-db') != '1') $(this).find('.gads-cb').prop('checked', chk);
            });
            updateSelCount();
        });
        $(document).on('change', '.gads-cb', function() { updateSelCount(); });

        // Xóa một IP
        $(document).on('click', '.btn-gads-del-one', function() {
            var rn = $(this).data('rn'), ip = $(this).data('ip');
            if (!confirm('Xóa IP ' + ip + ' khỏi Google Ads?\nHành động này không thể hoàn tác!')) return;
            doDelete([rn]);
        });

        // Xóa đã chọn
        $('#btn-gads-del-selected').on('click', function() {
            var rns = [], ips = [];
            $('#gads-ip-tbody .gads-cb:checked').each(function() {
                rns.push($(this).data('rn'));
                ips.push($(this).closest('tr').data('ip'));
            });
            if (!rns.length) return;
            if (!confirm('Xóa ' + rns.length + ' IP đã chọn khỏi Google Ads?\n\n' + ips.slice(0,8).join('\n') + (ips.length > 8 ? '\n...' : '') + '\n\nHành động không thể hoàn tác!')) return;
            doDelete(rns);
        });

        // Xóa N cũ nhất
        $('#btn-gads-del-oldest').on('click', function() {
            var n = parseInt($('#gads-oldest-n').val()) || 50;
            if (!confirm('Xóa ' + n + ' IP cũ nhất khỏi Google Ads?\nHành động không thể hoàn tác!')) return;
            showMsg('info', '<i class="fa-solid fa-spinner fa-spin"></i> Đang xóa ' + n + ' IP cũ nhất...');
            setGadsBtns(true);
            $.post(ajaxurl, { action: 'tkgadm_gads_delete_oldest', nonce: gNonce, count: n }, function(res) {
                setGadsBtns(false);
                if (res.success) {
                    var freed = res.data.freed || 0;
                    showMsg(freed > 0 ? 'ok' : 'info', (freed > 0 ? '✅ ' : 'ℹ️ ') + res.data.message);
                    if (freed > 0) $('#btn-gads-load').trigger('click');
                } else { showMsg('err', '❌ ' + res.data); }
            }).fail(function() { setGadsBtns(false); showMsg('err', '❌ Lỗi kết nối.'); });
        });

        function doDelete(rns) {
            showMsg('info', '<i class="fa-solid fa-spinner fa-spin"></i> Đang xóa ' + rns.length + ' IP khỏi Google Ads...');
            setGadsBtns(true);
            $.post(ajaxurl, { action: 'tkgadm_gads_delete_ips', nonce: gNonce, resource_names: rns }, function(res) {
                setGadsBtns(false);
                if (res.success) {
                    showMsg('ok', '✅ ' + res.data.message);
                    $('#btn-gads-load').trigger('click');
                } else { showMsg('err', '❌ ' + res.data); }
            }).fail(function() { setGadsBtns(false); showMsg('err', '❌ Lỗi kết nối.'); });
        }

        function setGadsBtns(disabled) {
            $('#btn-gads-load, #btn-gads-del-selected, #btn-gads-del-oldest').prop('disabled', disabled);
        }
    })(jQuery);
    </script>
    <?php
}

/**
 * ============================================================================
 * 3. AJAX HANDLERS
 * ============================================================================
 */

add_action('wp_ajax_tkgadm_get_blocked_ips', 'tkgadm_ajax_get_blocked_ips');
function tkgadm_ajax_get_blocked_ips() {
    check_ajax_referer('tkgadm_data_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền truy cập.');
    }
    
    global $wpdb;
    $table_blocked = $wpdb->prefix . 'gads_toolkit_blocked';
    $table_stats = $wpdb->prefix . 'gads_toolkit_stats';
    
    $min_visits = isset($_POST['min_visits']) ? intval($_POST['min_visits']) : 0;
    $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
    $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

    if (!empty($start_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
        $start_date = '';
    }

    if (!empty($end_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        $end_date = '';
    }

    // Build Where Clause. The visit total is calculated from live traffic data,
    // rather than the snapshot saved when the IP was first blocked.
    $where_clauses = array();
    $params = array();

    if (!empty($start_date)) {
        $where_clauses[] = "b.blocked_time >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    
    if (!empty($end_date)) {
        $where_clauses[] = "b.blocked_time <= %s";
        $params[] = $end_date . ' 23:59:59';
    }

    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    $params[] = $min_visits;
    
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $query = $wpdb->prepare(
        "SELECT b.ip_address, b.blocked_time, b.reason,
                GREATEST(COUNT(DISTINCT CASE WHEN s.gclid IS NOT NULL AND s.gclid != '' THEN s.gclid END), 0) AS visit_count
         FROM $table_blocked b
         LEFT JOIN $table_stats s ON s.ip_address = b.ip_address
         $where_sql
         GROUP BY b.id, b.ip_address, b.blocked_time, b.reason, b.visit_count
         HAVING visit_count >= %d
         ORDER BY visit_count DESC, b.blocked_time DESC
         LIMIT 1000", // Giới hạn 1000 IP để tránh treo trình duyệt
        $params
    );
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $results = $wpdb->get_results($query);

    foreach ($results as $row) {
        $row->visit_count = intval($row->visit_count);
        $row->blocked_time_display = $row->blocked_time ? wp_date('d/m/Y H:i:s', strtotime($row->blocked_time)) : '';
    }
    
    wp_send_json_success($results);
}

add_action('wp_ajax_tkgadm_delete_data', 'tkgadm_ajax_delete_data');
function tkgadm_ajax_delete_data() {
    check_ajax_referer('tkgadm_delete_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền truy cập.');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    $type = $_POST['type'];
    $rows_affected = 0;
    
    if ($type === 'range') {
        $from = sanitize_text_field($_POST['from']);
        $to = sanitize_text_field($_POST['to']);
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query = $wpdb->prepare("DELETE FROM $table WHERE visit_time >= %s AND visit_time <= %s", $from . ' 00:00:00', $to . ' 23:59:59');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows_affected = $wpdb->query($query);
        
    } elseif ($type === 'age') {
        $days = $_POST['days'];
        
        if ($days === 'all') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows_affected = $wpdb->query("TRUNCATE TABLE $table");
        } else {
            $days = intval($days);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $query = $wpdb->prepare("DELETE FROM $table WHERE visit_time < DATE_SUB(NOW(), INTERVAL %d DAY)", $days);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows_affected = $wpdb->query($query);
        }
    }
    
    if ($rows_affected !== false) {
        wp_send_json_success(['message' => "Đã xóa thành công $rows_affected dòng dữ liệu."]);
    } else {
        wp_send_json_error("Lỗi khi xóa dữ liệu DB.");
    }
}
