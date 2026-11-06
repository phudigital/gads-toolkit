<?php
/**
 * IP helpers: trusted client IP detection, CIDR matching, subnet keys.
 *
 * Client IP rule (default "auto"): proxy headers are trusted only when the
 * direct peer (REMOTE_ADDR) is a known proxy, i.e. a Cloudflare edge address
 * (CF-Connecting-IP) or a private/loopback address (local reverse proxy such as
 * nginx in front of Apache). A visitor connecting directly can therefore never
 * choose their IP by sending X-Forwarded-For.
 */

if (!defined('ABSPATH')) exit;

/** Cloudflare edge ranges (https://www.cloudflare.com/ips/). */
function tkgadm_cloudflare_ranges() {
    return array(
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    );
}

/** True when $ip lies inside $cidr (IPv4 or IPv6). */
function tkgadm_ip_in_cidr($ip, $cidr) {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    list($subnet, $bits) = explode('/', $cidr, 2);
    $ip_bin = @inet_pton($ip);
    $subnet_bin = @inet_pton($subnet);
    if ($ip_bin === false || $subnet_bin === false || strlen($ip_bin) !== strlen($subnet_bin)) {
        return false;
    }
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    if ($bytes && substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = chr((0xff << (8 - $rest)) & 0xff);
    return (($ip_bin[$bytes] & $mask) === ($subnet_bin[$bytes] & $mask));
}

function tkgadm_ip_in_ranges($ip, array $ranges) {
    foreach ($ranges as $range) {
        if (tkgadm_ip_in_cidr($ip, $range)) {
            return true;
        }
    }
    return false;
}

/** Public, routable address (not private, loopback, link-local or reserved). */
function tkgadm_is_public_ip($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function tkgadm_server_header($name) {
    if (empty($_SERVER[$name]) || !is_string($_SERVER[$name])) {
        return '';
    }
    return trim(wp_unslash($_SERVER[$name]));
}

/** Right-most public address of X-Forwarded-For (closest hop to our proxy). */
function tkgadm_ip_from_forwarded_for($leftmost = false) {
    $header = tkgadm_server_header('HTTP_X_FORWARDED_FOR');
    if ($header === '') {
        return '';
    }
    $parts = array_map('trim', explode(',', $header));
    if (!$leftmost) {
        $parts = array_reverse($parts);
    }
    foreach ($parts as $candidate) {
        if (tkgadm_is_public_ip($candidate)) {
            return $candidate;
        }
    }
    return '';
}

/**
 * Real visitor IP.
 * Setting tkgadm_ip_source: auto (default) | remote_addr | cloudflare | proxy.
 */
function tkgadm_get_real_user_ip() {
    $remote = tkgadm_server_header('REMOTE_ADDR');
    $remote = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
    $mode = get_option('tkgadm_ip_source', 'auto');
    $cf = tkgadm_server_header('HTTP_CF_CONNECTING_IP');
    $cf = filter_var($cf, FILTER_VALIDATE_IP) ? $cf : '';

    $ip = $remote;
    if ($mode === 'cloudflare') {
        $ip = $cf ?: $remote;
    } elseif ($mode === 'proxy') {
        $ip = tkgadm_ip_from_forwarded_for(true) ?: ($cf ?: $remote);
    } elseif ($mode !== 'remote_addr' && $remote !== '') {
        if ($cf && tkgadm_ip_in_ranges($remote, tkgadm_cloudflare_ranges())) {
            $ip = $cf;
        } elseif (!tkgadm_is_public_ip($remote)) {
            // Local reverse proxy: trust its headers.
            $real = tkgadm_server_header('HTTP_X_REAL_IP');
            if ($cf) {
                $ip = $cf;
            } elseif ($real && filter_var($real, FILTER_VALIDATE_IP)) {
                $ip = $real;
            } else {
                $ip = tkgadm_ip_from_forwarded_for() ?: $remote;
            }
        }
    }

    if (function_exists('apply_filters')) {
        $ip = apply_filters('tkgadm_real_user_ip', $ip, $remote);
    }
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/**
 * Subnet key used for grouping: "a.b.c.*" for IPv4 (/24), "xxxx:xxxx:xxxx:xxxx::/64" for IPv6.
 * Returns '' for patterns that are already ranges or invalid.
 */
function tkgadm_subnet_key($ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.*';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = inet_pton($ip);
        $prefix = substr($bin, 0, 8) . str_repeat("\0", 8);
        return inet_ntop($prefix) . '/64';
    }
    return '';
}

/** True if a stored block pattern (exact IP, a.b.c.* or IPv6 /64) covers $ip. */
function tkgadm_pattern_matches_ip($pattern, $ip) {
    if ($pattern === $ip) {
        return true;
    }
    if (substr($pattern, -2) === '.*') {
        return strpos($ip, substr($pattern, 0, -1)) === 0 && substr_count($ip, '.') === 3;
    }
    if (strpos($pattern, '/') !== false) {
        return tkgadm_ip_in_cidr($ip, $pattern);
    }
    return false;
}
