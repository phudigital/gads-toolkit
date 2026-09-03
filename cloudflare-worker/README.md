# GAds Toolkit - Cloudflare Worker Central Service

Đây là service trung tâm (Central Service) của GAds Toolkit được viết lại hoàn toàn để chạy trên Cloudflare Workers, thay thế cho code PHP trên VPS cũ.

Kiến trúc mới **Zero Hardcode** - toàn bộ cấu hình, giấy phép (licenses), lịch sử được lưu vào Cloudflare KV và có thể quản lý trực tiếp qua **Admin Dashboard**.

## 🚀 Hướng dẫn Deploy

### 1. Chuẩn bị
Bạn cần cài đặt Node.js và Wrangler CLI:
```bash
npm install -g wrangler
```

Đăng nhập vào Cloudflare:
```bash
wrangler login
```

### 2. Tạo KV Namespace
Tạo một KV namespace để lưu trữ dữ liệu (bắt buộc):
```bash
wrangler kv namespace create GADS_KV
```
Sau khi tạo xong, Cloudflare sẽ cấp cho bạn một `id`. Hãy copy `id` đó và dán vào file `wrangler.toml`:
```toml
[[kv_namespaces]]
binding = "GADS_KV"
id = "YOUR_KV_NAMESPACE_ID_HERE"
```

### 3. Cấu hình Secrets (Bảo mật)
Cần nhập các token bảo mật trực tiếp thông qua Wrangler (KHÔNG ghi vào code). Chạy lần lượt các lệnh sau và dán token của bạn vào:

```bash
# Token để đăng nhập Admin Dashboard (tự chọn một chuỗi an toàn, vd: openssl rand -hex 32)
wrangler secret put ADMIN_TOKEN

# Google Ads Credentials (Lấy từ Google Cloud Console)
wrangler secret put GADS_CLIENT_ID
wrangler secret put GADS_CLIENT_SECRET
wrangler secret put GADS_DEVELOPER_TOKEN
```

### 4. Khởi tạo Cấu hình Cơ bản (Lần đầu)
Vì Worker dùng kiến trúc "Zero Hardcode", lần đầu tiên deploy bạn cần có ít nhất cấu hình cơ bản trong KV để hoạt động (API version, rate limit, v.v.).

Cách đơn giản nhất là deploy Worker trước:
```bash
wrangler deploy
```
Sau đó truy cập **Admin Dashboard** tại `https://gads.pdl.vn/admin` (Sử dụng `ADMIN_TOKEN` đã tạo ở bước 3 để đăng nhập).
Vào tab **⚙️ Cấu hình** và điền:
- API Version: `v25`
- OAuth Redirect URI: `https://gads.pdl.vn/oauth`
- Rate Limit: `100`

### 5. Kết nối WordPress (Client)
Trên website WordPress cài GAds Toolkit, vào phần Cấu hình Google Ads, cập nhật đường dẫn tới Worker:
Bên trong plugin, sửa file `gads-toolkit.php` hoặc override option:
```php
define('GADS_SERVICE_URL', 'https://gads.pdl.vn');
```
Sau đó, từ Admin Dashboard của Worker, hãy tạo một License Key và cấp cho website WordPress đó.

---

## 🛠 Cấu trúc thư mục

- `src/index.js`: Router chính xử lý requests.
- `src/api.js`: Public API cho các website WordPress gọi (health, sync_ips, register_site, ...).
- `src/oauth.js`: Xử lý OAuth callback từ Google.
- `src/admin.js`: Giao diện Admin Dashboard (Inline HTML) & Admin API.
- `src/cron.js`: Lên lịch ping heartbeat (wp-cron) cho các site.
- `src/auth.js`: Middleware xác thực API Key và Admin Token.
- `src/utils.js`: Các hàm helper.

## 📊 Giới hạn Free Tier
Cloudflare Workers Free Tier cung cấp:
- 100,000 requests/ngày
- 10ms CPU time/request
- Cloudflare KV Free: 100k Reads, 1,000 Writes / ngày.
Với quy mô hiện tại (hỗ trợ vài chục sites), hệ thống này dùng chưa tới **5%** giới hạn gói Free.
