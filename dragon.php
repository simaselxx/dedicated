<?php
/**
 * Dragon SSH Panel Driver
 * Translated from: xssh/sshx.py (Dragon branches)
 * All operations via SSH (phpseclib) - menu-driven interface
 *
 * Requires: composer require phpseclib/phpseclib:~3.0
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ssh_helpers.php';
ini_set('error_log', 'error_log');

use phpseclib3\Net\SSH2;

/**
 * Connect to Dragon panel via SSH
 * Returns SSH2 object or false
 */
function connect_dragon($location) {
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    if (!$panel) return false;

    $host = $panel['url_panel'];
    $port = !empty($panel['secret_code']) ? intval($panel['secret_code']) : 22;
    $username = $panel['username_panel'];
    $password = $panel['password_panel'];

    try {
        $ssh = new SSH2($host, $port, 30);
        if (!$ssh->login($username, $password)) {
            error_log("Dragon SSH login failed for {$host}:{$port}");
            return false;
        }
        return $ssh;
    } catch (\Exception $e) {
        error_log("Dragon SSH connect error: " . $e->getMessage());
        return false;
    }
}

/**
 * Execute menu command and read output
 */
function dragon_menu_exec($ssh, $commands, $wait = 0.5) {
    $ssh->write("menu\n");
    usleep(300000); // 0.3s wait for menu to load

    foreach ($commands as $cmd) {
        $ssh->write($cmd . "\n");
        usleep(intval($wait * 1000000));
    }

    usleep(500000); // 0.5s final wait
    $output = $ssh->read('', SSH2::READ_SIMPLE);
    return clean_ansi($output);
}

/**
 * Parse user list from Dragon menu output
 * Supports 5-8 field formats (improved flexible parsing from sshx.py line 3136)
 * Returns: ['usernames' => [...], 'numbers' => [...]]
 */
function parse_dragon_user_list($cleaned, $section = 'LIST OF USERS:') {
    $usernames = [];
    $numbers = [];

    // Try multiple section headers
    $sections = [$section, 'LIST OF USERS AND EXPIRY DATE:', 'LIST OF USERS AND THEIR PASSWORDS:', 'LIST OF USERS:'];
    $found = false;
    foreach ($sections as $sec) {
        if (strpos($cleaned, $sec) !== false) {
            $cleaned = explode($sec, $cleaned)[1];
            $cleaned = explode('Enter or select a user', $cleaned)[0];
            $found = true;
            break;
        }
    }
    if (!$found) return ['usernames' => [], 'numbers' => []];

    $lines = explode("\n", $cleaned);
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        $parts = array_values(array_filter($parts, function($v) { return $v !== ''; }));

        if (count($parts) >= 5) {
            // Find number index (usually 0 or 1)
            $num_idx = 0;
            if (isset($parts[1]) && ctype_digit(ltrim($parts[1], '0'))) {
                $num_idx = 1;
            } elseif (!ctype_digit(ltrim($parts[0], '0'))) {
                continue;
            }

            $number = ltrim($parts[$num_idx], '0');
            if ($number === '') $number = $parts[$num_idx];

            // Find username (first non-digit, non-date field after number)
            for ($i = $num_idx + 1; $i < count($parts); $i++) {
                if (strlen($parts[$i]) >= 3 && !ctype_digit($parts[$i]) && strpos($parts[$i], '/') === false) {
                    $usernames[] = $parts[$i];
                    $numbers[] = $number;
                    break;
                }
            }
        }
    }

    return ['usernames' => $usernames, 'numbers' => $numbers];
}

/**
 * Create user on Dragon panel
 * SSH: menu > 1 > username > password > days > connections
 */
function add_user_dragon($location, $username, $password, $days, $connections) {
    $ssh = connect_dragon($location);
    if (!$ssh) return ['error' => 'SSH connection failed'];

    try {
        $ssh->write("menu\n");
        usleep(300000);
        $ssh->write("1\n");
        usleep(100000);
        $ssh->write("{$username}\n");
        usleep(100000);
        $ssh->write("{$password}\n");
        usleep(100000);
        $ssh->write("{$days}\n");
        usleep(100000);
        $ssh->write("{$connections}\n");
        usleep(500000);

        $output = $ssh->read('', SSH2::READ_SIMPLE);
        $cleaned = clean_ansi($output);
        $ssh->disconnect();

        if (strpos($cleaned, 'SSH ACCOUNT') !== false || strpos($cleaned, '◈') !== false) {
            return ['status' => true, 'msg' => 'User created successfully'];
        }
        return ['error' => 'Failed to create user: ' . substr($cleaned, -200)];
    } catch (\Exception $e) {
        $ssh->disconnect();
        return ['error' => 'SSH error: ' . $e->getMessage()];
    }
}

