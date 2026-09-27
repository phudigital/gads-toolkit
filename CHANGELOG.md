# Changelog

All notable changes to **GAds Toolkit - Phần mềm chống click ảo Google Ads** will be documented in this file.

## [4.2.8] - 2026-09-28

### Sửa lỗi quản lý IP Google Ads
- Cho phép tải danh sách, xóa IP đã chọn và đồng bộ lại toàn bộ bằng kết nối Central Service/OAuth hiện có; không yêu cầu Developer Token tại website.
- Bổ sung API `list_ips` và `remove_ips` trên Worker, giữ kiểm tra giấy phép và chỉ cho xóa IP thuộc đúng tài khoản.
- Dùng chung điều kiện kết nối cho upload và quản lý IP; kiểm tra dữ liệu local trước khi đồng bộ lại toàn bộ và trả đúng lỗi đồng bộ một phần.

## [4.2.7] - 2026-09-28

### Tính năng mới & Cơ chế Tự phục hồi
- **Đồng bộ IP chặn toàn diện (Full Sync)**: Thêm chức năng làm sạch toàn bộ IP trên Google Ads và đồng bộ lại danh sách từ cơ sở dữ liệu local một cách an toàn và tự động.
- **Tự động xử lý khi đầy hạn mức Google Ads (Quota Recovery)**: Khi đồng bộ gặp lỗi đầy giới hạn 500 IP (`LIMIT_EXCEEDED` / `RESOURCE_EXHAUSTED`), hệ thống sẽ tự động kích hoạt Smart Rotation để giải phóng slot và tự động thử lại ngay lập tức.
- **Đồng bộ hai chiều danh sách đen**: Tự động dọn dẹp các IP được xoay vòng ra khỏi bảng chặn nội bộ (`gads_toolkit_blocked`); khi gỡ IP khỏi Whitelist, tự động kích hoạt đẩy lại lên Google Ads nếu IP đó thuộc danh sách vi phạm.

### Cải thiện & Tinh chỉnh
- **Tinh gọn Cài đặt Chặn tự động**: Tối ưu nút gạt "Chặn tự động", đồng bộ trạng thái lưu cấu hình ngưỡng Auto-Rotate và chế độ đồng bộ.
- **Nâng cấp phân trang Dashboard**: Cải thiện thanh phân trang động trượt theo trang hiện tại trên màn hình Thống kê IP Ads.
- **Nhận diện dải IP /24 thông minh**: Tối ưu khả năng ánh xạ và hiển thị chi tiết cho các dải IP CIDR được Google Ads trả về.

## [4.2.6] - 2026-09-28

### Tính năng mới & Mở rộng
- **Hỗ trợ Central Service cho Smart Rotation & Gỡ IP Google Ads**: Cho phép Smart Rotation và Smart Auto-Whitelist gỡ bỏ IP khỏi tài khoản Google Ads thông qua Central Service (OAuth), không bắt buộc phải dùng Direct API với Developer Token.
- **Chuẩn hóa & Xác thực Tài khoản Google Ads**: Tập trung hóa xử lý ID tài khoản (`tkgadm_get_gads_ids`) và tự động kiểm tra định dạng Customer ID / Manager ID (10 chữ số) trước khi lưu cấu hình, ngăn ngừa lỗi sai định dạng.

### Cải thiện & Tối ưu
- **Tối ưu giao diện Quản lý Dữ liệu**: Nâng cấp ô nhập số lượng IP cũ nhất cần xóa với thiết kế liền khối (input group) hiện đại và gọn gàng.
- **Tinh chỉnh giao diện Dashboard**: Khắc phục màu nút đóng modal trên màn hình thống kê.
- **Hệ thống Kiểm thử Tự động (Unit Test Suite)**: Bổ sung bộ kiểm thử tự động với PHPUnit & Brain Monkey cho toàn bộ các hàm xử lý logic và format quan trọng.

## [4.2.5] - 2026-09-28

