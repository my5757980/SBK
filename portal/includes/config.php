<?php
/**
 * SBK Auction Portal - Database Configuration
 * Secure MySQL connection with error handling
 */

/**
 * Settings come from the .env file at the project root (git-ignored), so the
 * same code runs locally and on the server and `git pull` never overwrites
 * a machine's credentials. Values below are only fallbacks for local dev.
 */
function env_get($key, $default = null) {
    static $vars = null;
    if ($vars === null) {
        $vars = array();
        $path = dirname(__DIR__) . '/.env';
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                list($k, $v) = explode('=', $line, 2);
                $vars[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
    if (array_key_exists($key, $vars) && $vars[$key] !== '') {
        return $vars[$key];
    }
    $fromEnv = getenv($key);
    return ($fromEnv !== false && $fromEnv !== '') ? $fromEnv : $default;
}

// Database Configuration
define('DB_HOST', env_get('MYSQL_HOST', '127.0.0.1'));
define('DB_USER', env_get('MYSQL_USER', 'root'));
define('DB_PASS', env_get('MYSQL_PASSWORD', ''));
define('DB_NAME', env_get('MYSQL_DATABASE', 'sbk_auction'));
define('DB_PORT', (int) env_get('MYSQL_PORT', 3306));

// Site Configuration
define('SITE_NAME', env_get('SITE_NAME', 'SBK Auction Portal'));
define('SITE_URL', env_get('SITE_URL', 'http://localhost/sbk-auction/'));
define('ADMIN_EMAIL', env_get('ADMIN_EMAIL', 'admin@sbkauction.local'));

// Session Configuration
define('SESSION_TIMEOUT', 3600); // 1 hour
define('SESSION_NAME', 'sbk_session');

// Set session configuration
$is_https = strpos(SITE_URL, 'https://') === 0;
ini_set('session.name', SESSION_NAME);
ini_set('session.cookie_lifetime', SESSION_TIMEOUT);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', $is_https ? 1 : 0); // secure cookie on HTTPS
ini_set('session.use_strict_mode', 1);

// Error reporting.
// Never show PHP warnings to a visitor in production — they render into the page
// and break the layout. DEBUG_MODE can be forced from .env; otherwise it is on
// only for local dev (localhost) and off for a real domain.
$debug_env = env_get('DEBUG_MODE', null);
if ($debug_env !== null) {
    define('DEBUG_MODE', in_array(strtolower((string) $debug_env), array('1', 'true', 'on', 'yes'), true));
} else {
    define('DEBUG_MODE', strpos(SITE_URL, 'localhost') !== false || strpos(SITE_URL, '127.0.0.1') !== false);
}
error_reporting(E_ALL);
ini_set('display_errors', DEBUG_MODE ? 1 : 0);
ini_set('log_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Connection using MySQLi (Procedural style with OOP fallback)
function getDatabaseConnection() {
    static $connection = null;

    if ($connection === null) {
        // Create connection
        $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

        // Check connection
        if ($connection->connect_error) {
            error_log("Database Connection Error: " . $connection->connect_error);
            die("Connection failed. Please try again later.");
        }

        // Set charset to utf8mb4
        $connection->set_charset("utf8mb4");

        // Set timezone
        $connection->query("SET time_zone = '+00:00'");
    }

    return $connection;
}

// Global database connection
$conn = getDatabaseConnection();

// Helper function for prepared statements
function prepareQuery($query) {
    global $conn;
    return $conn->prepare($query);
}

// Helper function to execute query and get results
function queryDatabase($query, $types = "", $params = array()) {
    global $conn;

    try {
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }

        if (!empty($params) && !empty($types)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();

        return $stmt;
    } catch (Exception $e) {
        error_log("Query Error: " . $e->getMessage());
        return false;
    }
}

// Close connection on script termination
register_shutdown_function(function() {
    global $conn;
    if ($conn) {
        $conn->close();
    }
});

?>
