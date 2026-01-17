# Fraud Prevention for Google Ads - v2.2.0

Plugin WordPress toàn diện giúp theo dõi, phân tích và ngăn chặn click ảo (Fraud Click) từ Google Ads.

## 🎯 Tính năng chính

### 1. **Thống kê IP** (Menu chính)

- Theo dõi chi tiết từng IP truy cập từ Google Ads
- Đếm số lần click ads (dựa trên gclid/gbraid unique)
- Hiển thị tổng lượt truy cập, UTM term, thời gian truy cập cuối
- Chặn/Bỏ chặn IP với toggle switch
- Hỗ trợ wildcard cho IPv4 (ví dụ: `192.168.1.*`)
- Hỗ trợ đầy đủ IPv6
- Xem chi tiết phiên truy cập: URL, gclid, time on page
- Filter theo khoảng ngày

### 2. **Phân tích Traffic** (Submenu mới - v2.2.0)

- Biểu đồ cột chồng (stacked bar chart) so sánh:
  - 🎯 Google Ads Traffic (có gclid/gbraid)
  - 🌱 Organic Traffic (không có gclid/gbraid)
- Thống kê theo: Ngày / Tuần / Tháng / Quý
- Quick filters: 7 ngày, 30 ngày, 90 ngày
- Summary cards: Tổng visits, Ads visits, Organic visits, Tỷ lệ %

### 3. **Quản lý Dữ liệu** (Submenu mới - v2.2.0)

- Thống kê dung lượng database (MB)
- Xóa dữ liệu theo khoảng ngày
- Xóa dữ liệu cũ: 180 ngày / 1 năm / 2 năm
- Activity log theo dõi hành động
- **Chỉ xóa stats, không xóa IP blocked**

## 📊 Cấu trúc Database

### Bảng `wp_gads_toolkit_stats`

```sql
- id: BIGINT(20) AUTO_INCREMENT
- ip_address: VARCHAR(255) - Hỗ trợ IPv4 và IPv6
- visit_time: DATETIME
- url_visited: TEXT
- user_agent: TEXT
- gclid: VARCHAR(255) - Lưu cả gclid và gbraid
- time_on_page: INT - Thời gian ở lại trang (giây)
- visit_count: BIGINT(20) - Số lần truy cập lặp lại
```

### Bảng `wp_gads_toolkit_blocked`

```sql
- id: BIGINT(20) AUTO_INCREMENT
- ip_address: VARCHAR(255) - IP bị chặn
- blocked_time: DATETIME
```

## 🔧 Cấu trúc Plugin

```
gads-toolkit/
├── gads-toolkit.php (Bootstrap chính)
├── includes/
│   ├── core-functions.php (Database, tracking, helpers)
│   ├── ajax-functions.php (Tất cả AJAX handlers)
│   ├── admin-dashboard.php (Menu: Thống kê IP)
│   ├── admin-analytics.php (Submenu: Phân tích Traffic)
│   └── admin-maintenance.php (Submenu: Quản lý dữ liệu)
└── assets/
    ├── admin-style.css
    ├── admin-script.js
    ├── time-tracker.js
    └── chart.umd.min.js (Chart.js v4.4.0)
```

## 🚀 Cài đặt

1. Upload thư mục `gads-toolkit` vào `/wp-content/plugins/`
2. Activate plugin trong WordPress Admin
3. Truy cập **GAds Toolkit** trong menu admin

## 📈 Tracking Logic

Plugin tự động tracking khi URL có **BẤT KỲ** tham số nào sau:

- `gad_source` (Google Ads source parameter)
- `gclid` (Google Click ID - Android, Desktop)
- `gbraid` (Google Click ID - iOS 14.5+)

### Ưu tiên Click ID:

1. Nếu có `gclid` → dùng `gclid`
2. Nếu không có `gclid` nhưng có `gbraid` → dùng `gbraid`
3. Cả 2 đều lưu vào cùng cột `gclid` trong database

### Time on Page:

- Đo thời gian từ khi trang load
- Gửi cập nhật mỗi 10 giây
- Gửi khi người dùng rời trang hoặc chuyển tab

## 🛡️ Chặn IP

### Cách chặn:

1. Click toggle switch bên cạnh IP
2. Hoặc click nút "➕ Chặn IP" để nhập thủ công

### Hỗ trợ Wildcard (chỉ IPv4):

- `192.168.1.1` - Chặn IP cụ thể
- `192.168.1.*` - Chặn toàn bộ subnet 192.168.1.x
- `192.168.*.*` - Chặn toàn bộ 192.168.x.x
- `192.*.*.*` - Chặn toàn bộ 192.x.x.x

### IPv6:

- Hỗ trợ đầy đủ IPv6 (ví dụ: `2402:800:6310:c2ff:c91c:18eb:f87c:75a3`)
- Không hỗ trợ wildcard cho IPv6

## 🔌 AJAX Endpoints

### Cho Admin:

- `tkgadm_toggle_block_ip` - Chặn/bỏ chặn IP
- `tkgadm_get_chart_data` - Lấy dữ liệu biểu đồ theo IP
- `tkgadm_get_visit_details` - Lấy chi tiết phiên truy cập
- `tkgadm_get_traffic_data` - Lấy dữ liệu traffic analytics
- `tkgadm_delete_data` - Xóa dữ liệu thống kê

### Cho Frontend (Public):

- `tkgadm_update_time_on_page` - Cập nhật thời gian ở lại trang

## 📝 Changelog

### v2.2.0 (2026-01-17)

- ✨ **NEW**: Submenu "Phân tích Traffic" với biểu đồ cột chồng
- ✨ **NEW**: Submenu "Quản lý dữ liệu" để xóa data cũ
- ✨ **NEW**: Hỗ trợ `gbraid` (iOS 14.5+ tracking)
- ✨ **NEW**: Thống kê theo ngày/tuần/tháng/quý
- 🔧 Tái cấu trúc plugin thành modular (includes/ và assets/)
- 📊 Thống kê dung lượng database
- 🗑️ Xóa dữ liệu theo khoảng ngày hoặc độ tuổi

### v2.1.6 (2026-01-17)

- ✨ Hiển thị version plugin trong admin header
- 🐛 Fix double-click copy URL
- 🎨 Cải thiện UI: nút close modal cố định, toggle switch

### v2.1.5

- ✨ Thêm tracking `time_on_page`
- ✨ Hỗ trợ IPv6 đầy đủ
- ✨ Bảng chi tiết phiên truy cập thay vì danh sách URL
- 🎨 UI improvements

## 🤝 Hỗ trợ

- **Author**: Phú Digital
- **Website**: https://pdl.vn
- **GitHub**: https://github.com/phudigital/gads-toolkit

## 📄 License

GPLv2 or later