### Tính năng mới
- **Whitelist IP & Smart Auto-Whitelist**: Thêm danh sách IP tin cậy (hỗ trợ IP cụ thể và dải IP wildcard `x.x.x.*`). IP trong danh sách này sẽ không bao giờ bị chặn và không đẩy lên Google Ads.
- **Tự động nhận diện Google Verification Bot**: Thuật toán Smart Auto-Whitelist tự động quét và nhận diện các IP đã bị chặn nhưng vẫn liên tục click Ads (bot xác minh của Google) để đưa vào Whitelist và gỡ bỏ khỏi tài khoản Google Ads.
- **Smart IP Rotation (Tự động xoay vòng 500 IP)**: Tự động gỡ các IP bị chặn cũ nhất khi danh sách chặn trên Google Ads đạt tới giới hạn 500 IP để nhường chỗ cho IP gian lận mới phát sinh.
- **Trình quản lý IP Google Ads trực tiếp**: Xem danh sách IP đang bị chặn từ Google Ads theo thời gian thực; cho phép xóa IP chọn lọc hoặc xóa IP cũ nhất ngay trên website WordPress (hỗ trợ Direct API).

### Cải thiện & Tối ưu
- **Hợp nhất giao diện Quản lý Dữ liệu**: Tích hợp Whitelist IP và Trình quản lý IP Google Ads vào tab Quản lý Dữ liệu, trực quan và dễ sử dụng.
- **Cải tiến giao diện Cấu hình**: Làm mới phần thiết lập thông báo, cảnh báo IP nghi ngờ và báo cáo tổng hợp.
- **Bộ nhận diện cập nhật WordPress**: Bổ sung bộ Icon và Banner chuyên nghiệp hiển thị trực tiếp trong trang quản lý và cửa sổ cập nhật plugin của WordPress.

## [4.2.4] - 2026-09-27

### Khắc phục & Cải thiện
- Cập nhật bộ nhận diện giao diện: bổ sung Icon và Banner chuyên nghiệp cho plugin trong danh sách quản trị WordPress.
- Cập nhật trang chủ chính thức của plugin về `https://gads.pdl.vn`.
- Tối ưu mô tả cập nhật phiên bản thân thiện, ngắn gọn và dễ hiểu cho người dùng.

## [4.2.3] - 2026-09-27

### Khắc phục & Cải thiện
- Khắc phục lỗi không chặn được IP trên Google Ads khi sử dụng tài khoản quảng cáo thông thường (không phải tài khoản đại lý MCC).
- Cập nhật giao diện: thêm bộ Icon và Banner mới chuyên nghiệp hơn cho plugin.

## [4.2.2] - 2026-09-27

### Cải thiện giao diện
- Sắp xếp lại phần hiển thị tài khoản Google Ads trong mục Cấu Hình: gọn gàng, nút bấm dễ thao tác hơn và hiển thị tốt trên điện thoại.

## [4.2.1] - 2026-09-27

### Sửa lỗi
- Khắc phục sự cố khi kết nối với Google Ads: hệ thống sẽ tự động đưa bạn về đúng trang cấu hình một cách mượt mà sau khi cấp quyền.
- Chỉ hiển thị tùy chọn "Hủy kết nối" khi bạn thực sự đang kết nối với Google Ads.

## [4.2.0] - 2026-09-27

### Tính năng mới
- Tính năng cập nhật tự động: Từ nay bạn có thể cập nhật plugin trực tiếp trong bảng quản trị WordPress một cách an toàn và nhanh chóng (giống như các plugin thông thường khác).

## [4.1.9] - 2026-09-05

### Fixed
- Sửa lỗi chính tả `gian hạn` thành `gia hạn` trong thông báo lỗi giấy phép.

## [4.1.8] - 2026-09-05

### Fixed
- Chuẩn hóa cả ba trường hợp thiếu/không hợp lệ, bị vô hiệu hóa và hết hạn giấy phép về cùng thông báo tiếng Việt khi upload IP.

## [4.1.7] - 2026-09-05

### Changed
- Việt hóa thông báo lỗi API Key khi upload IP và hướng dẫn gia hạn/mua giấy phép tại `https://gads.pdl.vn`.

## [4.1.6] - 2026-09-05

### Added
- Thêm chức năng **Upload toàn bộ IP bị chặn lên Google Ads** ngay trong trang **Cấu Hình & Tích hợp**, có trạng thái xử lý và thông báo lỗi/thành công.

### Fixed
- Đồng bộ thủ công và tự động không còn giới hạn ở 500 IP; danh sách được khử trùng lặp trước khi gửi.

## [4.1.5] - 2026-09-05

### Changed
- Bổ sung favicon riêng cho landing page và Admin Dashboard.

## [4.1.4] - 2026-09-05