/**
 * Get user info from Dragon panel by parsing user list
 * SSH: menu > 9 (full user list)
 */
function get_user_info_dragon($location, $username) {
    $ssh = connect_dragon($location);
    if (!$ssh) return ['error' => 'SSH connection failed'];

    try {
        // Get total user count first
        $ssh->write("menu\n");
        usleep(500000);
        $output = $ssh->read('', SSH2::READ_SIMPLE);
        $cleaned = clean_ansi($output);

        $counter = 0;
        if (strpos($cleaned, 'Total: ') !== false) {
            $total_part = explode('Total: ', $cleaned)[1];
            $counter = intval(explode("\n", $total_part)[0]);
        }

        // Get full user list
        $ssh->write("menu\n");
        usleep(300000);
        $ssh->write("9\n");
        // Wait longer for large user lists
        $wait = max(1, intval($counter / 20));
        sleep($wait);

        $output = $ssh->read('', SSH2::READ_SIMPLE);
        $cleaned = clean_ansi($output);
        $ssh->disconnect();

        // Parse user list: ◇User ◇Password ◇limit ◇validity
        if (strpos($cleaned, 'User') === false) {
            return ['error' => 'Could not parse Dragon user list'];
        }

        $section = '';
        if (strpos($cleaned, 'TOTAL USERS') !== false) {
            $section = explode('validity', $cleaned);
            if (count($section) > 1) {
                $section = explode('TOTAL USERS', $section[1])[0];
            } else {
                $section = '';
            }
        }

        $lines = explode("\n", $section);
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            $parts = array_values(array_filter($parts, function($v) { return $v !== ''; }));

            if (count($parts) >= 4 && count($parts) <= 5) {
                $u = $parts[0];
                if ($u === $username) {
                    $pass = $parts[1] ?? '';
                    $conn_limit = $parts[2] ?? '1';
                    $validity = $parts[3] ?? '0';

                    $status = 'active';
                    if (in_array($validity, ['Nunca', 'Venceu'])) {
                        $status = 'disabled';
                        $validity = '0';
                    }

                    return [
                        'status' => $status,
                        'username' => $username,
                        'password' => $pass,
                        'data_limit' => null, // Dragon doesn't support traffic limits
                        'expire' => null,
                        'days_left' => $validity,
                        'connection_limit' => $conn_limit,
                        'used_traffic' => null,
                    ];
                }
            }
        }

        return ['error' => 'User not found'];
    } catch (\Exception $e) {
        $ssh->disconnect();
        return ['error' => 'SSH error: ' . $e->getMessage()];
    }
}

/**
 * Delete user from Dragon panel
 * SSH: menu > 3 > 1 > find user_number > send number
 */
function remove_user_dragon($location, $username) {
    $ssh = connect_dragon($location);
    if (!$ssh) return ['error' => 'SSH connection failed'];

    try {
        // First pass: get user number
        $ssh->write("menu\n");
        usleep(300000);
        $ssh->write("3\n");
        usleep(100000);
        $ssh->write("1\n");
        usleep(500000);

        $output = $ssh->read('', SSH2::READ_SIMPLE);
        $cleaned = clean_ansi($output);

        $parsed = parse_dragon_user_list($cleaned);
        $idx = array_search($username, $parsed['usernames']);
        if ($idx === false) {
            $ssh->disconnect();
            return ['error' => "User {$username} not found"];
        }

        $user_number = $parsed['numbers'][$idx];

        // Second pass: actually delete
        $ssh->write("menu\n");
        usleep(300000);
        $ssh->write("3\n");
        usleep(100000);
        $ssh->write("1\n");
        usleep(100000);
        $ssh->write("{$user_number}\n");
        sleep(1);

        $output = $ssh->read('', SSH2::READ_SIMPLE);
        $cleaned = clean_ansi($output);
        $ssh->disconnect();

        if (strpos($cleaned, "successfully removed") !== false) {
            return ['status' => true, 'msg' => 'User deleted'];
        }
        if (strpos($cleaned, "empty or invalid") !== false) {
            return ['error' => 'User is empty or invalid'];
        }
        // Assume success if no error detected
        return ['status' => true, 'msg' => 'User deleted'];
    } catch (\Exception $e) {
        $ssh->disconnect();
        return ['error' => 'SSH error: ' . $e->getMessage()];
    }
}

