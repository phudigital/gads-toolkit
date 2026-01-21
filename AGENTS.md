# AGENTS.md

> **Tài liệu dành cho Coding Agent** - Hướng dẫn setup, build, test và quy ước code cho plugin WordPress "Fraud Prevention for Google Ads"

---

## 📋 Tổng quan plugin

**Fraud Prevention for Google Ads** (gads-toolkit) là plugin WordPress chuyên nghiệp giúp:

- **Theo dõi Real-time:** Ghi lại mọi lượt truy cập từ Google Ads (dựa trên `gclid`/`gbraid`) kèm thông tin thiết bị và hành vi
- **Chặn IP Tức thì (Real-time Auto-Block):** Tự động chặn IP ngay khi phát hiện vi phạm quy tắc (số click/thời gian) mà không cần chờ cron job
- **Smart Cross-IP Blocking:** Sử dụng Cookie Tagging để nhận diện và chặn kẻ tấn công ngay cả khi họ đổi từ IPv4 sang IPv6 hoặc ngược lại
- **Hỗ trợ Dual-Stack (IPv4 + IPv6):** Thu thập và chặn đầy đủ cả hai loại IP address
- **Tích hợp Google Ads API:** Tự động đồng bộ danh sách IP bị chặn vào account-level exclusions
- **Thông báo đa kênh:** Cảnh báo qua Email và Telegram kèm báo cáo traffic hàng ngày
- **Phân tích traffic:** Biểu đồ so sánh Ads vs Organic với Chart.js

### Công nghệ chính:

- **Backend**: PHP 7.4+ (WordPress Plugin API)
- **Frontend**: Vanilla JavaScript (jQuery), Chart.js v4.4.0
- **Database**: MySQL/MariaDB (WordPress `$wpdb`)
- **External APIs**: Google Ads API v19, Telegram Bot API
- **Build Tools**: **KHÔNG CÓ** - Plugin này không sử dụng build tool (Webpack, Vite, v.v.). Tất cả assets đều là vanilla JS/CSS.
- **Security**: Cookie-based device tracking, Nonce verification, Capability checks

---

## 🛠️ Thiết lập môi trường

### Yêu cầu hệ thống:

- **PHP**: >= 7.4 (khuyến nghị 8.0+) với extension `curl` enabled
- **WordPress**: >= 5.8 (khuyến nghị 6.0+)
- **MySQL/MariaDB**: >= 5.7 / MariaDB 10.2+
- **Server**: Apache hoặc Nginx với `mod_rewrite` enabled, khuyến nghị hỗ trợ IPv6
- **PHP Extensions**: `mysqli`, `json`, `curl` (cho Google Ads API)

### Dependencies:

Plugin này **KHÔNG** sử dụng Composer hoặc npm dependencies. Tất cả code là native PHP và vanilla JavaScript.

### Cài đặt trong môi trường local:

1. **Clone repository vào thư mục plugins:**

   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/phudigital/gads-toolkit.git
   ```

2. **Activate plugin:**
   - Vào WordPress Admin → Plugins
   - Tìm "Fraud Prevention for Google Ads"
   - Click "Activate"

3. **Database tables sẽ tự động tạo khi activate:**
   - `wp_gads_toolkit_stats` - Lưu traffic logs (hỗ trợ IPv4 và IPv6)
   - `wp_gads_toolkit_blocked` - Lưu danh sách IP bị chặn

4. **Cấu hình Server Cron (Quan trọng):**

   ```bash
   crontab -e
   # Thêm dòng sau (thay đường dẫn thực tế):
   */5 * * * * /usr/bin/php /path/to/wp-content/plugins/gads-toolkit/central-service/cron-trigger.php >/dev/null 2>&1
   ```

5. **Cấu hình plugin:**
   - Vào **GAds Toolkit** → **Cấu hình Thông báo** để setup Email/Telegram
   - Vào **Cấu hình Google Ads** để kết nối API
   - Kiểm tra IPv6 support tại section "Chẩn đoán IPv6"

### Môi trường local khuyến nghị:

- **Local by Flywheel** (khuyến nghị cho WordPress development)
- **XAMPP/MAMP** (traditional stack)
- **Docker** với `wordpress:latest` image
- **Devilbox** (nếu cần multi-project setup)

---

## 🚀 Lệnh dev & build

### Development:

Plugin này **KHÔNG CÓ** bước build assets. Tất cả file JS/CSS đã ở dạng production-ready:

- `assets/admin-script.js` - Vanilla JavaScript (không cần transpile)
- `assets/admin-style.css` - Vanilla CSS (không cần preprocessor)
- `assets/time-tracker.js` - Frontend tracking script
- `assets/chart.umd.min.js` - Chart.js library (đã minified)

### Workflow khi sửa code:

1. Sửa file PHP/JS/CSS trực tiếp
2. Refresh browser để test (WordPress sẽ tự động load phiên bản mới dựa trên `GADS_TOOLKIT_VERSION`)
3. Không cần chạy `npm run build` hay command tương tự

### Cập nhật version:

Khi release version mới, cập nhật version trong `gads-toolkit.php`:

```php
/**
 * Version:     3.2.0
 */