### Changed
- Đồng bộ giao diện Admin Dashboard quản lý License Keys với prototype `landing-page/admin.html`: sidebar, header, thống kê, bảng key, tìm kiếm, copy key, toggle trạng thái và modal thêm/sửa.
- Giữ nguyên các API quản lý license hiện có và hiển thị ngày hết hạn trong dòng domain để không mất thông tin vận hành.

## [4.1.3] - 2026-09-04

### 🚀 UI/UX Redesign
- **Hiện đại hóa toàn diện giao diện quản trị**:
  - Tích hợp Tailwind CSS (cô lập phạm vi với preflight disabled bên trong `.wp-wrap`), bộ font Inter và hệ thống icon FontAwesome đồng bộ.
  - **Dashboard mới**: Thiết kế lại toàn bộ giao diện bảng điều khiển với thẻ tóm tắt số liệu (Tổng người Ads, Tổng người Organic, Lượt chặn, TB người/ngày, Tỷ lệ chặn), biểu đồ phân tích trực quan và bộ chọn thời gian linh hoạt (Hôm nay, 7, 15, 30, 60, 180 ngày hoặc Tùy chỉnh).
  - **Bảng IP Dashboard thông minh**: Bổ sung phân trang phía client (client-side pagination), tìm kiếm nhanh theo địa chỉ IP, và công tắc Toggle Switch (`tkgadm-switch`) chặn/bỏ chặn IP trực tiếp mượt mà.
  - **Quản lý Dữ liệu trực quan**: Bố cục 2 cột hiện đại hiển thị dung lượng bảng/dọn dẹp database và danh sách IP bị chặn có bộ lọc theo số phiên, khoảng ngày cùng nút sao chép IP nhanh.
  - **Hợp nhất menu Cấu hình & Tích hợp**: Gộp menu "Cấu hình Google Ads" và "Cấu hình Thông báo" thành trang duy nhất "Cấu hình & Tích hợp" (`tkgad-settings`) với giao diện Tab chuyên nghiệp (Google Ads API, Quy tắc Chặn tự động, Cảnh báo Telegram & Email).
  - **Tinh chỉnh giao diện form & controls**: Chuẩn hóa kích thước input, dropdown, toggle switch và card báo cáo định kỳ (`tkgadm-report-card`, `tkgadm-inline-time`) khớp chính xác với bản prototype, chống xung đột CSS từ theme/admin WordPress.
  - **Đồng bộ Toggle Switch**: Chuyển đổi toàn bộ checkbox truyền thống sang công tắc gạt `.tkgadm-switch` trên trang Thông báo và Cấu hình.

### ⚡ Performance & Database
- **Tự động tối ưu Index cơ sở dữ liệu**: Bổ sung hàm `tkgadm_ensure_stats_indexes()` tự động kiểm tra và thêm các chỉ mục quan trọng (`ip_address`, `visit_time`, `gclid`, `time_on_page`) cho bảng `wp_gads_toolkit_stats` khi nâng cấp, tăng tốc độ truy vấn đáng kể cho dữ liệu lớn.

### 🔔 Notifications & Integrations
- **Tối ưu gửi Telegram**: Bổ sung timeout 15 giây và kiểm tra chặt chẽ response HTTP 200 kèm cờ `ok` từ Telegram Bot API.
- **Kiểm tra kết nối trực tiếp (AJAX Connection Test)**: Bổ sung 2 endpoint AJAX (`tkgadm_test_telegram_connection`, `tkgadm_test_email_connection`) hỗ trợ kiểm tra kết nối tức thì tới bot Telegram và hòm thư nhận thông báo.

### 🐛 Fixed & Improvements
- **Khắc phục lệch ngày khi lọc**: Chuyển sang định dạng ngày địa phương (`formatLocalDate`) thay cho UTC ISO string trong bộ lọc thời gian.
- **Tương thích ngược API Key**: Bổ sung hàm `tkgadm_get_central_service_api_key()` tự động nhận diện cả key lưu trữ cũ và mới.
- **Giữ nguyên tham số URL**: Cải thiện cơ chế chuyển hướng bộ lọc trên Dashboard, giữ nguyên các tham số query hiện có.

## [4.0.2] - 2026-09-03

### Changed
- Hiển thị phiên bản GAds Toolkit trong sidebar Admin Dashboard; Worker, package và plugin được kiểm tra bắt buộc phải cùng phiên bản trước khi phát hành.
- Chuyển nút đổi mật khẩu Admin vào cụm thao tác cạnh nút đăng xuất.
- Mật khẩu Admin mới yêu cầu tối thiểu 12 ký tự.

