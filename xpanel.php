<?php
/**
 * XPanel SSH Panel Driver
 * Translated from: xssh/sshx.py (XPanel branches)
 * XPanel is Laravel-based and requires CSRF tokens on all POST requests
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ssh_helpers.php';
ini_set('error_log', 'error_log');

/**
 * Extract CSRF token from HTML <meta name="csrf-token">
 */
function get_csrf_xpanel($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $meta = $xpath->query("//meta[@name='csrf-token']");
    if ($meta->length > 0) {
        return $meta->item(0)->getAttribute('content');
    }
    // Fallback: search for _token input
    $input = $xpath->query("//input[@name='_token']");
    if ($input->length > 0) {
        return $input->item(0)->getAttribute('value');
    }
    return '';
}

/**
 * Login to XPanel, cache session for 3000 seconds
 * GET /login (for CSRF) then POST /login
 */
function login_xpanel($code_panel, $verify = true) {
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    $cookie_path = get_ssh_cookie_path($code_panel, 'xpanel');

    if ($verify && is_ssh_session_valid($panel)) {
        $date = json_decode($panel['datelogin'], true);
        if (!empty($date['access_token'])) {
            file_put_contents($cookie_path, $date['access_token']);
        }
        return $cookie_path;
    }

    $base_url = rtrim($panel['url_panel'], '/');

    // First GET the login page for CSRF token
    $get_result = ssh_curl_request($base_url . '/login', 'GET', null, $cookie_path);
    if (isset($get_result['error'])) return false;

    $token = get_csrf_xpanel($get_result['body']);

    $post_data = [
        '_token' => $token,
        'username' => $panel['username_panel'],
        'password' => $panel['password_panel']
    ];

    $result = ssh_curl_login($base_url . '/login', $post_data, $cookie_path);
    if (isset($result['error'])) return false;

    save_ssh_session($panel['name_panel'], $cookie_path);
    return $cookie_path;
}

/**
 * Get a fresh CSRF token from a page (needed for every POST in XPanel)
 */
function xpanel_get_token($panel, $cookie, $page = '/cp/users') {
    $base_url = rtrim($panel['url_panel'], '/');
    $result = ssh_curl_request($base_url . $page, 'GET', null, $cookie);
    if (isset($result['error'])) return '';
    return get_csrf_xpanel($result['body']);
}

/**
 * Create user on XPanel
 * POST /cp/users
 */
function add_user_xpanel($location, $username, $password, $traffic, $multiuser, $days, $desc = '', $first_login = true) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_xpanel($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $base_url = rtrim($panel['url_panel'], '/');
    $token = xpanel_get_token($panel, $cookie);

    if ($first_login) {
        // connection_start = days means "start from first login"
        $payload = [
            '_token' => $token,
            'username' => $username,
            'password' => $password,
            'email' => '',
            'mobile' => '',
            'multiuser' => $multiuser,
            'connection_start' => $days,
            'traffic' => $traffic,
            'type_traffic' => 'gb',
            'expdate' => '',
            'desc' => $desc,
        ];
    } else {
        $exp_days = $days - 1;
        $expdate = date('Y-m-d', time() + ($exp_days * 86400));
        $payload = [
            '_token' => $token,
            'username' => $username,
            'password' => $password,
            'email' => '',
            'mobile' => '',
            'multiuser' => $multiuser,
            'connection_start' => '',
            'traffic' => $traffic,
            'type_traffic' => 'gb',
            'expdate' => $expdate,
            'desc' => $desc,
        ];
    }

    $result = ssh_curl_request($base_url . '/cp/users', 'POST', $payload, $cookie);
    if (isset($result['error'])) return $result;

    if ($result['status'] <= 302) {
        return ['status' => true, 'msg' => 'User created successfully'];
    }
    return ['error' => "HTTP {$result['status']}"];
}

/**
 * Get user info from XPanel by parsing HTML from /cp/users
 */
