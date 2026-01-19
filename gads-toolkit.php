<?php
/**
 * Plugin Name: Fraud Prevention for Google Ads
 * Plugin URI:  https://github.com/phudigital/gads-toolkit
 * Description: Giải pháp toàn diện giúp theo dõi và ngăn chặn click ảo (Fraud Click) từ Google Ads.
 * Version:     2.8.1
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
define('GADS_TOOLKIT_VERSION', '2.5.0');
define('GADS_TOOLKIT_PATH', plugin_dir_path(__FILE__));
define('GADS_TOOLKIT_URL', plugin_dir_url(__FILE__));

// Load core functions (database, tracking, helpers)
require_once GADS_TOOLKIT_PATH . 'includes/core-functions.php';

// Load AJAX handlers
require_once GADS_TOOLKIT_PATH . 'includes/ajax-functions.php';

// Load notification functions
require_once GADS_TOOLKIT_PATH . 'includes/notification-functions.php';

// Load admin pages
if (is_admin()) {
    require_once GADS_TOOLKIT_PATH . 'includes/admin-dashboard.php';
    require_once GADS_TOOLKIT_PATH . 'includes/admin-analytics.php';
    require_once GADS_TOOLKIT_PATH . 'includes/admin-maintenance.php';
    require_once GADS_TOOLKIT_PATH . 'includes/admin-notifications.php';
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