### Fixed
- Activity Log hiển thị đúng trạng thái, client và chi tiết của các lượt đồng bộ IP thành công.

## [4.0.1] - 2026-09-03

### 🐛 Fixed
- Sửa payload đồng bộ IP lên Google Ads: loại bỏ trường `type` output-only gây lỗi `Request contains an invalid argument.`
- Chuẩn hóa Customer ID và Manager ID có dấu gạch ngang trước khi gọi API.
- Chuyển wildcard IPv4 dạng `x.x.x.*` sang CIDR tương đương trước khi gửi Google Ads.
- Bổ sung mã lỗi, vị trí field và request ID vào thông báo lỗi để chẩn đoán nhanh hơn.

## [4.0.0] - 2026-09-03

### 🚀 Major Architectural Changes
- **Migrated Central Service to Cloudflare Workers**: Viết lại hoàn toàn kiến trúc Central Service. Chuyển từ PHP/VPS sang Cloudflare Workers (JavaScript) để tối ưu hiệu năng và độ ổn định.
- **Zero Hardcode Architecture**: Mọi cấu hình (Google Ads API Version, License Keys, Rate limits...) hiện được lưu trữ trên Cloudflare KV. Cho phép thay đổi trực tiếp mà không cần sửa code hay re-deploy.
- **Worker Admin Dashboard**: Tích hợp sẵn giao diện quản trị Admin Dashboard (Inline HTML) ngay bên trong Cloudflare Worker để quản lý client và cấu hình hệ thống.
- **Fix Google Ads API 404 Error**: Cập nhật API endpoint từ `v20` (đã bị sunset) sang phiên bản mới nhất `v25` thông qua Cloudflare KV. Hệ thống sẽ tự động miễn nhiễm với các đợt sunset trong tương lai.
- **Clean up repository**: Di chuyển kiến trúc PHP cũ (`admin`, `central-service`) vào thư mục `archive/old-central-service-php` để chuẩn bị cho repo mới. Cập nhật lại toàn bộ tài liệu Technical Memory.

## [3.7.5] - 2026-04-16

### 📦 Package
- Cập nhật phiên bản và đóng gói plugin, loại bỏ các file rác không cần thiết để gửi cho khách hàng.

## [3.7.4] - 2026-04-16
### 🎨 UI/UX

- **Notification Templates Restyle**: Cập nhật lại toàn bộ template thông báo gửi qua Telegram và Email (Chặn tự động, Báo cáo ngày, IP nguy hiểm) sang định dạng Minimalist Compact Log (chuẩn hệ thống, tối ưu chiều ngang và lược bỏ các icon không cần thiết để hiển thị mượt trên thiết bị di động).

## [3.7.3] - 2026-04-16

### 🗑️ Removed

- **Custom SMTP**: Xóa hoàn toàn chức năng Custom SMTP (cấu hình qua hook `phpmailer_init`) để khắc phục lỗi xung đột toàn cục khiến Contact Form 7 và các plugin khác không gửi được mail. Plugin giờ sử dụng mail default của site.

### 📚 Documentation

- **Technical Architecture**: Tài liệu hóa kiến trúc Dual-Mode API, logic Central Service và các rule strict để nâng cấp các phiên bản sau mà không làm vỡ cấu trúc gốc.

## [3.7.0] - 2026-01-22

## [3.7.1] - 2026-02-01

### 🐛 Fixed

- **Daily Traffic Report**: Sửa lỗi báo cáo Email/Telegram ra toàn 0 do lệch timezone giữa WordPress và MySQL (lọc theo range “hôm qua” trong WP timezone).
- **Hourly Suspicious IP Check**: Đồng bộ mốc thời gian “1 giờ qua” theo WordPress timezone.
- **Dashboard Link**: Sửa đường dẫn dashboard trong báo cáo daily về đúng slug hiện tại.

### 💰 Pricing

- **New Pricing Structure**:
  - Trial: 10 days free (includes all Pro features)
  - Monthly: 100.000 VNĐ/month
  - Yearly: 800.000 VNĐ/year (save 33%)

### 🔐 Security & Licensing

