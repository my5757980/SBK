<?php
/**
 * The IDs the two feeds sign in with, kept by the admin panel (spec 010).
 *
 * The client, 2 October 2026: if an ID is ever suspended, the desk must be able to
 * put in a new one itself - the site's address, the username, the password - and
 * the feed carries on from the new one.
 *
 *   Feed A  the first auction site, read by pb-harvest.php on THIS server. Its site
 *           asks a person to tick "I'm not a robot" at sign-in, so a new ID needs a
 *           person once: they sign in with it and hand the session over on the
 *           Data sources page. Nothing here ever tries the password on the site.
 *   Feed B  the second auction and the statistics, read by the job on GitHub. It
 *           signs in with username and password by itself, and asks the portal for
 *           the ID at the start of every run (aaa-stats-ingest.php ?id=1).
 *
 * WHERE IT LIVES. `~/source-ids/`, outside every web root: ids.json (0600) and the
 * encryption key in a file of its own (0600). The password is AES-256-GCM
 * ciphertext in ids.json - never in a repository, a log, the GitHub job's state
 * file or a page's HTML. Written whole (tmp, length checked, rename) under a lock:
 * this host has before answered a write with an error and left an empty file.
 *
 * The site's ADDRESS may change only to the same site at a new address - the code
 * that reads each site is written for that site. The address rows are known by in
 * cars.source_url never changes; only where requests are sent does.
 */

require_once __DIR__ . '/source-health.php';      // sourceHome()

const SOURCE_FEEDS = array('a', 'b');

/** The addresses the feeds were built against - used until one is saved. */
function sourceIdDefaults() {
    return array('a' => 'https://pacificboeki.jp', 'b' => 'https://bid.aaajapan.com');
}

/** The folder (a test hands in a throwaway one). */
function sourceIdsDir($dir = null) {
    static $over = null;
    if ($dir !== null) {
        $over = rtrim($dir, '/');
    }
    return $over ?: sourceHome() . '/source-ids';
}

function sourceIdsLoadAll() {
    $f = sourceIdsDir() . '/ids.json';
    if (!is_file($f)) {
        return array();
    }
    for ($i = 0; $i < 3; $i++) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (is_array($j)) {
            return $j;
        }
        usleep(150000);
    }
    // Present but unreadable: say so rather than pretend nothing is saved.
    throw new RuntimeException('the saved IDs could not be read - try again');
}

/** What is saved for one feed, without the password: base, user, rev, saved_at, saved_by, has_pass. */
function sourceIdGet($feed) {
    $all = sourceIdsLoadAll();
    $e = is_array($all[$feed] ?? null) ? $all[$feed] : array();
    $def = sourceIdDefaults();
    return array(
        'base'     => (string) ($e['base'] ?? $def[$feed]),
        'custom'   => isset($e['base']) && $e['base'] !== $def[$feed],
        'user'     => (string) ($e['user'] ?? ''),
        'has_pass' => !empty($e['pass']),
        'rev'      => (int) ($e['rev'] ?? 0),
        'saved_at' => (int) ($e['saved_at'] ?? 0),
        'saved_by' => (string) ($e['saved_by'] ?? ''),
    );
}

/** Where requests for a feed go: the saved address, or the one it was built against. */
function sourceIdBase($feed) {
    try {
        return sourceIdGet($feed)['base'];
    } catch (Throwable $e) {
        return sourceIdDefaults()[$feed];
    }
}

/** The saved password, decrypted - only for the job that signs in, and the page's Show/Copy. */
function sourceIdPass($feed) {
    $all = sourceIdsLoadAll();
    $enc = (string) ($all[$feed]['pass'] ?? '');
    return $enc === '' ? '' : sourceIdDecrypt($enc);
}

/* ------------------------------------------------------------ encryption */

function sourceIdKey($create = false) {
    $f = sourceIdsDir() . '/key';
    if (is_file($f)) {
        $k = base64_decode(trim((string) file_get_contents($f)), true);
        if ($k !== false && strlen($k) === 32) {
            return $k;
        }
        throw new RuntimeException('the key file is damaged');
    }
    if (!$create) {
        throw new RuntimeException('no key yet');
    }
    $k = random_bytes(32);
    sourceIdsWrite($f, base64_encode($k));
    return $k;
}

function sourceIdEncrypt($plain) {
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', sourceIdKey(true), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        throw new RuntimeException('could not encrypt');
    }
    return base64_encode($iv . $tag . $ct);
}

