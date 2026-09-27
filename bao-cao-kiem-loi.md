# Báo Cáo Kiểm Lỗi Tổng Thể & Unit Test

## 1. Unit Test Suite (PHPUnit + Brain Monkey)
Tôi đã thiết lập môi trường Unit Test hoàn chỉnh sử dụng `PHPUnit` kết hợp với thư viện `Brain Monkey` (để mock các hàm WordPress).
Các file Unit Test đã được tạo trong thư mục `tests/unit/`:

- `CoreEngineTest.php`: Kiểm tra logic lấy IP người dùng (`tkgadm_get_real_user_ip`), giả lập các trường hợp có Cloudflare proxy, header `X-Forwarded-For` và kết nối trực tiếp.
- `IpValidationTest.php`: Kiểm tra hàm `tkgadm_validate_ip_pattern` với các format IPv4 chuẩn, IPv4 kèm wildcard (dải IP) và cả IPv6.
- `GoogleAdsIdTest.php`: Kiểm tra tính toàn vẹn của hàm `tkgadm_validate_gads_id_format`, xử lý các ID đúng định dạng (có khoảng trắng, có dấu gạch ngang) và từ chối các ID sai định dạng, trả về đúng `WP_Error`.
- `IpNormalizationTest.php`: Đảm bảo `tkgadm_normalize_google_ads_ip` chuyển đổi chính xác các dải IP dạng `192.168.1.*` thành định dạng CIDR `192.168.1.0/24` để tương thích với Google Ads API.

**Kết quả Test:** 
Chạy thành công `15/15 tests` (với 31 assertions). Logic xử lý chuỗi và định dạng của plugin rất vững.

## 2. Kiểm Lỗi Tổng Thể Codebase (Code Audit)
Dựa trên phân tích sâu toàn bộ mã nguồn PHP và JavaScript (từ plugin và Cloudflare Worker):

### Điểm Mạnh (✅)
1. **Bảo mật**: Các tiêu chuẩn bảo mật của WordPress được áp dụng rất tốt. Hàm AJAX đều có check `nonce` (`check_ajax_referer`), phân quyền chuẩn (`current_user_can('manage_options')`). Dữ liệu đầu vào được làm sạch kỹ bằng `sanitize_text_field`.
2. **Database**: Bảng custom được dùng thay vì nhồi nhét vào `wp_posts` hay `wp_options`. Đa số các truy vấn cơ sở dữ liệu đều sử dụng `$wpdb->prepare` để chống SQL Injection.
3. **Cloudflare Worker**: Mã JS bên Cloudflare Worker được viết rất sạch, bắt exception chuẩn, có check Rate-Limit, Hash Token để chống Timing Attack.

### Các Lỗi / Rủi Ro Tiềm Ẩn Cần Khắc Phục (⚠️)
1. **Lỗ hổng IP Spoofing (Giả mạo IP):** 
   Hàm `tkgadm_get_real_user_ip()` ưu tiên lấy IP từ `HTTP_X_FORWARDED_FOR` nếu không có `HTTP_CF_CONNECTING_IP`. Nếu website không sử dụng reverse proxy (như Cloudflare hay Nginx), một attacker có thể gửi request kèm header `X-Forwarded-For: 1.1.1.1` để qua mặt hệ thống chặn hoặc "đổ oan" cho một IP hợp lệ. 
   *(Đề xuất: Thêm tùy chọn "Bật chế độ Trust Proxy" trong Cài đặt, hoặc chỉ lấy chuỗi IP đầu tiên trong chuỗi X-Forwarded-For).*
2. **Khác biệt Timezone ở Cron Jobs:** 
   Trong file `module-notifications.php`, có sự pha trộn giữa `wp_date()` (timezone của WP) và `current_time('mysql')` hoặc cấu trúc query SQL `DATE_SUB(NOW(), INTERVAL...)`. Việc lệch múi giờ giữa PHP và MySQL Server có thể khiến các logic cron job nhận diện sai lệch giờ giao ngày của báo cáo.
3. **Hiệu suất (Performance) Cloudflare Worker:**
   Hàm cron ping (`handleCron`) sử dụng `Promise.allSettled(clientPromises)` để ping tất cả WP sites cùng lúc. Nếu lượng site lớn (>50), hệ thống sẽ báo lỗi vượt ngưỡng "concurrent subrequests limit" của Cloudflare Workers.
4. **Time-Tracker Javascript:** 
   Script `time-tracker.js` đang fallback xuống sync XHR (XMLHttpRequest đồng bộ) trên event `beforeunload`. API này đang dần bị các trình duyệt hiện đại chặn lại nhằm tránh treo tab khi đóng. Nên chuyển hẳn qua sử dụng `navigator.sendBeacon`.

**Tổng kết:** Plugin đang được cấu trúc rất tốt, hoạt động trơn tru. Hệ thống test hiện đã được lưu cấu hình trong `phpunit.xml` và `composer.json`, bạn có thể duy trì việc chạy test bằng lệnh `./vendor/bin/phpunit` trong tương lai.