- **API Key Management System**: Triển khai hệ thống quản lý License Key cho Central Service
  - Hỗ trợ nhiều API Key với thời hạn sử dụng riêng biệt
  - Kiểm tra tự động: Active status, Expiration date, Domain lock
  - Thông báo lỗi rõ ràng khi key hết hạn hoặc không hợp lệ
- **Central Service Security**: Cập nhật `central-service/config.php`
  - Cấu trúc `GADS_LICENSED_KEYS` thay thế single API key
  - Validation API Key trước khi cho phép sync IP
  - Tách file config khỏi Git (`.gitignore`) để bảo mật
  - Tạo `config-sample.php` làm template

### ✨ Added

- **API Key Validation**: Hàm `tkgadm_validate_api_key()` kiểm tra key với Central Service
- **Disconnect OAuth**: Nút "Hủy kết nối" để xóa OAuth token
- **Sync Status Notification**: Thông báo trực quan khi chặn IP
  - Màu xanh: "Đã chặn trên Google Ads" (sync thành công)
  - Màu đỏ: "Chỉ chặn ở website, chưa đồng bộ Google Ads" (sync thất bại)
  - Tự động tắt sau 2 giây

### 📝 Documentation

- **README.md**: Viết lại hoàn toàn với focus SEO và sales
  - Tối ưu keywords: "phần mềm chống click ảo", "plugin wordpress chống click ảo"
  - Thêm bảng giá API Key, testimonials, ROI calculator
  - Call-to-action rõ ràng với thông tin liên hệ
- **Screenshot**: Thêm ảnh Dashboard vào `assets/screenshot.png`

### 🔧 Changed

- **Error Messages**: Cập nhật thông báo lỗi hướng user đến `https://phu.vn` để mua/gia hạn key
- **OAuth Handler**: Kiểm tra Licensed Domains thay vì whitelist tĩnh
- **Plugin Name**: Đổi thành "Phần mềm chống click ảo Google Ads (GAds Toolkit)"

---

### 🐛 Fixed

- **Dashboard Time Filter**: Sửa lỗi tính toán ngày không chính xác (dùng `current_time` + `date` thay vì `strtotime`)
- **UI Flickering**: Khắc phục hiện tượng nhấp nháy dropdown khi load trang (xử lý logic filter tại server-side)

### ✨ Added

- **Tùy chọn "Hôm nay"**: Thêm filter xem báo cáo trong ngày hiện tại
- **Tối ưu view "Hôm nay"**: Chỉ hiển thị Summary Cards, ẩn biểu đồ (chart) để giao diện gọn gàng

---

## [3.6.11] - 2026-01-22

### 🔄 Refactored

- **Module Restructure**: Đổi tên `module-analytics.php` → `module-dashboard.php` để rõ ràng hơn
- **Cấu trúc 1:1**: Mỗi module tương ứng với 1 submenu (Dashboard, Data, Notifications, Google Ads)

### ✨ Added

- **Date Range Filter**: Thêm bộ lọc ngày cho "Quản Lý IP Bị Chặn"
  - Mặc định hiển thị từ ngày cũ nhất đến mới nhất
  - Hỗ trợ lọc theo khoảng thời gian tùy chỉnh
- **Copy IP List**: Nút copy danh sách IP (mỗi IP một dòng) tiện lợi

### 🔧 Changed

- **Blocking Reasons**: Việt hóa và chi tiết hóa lý do chặn
  - Format mới: `Chặn Tự Động: 7 click (Quy tắc: 5 click / 1 Giờ)`
  - Dễ đối chiếu số click thực tế với quy tắc đã cài đặt
- **Data Cleanup Options**: Cập nhật tùy chọn xóa dữ liệu (1, 2, 3 năm) thay vì 90/180 ngày
- **Manual Block Reason**: Ghi rõ "Chặn thủ công bởi Admin" khi admin chặn IP

### 📚 Documentation

- Thêm tooltip giải thích các loại lý do chặn (đã gỡ theo yêu cầu)

---

## [2.9.1] - 2026-01-20

### ✨ Added

- **Central OAuth Redirect Handler**: Giải pháp mới cho phép sử dụng một Redirect URI cố định cho tất cả các site
  - Thêm file `oauth-redirect.php` - standalone handler có thể deploy lên domain trung tâm
  - Thêm option "Custom OAuth Redirect URI" trong admin settings
  - Tự động phát hiện và hiển thị loại redirect URI đang sử dụng (Custom vs Direct)
  - State parameter với nonce verification để tăng cường bảo mật

