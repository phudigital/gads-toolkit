<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class CoreEngineTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        
        // Mock WP functions
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        
        // Load the file to test
        require_once dirname(__DIR__, 2) . '/includes/core-engine.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
        $_SERVER = []; // clean up
    }

    public function test_tkgadm_get_real_user_ip_cloudflare() {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        
        $ip = tkgadm_get_real_user_ip();
        $this->assertEquals('203.0.113.1', $ip);
    }
    
    public function test_tkgadm_get_real_user_ip_x_forwarded() {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        
        $ip = tkgadm_get_real_user_ip();
        $this->assertEquals('198.51.100.1', $ip);
    }
    
    public function test_tkgadm_get_real_user_ip_remote_addr() {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        
        $ip = tkgadm_get_real_user_ip();
        $this->assertEquals('192.0.2.1', $ip);
    }
}
