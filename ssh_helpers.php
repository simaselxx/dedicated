<?php
/**
 * SSH Panel Helpers - Shared utilities for Shahan, XPanel, Rocket, Dragon drivers
 * Used by: shahan.php, xpanel.php, rocket_ssh.php, dragon.php
 */

require_once __DIR__ . '/config.php';

/**
 * Get cookie file path for an SSH panel session
 */
function get_ssh_cookie_path($code_panel, $type) {
    return sys_get_temp_dir() . "/ssh_{$type}_{$code_panel}.txt";
}

/**
 * Check if a cached SSH panel session is still valid (< 3000 seconds old)
 */
function is_ssh_session_valid($panel_data) {
    if (empty($panel_data['datelogin'])) return false;
    $date = json_decode($panel_data['datelogin'], true);
    if (!isset($date['time'])) return false;
    $elapsed = time() - strtotime($date['time']);
    return ($elapsed <= 3000);
}

/**
 * Save SSH panel session info (cookie path + timestamp) to datelogin field
 */
function save_ssh_session($name_panel, $cookie_path) {
    $data = json_encode([
        'time' => date('Y/m/d H:i:s'),
        'access_token' => @file_get_contents($cookie_path) ?: ''
    ]);
    update("marzban_panel", "datelogin", $data, 'name_panel', $name_panel);
}

/**
 * Perform a cURL login request and save cookies
 * Returns: response body string or ['error' => message]
 */
function ssh_curl_login($url, $post_data, $cookie_path) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post_data),
        CURLOPT_COOKIEJAR => $cookie_path,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => 5000,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_ENCODING => '',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['error' => $error];
    }
    curl_close($ch);
    if ($httpCode > 302) {
        return ['error' => "HTTP $httpCode"];
    }
    return ['body' => $response, 'status' => $httpCode];
}

/**
 * Perform a cURL request with saved cookies
 * $method: GET, POST, PUT, DELETE
 * Returns: ['body' => ..., 'status' => ...] or ['error' => ...]
 */
function ssh_curl_request($url, $method = 'GET', $data = null, $cookie_path = null, $headers = []) {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => 8000,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ];
    if ($cookie_path) {
        $opts[CURLOPT_COOKIEFILE] = $cookie_path;
        $opts[CURLOPT_COOKIEJAR] = $cookie_path;
    }
    if (!empty($headers)) {
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }
    if ($data !== null) {
        if (is_array($data)) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
        } else {
            $opts[CURLOPT_POSTFIELDS] = $data;
        }
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['error' => $error];
    }
    curl_close($ch);
    return ['body' => $response, 'status' => $httpCode];
}

/**
 * Clean ANSI escape sequences from SSH output (used by Dragon)
 */
function clean_ansi($string) {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', ' ', $string);
}

/**
 * Parse SSH port info from panel's proxies JSON field
 * For rocket_ssh: fetches live ports from panel /settings page
 * Returns: ['ssh_port' => '22', 'udgpw' => '7300', 'dropbear' => '0']
 */
function parse_ssh_ports($panel) {
    $defaults = ['ssh_port' => '22', 'udgpw' => '0', 'dropbear' => '0'];
    // Rocket: fetch live ports from panel API
    if (isset($panel['type']) && $panel['type'] == 'rocket_ssh' && isset($panel['name_panel'])) {
        $live_ports = get_ports_rocket($panel['name_panel']);
        if (!empty($live_ports['ssh_port'])) {
            return $live_ports;
        }
    }
    if (empty($panel['proxies'])) return $defaults;
    $ports = json_decode($panel['proxies'], true);
    if (!is_array($ports)) return $defaults;
    return array_merge($defaults, $ports);
}

/**
 * Generate a random SSH password (12 chars, alphanumeric)
 */
function generate_ssh_password($length = 12) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Build standardized SSH service output array
 */
function build_ssh_service_info($host, $ports, $username, $password, $days, $traffic, $connection_limit) {
    return [
        'host' => $host,
        'ssh_port' => $ports['ssh_port'] ?? '22',
        'udgpw' => $ports['udgpw'] ?? '0',
        'dropbear' => $ports['dropbear'] ?? '0',
        'username' => $username,
        'password' => $password,
        'days' => $days,
        'traffic' => $traffic,
        'connection_limit' => $connection_limit,
    ];
}

/**
 * Generate NPVT SSH config link (base64 encoded)
 * Format: npvt-ssh://base64(json)
 */
function generate_npvt_link($username, $password, $host, $port = 22, $udgpw_port = 7300) {
    $config = [
        'sshConfigType' => 'SSH-Direct',
        'remarks' => $username,
        'sshHost' => $host,
        'sshPort' => intval($port),
        'sshUsername' => $username,
        'sshPassword' => $password,
        'sni' => '',
        'tlsVersion' => 'DEFAULT',
        'httpProxy' => '',
        'authenticateProxy' => false,
        'proxyUsername' => '',
        'proxyPassword' => '',
        'payload' => '',
        'dnsTTMode' => 'UDP',
        'dnsServer' => '',
        'nameserver' => '',
        'publicKey' => '',
        'udpgwPort' => intval($udgpw_port),
        'udpgwTransparentDNS' => true,
    ];
    $json = json_encode($config, JSON_UNESCAPED_SLASHES);
    return 'npvt-ssh://' . base64_encode($json);
}

/**
 * Get SSH host for customer display
 * For Dragon: url_panel is the SSH host directly
 * For HTTP panels: extract hostname from url_panel
 */
function get_ssh_display_host($panel) {
    $url = $panel['url_panel'] ?? '';
    if ($panel['type'] == 'dragon') {
        return $url;
    }
    $parsed = parse_url($url);
    return $parsed['host'] ?? $url;
}

/**
 * Get Jalali date string for N days from now
 * Used for Rocket date-based expiry
 * Uses gregorian_to_jalali() from jdf.php (already loaded)
 */
function get_jalali_expiry_date($days) {
    $timestamp = time() + ($days * 86400);
    $jalali = gregorian_to_jalali(
        intval(date('Y', $timestamp)),
        intval(date('m', $timestamp)),
        intval(date('d', $timestamp)),
        '/'
    );
    // Ensure format YYYY/MM/DD with leading zeros
    $parts = explode('/', $jalali);
    if (count($parts) == 3) {
        return sprintf('%04d/%02d/%02d', intval($parts[0]), intval($parts[1]), intval($parts[2]));
    }
    return $jalali;
}