### 🔧 Changed

- Cập nhật OAuth flow để hỗ trợ cả direct WordPress URL và central handler
- Cải thiện UI hiển thị redirect URI với color-coded notifications
- Thêm helper functions: `tkgadm_get_oauth_redirect_uri()`, `tkgadm_get_oauth_state()`, `tkgadm_verify_oauth_state()`

### 📚 Documentation

- Thêm `OAUTH-SETUP.md` - hướng dẫn chi tiết setup OAuth redirect URI
- Document 2 phương pháp: Direct WordPress URL vs Central OAuth Handler
- Thêm troubleshooting guide cho các lỗi OAuth phổ biến

### 🎯 Benefits

- **Cho developers/agencies**: Chỉ cần config Google Cloud Console 1 lần cho tất cả client sites
- **Cho plugin distribution**: Không cần yêu cầu user thêm redirect URI mới cho mỗi site
- **Tương thích ngược**: Plugin vẫn hoạt động bình thường với direct WordPress URL nếu không config custom handler

---

## [2.9.0] - 2026-01-19

### 🔄 Refactored

- Consolidate plugin modules into 5 core files:
  - `core-engine.php` - Database, tracking, auto-block, admin init
  - `module-analytics.php` - Dashboard & analytics UI/AJAX
  - `module-google-ads.php` - Google Ads API integration
  - `module-notifications.php` - Email/Telegram alerts
  - `module-data.php` - Data maintenance

### 🐛 Fixed

- Fix organic traffic logic to correctly identify IPs without gclid
- Improve IP validation for Google Ads sync (support IPv4, IPv6, wildcard)
- Fix auto-block rules evaluation (AND logic for multiple conditions)

### 📝 Documentation

- Add `AGENTS.md` - comprehensive guide for coding agents
- Add `ARCHITECTURE.md` - system architecture documentation
- Add `QUICKSTART.md` - quick start guide

---

## [2.8.2] - 2026-01-16

### 🐛 Fixed

- Fix WordPress.org plugin submission errors:
  - Remove compressed files and hidden files from package
  - Fix all database query security issues
  - Properly sanitize `$_SERVER` variables
  - Add proper `phpcs:ignore` comments where needed

### 🔒 Security

- Improve input sanitization across all modules
- Add nonce verification for all AJAX endpoints
- Enhance database query preparation

---

## [2.8.1] - 2026-01-15

### ✨ Added

- Deep test functionality for Email and Telegram notifications
- Detailed connection logs for troubleshooting

### 🔧 Changed

- Improve notification module error handling
- Better SMTP connection debugging

---

## [2.8.0] - 2026-01-14

### ✨ Added

- Auto-block feature with configurable rules
- Support multiple auto-block conditions (OR logic)
- Cron job for periodic auto-block scanning (every 15 minutes)
- Auto-sync to Google Ads when IP is auto-blocked

### 🎨 UI/UX

- Redesign admin interface with modern styling
- Add Chart.js v4.4.0 for traffic analytics
- Improve dashboard with real-time statistics

---

## [2.7.0] - 2026-01-13

### ✨ Added

- Google Ads API v19 integration
- Account-level IP exclusion sync
- Manager Account (MCC) support
- Hourly auto-sync cron job
- Manual sync button in admin

### 🔧 Changed

- Improve IP validation (support wildcard patterns)
- Better error messages for API failures
- Add partial failure handling for batch operations

---

## [2.6.0] - 2026-01-12

### ✨ Added

- Telegram notification support
- Email notification with SMTP configuration
- Hourly and daily alert schedules
- Customizable notification templates

---

## [2.5.0] - 2026-01-11

### ✨ Added

- Traffic analytics dashboard
- Ads vs Organic traffic comparison
- IP-level session details
- Time on page tracking

### 🎨 UI/UX

- Add interactive charts for traffic visualization
- Improve data table with sorting and filtering

---

## [2.0.0] - 2026-01-10

### ✨ Initial Release

- Track Google Ads traffic (gclid/gbraid)
- Manual IP blocking
- Basic traffic statistics
- WordPress admin integration

---

**Legend:**

- ✨ Added - New features
- 🔧 Changed - Changes in existing functionality
- 🐛 Fixed - Bug fixes
- 🔒 Security - Security improvements
- 📚 Documentation - Documentation changes
- 🎨 UI/UX - User interface improvements
- 🔄 Refactored - Code refactoring
