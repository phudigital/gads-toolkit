<?php
if (!defined('GADS_UPDATER_TEST') || !GADS_UPDATER_TEST) { throw new Exception('Disposable test installation required'); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
function gads_assert($ok, $message) { if (!$ok) { throw new Exception($message); } echo "PASS: $message\n"; }
$manifest = json_decode(file_get_contents($args[0]), true);
$zip = dirname(dirname(dirname($args[0]))) . '/gads-toolkit-' . $manifest['version'] . '.zip';
$live = getenv('GADS_UPDATER_LIVE') === '1';
$mode = 'normal';
$calls = 0;
add_filter('pre_http_request', function ($pre, $options, $url) use ($manifest, $zip, $live, &$mode, &$calls) {
    // Keep this disposable install from contacting any service beyond the update API.
    if (strpos($url, 'api.wordpress.org/plugins/update-check/') !== false) {
        return array('response' => array('code' => 200), 'body' => '{"plugins":{},"no_update":{},"translations":[]}');
    }
    if ($url === GADS_UPDATE_BASE . '/latest.json') {
        $calls++;
        if ($mode === 'offline') { return new WP_Error('offline', 'Simulated outage'); }
        if ($mode === 'invalid') { return array('response' => array('code' => 200), 'body' => '{"version":"bad"}'); }
        if ($live) { return $pre; }
        return array('response' => array('code' => 200), 'body' => json_encode($manifest));
    }
    if (strpos($url, GADS_UPDATE_BASE . '/download/') === 0) {
        if ($mode !== 'corrupt' && $live) { return $pre; }
        file_put_contents($options['filename'], $mode === 'corrupt' ? 'corrupted zip' : file_get_contents($zip));
        return array('response' => array('code' => 200), 'headers' => array(), 'body' => '');
    }
    return new WP_Error('isolated', 'External traffic blocked in test');
}, 10, 3);
$file = 'gads-toolkit/gads-toolkit.php';
wp_set_current_user(1);
tkgadm_update_clear_cache();
$mode = 'offline';
gads_assert(tkgadm_update_release() === false, 'outage fails safely');
$count = $calls;
tkgadm_update_release();
gads_assert($calls === $count, 'failure is cached');
tkgadm_update_clear_cache(); $mode = 'invalid';
gads_assert(tkgadm_update_release() === false, 'invalid manifest rejected');
tkgadm_update_clear_cache(); $mode = 'normal';
gads_assert(tkgadm_update_release()['version'] === $manifest['version'], 'valid release accepted');
$count = $calls; tkgadm_update_release();
gads_assert($calls === $count, 'valid release cached');
gads_assert(tkgadm_update_hostname('untouched', array(), 'other/other.php') === 'untouched', 'other plugins untouched');
$details = plugins_api('plugin_information', (object) array('slug' => 'gads-toolkit'));
gads_assert(!is_wp_error($details) && $details->download_link === $manifest['package'], 'details popup has versioned download');
$legacy = tkgadm_update_legacy((object) array('checked' => array($file => '4.1.9')));
gads_assert($legacy->response[$file]->new_version === $manifest['version'], 'legacy WordPress update entry');
$current = tkgadm_update_legacy((object) array('checked' => array($file => $manifest['version'])));
gads_assert(isset($current->no_update[$file]) && !isset($current->response[$file]), 'current version never offered as upgrade');
$mode = 'corrupt';
$bad = tkgadm_update_download(false, $manifest['package']);
gads_assert(is_wp_error($bad) && $bad->get_error_code() === 'gads_update_checksum', 'corrupted ZIP blocked');
$mode = 'normal';
update_option('gads_updater_preservation_test', array('license' => 'test-only', 'settings' => 'keep-me'));
global $wpdb;
$wpdb->replace($wpdb->prefix . 'gads_toolkit_blocked', array('ip_address' => '192.0.2.10', 'reason' => 'updater-test'));
$before_rows = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'gads_toolkit_blocked', ARRAY_A);
gads_assert(count($before_rows) > 0, 'persisted plugin row seeded');
wp_clean_plugins_cache(true);
wp_update_plugins();
$updates = get_site_transient('update_plugins');
gads_assert(isset($updates->response[$file]) && $updates->response[$file]->new_version === $manifest['version'], 'native WordPress discovers update');
$upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
if (getenv('GADS_UPDATER_BACKGROUND') === '1') {
    add_filter('wp_doing_cron', '__return_true');
    $result = $upgrader->upgrade($file, array('clear_update_cache' => true));
    gads_assert($result === true, 'background WordPress Plugin_Upgrader completed');
} else {
    // Same bulk upgrade path used by the dashboard AJAX Update now action.
    $result = $upgrader->bulk_upgrade(array($file), array('clear_update_cache' => true));
    gads_assert(is_array($result) && !is_wp_error($result[$file]) && !empty($result[$file]), 'dashboard WordPress Plugin_Upgrader completed');
}
$installed = get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false);
gads_assert($installed['Version'] === $manifest['version'], 'installed header matches release');
gads_assert(get_option('gads_updater_preservation_test')['settings'] === 'keep-me', 'settings preserved');
gads_assert($wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'gads_toolkit_blocked', ARRAY_A) === $before_rows, 'plugin database rows preserved');
gads_assert(is_plugin_active($file), 'plugin remains active');
wp_clean_plugins_cache(true); wp_update_plugins();
gads_assert(!isset(get_site_transient('update_plugins')->response[$file]), 'update notice disappears after upgrade');
echo $live ? "LIVE UPGRADE PASS\n" : "FIXTURE UPGRADE PASS\n";
