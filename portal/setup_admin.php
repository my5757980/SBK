<?php
/**
 * Create or reset a portal admin account.
 *
 * Run from the command line on the server:
 *
 *     php setup_admin.php admin "YourStrongPassword"
 *
 * The password is stored as a bcrypt hash in the `admins` table — it is never
 * written to any file, which is why there is no default admin password in this
 * repository.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/includes/config.php';

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';
$name     = $argv[3] ?? 'Administrator';

if ($username === '' || $password === '') {
    fwrite(STDERR, "usage: php setup_admin.php <username> <password> [display name]\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

global $conn;

$conn->query("
  CREATE TABLE IF NOT EXISTS admins (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)  NOT NULL UNIQUE,
    name          VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login    DATETIME NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $conn->prepare("SELECT id FROM admins WHERE username = ? LIMIT 1");
$stmt->bind_param('s', $username);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($exists) {
    $stmt = $conn->prepare("UPDATE admins SET name = ?, password_hash = ?, is_active = 1 WHERE username = ?");
    $stmt->bind_param('sss', $name, $hash, $username);
    $ok = $stmt->execute();
    $stmt->close();
    echo $ok ? "Updated admin '$username'.\n" : "Failed to update admin.\n";
} else {
    $stmt = $conn->prepare("INSERT INTO admins (username, name, password_hash) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $username, $name, $hash);
    $ok = $stmt->execute();
    $stmt->close();
    echo $ok ? "Created admin '$username'.\n" : "Failed to create admin.\n";
}

echo "Sign in at: " . SITE_URL . "admin/login.php\n";
