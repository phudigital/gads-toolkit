# Tóm tắt Cập nhật v3.9.0

## 🎯 Mục tiêu đã hoàn thành

### 1. ✅ Validation Customer ID

- Thêm hàm `tkgadm_validate_customer_id($id)`
- Kiểm tra độ dài (6-12 ký tự số)
- Tự động loại bỏ dấu gạch ngang/khoảng trắng trước khi check
- **Chặn lưu settings** và báo lỗi đỏ nếu ID không đúng định dạng
- Thêm thuộc tính `pattern` HTML5 vào input field để browser check trước

### 2. ✅ Reset Cấu hình (Factory Reset)

- Nút "🗑️ Xóa cấu hình & Reset" mới
- Xóa toàn bộ options liên quan:
  - API Key
  - Customer ID, Manager ID
  - OAuth Token (Refresh Token)
  - Synced logs
- Reload trang về trạng thái ban đầu

### 3. ✅ Nút "Copy Lỗi"

- Khi sync thủ công gặp lỗi (Ajax error hoặc API error):
  - Hiển thị thông báo lỗi màu đỏ
  - Xuất hiện nút "📋 Copy Lỗi" ngay bên dưới
- Giúp user copy toàn bộ nội dung lỗi kỹ thuật để gửi support
- Hiệu ứng "✅ Đã copy!" feedback

---

## 📁 Code Changes

### `includes/module-google-ads.php`

- Added `tkgadm_validate_customer_id()`
- Updated `tkgadm_render_google_ads_page()`:
  - Logic xử lý `tkgadm_reset_all` POST request
  - Validation Customer ID trong POST handler
  - UI nút Reset (chỉ hiện khi đã có API Key)
  - JavaScript cho nút "Copy Lỗi" trong Ajax handler

### `gads-toolkit.php`

- Version bumped: 3.8.0 -> 3.9.0

---

## 🔍 Testing Guide

1. **Test Validation ID:**
   - Nhập `abc` -> Báo lỗi
   - Nhập `123` -> Báo lỗi
   - Nhập `123-456-7890` -> OK
   - Nhập `1234567890` -> OK

2. **Test Reset:**
   - Cấu hình đầy đủ
   - Nhấn "Xóa cấu hình & Reset" -> Xác nhận OK
   - Kiểm tra trang reload lại trắng thông tin (từ đầu)

3. **Test Copy Error:**
   - Ngắt mạng hoặc làm sai Customer ID để gây lỗi sync
   - Nhấn "Upload IP lên Google Ads"
   - Thấy lỗi đỏ + nút Copy
   - Nhấn Copy -> Paste ra notepad xem nội dung

---

**Version:** 3.9.0
**Date:** 2026-01-22
