<?php
/** Public, license-independent WordPress updates. Loaded for admin and cron. */
if (!defined('ABSPATH')) { exit; }

define('GADS_UPDATE_BASE', 'https://gads.pdl.vn/updates/gads-toolkit');
define('GADS_UPDATE_CACHE', 'gads_toolkit_release_v1');

function tkgadm_update_release() {
    $cached = get_site_transient(GADS_UPDATE_CACHE);
    if (false !== $cached) { return is_array($cached) ? $cached : false; }
    // Cache failures briefly too; an outage must not slow every admin request.
    set_site_transient(GADS_UPDATE_CACHE, 'unavailable', 5 * MINUTE_IN_SECONDS);
    $response = wp_remote_get(GADS_UPDATE_BASE . '/latest.json', array(
        'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 65536,
        'user-agent' => 'GAds-Toolkit/' . GADS_TOOLKIT_VERSION,
    ));
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) { return false; }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) { return false; }
    foreach (array('version', 'requires', 'requires_php', 'sha256', 'package') as $key) {
        if (empty($data[$key]) || !is_string($data[$key])) { return false; }
    }
    if (!preg_match('/^\d+\.\d+\.\d+$/D', $data['version']) ||
        !preg_match('/^[a-f0-9]{64}$/D', $data['sha256']) ||
        !preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $data['requires']) ||
        !preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $data['requires_php']) ||
        $data['package'] !== GADS_UPDATE_BASE . '/download/' . $data['version'] . '/' . $data['sha256'] . '.zip') {
        return false;
    }
    set_site_transient(GADS_UPDATE_CACHE, $data, HOUR_IN_SECONDS);
    return $data;
}

function tkgadm_update_data($release) {
    return array(
        'id' => GADS_UPDATE_BASE, 'slug' => 'gads-toolkit',
        'plugin' => plugin_basename(GADS_TOOLKIT_PATH . 'gads-toolkit.php'),
        'version' => $release['version'], 'new_version' => $release['version'],
        'url' => 'https://gads.pdl.vn', 'package' => $release['package'],
        'requires' => $release['requires'], 'requires_php' => $release['requires_php'],
        'tested' => isset($release['tested']) ? $release['tested'] : '',
        'icons' => array(), 'banners' => array(),
    );
}

function tkgadm_update_hostname($update, $plugin_data, $plugin_file) {
    if ($plugin_file !== plugin_basename(GADS_TOOLKIT_PATH . 'gads-toolkit.php')) { return $update; }
    $release = tkgadm_update_release();
    return $release ? tkgadm_update_data($release) : false;
}
add_filter('update_plugins_gads.pdl.vn', 'tkgadm_update_hostname', 10, 3);

// WordPress < 5.8 has no Update URI hook. Preserve its existing support.
function tkgadm_update_legacy($transient) {
    if (!is_object($transient) || empty($transient->checked)) { return $transient; }
    $file = plugin_basename(GADS_TOOLKIT_PATH . 'gads-toolkit.php');
    if (!isset($transient->checked[$file])) { return $transient; }
    $release = tkgadm_update_release();
    // Never accept a same-slug WordPress.org package for this private updater.
    unset($transient->response[$file], $transient->no_update[$file]);
    if (!$release) { return $transient; }
    $bucket = version_compare($release['version'], $transient->checked[$file], '>') ? 'response' : 'no_update';
    if (!isset($transient->$bucket)) { $transient->$bucket = array(); }
    $transient->{$bucket}[$file] = (object) tkgadm_update_data($release);
    return $transient;
}
if (version_compare($GLOBALS['wp_version'], '5.8', '<')) {
    add_filter('pre_set_site_transient_update_plugins', 'tkgadm_update_legacy');
}

function tkgadm_update_details($result, $action, $args) {
    if ('plugin_information' !== $action || empty($args->slug) || 'gads-toolkit' !== $args->slug) { return $result; }
    $release = tkgadm_update_release();
    if (!$release) { return new WP_Error('gads_update_unavailable', 'Chưa thể tải thông tin cập nhật. Vui lòng thử lại sau.'); }
    $details = tkgadm_update_data($release);
    $details['name'] = 'GAds Toolkit';
    $details['author'] = '<a href="https://pdl.vn">Phú Digital</a>';
    $details['homepage'] = 'https://gads.pdl.vn';
    $details['download_link'] = $release['package'];
    $details['sections'] = array(
        'description' => 'Theo dõi và ngăn chặn click ảo Google Ads.',
        'changelog' => wpautop(esc_html(isset($release['changelog']) ? $release['changelog'] : '')),
    );
    return (object) $details;
}
add_filter('plugins_api', 'tkgadm_update_details', 10, 3);

// Verify the exact immutable package selected by WordPress, even if latest changed.
function tkgadm_update_download($reply, $package, $upgrader = null) {
    if (false !== $reply || !is_string($package)) { return $reply; }
    $pattern = '~^' . preg_quote(GADS_UPDATE_BASE, '~') . '/download/\d+\.\d+\.\d+/([a-f0-9]{64})\.zip$~D';
    if (!preg_match($pattern, $package, $matches)) { return $reply; }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $file = download_url($package, 120);
    if (is_wp_error($file)) { return $file; }
    if (!hash_equals($matches[1], hash_file('sha256', $file))) {
        wp_delete_file($file);
        return new WP_Error('gads_update_checksum', 'Gói cập nhật GAds Toolkit không khớp SHA-256. Đã dừng cập nhật.');
    }
    return $file;
}
add_filter('upgrader_pre_download', 'tkgadm_update_download', 10, 3);

function tkgadm_update_clear_cache() { delete_site_transient(GADS_UPDATE_CACHE); }
add_action('upgrader_process_complete', 'tkgadm_update_clear_cache');
// WordPress Dashboard > Updates > Check again also refreshes our own cache.
function tkgadm_update_force_check() {
    if (isset($_GET['force-check']) && current_user_can('update_plugins')) { tkgadm_update_clear_cache(); }
}
add_action('load-update-core.php', 'tkgadm_update_force_check', 1);
