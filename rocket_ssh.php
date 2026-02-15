<?php
/**
 * Rocket SSH Panel Driver
 * Translated from: xssh/sshx.py (Rocket branches)
 * Rocket uses JSON API (/ajax/...) unlike Shahan/XPanel HTML
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ssh_helpers.php';

/**
 * Login to Rocket panel, cache session for 3000 seconds
 * POST /ajax/login
 */
function login_rocket($code_panel, $verify = true) {
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    $cookie_path = get_ssh_cookie_path($code_panel, 'rocket');

    if ($verify && is_ssh_session_valid($panel)) {
        $date = json_decode($panel['datelogin'], true);
        if (!empty($date['access_token'])) {
            file_put_contents($cookie_path, $date['access_token']);
        }
        return $cookie_path;
    }

    $base_url = rtrim($panel['url_panel'], '/');
    $post_data = [
        'username' => $panel['username_panel'],
        'password' => $panel['password_panel'],
        'remember' => ''
    ];

    $result = ssh_curl_login($base_url . '/ajax/login', $post_data, $cookie_path);
    if (isset($result['error'])) return false;

    save_ssh_session($panel['name_panel'], $cookie_path);
    return $cookie_path;
}

/**
 * Get all users list from Rocket panel (JSON)
 * POST /ajax/users/list
 * Note: Response may contain <br> tag before JSON
 */
function get_users_rocket($location) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_rocket($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $base_url = rtrim($panel['url_panel'], '/');
    $result = ssh_curl_request($base_url . '/ajax/users/list', 'POST', [], $cookie);
    if (isset($result['error'])) return $result;

    $body = $result['body'];
    // Clean <br> tags before JSON (known Rocket issue)
    if (strpos($body, '<br') !== false) {
        $body = explode('<br', $body)[0];
    }

    $data = json_decode($body, true);
    if (!$data || !isset($data['data'])) {
        return ['error' => 'Invalid response from Rocket panel'];
    }

    return $data;
}

/**
 * Find user ID and info from Rocket users list
 * Like xssh: also fetches exp_days from edit form for days-based accounts
 */
