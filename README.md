# Fraud Prevention for Google Ads

Plugin WordPress hỗ trợ thống kê IP truy cập vào các URL có chứa tham số `gad_source`, giúp phát hiện và ngăn chặn gian lận quảng cáo. Phiên bản mới hỗ trợ chặn IP theo lớp (Wildcard), xem biểu đồ trực quan và quản lý tất cả trên một giao diện hiện đại.

## 📋 Tính năng chính

1.  **Thống kê truy cập thông minh:**

    - Tự động ghi lại IP, thời gian và URL khi truy cập có chứa `gad_source`.
    - Tự động bỏ qua quản trị viên (Logged-in Admins).
    - **Gộp dữ liệu:** Hiển thị thống kê gộp theo IP, tổng hợp tất cả các URL mà IP đó đã truy cập.

2.  **Chặn IP nâng cao (Wildcard):**

    - Hỗ trợ chặn IP cụ thể (VD: `192.168.1.1`).
    - **Hỗ trợ Wildcard (\*):** Cho phép chặn cả dải IP.
      - `193.186.4.*`: Chặn lớp C.
      - `162.120.*.*`: Chặn lớp B.
      - `10.*.*.*`: Chặn lớp A.

3.  **Giao diện báo cáo hiện đại (Dashboard):**

    - **Tất cả trong một:** Xem thống kê và quản lý chặn ngay trên cùng một trang.
    - **Biểu đồ (Chart.js):** Xem xu hướng truy cập của từng IP theo thời gian thực.
    - **Chi tiết URL & UTM:** Phân tích chi tiết từng đường dẫn truy cập và tự động trích xuất mã `utm_term`.
    - **Bộ lọc mạnh mẽ:** Lọc theo khoảng thời gian (Từ ngày - Đến ngày), tìm kiếm IP.

4.  **Quản lý danh sách đen (Blacklist):**
    - **Thao tác nhanh:** Bật/Tắt chặn IP ngay trên bảng thống kê bằng nút gạt (Toggle switch).
    - **Thêm hàng loạt:** Popup cho phép dán danh sách nhiều IP/Pattern để chặn cùng lúc.
    - **Sao chép nhanh:** Nút "Copy tất cả" giúp lấy danh sách IP chặn dễ dàng để chia sẻ hoặc backup.

## 🛠 Cài đặt và Kích hoạt

1.  Sao chép thư mục plugin vào thư mục `wp-content/plugins/`.
2.  Truy cập trang quản trị WordPress, menu **Plugins**.
3.  Kích hoạt plugin **Google Ads Fraud Toolkit**.
4.  Plugin sẽ tự động tạo 2 bảng dữ liệu: `tkgad_moi` và `tkgad_moi_blocked_ips`.

## 📖 Hướng dẫn sử dụng

### 1. Truy cập Dashboard

Menu: **GAds Toolkit** trên thanh sidebar quản trị.

### 2. Xem thống kê

- Bảng hiển thị danh sách các IP đã truy cập.
- Các IP bị chặn sẽ được tô đỏ và có nhãn "🚫 Đã chặn".
- Nhấn nút **"📋 Chi tiết"** để xem biểu đồ truy cập và danh sách các URL cụ thể của IP đó.

### 3. Chặn/Bỏ chặn IP

- **Cách 1 (Nhanh):** Tại bảng thống kê, gạt nút công tắc ở cột "Hành động" để Chặn hoặc Bỏ chặn ngay lập tức.
- **Cách 2 (Quản lý):** Nhấn nút **"➕ Quản lý IP"** để mở popup nhập danh sách IP thủ công (hỗ trợ nhập nhiều dòng).

### 4. Quản lý danh sách chặn

- Nhấn nút **"📋 Danh sách IP bị chặn"** để xem toàn bộ danh sách.
- Tại đây bạn có thể xóa IP khỏi danh sách hoặc copy toàn bộ danh sách ra clipboard.

## 💾 Cấu trúc Cơ sở dữ liệu

### 1. Bảng `tkgad_moi` (Lưu lịch sử)

| Cột           | Kiểu     | Mô tả              |
| :------------ | :------- | :----------------- |
| `id`          | BIGINT   | ID tự tăng         |
| `ip_address`  | VARCHAR  | IP người truy cập  |
| `visit_time`  | DATETIME | Thời gian truy cập |
| `url_visited` | TEXT     | URL truy cập       |
| `visit_count` | BIGINT   | Số lần truy cập    |

### 2. Bảng `tkgad_moi_blocked_ips` (Danh sách chặn)

| Cột            | Kiểu     | Mô tả                             |
| :------------- | :------- | :-------------------------------- |
| `id`           | BIGINT   | ID tự tăng                        |
| `ip_address`   | VARCHAR  | IP hoặc Pattern (VD: 192.168._._) |
| `blocked_time` | DATETIME | Thời gian chặn                    |

## 💻 Cấu trúc Code (Files & Functions)

- **`tkgadmoi.php`**: File core chứa toàn bộ logic.
  - **Xử lý IP & Wildcard:**
    - `tkgadm_ip_matches_pattern($ip, $pattern)`: Kiểm tra IP có khớp pattern không.
    - `tkgadm_is_ip_blocked($ip)`: Kiểm tra xem IP có nằm trong danh sách chặn không.
    - `tkgadm_validate_ip_pattern($pattern)`: Kiểm tra tính hợp lệ của IP/Pattern đầu vào.
  - **Database & Logging:**
    - `tkgadm_tao_bang()`: Tạo cấu trúc bảng.
    - `tkgadm_ghi_log_truy_cap()`: Ghi nhận lượt truy cập mới.
  - **Admin & UI:**
    - `tkgadm_them_menu_admin()`: Đăng ký menu.
    - `tkgadm_hien_thi_trang_thong_ke()`: Render giao diện Dashboard chính (HTML, CSS, JS).
  - **AJAX Handlers:**
    - `tkgadm_toggle_block_ip`: Xử lý AJAX chặn/bỏ chặn nhanh.
    - `tkgadm_get_chart_data`: API lấy dữ liệu vẽ biểu đồ.
  - **Helpers:**
    - `tkgadm_extract_utm_term($url)`: Lọc tham số utm_term từ URL.
