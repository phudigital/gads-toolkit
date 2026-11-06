<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class CoreEngineTest extends TestCase {

    private $mode = 'auto';

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return $key === 'tkgadm_ip_source' ? $this->mode : $default;
        });
        require_once dirname(__DIR__, 2) . '/includes/core-engine.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
        $_SERVER = [];
    }

    public function test_cloudflare_header_trusted_only_from_cloudflare_edge() {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '14.161.20.30';
        $_SERVER['REMOTE_ADDR'] = '172.70.1.2'; // Cloudflare 172.64.0.0/13
        $this->assertSame('14.161.20.30', tkgadm_get_real_user_ip());
    }

    public function test_spoofed_cloudflare_header_from_direct_visitor_is_ignored() {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '14.161.20.30';
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $this->assertSame('8.8.8.8', tkgadm_get_real_user_ip());
    }

    public function test_spoofed_forwarded_for_from_direct_visitor_is_ignored() {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1';
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $this->assertSame('8.8.8.8', tkgadm_get_real_user_ip());
    }

    public function test_local_reverse_proxy_uses_rightmost_public_forwarded_ip() {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 14.161.20.30, 10.0.0.5';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertSame('14.161.20.30', tkgadm_get_real_user_ip());
    }

    public function test_local_reverse_proxy_prefers_x_real_ip() {
        $_SERVER['HTTP_X_REAL_IP'] = '14.161.20.31';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        $this->assertSame('14.161.20.31', tkgadm_get_real_user_ip());
    }

    public function test_remote_addr_mode_ignores_all_headers() {
        $this->mode = 'remote_addr';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '14.161.20.30';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertSame('127.0.0.1', tkgadm_get_real_user_ip());
    }

    public function test_proxy_mode_uses_leftmost_public_forwarded_ip() {
        $this->mode = 'proxy';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '14.161.20.30, 9.9.9.9';
        $_SERVER['REMOTE_ADDR'] = '35.1.1.1';
        $this->assertSame('14.161.20.30', tkgadm_get_real_user_ip());
    }

    public function test_ipv6_cidr_and_subnet_keys() {
        $this->assertTrue(tkgadm_ip_in_cidr('2606:4700::1111', '2606:4700::/32'));
        $this->assertFalse(tkgadm_ip_in_cidr('2607:4700::1', '2606:4700::/32'));
        $this->assertSame('203.0.113.*', tkgadm_subnet_key('203.0.113.9'));
        $this->assertSame('2001:db8:1:2::/64', tkgadm_subnet_key('2001:db8:1:2:3:4:5:6'));
        $this->assertTrue(tkgadm_pattern_matches_ip('203.0.113.*', '203.0.113.200'));
        $this->assertFalse(tkgadm_pattern_matches_ip('203.0.113.*', '203.0.11.200'));
        $this->assertTrue(tkgadm_pattern_matches_ip('2001:db8:1:2::/64', '2001:db8:1:2::9'));
    }

    public function test_block_rules_are_validated() {
        $this->assertNull(tkgadm_sanitize_block_rule(['limit' => 0, 'duration' => 1, 'unit' => 'HOUR']));
        $this->assertSame(['limit' => 3, 'duration' => 2, 'unit' => 'HOUR'], tkgadm_sanitize_block_rule(['limit' => '3', 'duration' => '2', 'unit' => 'HOUR); DROP TABLE x;--']));
        $this->assertSame('WEEK', tkgadm_sanitize_block_rule(['limit' => 1, 'duration' => 1, 'unit' => 'week'])['unit']);
    }
}
