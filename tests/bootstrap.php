<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Mock WP core constants/functions if needed before tests
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