```

WordPress sẽ tự động bust cache cho assets dựa trên version này.

---

## 🧪 Testing

### Hiện trạng:

Plugin này **CHƯA CÓ** automated tests (PHPUnit, Pest, Jest, v.v.).

### Testing thủ công:

1. **Test Real-time Auto-Block:**
   - Cấu hình quy tắc chặn (ví dụ: 3 clicks trong 1 giờ)
   - Truy cập website với `?gclid=test_xxx` nhiều lần
   - Kiểm tra IP có bị chặn ngay lập tức không
   - Verify thông báo Telegram/Email được gửi

2. **Test Smart Cross-IP Blocking:**
   - Sau khi bị chặn, xóa Cookie `tkgadm_banned` trong DevTools
   - Đổi IP (hoặc giả lập bằng VPN)
   - Truy cập lại → IP mới sẽ bị chặn ngay

3. **Test IPv6 Support:**
   - Kiểm tra trang "Chẩn đoán IPv6" trong Cấu hình Thông báo
   - Verify server có IPv6 address
   - Test tracking với IPv6 client (nếu có)

4. **Test AJAX endpoints:**
   - Sử dụng browser DevTools → Network tab
   - Trigger actions trong admin (block IP, load chart, v.v.)
   - Kiểm tra response từ các AJAX handlers

5. **Test notifications:**
   - Vào **Cấu hình Thông báo** → Click "Deep Test" buttons
   - Module test sẽ hiển thị log chi tiết về SMTP/Telegram connection

6. **Test Google Ads sync:**
   - Vào **Cấu hình Google Ads** → Click "☁️ Upload IP lên Google Ads"
   - Kiểm tra response message và verify trong Google Ads account

### Khuyến nghị cho tương lai:

Nếu thêm automated tests, ưu tiên:

- **PHPUnit** cho WordPress plugin testing (theo chuẩn WordPress)
- **WP_UnitTestCase** để test với WordPress environment
- **WP_Ajax_UnitTestCase** để test AJAX handlers
- Setup test database riêng (không dùng production DB)

---

## 📐 Quy ước code

### Coding Standards:

Plugin tuân thủ **WordPress Coding Standards** với một số điểm chính:

#### 1. **PHP Coding Standards:**

- Sử dụng tabs (4 spaces) cho indentation
- Dấu ngoặc nhọn `{` trên cùng dòng với function/class declaration
- Tên function: `snake_case` với prefix `tkgadm_` (ví dụ: `tkgadm_track_visit()`)
- Tên class: `PascalCase` (hiện tại plugin chưa dùng OOP nhiều)
- Luôn escape output: `esc_html()`, `esc_attr()`, `esc_url()`
- Luôn sanitize input: `sanitize_text_field()`, `sanitize_email()`, v.v.

#### 2. **Database Queries:**

- **BẮT BUỘC** dùng `$wpdb->prepare()` cho dynamic queries
- Sử dụng `phpcs:ignore` comments khi cần thiết (đã có sẵn trong code)
- Ví dụ:
  ```php
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
  $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE ip = %s", $ip));
  ```

#### 3. **Security Best Practices:**

- **Nonce verification** cho tất cả AJAX requests:
  ```php
  check_ajax_referer('tkgadm_nonce', 'nonce');
  ```
- **Capability checks** cho admin actions:
  ```php
  if (!current_user_can('manage_options')) {
      wp_send_json_error('Không có quyền');
  }
  ```
- **Direct access prevention** ở đầu mỗi file:
  ```php
  if (!defined('ABSPATH')) exit;
  ```
- **Cookie Security:** Cookie `tkgadm_banned` được set với path `/` và expiry 30 ngày

#### 4. **Hooks & Filters:**

- Tên hook/action: `tkgadm_` prefix (ví dụ: `tkgadm_hourly_alert`, `tkgadm_daily_report`)
- Không đổi tên public hooks trừ khi có breaking change announcement
- Document hooks trong docblock:
  ```php
  /**
   * Fires when auto-block scan completes
   *
   * @param array $blocked_ips List of newly blocked IPs
   */
  do_action('tkgadm_auto_block_complete', $blocked_ips);
  ```

#### 5. **Namespace & File Organization:**

Plugin sử dụng **modular structure** (không dùng PHP namespace):

- `includes/core-engine.php` - Database, tracking, admin init, Real-time Auto-Block, Smart Cross-IP Blocking
- `includes/module-dashboard.php` - Dashboard & analytics UI/AJAX (đổi tên từ module-analytics.php v3.6.11)
- `includes/module-google-ads.php` - Google Ads API integration, sync UI
- `includes/module-notifications.php` - Email/Telegram alerts, IPv6 diagnostics
- `includes/module-data.php` - Data maintenance
- `central-service/cron-trigger.php` - Server-side cron trigger (CLI only)

**Quy tắc:** Mỗi module chứa cả UI rendering functions VÀ AJAX handlers liên quan.

#### 6. **Internationalization (i18n):**

- Text Domain: `gads-toolkit`
- Hiện tại plugin **CHƯA** có translation files (`.pot`/`.po`)
- Khi thêm i18n, wrap text strings:
  ```php
  __('Text to translate', 'gads-toolkit');
  esc_html__('Text to translate', 'gads-toolkit');
  ```

---

## 🔐 Tính năng bảo mật nâng cao

### 1. Real-time Auto-Block Engine

**Location:** `includes/core-engine.php` → `tkgadm_check_ip_instant()`

**Cơ chế:**

- Hook vào `tkgadm_track_visit()` để kiểm tra ngay khi có `gclid`
- Query database đếm số click trong khoảng thời gian theo rules
- Nếu vi phạm → Chặn IP + Set Cookie `tkgadm_banned` + Sync Google Ads + Gửi thông báo

**Lưu ý khi sửa:**

- Đảm bảo query được prepare đúng cách
- Cookie phải được set trước khi output bất kỳ content nào
- Break loop sau khi chặn để tránh duplicate actions

### 2. Smart Cross-IP Blocking

**Location:** `includes/core-engine.php` → `tkgadm_track_visit()` (sau real-time check)

**Cơ chế:**

- Kiểm tra Cookie `tkgadm_banned` trong mỗi request
- Nếu có cookie nhưng IP hiện tại chưa bị chặn → Chặn IP mới này
- Gửi thông báo với tag "Cross-IP Detection"

**Lưu ý:**

- Cookie có thể bị xóa bởi user → Không phải giải pháp 100%
- Kết hợp với các phương pháp khác (fingerprinting) nếu cần tăng độ chính xác

### 3. IPv6 Support

**Database:** Cột `ip_address` là `VARCHAR(255)` - đủ cho cả IPv4 và IPv6

**Validation:**

- Sử dụng `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)` để phát hiện IPv6
- Google Ads API hỗ trợ cả IPv4 và IPv6 trong IP exclusions

**Diagnostic Tool:**

- Trang "Chẩn đoán IPv6" trong module-notifications.php
- Sử dụng cURL với `CURL_IPRESOLVE_V6` để test IPv6 connectivity

---

## 🤖 Hướng dẫn cho coding agent

### Khi sửa/thêm code, luôn:

1. **Tôn trọng cấu trúc module hiện tại:**
   - Nếu sửa analytics logic → edit `module-analytics.php`
   - Nếu sửa Google Ads sync → edit `module-google-ads.php`
   - Nếu sửa Real-time blocking → edit `core-engine.php`
   - Nếu thêm AJAX handler mới → đặt trong module tương ứng với chức năng

2. **Không đổi tên public hooks/filters** trừ khi:
   - Có breaking change cần thiết
   - Đã document trong CHANGELOG
   - Cung cấp backward compatibility wrapper

3. **Luôn update docblock** khi thay đổi function signature:

   ```php
   /**
    * Block IP and optionally sync to Google Ads
    *
    * @param string $ip IP address to block (supports IPv4, IPv6, wildcard)
    * @param string $reason Reason for blocking
    * @return bool True if blocked successfully
    */
   function tkgadm_block_ip_internal($ip, $reason = '') {
       // ...
   }
   ```

4. **Escape/Sanitize checklist:**
   - Input từ user: `sanitize_text_field()`, `sanitize_email()`, `intval()`, v.v.
   - Input từ `$_SERVER`: `sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))`
   - Output HTML: `esc_html()`, `esc_attr()`, `wp_kses_post()`
   - Output URL: `esc_url()`, `esc_url_raw()`
   - Database queries: **LUÔN** dùng `$wpdb->prepare()`

5. **Performance considerations:**
   - Tránh query trong loop (N+1 problem)
   - Sử dụng `wp_cache_*` functions nếu query nặng
   - Limit kết quả với `LIMIT` clause (đặc biệt cho stats table)
   - Index database columns thường xuyên query (`ip_address`, `visit_time`)

6. **Cron Jobs:**
   - Server-side cron: `central-service/cron-trigger.php` (chạy mỗi 5 phút)
   - WP-Cron jobs: Register trong `tkgadm_schedule_notifications()`
   - Test bằng WP-CLI: `wp cron event list`

7. **UI/UX Guidelines:**
   - Sử dụng grid layout 2 cột cho settings pages (đã áp dụng cho Google Ads và Notifications)
   - Font-size: 13px cho labels, 12px cho descriptions
   - Inline styles cho rapid prototyping, sau đó refactor vào CSS nếu cần
   - Emoji icons cho visual hierarchy (🔔, ⚙️, 📊, v.v.)

---

## 🔄 Gợi ý PR / Commit

### Format commit message:

```
[TYPE] Brief description (max 72 chars)