/**
 * Renew user on Dragon panel
 * Step 1: menu > 5 > user_number > DD/MM/YYYY (expiry date)
 * Step 2: menu > 6 > user_number > connection_limit
 */
function renew_user_dragon($location, $username, $days, $connections) {
    $ssh = connect_dragon($location);
    if (!$ssh) return ['error' => 'SSH connection failed'];

    try {
        // Get user number from list (option 5)
        $ssh->write("menu\n");
        usleep(300000);
        $ssh->write("5\n");
        usleep(500000);

        $output = $ssh->read('', SSH2::READ_SIMPLE);
        $cleaned = clean_ansi($output);

        $parsed = parse_dragon_user_list($cleaned, 'LIST OF USERS AND EXPIRY DATE:');
        $idx = array_search($username, $parsed['usernames']);
        if ($idx === false) {
            $ssh->disconnect();
            return ['error' => "User {$username} not found"];
        }

        $user_number = $parsed['numbers'][$idx];

        // Step 1: Update expiry date (DD/MM/YYYY format)
        $timestamp = time() + (intval($days) * 86400);
        $fixed_date = date('d/m/Y', $timestamp);

        $ssh->write("menu\n");
        usleep(300000);
        $ssh->write("5\n");
        usleep(300000);
        $ssh->write("{$user_number}\n");
        sleep(2);
        $ssh->write("{$fixed_date}\n");
        sleep(2);

        // Step 2: Update connection limit
        $ssh->write("menu\n");
        usleep(300000);
        $ssh->write("6\n");
        usleep(300000);
        $ssh->write("{$user_number}\n");
        usleep(500000);
        $ssh->write("{$connections}\n");
        sleep(1);

        $output = $ssh->read('', SSH2::READ_SIMPLE);
        $cleaned = clean_ansi($output);
        $ssh->disconnect();

        // Check multiple success patterns
        if (strpos($cleaned, "Limit applied") !== false
            || strpos($cleaned, "foi") !== false
            || stripos($cleaned, "successfully") !== false) {
            return ['status' => true, 'msg' => 'User renewed'];
        }

        // Assume success even without clear message (Dragon behavior)
        return ['status' => true, 'msg' => 'User renewed'];
    } catch (\Exception $e) {
        $ssh->disconnect();
        return ['error' => 'SSH error: ' . $e->getMessage()];
    }
}

/**
 * Change password on Dragon panel
 * SSH: menu > 7 > user_number > new_password
 */
function change_password_dragon($location, $username, $new_password) {
    $ssh = connect_dragon($location);
    if (!$ssh) return ['error' => 'SSH connection failed'];

    try {
        // Get user number (option 7 shows users with passwords)
        $ssh->write("menu\n");
        usleep(300000);
        $ssh->write("7\n");
        usleep(500000);

        $output = $ssh->read('', SSH2::READ_SIMPLE);
        $cleaned = clean_ansi($output);

        $parsed = parse_dragon_user_list($cleaned, 'LIST OF USERS AND THEIR PASSWORDS:');
        $idx = array_search($username, $parsed['usernames']);
        if ($idx === false) {
            $ssh->disconnect();
            return ['error' => "User {$username} not found"];
        }

        $user_number = $parsed['numbers'][$idx];

        // Change password
        $ssh->write("menu\n");
        usleep(300000);
        $ssh->write("7\n");
        usleep(100000);
        $ssh->write("{$user_number}\n");
        usleep(100000);
        $ssh->write("{$new_password}\n");
        sleep(1);

        $output = $ssh->read('', SSH2::READ_SIMPLE);
        $cleaned = clean_ansi($output);
        $ssh->disconnect();

        if (strpos($cleaned, "has been changed to") !== false) {
            return ['status' => true, 'msg' => "Password changed to {$new_password}"];
        }
        if (strpos($cleaned, "empty or invalid") !== false) {
            return ['error' => 'User is empty or invalid'];
        }
        return ['error' => 'Unknown error changing password'];
    } catch (\Exception $e) {
        $ssh->disconnect();
        return ['error' => 'SSH error: ' . $e->getMessage()];
    }
}
