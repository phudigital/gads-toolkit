<?php
/**
 * OAuth Redirect Handler
 * 
 * Deploy to: https://pdl.vn/gads-toolkit/oauth/index.php
 * 
 * Handles Google OAuth callbacks and redirects back to WordPress sites
 * with authorization code.
 * 
 * @package Google Ads Toolkit - Central Service
 * @version 1.0.0
 */

define('GADS_CENTRAL_SERVICE', true);
require_once dirname(__DIR__) . '/config.php';

/**
 * Helper Functions
 */
function sanitize_input($str) {
    return htmlspecialchars(strip_tags($str), ENT_QUOTES, 'UTF-8');
}

function add_query_arg($args, $url) {
    $parsed = parse_url($url);
    $query = isset($parsed['query']) ? $parsed['query'] : '';
    
    parse_str($query, $query_params);
    $query_params = array_merge($query_params, $args);
    
    $new_query = http_build_query($query_params);
    
    $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
    $host = isset($parsed['host']) ? $parsed['host'] : '';
    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    $path = isset($parsed['path']) ? $parsed['path'] : '';
    $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
    
    return $scheme . $host . $port . $path . '?' . $new_query . $fragment;
}

function log_message($message, $level = 'INFO') {
    if (!GADS_ENABLE_LOGGING) return;
    
    $log_dir = dirname(GADS_LOG_FILE);
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$level}] {$message}\n";
    @file_put_contents(GADS_LOG_FILE, $log_entry, FILE_APPEND);
}

function display_error($title, $message) {
    log_message("Error: {$title} - {$message}", 'ERROR');
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title); ?> - Google Ads Toolkit</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .error-container {
                background: white;
                border-radius: 12px;
                padding: 40px;
                max-width: 500px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                text-align: center;
            }
            .error-icon {
                font-size: 64px;
                margin-bottom: 20px;
            }
            h1 {
                color: #333;
                font-size: 24px;
                margin-bottom: 15px;
            }
            p {
                color: #666;
                line-height: 1.6;
                margin-bottom: 25px;
            }
            .btn {
                display: inline-block;
                background: #667eea;
                color: white;
                padding: 12px 30px;
                border-radius: 6px;
                text-decoration: none;
                font-weight: 500;
                transition: background 0.3s;
            }
            .btn:hover {
                background: #5568d3;
            }
            .footer {
                margin-top: 20px;
                font-size: 12px;
                color: #999;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1><?php echo htmlspecialchars($title); ?></h1>
            <p><?php echo htmlspecialchars($message); ?></p>
            <a href="javascript:window.close();" class="btn">Close Window</a>
            <div class="footer">Google Ads Toolkit Central Service</div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Main OAuth Handler
 */
function handle_oauth_redirect() {
    // Get parameters from Google OAuth callback
    $code = isset($_GET['code']) ? sanitize_input($_GET['code']) : '';
    $state = isset($_GET['state']) ? sanitize_input($_GET['state']) : '';
    $error = isset($_GET['error']) ? sanitize_input($_GET['error']) : '';
    
    log_message("OAuth callback received - Code: " . ($code ? 'present' : 'missing') . ", State: " . ($state ? 'present' : 'missing'));
    
    // Decode state parameter
    $state_data = json_decode(base64_decode($state), true);
    
    if (!$state_data || !isset($state_data['return_url'])) {
        display_error('Invalid State', 'State parameter is missing or invalid. Please try again from your WordPress admin.');
        return;
    }
    
    $return_url = $state_data['return_url'];
    
    // Validate return URL
    if (!filter_var($return_url, FILTER_VALIDATE_URL)) {
        display_error('Invalid Return URL', 'The return URL is not valid. Please contact support.');
        return;
    }
    
    // Check Allowed Origins (Licensed Domains + Legacy Whitelist)
    $parsed_url = parse_url($return_url);
    $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
    $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] : 'https';
    $origin = $scheme . '://' . $host;
    $is_allowed = false;

    // 1. Check Licensed Domains
    if (defined('GADS_LICENSED_KEYS')) {
        foreach (GADS_LICENSED_KEYS as $key => $license) {
            // Skip inactive or expired
            if (empty($license['active'])) continue;
            if (!empty($license['expires_at']) && strtotime($license['expires_at']) < time()) continue;

            $lic_domain = isset($license['domain']) ? $license['domain'] : '';
            
            // Wildcard or exact match
            if ($lic_domain === '*' || $lic_domain === $host) {
                $is_allowed = true;
                break;
            }
        }
    }

    // 2. Check Legacy Allowed Origins (Fallback)
    if (!$is_allowed && defined('GADS_ALLOWED_ORIGINS') && !empty(GADS_ALLOWED_ORIGINS)) {
        if (in_array($origin, GADS_ALLOWED_ORIGINS)) {
            $is_allowed = true;
        }
    }

    // If GADS_ALLOWED_ORIGINS is empty array and no licensed keys match, strictly block?
    // Current config.php has an empty array comment, implying tight security.
    // If NO whitelist is defined at all (not even empty array), maybe allow all? 
    // Convention: If defined, enforce it.
    
    if (defined('GADS_ALLOWED_ORIGINS') || defined('GADS_LICENSED_KEYS')) {
        if (!$is_allowed) {
            log_message("Blocked unauthorized origin: {$origin}", 'WARNING');
            display_error('Unauthorized Origin', 'This website is not authorized to use the Central OAuth Service. License expired or invalid. Please renew your license at https://phu.vn');
            return;
        }
    }
    
    // Handle OAuth error
    if ($error) {
        $error_description = isset($_GET['error_description']) ? urldecode($_GET['error_description']) : 'Unknown error';
        log_message("OAuth error: {$error} - {$error_description}", 'ERROR');
        
        $redirect_url = add_query_arg([
            'oauth_error' => $error,
            'oauth_error_description' => urlencode($error_description)
        ], $return_url);
        
        header('Location: ' . $redirect_url);
        exit;
    }
    
    // Handle successful authorization
    if ($code) {
        log_message("OAuth success, redirecting to: {$return_url}", 'INFO');
        
        // Add code to return URL
        $redirect_url = add_query_arg([
            'code' => $code,
            'oauth_success' => '1'
        ], $return_url);
        
        header('Location: ' . $redirect_url);
        exit;
    }
    
    // No code and no error
    display_error('No Authorization Code', 'No authorization code was received from Google. Please try again.');
}

// Execute handler
handle_oauth_redirect();
