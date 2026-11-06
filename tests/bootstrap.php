<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Mock WP core constants/functions if needed before tests
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $message;
        public function __construct($code = '', $message = '') { $this->message = $message; }
        public function get_error_message() { return $this->message; }
    }
}
