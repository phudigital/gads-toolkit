# GAds Toolkit - Technical Memory for AI Coding

**Mục đích tài liệu:** Đây là bộ nhớ kỹ thuật (Memory/Context) tiêu chuẩn dành cho tác vụ AI Coding. Tài liệu này lưu trữ kiến trúc cốt lõi, flow hoạt động, và các rule thiết kế của plugin **GAds Toolkit** sau khi đã migrate Central Service sang Cloudflare Workers (Kiến trúc Zero Hardcode).

---

## 1. Tổng quan hệ thống (System Overview)
* **Tên dự án:** GAds Toolkit
* **Chức năng chính:** Theo dõi truy cập, phát hiện và chặn IP click tặc tự động đẩy lên Danh sách loại trừ (Exclusion List) mức tài khoản (Customer Level) của Google Ads.
* **Mô hình kiến trúc (MỚI):**
  - **Client:** Plugin WordPress (PHP) chạy trên site khách hàng.
  - **Server (Central Service):** Cloudflare Worker (JS) đóng vai trò proxy bảo mật, lưu trữ giấy phép (license), API version, và kết nối với Google Ads API. Quản lý qua Admin Dashboard tĩnh (Inline HTML) ngay trên Worker.

## 2. Cấu trúc Project (Project Structure)
Dự án được chia làm 2 phần độc lập trong cùng repo:

### A. WordPress Plugin (Thư mục gốc)
* `gads-toolkit.php`: File chính của plugin. Định nghĩa `GADS_SERVICE_URL`.
* `includes/core-engine.php`: Module theo dõi traffic (Tracking Engine) bắt IP, GCLID.
* `includes/module-data.php`: Xử lý Database nội bộ WP (Lọc data, tính toán click).
* `includes/module-google-ads.php`: Tương tác với Central Service qua API.
* `includes/module-notifications.php`: Cảnh báo Telegram, Email (dùng wp_mail).
* `includes/module-dashboard.php`: Giao diện backend WP (Thống kê, biểu đồ).
* `includes/module-settings.php`: Giao diện Cấu hình & Tích hợp (Google Ads, Quy tắc chặn, Thông báo).
* `assets/`: File tĩnh (CSS, JS, images).

### B. Cloudflare Worker Central Service (`cloudflare-worker/`)
* `wrangler.toml`: Cấu hình Worker, KV Bindings, Cron Triggers.
* `src/index.js`: Router chính.
* `src/api.js`: Xử lý các endpoint public (`/api?action=sync_ips`, `exchange_code`, ...).
* `src/admin.js`: API nội bộ và giao diện Admin Dashboard.
* `src/auth.js`: Middleware bảo mật, Rate limit.
* `src/utils.js`: Helper functions (IP validation, KV logging).

## 3. Kiến trúc Cloudflare Worker (Zero Hardcode)
Kiến trúc này giải quyết triệt để lỗi 404 do Google sunset API version, đồng thời bảo mật `Developer Token`.

* **Lưu trữ toàn bộ cấu hình trên KV (`GADS_KV`)**:
  - `config:api_version`: VD `"v25"`. Có thể đổi realtime qua Dashboard.
  - `config:allowed_origins`, `config:rate_limit`
  - `license:{key}`: Thông tin giấy phép khách hàng.
  - `client:{url}`: Đăng ký thiết bị (Heartbeat).
* **Bảo mật**:
  - `ADMIN_TOKEN` và Google Credentials lưu dạng `Worker Secrets`. Không bao giờ lộ trong mã nguồn.
  - Truy cập Admin Dashboard yêu cầu `Bearer {ADMIN_TOKEN}`.

## 4. Flow Hoạt Động Cốt Lõi
1. Khách hàng cài Plugin WP, nhập `License Key`.
2. IP vi phạm bị phát hiện (qua Cron 15 phút tại `module-google-ads.php`).
3. Plugin gửi request chứa `ips`, `refresh_token`, `customer_id` đến Worker Endpoint `/api?action=sync_ips` (Kèm header `X-API-Key`).
4. Worker kiểm tra License Key từ KV, Rate Limit.
5. Worker lấy `api_version` từ KV, tạo request đẩy IP lên Google Ads API.
6. Worker trả kết quả về Plugin WP, đồng thời ghi log vào KV (`logs:recent`).

## 5. Quy tắc AI Coding khi sửa/nâng cấp
1. **Không Hardcode**: Mọi endpoint của Google API, version, hoặc config động phải được lấy từ Cloudflare KV. Nếu thêm config mới, hãy thêm vào Admin Dashboard (trong `src/admin.js`).
2. **Workers Free Tier**: Tránh các vòng lặp vô tận, giảm thiểu số lần Write vào KV (ưu tiên đọc). Giữ bundle size nhỏ (Vanilla JS, không Framework).
3. **Không can thiệp Email Global WP**: Không dùng Hook `phpmailer_init` ở plugin WP để tránh crash Contact Form 7.
4. **Database Security (WP)**: SQL raw phải qua `$wpdb->prepare`. IP Tracking bắt Filter `FILTER_VALIDATE_IP`.
