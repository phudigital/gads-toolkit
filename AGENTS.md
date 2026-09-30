# GAds Toolkit — hướng dẫn cho AI coding

Phạm vi: toàn bộ repository WordPress plugin và `cloudflare-worker/`.
Tài liệu được đối chiếu với mã nguồn 4.2.8 ngày 2026-09-28. Khi code thay đổi,
kiểm tra lại các điểm tham chiếu; không dùng tài liệu này làm bằng chứng production đang chạy đúng.
Yêu cầu trực tiếp của người dùng quyết định phạm vi công việc. Không tự mở rộng một bản sửa thành refactor toàn hệ thống.

## 1. Đọc trước khi sửa

1. Chạy `git status --short`, đọc diff hiện có; giữ nguyên thay đổi không thuộc nhiệm vụ.
2. Xác định nơi xảy ra lỗi: checkout local, website WordPress cụ thể, Worker hay gói ZIP đã cài.
   Ghi nhận URL, phiên bản plugin, phiên bản Worker và thao tác tái hiện nếu có quyền truy cập.
3. Lần theo **UI → AJAX/hook → hàm nghiệp vụ → transport → Worker → Google Ads → phản hồi → UI**.
   Không kết luận chỉ từ tên nút, comment, changelog hoặc một helper đã tồn tại.
4. Xác định bảng/options/transients bị đọc hoặc ghi, cron/caller dùng chung và tác động bên ngoài.
5. Sửa nhỏ nhất đủ giải quyết nguyên nhân. Khi thay đổi contract, sửa cả phía gọi, phía nhận và kiểm thử liên quan.

Không chạy cron, Full Sync, Smart Rotation, Smart Auto-Whitelist hoặc test thông báo chỉ để tìm hiểu code.
Các thao tác này có thể xóa dữ liệu, thay đổi tài khoản quảng cáo hoặc gửi email/Telegram thật.

## 2. Bản đồ chức năng và dữ liệu

Đường dẫn dưới đây tính từ gốc repository; tìm theo tên hàm thay vì dựa vào số dòng.

| Chức năng / caller | File và điểm vào | Dữ liệu / tác động cần theo dõi |
| --- | --- | --- |
| Bootstrap, activation, deactivation | `gads-toolkit.php` | Thứ tự load module, tạo bảng, lịch thông báo; updater và whitelist được load ngoài admin |
| Truy cập frontend, chặn tức thì, scan định kỳ | `includes/core-engine.php`: `tkgadm_track_visit`, `tkgadm_check_ip_instant`, `tkgadm_run_auto_block_scan`, `tkgadm_block_ip_internal` | Bảng stats/blocked, quy tắc chặn, whitelist; có các caller đồng bộ và thông báo |
| Thời gian ở trang | `assets/time-tracker.js` → `includes/module-dashboard.php`: `tkgadm_ajax_update_time_on_page` | Endpoint công khai cập nhật stats; không phải bằng chứng danh tính khách |
| Thống kê, chặn/bỏ chặn local | `includes/module-dashboard.php`: `tkgadm_ajax_toggle_block_ip` | `gads_toolkit_blocked`; nhánh bỏ chặn local không tự gỡ criterion trên Google |
| Cấu hình, OAuth hiện hành | `includes/module-settings.php`: `tkgadm_settings_oauth_actions`, `tkgadm_render_settings_page` | Options `tkgadm_*`, refresh token; pending OAuth transient theo user |
| Kết nối và upload | `includes/module-google-ads.php`: `tkgadm_get_gads_ids`, `tkgadm_get_gads_connection_mode`, `tkgadm_do_sync_process`, `tkgadm_sync_ip_to_google_ads` | Direct hoặc Central Service; upload tạo account-level IP exclusions |
| Giao diện "IP trên Google Ads" | `includes/module-data.php`: `tkgadm_render_maintenance_page` | Điều kiện hiển thị, nonce, JS gọi các AJAX quản lý IP |
| Tải/xóa IP remote | `includes/module-gads-manager.php`: `tkgadm_ajax_gads_list_ips`, `tkgadm_ajax_gads_delete_ips` | Các wrapper `tkgadm_list_connected_google_ads_ips`, `tkgadm_remove_connected_google_ads_ips`; enrich từ DB local |
| Đồng bộ lại toàn bộ | `includes/module-google-ads.php`: `tkgadm_do_full_sync_google_ads` | Đọc/kiểm tra local → tải remote → xóa remote → upload lại; có thể ảnh hưởng IP thêm ngoài plugin |
| Whitelist, rotation, tự nhận diện IP tin cậy | `includes/module-whitelist.php` | Bảng whitelist, local blocked, list/remove Google Ads; được gọi từ admin và cron |
| Cảnh báo, báo cáo, test gửi | `includes/module-notifications.php` | Email, Telegram, lịch và trạng thái gửi; test gửi cũng có tác động thật |
| Worker routing | `cloudflare-worker/src/index.js` | `/api`, `/oauth`, `/admin`, `/updates/*`, static assets và scheduled handler |
| API plugin → Worker | `cloudflare-worker/src/api.js`: `handleApiRequest` | License/rate-limit trước action; `get_credentials`, `exchange_code`, `sync_ips`, `list_ips`, `remove_ips`, `register_site` |
| IP manager phía Worker | `cloudflare-worker/src/ip-manager.js`: `handleIpManager` | Refresh OAuth, searchStream, kiểm tra IP thuộc customer trước mutate |
| Auth, OAuth redirect, admin | `cloudflare-worker/src/auth.js`, `oauth.js`, `admin.js` | Giấy phép, origin, admin token/hash, Turnstile; mỗi lớp có trách nhiệm riêng |
| Heartbeat | `cloudflare-worker/src/cron.js`: `handleCron` | Đọc `client:*`, gọi `wp-cron.php` của site; đây không phải health check thuần đọc |
| Cập nhật plugin | `includes/module-updater.php` ↔ `cloudflare-worker/src/updates.js` | Manifest public, checksum, private R2, cache và WordPress Upgrader |
| Build/release | `cloudflare-worker/scripts/` | Đồng bộ version, build landing/ZIP, xuất bản R2/KV và deploy Worker |

