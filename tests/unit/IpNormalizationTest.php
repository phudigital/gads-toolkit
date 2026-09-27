<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

class IpNormalizationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        require_once dirname(__DIR__, 2) . '/includes/module-google-ads.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_standard_ip() {
        $this->assertEquals('192.168.1.1', tkgadm_normalize_google_ads_ip('192.168.1.1'));
        $this->assertEquals('8.8.8.8', tkgadm_normalize_google_ads_ip('8.8.8.8'));
    }

    public function test_wildcard_ip() {
        $this->assertEquals('192.168.1.0/24', tkgadm_normalize_google_ads_ip('192.168.1.*'));
        $this->assertEquals('10.0.0.0/24', tkgadm_normalize_google_ads_ip('10.0.0.*'));
    }

    public function test_invalid_wildcard() {
        // Invalid octet
        $this->assertNull(tkgadm_normalize_google_ads_ip('256.168.1.*'));
        // Not enough octets for wildcard match regex
        $this->assertNull(tkgadm_normalize_google_ads_ip('192.168.*'));
    }

    public function test_invalid_ip() {
        $this->assertNull(tkgadm_normalize_google_ads_ip('not.an.ip'));
        $this->assertNull(tkgadm_normalize_google_ads_ip('999.999.999.999'));
    }
}
