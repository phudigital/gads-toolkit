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
              .html("🚫 Đã chặn");
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
  $(".view-details").on("click", function () {
    const ip = $(this).data("ip");
    const urls = $(this).data("urls").split("|||");

    $("#modal-title").text("📋 Chi tiết của IP: " + ip);

    // Hiển thị loading
    $("#url-list")
      .empty()
      .append(
        $("<p>").css("text-align", "center").text("⏳ Đang tải biểu đồ...")
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
        if (response.success) {
          // Destroy old chart
          if (visitChart) visitChart.destroy();

          // Create new chart
          const ctx = document.getElementById("visit-chart").getContext("2d");
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
        } else {
          $("#url-list").html(
            "<p style='color:red;'>Lỗi tải biểu đồ: " + response.data + "</p>"
          );
        }

        // Show URLs
        var urlHeader = $("<h3>")
          .css("margin-top", "20px")
          .text("🔗 Danh sách URLs");
        $("#url-list").empty().append(urlHeader);

        function extractUtmTerm(url) {
          try {
            const urlObj = new URL(url);
            const params = new URLSearchParams(urlObj.search);
            return params.get("utm_term") || "-";
          } catch (e) {
            return "-";
          }
        }

        urls.forEach(function (url, index) {
          const utmTerm = extractUtmTerm(url);
          var urlItem = $("<div>").addClass("tkgadm-url-item");
          urlItem.append($("<strong>").text("URL " + (index + 1) + ":"));
          urlItem.append($("<br>"));
          urlItem.append($("<small>").text(url));
          urlItem.append($("<br>"));
          urlItem.append($("<strong>").text("UTM Term: "));
          urlItem.append($("<span>").css("color", "#667eea").text(utmTerm));
          $("#url-list").append(urlItem);
        });
      },
      error: function () {
        $("#url-list")
          .empty()
          .append($("<p>").css("color", "red").text("Lỗi tải biểu đồ"));
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