function get_user_info_rocket($username, $location) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $users = get_users_rocket($location);
    if (isset($users['error'])) return $users;

    foreach ($users['data'] as $user) {
        if ($user['username'] === $username) {
            $status = 'active';
            if (isset($user['status'])) {
                if (mb_strpos($user['status'], 'غیرفعال') !== false) $status = 'disabled';
                if (mb_strpos($user['status'], 'منقضی') !== false) $status = 'expired';
            }
            if (isset($user['is_active']) && !$user['is_active']) $status = 'disabled';

            // Parse traffic
            $data_limit = null;
            $traffic_raw = $user['traffic'] ?? 0;
            if (isset($user['traffic']) && $user['traffic'] > 0) {
                $data_limit = intval($user['traffic'] * pow(1024, 3));
            }

            // Parse used traffic
            $used_traffic = null;
            if (isset($user['usage'])) {
                $used_traffic = intval($user['usage'] * pow(1024, 3));
            }

            // Get end_date and remaining_days from list
            $end_date = $user['end_date'] ?? '';
            $days_left = $user['remaining_days'] ?? $user['exp_days'] ?? null;
            $desc = $user['desc'] ?? '';

            // For days-based accounts (end_date == 0): fetch exp_days from edit form (like xssh)
            $is_days_based = (empty($end_date) || $end_date == '0' || $end_date === 0);
            if ($is_days_based) {
                $cookie = login_rocket($panel['code_panel']);
                if ($cookie) {
                    $base_url = rtrim($panel['url_panel'], '/');
                    $edit_url = $base_url . '/ajax-views/users/' . $user['id'] . '/edit?_=' . time();
                    $edit_result = ssh_curl_request($edit_url, 'GET', null, $cookie);
                    if (!isset($edit_result['error']) && $edit_result['status'] == 200) {
                        $edit_json = json_decode($edit_result['body'], true);
                        if (isset($edit_json['html'])) {
                            $html = $edit_json['html'];
                            // Parse exp_days input value
                            if (preg_match('/name=["\']exp_days["\'][^>]*value=["\']([^"\']+)["\']/', $html, $m)) {
                                $days_left = $m[1];
                            } elseif (preg_match('/value=["\']([^"\']+)["\'][^>]*name=["\']exp_days["\']/', $html, $m)) {
                                $days_left = $m[1];
                            }
                            // Also get description from textarea
                            if (preg_match('/<textarea[^>]*name=["\']desc["\'][^>]*>([^<]*)<\/textarea>/', $html, $m)) {
                                $desc = $m[1];
                            }
                        }
                    }
                }
            }

            // Parse expiry timestamp
            $expire = null;
            if (!$is_days_based && !empty($end_date)) {
                // Date-based: parse end_date (Jalali date string)
                // Try to convert Jalali to timestamp if possible
                $ts = strtotime($end_date);
                if ($ts !== false && $ts > 0) $expire = $ts;
            } elseif ($is_days_based && $days_left !== null && is_numeric($days_left) && intval($days_left) > 0 && intval($days_left) < 9999) {
                // Days-based: calculate expire from days_left
                $expire = time() + (intval($days_left) * 86400);
            }
            // If days_left >= 9999 or null/0 with no end_date → unlimited (expire stays null)

            // Check online status from online_users array
            $online_users = $user['online_users'] ?? [];
            $is_online = !empty($online_users);
            $online_at = null;
            if ($is_online) {
                // User is currently online
                $online_at = time();
            }

            return [
                'status' => $status,
                'username' => $username,
                'password' => $user['password'] ?? '',
                'data_limit' => $data_limit,
                'expire' => $expire,
                'days_left' => $days_left,
                'connection_limit' => $user['limit_users'] ?? '1',
                'used_traffic' => $used_traffic,
                'uid' => $user['id'],
                'kind' => $is_days_based ? 'days' : 'expiry',
                'date' => $end_date,
                'desc' => $desc,
                'traffic_raw' => $traffic_raw,
                'online_at' => $online_at,
                'is_online' => $is_online,
                'online_count' => count($online_users),
            ];
        }
    }

    return ['error' => 'User not found'];
}

/**
 * Create user on Rocket panel
 * POST /ajax/users
 */
function add_user_rocket($location, $username, $password, $traffic, $limit_users, $days, $desc = '', $first_login = true) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_rocket($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $base_url = rtrim($panel['url_panel'], '/');
    $days = intval($days);
    $traffic = intval($traffic);
    $limit_users = intval($limit_users);

    if ($first_login) {
        // Mode: after first connect - use days
        $payload = [
            'username' => $username,
            'password' => $password,
            'email' => '',
            'mobile' => '',
            'limit_users' => strval($limit_users),
            'traffic' => strval($traffic),
            'expiry_type' => 'days',
            'exp_days' => strval($days),
            'exp_date' => '',
            'desc' => $desc,
        ];
    } else {
        // Mode: date-based - calculate Jalali expiry date
        $jalali_date = get_jalali_expiry_date($days);
        $payload = [
            'username' => $username,
            'password' => $password,
            'email' => '',
            'mobile' => '',
            'limit_users' => strval($limit_users),
            'traffic' => strval($traffic),
            'expiry_type' => 'date',
            'exp_days' => '',
            'exp_date' => $jalali_date,
            'desc' => $desc,
        ];
    }

    $result = ssh_curl_request($base_url . '/ajax/users', 'POST', $payload, $cookie);
    if (isset($result['error'])) return $result;

    // Retry with +1 day if date is in the past (Rocket validation)
    if ($result['status'] == 200) {
        $body = $result['body'] ?? '';
        if (!$first_login && strpos($body, 'تاریخ پایان نمی تواند کوچکتر از تاریخ فعلی باشد') !== false) {
            $jalali_date = get_jalali_expiry_date($days + 1);
            $payload['exp_date'] = $jalali_date;
            $result = ssh_curl_request($base_url . '/ajax/users', 'POST', $payload, $cookie);
            if (isset($result['error'])) return $result;
        }
    }

    if ($result['status'] == 200) {
        return ['status' => true, 'msg' => 'User created successfully'];
    }
    return ['error' => "HTTP {$result['status']}"];
}