### Nơi lưu trạng thái

- SQL dùng `$wpdb->prefix` với `gads_toolkit_stats`, `gads_toolkit_blocked`, `gads_toolkit_whitelist`.
  Không hardcode `wp_`. Đây là ba loại dữ liệu khác nhau, không phải bản sao chắc chắn của Google Ads.
- Cấu hình, quy tắc và token client nằm trong WordPress options `tkgadm_*`.
  Dùng helper hiện có để đọc API key; giữ tương thích key cũ/mới và constant override.
- Transients phục vụ OAuth, cache và trạng thái tạm; không coi transient là nguồn dữ liệu bền vững.
- `GADS_KV`: `license:*`, `client:*`, `config:*`, `logs:recent`, `rate:*`, metadata release.
- Worker Secrets: OAuth client secret, Developer Token, admin/Turnstile secrets theo `wrangler.toml`.
  `get_credentials` chỉ cung cấp cấu hình client cần cho OAuth; không mở endpoint trả shared secrets.
- `GADS_RELEASES`: binding R2 chứa ZIP; bucket private, download public đi qua Worker.

## 3. Kiến trúc Worker tại gads.pdl.vn

### 3.1 Tổng quan routes

Worker triển khai tại `https://gads.pdl.vn` (zone `pdl.vn`, wrangler binding `gads-central-service`).
Thứ tự ưu tiên route trong `wrangler.toml`:

```
run_worker_first = ["/api*", "/oauth*", "/admin*", "/updates/*"]
```

Static assets (`./public/`) phục vụ landing page; Worker chặn các route động trước khi asset lookup.

| Route | File xử lý | Auth cần |
| --- | --- | --- |
| `GET /api?action=health` | `api.js` | Không (public) |
| `GET /api?action=validate_license` | `api.js` | License Key active/còn hạn, không dùng master key |
| `GET /api?action=get_credentials` | `api.js` | `X-API-Key` header hoặc `?api_key=` |
| `POST /api?action=exchange_code` | `api.js` | API key + rate limit |
| `POST /api?action=sync_ips` | `api.js` | API key + rate limit |
| `POST /api?action=list_ips` | `api.js` → `ip-manager.js` | API key + rate limit |
| `POST /api?action=remove_ips` | `api.js` → `ip-manager.js` | API key + rate limit |
| `POST /api?action=register_site` | `api.js` | API key + rate limit |
| `GET /oauth` | `oauth.js` | Kiểm tra origin qua license domain |
| `GET /admin` | `admin.js` | Turnstile + ADMIN_TOKEN |
| `GET /admin/api/*` | `admin.js` | Bearer token (Admin) |
| `GET /updates/gads-toolkit/latest.json` | `updates.js` | Không (public) |
| `GET /updates/gads-toolkit/download/*.zip` | `updates.js` | Không (public, URL có SHA-256) |
| `GET /` | `index.js` | Không (JSON health) |

### 3.2 Admin Dashboard tại `/admin`

Dashboard HTML được render trực tiếp từ `admin.js:getDashboardHTML()` — **không phải file HTML tĩnh**.
Token admin được lưu vào `localStorage` phía client (`adminToken`), gửi qua `Authorization: Bearer <token>` mỗi request.

Luồng đăng nhập:
1. `GET /admin` → render HTML (có Turnstile widget nếu `TURNSTILE_SECRET_KEY` được set).
2. `POST /admin/api/login` với `{token, turnstile_token}`.
   - Server xác minh Turnstile với `challenges.cloudflare.com` (kiểm tra `action === 'admin_login'`, `hostname` phải khớp).
   - Nếu `TURNSTILE_SECRET_KEY` không tồn tại → bỏ qua Turnstile (môi trường dev).
   - Xác minh `ADMIN_TOKEN`: ưu tiên hash trong `config:admin_token_hash` (KV), fallback về secret `ADMIN_TOKEN`.
