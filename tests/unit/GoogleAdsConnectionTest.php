<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class GoogleAdsConnectionTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('tkgadm_license_is_valid')->justReturn(true);
        require_once dirname(__DIR__, 2) . '/includes/module-google-ads.php';
    }
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }
    private function options($overrides = []) {
        $values = array_merge([
            'tkgadm_gads_customer_id' => '123-456-7890',
            'tkgadm_gads_refresh_token' => 'test-refresh',
            'tkgadm_central_service_url' => 'https://service.test',
            'tkgadm_central_service_api_key' => 'test-key',
        ], $overrides);
        Functions\when('get_option')->alias(function($key, $default = false) use ($values) {
            return $values[$key] ?? $default;
        });
    }
    public function test_oauth_connection_enables_manager_without_local_developer_token() {
        $this->options();
        $this->assertSame('central', tkgadm_get_gads_connection_mode());
    }
    public function test_incomplete_direct_credentials_fall_back_to_central() {
        $this->options(['tkgadm_gads_developer_token' => 'test-developer']);
        $this->assertSame('central', tkgadm_get_gads_connection_mode());
    }
    public function test_complete_direct_connection_is_preserved() {
        $this->options(['tkgadm_gads_developer_token' => 'test-developer', 'tkgadm_gads_client_id' => 'client', 'tkgadm_gads_client_secret' => 'secret']);
        $this->assertSame('direct', tkgadm_get_gads_connection_mode());
    }
    public function test_disconnected_account_stays_disabled() {
        $this->options(['tkgadm_gads_refresh_token' => '']);
        $this->assertSame('', tkgadm_get_gads_connection_mode());
    }
    public function test_invalid_customer_stays_disabled() {
        $this->options(['tkgadm_gads_customer_id' => '123']);
        $this->assertSame('', tkgadm_get_gads_connection_mode());
    }
    public function test_list_routes_to_central_without_requesting_a_direct_token() {
        $this->options();
        Functions\expect('tkgadm_get_google_access_token')->never();
        Functions\expect('tkgadm_list_ips_via_central_service')->once()->andReturn([['resource_name' => 'test', 'ip_address' => '203.0.113.1']]);
        $this->assertCount(1, tkgadm_list_connected_google_ads_ips());
    }
    public function test_delete_routes_to_central_without_requesting_a_direct_token() {
        $this->options();
        Functions\expect('tkgadm_get_google_access_token')->never();
        Functions\expect('tkgadm_remove_ips_via_central_service')->once()->with(['test'])->andReturn(true);
        $this->assertTrue(tkgadm_remove_connected_google_ads_ips(['test']));
    }
    private function localDatabase($ips, $error = '') {
        global $wpdb;
        $wpdb = new class($ips, $error) {
            public $prefix = 'wp_';
            public $last_error;
            private $ips;
            public function __construct($ips, $error) { $this->ips = $ips; $this->last_error = $error; }
            public function get_col($query) { return $this->ips; }
        };
        Functions\when('is_wp_error')->alias(function($value) { return $value instanceof WP_Error; });
    }
    public function test_full_sync_stops_before_google_when_local_read_fails() {
        $this->options();
        $this->localDatabase([], 'database unavailable');
        Functions\expect('tkgadm_list_connected_google_ads_ips')->never();
        Functions\expect('tkgadm_remove_connected_google_ads_ips')->never();
        $this->assertFalse(tkgadm_do_full_sync_google_ads()['success']);
    }
    public function test_full_sync_preserves_wildcards_for_upload_normalization() {
        $this->options();
        $this->localDatabase(['203.0.113.*', '203.0.113.*']);
        Functions\expect('tkgadm_list_connected_google_ads_ips')->once()->andReturn([['resource_name' => 'test']]);
        Functions\expect('tkgadm_remove_connected_google_ads_ips')->once()->with(['test'])->andReturn(true);
        Functions\expect('tkgadm_sync_ip_to_google_ads')->once()->with(['203.0.113.*'], true)->andReturn(['success' => true, 'message' => 'Synced']);
        $this->assertTrue(tkgadm_do_full_sync_google_ads()['success']);
    }
    public function test_full_sync_does_not_upload_after_unconfirmed_removal() {
        $this->options();
        $this->localDatabase(['203.0.113.1']);
        Functions\expect('tkgadm_list_connected_google_ads_ips')->once()->andReturn([['resource_name' => 'test']]);
        Functions\expect('tkgadm_remove_connected_google_ads_ips')->once()->andReturn(new WP_Error('timeout', 'timeout'));
        Functions\expect('tkgadm_sync_ip_to_google_ads')->never();
        $this->assertFalse(tkgadm_do_full_sync_google_ads()['success']);
    }
}
