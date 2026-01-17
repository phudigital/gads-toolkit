# Hướng dẫn Chuyển Dữ liệu (Migration) sang Phiên bản Mới

Từ phiên bản **GAds Toolkit 2.1.5** trở đi, plugin sử dụng tên bảng dữ liệu mới để đồng bộ với tên plugin.

- Bảng cũ: `wp_tkgad_moi`, `wp_tkgad_moi_blocked_ips`
- Bảng mới: `wp_gads_toolkit_stats`, `wp_gads_toolkit_blocked`

Nếu bạn đang có dữ liệu cũ và muốn chuyển sang bảng mới, hãy làm theo hướng dẫn dưới đây.

## ⚠️ Lưu ý quan trọng

1. **Sao lưu dữ liệu (Backup)** trước khi thực hiện.
2. Hướng dẫn dưới đây giả định tiền tố (prefix) database của bạn là `wp_`. Nếu prefix của bạn khác (ví dụ `wp_123_`), hãy thay `wp_` thành prefix tương ứng trong các câu lệnh SQL.

---

## Bước 1: Chuẩn bị dữ liệu cũ

Nếu bạn đã xóa plugin cũ hoặc database đã bị reset, bạn cần Import file backup (`.sql`) của bảng cũ vào phpMyAdmin trước.
Đảm bảo rằng trong database hiện tại đang tồn tại 2 bảng cũ là `wp_tkgad_moi` và `wp_tkgad_moi_blocked_ips`.

## Bước 2: Chạy lệnh SQL để chuyển dữ liệu

Truy cập **phpMyAdmin** > Chọn Database của bạn > Chọn tab **SQL**.

### 1. Chuyển dữ liệu lịch sử truy cập (Stats)

Copy và chạy đoạn mã dưới đây. Đoạn mã này đã bao gồm xử lý lỗi ngày tháng (`0000-00-00 00:00:00`) thường gặp:

```sql
INSERT INTO wp_gads_toolkit_stats (ip_address, visit_time, url_visited, visit_count)
SELECT
    ip_address,
    CASE
        -- Xử lý lỗi ngày tháng 0000-00-00 bằng cách thay bằng thời gian hiện tại
        WHEN CAST(visit_time AS CHAR) = '0000-00-00 00:00:00' THEN CURRENT_TIMESTAMP
        WHEN visit_time IS NULL THEN CURRENT_TIMESTAMP
        ELSE visit_time
    END,
    url_visited,
    visit_count
FROM wp_tkgad_moi;
```

_Lưu ý: Các cột mới như `user_agent`, `gclid` sẽ được để trống (NULL) vì dữ liệu cũ không có thông tin này._

### 2. Chuyển dữ liệu IP chặn (Blocked IPs)

Copy và chạy đoạn mã sau:

```sql
INSERT INTO wp_gads_toolkit_blocked (ip_address, blocked_time)
SELECT ip_address, blocked_time
FROM wp_tkgad_moi_blocked_ips;
```

## Bước 3: Kiểm tra và Dọn dẹp

1. Vào trang Dashboard của plugin: **Thống kê Google Ads Toolkit**.
2. Kiểm tra xem dữ liệu cũ và danh sách chặn đã hiển thị đầy đủ chưa.
3. Nếu mọi thứ đã ổn định, bạn có thể xóa 2 bảng cũ (`wp_tkgad_moi` và `wp_tkgad_moi_blocked_ips`) trong database để giải phóng dung lượng.
