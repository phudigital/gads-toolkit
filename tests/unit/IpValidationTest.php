<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

class IpValidationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        require_once dirname(__DIR__, 2) . '/includes/core-engine.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_ipv4_valid() {
        $this->assertTrue(tkgadm_validate_ip_pattern('192.168.1.1'));
        $this->assertTrue(tkgadm_validate_ip_pattern('8.8.8.8'));
    }

    public function test_ipv4_invalid() {
        $this->assertFalse(tkgadm_validate_ip_pattern('256.1.1.1'));
        $this->assertFalse(tkgadm_validate_ip_pattern('192.168.1'));
        $this->assertFalse(tkgadm_validate_ip_pattern('not.an.ip'));
    }

    public function test_ipv4_wildcard() {
        $this->assertTrue(tkgadm_validate_ip_pattern('192.168.1.*'));
        $this->assertTrue(tkgadm_validate_ip_pattern('*.*.*.*'));
        $this->assertFalse(tkgadm_validate_ip_pattern('192.168.*')); // Only 3 parts
    }

    public function test_ipv6() {
        $this->assertTrue(tkgadm_validate_ip_pattern('2001:0db8:85a3:0000:0000:8a2e:0370:7334'));
        $this->assertTrue(tkgadm_validate_ip_pattern('::1'));
        $this->assertFalse(tkgadm_validate_ip_pattern('2001:xyz::1'));
    }
}
