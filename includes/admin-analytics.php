<?php
/**
 * Admin Analytics - Phân tích Traffic
 * Submenu: Thống kê traffic từ Google Ads vs Organic
 */

if (!defined('ABSPATH')) exit;

function tkgadm_render_analytics_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'gads_toolkit_stats';
    
    // Lấy ngày cũ nhất và mới nhất từ database
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $date_range = $wpdb->get_row("SELECT 
        DATE(MIN(visit_time)) as oldest,
        DATE(MAX(visit_time)) as newest
        FROM $table");
    
    $default_from = $date_range && $date_range->oldest ? $date_range->oldest : date('Y-m-d', strtotime('-30 days'));
    $default_to = $date_range && $date_range->newest ? $date_range->newest : date('Y-m-d');
    
    ?>
    <div class="wrap">
        <div class="tkgadm-wrap">
            <div class="tkgadm-header">
                <h1>📈 Thống Kê Traffic</h1>
                <p style="color: #666; margin-top: 10px;">So sánh lượt truy cập từ Google Ads (có gclid/gbraid) và lượt truy cập tự nhiên (Organic - đã lọc Bot)</p>

                <div class="tkgadm-filters" style="margin-top: 20px;">
                    <!-- Period Selector -->
                    <select id="analytics-period" class="tkgadm-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #ddd;">
                        <option value="day">Theo ngày</option>
                        <option value="week">Theo tuần</option>
                        <option value="month">Theo tháng</option>
                        <option value="quarter">Theo quý</option>
                    </select>

                    <!-- Date Range -->
                    <div style="display: inline-flex; gap: 5px; align-items: center; background: white; padding: 8px 12px; border-radius: 8px;">
                        <input type="date" id="analytics-from" class="tkgadm-input" style="width: 150px; padding: 6px; font-size: 13px;" title="Từ ngày">
                        <span style="color: #999;">→</span>
                        <input type="date" id="analytics-to" class="tkgadm-input" style="width: 150px; padding: 6px; font-size: 13px;" title="Đến ngày">
                        <button id="analytics-apply" class="tkgadm-btn tkgadm-btn-primary" style="padding: 6px 12px;">🔍 Xem</button>
                    </div>

                    <!-- Quick filters -->
                    <button class="analytics-quick-filter tkgadm-btn tkgadm-btn-secondary" data-days="7">7 ngày</button>
                    <button class="analytics-quick-filter tkgadm-btn tkgadm-btn-secondary" data-days="30">30 ngày</button>
                    <button class="analytics-quick-filter tkgadm-btn tkgadm-btn-secondary" data-days="90">90 ngày</button>
                </div>
            </div>

            <!-- Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9;">📊 Tổng lượt truy cập</div>
                    <div id="total-visits" style="font-size: 36px; font-weight: bold; margin-top: 10px;">-</div>
                </div>
                <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9;">🎯 Từ Google Ads</div>
                    <div id="ads-visits" style="font-size: 36px; font-weight: bold; margin-top: 10px;">-</div>
                </div>
                <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9;">🌱 Organic Traffic</div>
                    <div id="organic-visits" style="font-size: 36px; font-weight: bold; margin-top: 10px;">-</div>
                </div>
                <div style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9;">📈 Tỷ lệ Ads/Total</div>
                    <div id="ads-ratio" style="font-size: 36px; font-weight: bold; margin-top: 10px;">-</div>
                </div>
            </div>

            <!-- Chart Container -->
            <div class="tkgadm-table-container" style="padding: 30px;">
                <canvas id="traffic-chart" style="max-height: 400px;"></canvas>
            </div>

            <!-- Loading Indicator -->
            <div id="analytics-loading" style="display: none; text-align: center; padding: 40px;">
                <div style="font-size: 48px;">⏳</div>
                <div style="margin-top: 10px; color: #666;">Đang tải dữ liệu...</div>
            </div>
        </div>

        <!-- Modal chi tiết visits -->
        <div id="period-details-modal" class="tkgadm-modal">
            <span class="tkgadm-modal-close">&times;</span>
            <div class="tkgadm-modal-content">
                <div class="tkgadm-modal-header">
                    <h2 id="period-modal-title">Chi tiết lượt truy cập</h2>
                </div>
                <div id="period-details-content" style="max-height: 500px; overflow-y: auto;"></div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        let trafficChart = null;

        // Load traffic data
        function loadTrafficData() {
            const period = $('#analytics-period').val();
            const from = $('#analytics-from').val();
            const to = $('#analytics-to').val();

            $('#analytics-loading').show();

            $.ajax({
                url: tkgadm_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'tkgadm_get_traffic_data',
                    nonce: tkgadm_vars.nonce,
                    period: period,
                    from: from,
                    to: to
                },
                success: function(response) {
                    $('#analytics-loading').hide();
                    
                    if (response.success) {
                        renderTrafficChart(response.data, period);
                    } else {
                        alert('Lỗi: ' + response.data);
                    }
                },
                error: function() {
                    $('#analytics-loading').hide();
                    alert('Lỗi kết nối');
                }
            });
        }

        // Render chart
        function renderTrafficChart(data, period) {
            const adsData = data.ads || [];
            const organicData = data.organic || [];

            // Merge periods
            const allPeriods = [...new Set([
                ...adsData.map(d => d.period),
                ...organicData.map(d => d.period)
            ])].sort();

            const adsValues = allPeriods.map(p => {
                const found = adsData.find(d => d.period === p);
                return found ? parseInt(found.total) : 0;
            });

            const organicValues = allPeriods.map(p => {
                const found = organicData.find(d => d.period === p);
                return found ? parseInt(found.total) : 0;
            });

            // Update summary
            const totalAds = adsValues.reduce((a, b) => a + b, 0);
            const totalOrganic = organicValues.reduce((a, b) => a + b, 0);
            const total = totalAds + totalOrganic;
            const ratio = total > 0 ? ((totalAds / total) * 100).toFixed(1) : 0;

            $('#total-visits').text(total.toLocaleString());
            $('#ads-visits').text(totalAds.toLocaleString());
            $('#organic-visits').text(totalOrganic.toLocaleString());
            $('#ads-ratio').text(ratio + '%');

            // Destroy old chart
            if (trafficChart) {
                trafficChart.destroy();
            }

            // Create new chart
            const ctx = document.getElementById('traffic-chart').getContext('2d');
            trafficChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: allPeriods,
                    datasets: [
                        {
                            label: '🎯 Google Ads',
                            data: adsValues,
                            backgroundColor: 'rgba(255, 152, 0, 0.8)',
                            borderColor: 'rgba(255, 152, 0, 1)',
                            borderWidth: 1
                        },
                        {
                            label: '🌱 Organic',
                            data: organicValues,
                            backgroundColor: 'rgba(76, 175, 80, 0.8)',
                            borderColor: 'rgba(76, 175, 80, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        title: {
                            display: true,
                            text: 'Biểu đồ Traffic: Google Ads vs Organic',
                            font: { size: 16, weight: 'bold' }
                        }
                    },
                    scales: {
                        x: {
                            stacked: true,
                            title: { display: true, text: getPeriodLabel(period) }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: { display: true, text: 'Số lượt truy cập' }
                        }
                    },
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const element = elements[0];
                            const datasetIndex = element.datasetIndex;
                            const index = element.index;
                            const periodValue = allPeriods[index];
                            const type = datasetIndex === 0 ? 'ads' : 'organic';
                            
                            loadPeriodDetails(periodValue, type, period);
                        }
                    }
                }
            });
        }

        // Load chi tiết visits theo period
        function loadPeriodDetails(periodValue, type, periodType) {
            const typeLabel = type === 'ads' ? '🎯 Google Ads' : '🌱 Organic';
            $('#period-modal-title').text(`Chi tiết ${typeLabel} - ${periodValue}`);
            $('#period-details-content').html('<div style="text-align:center; padding:20px;">⏳ Đang tải...</div>');
            $('#period-details-modal').fadeIn();

            $.ajax({
                url: tkgadm_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'tkgadm_get_period_details',
                    nonce: tkgadm_vars.nonce,
                    period_value: periodValue,
                    type: type,
                    period_type: periodType
                },
                success: function(response) {
                    if (response.success && response.data.visits) {
                        renderVisitsTable(response.data.visits);
                    } else {
                        $('#period-details-content').html('<p style="color:red;">Lỗi tải dữ liệu</p>');
                    }
                },
                error: function() {
                    $('#period-details-content').html('<p style="color:red;">Lỗi kết nối</p>');
                }
            });
        }

        // Render bảng visits
        function renderVisitsTable(visits) {
            if (visits.length === 0) {
                $('#period-details-content').html('<p>Không có dữ liệu</p>');
                return;
            }

            let html = '<table class="tkgadm-table" style="width:100%;">';
            html += '<thead><tr>';
            html += '<th>🌐 IP</th>';
            html += '<th>⏰ Thời gian</th>';
            html += '<th>🔗 URL</th>';
            html += '<th>⏱️ Time on Page</th>';
            html += '<th>📊 Lượt xem</th>';
            html += '</tr></thead><tbody>';

            visits.forEach(visit => {
                const timeOnPage = visit.time_on_page > 0 ? visit.time_on_page + 's' : '-';
                const url = visit.url_visited.length > 50 ? visit.url_visited.substring(0, 50) + '...' : visit.url_visited;
                
                html += '<tr>';
                html += `<td><strong>${visit.ip_address}</strong></td>`;
                html += `<td>${visit.visit_time}</td>`;
                html += `<td title="${visit.url_visited}"><small>${url}</small></td>`;
                html += `<td>${timeOnPage}</td>`;
                html += `<td>${visit.visit_count}</td>`;
                html += '</tr>';
            });

            html += '</tbody></table>';
            $('#period-details-content').html(html);
        }

        // Close modal
        $('.tkgadm-modal-close').on('click', function() {
            $(this).closest('.tkgadm-modal').fadeOut();
        });

        function getPeriodLabel(period) {
            const labels = {
                'day': 'Ngày',
                'week': 'Tuần',
                'month': 'Tháng',
                'quarter': 'Quý'
            };
            return labels[period] || 'Thời gian';
        }

        // Event handlers
        $('#analytics-apply').on('click', loadTrafficData);
        $('#analytics-period').on('change', loadTrafficData);

        $('.analytics-quick-filter').on('click', function() {
            const days = $(this).data('days');
            const to = new Date();
            const from = new Date();
            from.setDate(from.getDate() - days);

            $('#analytics-from').val(from.toISOString().split('T')[0]);
            $('#analytics-to').val(to.toISOString().split('T')[0]);
            loadTrafficData();
        });

        // Load initial data (từ ngày cũ nhất đến mới nhất trong DB)
        <?php
        echo "$('#analytics-from').val('" . esc_js($default_from) . "');";
        echo "$('#analytics-to').val('" . esc_js($default_to) . "');";
        ?>
        loadTrafficData();
    });
    </script>
    <?php
}
