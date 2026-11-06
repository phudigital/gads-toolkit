/**
 * GAds Toolkit - visit beacon + time on page.
 * Works with page caches: no per-visitor data is embedded in the HTML,
 * the server derives the visitor IP itself.
 */
(function () {
  var cfg = window.tkgadm_tracker || {};
  var startTime = Date.now();
  var lastSent = 0;

  function pageUrl() {
    return window.location.href.split("#")[0];
  }

  function send(path, fields) {
    var body = new URLSearchParams(fields);
    if (cfg.rest) {
      var url = cfg.rest + path;
      if (navigator.sendBeacon && navigator.sendBeacon(url, body)) {
        return;
      }
      if (window.fetch) {
        fetch(url, { method: "POST", body: body, keepalive: true, credentials: "omit" }).catch(function () {});
      }
      return;
    }
    // Pre-4.4 localized config (old cached HTML): admin-ajax fallback.
    if (cfg.ajaxurl && path === "time" && navigator.sendBeacon) {
      var params = new URLSearchParams(window.location.search);
      fields.action = "tkgadm_update_time_on_page";
      fields.gclid = params.get("gclid") || params.get("gbraid") || params.get("wbraid") || "";
      navigator.sendBeacon(cfg.ajaxurl, new URLSearchParams(fields));
    }
  }

  function sendTime() {
    var seconds = Math.floor((Date.now() - startTime) / 1000);
    if (seconds < 3 || seconds === lastSent) {
      return;
    }
    lastSent = seconds;
    send("time", { url: pageUrl(), time: String(seconds) });
  }

  // Record the view (covers cached pages; deduplicated server side).
  send("hit", { url: pageUrl() });

  window.addEventListener("pagehide", sendTime);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") {
      sendTime();
    }
  });
})();
