<?php
/**
 * Shahan SSH Panel Driver
 * Translated from: xssh/sshx.py (Shahan branches)
 * All operations via HTTP POST/GET to /p/newuser.php and /p/index.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ssh_helpers.php';
ini_set('error_log', 'error_log');

/**
 * Login to Shahan panel, cache session for 3000 seconds
 */
function login_shahan($code_panel, $verify = true) {
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    $cookie_path = get_ssh_cookie_path($code_panel, 'shahan');

    if ($verify && is_ssh_session_valid($panel)) {
        $date = json_decode($panel['datelogin'], true);
        if (!empty($date['access_token'])) {
            file_put_contents($cookie_path, $date['access_token']);
        }
        return $cookie_path;
    }

    $route = !empty($panel['secret_code']) ? $panel['secret_code'] : 'p';
    $login_url = rtrim($panel['url_panel'], '/') . "/{$route}/login.php";
    $post_data = [
        'username' => $panel['username_panel'],
        'password' => $panel['password_panel'],
        'loginsubmit' => ''
    ];

    $result = ssh_curl_login($login_url, $post_data, $cookie_path);
    if (isset($result['error'])) {
        return false;
    }

    save_ssh_session($panel['name_panel'], $cookie_path);
    return $cookie_path;
}

/**
 * Create user on Shahan panel
 * POST /p/newuser.php
 */
function add_user_shahan($location, $username, $password, $traffic, $multiuser, $days, $desc = '', $first_login = true) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_shahan($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $route = !empty($panel['secret_code']) ? $panel['secret_code'] : 'p';
    $url = rtrim($panel['url_panel'], '/') . "/{$route}/newuser.php";

    if ($traffic == 0) $traffic = '';

    $payload = [
        'newuserusername' => $username,
        'newuserpassword' => $password,
        'newusermobile' => '',
        'newuseremail' => '',
        'newusertraffic' => $traffic,
        'newusermultiuser' => $multiuser,
        'newuserfinishdate' => $days,
        'newuserreferral' => '',
        'newuserinfo' => $desc,
        'newusersubmit' => 'ثبت'
    ];

    if ($first_login) {
        $payload['newusertelegramid'] = '';
        $payload['newuserfirstlogin'] = 'newuserfirstlogin';
    }

    $result = ssh_curl_request($url, 'POST', $payload, $cookie);
    if (isset($result['error'])) return $result;

    if ($result['status'] == 200) {
        return ['status' => true, 'msg' => 'User created successfully'];
    }
    return ['error' => "HTTP {$result['status']}"];
}

/**
 * Get user info from Shahan panel by parsing HTML
 * GET /p/index.php
 */