/**
 * Delete user from Rocket panel
 * First find user ID from list, then DELETE /ajax/users/{id}
 */
function remove_user_rocket($location, $username) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_rocket($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    // Get user ID first
    $user_info = get_user_info_rocket($username, $location);
    if (isset($user_info['error'])) return $user_info;
    if (!isset($user_info['uid'])) return ['error' => 'User ID not found'];

    $base_url = rtrim($panel['url_panel'], '/');
    $result = ssh_curl_request($base_url . '/ajax/users/' . $user_info['uid'], 'DELETE', null, $cookie);
    if (isset($result['error'])) return $result;

    if ($result['status'] == 200) {
        return ['status' => true, 'msg' => 'User deleted'];
    }
    return ['error' => "HTTP {$result['status']}"];
}

/**
 * Edit/Renew user on Rocket panel
 * PUT /ajax/users/{uid}
 */
function edit_user_rocket($location, $username, array $data) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_rocket($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    // Get user ID and current info from API
    $user_info = get_user_info_rocket($username, $location);
    if (isset($user_info['error'])) return $user_info;

    $base_url = rtrim($panel['url_panel'], '/');

    // Get raw values from user_info (exactly like xssh Python does)
    // Traffic: use raw GB value (0 = unlimited)
    $traffic = $data['traffic'] ?? $user_info['traffic_raw'] ?? 0;

    // Connection limit
    $limit_users = $data['connection_limit'] ?? $user_info['connection_limit'] ?? 1;

    // Password: new or current
    $password = $data['password'] ?? $user_info['password'];

    // Description
    $desc = $data['desc'] ?? $user_info['desc'] ?? '';

    // Expiry: check if date-based (end_date != 0) or days-based
    // In xssh: kind = "days" if end_date == 0, else "expiry"
    $end_date = $user_info['date'] ?? '';
    $is_days_based = (empty($end_date) || $end_date == '0' || $end_date == 0);

    // Days value: prefer from $data (renewal), fallback to API
    $days = isset($data['days']) ? intval($data['days']) : ($user_info['days_left'] ?? 30);

    // For date-based: calculate new Jalali date from days
    $new_jalali_date = '';
    if (!$is_days_based && $days > 0) {
        $new_jalali_date = get_jalali_expiry_date($days);
    }

    if ($is_days_based) {
        // Days-based: send expiry_type + exp_days + empty exp_date
        $payload = [
            'password' => $password,
            'email' => '',
            'mobile' => '',
            'limit_users' => $limit_users,
            'traffic' => $traffic,
            'expiry_type' => 'days',
            'exp_days' => $days,
            'exp_date' => '',
            'desc' => $desc,
        ];
    } else {
        // Date-based: send exp_days + exp_date (Jalali)
        $payload = [
            'password' => $password,
            'email' => '',
            'mobile' => '',
            'limit_users' => $limit_users,
            'traffic' => $traffic,
            'exp_days' => $days,
            'exp_date' => $new_jalali_date ?: $end_date,
            'desc' => $desc,
        ];
    }

    $result = ssh_curl_request($base_url . '/ajax/users/' . $user_info['uid'], 'PUT', $payload, $cookie);
    if (isset($result['error'])) return $result;

    // If user was inactive, reactivate
    if ($user_info['status'] !== 'active' && (!isset($data['keep_status']) || !$data['keep_status'])) {
        ssh_curl_request($base_url . '/ajax/users/' . $user_info['uid'] . '/toggle-active', 'PUT', [], $cookie);
    }

    if ($result['status'] == 200) {
        return ['status' => true, 'msg' => 'User updated'];
    }
    return ['error' => "HTTP {$result['status']}, body: " . substr($result['body'] ?? '', 0, 200)];
}

/**
 * Toggle user active/inactive on Rocket panel
 * PUT /ajax/users/{uid}/toggle-active
 */
