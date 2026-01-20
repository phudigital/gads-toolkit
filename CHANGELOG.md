# Changelog

All notable changes to **Fraud Prevention for Google Ads** will be documented in this file.

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
