<?php
/**
 * Central Service Configuration (SAMPLE)
 * 
 * This file contains centralized credentials for Google Ads API.
 * Rename this file to config.php and fill in your credentials.
 * Deploy to: https://your-domain.com/gads-toolkit/config.php
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
define('GADS_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID');
define('GADS_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
define('GADS_DEVELOPER_TOKEN', 'YOUR_GOOGLE_ADS_DEVELOPER_TOKEN');

/**
 * OAuth Redirect URI
 * Should match the URL where oauth/index.php is deployed
 */
define('GADS_OAUTH_REDIRECT_URI', 'https://your-domain.com/gads-toolkit/oauth/');

/**
 * API Security & Licensing
 * 
 * Manage API keys for clients here.
 * Format: 'KEY' => ['domain', 'expires_at', 'active']
 */
define('GADS_LICENSED_KEYS', [
    // Example Client
    'client_key_123456789' => [
        'domain'      => 'client-domain.com',
        'expires_at'  => '2025-12-31',
        'active'      => true
    ],
]);

// Fallback legacy key (Optional)
define('GADS_API_KEY', 'legacy_key_placeholder');

/**
 * Allowed Origins (CORS)
 * 
 * List of domains allowed to use this service
 * Leave empty to allow all (not recommended for production)
 */
define('GADS_ALLOWED_ORIGINS', [
    // 'https://client-domain.com',
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
