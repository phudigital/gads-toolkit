<?php
/**
 * API Proxy Service
 * 
 * Deploy to: https://pdl.vn/gads-toolkit/api/index.php
 * 
 * Proxies requests to Google Ads API using centralized credentials.
 * WordPress sites only need to provide Customer ID and API key.
 * 
 * @package Google Ads Toolkit - Central Service
 * @version 1.0.0
 */

define('GADS_CENTRAL_SERVICE', true);
require_once dirname(__DIR__) . '/config.php';

// Set headers
header('Content-Type: application/json');

/**
 * Helper Functions
 */
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

function send_json_response($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data);
    exit;
}

function send_error($message, $code = 400) {
    log_message("API Error: {$message}", 'ERROR');
    send_json_response([
        'success' => false,
        'error' => $message
    ], $code);
}

/**
 * Verify API Key
 */
function verify_api_key() {
    $headers = getallheaders();
    $api_key = isset($headers['X-API-Key']) ? $headers['X-API-Key'] : '';
    
    if (empty($api_key)) {
        $api_key = isset($_GET['api_key']) ? $_GET['api_key'] : '';
    }
    
    // 1. Check Legacy/Master Key
    if ($api_key === GADS_API_KEY) {
        return true;
    }

    // 2. Check Licensed Keys
    if (defined('GADS_LICENSED_KEYS') && isset(GADS_LICENSED_KEYS[$api_key])) {
        $license = GADS_LICENSED_KEYS[$api_key];

        // Check Active Status
        if (empty($license['active'])) {
            send_error('License key is inactive. Please contact https://phu.vn to renew your license.', 403);
        }

        // Check Expiration
        if (!empty($license['expires_at'])) {
            $expiry = strtotime($license['expires_at']);
            if ($expiry < time()) {
                send_error('License key expired on ' . $license['expires_at'] . '. Please visit https://phu.vn to extend your subscription.', 403);
            }
        }

        // Valid License
        return true;
    }
    
    // 3. Fallback: Invalid
    log_message("Invalid API key attempt: {$api_key} from IP: " . $_SERVER['REMOTE_ADDR'], 'WARNING');
    send_error('Invalid API key. Please verify your key or buy a new license at https://phu.vn', 401);
}

/**
 * Check Rate Limit
 */
function check_rate_limit() {
    $ip = $_SERVER['REMOTE_ADDR'];
    $rate_file = dirname(GADS_LOG_FILE) . "/rate_{$ip}.txt";
    
    $current_hour = date('Y-m-d-H');
    $data = @file_get_contents($rate_file);
    
    if ($data) {
        list($stored_hour, $count) = explode('|', $data);
        
        if ($stored_hour === $current_hour) {
            if ($count >= GADS_RATE_LIMIT_PER_HOUR) {
                send_error('Rate limit exceeded. Please try again later.', 429);
            }
            $count++;
        } else {
            $count = 1;
        }
    } else {
        $count = 1;
    }
    
    @file_put_contents($rate_file, "{$current_hour}|{$count}");
}

/**
 * Get Access Token from Refresh Token
 */
function get_access_token($refresh_token) {
    $url = 'https://oauth2.googleapis.com/token';
    $data = [
        'client_id' => GADS_CLIENT_ID,
        'client_secret' => GADS_CLIENT_SECRET,
        'refresh_token' => $refresh_token,
        'grant_type' => 'refresh_token'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        $error_data = json_decode($response, true);
        $error_msg = isset($error_data['error_description']) ? $error_data['error_description'] : 'Failed to get access token';
        throw new Exception($error_msg);
    }
    
    $result = json_decode($response, true);
    return $result['access_token'];
}

/**
 * Exchange Authorization Code for Tokens
 */
function exchange_code_for_tokens($code) {
    $url = 'https://oauth2.googleapis.com/token';
    $data = [
        'code' => $code,
        'client_id' => GADS_CLIENT_ID,
        'client_secret' => GADS_CLIENT_SECRET,
        'redirect_uri' => GADS_OAUTH_REDIRECT_URI,
        'grant_type' => 'authorization_code'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        $error_data = json_decode($response, true);
        $error_msg = isset($error_data['error_description']) ? $error_data['error_description'] : 'Failed to exchange code';
        throw new Exception($error_msg);
    }
    
    return json_decode($response, true);
}

/**
 * Sync IPs to Google Ads
 */