function get_user_info_shahan($username, $location) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_shahan($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $route = !empty($panel['secret_code']) ? $panel['secret_code'] : 'p';
    $url = rtrim($panel['url_panel'], '/') . "/{$route}/index.php";

    $result = ssh_curl_request($url, 'GET', null, $cookie);
    if (isset($result['error'])) return $result;

    $html = $result['body'];
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);

    // Find all td elements with name attribute containing username
    $tds = $xpath->query("//td[@name]");
    $users_data = [];
    $current_user = null;

    foreach ($tds as $td) {
        $name = $td->getAttribute('name');
        $value = trim($td->textContent);

        if (strpos($name, 'username') !== false) {
            if ($current_user !== null) {
                $users_data[$current_user['username']] = $current_user;
            }
            $current_user = ['username' => $value];
        }
        if ($current_user === null) continue;

        if (strpos($name, 'password') !== false) $current_user['password'] = $value;
        if (strpos($name, 'traffic') !== false) $current_user['traffic'] = $value;
        if (strpos($name, 'multilogin') !== false) $current_user['connection_limit'] = $value;
        if (strpos($name, 'expire') !== false) $current_user['expire'] = $value;
        if (strpos($name, 'ip') !== false) $current_user['ip'] = $value;
        if (strpos($name, 'drop') !== false) $current_user['dropbear'] = $value;
        if (strpos($name, 'port') !== false && strpos($name, 'udpport') === false && strpos($name, 'panelport') === false) {
            $port_val = $value;
            // Parse UDGPW from port field (format: "7300badvpn..." or "7300localhost...")
            if (strpos($port_val, 'badvpn') !== false) {
                $current_user['udgpw'] = explode('badvpn', $port_val)[0];
            } elseif (strpos($port_val, 'localhost') !== false) {
                $current_user['udgpw'] = explode('localhost', $port_val)[0];
            } elseif (strpos($port_val, '127.0.0.1') !== false) {
                $current_user['udgpw'] = explode('127.0.0.1', $port_val)[0];
            } else {
                $parts = explode(' ', trim($port_val));
                $current_user['ssh_port'] = $parts[0] ?? '';
                if (isset($parts[1])) $current_user['udgpw'] = $parts[1];
            }
        }
    }
    if ($current_user !== null) {
        $users_data[$current_user['username']] = $current_user;
    }

    // Parse days remaining from non-named td elements
    $all_tds = $xpath->query("//td[not(@name)]");
    $days_list = [];
    foreach ($all_tds as $td) {
        $text = trim($td->textContent);
        if (mb_strpos($text, 'روز') !== false) {
            if (mb_strpos($text, 'گذشته') !== false) {
                $days_list[] = '-' . explode('روز', $text)[0];
            } else {
                $days_list[] = trim(explode('روز', $text)[0]);
            }
        } elseif ($text === 'نامحدود' && $td->ownerDocument->saveHTML($td) === '<td>نامحدود</td>') {
            $days_list[] = '9999';
        } elseif (mb_strpos($text, 'فعال نشده') !== false) {
            $days_list[] = 'inactive';
        }
    }

    // Match days to users by index
    $user_keys = array_keys($users_data);
    foreach ($user_keys as $i => $key) {
        if (isset($days_list[$i])) {
            $users_data[$key]['days_left'] = $days_list[$i];
        }
    }

    if (!isset($users_data[$username])) {
        return ['error' => 'User not found'];
    }

    $u = $users_data[$username];
    $status = 'active';
    if (isset($u['days_left'])) {
        if ($u['days_left'] === 'inactive') $status = 'on_hold';
        elseif (intval($u['days_left']) <= 0) $status = 'expired';
    }

    // Convert traffic to bytes
    $data_limit = null;
    if (!empty($u['traffic']) && $u['traffic'] !== 'نامحدود') {
        $traffic_val = str_replace(['گیگابایت', 'گیگ'], '', $u['traffic']);
        $traffic_val = floatval(trim($traffic_val));
        $data_limit = intval($traffic_val * pow(1024, 3));
    }

    return [
        'status' => $status,
        'username' => $username,
        'password' => $u['password'] ?? '',
        'data_limit' => $data_limit,
        'expire' => $u['expire'] ?? null,
        'days_left' => $u['days_left'] ?? null,
        'connection_limit' => $u['connection_limit'] ?? '1',
        'used_traffic' => null,
        'ip' => $u['ip'] ?? '',
        'udgpw' => $u['udgpw'] ?? '',
        'ssh_port' => $u['ssh_port'] ?? '',
        'dropbear' => $u['dropbear'] ?? '',
    ];
}

/**
 * Delete user from Shahan panel
 * POST /p/newuser.php with delusersubmit
 */
function remove_user_shahan($location, $username) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_shahan($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $route = !empty($panel['secret_code']) ? $panel['secret_code'] : 'p';
    $url = rtrim($panel['url_panel'], '/') . "/{$route}/newuser.php";

    $payload = [
        'edituserusername' => $username,
        'delusersubmit' => 'submitted H a m e d A p'
    ];

    $result = ssh_curl_request($url, 'POST', $payload, $cookie);
    if (isset($result['error'])) return $result;

    if ($result['status'] == 200) {
        return ['status' => true, 'msg' => 'User deleted'];
    }
    return ['error' => "HTTP {$result['status']}"];
}

/**
 * Edit/Renew user on Shahan panel
 * POST /p/newuser.php with editusersubmit
 */
