<?php
/**
 * Central Service Configuration
 * 
 * This file contains centralized credentials for Google Ads API.
 * Deploy this to: https://pdl.vn/gads-toolkit/config.php
 * 
 * SECURITY: Make sure this file is NOT publicly accessible via web browser.
 * Add to .htaccess or nginx config to deny direct access.
 * 
 * @package Google Ads Toolkit - Central Service
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('GADS_CENTRAL_SERVICE')) {
    http_response_code(403);
    die('Direct access not allowed');
}

/**
 * Google Ads API Credentials
 * 
 * Get these from: https://console.cloud.google.com/
 */
define('GADS_CLIENT_ID', 'YOUR_CLIENT_ID_HERE');
define('GADS_CLIENT_SECRET', 'YOUR_CLIENT_SECRET_HERE');
define('GADS_DEVELOPER_TOKEN', 'YOUR_DEVELOPER_TOKEN_HERE');

/**
 * OAuth Redirect URI
 * Should match the URL where oauth/index.php is deployed
 */
define('GADS_OAUTH_REDIRECT_URI', 'https://pdl.vn/gads-toolkit/oauth/');

/**
 * API Security
 * 
 * API Key for authenticating requests from WordPress sites
 * Generate a strong random key: openssl rand -hex 32
 */
define('GADS_API_KEY', 'YOUR_SECURE_API_KEY_HERE');

/**
 * Allowed Origins (CORS)
 * 
 * List of domains allowed to use this service
 * Leave empty to allow all (not recommended for production)
 */
define('GADS_ALLOWED_ORIGINS', [
    // 'https://client-site1.com',
    // 'https://client-site2.com',
    // Add your WordPress site domains here
]);

/**
 * Rate Limiting
 * 
 * Maximum requests per IP per hour
 */
define('GADS_RATE_LIMIT_PER_HOUR', 100);

/**
 * Logging
 * 
 * Enable detailed logging for debugging
 */
define('GADS_ENABLE_LOGGING', true);
define('GADS_LOG_FILE', __DIR__ . '/logs/service.log');

/**
 * Google Ads API Version
 */
define('GADS_API_VERSION', 'v19');

/**
 * Session/State Settings
 */
define('GADS_STATE_EXPIRY_SECONDS', 3600); // 1 hour
