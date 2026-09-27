# GAds Toolkit - UI/UX Redesign & Restructure (WordPress Native)

## 1. Phân tích hiện trạng & Vấn đề khả thi
- Hiện tại, plugin có quá nhiều sub-menu và các file module riêng rẽ chứa nhiều cài đặt phân mảnh (như `module-settings.php`, `module-google-ads.php`, `module-whitelist.php`, `module-notifications.php`).
- Việc thiết kế lại hoàn toàn dưới dạng Single Page Application (không có sidebar WP) dù đẹp nhưng sẽ **khó khả thi** và tốn kém tài nguyên để tích hợp lại vào hệ sinh thái WordPress. 
- Yêu cầu đặt ra là: **Phải bám sát giao diện hiện tại của plugin, sử dụng chuẩn WP nhưng đẹp và khoa học hơn**.

## 2. Giải pháp (Redesign UI/UX)
Sử dụng cấu trúc `nav-tab-wrapper` kinh điển của WordPress, bên trong tích hợp cấu trúc lưới của Tailwind CSS (đã được sử dụng trong plugin). 

Gom toàn bộ các sub-menu của plugin vào **MỘT trang duy nhất**, phân tách bằng **5 Tab**:

### 1️⃣ Tab: Tổng quan (Dashboard)
- Gộp từ: `module-dashboard.php`.
- Giữ nguyên các Widget thống kê nhưng cấu trúc lại theo Bento Grid.
- Hiện biểu đồ và log truy cập gần nhất.

### 2️⃣ Tab: Quản lý Truy cập
- Gộp từ: List IP của `module-dashboard.php` và chức năng của `module-whitelist.php`.
- Một bảng duy nhất quản lý tất cả IP (có đánh dấu trạng thái Bị chặn hoặc Whitelist).
- Nút thêm Whitelist và Chặn IP thủ công nằm cạnh nhau để dễ thao tác.

### 3️⃣ Tab: Đồng bộ Google Ads
- Gộp từ: `module-google-ads.php` và `module-gads-manager.php`.
- Tách biệt rõ ràng khu vực cấu hình ID (Customer, Manager) và khu vực quy tắc đồng bộ tự động.

### 4️⃣ Tab: Cảnh báo (Notifications)
- Dành riêng cho `module-notifications.php`.
- Form cấu hình Email và Telegram đặt cạnh nhau dạng lưới (Grid), dễ so sánh và thiết lập.

### 5️⃣ Tab: Hệ thống & Dữ liệu
- Gộp từ: `module-settings.php` (phần API Key) và `module-data.php` (Export/Import/Xóa Log).

## 3. Prototype Cập nhật
Xem tệp `index.html` trong thư mục này. Nó mô phỏng lại toàn bộ phần vỏ ngoài của WordPress (sidebar, topbar) để chứng minh tính **khả thi** và **ăn khớp** khi đưa đoạn mã mới (bên trong `wrap`) vào thay thế các hàm render hiện tại. 

Thiết kế đã được điều chỉnh dùng bộ màu chuẩn của WP (`#f0f0f1`, `#c3c4c7`, `#2271b1`) nhưng form/bảng được cách tân sạch sẽ hơn nhờ Tailwind.