3. Thành công → `{success: true}` → client lưu token vào `localStorage`.

Các trang admin (sections trong một SPA):
- **Tổng quan** (`/admin/api/stats` + `/admin/api/logs`): số sites, license active, API version, requests hôm nay, 20 log gần nhất.
- **License Keys** (`/admin/api/licenses`): CRUD license — key, domain, label, expires_at, active toggle.
- **Sites kết nối** (`/admin/api/clients`): danh sách `client:*` trong KV, xóa client.
- **Cài đặt** (`/admin/api/config`): api_version, rate_limit, oauth_redirect, legacy_api_key, allowed_origins.
- **Activity Log** (`/admin/api/logs`): rolling buffer 200 entries trong `logs:recent`.
- **Đổi mật khẩu** (`PUT /admin/api/security/admin-token`): xác minh token hiện tại, lưu hash mới vào KV.

#### Admin API endpoints đầy đủ

| Method | Path | Mô tả |
| --- | --- | --- |
| POST | `/admin/api/login` | Đăng nhập (Turnstile + ADMIN_TOKEN) |
| GET | `/admin/api/stats` | Thống kê tổng quan |
| GET | `/admin/api/licenses` | Danh sách licenses |
| POST | `/admin/api/licenses` | Tạo license mới |
| PUT | `/admin/api/licenses/:key` | Cập nhật license |
| DELETE | `/admin/api/licenses/:key` | Xóa license |
| GET | `/admin/api/clients` | Danh sách clients đã đăng ký |
| DELETE | `/admin/api/clients` | Xóa client (body: `{url}`) |
| GET | `/admin/api/config` | Đọc cấu hình KV |
| PUT | `/admin/api/config` | Ghi cấu hình KV |
| GET | `/admin/api/logs` | Activity log |
| PUT | `/admin/api/security/admin-token` | Đổi mật khẩu Admin |

### 3.3 Xác thực API key (plugin client)

`auth.js:verifyApiKey()`:
1. Đọc key từ `X-API-Key` header, fallback `?api_key=` query param.
2. Kiểm tra `config:legacy_api_key` (KV) — master key không cần domain ràng buộc.
3. Kiểm tra `license:{key}` (KV) — phải `active: true` và chưa hết `expires_at`.
4. Nếu không hợp lệ: trả 401/403 với thông báo mua license tại `https://gads.pdl.vn`.

**Domain trong mã nguồn mới**: License Keys cần domain công khai và header `X-GAds-Site`/`X-GAds-Site-Token`. `license-domain.js` đối chiếu hostname và xác minh HMAC challenge tại endpoint REST WordPress qua HTTPS; chỉ alias `www` được phép, không tự mở rộng sang subdomain. Master key là ngoại lệ tương thích transport, không mở khóa plugin trả phí. Origin header một mình không chứng minh domain. Cần kiểm chứng bản triển khai trước khi khẳng định production đã áp dụng.

Rate limit chạy trong `verifyApiKey()` sau khi xác minh key và trước DNS/callback ownership. `checkRateLimit()` dùng `rate:{ip}:{hour}` trong KV, TTL 1 giờ. Giới hạn đọc từ `config:rate_limit` (default 100).

### 3.4 Token Admin và rotation

- **Secret ban đầu**: `ADMIN_TOKEN` (Cloudflare Worker Secret).
- **Sau rotation**: hash SHA-256 lưu vào `config:admin_token_hash` trong KV.
- Hàm xác minh `verifyAdminTokenValue()` ưu tiên hash trong KV; nếu chưa có dùng so sánh trực tiếp với secret.
- Dùng `timingSafeEqual` (Web Crypto) để tránh timing attack.
- Rotation xác minh token hiện tại trước khi ghi hash mới; token mới phải dài 12–256 ký tự và khác token cũ.

### 3.5 OAuth redirect flow

`oauth.js:handleOAuthRedirect()`:
1. Parse `code`, `state` (base64-encoded JSON với `return_url`), `error` từ query Google gửi về.
2. Decode state → lấy `return_url` → validate là URL hợp lệ.
3. Kiểm tra origin của `return_url` được phép:
   - So khớp hostname với các domain của license active trong KV (`license:*`).
   - Hoặc có trong `config:allowed_origins` (KV, mảng JSON).
4. Nếu origin không được phép → trả trang lỗi HTML (không redirect).
5. Nếu `error` từ Google → redirect về `return_url?oauth_error=...&oauth_error_description=...`.
6. Nếu `code` → redirect về `return_url?code=...&oauth_success=1`.

**Giới hạn**: `oauth.js` quét license một trang (`list()` không có cursor pagination) — không dùng mẫu này cho code mới yêu cầu scan toàn bộ KV. Fallback `origin.includes(domain)` khi URL parse lỗi — không an toàn cho production domain isolation.

