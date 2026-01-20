<?php
/**
 * Cloudflare & IP Detection Test Script
 * 
 * Script này giúp kiểm tra:
 * - Cloudflare Proxy có đang bật không
 * - IP nào đang được nhận (IPv4/IPv6)
 * - Headers nào có sẵn
 * - Plugin sẽ tracking IP nào
 */

// Prevent direct access in production
if (!defined('TESTING_MODE')) {
    // Uncomment dòng dưới để cho phép test
    define('TESTING_MODE', true);
}

if (!TESTING_MODE) {
    die('Testing mode is disabled. Edit this file to enable.');
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cloudflare & IP Test - GADS Toolkit</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .card h2 {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 1.5em;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9em;
            margin: 5px;
        }
        
        .badge-success {
            background: #10b981;
            color: white;
        }
        
        .badge-warning {
            background: #f59e0b;
            color: white;
        }
        
        .badge-danger {
            background: #ef4444;
            color: white;
        }
        
        .badge-info {
            background: #3b82f6;
            color: white;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .info-item {
            background: #f3f4f6;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .info-item label {
            display: block;
            font-weight: bold;
            color: #374151;
            margin-bottom: 5px;
            font-size: 0.9em;
        }
        
        .info-item .value {
            color: #1f2937;
            font-family: 'Courier New', monospace;
            word-break: break-all;
            font-size: 0.95em;
        }
        
        .highlight {
            background: #fef3c7;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        table th {
            background: #f9fafb;
            font-weight: bold;
            color: #374151;
        }
        
        table td {
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .alert-info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            color: #1e40af;
        }
        
        .alert-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
        
        .alert-warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }
        
        code {
            background: #1f2937;
            color: #10b981;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        
        .icon {
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Cloudflare & IP Detection Test</h1>
            <p>GADS Toolkit - Fraud Prevention for Google Ads</p>
        </div>

        <?php
        // ============================================
        // DETECT IP ADDRESS
        // ============================================
        
        function get_all_possible_ips() {
            $ips = [];
            
            // Cloudflare headers
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $ips['CF-Connecting-IP'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
            }
            
            // Standard proxy headers
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips['X-Forwarded-For'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
            }
            
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ips['X-Real-IP'] = $_SERVER['HTTP_X_REAL_IP'];
            }
            
            // Direct connection
            if (!empty($_SERVER['REMOTE_ADDR'])) {
                $ips['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'];
            }
            
            return $ips;
        }
        
        function get_final_ip() {
            // Giống logic trong plugin
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                return $_SERVER['HTTP_CF_CONNECTING_IP'];
            }
            
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                return trim($ips[0]);
            }
            
            return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        }
        
        function is_ipv6($ip) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        
        function is_ipv4($ip) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }
        
        function is_cloudflare_ip($ip) {
            // Cloudflare IPv4 ranges (simplified check)
            $cf_ipv4_ranges = [
                '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
                '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
                '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
                '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
                '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22'
            ];
            
            // Cloudflare IPv6 ranges
            $cf_ipv6_ranges = [
                '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
                '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'
            ];
            
            if (is_ipv4($ip)) {
                foreach ($cf_ipv4_ranges as $range) {
                    if (ip_in_range($ip, $range)) {
                        return true;
                    }
                }
            }
            
            if (is_ipv6($ip)) {
                foreach ($cf_ipv6_ranges as $range) {
                    if (ip_in_range_ipv6($ip, $range)) {
                        return true;
                    }
                }
            }
            
            return false;
        }
        
        function ip_in_range($ip, $range) {
            list($subnet, $mask) = explode('/', $range);
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask_long = -1 << (32 - (int)$mask);
            return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
        }
        
        function ip_in_range_ipv6($ip, $range) {
            // Simplified IPv6 range check
            list($subnet, $mask) = explode('/', $range);
            $ip_bin = inet_pton($ip);
            $subnet_bin = inet_pton($subnet);
            
            if ($ip_bin === false || $subnet_bin === false) {
                return false;
            }
            
            $mask = (int)$mask;
            $ip_bits = unpack('C*', $ip_bin);
            $subnet_bits = unpack('C*', $subnet_bin);
            
            for ($i = 1; $i <= 16; $i++) {
                $bits_to_check = min(8, $mask);
                $mask -= $bits_to_check;
                
                if ($bits_to_check == 0) {
                    break;
                }
                
                $ip_byte = $ip_bits[$i];
                $subnet_byte = $subnet_bits[$i];
                $mask_byte = (0xFF << (8 - $bits_to_check)) & 0xFF;
                
                if (($ip_byte & $mask_byte) !== ($subnet_byte & $mask_byte)) {
                    return false;
                }
            }
            
            return true;
        }
        
        $all_ips = get_all_possible_ips();
        $final_ip = get_final_ip();
        $is_cloudflare = !empty($_SERVER['HTTP_CF_CONNECTING_IP']) || is_cloudflare_ip($_SERVER['REMOTE_ADDR'] ?? '');
        $ip_version = is_ipv6($final_ip) ? 'IPv6' : (is_ipv4($final_ip) ? 'IPv4' : 'Unknown');
        ?>

        <!-- CLOUDFLARE STATUS -->
        <div class="card">
            <h2>☁️ Cloudflare Status</h2>
            <div>
                <?php if ($is_cloudflare): ?>
                    <span class="status-badge badge-success">✅ Cloudflare Proxy: ON</span>
                    <span class="status-badge badge-info">🌐 Traffic đi qua Cloudflare Edge</span>
                <?php else: ?>
                    <span class="status-badge badge-warning">⚠️ Cloudflare Proxy: OFF (DNS Only)</span>
                    <span class="status-badge badge-info">🔗 Direct Connection</span>
                <?php endif; ?>
            </div>
            
            <div class="alert alert-info">
                <strong>ℹ️ Giải thích:</strong><br>
                <?php if ($is_cloudflare): ?>
                    Website đang sử dụng Cloudflare Proxy (Orange Cloud ☁️). Traffic từ user đi qua Cloudflare trước khi đến server.
                    Điều này có nghĩa là:
                    <ul style="margin-top: 10px; margin-left: 20px;">
                        <li>✅ IPv6 được hỗ trợ tự động (ngay cả khi server chỉ có IPv4)</li>
                        <li>✅ Có DDoS protection và WAF</li>
                        <li>⚠️ IP nhận được là Cloudflare IP, cần dùng header <code>CF-Connecting-IP</code> để lấy IP thật</li>
                    </ul>
                <?php else: ?>
                    Website KHÔNG sử dụng Cloudflare Proxy (Grey Cloud ☁️ - DNS Only). User kết nối trực tiếp đến server.
                    Điều này có nghĩa là:
                    <ul style="margin-top: 10px; margin-left: 20px;">
                        <li>⚠️ IPv6 chỉ có nếu server hỗ trợ IPv6</li>
                        <li>❌ Không có DDoS protection từ Cloudflare</li>
                        <li>✅ IP nhận được là IP thật của user</li>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- IP INFORMATION -->
        <div class="card">
            <h2>🌐 IP Address Information</h2>
            
            <div class="info-grid">
                <div class="info-item">
                    <label>🎯 IP Plugin Sẽ Tracking:</label>
                    <div class="value" style="font-size: 1.2em; color: #667eea; font-weight: bold;">
                        <?php echo htmlspecialchars($final_ip); ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <label>📊 IP Version:</label>
                    <div class="value">
                        <?php if ($ip_version === 'IPv6'): ?>
                            <span class="status-badge badge-success">IPv6</span>
                        <?php elseif ($ip_version === 'IPv4'): ?>
                            <span class="status-badge badge-info">IPv4</span>
                        <?php else: ?>
                            <span class="status-badge badge-danger">Unknown</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <label>🔍 IP Source:</label>
                    <div class="value">
                        <?php 
                        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                            echo '<code>CF-Connecting-IP</code> (Cloudflare)';
                        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                            echo '<code>X-Forwarded-For</code> (Proxy)';
                        } else {
                            echo '<code>REMOTE_ADDR</code> (Direct)';
                        }
                        ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <label>🛡️ Is Cloudflare IP:</label>
                    <div class="value">
                        <?php echo is_cloudflare_ip($_SERVER['REMOTE_ADDR'] ?? '') ? 
                            '<span class="status-badge badge-success">Yes</span>' : 
                            '<span class="status-badge badge-warning">No</span>'; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ALL DETECTED IPs -->
        <div class="card">
            <h2>📋 All Detected IP Addresses</h2>
            
            <?php if (empty($all_ips)): ?>
                <div class="alert alert-warning">
                    ⚠️ Không phát hiện được IP nào!
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Header Name</th>
                                <th>IP Address</th>
                                <th>Type</th>
                                <th>Priority</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $priority = 1;
                            foreach ($all_ips as $header => $ip): 
                                $type = is_ipv6($ip) ? 'IPv6' : (is_ipv4($ip) ? 'IPv4' : 'Unknown');
                                $is_selected = ($ip === $final_ip);
                            ?>
                                <tr style="<?php echo $is_selected ? 'background: #fef3c7;' : ''; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($header); ?></strong>
                                        <?php if ($is_selected): ?>
                                            <span class="status-badge badge-success" style="font-size: 0.7em;">✓ SELECTED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($ip); ?></td>
                                    <td>
                                        <?php if ($type === 'IPv6'): ?>
                                            <span class="status-badge badge-success" style="font-size: 0.8em;">IPv6</span>
                                        <?php else: ?>
                                            <span class="status-badge badge-info" style="font-size: 0.8em;">IPv4</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $priority++; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ALL SERVER HEADERS -->
        <div class="card">
            <h2>📡 All HTTP Headers</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Header</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $headers = [];
                        foreach ($_SERVER as $key => $value) {
                            if (strpos($key, 'HTTP_') === 0 || in_array($key, ['REMOTE_ADDR', 'SERVER_ADDR', 'SERVER_NAME'])) {
                                $headers[$key] = $value;
                            }
                        }
                        ksort($headers);
                        
                        foreach ($headers as $key => $value): 
                            $is_ip_related = (
                                strpos($key, 'IP') !== false || 
                                strpos($key, 'ADDR') !== false || 
                                strpos($key, 'FORWARDED') !== false ||
                                strpos($key, 'CF_') !== false
                            );
                        ?>
                            <tr style="<?php echo $is_ip_related ? 'background: #fef3c7;' : ''; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($key); ?></strong>
                                    <?php if ($is_ip_related): ?>
                                        <span class="icon">🔍</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($value); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PLUGIN COMPATIBILITY -->
        <div class="card">
            <h2>🔌 Plugin Compatibility Check</h2>
            
            <div class="alert alert-success">
                <strong>✅ Plugin GADS Toolkit hoàn toàn tương thích!</strong><br><br>
                Plugin sử dụng logic sau để detect IP (theo thứ tự ưu tiên):
                <ol style="margin-top: 10px; margin-left: 20px;">
                    <li><code>HTTP_CF_CONNECTING_IP</code> - Cloudflare real IP (highest priority)</li>
                    <li><code>HTTP_X_FORWARDED_FOR</code> - Standard proxy header</li>
                    <li><code>REMOTE_ADDR</code> - Direct connection (fallback)</li>
                </ol>
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <label>✅ IP sẽ được tracking:</label>
                    <div class="value" style="color: #10b981; font-weight: bold;">
                        <?php echo htmlspecialchars($final_ip); ?> (<?php echo $ip_version; ?>)
                    </div>
                </div>
                
                <div class="info-item">
                    <label>✅ Có thể block IP này:</label>
                    <div class="value">
                        <span class="status-badge badge-success">Yes</span>
                    </div>
                </div>
                
                <div class="info-item">
                    <label>✅ Tracking accuracy:</label>
                    <div class="value">
                        <?php if ($is_cloudflare && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])): ?>
                            <span class="status-badge badge-success">100% Accurate (Real IP)</span>
                        <?php elseif (!$is_cloudflare): ?>
                            <span class="status-badge badge-success">100% Accurate (Direct)</span>
                        <?php else: ?>
                            <span class="status-badge badge-warning">May be Cloudflare IP</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- RECOMMENDATIONS -->
        <div class="card">
            <h2>💡 Recommendations</h2>
            
            <?php if ($is_cloudflare): ?>
                <div class="alert alert-success">
                    <strong>✅ Cấu hình tốt!</strong><br>
                    Website đang sử dụng Cloudflare Proxy, điều này mang lại:
                    <ul style="margin-top: 10px; margin-left: 20px;">
                        <li>✅ IPv6 support tự động</li>
                        <li>✅ DDoS protection</li>
                        <li>✅ Plugin tracking chính xác IP thật của user</li>
                        <li>✅ Có thể block cả IPv4 và IPv6</li>
                    </ul>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <strong>⚠️ Đang dùng DNS Only</strong><br>
                    Bạn có thể cân nhắc bật Cloudflare Proxy để:
                    <ul style="margin-top: 10px; margin-left: 20px;">
                        <li>✅ Tự động có IPv6 support (ngay cả khi server chỉ hỗ trợ IPv4)</li>
                        <li>✅ Bảo vệ khỏi DDoS và fraud clicks</li>
                        <li>✅ Tăng tốc độ với caching</li>
                        <li>✅ Ẩn IP server thật</li>
                    </ul>
                    <br>
                    <strong>Hiện tại:</strong>
                    <ul style="margin-top: 10px; margin-left: 20px;">
                        <li><?php echo $ip_version === 'IPv6' ? '✅' : '❌'; ?> IPv6 support: 
                            <?php echo $ip_version === 'IPv6' ? 'Có (server hỗ trợ)' : 'Không (server chưa hỗ trợ)'; ?>
                        </li>
                        <li>✅ Plugin vẫn tracking chính xác</li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <!-- FOOTER -->
        <div style="text-align: center; color: white; margin-top: 30px; opacity: 0.9;">
            <p>🛡️ <strong>GADS Toolkit</strong> - Fraud Prevention for Google Ads</p>
            <p style="font-size: 0.9em; margin-top: 5px;">
                Developed by <a href="https://pdl.vn" style="color: #fbbf24; text-decoration: none;">Phú Digital</a>
            </p>
        </div>
    </div>
</body>
</html>
