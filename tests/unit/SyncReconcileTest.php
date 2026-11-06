<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class SyncReconcileTest extends TestCase {
    private $options;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('tkgadm_license_is_valid')->justReturn(true);
        Functions\when('tkgadm_is_ip_whitelisted')->justReturn(false);
        Functions\when('tkgadm_log_action')->justReturn(1);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('is_wp_error')->alias(function ($v) { return $v instanceof WP_Error; });
        $this->options = [
            'tkgadm_gads_customer_id' => '123-456-7890',
            'tkgadm_gads_refresh_token' => 'test-refresh',
            'tkgadm_central_service_url' => 'https://service.test',
            'tkgadm_central_service_api_key' => 'test-key',
            'tkgadm_gads_managed_ips' => [],
        ];
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
        require_once dirname(__DIR__, 2) . '/includes/module-google-ads.php';
        require_once dirname(__DIR__, 2) . '/includes/module-sync.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function local(array $ips, $error = '') {
        global $wpdb;
        $wpdb = new class($ips, $error) {
            public $prefix = 'wp_';
            public $last_error;
            private $ips;
            public function __construct($ips, $error) { $this->ips = $ips; $this->last_error = $error; }
            public function prepare($q, ...$a) { return $q; }
            public function get_col($q) { return $this->ips; }
            public function get_var($q) { return count($this->ips); }
        };
    }

    public function test_local_read_failure_never_touches_google() {
        $this->local([], 'database unavailable');
        Functions\expect('tkgadm_list_connected_google_ads_ips')->never();
        Functions\expect('tkgadm_remove_connected_google_ads_ips')->never();
        $this->assertFalse(tkgadm_reconcile_google_ads()['success']);
    }

    public function test_adds_missing_removes_stale_managed_and_keeps_manual_entries() {
        $this->local(['203.0.113.5', '198.51.100.*']);
        $this->options['tkgadm_gads_managed_ips'] = ['192.0.2.7'];
        Functions\expect('tkgadm_list_connected_google_ads_ips')->once()->andReturn([
            ['resource_name' => 'rn/manual', 'ip_address' => '8.8.8.8'],        // advertiser's own
            ['resource_name' => 'rn/stale', 'ip_address' => '192.0.2.7'],      // ours, unblocked locally
            ['resource_name' => 'rn/range', 'ip_address' => '198.51.100.0/24'], // already present
        ]);
        Functions\expect('tkgadm_remove_connected_google_ads_ips')->once()->with(['rn/stale'])->andReturn(true);
        Functions\expect('tkgadm_gads_create_ip_blocks')->once()->with(['203.0.113.5'])
            ->andReturn(['success' => true, 'message' => 'ok', 'added' => ['203.0.113.5']]);
        $result = tkgadm_reconcile_google_ads();
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['added']);
        $this->assertSame(1, $result['removed']);
        sort($this->options['tkgadm_gads_managed_ips']);
        $this->assertSame(['198.51.100.0/24', '203.0.113.5'], $this->options['tkgadm_gads_managed_ips']);
    }

    public function test_failed_removal_stops_before_upload() {
        $this->local(['203.0.113.5']);
        $this->options['tkgadm_gads_managed_ips'] = ['192.0.2.7'];
        Functions\expect('tkgadm_list_connected_google_ads_ips')->once()->andReturn([['resource_name' => 'rn/stale', 'ip_address' => '192.0.2.7']]);
        Functions\expect('tkgadm_remove_connected_google_ads_ips')->once()->andReturn(new WP_Error('timeout', 'timeout'));
        Functions\expect('tkgadm_gads_create_ip_blocks')->never();
        $this->assertFalse(tkgadm_reconcile_google_ads()['success']);
    }

    public function test_full_account_uploads_only_free_slots_newest_first() {
        $this->local(['203.0.113.1', '203.0.113.2']); // newest first
        $remote = [];
        for ($i = 0; $i < 499; $i++) {
            $remote[] = ['resource_name' => "rn/$i", 'ip_address' => '10.' . intdiv($i, 250) . '.' . ($i % 250) . '.1'];
        }
        Functions\expect('tkgadm_list_connected_google_ads_ips')->once()->andReturn($remote);
        Functions\expect('tkgadm_remove_connected_google_ads_ips')->never();
        Functions\expect('tkgadm_gads_create_ip_blocks')->once()->with(['203.0.113.1'])
            ->andReturn(['success' => true, 'message' => 'ok', 'added' => ['203.0.113.1']]);
        $result = tkgadm_reconcile_google_ads();
        $this->assertSame(1, $result['skipped']);
    }

    public function test_concurrent_run_is_refused() {
        Functions\when('get_transient')->justReturn(time());
        Functions\expect('tkgadm_list_connected_google_ads_ips')->never();
        $this->assertFalse(tkgadm_reconcile_google_ads()['success']);
    }

    public function test_central_upload_sends_wildcard_ranges_and_maps_results() {
        Functions\when('tkgadm_service_headers')->justReturn([]);
        Functions\when('wp_remote_retrieve_body')->alias(function ($r) { return $r['body']; });
        $sent = null;
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$sent) {
            $sent = json_decode($args['body'], true)['ips'];
            return ['body' => json_encode(['success' => true, 'data' => ['added' => ['1.2.3.0/24', '5.6.7.8']]])];
        });
        $result = tkgadm_sync_via_central_service(['1.2.3.0/24', '5.6.7.8']);
        $this->assertSame(['1.2.3.*', '5.6.7.8'], $sent);
        $this->assertSame(['1.2.3.0/24', '5.6.7.8'], $result['added']);
    }

    public function test_central_reply_without_data_adds_nothing() {
        Functions\when('tkgadm_service_headers')->justReturn([]);
        Functions\when('wp_remote_retrieve_body')->alias(function ($r) { return $r['body']; });
        Functions\when('wp_remote_post')->justReturn(['body' => json_encode(['success' => true, 'message' => 'No valid IPs to sync'])]);
        $this->assertSame([], tkgadm_sync_via_central_service(['5.6.7.8'])['added']);
    }

    public function test_ipv6_is_normalized_for_comparison() {
        $this->assertSame('2001:db8::1', tkgadm_normalize_google_ads_ip('2001:0DB8:0000:0000:0000:0000:0000:0001'));
        $this->assertSame('1.2.3.0/24', tkgadm_normalize_google_ads_ip('1.2.3.*'));
        $this->assertSame('1.2.3.0/24', tkgadm_normalize_google_ads_ip('1.2.3.0/24'));
    }
}
