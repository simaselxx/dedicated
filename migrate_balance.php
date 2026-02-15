<?php
/**
 * Migration Script: Transfer user balances from XSSH to Mirzabot
 *
 * Usage: php migrate_balance.php /path/to/xssh/ssh.db
 *
 * This script reads balances from XSSH SQLite database and updates them in Mirzabot MySQL
 */

// Mirzabot database connection
require_once __DIR__ . '/config.php';

// Check command line argument
if ($argc < 2) {
    echo "Usage: php migrate_balance.php /path/to/xssh/ssh.db\n";
    echo "Example: php migrate_balance.php /root/xssh/ssh.db\n";
    exit(1);
}

$sqlite_path = $argv[1];

// Check if SQLite file exists
if (!file_exists($sqlite_path)) {
    echo "Error: SQLite database not found at: $sqlite_path\n";
    exit(1);
}

echo "=== XSSH to Mirzabot Balance Migration ===\n\n";

// Connect to XSSH SQLite database
try {
    $sqlite = new PDO("sqlite:$sqlite_path");
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to XSSH database: $sqlite_path\n";
} catch (PDOException $e) {
    echo "Error connecting to SQLite: " . $e->getMessage() . "\n";
    exit(1);
}

// Connect to Mirzabot MySQL database
try {
    $mysql = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to Mirzabot MySQL database\n\n";
} catch (PDOException $e) {
    echo "Error connecting to MySQL: " . $e->getMessage() . "\n";
    exit(1);
}

// Read all users with balance > 0 from XSSH
$stmt = $sqlite->query("SELECT ID, Name, Username, Balance FROM Clients WHERE Balance > 0");
$xssh_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($xssh_users) . " users with balance > 0 in XSSH\n\n";

if (count($xssh_users) == 0) {
    echo "No users to migrate.\n";
    exit(0);
}

// Display users before migration
echo "Users to migrate:\n";
echo str_repeat("-", 60) . "\n";
printf("%-15s %-20s %-15s\n", "User ID", "Username", "Balance");
echo str_repeat("-", 60) . "\n";

foreach ($xssh_users as $user) {
    printf("%-15s %-20s %-15s\n", $user['ID'], "@" . ($user['Username'] ?? 'N/A'), number_format($user['Balance']) . " T");
}
echo str_repeat("-", 60) . "\n";
echo "Total: " . number_format(array_sum(array_column($xssh_users, 'Balance'))) . " Toman\n\n";

// Ask for confirmation
echo "Do you want to proceed with migration? (yes/no): ";
$confirm = trim(fgets(STDIN));

if (strtolower($confirm) !== 'yes') {
    echo "Migration cancelled.\n";
    exit(0);
}

echo "\nStarting migration...\n\n";

$migrated = 0;
$created = 0;
$skipped = 0;
$errors = 0;

foreach ($xssh_users as $user) {
    $user_id = $user['ID'];
    $balance = intval($user['Balance']);
    $name = $user['Name'] ?? '';
    $username = $user['Username'] ?? '';

    try {
        // Check if user exists in Mirzabot
        $check = $mysql->prepare("SELECT id, Balance FROM user WHERE id = ?");
        $check->execute([$user_id]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // User exists - ADD XSSH balance to existing Mirzabot balance
            $old_balance = intval($existing['Balance']);
            $new_balance = $old_balance + $balance;
            $update = $mysql->prepare("UPDATE user SET Balance = ? WHERE id = ?");
            $update->execute([$new_balance, $user_id]);
            echo "Updated user $user_id: $old_balance + $balance = $new_balance T\n";
            $migrated++;
        } else {
            // User doesn't exist - create new user
            $insert = $mysql->prepare("INSERT INTO user (id, name, username, Balance, step) VALUES (?, ?, ?, ?, 'home')");
            $insert->execute([$user_id, $name, $username, $balance]);
            echo "Created user $user_id with balance: $balance T\n";
            $created++;
        }
    } catch (PDOException $e) {
        echo "Error migrating user $user_id: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n=== Migration Complete ===\n";
echo "Updated existing users: $migrated\n";
echo "Created new users: $created\n";
echo "Errors: $errors\n";
