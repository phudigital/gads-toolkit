# 📖 Hướng Dẫn Cấu Hình Chi Tiết GAds Toolkit

Tài liệu này hướng dẫn anh thiết lập 3 thành phần quan trọng nhất để hệ thống hoạt động hoàn hảo.

---

## 1. Kết nối Google Ads API (Quan trọng nhất)

Để IP bị chặn tự động được đẩy lên Google Ads, anh cần thiết lập API:

### Bước 1: Lấy Developer Token

1. Đăng nhập vào tài khoản Google Ads **Quản lý (MCC)**.
2. Menu **Công cụ & Cài đặt** -> **Cài đặt Trung tâm API**.
3. Copy **Developer Token**.

### Bước 2: Tạo Client ID & Client Secret

1. Truy cập [Google Cloud Console](https://console.cloud.google.com/).
2. Tạo dự án mới, tìm kiếm và Enable **Google Ads API**.
3. Vào **Credentials** -> **Create Credentials** -> **OAuth Client ID**.
4. Chọn **Web Application** hoặc **Desktop App**.
5. Thêm `https://developers.google.com/oauthplayground` vào Authorized Redirect URIs.
6. Lưu lại **Client ID** và **Client Secret**.

### Bước 3: Lấy Refresh Token

1. Truy cập [OAuth2 Playground](https://developers.google.com/oauthplayground).
2. Biểu tượng ⚙️ (góc phải) -> Tích chọn **Use your own OAuth credentials** -> Nhập Client ID/Secret.
3. Phần Scopes nhập: `https://www.googleapis.com/auth/adwords` -> Bấm **Authorize APIs**.
4. Bấm **Exchange authorization code for tokens** -> Copy **Refresh Token**.

### Bước 4: Nhập vào Plugin

- Vào menu **Kết nối API** trong Plugin và nhận tất cả thông tin trên.
- Nhập **Customer ID** (ID tài khoản chạy quảng cáo) và **Manager ID** (nếu dùng MCC).

---

## 2. Cấu hình Cron Job phía Server

Mặc định WordPress Cron (wp-cron.php) chỉ chạy khi có người truy cập web. Để đảm bảo hệ thống quét click tặc 24/7 ngay cả khi vắng khách, anh cần cấu hình Cron Job trên VPS.

**Mở Terminal VPS và gõ:**

```bash
crontab -e
```

**Thêm dòng này vào cuối file (thay đường dẫn thực tế):**

```bash
*/5 * * * * /usr/bin/php /var/www/html/wp-content/plugins/gads-toolkit/central-service/cron-trigger.php >/dev/null 2>&1
```

_(Lưu ý: File `cron-trigger.php` đã được bảo mật để chỉ có thể chạy qua lệnh này, không thể chạy qua trình duyệt)._

---

## 3. Cấu hình Thông Báo Telegram

1. Chat với [@BotFather](https://t.me/BotFather) để tạo Bot mới -> Lấy **Bot Token**.
2. Thêm Bot vào một Group/Channel.
3. Chat với [@userinfobot](https://t.me/userinfobot) để lấy **Chat ID** của Group đó.
4. Nhập vào menu **Cấu hình Thông báo**.

---

## 4. Tối ưu IPv6 (Tùy chọn nhưng nên có)

Để chặn chính xác người dùng 4G/5G:

1. Đảm bảo VPS đã bật IPv6 (Sửa `/etc/netplan/` như hướng dẫn trong trang Chẩn đoán).
2. Thêm bản ghi **AAAA** trong DNS (Cloudflare/PA Việt Nam) trỏ về địa chỉ IPv6 của VPS.

---

## 5. Cơ chế "Smart Cross-IP Blocking"

Tính năng này không cần cấu hình. Hệ thống sẽ tự động thực hiện:

- Khi một IP bị chặn, hệ thống gắn một Cookie `tkgadm_banned` vào trình duyệt kẻ đó.
- Dù họ đổi mạng sang 4G (IPv6 mới) hoặc nhảy sang IP khác, hệ thống vẫn nhận diện ra "người cũ" thông qua Cookie và thực hiện chặn IP mới đó ngay lập tức.

---

_Nếu gặp khó khăn trong quá trình cấu hình, hãy liên hệ Support kỹ thuật._
