# GAds Toolkit - Technical Memory for AI Coding

**Mục đích tài liệu:** Đây là bộ nhớ kỹ thuật (Memory/Context) tiêu chuẩn dành cho tác vụ AI Coding. Tài liệu này lưu trữ kiến trúc cốt lõi, flow hoạt động, và các rule thiết kế của plugin **GAds Toolkit** để thuận tiện khi sửa đổi, nâng cấp (VD: thêm báo cáo Cost/Click) mà không làm vỡ kiến trúc gốc.

---

## 1. Tổng quan hệ thống (System Overview)
* **Tên plugin:** GAds Toolkit
* **Chức năng chính:** Theo dõi truy cập, bóc tách lưu lượng Organic/Google Ads (thông qua `gclid`), phát hiện và chặn IP click tặc tự động đẩy lên Danh sách loại trừ (Exclusion List) mức tài khoản (Customer Level) của Google Ads.
* **Mô hình kiến trúc:** Modular / Tách file thao tác riêng rẽ theo từng tính năng, kết hợp với một "Central Service" ngoại vi để xử lý tác vụ bảo mật.

## 2. Cấu trúc Module (File Structure)
Plugin chia thành các module chính nằm trong thư mục `includes/`:
* `core-engine.php`: Module theo dõi & ghi nhận traffic (Tracking Engine). Capture IP, GCLID từ URL param, xác định thiết bị, thời gian trên trang.
* `module-data.php`: Xử lý tương tác nội bộ Database (Lọc data, tính toán click).
* `module-google-ads.php`: Chứa toàn bộ Dataflow kết nối Google Ads API (OAuth, Sync IP_BLOCK).
* `module-notifications.php`: Quản lý Cảnh báo (Email, Telegram qua HTTPS), Deep Test Ajax, Cron chạy cảnh báo 1h và Daily Report. **(Lưu ý: Không dùng Custom SMTP từ WordPress, chỉ dùng config Default PHP Mail).**
* `module-dashboard.php`: Giao diện quản lý Admin Backend (UI / UX config).

## 3. Kiến trúc Cơ sở dữ liệu (Database Tables)
Hệ thống sử dụng các custom table chính:
1. `wp_gads_toolkit_stats`: Bảng Master lưu vết IP, `gclid`, loại traffic, visit_time, time_on_page.
2. `wp_gads_toolkit_blocked`: Bảng lưu danh sách IP đã bị chặn và timestamp thực hiện block.

## 4. Google Ads API Architecture (Đặc biệt quan trọng)
Kiến trúc kết nối Google Ads *không dùng 1 flow tĩnh*, mà sử dụng **Dual-Mode (2 Kịch bản xác thực)** để che giấu `Developer Token` với Client (người mua plugin):

### Mode 1: Central Service (Mặc định cho KH)
* **Cơ chế:** Client KHÔNG CẦN nhập Client ID / Secret / Developer Token. Client chỉ nhập một `Secure API Key` vào WP Admin.
* **Flow hoạt động:** 
  - Giao diện Admin trỏ OAuth Redirect tới server `https://pdl.vn/gads-toolkit/oauth/`.
  - Mọi API call (như đẩy IP Block `?action=sync_ips`, lấy URL Auth `?action=get_credentials`) của plugin sẽ dùng `wp_remote_post` ném request sang **pdl.vn/gads-toolkit/api/** kèm theo `refresh_token` & `ip`.
  - Chỉ Server `pdl.vn` mới được cầm `Developer Token` thật + Secret Key -> Chịu trách nhiệm gọi thằng lên Google API và gửi Response về lại cho Plugin ở phía Client.
* **Điểm neo kết nối:** Biến `$api_key`, hàm `tkgadm_is_using_central_service()`, và API Endpoints tại `pdl.vn`.

### Mode 2: Direct API/Self-Managed (Dành cho Dev admin)
* Nếu không nhập API Key, Plugin tự rẽ nhánh gọi phương thức local.
* Đọc biến lưu tại wp_options như `tkgadm_gads_customer_id`, `tkgadm_gads_developer_token`, `tkgadm_gads_manager_id`.
* Gọi thẳng `wp_remote_post` lên Endpoint `https://googleads.googleapis.com/v20/customers/{customer_id}/customerNegativeCriteria:mutate` để push IP.

*[Phục vụ Mở rộng (Feature Expand)]* **Thêm Thống kê Chi tiêu (Cost/Clicks):** 
Do mô hình Central Service, bất kỳ lệnh Call Query (VD: `googleAds:search`) nào trong tương lai cũng phải phân thành 2 nhánh:
- Nhánh 1: Viết 1 Action mới tại `pdl.vn/api` giả dụ `?action=get_stats` nhận param và return JSON report. Code Plugin chỉ việc CURL (wp_remote_get) gọi lên endpoint PDL này và parse Render array ra thẻ div/HTML . 
- Nhánh 2: Code trực tiếp câu lệnh API nếu là Self-Managed. 

## 5. Hệ thống Cảnh báo & Tự động hó (Notifications & Cron)
* **Logic Chặn tự động:** 
  - Cron Hook: `tkgadm_auto_block_scan_event` (quét theo Rule).
  - Tự động bắt Limit: Cấu hình Max Clicks/Duration. Tự động chuyển IP vi phạm vào wp_gads_toolkit_blocked và queue đẩy sang GoogleAds.
* **Notification System:**
  - Hỗ trợ Telegram (dựa trên Bot Token + Chat ID qua API Telegram).
  - Hỗ trợ Email: wp_mail tiêu chuẩn (không ghi đè `phpmailer_init`, tránh conflict các plugin khác như CF7).
  - Cron Hook: `tkgadm_hourly_alert` (check IP nguy hiểm), `tkgadm_daily_report` (thống kê tổng).
  - Chức năng Deep Test: (Trong `module-notifications.php`), sử dụng để debug luồng thư và bắt lỗi WP_Mail Failed qua hệ thống Ajax.

## 6. Quy tắc AI Coding khi sửa/nâng cấp Plugin này
Vui lòng tuân thủ chặt các quy tắc sau khi khởi chạy Agentic/AI edit hệ thống:
1. **Không can thiệp Email Global:** Tuyệt đối không thêm/tái tạo tính năng Custom SMTP can thiệp bằng Hook `phpmailer_init` (Tránh crash CF7). 
2. **Tuân thủ Dual-Mode Auth:** Mọi truy xuất Google Ads API mới (Campaign list, Analytics) PHẢI ưu tiên kiểm tra nhánh `tkgadm_is_using_central_service()`. Ném HTTP request sang Endpoint `pdl.vn` thay vì trỏ thẳng Google Ads. Tránh lộ Developer Token.
3. **Database Security:** Các lệnh SQL raw phải bắt buộc chuẩn hóa qua `$wpdb->prepare` chống Injection, nhất là với cột IP (do IP dễ bị spoof fake header). IP Tracking phải bắt Filter `FILTER_VALIDATE_IP` cover cả IPv4 và IPv6.
4. **Không viết trực tiếp file log vào disk:** Cố gắng ghi Log ra DB bảng riêng hoặc qua Admin Notice để tuân thủ rule write-permission của WordPress Server.
5. Khi UI HTML thêm form hoặc section mới, hãy đảm bảo giữ Bootstrap/Grid structure hiện diện ở Code Admin cũ, sử dụng các div collapse nếu logic quá dài để UX gọn nhất có thể.

---
*Generated technical memory for PDL ecosystem - Reference for autonomous update procedures.*
