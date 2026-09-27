<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

// Define a stub for WP_Error to avoid fatal errors if it's not defined
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public function __construct($code = '', $message = '') {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_message() {
            return $this->message;
        }
    }
}

class GoogleAdsIdTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        require_once dirname(__DIR__, 2) . '/includes/module-google-ads.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_valid_ids() {
        $this->assertEquals('1234567890', tkgadm_validate_gads_id_format('1234567890'));
        $this->assertEquals('1234567890', tkgadm_validate_gads_id_format('123-456-7890'));
        $this->assertEquals('1234567890', tkgadm_validate_gads_id_format(' 123 456 7890 '));
    }

    public function test_empty_id() {
        $this->assertEquals('', tkgadm_validate_gads_id_format(''));
        $this->assertEquals('', tkgadm_validate_gads_id_format('   '));
    }

    public function test_invalid_ids() {
        $this->assertInstanceOf('WP_Error', tkgadm_validate_gads_id_format('12345'));
        $this->assertInstanceOf('WP_Error', tkgadm_validate_gads_id_format('abc-def-ghij'));
        $this->assertInstanceOf('WP_Error', tkgadm_validate_gads_id_format('12345678901')); // 11 digits
    }
}
