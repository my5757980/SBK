<?php
/**
 * SBK Chat — configuration.
 *
 * TWO DATABASES, AND WHY.
 *
 * The chat's own tables (`chat_guests`, `chat_threads`, `chat_messages`,
 * `chat_presence`) live in the auction's database, which is simply where they
 * were made and where they still are.
 *
 * WHO the people are comes from somewhere else: the WordPress site at
 * sbkautotrading.com. That is the owner's instruction, given on 2026-09-10 and
 * in his own words — the chat belongs to the WEBSITE, not to the auction
 * portal; the staff and agents whose accounts are made on the website are the
 * only people a customer may write to; and a customer who is already signed in
 * to the website should walk into the chat without being asked who they are.
 *
 * So `chat_threads.staff_id` is now a WORDPRESS user id (`wp_users.ID`), and a
 * signed-in customer is carried by their WordPress user id too.
 *
 * NO PASSWORD IS COPIED ANYWHERE. The auction's credentials are read from its
 * own .env by absolute path and WordPress's from its own wp-config.php, both on
 * this same disk, so each secret still lives in exactly one file.
 */

/** Settings from the auction's .env, which is the one place they live. */
function env_get($key, $default = null) {
    static $vars = null;
    if ($vars === null) {
        $vars = array();
        foreach (array('/home/thelyfas/auction.sbkautotrading.com/.env',
                       dirname(__DIR__) . '/.env') as $path) {
            if (!is_readable($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                list($k, $v) = explode('=', $line, 2);
                $k = trim($k);
                if (!isset($vars[$k])) { $vars[$k] = trim(trim($v), "\"'"); }
            }
            break;
        }
    }
    if (array_key_exists($key, $vars) && $vars[$key] !== '') {
        return $vars[$key];
    }
    $fromEnv = getenv($key);
    return ($fromEnv !== false && $fromEnv !== '') ? $fromEnv : $default;
}

define('DB_HOST', env_get('MYSQL_HOST', '127.0.0.1'));
define('DB_USER', env_get('MYSQL_USER', 'root'));
define('DB_PASS', env_get('MYSQL_PASSWORD', ''));
define('DB_NAME', env_get('MYSQL_DATABASE', 'sbk_auction'));
define('DB_PORT', (int) env_get('MYSQL_PORT', 3306));

/* ------------------------------------------------- the WordPress website ----
   Read out of wp-config.php rather than copied here. One file on this server
   knows the website's database password, and if it is ever changed there it is
   changed for the chat in the same moment. */
define('WP_ROOT', '/home/thelyfas/sbkautotrading.com');

function wp_conf($key, $default = '') {
    static $c = null;
    if ($c === null) {
        $c = array();
        $path = WP_ROOT . '/wp-config.php';
        $src  = is_readable($path) ? (string) file_get_contents($path) : '';
        foreach (array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST') as $k) {
            if (preg_match('/define\(\s*[\'"]' . $k . '[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/s', $src, $m)) {
                $c[$k] = $m[1];
            }
        }
        if (preg_match('/\$table_prefix\s*=\s*[\'"](.*?)[\'"]/', $src, $m)) {
            $c['PREFIX'] = $m[1];
        }
    }
    return isset($c[$key]) && $c[$key] !== '' ? $c[$key] : $default;
}

define('WP_PREFIX', wp_conf('PREFIX', 'wp_'));
define('WP_URL',    'https://sbkautotrading.com/');

/* Two front doors, one application. The chat's own address and the
   application's - chat-application.sbkautotrading.com, the owner's order of
   19 September 2026 - run this same code, so there is still only one copy of
   it. When somebody is sent to the website to sign in, the website must know
   which door to hand them back through, or a person who started on the
   application would finish on the chat. `app=1` tells it; the website only
   ever sends anybody to its own two known addresses, never to one it is given. */
define('ON_APP_HOST', stripos((string) ($_SERVER['HTTP_HOST'] ?? ''), 'chat-application.') === 0);
define('WP_GO',       WP_URL . '?sbk_chat_go=1' . (ON_APP_HOST ? '&app=1' : ''));

/* Which WordPress roles are "the desk".
   The owner's rule: staff and agents appear in the chat, customers never. Of
   the 64 accounts on the website 59 are customers, so this list is the whole of
   the difference — and a person can still be added or held back one at a time
   with the tick box this plugin puts on their WordPress profile, because a job
   title is a rule of thumb and a person is a person.

   These are role KEYS, not the names shown in WordPress, and the two differ
   where it matters most: the owner's "Agent" is the key `sbk_agent`, and his
   "Manager" is `manager` — NOT `shop_manager`, which is WooCommerce's own role
   and a different thing. The owner named his four on 14 September 2026 —
   Administrator, CSD, Manager, Agent — and `csd` and `manager` were missing, so
   a person given either of them was invisible in the chat.

   `shop_manager` and `editor` were kept for a day because Talal Sheikh held
   `shop_manager` and dropping it would have taken him off the desk without
   anybody asking. By 15 September he had been moved to Agent and Tooba to CSD,
   leaving both old roles with nobody at all (checked with sbk-tools/wproles.py),
   so the list is now exactly the owner's four. Anyone who needs the desk without
   one of them can still be ticked on by hand on their WordPress profile. */
define('STAFF_ROLES', 'administrator,csd,manager,sbk_agent');

/* What signs "this person is signed in to the website".
   WordPress's own login cookie is set on sbkautotrading.com alone (its
   COOKIE_DOMAIN is empty), so it never reaches this subdomain and could not be
   read here even if we wanted to. Instead the website hands over a short-lived
   line of text signed with this secret, which is checked in enter.php. Nothing
   about WordPress's own login has to be touched, and nobody gets signed out. */
define('LINK_SECRET', env_get('CHAT_LINK_SECRET', ''));
define('LINK_SECONDS', 300);              // a handover is used at once or not at all

/* The parent domain, so one cookie serves the chat and the website both.
   Empty on a machine that is not the server, where a domain-wide cookie on
   "localhost" would simply be refused. */
define('COOKIE_DOMAIN',
    (strpos((string) ($_SERVER['HTTP_HOST'] ?? ''), 'sbkautotrading.com') !== false)
        ? '.sbkautotrading.com' : '');

define('CHAT_NAME', 'SBK Global Auto Trading');
define('CHAT_URL',  env_get('CHAT_URL', 'https://chat.sbkautotrading.com/'));

/* The origins allowed to ask this app anything from a page of their own.
   The website first, because that is where the chat now lives. */
define('SITE_ORIGINS', 'https://sbkautotrading.com,https://www.sbkautotrading.com');

/* Where pictures and voice notes go.
   OUTSIDE the document root, deliberately and not merely tidily: a conversation
   between a customer and the desk is not public, and a folder under the web
   root is public whatever anybody intends. Nothing can be reached by guessing a
   path; media.php is the only door, and it asks who is knocking.
   The fallback is only for a machine that has no such folder - a laptop. */
define('MEDIA_DIR', is_dir('/home/thelyfas')
    ? '/home/thelyfas/chat-media'
    : dirname(__DIR__) . '/storage');

/* How long since the last heartbeat still counts as "online".
   The beat is every 15 seconds, so 45 forgives two missed ones - a lost beat on
   a phone that dipped under a bridge should not put somebody offline. */
define('ONLINE_SECONDS', 45);

/* Calls. The sound and picture go browser to browser; these only govern the
   handshake that sits in chat_calls.
   RING_SECONDS  how long a call rings before it is a missed call
   CALL_STALE    an answered call whose other side has stopped asking about it
                 for this long is over — a closed laptop does not hang up
                 politely, and the other person must not be left talking to it */
define('RING_SECONDS', 45);
define('CALL_STALE', 25);

/* What a visitor may send. Files are the one resource this server is short of -
   the account is at 79% of its inode limit with 28 domains on it - so the sizes
   are held down and old media is swept up. See sweepOldMedia(). */
define('MAX_IMAGE_BYTES', 6 * 1024 * 1024);
define('MAX_VOICE_BYTES', 8 * 1024 * 1024);
define('MEDIA_KEEP_DAYS', 120);

date_default_timezone_set('UTC');

/** One connection to the chat's own tables, opened when something needs it. */
function db() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn->connect_error) {
            http_response_code(503);
            exit('The chat is not reachable at the moment.');
        }
        $conn->set_charset('utf8mb4');
        $conn->query("SET time_zone = '+00:00'");
    }
    return $conn;
}