function toggle_active_rocket($location, $username) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_rocket($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $user_info = get_user_info_rocket($username, $location);
    if (isset($user_info['error'])) return $user_info;

    $base_url = rtrim($panel['url_panel'], '/');
    $result = ssh_curl_request($base_url . '/ajax/users/' . $user_info['uid'] . '/toggle-active', 'PUT', [], $cookie);
    if (isset($result['error'])) return $result;
    return ['status' => true, 'msg' => 'User status toggled'];
}

/**
 * Reset traffic for user on Rocket panel
 * PUT /ajax/users/{uid}/reset-traffic
 */
function reset_traffic_rocket($location, $username) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_rocket($panel['code_panel']);
    if (!$cookie) return ['error' => 'Login failed'];

    $user_info = get_user_info_rocket($username, $location);
    if (isset($user_info['error'])) return $user_info;

    $base_url = rtrim($panel['url_panel'], '/');
    $result = ssh_curl_request($base_url . '/ajax/users/' . $user_info['uid'] . '/reset-traffic', 'PUT', [], $cookie);
    if (isset($result['error'])) return $result;
    return ['status' => true, 'msg' => 'Traffic reset'];
}

/**
 * Get SSH/UDGPW ports from Rocket settings page
 * GET /settings - parse HTML for ssh_port and udp_port inputs
 */
function get_ports_rocket($location) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_rocket($panel['code_panel']);
    if (!$cookie) return parse_ssh_ports($panel);

    $base_url = rtrim($panel['url_panel'], '/');
    $result = ssh_curl_request($base_url . '/settings', 'GET', null, $cookie);
    if (isset($result['error'])) return parse_ssh_ports($panel);

    $dom = new DOMDocument();
    @$dom->loadHTML($result['body']);
    $xpath = new DOMXPath($dom);

    $ports = ['ssh_port' => '', 'udgpw' => '0', 'dropbear' => '0'];

    $ssh_input = $xpath->query("//input[@name='ssh_port']");
    if ($ssh_input->length > 0) {
        $ports['ssh_port'] = $ssh_input->item(0)->getAttribute('value');
    }

    $udp_input = $xpath->query("//input[@name='udp_port']");
    if ($udp_input->length > 0) {
        $ports['udgpw'] = $udp_input->item(0)->getAttribute('value');
    }

    return $ports;
}

/**
 * Get panel statistics for Rocket SSH
 * Returns: total_users, active_users, disabled_users, expired_users, total_traffic_used
 */
function get_panel_stats_rocket($location) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $cookie = login_rocket($panel['code_panel']);

    $stats = [
        'connected' => false,
        'total_users' => 0,
        'active_users' => 0,
        'disabled_users' => 0,
        'expired_users' => 0,
        'total_traffic_used' => 0,
        'total_traffic_limit' => 0,
        'error' => null
    ];

    if (!$cookie) {
        $stats['error'] = 'Login failed';
        return $stats;
    }

    $users = get_users_rocket($location);
    if (isset($users['error'])) {
        $stats['error'] = $users['error'];
        return $stats;
    }

    $stats['connected'] = true;

    if (isset($users['data']) && is_array($users['data'])) {
        $stats['total_users'] = count($users['data']);

        foreach ($users['data'] as $user) {
            // Count by status
            $is_active = true;
            if (isset($user['status'])) {
                if (mb_strpos($user['status'], 'غیرفعال') !== false) {
                    $stats['disabled_users']++;
                    $is_active = false;
                } elseif (mb_strpos($user['status'], 'منقضی') !== false) {
                    $stats['expired_users']++;
                    $is_active = false;
                }
            }
            if (isset($user['is_active']) && !$user['is_active']) {
                if ($is_active) $stats['disabled_users']++;
                $is_active = false;
            }
            if ($is_active) {
                $stats['active_users']++;
            }

            // Sum traffic
            if (isset($user['usage']) && is_numeric($user['usage'])) {
                $stats['total_traffic_used'] += floatval($user['usage']);
            }
            if (isset($user['traffic']) && is_numeric($user['traffic'])) {
                $stats['total_traffic_limit'] += floatval($user['traffic']);
            }
        }
    }

    return $stats;
}