function edit_user_shahan($location, $username, $password, $traffic, $multiuser, $days, $desc = '') {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_shahan($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $route = !empty($panel['secret_code']) ? $panel['secret_code'] : 'p';
    $url = rtrim($panel['url_panel'], '/') . "/{$route}/newuser.php";

    if ($traffic == 0 || $traffic === null) $traffic = '';

    $payload = [
        'edituserusername' => $username,
        'edituserpassword' => $password,
        'editusermobile' => '',
        'edituseremail' => '',
        'editusertraffic' => $traffic,
        'editusermultiuser' => $multiuser,
        'edituserfinishdate' => $days,
        'edituserreferral' => '',
        'edituserinfo' => $desc,
        'editusersubmit' => 'ثبت'
    ];

    // Debug log
    error_log("SHAHAN EDIT: url=$url user=$username payload=" . json_encode($payload, JSON_UNESCAPED_UNICODE));

    $result = ssh_curl_request($url, 'POST', $payload, $cookie);

    // Debug log result
    error_log("SHAHAN EDIT RESULT: status={$result['status']} body=" . substr($result['body'] ?? '', 0, 500));

    if (isset($result['error'])) return $result;

    // Also activate the user after editing
    $activate_payload = [
        'edituserusername' => $username,
        'edituserpassword' => $password,
        'activeusersubmit' => 'submitted H a m e d A p'
    ];
    ssh_curl_request($url, 'POST', $activate_payload, $cookie);

    if ($result['status'] == 200) {
        return ['status' => true, 'msg' => 'User updated'];
    }
    return ['error' => "HTTP {$result['status']}, body: " . substr($result['body'] ?? '', 0, 200)];
}

/**
 * Enable user on Shahan panel
 */
function enable_user_shahan($location, $username) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_shahan($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    // Get user password first
    $user_info = get_user_info_shahan($username, $location);
    if (isset($user_info['error'])) return $user_info;

    $route = !empty($panel['secret_code']) ? $panel['secret_code'] : 'p';
    $url = rtrim($panel['url_panel'], '/') . "/{$route}/newuser.php";

    $payload = [
        'edituserusername' => $username,
        'edituserpassword' => $user_info['password'],
        'activeusersubmit' => 'submitted H a m e d A p'
    ];

    $result = ssh_curl_request($url, 'POST', $payload, $cookie);
    if (isset($result['error'])) return $result;
    return ['status' => true, 'msg' => 'User enabled'];
}

/**
 * Disable user on Shahan panel
 */
function disable_user_shahan($location, $username) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_shahan($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $user_info = get_user_info_shahan($username, $location);
    if (isset($user_info['error'])) return $user_info;

    $route = !empty($panel['secret_code']) ? $panel['secret_code'] : 'p';
    $url = rtrim($panel['url_panel'], '/') . "/{$route}/newuser.php";

    $payload = [
        'edituserusername' => $username,
        'edituserpassword' => $user_info['password'],
        'deactiveusersubmit' => 'submitted H a m e d A p'
    ];

    $result = ssh_curl_request($url, 'POST', $payload, $cookie);
    if (isset($result['error'])) return $result;
    return ['status' => true, 'msg' => 'User disabled'];
}

/**
 * Reset traffic for user on Shahan panel
 */
function reset_traffic_shahan($location, $username) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_shahan($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $route = !empty($panel['secret_code']) ? $panel['secret_code'] : 'p';
    $url = rtrim($panel['url_panel'], '/') . "/{$route}/newuser.php";

    $payload = [
        'edituserusername' => $username,
        'resettrafficsubmit' => 'submitted H a m e d A p'
    ];

    $result = ssh_curl_request($url, 'POST', $payload, $cookie);
    if (isset($result['error'])) return $result;
    return ['status' => true, 'msg' => 'Traffic reset'];
}

/**
 * Get SSH/UDGPW ports from Shahan settings page
 * GET /p/setting.php
 */
function get_ports_shahan($location) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_shahan($panel['code_panel']);
    if (!$cookie) return parse_ssh_ports($panel);

    $route = !empty($panel['secret_code']) ? $panel['secret_code'] : 'p';
    $url = rtrim($panel['url_panel'], '/') . "/{$route}/setting.php";

    $result = ssh_curl_request($url, 'GET', null, $cookie);
    if (isset($result['error'])) return parse_ssh_ports($panel);

    $dom = new DOMDocument();
    @$dom->loadHTML($result['body']);
    $xpath = new DOMXPath($dom);

    $ports = ['ssh_port' => '', 'udgpw' => '0', 'dropbear' => '0'];

    // Parse port input
    $port_input = $xpath->query("//input[@name='port']");
    if ($port_input->length > 0) {
        $ports['ssh_port'] = $port_input->item(0)->getAttribute('value');
    }

    // Parse UDP port (UDGPW)
    $udp_input = $xpath->query("//input[@name='udpport']");
    if ($udp_input->length > 0) {
        $udp_val = $udp_input->item(0)->getAttribute('value');
        // Strip badvpn/localhost/127.0.0.1 prefix
        foreach (['badvpn', 'localhost', '127.0.0.1'] as $prefix) {
            if (strpos($udp_val, $prefix) !== false) {
                $udp_val = explode($prefix, $udp_val)[0];
                break;
            }
        }
        // Also try pipe separator
        if (strpos($udp_val, '|') !== false) {
            $udp_val = explode('|', $udp_val)[0];
        }
        $ports['udgpw'] = trim($udp_val);
    }

    // Parse dropbear port
    $drop_input = $xpath->query("//input[@name='dropport']");
    if ($drop_input->length > 0) {
        $ports['dropbear'] = $drop_input->item(0)->getAttribute('value');
    }

    return $ports;
}
