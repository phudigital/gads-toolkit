# Version 3.6.0 - Biểu Đồ Thống Kê Hàng Ngày

## 🎉 Tính năng mới

### 📊 Biểu đồ thống kê hàng ngày (Dashboard)

Đã bổ sung biểu đồ kết hợp (cột + đường) vào tab **"Thống Kê IP Ads"** để theo dõi hiệu suất hàng ngày:

#### ✨ Tính năng chính:

1. **Biểu đồ kết hợp**:
   - **Cột xanh**: Số lượt truy cập qua Google Ads mỗi ngày (tất cả IP có gclid)
   - **Đường đỏ**: Số lượt chặn IP mỗi ngày
   - **Dual Y-axis**: Trục Y bên trái cho Ads, trục Y bên phải cho số lượt chặn

2. **Bộ lọc thời gian linh hoạt**:
   - 7 ngày
   - 15 ngày (mặc định)
   - 30 ngày
   - 180 ngày

3. **Summary Cards**:
   - 📊 Tổng lượt Ads
   - 🚫 Tổng lượt chặn
   - 📈 Trung bình Ads/ngày
   - ⚡ Tỷ lệ chặn (%)

4. **Tương tác với biểu đồ**:
   - Click vào cột (Ads) → Xem danh sách IP có lượt truy cập Ads trong ngày đó
   - Click vào điểm đường (Blocked) → Xem danh sách IP bị chặn trong ngày đó
   - Modal hiển thị chi tiết: IP, số phiên, tổng lượt, trạng thái

#### 🎨 Giao diện:

- Thiết kế hiện đại với gradient cards
- Biểu đồ responsive, tự động điều chỉnh theo màn hình
- Tooltip hiển thị đầy đủ thông tin khi hover
- Animation mượt mà khi chuyển đổi dữ liệu

#### 🔧 Kỹ thuật:

- **Chart.js**: Mixed chart (bar + line)
- **AJAX**: 2 endpoints mới
  - `tkgadm_get_daily_stats`: Lấy dữ liệu thống kê theo ngày
  - `tkgadm_get_daily_details`: Lấy chi tiết IP theo ngày
- **Performance**: Tối ưu query với GROUP BY và DATE functions
- **UX**: Loading state, error handling, responsive design

## 📝 Thay đổi kỹ thuật

### Files đã chỉnh sửa:

1. **includes/module-analytics.php**:
   - Thêm HTML cho biểu đồ và summary cards
   - Thêm modal chi tiết ngày
   - Thêm 2 AJAX handlers mới

2. **assets/admin-script.js**:
   - Thêm logic load và render biểu đồ
   - Xử lý click events trên biểu đồ
   - Render modal chi tiết

3. **gads-toolkit.php**:
   - Cập nhật version: 3.5.0 → 3.6.0

### Database queries:

```sql
-- Lấy số lượt Ads theo ngày
SELECT DATE(visit_time) as date,
       COUNT(DISTINCT ip_address) as unique_ips,
       SUM(visit_count) as total_visits
FROM wp_gads_toolkit_stats
WHERE gclid IS NOT NULL AND gclid != ''
AND visit_time >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
GROUP BY DATE(visit_time)
ORDER BY date ASC

-- Lấy số lượt chặn theo ngày
SELECT DATE(blocked_time) as date,
       COUNT(*) as blocked_count
FROM wp_gads_toolkit_blocked
WHERE blocked_time >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
GROUP BY DATE(blocked_time)
ORDER BY date ASC
```

## 🚀 Hướng dẫn sử dụng

1. Truy cập **WordPress Admin → Fraud Prevention → Thống Kê IP Ads**
2. Biểu đồ sẽ tự động load với dữ liệu 15 ngày gần nhất
3. Click vào các nút **7/15/30/180 ngày** để thay đổi khoảng thời gian
4. Click vào cột hoặc điểm trên biểu đồ để xem chi tiết IP của ngày đó
5. Trong modal chi tiết, xem danh sách IP với trạng thái chặn/hoạt động

## 📊 Use Cases

- **Theo dõi xu hướng**: Xem lượng Ads traffic tăng/giảm theo thời gian
- **Phát hiện bất thường**: Nhận biết ngày có lượt chặn cao bất thường
- **Đánh giá hiệu quả**: Tính tỷ lệ chặn so với tổng lượt Ads
- **Phân tích chi tiết**: Drill-down vào từng ngày để xem IP cụ thể

## 🔮 Tương lai

Các tính năng có thể mở rộng:

- Export dữ liệu biểu đồ ra CSV/Excel
- So sánh 2 khoảng thời gian
- Thêm filter theo nguồn traffic (utm_source, utm_campaign)
- Alert tự động khi tỷ lệ chặn vượt ngưỡng
- Tích hợp với Google Analytics để so sánh dữ liệu

---

**Ngày phát hành**: 2026-01-21  
**Phiên bản**: 3.6.0  
**Tác giả**: Phú Digital
