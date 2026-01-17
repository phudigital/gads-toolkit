/**
 * GAds Toolkit - Time on Page Tracker
 * Đo thời gian người dùng ở lại trang và gửi về server
 * Chạy cho TẤT CẢ traffic (đã lọc Bot ở PHP)
 */

(function () {
  let startTime = Date.now();

  // Lấy thông tin cần thiết
  function getTrackingData() {
    const urlParams = new URLSearchParams(window.location.search);
    // Ưu tiên gclid, nếu không có thì dùng gbraid
    let clickId = urlParams.get("gclid") || "";
    if (!clickId) {
      clickId = urlParams.get("gbraid") || "";
    }

    return {
      url: window.location.href,
      gclid: clickId, // Lưu cả gclid hoặc gbraid (hoặc rỗng nếu là Organic)
      user_agent: navigator.userAgent,
    };
  }

  // Gửi thời gian về server
  function sendTimeUpdate() {
    const currentTime = Date.now();
    const timeOnPage = Math.floor((currentTime - startTime) / 1000); // Đổi sang giây

    // Chỉ gửi nếu đã qua ít nhất 3 giây
    if (timeOnPage < 3) {
      return;
    }

    const trackingData = getTrackingData();

    // Gửi AJAX bằng sendBeacon (tối ưu cho beforeunload)
    const data = new URLSearchParams({
      action: "tkgadm_update_time_on_page",
      ip: tkgadm_tracker.user_ip,
      url: trackingData.url,
      time: timeOnPage,
      user_agent: trackingData.user_agent,
      gclid: trackingData.gclid,
    });

    // Dùng sendBeacon nếu có, fallback sang XHR
    if (navigator.sendBeacon) {
      navigator.sendBeacon(tkgadm_tracker.ajaxurl, data);
    } else {
      const xhr = new XMLHttpRequest();
      xhr.open("POST", tkgadm_tracker.ajaxurl, false); // Sync request cho beforeunload
      xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
      xhr.send(data.toString());
    }
  }

  // Gửi khi người dùng rời trang
  window.addEventListener("beforeunload", function () {
    sendTimeUpdate();
  });

  // Gửi khi tab bị ẩn (người dùng chuyển tab hoặc minimize)
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) {
      sendTimeUpdate();
    }
  });

  console.log("GAds Toolkit Time Tracker initialized (All traffic)");
})();