function sourceIdDecrypt($enc) {
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('the saved password is damaged');
    }
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', sourceIdKey(), OPENSSL_RAW_DATA,
                          substr($raw, 0, 12), substr($raw, 12, 16));
    if ($pt === false) {
        throw new RuntimeException('the saved password could not be read');
    }
    return $pt;
}

/* ---------------------------------------------------------------- saving */

/** One file, written whole or not at all. */
function sourceIdsWrite($file, $content) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $tmp = $file . '.tmp';
    $w = @file_put_contents($tmp, $content);
    clearstatcache(true, $tmp);
    if ($w !== strlen($content) || @filesize($tmp) !== strlen($content)) {
        @unlink($tmp);
        throw new RuntimeException('the server did not save the file - try again');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('the server did not save the file - try again');
    }
    @chmod($file, 0600);
}

/** Is this a site address we can send requests to? Returns the clean address or ''. */
function sourceIdCleanBase($url) {
    $url = trim((string) $url);
    // '#' as the delimiter: '~' is allowed in a path, and as the delimiter it ended the pattern early
    if (!preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}(:\d{2,5})?(/[A-Za-z0-9._~/-]*)?$#i', $url)) {
        return '';
    }
    return rtrim($url, '/');
}

/**
 * Save a feed's ID. A null password keeps the saved one (the form leaves it empty
 * when only the address or username changes). Every save is a new revision: that
 * number is how the GitHub job knows the ID changed and starts afresh.
 *
 * @return int the new revision
 */