### 3.6 IP Manager (list_ips / remove_ips)

`ip-manager.js:handleIpManager()` được gọi **sau khi** `api.js` đã xác thực license + rate limit:

1. Validate `customer_id` (10 chữ số), `manager_id` (tùy chọn), `refresh_token` (bắt buộc).
2. Kiểm tra Worker có đủ secrets (`GADS_CLIENT_ID`, `GADS_CLIENT_SECRET`, `GADS_DEVELOPER_TOKEN`).
3. Refresh access token qua `https://oauth2.googleapis.com/token`.
4. Gọi `googleAds:searchStream` với query `IP_BLOCK` của customer.
5. Với `list_ips`: trả `{success: true, data: {ips: [{resource_name, ip_address}]}}`.
6. Với `remove_ips`:
   - Validate `resource_names` (1–500 items, regex `^customers/{customerId}/customerNegativeCriteria/[0-9]+$`).
   - Đối chiếu với danh sách remote — nếu có resource_name không tồn tại → 409 (reload required).
   - Mutate với `partialFailure: false`.
   - Kiểm tra `result.results.length === resourceNames.length` trước khi báo thành công.

Timeout 10 giây cho mọi fetch. Lỗi timeout → 502 kèm hướng dẫn tải lại, không retry tự động.

### 3.7 Heartbeat Cron

`cron.js:handleCron()` chạy mỗi 10 phút (wrangler cron `*/10 * * * *`):
1. List tất cả `client:*` trong KV (một trang — xem giới hạn mục 5).
2. Với mỗi client có `status: 'active'`: fetch `{siteUrl}/wp-cron.php?doing_wp_cron={timestamp}` timeout 5 giây.
3. Chạy parallel với `Promise.allSettled()`.
4. Log summary ra console (không ghi vào `logs:recent`).

**Đây không phải health check thuần đọc** — kích hoạt WordPress cron thật của site khách.

### 3.8 Updates / Plugin Updater

`updates.js:handleUpdateRequest()` (public, không cần auth):
- `GET /updates/gads-toolkit/latest.json` → đọc `release:gads-toolkit:stable` từ KV.
- `GET /updates/gads-toolkit/releases/{version}.json` → đọc `release:gads-toolkit:version:{version}` từ KV.
- `GET /updates/gads-toolkit/download/{version}/{sha256}.zip` → stream từ R2 bucket `GADS_RELEASES`.
- Validate release metadata: version pattern, sha256 pattern, changelog max 30000 ký tự, URL package phải khớp format chuẩn.
- Download URL chứa SHA-256 làm path — immutable, cache 1 năm. HEAD request được hỗ trợ.

### 3.9 KV key schema đầy đủ

| Prefix/Key | Mô tả | Ghi bởi |
| --- | --- | --- |
| `license:{key}` | `{domain, label, expires_at, active, created_at}` | Admin dashboard |
| `client:{origin}` | `{registered_at, ip, status}` | `register_site` action |
| `config:api_version` | Google Ads API version (default `v25`) | Admin config |
| `config:rate_limit` | Rate limit per IP per hour (default `100`) | Admin config |
| `config:oauth_redirect` | OAuth redirect URI cho Central Service | Admin config |
| `config:legacy_api_key` | Master API key bypass license check | Admin config |
| `config:allowed_origins` | JSON array thêm origins cho OAuth | Admin config |
| `config:admin_token_hash` | SHA-256 hash của ADMIN_TOKEN sau rotation | Admin security |
| `logs:recent` | JSON array tối đa 200 entries rolling | `logActivity()` trong Worker |
| `license-proof:{sha256}` | Hạn ownership đã xác minh, TTL 300 giây; hash gồm key/domain/site/mã cài đặt | `license-domain.js` |
| `rate:{ip}:{hour}` | Rate counter, TTL 1 giờ | `checkRateLimit()` |
| `release:gads-toolkit:stable` | Metadata release stable | Build script |
| `release:gads-toolkit:version:{v}` | Metadata release theo version | Build script |
| `gads-toolkit/{v}/{sha}.zip` | File ZIP trong R2 | Build script |

### 3.10 Worker Secrets (không ghi vào file)

Đặt bằng `wrangler secret put`:
- `GADS_CLIENT_ID` — Google OAuth Client ID
- `GADS_CLIENT_SECRET` — Google OAuth Client Secret
- `GADS_DEVELOPER_TOKEN` — Google Ads Developer Token (chỉ dùng phía Worker)
- `ADMIN_TOKEN` — mật khẩu đăng nhập Admin Dashboard ban đầu
- `TURNSTILE_SECRET_KEY` — Cloudflare Turnstile secret

Env var non-secret trong `wrangler.toml`:
- `ENVIRONMENT = "production"`
- `TURNSTILE_SITE_KEY = "0x4AAAAAAANH17m2qkqk6zVn"`

## 4. Contract kết nối — bắt buộc giữ đồng nhất