function get_user_info_xpanel($username, $location) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_xpanel($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $base_url = rtrim($panel['url_panel'], '/');
    $result = ssh_curl_request($base_url . '/cp/users', 'GET', null, $cookie);
    if (isset($result['error'])) return $result;

    $html = $result['body'];
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);

    // XPanel shows users in a table - find user row
    $rows = $xpath->query("//table//tr");
    foreach ($rows as $row) {
        $cells = $xpath->query(".//td", $row);
        if ($cells->length < 5) continue;

        // Typically: username, traffic, multiuser, expdate, status, ...
        $row_username = trim($cells->item(0)->textContent);
        if ($row_username !== $username) continue;

        $traffic_text = trim($cells->item(1)->textContent ?? '');
        $multiuser = trim($cells->item(2)->textContent ?? '');
        $expdate = trim($cells->item(3)->textContent ?? '');
        $status_text = trim($cells->item(4)->textContent ?? '');

        $status = 'active';
        if (mb_strpos($status_text, 'غیرفعال') !== false || mb_strpos($status_text, 'deactive') !== false) {
            $status = 'disabled';
        }

        // Parse traffic
        $data_limit = null;
        if ($traffic_text !== '' && mb_strpos($traffic_text, 'نامحدود') === false && strtolower($traffic_text) !== 'unlimited') {
            $traffic_val = floatval(preg_replace('/[^0-9.]/', '', $traffic_text));
            $data_limit = intval($traffic_val * pow(1024, 3));
        }

        // Parse expire
        $expire = null;
        if (!empty($expdate) && $expdate !== '-') {
            $ts = strtotime($expdate);
            if ($ts !== false) $expire = $ts;
        }

        return [
            'status' => $status,
            'username' => $username,
            'password' => '',
            'data_limit' => $data_limit,
            'expire' => $expire,
            'days_left' => null,
            'connection_limit' => $multiuser,
            'used_traffic' => null,
        ];
    }

    return ['error' => 'User not found'];
}

/**
 * Delete user from XPanel
 * GET /cp/user/delete/{username}
 */
function remove_user_xpanel($location, $username) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_xpanel($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $base_url = rtrim($panel['url_panel'], '/');
    $result = ssh_curl_request($base_url . '/cp/user/delete/' . urlencode($username), 'GET', null, $cookie);
    if (isset($result['error'])) return $result;

    if ($result['status'] <= 302) {
        return ['status' => true, 'msg' => 'User deleted'];
    }
    return ['error' => "HTTP {$result['status']}"];
}

/**
 * Edit user on XPanel
 * POST /cp/user/edit
 */
function edit_user_xpanel($location, $username, array $data) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_xpanel($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $base_url = rtrim($panel['url_panel'], '/');
    $token = xpanel_get_token($panel, $cookie, '/cp/user/edit');

    $payload = [
        '_token' => $token,
        'username' => $username,
        'password' => $data['password'] ?? '',
        'email' => '',
        'mobile' => '',
        'multiuser' => $data['multiuser'] ?? $data['connection_limit'] ?? '1',
        'traffic' => $data['traffic'] ?? 0,
        'type_traffic' => 'gb',
        'expdate' => $data['expdate'] ?? '',
        'activate' => $data['activate'] ?? 'active',
        'desc' => $data['desc'] ?? '',
        'submit' => 'submit'
    ];

    $result = ssh_curl_request($base_url . '/cp/user/edit', 'POST', $payload, $cookie);
    if (isset($result['error'])) return $result;

    if ($result['status'] <= 302) {
        return ['status' => true, 'msg' => 'User updated'];
    }
    return ['error' => "HTTP {$result['status']}"];
}

/**
 * Enable user on XPanel
 * GET /cp/user/active/{username}
 */
function enable_user_xpanel($location, $username) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_xpanel($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $base_url = rtrim($panel['url_panel'], '/');
    $result = ssh_curl_request($base_url . '/cp/user/active/' . urlencode($username), 'GET', null, $cookie);
    if (isset($result['error'])) return $result;
    return ['status' => true, 'msg' => 'User enabled'];
}

/**
 * Disable user on XPanel
 * GET /cp/user/deactive/{username}
 */
function disable_user_xpanel($location, $username) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_xpanel($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $base_url = rtrim($panel['url_panel'], '/');
    $result = ssh_curl_request($base_url . '/cp/user/deactive/' . urlencode($username), 'GET', null, $cookie);
    if (isset($result['error'])) return $result;
    return ['status' => true, 'msg' => 'User disabled'];
}

/**
 * Reset traffic for user on XPanel
 * GET /cp/user/reset/{username}
 */
function reset_traffic_xpanel($location, $username) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_xpanel($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $base_url = rtrim($panel['url_panel'], '/');
    $result = ssh_curl_request($base_url . '/cp/user/reset/' . urlencode($username), 'GET', null, $cookie);
    if (isset($result['error'])) return $result;
    return ['status' => true, 'msg' => 'Traffic reset'];
}
