# Google Ads Toolkit - Central Service

Unified service cho OAuth authentication và API proxy, giúp WordPress sites không cần nhập Client ID, Client Secret, Developer Token.

## 📁 Cấu trúc thư mục

```
https://pdl.vn/gads-toolkit/
├── config.php          # Centralized credentials (KHÔNG public)
├── .htaccess          # Apache security rules
├── nginx.conf         # Nginx configuration example
├── oauth/
│   └── index.php      # OAuth redirect handler
├── api/
│   └── index.php      # API proxy service
└── logs/              # Service logs (tự động tạo)
    └── service.log
```

## 🚀 Deployment Guide

### Bước 1: Upload files lên server

```bash
# SSH vào server
ssh user@pdl.vn

# Tạo thư mục
sudo mkdir -p /var/www/pdl.vn/gads-toolkit
cd /var/www/pdl.vn/gads-toolkit

# Upload tất cả files từ central-service/
# - config.php
# - oauth/index.php
# - api/index.php
```

### Bước 2: Cấu hình credentials

Edit `config.php`:

```php
// Google Ads API Credentials
define('GADS_CLIENT_ID', 'YOUR_CLIENT_ID_HERE');
define('GADS_CLIENT_SECRET', 'YOUR_CLIENT_SECRET_HERE');
define('GADS_DEVELOPER_TOKEN', 'YOUR_DEVELOPER_TOKEN_HERE');

// OAuth Redirect URI
define('GADS_OAUTH_REDIRECT_URI', 'https://pdl.vn/gads-toolkit/oauth/');

// API Security - Generate strong key
define('GADS_API_KEY', 'YOUR_SECURE_API_KEY_HERE');

// Allowed Origins (optional - for security)
define('GADS_ALLOWED_ORIGINS', [
    'https://client-site1.com',
    'https://client-site2.com',
]);
```

**Generate API Key:**

```bash
openssl rand -hex 32
```

### Bước 3: Set permissions

```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/pdl.vn/gads-toolkit

# Set permissions
sudo chmod 755 /var/www/pdl.vn/gads-toolkit
sudo chmod 644 /var/www/pdl.vn/gads-toolkit/config.php
sudo chmod 755 /var/www/pdl.vn/gads-toolkit/oauth
sudo chmod 644 /var/www/pdl.vn/gads-toolkit/oauth/index.php
sudo chmod 755 /var/www/pdl.vn/gads-toolkit/api
sudo chmod 644 /var/www/pdl.vn/gads-toolkit/api/index.php

# Create logs directory
sudo mkdir -p /var/www/pdl.vn/gads-toolkit/logs
sudo chmod 755 /var/www/pdl.vn/gads-toolkit/logs
```

### Bước 4: Configure Nginx

```bash
# Copy nginx config
sudo cp nginx.conf /etc/nginx/sites-available/gads-toolkit-service

# Edit config - update domain and paths
sudo nano /etc/nginx/sites-available/gads-toolkit-service

# Enable site
sudo ln -s /etc/nginx/sites-available/gads-toolkit-service /etc/nginx/sites-enabled/

# Test config
sudo nginx -t

# Reload nginx
sudo systemctl reload nginx
```

**Hoặc nếu dùng Apache:**

```bash
# Copy .htaccess to service directory
cp .htaccess /var/www/pdl.vn/gads-toolkit/

# Enable mod_rewrite and mod_headers
sudo a2enmod rewrite
sudo a2enmod headers
sudo systemctl restart apache2
```

### Bước 5: Setup SSL (Khuyến nghị)

```bash
# Install certbot
sudo apt install certbot python3-certbot-nginx

# Get SSL certificate
sudo certbot --nginx -d pdl.vn

# Auto-renewal is configured automatically
```

### Bước 6: Configure Google Cloud Console

1. Vào https://console.cloud.google.com/
2. Chọn project
3. APIs & Services → Credentials
4. Click OAuth 2.0 Client ID
5. Thêm **Authorized redirect URI**:
   ```
   https://pdl.vn/gads-toolkit/oauth/
   ```
6. Save

### Bước 7: Test service

**Test OAuth endpoint:**

```bash
curl https://pdl.vn/gads-toolkit/oauth/
# Should return error page (expected - no OAuth params)
```

**Test API health:**

```bash
curl -H "X-API-Key: YOUR_API_KEY" \
     https://pdl.vn/gads-toolkit/api/?action=health
```

Expected response:

```json
{
  "success": true,
  "status": "healthy",
  "version": "1.0.0",
  "timestamp": 1737328800
}
```

**Test get credentials:**

```bash
curl -H "X-API-Key: YOUR_API_KEY" \
     https://pdl.vn/gads-toolkit/api/?action=get_credentials
```

Expected response:

```json
{
  "success": true,
  "data": {
    "client_id": "YOUR_CLIENT_ID",
    "oauth_redirect_uri": "https://pdl.vn/gads-toolkit/oauth/",
    "api_version": "v19"
  }
}
```

## 🔧 WordPress Plugin Configuration

Sau khi deploy central service, cấu hình WordPress plugin:

### Bước 1: Vào WordPress Admin

- **GAds Toolkit** → **Cấu hình Google Ads**

### Bước 2: Nhập thông tin Central Service

- **Central Service URL**: `https://pdl.vn/gads-toolkit`
- **API Key**: `[API key bạn đã generate]`