/**
 * One connection to the WORDPRESS database, for reading people.
 *
 * Read-only in practice: this app never writes a row in the website's tables.
 * It is a second connection rather than a cross-database query so that if the
 * website is ever moved to its own server only this function changes.
 */
function wpdb() {
    static $conn = null;
    if ($conn === null) {
        $host = wp_conf('DB_HOST', 'localhost');
        $port = 3306;
        if (strpos($host, ':') !== false) {
            list($host, $port) = explode(':', $host, 2);
            $port = (int) $port;
        }
        $conn = @new mysqli($host, wp_conf('DB_USER'), wp_conf('DB_PASSWORD'),
                            wp_conf('DB_NAME'), $port);
        if ($conn->connect_error) {
            return $conn = false;
        }
        $conn->set_charset('utf8mb4');
        $conn->query("SET time_zone = '+00:00'");
    }
    return $conn;
}

/**
 * Who may put this app inside a frame of their own.
 *
 * The chat now opens as a panel ON the website — the owner's words were that
 * this "will be inside our SBK WordPress website" — so the website has to be
 * allowed to frame it. Nobody else is: without this line a browser's default is
 * to allow ANY site to frame it, and a copy of this chat inside somebody else's
 * page is a clickjacking trick waiting to be used.
 *
 * The cookies keep working inside that frame because chat.sbkautotrading.com and
 * sbkautotrading.com are the same SITE even though they are different ORIGINS,
 * and SameSite is about the site. A frame from anywhere else would get no
 * cookies at all — which is the second reason this is safe.
 */
function frameGuard() {
    $list = array_map('trim', explode(',', SITE_ORIGINS));
    header("Content-Security-Policy: frame-ancestors 'self' " . implode(' ', $list));
    header_remove('X-Frame-Options');
}
if (PHP_SAPI !== 'cli') {
    frameGuard();
}

/* The visitor's session and the staff member's session are separate cookies on
   purpose: a member of staff testing the visitor side in the same browser must
   not sign themselves out of the desk by doing it. */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_name('sbkchat');
    session_start();
}

/** Everything that reaches a page is escaped here, once. */
function e($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
