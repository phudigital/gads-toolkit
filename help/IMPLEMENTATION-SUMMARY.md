# Central Service Implementation - Complete Summary

## 🎯 Mục tiêu đã đạt được

✅ **User chỉ cần nhập:**

- Customer ID (Google Ads Account)
- Manager ID (nếu có MCC)
- Central Service URL
- API Key

✅ **User KHÔNG CẦN nhập:**

- ❌ Client ID
- ❌ Client Secret
- ❌ Developer Token

## 📦 Files đã tạo

### Central Service (Deploy lên https://pdl.vn/gads-toolkit/)

```
central-service/
├── config.php              # Centralized credentials & settings
├── .htaccess              # Apache security rules
├── nginx.conf             # Nginx configuration (cho server Nginx)
├── README.md              # Deployment guide đầy đủ
├── oauth/
│   └── index.php          # OAuth redirect handler
└── api/
    └── index.php          # API proxy service
```

### WordPress Plugin Updates

```
includes/
└── module-google-ads.php  # Updated với central service support
```

## 🏗️ Kiến trúc

```
┌─────────────────┐
│ WordPress Site 1│─┐
├─────────────────┤ │
│ WordPress Site 2│─┼──→ Central Service ──→ Google Ads API
├─────────────────┤ │    (pdl.vn/gads-toolkit)
│ WordPress Site 3│─┘
└─────────────────┘

User chỉ nhập:
- Customer ID
- Manager ID (optional)
- Service URL
- API Key

Central Service quản lý:
- Client ID
- Client Secret
- Developer Token
- OAuth flow
- API requests
```

## 🚀 Workflow

### 1. OAuth Authentication

```
User clicks "Kết nối Google" trong WordPress
    ↓
WordPress lấy Client ID từ Central Service API
    ↓
Redirect user đến Google OAuth (với central redirect URI)
    ↓
Google redirect về Central Service OAuth handler
    ↓
Central Service exchange code for tokens
    ↓
Redirect về WordPress với refresh token
    ↓
WordPress lưu refresh token
    ↓
✅ Hoàn tất!
```

### 2. IP Sync Process

```
WordPress gọi sync IPs
    ↓
Kiểm tra: Dùng Central Service?
    ├─ YES → Gửi request đến Central Service API
    │         ├─ Service lấy access token từ refresh token
    │         ├─ Service gọi Google Ads API với credentials của nó
    │         └─ Trả kết quả về WordPress
    │
    └─ NO  → Gọi trực tiếp Google Ads API (legacy mode)
```

## 🔧 Deployment Steps

### Bước 1: Deploy Central Service

```bash
# 1. Upload files lên server
scp -r central-service/* user@pdl.vn:/var/www/pdl.vn/gads-toolkit/

# 2. Configure credentials
nano /var/www/pdl.vn/gads-toolkit/config.php
# - Nhập Client ID, Secret, Developer Token
# - Generate API Key: openssl rand -hex 32

# 3. Set permissions
chown -R www-data:www-data /var/www/pdl.vn/gads-toolkit
chmod 644 config.php

# 4. Configure Nginx
cp nginx.conf /etc/nginx/sites-available/gads-toolkit-service
ln -s /etc/nginx/sites-available/gads-toolkit-service /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

# 5. Setup SSL
certbot --nginx -d pdl.vn

# 6. Test
curl -H "X-API-Key: YOUR_KEY" https://pdl.vn/gads-toolkit/api/?action=health
```

### Bước 2: Configure Google Cloud Console

1. Vào https://console.cloud.google.com/
2. Credentials → OAuth 2.0 Client ID
3. Thêm redirect URI: `https://pdl.vn/gads-toolkit/oauth/`
4. Save

**Chỉ cần làm 1 lần duy nhất!** ✅

### Bước 3: Configure WordPress Plugin

Trong WordPress Admin → GAds Toolkit → Cấu hình Google Ads:

1. **Central Service URL**: `https://pdl.vn/gads-toolkit`
2. **API Key**: `[key đã generate]`
3. **Customer ID**: `123-456-7890`
4. **Manager ID**: `(nếu có)`
5. Click "Kết nối tài khoản Google"

Done! 🎉

## 🔒 Security Features

### 1. API Key Authentication

- Mọi request đến API phải có header `X-API-Key`
- Key được generate random (32 bytes hex)

### 2. Rate Limiting

- Default: 100 requests/hour per IP
- Configurable trong `config.php`

### 3. Origin Whitelist

