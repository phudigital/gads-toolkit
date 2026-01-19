# AGENTS.md

> **Tài liệu dành cho Coding Agent** - Hướng dẫn setup, build, test và quy ước code cho plugin WordPress "Fraud Prevention for Google Ads"

---

## 📋 Tổng quan plugin

**Fraud Prevention for Google Ads** (gads-toolkit) là plugin WordPress chuyên nghiệp giúp:

- Theo dõi và phân tích traffic từ Google Ads (dựa trên `gclid`/`gbraid`)
- Phát hiện và chặn click ảo (fraud clicks) tự động hoặc thủ công
- Tích hợp Google Ads API để đồng bộ danh sách IP bị chặn vào account-level exclusions
- Gửi cảnh báo qua Email và Telegram khi phát hiện hành vi nghi ngờ
- Phân tích traffic với biểu đồ so sánh Ads vs Organic

### Công nghệ chính:

- **Backend**: PHP 7.4+ (WordPress Plugin API)
- **Frontend**: Vanilla JavaScript (jQuery), Chart.js v4.4.0
- **Database**: MySQL/MariaDB (WordPress `$wpdb`)
- **External APIs**: Google Ads API v19, Telegram Bot API
- **Build Tools**: **KHÔNG CÓ** - Plugin này không sử dụng build tool (Webpack, Vite, v.v.). Tất cả assets đều là vanilla JS/CSS.

---

## 🛠️ Thiết lập môi trường

### Yêu cầu hệ thống:

- **PHP**: >= 7.4 (khuyến nghị 8.0+)
- **WordPress**: >= 5.8 (khuyến nghị 6.0+)
- **MySQL/MariaDB**: >= 5.7 / MariaDB 10.2+
- **Server**: Apache hoặc Nginx với `mod_rewrite` enabled
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
   - `wp_gads_toolkit_stats` - Lưu traffic logs
   - `wp_gads_toolkit_blocked` - Lưu danh sách IP bị chặn

4. **Cấu hình plugin (tùy chọn):**
   - Vào **GAds Toolkit** → **Cấu hình Thông báo** để setup Email/Telegram
   - Vào **Cấu hình Google Ads** để kết nối API (nếu cần auto-sync)

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

Khi release version mới, cập nhật constant trong `gads-toolkit.php`:

```php
define('GADS_TOOLKIT_VERSION', '2.8.2'); // Tăng version number
```

WordPress sẽ tự động bust cache cho assets dựa trên version này.

---

## 🧪 Testing

### Hiện trạng:

Plugin này **CHƯA CÓ** automated tests (PHPUnit, Pest, Jest, v.v.).

### Testing thủ công:

1. **Test tracking logic:**
   - Truy cập: `(your-site)/wp-content/plugins/gads-toolkit/test-organic-logic.php`
   - Script này sẽ hiển thị chi tiết SQL queries và phân loại traffic (Ads vs Organic)
   - Yêu cầu đăng nhập với quyền `manage_options`

2. **Test AJAX endpoints:**
   - Sử dụng browser DevTools → Network tab
   - Trigger actions trong admin (block IP, load chart, v.v.)
   - Kiểm tra response từ các AJAX handlers

3. **Test notifications:**
   - Vào **Cấu hình Thông báo** → Click "Deep Test" buttons
   - Module test sẽ hiển thị log chi tiết về SMTP/Telegram connection

4. **Test Google Ads sync:**
   - Vào **Cấu hình Google Ads** → Click "Đồng bộ ngay"
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

- `includes/core-engine.php` - Database, tracking, admin init
- `includes/module-analytics.php` - Dashboard & analytics UI/AJAX
- `includes/module-google-ads.php` - Google Ads API integration
- `includes/module-notifications.php` - Email/Telegram alerts
- `includes/module-data.php` - Data maintenance

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

## 🤖 Hướng dẫn cho coding agent

### Khi sửa/thêm code, luôn:

1. **Tôn trọng cấu trúc module hiện tại:**
   - Nếu sửa analytics logic → edit `module-analytics.php`
   - Nếu sửa Google Ads sync → edit `module-google-ads.php`
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
    * @param bool $auto_sync Whether to sync immediately to Google Ads
    * @return bool True if blocked successfully
    */
   function tkgadm_block_ip($ip, $auto_sync = false) {
       // ...
   }
   ```

4. **Thêm/cập nhật test case** (khi có test suite):
   - Nếu thêm function mới → thêm test coverage
   - Nếu fix bug → thêm regression test
   - Chạy `vendor/bin/phpunit` trước khi commit (khi có)

5. **Escape/Sanitize checklist:**
   - Input từ user: `sanitize_text_field()`, `sanitize_email()`, `intval()`, v.v.
   - Output HTML: `esc_html()`, `esc_attr()`, `wp_kses_post()`
   - Output URL: `esc_url()`, `esc_url_raw()`
   - Database queries: **LUÔN** dùng `$wpdb->prepare()`

6. **Performance considerations:**
   - Tránh query trong loop (N+1 problem)
   - Sử dụng `wp_cache_*` functions nếu query nặng
   - Limit kết quả với `LIMIT` clause (đặc biệt cho stats table)
   - Index database columns thường xuyên query (`ip_address`, `visit_time`)

7. **Cron Jobs:**
   - Khi thêm cron job mới, nhớ:
     - Register trong `tkgadm_schedule_notifications()` (module-notifications.php)
     - Unregister trong `tkgadm_unschedule_notifications()`
     - Test bằng WP-CLI: `wp cron event list`

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

### Ví dụ:

```
[FEAT] Add bulk IP blocking feature

- Add modal UI for bulk IP input
- Support wildcard patterns (192.168.1.*)
- Validate IP format before blocking
- Update README with bulk block instructions
```

### Trước khi gửi PR:

1. **Kiểm tra code style:**
   - Nếu có PHPCS: `phpcs --standard=WordPress includes/`
   - Nếu chưa có: review manually theo WordPress Coding Standards

2. **Test thủ công:**
   - Activate/deactivate plugin → check database tables
   - Test tất cả AJAX endpoints liên quan
   - Test trên ít nhất 2 browsers (Chrome, Firefox)

3. **Cập nhật documentation:**
   - Update `README.md` nếu thêm feature mới
   - Update `CHANGELOG` section trong README
   - Update version number trong `gads-toolkit.php` nếu cần

4. **Check security:**
   - Tất cả AJAX có nonce verification?
   - Tất cả admin actions có capability check?
   - Tất cả user input đã sanitize?
   - Tất cả output đã escape?

---

## 📚 Tài liệu tham khảo

- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [WordPress Database Class ($wpdb)](https://developer.wordpress.org/reference/classes/wpdb/)
- [Google Ads API Documentation](https://developers.google.com/google-ads/api/docs/start)
- [Chart.js Documentation](https://www.chartjs.org/docs/latest/)

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

---

**Version:** 2.8.1  
**Last Updated:** 2026-01-19  
**Maintainer:** Phú Digital (https://pdl.vn)