Lỗi từng xảy ra: upload qua Central Service thành công, nhưng UI quản lý IP vẫn yêu cầu
Developer Token local; helper PHP gọi `list_ips`/`remove_ips` đã có trong khi Worker chưa xử lý action.
Sửa câu cảnh báo hoặc hiện nút không giải quyết được đường gọi bị thiếu.

- Dùng `tkgadm_get_gads_connection_mode()` cho điều kiện khả dụng và lựa chọn transport của IP manager/upload.
  Không tự chép thêm điều kiện `$has_direct` vào từng trang.
- Direct hiện yêu cầu Customer ID hợp lệ, refresh token, Developer Token và OAuth client ID/secret local.
  Central yêu cầu Customer ID/refresh token và cấu hình service/API key; shared secrets ở Worker.
- `tkgadm_is_using_central_service()` chỉ kiểm tra có cấu hình service/key, không xác minh license hoặc token còn dùng được.
  `tkgadm_get_gads_connection_mode()` cũng chỉ xác định đủ cấu hình; không đồng nghĩa request Google sẽ thành công.
- Customer ID là tài khoản quảng cáo bị tác động. Manager ID là MCC cho header `login-customer-id`.
  Chuẩn hóa ID thành 10 chữ số; chỉ gửi header MCC khi có Manager ID hợp lệ. Không tự lấy Customer ID điền vào MCC.
- Không lấy/copy Developer Token của Worker về WordPress để né lỗi lựa chọn transport.
- Khi thêm action mới vào Worker, phải có: route trong `index.js` (hoặc `api.js`), kiểm tra auth, schema request/response, PHP caller và UI xử lý lỗi.
  `list_ips` trả `data.ips` với `resource_name`/`ip_address`; lỗi/malformed response không được biến thành danh sách rỗng.
- Code rotation/auto-whitelist hiện còn chọn Central trực tiếp bằng `tkgadm_is_using_central_service()`.
  Nếu sửa transport, rà các caller này; chưa được khẳng định toàn bộ plugin đã dùng chung một bộ chọn.
- Lỗi upstream phải tới UI đúng nghĩa: thiếu cấu hình, license, OAuth hết hạn, sai customer/MCC, quota, permission, timeout.
  Có refresh token trong DB không chứng minh "đã kết nối thành công" với Google ở thời điểm hiện tại.
- Khi thêm admin API endpoint mới, cập nhật bảng route ở mục 3.2. Giữ gate `verifyAdminToken` trước mọi route `/admin/api/*` trừ `/admin/api/login`.

## 5. Dữ liệu IP và tác động trên Google Ads

- Tách rõ: chặn/bỏ chặn local, upload thêm exclusion, xóa exclusion, và Full Sync.
  Local blacklist không tự trở thành firewall HTTP và không chứng minh IP đã bị chặn ở Google Ads.
- Giữ whitelist ở mọi đường thêm IP: thủ công, tức thì, cron, upload và Full Sync.
  Khi sửa phải lần tới caller thực tế, không chỉ kiểm tra một hàm thêm DB.
- Realtime và scan hiện dùng `COUNT(DISTINCT gclid)` cho số click liên quan; lượt truy cập/row count là chỉ số khác.
  `gbraid` có thể được lưu vào trường `gclid`; đừng đổi ý nghĩa báo cáo bằng một thay đổi query tiện tay.
- Phân biệt IPv4/IPv6 cụ thể, wildcard `a.b.c.*`, CIDR `/24`, host CIDR `/32` hoặc `/128`.
  Google có thể trả `a.b.c.d/32` trong khi local lưu `a.b.c.d`; không đánh dấu "không có trong DB" chỉ vì khác chuỗi.
  Không quy mọi IP trong cùng subnet thành cùng một bản ghi. Việc normalize phải nhất quán, không normalize hai lần làm mất wildcard.
  Worker dùng `normalizeIpForGoogleAds()` trong `utils.js`: wildcard `a.b.c.*` → CIDR `a.b.c.0/24` trước khi gửi lên Google.
- Xóa theo `resource_name` thuộc đúng customer và đúng loại `IP_BLOCK`; không nhận criterion tùy ý từ trình duyệt.
  Worker validate server-side regex `^customers/{customerId}/customerNegativeCriteria/[0-9]+$` trước khi xóa.
  Đối chiếu danh sách remote trước khi xóa (Worker làm điều này trong `ip-manager.js`; 409 nếu có resource không tìm thấy). Giữ `partialFailure: false` cho thao tác xóa đồng loạt hiện có.
- `sync_ips` dùng `partialFailure: true` (partial error không hủy batch, được log); `remove_ips` dùng `partialFailure: false` (all-or-nothing). Không hoán đổi hai hành vi này.
- HTTP 200 chưa chắc thành công: kiểm tra `partialFailureError`, số kết quả và schema.
  Báo số IP Google xác nhận (`result.results.length`), không lấy số IP gửi đi làm bằng chứng tất cả đã thành công.