function sourceIdSave($feed, $base, $user, $pass, $by) {
    if (!in_array($feed, SOURCE_FEEDS, true)) {
        throw new InvalidArgumentException('no such feed');
    }
    $clean = sourceIdCleanBase($base);
    if ($clean === '') {
        throw new InvalidArgumentException('The address must start with https:// and be a website address, like https://www.example.com');
    }
    $user = trim((string) $user);
    if ($user === '' || strlen($user) > 120 || preg_match('/[\x00-\x1f]/', $user)) {
        throw new InvalidArgumentException('Enter the username (up to 120 characters).');
    }
    if ($pass !== null && ($pass === '' || strlen($pass) > 200 || preg_match('/[\x00-\x1f]/', $pass))) {
        throw new InvalidArgumentException('Enter the password (up to 200 characters).');
    }
    $dir = sourceIdsDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $lock = fopen($dir . '/ids.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('busy - try again');
    }
    try {
        $all = sourceIdsLoadAll();
        $old = is_array($all[$feed] ?? null) ? $all[$feed] : array();
        if ($pass === null && empty($old['pass'])) {
            throw new InvalidArgumentException('Enter the password - none is saved yet.');
        }
        $all[$feed] = array(
            'base'     => $clean,
            'user'     => $user,
            'pass'     => $pass === null ? $old['pass'] : sourceIdEncrypt($pass),
            'rev'      => (int) ($old['rev'] ?? 0) + 1,
            'saved_at' => time(),
            'saved_by' => substr((string) $by, 0, 80),
        );
        foreach ($old as $k => $v) {                       // sourceIdMark()'s notes stay
            if (strpos($k, 'note_') === 0) {
                $all[$feed][$k] = $v;
            }
        }
        sourceIdsWrite($dir . '/ids.json', json_encode($all, JSON_PRETTY_PRINT));
        return $all[$feed]['rev'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Notes beside a feed's ID that are not the ID - e.g. who feed A's last handed-over
 * session belongs to. Does not change the revision (the job must not restart for it).
 */
function sourceIdMark($feed, array $fields) {
    if (!in_array($feed, SOURCE_FEEDS, true)) {
        return;
    }
    $dir = sourceIdsDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $lock = fopen($dir . '/ids.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        return;
    }
    try {
        $all = sourceIdsLoadAll();
        $e = is_array($all[$feed] ?? null) ? $all[$feed] : array();
        foreach ($fields as $k => $v) {
            if (in_array($k, array('base', 'user', 'pass', 'rev'), true)) {
                continue;                                  // never through here
            }
            $e['note_' . $k] = is_int($v) ? $v : substr((string) $v, 0, 120);
        }
        $all[$feed] = $e;
        sourceIdsWrite($dir . '/ids.json', json_encode($all, JSON_PRETTY_PRINT));
    } catch (Throwable $e) {
        // a note is not worth failing the hand-over for
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** The notes kept by sourceIdMark(), without their prefix. */
function sourceIdNotes($feed) {
    try {
        $all = sourceIdsLoadAll();
    } catch (Throwable $e) {
        return array();
    }
    $out = array();
    foreach ((array) ($all[$feed] ?? array()) as $k => $v) {
        if (strpos($k, 'note_') === 0) {
            $out[substr($k, 5)] = $v;
        }
    }
    return $out;
}

/* ------------------------------------------------- feed A: the session */

/** The session code as pasted - with or without "session_id=", quotes, spaces. '' if it is not one. */
function pbSessionClean($code) {
    $code = trim((string) $code, " \t\r\n\"'");
    if (preg_match('/session_id\s*[=:]\s*"?([^;"\s]+)/i', $code, $m)) {
        $code = $m[1];
    }
    return preg_match('/^[A-Za-z0-9_.%\-]{20,200}$/', $code) ? $code : '';
}

/**
 * Whose is this session? ONE request to the site - the same question the harvester
 * asks every half hour. Returns ['ok' => bool, 'uid', 'login', 'name', 'why'].
 */
function pbSessionCheck($base, $code) {
    $ch = curl_init(rtrim($base, '/') . '/web/session/get_session_info');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_POST => 1, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS => json_encode(array('jsonrpc' => '2.0', 'method' => 'call', 'params' => new stdClass(), 'id' => 1)),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json',
                                    'Origin: ' . rtrim($base, '/'), 'Referer: ' . rtrim($base, '/') . '/pb-auction/',
                                    'Cookie: api_mode=odoo; session_id=' . $code),
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                           . '(KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
    ));
    $body = curl_exec($ch);
    $code_http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $body === '') {
        return array('ok' => false, 'why' => 'The site did not answer (' . ($err ?: 'no answer') . '). Try again in a minute.');
    }
    if ($code_http !== 200) {
        return array('ok' => false, 'why' => 'The site answered with an error (' . $code_http . '). Check the address and try again.');
    }
    $j = json_decode($body, true);
    // a code that belongs to no sign-in is answered with an error, not an empty result
    if (is_array($j) && isset($j['error'])) {
        $m = strtolower((string) ($j['error']['message'] ?? '') . ' ' . (string) ($j['error']['data']['name'] ?? ''));
        if ((int) ($j['error']['code'] ?? 0) === 100 || strpos($m, 'session') !== false) {
            return array('ok' => false, 'why' => 'This code is not signed in. Sign in on the site first (and tick the box), then copy the code again.');
        }
        return array('ok' => false, 'why' => 'The site did not accept this code. Copy it again from the session_id row.');
    }
    $r = is_array($j) ? ($j['result'] ?? null) : null;
    if (!is_array($r)) {
        return array('ok' => false, 'why' => 'The site did not answer as expected. Check the address.');
    }
    $uid = (int) ($r['uid'] ?? 0);
    if ($uid <= 0) {
        return array('ok' => false, 'why' => 'This code is not signed in. Sign in on the site first (and tick the box), then copy the code again.');
    }
    return array('ok' => true, 'uid' => $uid, 'login' => (string) ($r['username'] ?? ''),
                 'name' => (string) ($r['name'] ?? ''), 'why' => '');
}

/**
 * Hand a checked session to the harvester. Straight in when no harvest run holds
 * its lock - the stop it may be in is cleared under that same lock; otherwise it
 * waits in session.pending and the next run, within five minutes, takes it.
 *
 * @return string 'now' or 'next-run'
 */
function pbSessionInstall($code, $pbDir = null) {
    $pbDir = $pbDir ?: sourceHome() . '/pb-harvest';
    $line = 'api_mode=odoo; session_id=' . $code;
    $lock = @fopen($pbDir . '/harvest.lock', 'c');
    if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
        try {
            sourceIdsWrite($pbDir . '/session.txt', $line);
            @unlink($pbDir . '/session.pending');
            $st = json_decode((string) @file_get_contents($pbDir . '/state.json'), true);
            if (is_array($st) && (($st['halted'] ?? '') !== '')) {
                $st['halted'] = '';
                $st['haltedAt'] = '';
                $st['sessionAt'] = 0;          // asked again on the next run
                sourceIdsWrite($pbDir . '/state.json', json_encode($st));
            }
            @file_put_contents($pbDir . '/harvest.log', gmdate('Y-m-d H:i') . " new session from the admin panel - installed\n",
                               FILE_APPEND | LOCK_EX);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return 'now';
    }
    if ($lock) {
        fclose($lock);
    }
    sourceIdsWrite($pbDir . '/session.pending', $line);
    return 'next-run';
}