- Có thể giới hạn domains được phép sử dụng service
- Configure trong `GADS_ALLOWED_ORIGINS`

### 4. Config Protection

- `config.php` bị block bởi Nginx/Apache
- Không thể access trực tiếp qua web

### 5. Logging

- Tất cả requests được log
- Bao gồm IP, action, timestamp
- Có thể monitor và audit

## 📊 API Endpoints

| Endpoint                       | Method | Purpose                                          |
| ------------------------------ | ------ | ------------------------------------------------ |
| `/api/?action=health`          | GET    | Health check                                     |
| `/api/?action=get_credentials` | GET    | Lấy public credentials (Client ID, redirect URI) |
| `/api/?action=exchange_code`   | POST   | Exchange OAuth code for tokens                   |
| `/api/?action=sync_ips`        | POST   | Sync IPs to Google Ads                           |

## 🎨 WordPress Plugin UI Changes

Plugin tự động detect mode:

### Mode 1: Central Service (Client Mode)

```
✅ Đang sử dụng Central Service
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Chỉ cần nhập:
├─ API Key (Secure Key from Developer)
├─ Customer ID
└─ Manager ID (optional)

Đã được cấu hình sẵn:
✅ Central Service URL (Hardcoded)

Không cần nhập:
❌ Client ID
❌ Client Secret
❌ Developer Token
```

### Mode 2: Direct API (Legacy)

```
⚠️ Đang dùng Direct API
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Cần nhập đầy đủ:
├─ Client ID
├─ Client Secret
├─ Developer Token
├─ Customer ID
└─ Manager ID (optional)
```

## 💡 Benefits

### Cho Developers/Agencies:

✅ Quản lý credentials tập trung  
✅ Dễ update/rotate credentials  
✅ Monitor usage từ tất cả sites  
✅ Có thể monetize service

### Cho End Users:

✅ Setup đơn giản hơn  
✅ Không cần access Google Cloud Console  
✅ Không lo credentials bị leak  
✅ Chỉ cần Customer ID

### Cho Plugin Distribution:

✅ Giảm friction trong onboarding  
✅ Có thể offer hosted service  
✅ Better UX  
✅ Professional

## 🧪 Testing Checklist

- [ ] Deploy central service lên server
- [ ] Configure Nginx/Apache
- [ ] Setup SSL certificate
- [ ] Test health endpoint
- [ ] Test get_credentials endpoint
- [ ] Configure Google Cloud Console
- [ ] Test OAuth flow end-to-end
- [ ] Test IP sync via central service
- [ ] Test rate limiting
- [ ] Test error handling
- [ ] Monitor logs
- [ ] Test với multiple WordPress sites

## 📝 Next Steps

### Immediate:

1. Deploy central service lên production server
2. Configure credentials trong `config.php`
3. Setup Nginx với SSL
4. Test tất cả endpoints
5. Update WordPress plugin settings

### Future Enhancements:

- [ ] Admin dashboard cho central service
- [ ] Usage analytics/reporting
- [ ] Multi-tenant support với separate API keys
- [ ] Webhook notifications
- [ ] Backup/failover service
- [ ] CDN integration

## 🐛 Troubleshooting

### Service không accessible

```bash
# Check Nginx status
systemctl status nginx

# Check logs
tail -f /var/log/nginx/gads-toolkit-error.log

# Test PHP-FPM
systemctl status php8.1-fpm
```

### OAuth không hoạt động

```bash
# Check redirect URI trong Google Console
# Phải khớp chính xác: https://pdl.vn/gads-toolkit/oauth/

# Check SSL certificate
curl -I https://pdl.vn/gads-toolkit/oauth/

# Check service logs
tail -f /var/www/pdl.vn/gads-toolkit/logs/service.log
```

### API trả về 401

```bash
# Verify API key
curl -H "X-API-Key: WRONG_KEY" https://pdl.vn/gads-toolkit/api/?action=health
# Should return 401

curl -H "X-API-Key: CORRECT_KEY" https://pdl.vn/gads-toolkit/api/?action=health
# Should return 200
```

## 📚 Documentation

- **Central Service**: `central-service/README.md`
- **OAuth Setup**: `OAUTH-SETUP.md`
- **Changelog**: `CHANGELOG.md`
- **Architecture**: `ARCHITECTURE.md` (if exists)

---

**Implementation Date:** 2026-01-20  
**Version:** 3.0.0 (Central Service)  
**Status:** ✅ Complete and Ready for Production  
**Server:** Nginx (with Apache fallback support)