- Full Sync là thao tác phá hủy rồi tạo lại, không có transaction chung giữa SQL và Google Ads.
  Đọc DB, kiểm tra lỗi đọc, whitelist, tính hợp lệ và giới hạn trước khi xóa remote.
  Không coi lỗi đọc DB là local rỗng. Giữ xác nhận trên UI và giải thích rõ IP thêm ngoài plugin cũng có thể bị xóa.
- Nếu xóa timeout hoặc thiếu xác nhận: dừng, tải lại remote để đối chiếu; không retry mù hoặc tiếp tục upload.
  Nếu xóa thành công nhưng upload lỗi: báo trạng thái từng bước, không trả "đồng bộ thành công".
- Không tự gọi Full Sync từ màn hình chỉ đọc, health check hoặc thao tác reconnect.
  Khi sửa auto-cleanup/rotation, kiểm tra thứ tự xóa local/remote, chế độ auto-sync và tránh vòng lặp recovery.

## 6. Ranh giới bảo mật

### WordPress

- Mutation admin phải có capability thích hợp (`manage_options` cho quản lý plugin) **và** nonce đúng action.
  Nonce không thay thế authorization. Endpoint đọc dữ liệu nhạy cảm cũng cần phân quyền.
- Dữ liệu request: kiểm tra kiểu trước khi map/cast, `wp_unslash`, validate theo domain rồi sanitize.
  SQL values dùng `$wpdb->prepare()`; tên bảng/cột/order lấy từ allowlist do code kiểm soát.
- Output escape đúng ngữ cảnh: HTML, attribute, URL, JavaScript/JSON. Không ghép raw API error hoặc DB text vào `.html()`.
- Không dùng endpoint `wp_ajax_nopriv_*` cho thao tác quản trị.
  Tracking công khai cần giới hạn payload/rate và ràng buộc session/bản ghi phù hợp; nonce công khai không chứng minh danh tính IP.
- Giữ khả năng chạy với minimum PHP/WordPress trong header plugin; không dùng cú pháp PHP mới hơn nếu chưa có quyết định nâng minimum.
- Schema/index migration phải idempotent; không reset dữ liệu/token/cấu hình khi cập nhật hay deactivate.
  Khi sửa thời gian, giữ nhất quán timezone WordPress và UTC của Worker; tránh trộn `date()` với dữ liệu local.

### Worker và OAuth

- Giữ license/rate-limit gate trước action bảo vệ trong `handleApiRequest`; `handleIpManager` không tự làm auth.
  Health public (`action=health` không có API key) và updater public là ngoại lệ có chủ đích, không phải mẫu cho API quản trị.
- License key không phải quyền admin Worker. Giữ Bearer verification cho `/admin/api/*` sau login.
  CORS không phải cơ chế xác thực hoặc cách ràng buộc một API key vào domain.
- OAuth hiện hành xử lý trước output ở `admin_init`: pending state ngẫu nhiên theo user, so sánh `hash_equals`, dùng một lần,
  yêu cầu refresh token, redirect dọn code khỏi URL. Giữ các điều kiện này và kiểm tra callback lỗi/replay/expired.
- Base64 state chỉ là encoding, không phải chữ ký. Worker redirect cần kiểm tra origin qua license domain hoặc `config:allowed_origins`;
  không tin `return_url` từ request nếu origin chưa được xác minh.
  Rà cả OAuth legacy trong `module-google-ads.php` nếu đổi callback hoặc state contract.
- Khi Turnstile được cấu hình (`TURNSTILE_SECRET_KEY` tồn tại), xác minh server-side `success`, `hostname`, `action === 'admin_login'` và lỗi/expiry;
  không bỏ gate vì checkbox hoặc widget đã hiện. Nếu `TURNSTILE_SECRET_KEY` không có → skip (môi trường dev).
  Không tự xóa secret để vượt lỗi đăng nhập; kiểm tra cấu hình Turnstile widget site key trước.
- Admin Token rotation phải xác minh token hiện tại, lưu hash SHA-256 vào KV và làm vô hiệu token cũ theo thiết kế.
  Không in secrets, refresh/access token, authorization code, cookie, full URL chứa key hoặc payload nhạy cảm vào logs/test fixtures.
  `logActivity()` trong Worker chỉ log action, client identifier (customer ID hoặc IP), result và detail text.
- Với URL người dùng đăng ký để heartbeat/redirect: kiểm tra scheme, host và đích thực tế;
  không mở rộng server-side fetch tới địa chỉ nội bộ/metadata hoặc redirect tùy ý.
- KV rate counters/log updates không nguyên tử; không dùng chúng như lock bảo đảm một lần thực thi.
  Khi quét KV, xử lý pagination với cursor; `cron.js` và `oauth.js` hiện chỉ đọc một trang — không lấy làm chuẩn.

### Điểm cần thận trọng trong bản code được rà soát

Đây là các giới hạn cần kiểm tra khi chạm tới vùng code tương ứng, **không phải các lỗi đã được sửa bởi tài liệu này**:

