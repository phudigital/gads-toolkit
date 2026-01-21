<?php
/**
 * Plugin Name: Fraud Prevention for Google Ads
 * Plugin URI:  https://github.com/phudigital/gads-toolkit
 * Description: Giải pháp toàn diện giúp theo dõi và ngăn chặn click ảo (Fraud Click) từ Google Ads.
 * Version:     3.6.11
 * Author:      Phú Digital
 * Author URI:  https://pdl.vn
 * License:     GPLv2 or later
 * Text Domain: gads-toolkit
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GADS_TOOLKIT_VERSION', '3.6.11');
define('GADS_TOOLKIT_PATH', plugin_dir_path(__FILE__));
define('GADS_TOOLKIT_URL', plugin_dir_url(__FILE__));

// --- CẤU HÌNH CENTRAL SERVICE (DÀNH CHO CLIENT) ---
// Thay đổi giá trị này trước khi gửi plugin cho khách hàng
define('GADS_SERVICE_URL', 'https://pdl.vn/gads-toolkit');
// define('GADS_API_KEY', 'YOUR_API_KEY'); // Đã tắt để cho phép nhập trong Admin
// --------------------------------------------------

// Load core functions (database, validation, helpers)
// 1. Core Engine (Database, Tracking, Auto-Block, Admin Init)
require_once GADS_TOOLKIT_PATH . 'includes/core-engine.php';

// 2. Functional Modules (API, Cron, Background Logic)
require_once GADS_TOOLKIT_PATH . 'includes/module-google-ads.php';
require_once GADS_TOOLKIT_PATH . 'includes/module-notifications.php';

// 3. Admin & Data Modules (Admin UI & AJAX)
if (is_admin()) {
    require_once GADS_TOOLKIT_PATH . 'includes/module-dashboard.php';
    require_once GADS_TOOLKIT_PATH . 'includes/module-data.php';
}

// Activation hook
register_activation_hook(__FILE__, 'tkgadm_activate_plugin');
function tkgadm_activate_plugin() {
    tkgadm_create_tables();
    tkgadm_schedule_notifications(); // Kích hoạt cron jobs
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'tkgadm_deactivate_plugin');
function tkgadm_deactivate_plugin() {
    tkgadm_unschedule_notifications(); // Hủy cron jobs
}