function sync_ips_to_google_ads($customer_id, $manager_id, $refresh_token, $ips) {
    try {
        // Get access token
        $access_token = get_access_token($refresh_token);
        
        // Clean customer ID
        $customer_id = str_replace('-', '', $customer_id);
        
        // Prepare operations
        $operations = [];
        $skipped_count = 0;
        
        foreach ($ips as $ip) {
            $clean_ip = trim($ip);
            $is_valid = false;
            
            // Validate IP
            if (filter_var($clean_ip, FILTER_VALIDATE_IP)) {
                $is_valid = true;
            } elseif (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\*$/', $clean_ip)) {
                $is_valid = true;
            }
            
            if ($is_valid) {
                $operations[] = [
                    'create' => [
                        'type' => 'IP_BLOCK',
                        'ip_block' => [
                            'ip_address' => $clean_ip
                        ]
                    ]
                ];
            } else {
                $skipped_count++;
            }
        }
        
        if (empty($operations)) {
            return [
                'success' => true,
                'message' => $skipped_count > 0 
                    ? "No valid IPs to sync ({$skipped_count} skipped)" 
                    : "Empty IP list"
            ];
        }
        
        // Call Google Ads API
        $api_url = "https://googleads.googleapis.com/" . GADS_API_VERSION . "/customers/{$customer_id}/customerNegativeCriteria:mutate";
        
        $headers = [
            'Authorization: Bearer ' . $access_token,
            'developer-token: ' . GADS_DEVELOPER_TOKEN,
            'Content-Type: application/json'
        ];
        
        if (!empty($manager_id)) {
            $manager_id = str_replace('-', '', $manager_id);
            $headers[] = 'login-customer-id: ' . $manager_id;
        }
        
        $payload = [
            'operations' => $operations,
            'partialFailure' => true,
            'validateOnly' => false
        ];
        
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            $error_data = json_decode($response, true);
            $error_msg = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unknown error';
            
            if (isset($error_data['error']['details'])) {
                $error_msg .= ' (Details: ' . json_encode($error_data['error']['details']) . ')';
            }
            
            throw new Exception("Google API Error ({$http_code}): {$error_msg}");
        }
        
        $result = json_decode($response, true);
        $success_count = isset($result['results']) ? count($result['results']) : 0;
        
        $message = "Successfully synced {$success_count} IPs";
        if ($skipped_count > 0) {
            $message .= " ({$skipped_count} skipped)";
        }
        
        return [
            'success' => true,
            'message' => $message,
            'synced_count' => $success_count,
            'skipped_count' => $skipped_count
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Main API Router
 */
function handle_api_request() {
    // Verify API key
    verify_api_key();
    
    // Check rate limit
    check_rate_limit();
    
    // Get request method and action
    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    
    log_message("API Request: {$method} {$action} from IP: " . $_SERVER['REMOTE_ADDR'], 'INFO');
    
    // Handle different actions
    switch ($action) {
        case 'exchange_code':
            if ($method !== 'POST') {
                send_error('Method not allowed', 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $code = isset($input['code']) ? $input['code'] : '';
            
            if (empty($code)) {
                send_error('Authorization code is required');
            }
            
            try {
                $tokens = exchange_code_for_tokens($code);
                log_message("Successfully exchanged code for tokens", 'INFO');
                send_json_response([
                    'success' => true,
                    'data' => $tokens
                ]);
            } catch (Exception $e) {
                send_error($e->getMessage());
            }
            break;
            
        case 'sync_ips':
            if ($method !== 'POST') {
                send_error('Method not allowed', 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $customer_id = isset($input['customer_id']) ? $input['customer_id'] : '';
            $manager_id = isset($input['manager_id']) ? $input['manager_id'] : '';
            $refresh_token = isset($input['refresh_token']) ? $input['refresh_token'] : '';
            $ips = isset($input['ips']) ? $input['ips'] : [];
            
            if (empty($customer_id) || empty($refresh_token) || empty($ips)) {
                send_error('Missing required parameters: customer_id, refresh_token, ips');
            }
            
            $result = sync_ips_to_google_ads($customer_id, $manager_id, $refresh_token, $ips);
            
            if ($result['success']) {
                log_message("IP sync successful: {$result['message']}", 'INFO');
                send_json_response([
                    'success' => true,
                    'data' => $result
                ]);
            } else {
                send_error($result['error']);
            }
            break;
            
        case 'register_site':
            if ($method !== 'POST') {
                send_error('Method not allowed', 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $site_url = isset($input['site_url']) ? filter_var($input['site_url'], FILTER_SANITIZE_URL) : '';
            
            if (empty($site_url)) {
                send_error('Site URL is required');
            }
            
            // Validate URL format
            if (!filter_var($site_url, FILTER_VALIDATE_URL)) {
                send_error('Invalid Site URL format');
            }
            
            // Storage path (protected in logs folder)
            $clients_file = dirname(dirname(__FILE__)) . '/logs/clients.json';
            
            // Load existing clients
            $clients = [];
            if (file_exists($clients_file)) {
                $content = @file_get_contents($clients_file);
                if ($content) {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $clients = $decoded;
                    }
                }
            }
            
            // Add/Update client
            $clients[$site_url] = [
                'registered_at' => date('Y-m-d H:i:s'),
                'ip' => $_SERVER['REMOTE_ADDR'],
                'status' => 'active'
            ];
            
            // Save back to file with LOCK_EX to prevent race conditions
            if (@file_put_contents($clients_file, json_encode($clients, JSON_PRETTY_PRINT), LOCK_EX)) {
                log_message("New site registered: {$site_url}", 'INFO');
                send_json_response([
                    'success' => true,
                    'message' => 'Site registered for centralized heartbeat'
                ]);
            } else {
                send_error('Failed to save client registration', 500);
            }
            break;

        case 'get_credentials':
            // Return public credentials info (not secrets)
            send_json_response([
                'success' => true,
                'data' => [
                    'client_id' => GADS_CLIENT_ID,
                    'oauth_redirect_uri' => GADS_OAUTH_REDIRECT_URI,
                    'api_version' => GADS_API_VERSION
                ]
            ]);
            break;
            
        case 'health':
            // Health check endpoint
            send_json_response([
                'success' => true,
                'status' => 'healthy',
                'version' => '1.0.0',
                'timestamp' => time()
            ]);
            break;
            
        default:
            send_error('Invalid action. Available actions: exchange_code, sync_ips, get_credentials, health');
    }
}

// Execute API handler
handle_api_request();