### Bước 3: Nhập thông tin Google Ads

- **Customer ID**: `123-456-7890`
- **Manager ID**: `(nếu có MCC)`

### Bước 4: Kết nối Google Ads

- Click **"Kết nối tài khoản Google"**
- Authorize
- Done! ✅

**Lưu ý:** Khi dùng Central Service, bạn **KHÔNG CẦN** nhập:

- ❌ Client ID
- ❌ Client Secret
- ❌ Developer Token

## 🔒 Security Best Practices

### 1. Protect config.php

**Nginx** (đã có trong nginx.conf):

```nginx
location ~ ^/gads-toolkit/config\.php$ {
    deny all;
    return 404;
}
```

**Apache** (đã có trong .htaccess):

```apache
<Files "config.php">
    Order Allow,Deny
    Deny from all
</Files>
```

### 2. Restrict API access by IP (optional)

Edit `nginx.conf`:

```nginx
location /gads-toolkit/api/ {
    # Allow specific IPs only
    allow 1.2.3.4;      # Your office IP
    allow 5.6.7.8;      # Client site IP
    deny all;

    # ... rest of config
}
```

### 3. Monitor logs

```bash
# View access log
tail -f /var/log/nginx/gads-toolkit-access.log

# View error log
tail -f /var/log/nginx/gads-toolkit-error.log

# View service log
tail -f /var/www/pdl.vn/gads-toolkit/logs/service.log
```

### 4. Rate limiting (Nginx)

Add to nginx config:

```nginx
limit_req_zone $binary_remote_addr zone=api_limit:10m rate=10r/s;

location /gads-toolkit/api/ {
    limit_req zone=api_limit burst=20 nodelay;
    # ... rest of config
}
```

## 📊 Monitoring & Maintenance

### Check service health

```bash
# Create monitoring script
cat > /usr/local/bin/check-gads-service.sh << 'EOF'
#!/bin/bash
API_KEY="YOUR_API_KEY"
RESPONSE=$(curl -s -H "X-API-Key: $API_KEY" https://pdl.vn/gads-toolkit/api/?action=health)

if echo "$RESPONSE" | grep -q '"status":"healthy"'; then
    echo "✅ Service is healthy"
    exit 0
else
    echo "❌ Service is down!"
    echo "$RESPONSE"
    exit 1
fi
EOF

chmod +x /usr/local/bin/check-gads-service.sh
```

### Setup cron for monitoring

```bash
# Add to crontab
crontab -e

# Check every 5 minutes
*/5 * * * * /usr/local/bin/check-gads-service.sh || mail -s "GADS Service Down" admin@pdl.vn
```

### Log rotation

```bash
# Create logrotate config
sudo nano /etc/logrotate.d/gads-toolkit

# Add:
/var/www/pdl.vn/gads-toolkit/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    create 0644 www-data www-data
}
```

## 🐛 Troubleshooting

### OAuth không hoạt động

**Check:**

1. Redirect URI trong Google Console khớp chính xác
2. SSL certificate hợp lệ (nếu dùng HTTPS)
3. PHP có quyền write vào logs/
4. Check error log: `tail -f /var/log/nginx/gads-toolkit-error.log`

### API trả về 401 Unauthorized

**Check:**

1. API Key đúng chưa
2. Header `X-API-Key` có được gửi không
3. Check service log: `tail -f /var/www/pdl.vn/gads-toolkit/logs/service.log`

### Rate limit exceeded

**Solution:**

1. Tăng `GADS_RATE_LIMIT_PER_HOUR` trong config.php
2. Hoặc whitelist IP trong allowed origins

### Google Ads API errors

**Check:**

1. Developer Token còn hạn không
2. Customer ID đúng format (xxx-xxx-xxxx)
3. Manager ID (nếu dùng MCC) đúng chưa
4. Check service log để xem chi tiết error

## 📝 API Endpoints

### 1. Health Check

```
GET /gads-toolkit/api/?action=health
Header: X-API-Key: YOUR_KEY
```

### 2. Get Credentials

```
GET /gads-toolkit/api/?action=get_credentials
Header: X-API-Key: YOUR_KEY
```

### 3. Exchange Code

```
POST /gads-toolkit/api/?action=exchange_code
Header: X-API-Key: YOUR_KEY
Header: Content-Type: application/json
Body: {"code": "AUTHORIZATION_CODE"}
```

### 4. Sync IPs

```
POST /gads-toolkit/api/?action=sync_ips
Header: X-API-Key: YOUR_KEY
Header: Content-Type: application/json
Body: {
  "customer_id": "123-456-7890",
  "manager_id": "987-654-3210",
  "refresh_token": "REFRESH_TOKEN",
  "ips": ["1.2.3.4", "5.6.7.8"]
}
```

## 🔄 Updates

Khi có update:

```bash
# Backup current version
cp -r /var/www/pdl.vn/gads-toolkit /var/www/pdl.vn/gads-toolkit.backup

# Upload new files
# ... upload process ...

# Test
curl -H "X-API-Key: YOUR_KEY" https://pdl.vn/gads-toolkit/api/?action=health

# If OK, remove backup
rm -rf /var/www/pdl.vn/gads-toolkit.backup
```

---

**Version:** 1.0.0  
**Last Updated:** 2026-01-20  
**Support:** https://pdl.vn