- `tkgadm_get_real_user_ip()` ưu tiên proxy headers nhưng chưa tự xác minh proxy gửi đến có đáng tin.
- `tkgadm_ajax_update_time_on_page()` nhận IP/URL/time từ client công khai; không coi các giá trị này là bằng chứng chống giả mạo.
- `oauth.js` còn fallback `origin.includes(...)` khi parse domain lỗi và quét license một trang;
  `cron.js` cũng list client một trang. Không lấy các mẫu này làm chuẩn cho code mới.
- `verifyApiKey()` trong mã nguồn mới kiểm tra key/active/expiry/domain và ownership HMAC; master key vẫn là ngoại lệ transport.
  Plugin cũ thiếu header xác minh sẽ bị từ chối; cần rollout đồng bộ và kiểm chứng production. Origin header một mình không chứng minh domain.
- Turnstile hiện bỏ qua khi không có `TURNSTILE_SECRET_KEY`; phải xác minh cấu hình trước khi nói production được bảo vệ bởi Turnstile.
- Enrichment IP manager hiện so khớp raw IP và có nhánh `/24`; chưa xử lý đầy đủ host CIDR `/32`/`/128`.
  Cần kiểm chứng trước khi dùng "IP không có trong DB" cho thao tác xóa hàng loạt.
- Một số đường cleanup/rotation ghi local và remote riêng; chưa có giao dịch nguyên tử hay rollback chung.
- Admin token lưu plain text trong `localStorage` phía client — đây là thiết kế SPA hiện tại;
  không ghi token vào log hoặc URL; session không expire tự động (chỉ expire khi `/admin/api/*` trả 401).
- `logs:recent` là rolling buffer 200 entries, không phải audit log bất biến; đừng dùng để audit bảo mật.

Nếu phát hiện lỗi bảo mật trong lúc sửa tính năng, nêu bằng chứng, mức ảnh hưởng và phạm vi sửa cần thiết;
không âm thầm refactor toàn bộ hoặc tuyên bố đã audit bảo mật đầy đủ chỉ từ đọc code.

## 7. Kiểm chứng theo phạm vi thay đổi

Các lệnh sau chạy từ gốc repository, dùng Node 22 tương thích toolchain hiện tại và PHP phù hợp.
Chỉ chạy nhóm liên quan; kiểm thử tài liệu thuần túy không cần deploy hoặc gọi Google Ads.

```sh
# PHP: lint các file vừa sửa và test logic khi có thay đổi hành vi.
php -l includes/module-google-ads.php
php -l includes/module-gads-manager.php
php -l includes/module-data.php
vendor/bin/phpunit

# Worker: kiểm tra cú pháp/version và test API bị ảnh hưởng.
npm --prefix cloudflare-worker run check
npm --prefix cloudflare-worker run test:ip-manager
npm --prefix cloudflare-worker run test:updates

git diff --check
```

- Chưa có dependencies thì cài từ lockfile; không đổi version dependency chỉ để chạy test.
  Không dùng `npm run check` như bằng chứng đã test logic: script chủ yếu kiểm tra cú pháp/version.
- Ma trận kết nối: OAuth Central không có Developer Token local; Direct đầy đủ; Direct thiếu secret;
  disconnected; Customer/MCC sai; license invalid/inactive/expired; lỗi refresh và Google permission.
- Ma trận IP: danh sách rỗng hợp lệ vs response lỗi; nhiều stream batches; plain/wildcard/CIDR;
  sai customer/resource type; quota; partial failure; timeout sau mutation; local read lỗi; whitelist.
- Mock phải chặn outbound thật và kiểm tra đúng transport, headers, payload, đường lỗi và không retry mù.
  Viết regression test cho lỗi logic có rủi ro; không viết test chỉ sao chép implementation cho sửa copy/UI nhỏ.
- `tests/wordpress-updater.php` dành cho WordPress disposable có `GADS_UPDATER_TEST`; có thao tác nâng cấp/thay file.
  Không chạy trên website khách. WP-CLI dùng đúng PHP/MySQL socket; AJAX mock cần cả `$_POST` và `$_REQUEST` khi áp dụng.
- Sau sửa UI/API, kiểm chứng website đã cài bản mới bằng phiên đăng nhập có sẵn và thao tác **Tải danh sách**.
  Giữ lỗi backend hiển thị; ghi rõ hạn chế nếu chưa vào được website. Lint/health/mock không chứng minh màn hình hoạt động.
- Test gửi email/Telegram, thêm/xóa IP thật hoặc Full Sync chỉ khi nằm trong phạm vi người dùng đã cho phép.
  Không tạo side effect mới chỉ để có bằng chứng thành công; báo riêng phần chỉ được kiểm tra bằng mock.
- Sau sửa Admin Dashboard Worker: kiểm chứng tại `https://gads.pdl.vn/admin` với token hợp lệ;
  không dùng mock render HTML làm bằng chứng endpoint API hoạt động.

## 8. Build, triển khai và cập nhật

