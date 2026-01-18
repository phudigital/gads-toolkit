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
    if (currentShow === "1" || currentShow === 1) {
      // Đang hiện IP chặn -> chuyển sang hiện tất cả
      window.location.href = "?page=tkgad-moi";
    } else {
      // Đang hiện tất cả -> chuyển sang chỉ hiện IP chặn
      window.location.href = "?page=tkgad-moi&show_blocked=1";
    }
  });

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
  $("#copy-blocked-ips").on("click", function () {
    var textarea = document.getElementById("blocked-ips-hidden");
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
    const $label = $row.find(".tkgadm-toggle-label");
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
        if (response.success) {
          // Cập nhật UI
          if (response.data.blocked) {
            $row.addClass("tkgadm-blocked blocked-ip");
            $row.find(".tkgadm-badge-danger").remove();
            var badge = $("<span>")
              .addClass("tkgadm-badge tkgadm-badge-danger")
              .html("🚫");
            $row.find("td:first strong").after(" ", badge);
            $label.removeClass("active").addClass("blocked").text("Đã chặn");
          } else {
            $row.removeClass("tkgadm-blocked blocked-ip");
            $row.find(".tkgadm-badge-danger").remove();
            $label.removeClass("blocked").addClass("active").text("Hoạt động");
          }
          $checkbox.prop("disabled", false);
        } else {
          alert("Lỗi: " + response.data);
          // Revert checkbox
          $checkbox.prop("checked", !isBlocking).prop("disabled", false);
        }
      },
      error: function () {
        alert("Lỗi kết nối!");
        // Revert checkbox
        $checkbox.prop("checked", !isBlocking).prop("disabled", false);
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
});
