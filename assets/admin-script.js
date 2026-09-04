jQuery(document).ready(function ($) {
  // Toggle switch Ẩn/Hiện IP Blocked
  $("#toggle-blocked-ips").on("change", function () {
    const isChecked = $(this).is(":checked");
    $(".blocked-ip").toggle(!isChecked);
    $(this)
      .next()
      .next()
      .text(isChecked ? "Hiện IP Blocked" : "Ẩn IP Blocked");
  });

  // Date range handlers
  $("#apply-date-range").on("click", function () {
    const dateFrom = $("#date-from").val();
    const dateTo = $("#date-to").val();
    let url = "?page=tkgad-moi";
    if (dateFrom) url += "&date_from=" + dateFrom;
    if (dateTo) url += "&date_to=" + dateTo;
    window.location.href = url;
  });

  $("#clear-date-range").on("click", function () {
    window.location.href = "?page=tkgad-moi";
  });

  // Copy blocked IPs
  $("#copy-blocked-ips").on("click", function () {
    const textarea = document.getElementById("blocked-ips-textarea");
    if (!textarea || !textarea.value) {
      alert("Chưa có IP nào bị chặn!");
      return;
    }

    textarea.select();
    document.execCommand("copy");

    const originalText = $(this).html();
    $(this).html("✅ Đã copy!");
    setTimeout(() => {
      $(this).html(originalText);
    }, 2000);
  });

  // Toggle blocked view
  $("#toggle-blocked-view").on("click", function () {
    const currentShow = $(this).data("show");
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set("page", "tkgad-moi");

    if (currentShow === "1" || currentShow === 1) {
      urlParams.delete("show_blocked");
    } else {
      urlParams.set("show_blocked", "1");
    }

    window.location.href = "?" + urlParams.toString();
  });

  // Sort table by visits
  let sortOrder = "desc"; // Mặc định giảm dần (nhiều nhất trước)
  $('.sortable[data-sort="visits"]').on("click", function () {
    const $table = $(this).closest("table");
    const $tbody = $table.find("tbody");
    const $rows = $tbody.find("tr").get();

    // Toggle sort order
    sortOrder = sortOrder === "desc" ? "asc" : "desc";

    // Update icon
    $(".sort-icon").text(sortOrder === "desc" ? "▼" : "▲");

    // Sort rows by ad clicks
    $rows.sort(function (a, b) {
      const aVal = parseInt($(a).data("ad-clicks")) || 0;
      const bVal = parseInt($(b).data("ad-clicks")) || 0;

      if (sortOrder === "desc") {
        return bVal - aVal; // Giảm dần
      } else {
        return aVal - bVal; // Tăng dần
      }
    });

    // Re-append sorted rows
    $.each($rows, function (index, row) {
      $tbody.append(row);
    });

    refreshDashboardTable();
  });

  let dashboardCurrentPage = 1;
  const dashboardPageSize = 10;

  function getFilteredDashboardRows() {
    const searchValue = ($("#search-ip-input").val() || "").toLowerCase();
    return $("#ip-table-body .ip-row").filter(function () {
      return $(this).text().toLowerCase().indexOf(searchValue) > -1;
    });
  }

  function refreshDashboardTable() {
    const $rows = $("#ip-table-body .ip-row");
    if (!$rows.length) {
      return;
    }

    const $filteredRows = getFilteredDashboardRows();
    const totalRows = $filteredRows.length;
    const totalPages = Math.max(Math.ceil(totalRows / dashboardPageSize), 1);
    dashboardCurrentPage = Math.min(Math.max(dashboardCurrentPage, 1), totalPages);

    $rows.hide();

    const startIndex = (dashboardCurrentPage - 1) * dashboardPageSize;
    const endIndex = Math.min(startIndex + dashboardPageSize, totalRows);
    $filteredRows.slice(startIndex, endIndex).show();

    $("#total-ips-count").text(totalRows);
    $("#table-info").text(
      totalRows > 0
        ? `Hiển thị ${startIndex + 1}-${endIndex} của ${totalRows} IPs`
        : "Hiển thị 0 của 0 IPs",
    );

    let html = "";
    html += `<button type="button" class="dashboard-page-btn px-3 py-1 rounded border border-gray-200 hover:bg-gray-50 disabled:opacity-50 h-8 border-solid cursor-pointer" data-page="${dashboardCurrentPage - 1}" ${dashboardCurrentPage === 1 ? "disabled" : ""}>Trước</button>`;

    const visiblePages = Math.min(totalPages, 3);
    for (let page = 1; page <= visiblePages; page++) {
      const activeClass =
        page === dashboardCurrentPage
          ? "bg-blue-600 text-white font-medium border-none"
          : "border border-gray-200 hover:bg-gray-50 border-solid";
      html += `<button type="button" class="dashboard-page-btn px-3 py-1 rounded ${activeClass} h-8 cursor-pointer" data-page="${page}">${page}</button>`;
    }

    html += `<button type="button" class="dashboard-page-btn px-3 py-1 rounded border border-gray-200 hover:bg-gray-50 h-8 border-solid cursor-pointer" data-page="${dashboardCurrentPage + 1}" ${dashboardCurrentPage >= totalPages ? "disabled" : ""}>Tiếp</button>`;

    $("#pagination-controls").html(html);
  }

  function updateBlockedIpCache(ip, isBlocked) {
    const textarea = document.getElementById("blocked-ips-textarea");
    if (!textarea) {
      return;
    }

    const ips = textarea.value
      .split(/\r?\n/)
      .map((item) => item.trim())
      .filter(Boolean);
    const uniqueIps = new Set(ips);

    if (isBlocked) {
      uniqueIps.add(ip);
    } else {
      uniqueIps.delete(ip);
    }

    const nextIps = Array.from(uniqueIps);
    textarea.value = nextIps.join("\n");
    $("#copy-count-badge").text(nextIps.length);
  }

  $("#search-ip-input").on("keyup", function () {
    dashboardCurrentPage = 1;
    refreshDashboardTable();
  });

  $(document).on("click", ".dashboard-page-btn", function () {
    const requestedPage = parseInt($(this).data("page"), 10);
    if (requestedPage && !$(this).prop("disabled")) {
      dashboardCurrentPage = requestedPage;
      refreshDashboardTable();
    }
  });

  refreshDashboardTable();

  // Open popup modals
  $("#open-manage-ip").on("click", function () {
    $("#manage-ip-modal").fadeIn();
  });

  // Copy danh sách IP bị chặn
  $("#copy-blocked-ips-btn").on("click", function () {
    var textarea = document.getElementById("blocked-ips-copy-hidden");
    if (!textarea || !textarea.value) {
      alert("Chưa có IP nào bị chặn!");
      return;
    }

    textarea.style.position = "static";
    textarea.select();
    document.execCommand("copy");
    textarea.style.position = "absolute";

    var originalText = $(this).html();
    $(this).html("✅ Đã copy!");
    setTimeout(() => {
      $(this).html(originalText);
    }, 2000);
  });

  // Copy blocked IPs từ modal (giữ lại cho backward compatibility)
  $("#copy-blocked-ips-modal").on("click", function () {
    var textarea = document.getElementById("blocked-ips-hidden");
    if (!textarea || !textarea.value) {
      alert("Chưa có IP nào bị chặn!");
      return;
    }

    textarea.style.position = "static";
    textarea.select();
    document.execCommand("copy");
    textarea.style.position = "absolute";

    $(this).text("✅ Đã copy!");
    setTimeout(() => {
      $(this).text("📋 Copy tất cả");
    }, 2000);
  });

  // Delete blocked IP
  $(".delete-blocked-ip").on("click", function () {
    const ip = $(this).data("ip");
    if (!confirm("Bạn có chắc muốn xóa IP: " + ip + "?")) return;

    $.ajax({
      url: tkgadm_vars.ajaxurl,
      type: "POST",
      data: {
        action: "tkgadm_toggle_block_ip",
        ip: ip,
        block_action: "unblock",
        nonce: tkgadm_vars.nonce_block,
      },
      success: function (response) {
        if (response.success) {
          alert("Đã xóa IP: " + ip);
          location.reload();
        } else {
          alert("Lỗi: " + response.data);
        }
      },
    });
  });

  // Confirm block IP - Hỗ trợ nhiều IP
  $("#confirm-block-ip").on("click", function () {
    const ipInput = $("#ip-to-block").val().trim();

    if (!ipInput) {
      alert("Vui lòng nhập ít nhất một IP!");
      return;
    }

    // Split by newline, comma, or space
    const ips = ipInput
      .split(/[\n,\s]+/)
      .map((ip) => ip.trim())
      .filter((ip) => ip.length > 0);

    if (ips.length === 0) {
      alert("Không tìm thấy IP hợp lệ!");
      return;
    }

    const $button = $(this);
    $button.prop("disabled", true).text("⏳ Đang xử lý...");

    let successCount = 0;
    let errorCount = 0;

    // Block từng IP
    const blockPromises = ips.map((ip) => {
      return $.ajax({
        url: tkgadm_vars.ajaxurl,
        type: "POST",
        data: {
          action: "tkgadm_toggle_block_ip",
          ip: ip,
          block_action: "block",
          nonce: tkgadm_vars.nonce_block,
        },
      }).then(
        (response) => {
          if (response.success) {
            successCount++;
          } else {
            errorCount++;
          }
        },
        (error) => {
          errorCount++;
        },
      );
    });

    // Đợi tất cả requests hoàn thành
    Promise.all(blockPromises).finally(() => {
      $button.prop("disabled", false).text("🚫 Chặn tất cả IP");

      if (successCount > 0) {
        alert(`✅ Đã chặn thành công ${successCount} IP!`);
        $("#ip-to-block").val("");
        $("#manage-ip-modal").fadeOut();
        location.reload();
      } else {
        alert(`❌ Không thể chặn IP. Vui lòng kiểm tra lại!`);
      }
    });
  });

  // Toggle block/unblock - Công tắc đơn giản
  $(".toggle-block").on("change", function () {
    const ip = $(this).data("ip");
    const $checkbox = $(this);
    const $row = $checkbox.closest("tr");
    const $label = $row.find(".status-label");
    const isBlocking = $checkbox.is(":checked");

    // Disable checkbox trong khi xử lý
    $checkbox.prop("disabled", true);

    $.ajax({
      url: tkgadm_vars.ajaxurl,
      type: "POST",
      data: {
        action: "tkgadm_toggle_block_ip",
        ip: ip,
        block_action: isBlocking ? "block" : "unblock",
        nonce: tkgadm_vars.nonce_block,
      },
      success: function (response) {
        console.log('Block IP Response:', response);
        if (response.success) {
          // Cập nhật UI
          if (response.data.blocked) {
            updateBlockedIpCache(ip, true);
            $row.addClass("bg-red-50/30").removeClass("hover:bg-gray-50");
            if ($row.find(".status-badge").length === 0) {
              $row
                .find("td:first")
                .append(
                  ' <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 status-badge">Banned</span>',
                );
            }
            $label
              .removeClass("text-emerald-600")
              .addClass("text-red-600")
              .text("Bị chặn");
            
            // Show sync status message
            if (response.data.sync_message) {
              const color = response.data.sync_status === 'synced' ? '#28a745' : '#dc3545';
              const $syncMsg = $("<div>")
                .css({
                  position: 'fixed',
                  top: '50%',
                  left: '50%',
                  transform: 'translate(-50%, -50%)',
                  background: color,
                  color: 'white',
                  padding: '15px 30px',
                  borderRadius: '8px',
                  fontSize: '16px',
                  fontWeight: 'bold',
                  zIndex: 99999,
                  boxShadow: '0 4px 12px rgba(0,0,0,0.3)'
                })
                .text(response.data.sync_message)
                .appendTo('body');
              
              setTimeout(function() {
                $syncMsg.fadeOut(300, function() {
                  $(this).remove();
                });
              }, 2000);
            }
          } else {
            updateBlockedIpCache(ip, false);
            $row.removeClass("bg-red-50/30").addClass("hover:bg-gray-50");
            $row.find(".status-badge").remove();
            $label
              .removeClass("text-red-600")
              .addClass("text-emerald-600")
              .text("Hoạt động");
          }
          refreshDashboardTable();
          $checkbox.prop("disabled", false);
        } else {
          alert("Lỗi: " + response.data);
          // Revert checkbox
          $checkbox.prop("checked", !isBlocking).prop("disabled", false);
          refreshDashboardTable();
        }
      },
      error: function () {
        alert("Lỗi kết nối!");
        // Revert checkbox
        $checkbox.prop("checked", !isBlocking).prop("disabled", false);
        refreshDashboardTable();
      },
    });
  });

  // View details modal with chart
  let visitChart = null;
  $(".view-details").on("click", function (e) {
    e.preventDefault(); // Ngăn link navigate

    const ip = $(this).data("ip");
    const urlsData = $(this).data("urls");
    const urls = urlsData ? urlsData.toString().split("|||") : [];

    console.log("Opening modal for IP:", ip);
    console.log("URLs data:", urlsData);
    console.log("Chart.js available:", typeof Chart !== "undefined");

    $("#modal-title").text("📋 Chi tiết của IP: " + ip);

    // Hiển thị loading
    $("#url-list")
      .empty()
      .append(
        $("<p>").css("text-align", "center").text("⏳ Đang tải biểu đồ..."),
      );
    $("#url-modal").fadeIn();

    // Load chart data
    $.ajax({
      url: tkgadm_vars.ajaxurl,
      type: "POST",
      data: {
        action: "tkgadm_get_chart_data",
        ip: ip,
        nonce: tkgadm_vars.nonce_chart,
      },
      success: function (response) {
        console.log("AJAX Response:", response);

        if (response.success) {
          // Destroy old chart
          if (visitChart) {
            console.log("Destroying old chart");
            visitChart.destroy();
          }

          // Check if Chart.js is loaded
          if (typeof Chart === "undefined") {
            console.error("Chart.js is not loaded!");
            $("#url-list").html(
              "<p style='color:red;'>Lỗi: Chart.js chưa được tải. Vui lòng tải lại trang.</p>",
            );
            return;
          }

          // Create new chart
          const canvas = document.getElementById("visit-chart");
          if (!canvas) {
            console.error("Canvas element not found!");
            return;
          }

          const ctx = canvas.getContext("2d");
          console.log("Creating chart with data:", response.data);

          visitChart = new Chart(ctx, {
            type: "line",
            data: {
              labels: response.data.labels,
              datasets: [
                {
                  label: "Số lần truy cập",
                  data: response.data.data,
                  borderColor: "#667eea",
                  backgroundColor: "rgba(102, 126, 234, 0.1)",
                  tension: 0.4,
                  fill: true,
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: true,
              plugins: {
                legend: { display: true },
                title: { display: true, text: "Biểu đồ truy cập theo giờ" },
              },
              scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } },
              },
            },
          });

          console.log("Chart created successfully");
        } else {
          console.error("AJAX error:", response.data);
          $("#url-list").html(
            "<p style='color:red;'>Lỗi tải biểu đồ: " + response.data + "</p>",
          );
        }

        // Load chi tiết phiên truy cập
        $.ajax({
          url: tkgadm_vars.ajaxurl,
          type: "POST",
          data: {
            action: "tkgadm_get_visit_details",
            ip: ip,
            nonce: tkgadm_vars.nonce_chart,
          },
          success: function (detailResponse) {
            console.log("Visit details:", detailResponse);

            if (detailResponse.success && detailResponse.data.visits) {
              const visits = detailResponse.data.visits;

              var visitHeader = $("<h3>")
                .css("margin-top", "20px")
                .text(
                  "📋 Chi tiết phiên truy cập (" + visits.length + " phiên)",
                );
              $("#url-list").empty().append(visitHeader);

              if (visits.length === 0) {
                $("#url-list").append("<p>Chưa có dữ liệu phiên truy cập.</p>");
                return;
              }

              // Tạo bảng chi tiết
              var table = $("<table>")
                .addClass("tkgadm-table")
                .css({ "margin-top": "15px", "font-size": "13px" });

              var thead = $("<thead>").html(
                "<tr>" +
                  "<th>⏰ Thời gian</th>" +
                  "<th>🔗 URL</th>" +
                  "<th>🏷️ UTM Term</th>" +
                  "<th>⏱️ Time on Page</th>" +
                  "<th>🔄 Lượt xem</th>" +
                  "</tr>",
              );

              var tbody = $("<tbody>");
              visits.forEach(function (visit) {
                var timeOnPage =
                  visit.time_on_page > 0
                    ? visit.time_on_page + "s"
                    : "<span style='color:#999;'>N/A</span>";

                // Rút gọn URL để hiển thị
                var displayUrl =
                  visit.url.length > 60
                    ? visit.url.substring(0, 60) + "..."
                    : visit.url;

                var row = $("<tr>");
                row.append($("<td>").text(visit.visit_time));

                // Cột URL: hiển thị rút gọn, double-click để copy
                var urlCell = $("<td>")
                  .addClass("url-copy-cell")
                  .attr("title", "Double-click để copy URL đầy đủ")
                  .css({
                    cursor: "pointer",
                    transition: "background 0.2s",
                  })
                  .html(
                    "<small style='word-break:break-all; color: #007cba;'>" +
                      displayUrl +
                      "</small>",
                  )
                  .data("full-url", visit.url);

                row.append(urlCell);
                row.append($("<td>").text(visit.utm_term));
                row.append($("<td>").html(timeOnPage));
                row.append($("<td>").text(visit.visit_count));
                tbody.append(row);
              });

              table.append(thead).append(tbody);
              $("#url-list").append(table);

              // Event delegation cho double-click copy URL
              $("#url-list")
                .off("dblclick", ".url-copy-cell")
                .on("dblclick", ".url-copy-cell", function () {
                  var $cell = $(this);
                  var fullUrl = $cell.data("full-url");

                  // Visual feedback
                  $cell.css("background", "#ffffcc");

                  // Copy to clipboard
                  if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard
                      .writeText(fullUrl)
                      .then(function () {
                        alert("✅ Đã copy URL:\n" + fullUrl);
                        $cell.css("background", "");
                      })
                      .catch(function (err) {
                        console.error("Copy failed:", err);
                        fallbackCopy(fullUrl, $cell);
                      });
                  } else {
                    fallbackCopy(fullUrl, $cell);
                  }

                  function fallbackCopy(text, cell) {
                    var temp = $("<textarea>")
                      .val(text)
                      .css({ position: "fixed", left: "-9999px" })
                      .appendTo("body");
                    temp[0].select();
                    try {
                      document.execCommand("copy");
                      alert("✅ Đã copy URL:\n" + text);
                    } catch (err) {
                      alert(
                        "❌ Không thể copy. Vui lòng copy thủ công:\n" + text,
                      );
                    }
                    temp.remove();
                    cell.css("background", "");
                  }
                });
            } else {
              $("#url-list").append(
                "<p style='color:red;'>Lỗi tải chi tiết phiên truy cập.</p>",
              );
            }
          },
          error: function (xhr, status, error) {
            console.error("Failed to load visit details:", error);
            $("#url-list").append(
              "<p style='color:red;'>Lỗi tải chi tiết: " + error + "</p>",
            );
          },
        });
      },
      error: function (xhr, status, error) {
        console.error("AJAX request failed:", status, error);
        console.error("XHR:", xhr);
        $("#url-list")
          .empty()
          .append(
            $("<p>")
              .css("color", "red")
              .text("Lỗi tải biểu đồ: " + error),
          );
      },
    });
  });

  // Close modal
  $(".tkgadm-modal-close").on("click", function () {
    $(".tkgadm-modal").fadeOut();
  });

  $(window).on("click", function (event) {
    if ($(event.target).hasClass("tkgadm-modal")) {
      $(".tkgadm-modal").fadeOut();
    }
  });

  // ============================================================================
  // DAILY STATS CHART (Biểu đồ thống kê hàng ngày)
  // ============================================================================
  let dailyStatsChart = null;

  // Load daily stats data
  function loadDailyStats(dateFrom, dateTo) {
    $("#daily-stats-loading").show();
    $("#daily-stats-chart").hide();

    $.ajax({
      url: tkgadm_vars.ajaxurl,
      type: "POST",
      data: {
        action: "tkgadm_get_daily_stats",
        date_from: dateFrom,
        date_to: dateTo,
        nonce: tkgadm_vars.nonce,
      },
      success: function (response) {
        $("#daily-stats-loading").hide();
        $("#daily-stats-chart").show();

        if (response.success && response.data.data) {
          renderDailyStatsChart(response.data.data);
        } else {
          alert("Lỗi: " + (response.data || "Không thể tải dữ liệu"));
        }
      },
      error: function (xhr, status, error) {
        $("#daily-stats-loading").hide();
        $("#daily-stats-chart").show();
        console.error("Failed to load daily stats:", error);
        alert("Lỗi kết nối: " + error);
      },
    });
  }

  // Render daily stats chart
  function renderDailyStatsChart(data) {
    const labels = data.map((d) => {
      const date = new Date(d.date);
      return date.getDate() + "/" + (date.getMonth() + 1);
    });

    const adsVisits = data.map((d) => d.ads_visits);
    const organicVisits = data.map((d) => d.organic_visits);
    const blockedCounts = data.map((d) => d.blocked_count);

    // Calculate summary
    const totalAds = adsVisits.reduce((a, b) => a + b, 0);
    const totalOrganic = organicVisits.reduce((a, b) => a + b, 0);
    const totalBlocked = blockedCounts.reduce((a, b) => a + b, 0);
    const avgAds = data.length > 0 ? Math.round(totalAds / data.length) : 0;
    const blockRate =
      totalAds > 0 ? ((totalBlocked / totalAds) * 100).toFixed(1) : 0;

    $("#daily-total-ads").text(totalAds.toLocaleString());
    $("#daily-total-organic").text(totalOrganic.toLocaleString());
    $("#daily-total-blocked").text(totalBlocked.toLocaleString());
    $("#daily-avg-ads").text(avgAds.toLocaleString());
    $("#daily-block-rate").text(blockRate + "%");

    // Destroy old chart
    if (dailyStatsChart) {
      dailyStatsChart.destroy();
    }

    // Create new chart
    const ctx = document.getElementById("daily-stats-chart");
    if (!ctx) return;

    dailyStatsChart = new Chart(ctx, {
      type: "line",
      data: {
        labels: labels,
        datasets: [
          {
            label: "Ads Traffic",
            data: adsVisits,
            borderColor: "#2563eb",
            backgroundColor: "rgba(37, 99, 235, 0.1)",
            borderWidth: 2,
            fill: true,
            tension: 0.4,
          },
          {
            label: "Organic Traffic",
            data: organicVisits,
            borderColor: "#10b981",
            backgroundColor: "transparent",
            borderWidth: 2,
            borderDash: [5, 5],
            fill: false,
            tension: 0.4,
          },
          {
            label: "IP Bị chặn",
            data: blockedCounts,
            borderColor: "#ef4444",
            backgroundColor: "transparent",
            borderWidth: 2,
            tension: 0.4,
            fill: false,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: "index",
          intersect: false,
        },
        plugins: {
          legend: {
            position: "top",
            labels: {
              usePointStyle: true,
              boxWidth: 8,
            },
          },
        },
        scales: {
          y: {
            beginAtZero: true,
            grid: {
              color: "#f3f4f6",
            },
          },
          x: {
            grid: {
              display: false,
            },
          },
        },
        onClick: (event, elements) => {
          if (elements.length > 0) {
            const element = elements[0];
            const index = element.index;
            const date = data[index].date;
            const datasetIndex = element.datasetIndex;

            // datasetIndex 0 = Ads, 1 = Organic, 2 = Blocked
            let type;
            if (datasetIndex === 0) {
              type = "ads";
            } else if (datasetIndex === 1) {
              type = "organic";
            } else {
              type = "blocked";
            }

            loadDailyDetails(date, type);
          }
        },
      },
    });
  }

  // Render daily details table with Expand logic

  // Render daily details table with Accordion
  function renderDailyDetailsTable(ips, type) {
    if (ips.length === 0) {
      $("#daily-details-content").html(
        '<p style="text-align:center; padding:20px;">Không có dữ liệu</p>',
      );
      return;
    }

    let html =
      '<table class="tkgadm-table" style="width:100%; border-collapse: separate; border-spacing: 0;">';
    html += "<thead><tr>";
    html += "<th style='width:30px;'></th>"; // Arrow column
    html += "<th>🌐 IP Address</th>";

    if (type === "ads" || type === "organic") {
      html += "<th>📊 Số phiên</th>";
      html += "<th>⏰ Lần cuối</th>";
    } else {
      html += "<th>⏰ Thời gian chặn</th>";
      html += "<th>📊 Tổng lượt</th>";
      html += "<th>🎯 Click Ads</th>";
    }

    html += "<th>⚙️ Trạng thái</th>";
    html += "</tr></thead><tbody>";

    ips.forEach((ip, index) => {
      const blockedBadge = ip.is_blocked
        ? '<span class="tkgadm-badge tkgadm-badge-danger">🚫 Đã chặn</span>'
        : '<span class="tkgadm-badge tkgadm-badge-success">✅ Hoạt động</span>';

      const detailId = `detail-${index}`;

      // Main Row
      html += `<tr class="tkgadm-accordion-toggle" onclick="toggleAccordion('${detailId}', this)">`;
      html += `<td><span class="tkgadm-accordion-icon">▶</span></td>`;
      html += `<td><strong>${ip.ip_address}</strong></td>`;

      if (type === "ads" || type === "organic") {
        html += `<td>${ip.session_count || 0} phiên</td>`;
        html += `<td>${ip.last_visit || "-"}</td>`;
      } else {
        html += `<td>${ip.blocked_time || "-"}</td>`;
        html += `<td>${ip.total_visits || 0}</td>`;
        html += `<td>${ip.ad_clicks || 0}</td>`;
      }

      html += `<td>${blockedBadge}</td>`;
      html += "</tr>";

      // Detail Parent Row (Hidden)
      html += `<tr id="${detailId}" class="tkgadm-accordion-content-row" style="display:none;">`;
      html += `<td colspan="5" style="padding: 10px 20px;">`;

      // Inner Session Table
      if (ip.sessions && ip.sessions.length > 0) {
        html += `<table class="tkgadm-session-table">`;
        html += `<tbody>`;

        ip.sessions.forEach((s, sIndex) => {
          const badgeClass =
            s.type === "Ads" ? "tkgadm-badge-ads" : "tkgadm-badge-organic";
          const badgeIcon = s.type === "Ads" ? "📊" : "🌱";
          const link = s.url
            ? `<a href="${s.url}" target="_blank" class="tkgadm-link">🔗 ${s.url}</a>`
            : "";
          const timeOnPage =
            s.time_on_page > 0
              ? `⏱️ ${s.time_on_page}s`
              : `<span style="color:#999">⏱️ < 1s</span>`;
          const visitCount =
            s.visit_count > 1 ? `📶 ${s.visit_count} lượt` : `📶 1 lượt`;

          html += `<tr>`;
          html += `<td style="width: 120px;"><span class="tkgadm-badge-sm ${badgeClass}">${badgeIcon} ${s.type}</span></td>`;
          html += `<td style="width: 80px; color:#777;">Phiên ${ip.session_count - sIndex}</td>`;
          html += `<td style="width: 150px;">⏰ ${s.time}</td>`; // Just time part
          html += `<td style="width: 100px;">${timeOnPage}</td>`;
          html += `<td style="width: 100px;">${visitCount}</td>`;
          html += `<td>${link}</td>`;
          html += `</tr>`;
        });

        html += `</tbody></table>`;
      } else {
        html += `<div style="padding:10px; color:#777;">Không có chi tiết phiên.</div>`;
      }

      html += `</td></tr>`;
    });

    html += "</tbody></table>";
    $("#daily-details-content").html(html);
  }

  // Toggle Accordion Function
  window.toggleAccordion = function (id, element) {
    const content = $("#" + id);
    const icon = $(element).find(".tkgadm-accordion-icon");

    if (content.is(":visible")) {
      content.hide();
      $(element).removeClass("expanded");
      icon.css("transform", "rotate(0deg)");
    } else {
      content.fadeIn(200);
      $(element).addClass("expanded");
      icon.css("transform", "rotate(90deg)");
    }
  };

  // Load daily details modal
  function loadDailyDetails(date, type) {
    const typeLabel =
      type === "ads"
        ? "📊 Lượt truy cập Ads"
        : type === "organic"
          ? "🌱 Organic Traffic"
          : "🚫 IP bị chặn";
    const formattedDate = new Date(date).toLocaleDateString("vi-VN");

    $("#daily-modal-title").text(`${typeLabel} - ${formattedDate}`);
    $("#daily-details-content").html(
      '<div style="text-align:center; padding:20px;">⏳ Đang tải dữ liệu chi tiết...</div>',
    );
    $("#daily-details-modal").fadeIn();

    $.ajax({
      url: tkgadm_vars.ajaxurl,
      type: "POST",
      data: {
        action: "tkgadm_get_daily_details",
        date: date,
        type: type,
        nonce: tkgadm_vars.nonce,
      },
      success: function (response) {
        if (response.success && response.data.ips) {
          renderDailyDetailsTable(response.data.ips, response.data.type);
        } else {
          $("#daily-details-content").html(
            '<p style="color:red; text-align:center;">Lỗi tải dữ liệu: ' +
              (response.data || "Unknown") +
              "</p>",
          );
        }
      },
      error: function () {
        $("#daily-details-content").html(
          '<p style="color:red; text-align:center;">Lỗi kết nối máy chủ</p>',
        );
      },
    });
  }

  // Calculate date range from days
  function getDateRangeFromDays(days) {
    function formatLocalDate(date) {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, "0");
      const day = String(date.getDate()).padStart(2, "0");
      return `${year}-${month}-${day}`;
    }

    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - days + 1);

    return {
      from: formatLocalDate(from),
      to: formatLocalDate(to),
    };
  }

  // Event handler for time period select
  $("#time-period").on("change", function () {
    const value = $(this).val();

    if (value === "custom") {
      // Show custom date range picker
      $("#custom-date-range").css("display", "inline-flex");
    } else {
      // Hide custom date range picker
      $("#custom-date-range").hide();

      // Calculate date range and reload page with query string
      const days = parseInt(value);
      const range = getDateRangeFromDays(days);

      const urlParams = new URLSearchParams(window.location.search);
      urlParams.set("page", "tkgad-moi");
      urlParams.set("date_from", range.from);
      urlParams.set("date_to", range.to);
      window.location.href = "?" + urlParams.toString();
    }
  });

  // Event handler for custom date range apply
  $("#apply-custom-range").on("click", function () {
    const dateFrom = $("#date-from").val();
    const dateTo = $("#date-to").val();

    if (!dateFrom || !dateTo) {
      alert("Vui lòng chọn đầy đủ ngày bắt đầu và kết thúc.");
      return;
    }

    if (new Date(dateFrom) > new Date(dateTo)) {
      alert("Ngày bắt đầu phải nhỏ hơn ngày kết thúc.");
      return;
    }

    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set("page", "tkgad-moi");
    urlParams.set("date_from", dateFrom);
    urlParams.set("date_to", dateTo);
    window.location.href = "?" + urlParams.toString();
  });

  // Load initial daily stats based on current URL params or default 30 days
  if ($("#daily-stats-chart").length > 0) {
    // Get date range from URL or use default
    const urlParams = new URLSearchParams(window.location.search);
    let dateFrom = urlParams.get("date_from");
    let dateTo = urlParams.get("date_to");

    if (!dateFrom || !dateTo) {
      const range = getDateRangeFromDays(30);
      dateFrom = range.from;
      dateTo = range.to;
    }

    // --- SYNC DROPDOWN WITH DATE RANGE ---
    const d1 = new Date(dateFrom);
    const d2 = new Date(dateTo);
    const diffTime = Math.abs(d2 - d1);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

    // Check specific options or custom
    // Note: +/- 1 day tolerance can be useful if timezone issues occur, strict blocking for now
    const validOptions = ["1", "7", "15", "30", "60", "180"];

    if (validOptions.includes(diffDays.toString())) {
      $("#time-period").val(diffDays);
      $("#custom-date-range").hide();
    } else {
      $("#time-period").val("custom");
      $("#custom-date-range").css("display", "inline-flex");
    }
    // -------------------------------------

    loadDailyStats(dateFrom, dateTo);
  }
});