1. Đọc script hiện hành trước khi chạy. Version gốc nằm ở header và `GADS_TOOLKIT_VERSION` trong `gads-toolkit.php`;
   cập nhật changelog khi release. Tài liệu đơn thuần không cần tăng version.
2. `npm --prefix cloudflare-worker run sync:version` đồng bộ package/version/README và rebuild landing/docs.
   Đây là lệnh ghi file; xem diff sau khi chạy. `cloudflare-worker/public/` và `docs/index.html` là output build,
   nguồn build được chỉ ra trong `scripts/build-landing.mjs`; không chỉ sửa output rồi để build ghi đè.
3. `python3 cloudflare-worker/scripts/build-plugin.py` tạo ZIP theo allowlist.
   Kiểm tra archive chỉ chứa runtime cần thiết dưới `gads-toolkit/`, không gồm secrets, dependencies dev, dump, logs hoặc bản sao repo.
   `AGENTS.md` là tài liệu coding trong repo, không cần đưa vào ZIP runtime.
4. Nếu plugin mới cần endpoint mới, deploy Worker tương thích ngược **trước**, kiểm chứng API rồi mới cập nhật plugin khách.
   `npm --prefix cloudflare-worker run deploy` đồng bộ version, kiểm tra và deploy, không tự cập nhật plugin WordPress.
5. `npm --prefix cloudflare-worker run release:plugin` chỉ build/chuẩn bị release.
   Thêm `-- --publish` sẽ upload R2, xác minh hash, ghi metadata version/stable vào KV **và deploy Worker**.
   Script hiện ghi stable trước bước deploy cuối; vì vậy không dựa vào bước deploy cuối để bảo đảm backend sẵn sàng cho plugin mới.
6. Giữ package immutable theo version + SHA-256, không tái sử dụng version với nội dung khác, không downgrade stable.
   Giữ checksum verification và validation URL của updater; không tắt để "cho cập nhật chạy".
7. Phân biệt ba kết quả: Worker đã deploy, manifest stable đã publish, website cụ thể đã cài plugin mới.
   Git push, ZIP local, health version hoặc KV write riêng lẻ không chứng minh cả ba.
8. Readback đúng phạm vi: `/api?action=health`, `/updates/gads-toolkit/latest.json`, package hash và phiên bản/site UI.
   Tính đến KV propagation và cache WordPress; chờ/đọc lại có giới hạn, không xuất bản lại tùy tiện.
9. Giữ nguyên route `/api`, `/oauth`, `/admin`, `/updates/*`, bindings KV/R2 và cron trừ khi nhiệm vụ yêu cầu đổi.
   `/admin` được render từ `admin.js:getDashboardHTML()` — đây là HTML inline trong Worker, không phải file tĩnh.
   Khi sửa Admin Dashboard, sửa trong `admin.js`; không sửa file HTML trong `cloudflare-worker/public/` rồi mong Worker dùng.
10. Khi thêm/đổi Admin API endpoint: cập nhật bảng ở mục 3.2 và bảng KV ở mục 3.9 nếu có key mới.
    Khi thêm KV key mới, đảm bảo Admin config page có UI đọc/ghi tương ứng hoặc ghi rõ cách set thủ công.
11. Kết thúc bằng kết quả cụ thể: đã sửa gì, đã kiểm tra gì, đã triển khai tới đâu và phần chưa xác minh.
    Cập nhật `AGENTS.md` nếu thay đổi kiến trúc/contract làm hướng dẫn này sai; không ghi secrets hoặc dữ liệu khách vào tài liệu.

## Domain license trong bản đang phát triển

- `includes/module-license.php`: site URL lấy từ `home_url`, mã cài đặt lấy từ secret ngẫu nhiên trong option `tkgadm_license_site_secret`; không lấy Host/Origin từ request.
- Endpoint public chỉ đọc `GET /?rest_route=/gads-toolkit/v1/license-proof&challenge={64-hex}` trả `{site_url, proof}`; HMAC SHA-256, `Cache-Control: no-store`, không trả key/secret, không kiểm tra license hoặc gọi mạng để tránh recursion.
- Các PHP transport dùng `tkgadm_service_headers()` và `redirection: 0`. `validate_license` trả `data.valid`, `data.expires_at`, `data.site_url`; response phải khớp site trước khi mở khóa.
- Worker dùng DNS-over-HTTPS tại Cloudflare để loại địa chỉ không công khai trước callback. Chỉ fetch HTTPS hostname thuộc domain license, không gửi key/token đến callback, không theo redirect, response tối đa 16 KiB.
- Bản phát triển thay contract: domain trống, site HTTP/local, REST bị WAF/auth chặn hoặc plugin cũ thiếu header sẽ fail closed. Không tự sửa license khách hoặc triển khai chỉ một phía.
- Chạy `npm --prefix cloudflare-worker run test:license` và `php tests/license-gate.php` cho protocol mới; mocks không chứng minh ownership trên site đã cài thật.