- Detailed change 1
- Detailed change 2
- Fix #issue_number (if applicable)
```

**TYPE** có thể là:

- `[FEAT]` - Tính năng mới
- `[FIX]` - Bug fix
- `[REFACTOR]` - Code refactoring (không thay đổi behavior)
- `[DOCS]` - Cập nhật documentation
- `[STYLE]` - Code style changes (formatting, v.v.)
- `[PERF]` - Performance improvements
- `[TEST]` - Thêm/sửa tests
- `[SECURITY]` - Security improvements

### Ví dụ:

```
[FEAT] Add Smart Cross-IP Blocking with Cookie Tagging

- Implement cookie-based device tracking (tkgadm_banned)
- Auto-block new IPs from previously banned devices
- Add Cross-IP detection notification
- Update AGENTS.md with security documentation
```

### Trước khi gửi PR:

1. **Kiểm tra code style:**
   - Nếu có PHPCS: `phpcs --standard=WordPress includes/`
   - Nếu chưa có: review manually theo WordPress Coding Standards

2. **Test thủ công:**
   - Activate/deactivate plugin → check database tables
   - Test tất cả AJAX endpoints liên quan
   - Test trên ít nhất 2 browsers (Chrome, Firefox)
   - Test cả IPv4 và IPv6 nếu có thay đổi tracking logic

3. **Cập nhật documentation:**
   - Update `README.md` nếu thêm feature mới
   - Update `SETUP-GUIDE.md` nếu có thay đổi cấu hình
   - Update `AGENTS.md` (file này) nếu có thay đổi architecture
   - Update version number trong `gads-toolkit.php`

4. **Check security:**
   - Tất cả AJAX có nonce verification?
   - Tất cả admin actions có capability check?
   - Tất cả user input đã sanitize?
   - Tất cả output đã escape?
   - Cookie được set an toàn (path, expiry)?

---

## 📚 Tài liệu tham khảo

- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [WordPress Database Class ($wpdb)](https://developer.wordpress.org/reference/classes/wpdb/)
- [Google Ads API Documentation](https://developers.google.com/google-ads/api/docs/start)
- [Chart.js Documentation](https://www.chartjs.org/docs/latest/)
- [IPv6 Testing Tools](https://test-ipv6.com/)

---

## 🐛 Troubleshooting

### Plugin không activate được:

- Check PHP version >= 7.4
- Check WordPress version >= 5.8
- Check file permissions (755 for directories, 644 for files)

### Database tables không tạo:

- Manually run: `tkgadm_create_tables()` trong PHP console
- Check MySQL user có quyền `CREATE TABLE`

### AJAX không hoạt động:

- Check browser console cho JavaScript errors
- Verify nonce trong request (DevTools → Network → Payload)
- Check PHP error log: `wp-content/debug.log`

### Google Ads sync failed:

- Verify API credentials trong **Cấu hình Google Ads**
- Check error message trong sync response
- Ensure `curl` extension enabled trong PHP
- Verify Manager ID (login-customer-id) nếu dùng MCC

### Real-time blocking không hoạt động:

- Kiểm tra "Kích hoạt chặn tự động" đã bật
- Verify quy tắc chặn đã được cấu hình
- Check PHP error log cho SQL errors
- Test với `?gclid=test_xxx` để trigger tracking

### Smart Cross-IP không chặn:

- Kiểm tra Cookie `tkgadm_banned` trong DevTools → Application → Cookies
- Cookie có thể bị block bởi browser privacy settings
- Verify function `tkgadm_is_ip_blocked()` hoạt động đúng

### IPv6 không được ghi nhận:

- Kiểm tra VPS có IPv6 address: `ip -6 addr show`
- Verify DNS có bản ghi AAAA
- Test IPv6 connectivity: `curl -6 https://ipv6.google.com`
- Check "Chẩn đoán IPv6" trong plugin admin

---
