# Cập nhật: Hỗ trợ Cloudflare Proxy & IPv6

## 📅 Ngày: 2026-01-20

## 🎯 Vấn đề

Plugin trước đây chỉ sử dụng `$_SERVER['REMOTE_ADDR']` để lấy IP, dẫn đến:

- ❌ **Khi Cloudflare Proxy BẬT**: Nhận IP của Cloudflare thay vì IP thật của user
- ✅ **Khi Cloudflare Proxy TẮT**: Hoạt động bình thường

## ✅ Giải pháp đã triển khai

### 1. Thêm function `tkgadm_get_real_user_ip()`

**File**: `includes/core-engine.php`

```php
function tkgadm_get_real_user_ip() {
    // 1. Cloudflare Proxy (highest priority)
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    // 2. Standard proxy headers
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = sanitize_text_field(trim($ips[0]));
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REAL_IP']));
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    // 3. Direct connection (fallback)
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }

    return '';
}
```

**Thứ tự ưu tiên**:

1. `CF-Connecting-IP` - Cloudflare real IP (cao nhất)
2. `X-Forwarded-For` - Standard proxy header
3. `X-Real-IP` - Alternative proxy header
4. `REMOTE_ADDR` - Direct connection (fallback)

### 2. Cập nhật tất cả các nơi sử dụng IP

**Các file đã sửa**:

#### `includes/core-engine.php`

- ✅ `tkgadm_track_visit()` - Tracking visits
- ✅ `tkgadm_enqueue_time_tracker()` - Time tracker script

#### `includes/module-notifications.php`

- ✅ IPv6 Diagnostic section

## 📊 Kết quả

### Trước khi sửa:

| Cloudflare Proxy | IP nhận được  | Tracking |
| ---------------- | ------------- | -------- |
| ON ☁️            | Cloudflare IP | ❌ SAI   |
| OFF ☁️           | User IP thật  | ✅ ĐÚNG  |

### Sau khi sửa:

| Cloudflare Proxy | IP nhận được                       | Tracking |
| ---------------- | ---------------------------------- | -------- |
| ON ☁️            | User IP thật (từ CF-Connecting-IP) | ✅ ĐÚNG  |
| OFF ☁️           | User IP thật (từ REMOTE_ADDR)      | ✅ ĐÚNG  |

## 🧪 Cách kiểm tra

### 1. Sử dụng test script

Truy cập:

```
https://your-site.com/wp-content/plugins/gads-toolkit/test-cloudflare-ip.php
```

Script sẽ hiển thị:

- ✅ Cloudflare status (Proxy ON/OFF)
- ✅ IP hiện tại (IPv4/IPv6)
- ✅ Tất cả headers liên quan
- ✅ IP mà plugin sẽ tracking

### 2. Kiểm tra trong Admin

Vào **GAds Toolkit > Cấu hình Thông báo** > Phần "Chẩn đoán IPv6"

Sẽ hiển thị:

- IP của bạn (đã qua xử lý Cloudflare nếu có)
- Loại IP (IPv4/IPv6)
- Server có hỗ trợ IPv6 không

## 📚 Tài liệu tham khảo

Xem file `CLOUDFLARE-IPV6.md` để hiểu rõ hơn về:

- Sự khác biệt giữa Cloudflare Proxy ON vs OFF
- Tại sao IPv6 chỉ có khi Proxy ON (nếu server không hỗ trợ IPv6)
- Cách Cloudflare xử lý IPv4/IPv6

## ⚠️ Lưu ý quan trọng

### Cho Production:

- ✅ **NÊN** bật Cloudflare Proxy để:
  - Tự động có IPv6 support
  - Bảo vệ khỏi DDoS
  - Tăng tốc độ với caching
  - Plugin vẫn tracking đúng IP thật

### Cho Development:

- ⚠️ **CÓ THỂ** tắt Proxy để debug dễ hơn
- ✅ Plugin vẫn hoạt động bình thường trong cả 2 trường hợp

## 🔄 Tương thích ngược

- ✅ **Hoàn toàn tương thích** với các website không dùng Cloudflare
- ✅ **Hoàn toàn tương thích** với các website dùng Cloudflare DNS Only
- ✅ **Cải thiện** tracking cho website dùng Cloudflare Proxy

## 🎉 Kết luận

Plugin giờ đây:

- ✅ Hỗ trợ đầy đủ Cloudflare Proxy
- ✅ Tracking chính xác IP thật của user
- ✅ Hỗ trợ cả IPv4 và IPv6
- ✅ Tương thích với mọi cấu hình hosting
- ✅ Có công cụ test và diagnostic đầy đủ

---

**Phiên bản**: 3.2.0  
**Tác giả**: Phú Digital  
**Ngày cập nhật**: 2026-01-20
