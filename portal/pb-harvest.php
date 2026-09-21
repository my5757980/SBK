<?php
/**
 * Pacific Boeki into the catalogue - the auction list, filled the way jpauc
 * used to fill it.
 *
 * The owner's instruction, 11 September 2026: jpauc is paused where it stands,
 * and from here the auction comes from pacificboeki.jp - "sab kuch portal mein
 * waise hi chalega jaise jpauc se" - with ONE condition: a vehicle we already
 * hold from jpauc is also on Pacific Boeki, and it must not arrive a second time.
 *
 * WHAT MAKES A VEHICLE THE SAME VEHICLE. Both sources take their pictures from
 * the same host, and the picture's address carries the sale day, the auction
 * house's code and the lot:
 *
 *     p3.aleado.com/pic/?system=auto&date=2026-09-11&auct=71&bid=5517&number=1
 *
 * Pacific Boeki hands over exactly those three as `date`, `auct_ref` and `bid`.
 * So a Pacific Boeki lot and a jpauc row are the same car when day, house code
 * and lot agree - checked against real rows before this was written: our
 * "Tokyo" lot 5517 on 11.09 is Pacific Boeki's NAA Tokyo (code 71), our "Chiba"
 * lot 5517 is JU Chiba (code 68), and the start prices agree to the yen. The
 * hall's NAME is no good for this: jpauc said "Tokyo" for what Pacific Boeki
 * splits into JU, CAA, NAA, Honda, Isuzu and ZIP Tokyo.
 *
 * A match is UPDATED in place - same row, same car_id, so every bid on it keeps
 * its vehicle. Only a lot with no match is inserted, as `pb-<day>-<code>-<lot>`.
 *
 * WHAT IS READ. One JSON call returns a hundred lots (the API's own ceiling):
 * `/api/v1/auction/search` for one sale day and one auction house at a time,
 * newest model year first, id breaking ties - a stable order, so a house is
 * read to its last page and the count it declared says whether all of it came.
 * A house read complete is also what retires what it no longer lists: only the
 * customer-facing listing may say a vehicle is there, for adding AND removing
 * (see the sbk-sync-rule note - two wrong answers were learned the hard way).
 *
 * SIGNING IN. The site asks a person to tick "I'm not a robot" at login, and
 * that stays a person's job: nobody here solves or sidesteps it. A member signs
 * in once in a browser and the session cookie is placed in PB_SESSION (see
 * sbk-tools/pbsession.py); the site renews it on every answer and this file
 * keeps the renewed one. If it ever lapses the harvest halts with 'login' and
 * waits for a person - it never tries the password itself.
 *
 * SAFE. The owner's standing rule for any source: never risk being cut off.
 * One request at a time, REQ_GAP seconds apart; a refusal, a challenge page or
 * a lapsed session halts everything until a person looks; a daily budget.
 *
 *     php pb-harvest.php --max=110            (cron, every five minutes)
 *     pb-harvest.php?t=TOKEN&max=20            (by hand, prints its progress)
 *     pb-harvest.php?t=TOKEN&dry=1&date=2026-09-12&hall=ZIP%20Tokyo&pages=3
 *                                              (reads, compares, writes NOTHING)
 */

require_once __DIR__ . '/includes/config.php';

/* The token lives in the server's .env, never here (21 September 2026). The cron
   runs this from the command line and needs no token at all; this door is the
   HTTP one. Empty refuses everything - hash_equals('', '') is true. */
define('PB_TOKEN', env_get('PB_TOKEN', ''));
const PB_BASE    = 'https://pacificboeki.jp';
/* Outside the web root: the session is a member's login and the state names
   what we read. Nothing here should ever be one URL away. */
define('PB_DIR', dirname(__DIR__) . '/pb-harvest');
define('PB_SESSION', PB_DIR . '/session.txt');
define('PB_STATE', PB_DIR . '/state.json');
define('PB_LOCK', PB_DIR . '/harvest.lock');
define('PB_LOG', PB_DIR . '/harvest.log');

/** Seconds between requests, on top of however long each took. Half again
 *  the gap jpauc ran at: a new source, and nothing is lost by being gentle. */
const REQ_GAP = 1.5;
/** Seconds a run may last - well inside the five minutes to the next one. */
const RUN_LIMIT = 230;
/** The API refuses more than a hundred a page whatever is asked for. */
const PER_PAGE = 100;
/**
 * Requests a day. Arithmetic, not policy: the whole listing is ~1,750 pages
 * (174,076 lots on 11 September), today's houses are re-read hourly for their
 * results, tomorrow's every two hours and the rest every four - about 20,000 a
 * day. This leaves room over that and nothing near the 45,000 jpauc ran at.
 */
const DAILY_BUDGET = 25000;
/** How often the sale days and each house's count are asked again - seven
 *  requests, which is what lets a changed house be read within minutes. */
const SURVEY_EVERY = 600;
/** How often the session is asked who it belongs to. */
const SESSION_EVERY = 1800;
/** Retire at most this many from one house per complete read, plus a tenth of
 *  its size: a read that went wrong must not empty a house in one stroke. */
const RETIRE_CAP = 60;

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (PB_TOKEN === '' || !hash_equals(PB_TOKEN, (string) ($_GET['t'] ?? ''))) {
        http_response_code(404);
        exit;
    }
}
$opts = $isCli ? getopt('', array('max::', 'reset::', 'dry::', 'date::', 'hall::', 'pages::')) : $_GET;
$maxRequests = max(1, min(160, (int) ($opts['max'] ?? 40)));
$dry = isset($opts['dry']);

@set_time_limit(0);
ignore_user_abort(true);
if (!is_dir(PB_DIR)) {
    @mkdir(PB_DIR, 0700, true);
}

/* One run at a time - two would share one cursor and spend the budget twice. */
$lock = fopen(PB_LOCK, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $age = is_file(PB_LOCK) ? time() - (int) filemtime(PB_LOCK) : 0;
    if ($age < RUN_LIMIT * 2) {
        echo "pehle se chal raha hai - chhor diya\n";
        exit;
    }
    @fclose($lock);
    @unlink(PB_LOCK);
    $lock = fopen(PB_LOCK, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        echo "lock nahi mila\n";
        exit;
    }
}
touch(PB_LOCK);
register_shutdown_function(function () use ($lock) {
    // A fatal error ends the run before its log line - write it where the
    // health check looks, not only in the account's error_log.
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        pbLog('FATAL ' . substr(preg_replace('/\s+/', ' ', $e['message']), 0, 300) . ' @' . $e['line']);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
});

/* ------------------------------------------------------------------ state */

function stateLoad() {
    $fresh = array(
        'day' => gmdate('Y-m-d'), 'used' => 0, 'runs' => 0, 'halted' => '', 'haltedAt' => '',
        // today's tallies, for the log and the owner's "did it run fine?"
        'new' => 0, 'adopted' => 0, 'updated' => 0, 'retired' => 0, 'purged' => 0,
        'uid' => 0, 'sessionAt' => 0,
        // dates: the sale days on offer; parts: "day|house" => n, codes, readAt
        'dates' => array(), 'surveyAt' => 0, 'parts' => array(),
        // the house being read: key, page, pages, since (db time), rows, cnt0
        'cur' => null,
        'reads' => 0,
        // sale days whose leftover jpauc rows have been swept - see sweepDay()
        'swept' => array(),
    );
    $s = is_file(PB_STATE) ? json_decode((string) file_get_contents(PB_STATE), true) : null;
    if (!is_array($s)) {
        return $fresh;
    }
    $s += $fresh;
    if ($s['day'] !== gmdate('Y-m-d')) {
        foreach (array('used', 'runs', 'new', 'adopted', 'updated', 'retired', 'purged') as $k) {
            $s[$k] = 0;
        }
        $s['day'] = gmdate('Y-m-d');
        foreach (array_keys((array) $s['swept']) as $d) {
            if ($d < $s['day']) { unset($s['swept'][$d]); }      // days already gone from the list
        }
    }
    return $s;
}

function stateSave($s) {
    file_put_contents(PB_STATE, json_encode($s), LOCK_EX);
}

function pbLog($line) {
    @file_put_contents(PB_LOG, gmdate('Y-m-d H:i') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/* ---------------------------------------------------------------- the API */

function pbCookie() {
    return is_file(PB_SESSION) ? trim((string) file_get_contents(PB_SESSION)) : '';
}

/** Keep whatever session the site hands back - it renews itself on answers. */
function pbKeepCookie($head) {
    if (!preg_match('/^set-cookie:\s*session_id=([^;\s]+)/im', $head, $m)) {
        return;
    }
    $now = pbCookie();
    $new = preg_match('/session_id=[^;\s]*/', $now)
        ? preg_replace('/session_id=[^;\s]*/', 'session_id=' . $m[1], $now)
        : trim($now . '; session_id=' . $m[1], '; ');
    if ($new !== $now) {
        file_put_contents(PB_SESSION, $new, LOCK_EX);
        @chmod(PB_SESSION, 0600);
    }
}

/**
 * One JSON-RPC call. Waits REQ_GAP before every call but the first of a run.
 * Returns [result, ''] or [null, why]; `why` starting 'HALT ' stops everything.
 */
function pbCall($path, $params, array &$s) {
    static $last = 0.0;
    if ($last > 0) {
        $wait = REQ_GAP - (microtime(true) - $last);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
    }
    $cookie = pbCookie();
    if ($cookie === '') {
        return array(null, 'HALT login: no session - sign in and run pbsession.py');
    }
    $ch = curl_init(PB_BASE . $path);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_HEADER => 1, CURLOPT_POST => 1,
        CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_ENCODING => '',
        CURLOPT_POSTFIELDS => json_encode(array('jsonrpc' => '2.0', 'method' => 'call',
                                                'params' => $params, 'id' => mt_rand())),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json', 'Accept: application/json, text/plain, */*',
            'Origin: ' . PB_BASE, 'Referer: ' . PB_BASE . '/pb-auction/', 'Cookie: ' . $cookie),
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                           . '(KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
    ));
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err  = curl_error($ch);
    curl_close($ch);
    $last = microtime(true);
    $s['used']++;

    if ($raw === false || $raw === '') {
        return array(null, 'net ' . $err);
    }
    $head = substr($raw, 0, $hs);
    $body = substr($raw, $hs);
    pbKeepCookie($head);

    if ($code === 403 || $code === 401) {
        return array(null, 'HALT refused: http ' . $code);        // never retry into a ban
    }
    if ($code === 429) {
        return array(null, 'slow: http 429');                     // ends the run, not the harvest
    }
    if ($code !== 200) {
        return array(null, 'http ' . $code);
    }
    $j = json_decode($body, true);
    if (!is_array($j)) {
        // A challenge page or anything else that is not the API answering.
        return array(null, 'HALT not json: ' . substr(preg_replace('/\s+/', ' ', strip_tags($body)), 0, 80));
    }
    if (isset($j['error'])) {
        $msg = (string) ($j['error']['message'] ?? 'error');
        if ((int) ($j['error']['code'] ?? 0) === 100 || stripos($msg, 'session') !== false) {
            return array(null, 'HALT login: ' . $msg);
        }
        return array(null, 'api: ' . $msg);
    }
    return array($j['result'] ?? null, '');
}

/** Is the session still a member's? Asked every SESSION_EVERY seconds. */
function sessionOk(array &$s) {
    list($r, $why) = pbCall('/web/session/get_session_info', new stdClass(), $s);
    if ($r === null) {
        return $why;
    }
    $uid = (int) ($r['uid'] ?? 0);
    if ($uid <= 0) {
        return 'HALT login: session belongs to nobody';
    }
    $s['uid'] = $uid;
    $s['sessionAt'] = time();
    return '';
}

/* ---------------------------------------------------------------- the plan */

/** Japan's date - the sale days are Japan's. */
function jstToday() {
    return gmdate('Y-m-d', time() + 9 * 3600);
}

/**
 * The sale days on offer and every house's count on each - the plan a pass is
 * read against. One request for the days, one per day for its houses.
 */
function survey(array &$s) {
    list($r, $why) = pbCall('/api/v1/auction/filter/initial', new stdClass(), $s);
    if ($r === null) {
        return $why;
    }
    $data  = $r['data'] ?? $r;
    $dates = array();
    foreach ((array) ($data['auction_dates'] ?? array()) as $d) {
        $v = is_array($d) ? ($d['date'] ?? $d['value'] ?? $d['name'] ?? '') : $d;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v, $m)) {
            $dates[] = $m[0];
        }
    }
    $dates = array_values(array_unique($dates));
    sort($dates);
    if (!$dates) {
        return 'survey: no sale days';
    }
    $parts = array();
    foreach ($dates as $d) {
        list($h, $why) = pbCall('/api/v1/auction/filter/halls', array('dates' => array($d)), $s);
        if ($h === null) {
            return $why;
        }
        foreach ((array) ($h['data'] ?? array()) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $n    = (int) ($row['count'] ?? 0);
            if ($name === '' || $n <= 0) {
                continue;
            }
            $key = $d . '|' . $name;
            $old = $s['parts'][$key] ?? array();
            // The house's count moved since we last read it: lots were added or
            // taken away. Read it at once rather than when its turn comes round -
            // this is what keeps the portal level with the source between reads.
            // A house read before this was counted from takes the last survey's
            // figure as its starting point, so a change is caught from now on
            // rather than only after its next scheduled read.
            $nRead = isset($old['nRead']) ? (int) $old['nRead']
                   : ((!empty($old['readAt']) && isset($old['n'])) ? (int) $old['n'] : null);
            $dirty = !empty($old['dirty']) || ($nRead !== null && $nRead !== $n);
            $parts[$key] = array('d' => $d, 'h' => $name, 'n' => $n,
                                 'readAt' => (int) ($old['readAt'] ?? 0),
                                 'codes'  => $old['codes'] ?? array(),
                                 'short'  => (int) ($old['short'] ?? 0),
                                 'tries'  => (int) ($old['tries'] ?? 0),
                                 'since0' => (string) ($old['since0'] ?? ''),
                                 'nRead'  => $nRead,
                                 'avail'  => isset($old['avail']) ? (int) $old['avail'] : null,
                                 'res'    => isset($old['res']) ? (int) $old['res'] : null,
                                 'dirty'  => $dirty ? 1 : 0);
        }
    }
    $s['dates'] = $dates;
    $s['parts'] = $parts;
    $s['surveyAt'] = time();
    return '';
}

/**
 * How soon a house wants reading again.
 *
 * The owner's word, 12 September: "jaldi se jaldi, barabar saath saath" - keep
 * level with the source as closely as can be done safely. So the reading goes
 * where things change:
 *   - today in Japan, while the halls are selling (08:00-20:00 JST): every TEN
 *     minutes for a house that sells, because that is when lots are sold and
 *     must leave the list (thirty until 14 September - see below);
 *   - today, a STOCK house - Stock, Kyouyuu, Kyoyuzaiko, Oneprice in its name and
 *     no sold/unsold result at its last read: every three hours;
 *   - today, overnight: every three hours - nothing is sold at night;
 *   - today, a house whose every lot already has its result: every three hours;
 *   - tomorrow: every two hours; later days: every four;
 *   - a day already over in Japan: once more for its results, before it goes.
 * On top of this, the survey every ten minutes flags a house whose COUNT moved
 * (lots added or withdrawn) and that house is read at once - see survey(). That
 * is also how a stock house's sales show: a sold stock car leaves its list.
 *
 * 14 September 2026, the owner's yes to "jaldi": a sold car took 30-40 minutes to
 * leave the portal, and 70% of each half-hour pass went on six stock houses that
 * had no result all day (JU Kyouyuu alone is 150 pages). They now wait for their
 * count to move or three hours, and the houses that sell are read every ten
 * minutes with the requests that frees. The pace per request is unchanged. Should
 * a heavy day use 80% of DAILY_BUDGET, selling houses drop back to thirty minutes,
 * so faster reading can never run the day out of requests.
 *
 * @param int $used requests used today ($s['used']), for that fall-back
 */
function partInterval($d, array $p = array(), $used = 0) {
    $today = jstToday();
    if ($d < $today) {
        return 6 * 3600;
    }
    if ($d === $today) {
        if (isset($p['avail']) && $p['avail'] === 0) {
            return 3 * 3600;
        }
        $h = (int) gmdate('G', time() + 9 * 3600);
        if ($h < 8 || $h >= 20) {
            return 3 * 3600;
        }
        if (preg_match('/stock|kyouyuu|kyoyuzaiko|oneprice/i', (string) ($p['h'] ?? '')) && empty($p['res'])) {
            return 3 * 3600;
        }
        return ($used > DAILY_BUDGET * 0.8) ? 1800 : 600;
    }
    return ($d === gmdate('Y-m-d', strtotime($today . ' +1 day'))) ? 2 * 3600 : 4 * 3600;
}

/** The house most in need: never read, then count changed, then most overdue. */
function pickPart(array $s) {
    $best = null;
    $bestKey = null;
    foreach ($s['parts'] as $key => $p) {
        if ($p['d'] < gmdate('Y-m-d')) {
            continue;                    // gone from our list already; nothing to gain
        }
        if (!$p['readAt']) {
            $rank = array(0, strtotime($p['d']));            // never read: earliest day first
        } elseif (!empty($p['dirty'])) {
            $rank = array(1, $p['readAt']);                  // its count moved: now
        } else {
            $due = $p['readAt'] + partInterval($p['d'], $p, (int) $s['used']);
            if ($due > time()) {
                continue;                                    // fresh enough
            }
            $rank = array(2, $due);
        }
        if ($best === null || $rank < $best) {
            $best = $rank;
            $bestKey = $key;
        }
    }
    return $bestKey;
}

/* ---------------------------------------------------------------- storing */

function dbNow($conn) {
    $r = $conn->query("SELECT NOW()");
    return $r ? $r->fetch_row()[0] : gmdate('Y-m-d H:i:s');
}

/** "auct|lot" for a stored row, read out of its picture address. */
function storedKey($images) {
    if (preg_match('/[?&]auct=(\d+)&bid=([^&"\\\\]+)/', (string) $images, $m)) {
        return (int) $m[1] . '|' . lotKey($m[2]);
    }
    return null;
}

function lotKey($lot) {
    $lot = trim((string) $lot);
    return ctype_digit($lot) ? (ltrim($lot, '0') === '' ? '0' : ltrim($lot, '0')) : $lot;
}

/** A Pacific Boeki lot as our columns. Prices arrive in thousands of yen. */
function mapLot(array $r) {
    $d    = (string) $r['date'];
    $auct = (int) $r['auct_ref'];
    $bid  = trim((string) $r['bid']);
    $time = null;
    if (preg_match('/\s(\d{2}:\d{2}(?::\d{2})?)$/', (string) ($r['lot_date'] ?? ''), $m)
        && strpos($m[1], '00:00') !== 0) {
        $time = strlen($m[1]) === 5 ? $m[1] . ':00' : $m[1];
    }
    $res = strtolower(trim((string) ($r['result_en'] ?? '')));
    $status = ($res === '' || $res === 'n/a' || $res === 'available') ? 'available' : $res;
    // Pacific Boeki says "removed" for a lot that was withdrawn but is still in its
    // list. 'removed' is OUR word for a row we retired, and purgeDead() deletes
    // those an hour later - so every withdrawn lot was written, deleted, written
    // again on the next read, and never kept (443 of AUCNET's 1,549 on 13 September,
    // which made the house look short). Stored as 'withdrawn' it is kept, like
    // sold and unsold, until its sale day is over. Not for sale either way.
    if ($status === 'removed') {
        $status = 'withdrawn';
    }
    $img = 'https://p3.aleado.com/pic/?system=auto&date=' . $d . '&auct=' . $auct
         . '&bid=' . rawurlencode($bid) . '&number=1&h=320';
    return array(
        'car_id'  => substr('pb-' . $d . '-' . $auct . '-' . preg_replace('/[^0-9A-Za-z]/', '', $bid), 0, 50),
        'lot'     => $bid,
        'make'    => trim((string) ($r['company'] ?? '')),
        'model'   => trim((string) ($r['model_name_en'] ?? '')),
        'year'    => (int) ($r['model_year_en'] ?? 0) ?: null,
        // Thousands of km, like the price's thousands of yen: 377 is 377,000 km.
        // Checked against jpauc's rows for the same lots (5 here, 5000 there).
        'km'      => (int) ($r['mileage_num'] ?? 0) * 1000,
        'price'   => (float) ($r['start_price_en'] ?? 0) * 1000,
        'sold'    => (float) ($r['end_price_en'] ?? 0) * 1000,
        'hall'    => trim((string) ($r['auction_name'] ?? '')),
        'date'    => DateTime::createFromFormat('Y-m-d', $d)->format('d.m.Y'),
        'time'    => $time,
        'chassis' => trim((string) ($r['chassis_no'] ?? '')),
        'trans'   => trim((string) ($r['transmission_en'] ?? '')),
        'grade'   => trim((string) ($r['grade_en'] ?? '')),
        'rating'  => trim((string) ($r['scores_en'] ?? '')),
        'cc'      => (int) ($r['displacement_num'] ?? 0),
        'color'   => trim((string) ($r['color_en'] ?? '')),
        'equip'   => substr(trim((string) ($r['equipment_en'] ?? '')), 0, 120),
        'status'  => $status,
        'images'  => json_encode(array($img)),
        'src'     => PB_BASE . '/auction#' . (int) ($r['lot_id'] ?? 0),
        'key'     => $auct . '|' . lotKey($bid),
        'auct'    => $auct,
        'day'     => $d,
    );
}

/**
 * Write one page of lots. A lot we already hold - from jpauc or from an earlier
 * read - is updated where it stands; only a lot with no match is inserted.
 * In a dry run nothing is written and the would-be outcome is counted instead.
 */
function storeLots($conn, array $rows, $dry, array &$st) {
    static $upd = null, $ins = null;
    $byDay = array();
    foreach ($rows as $r) {
        $d = (string) ($r['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !isset($r['auct_ref'], $r['bid']) || trim((string) $r['bid']) === '') {
            $st['bad']++;
            continue;
        }
        $byDay[$d][] = mapLot($r);
    }
    foreach ($byDay as $d => $lots) {
        $raw = array_values(array_unique(array_map(function ($c) { return $c['lot']; }, $lots)));
        $q = $conn->prepare("SELECT id, car_id, lot_no, images, make, model, year, mileage, price, auction, status
                               FROM cars WHERE auction_on = ? AND lot_no IN ("
                            . implode(',', array_fill(0, count($raw), '?')) . ")");
        $args = array_merge(array($d), $raw);
        $q->bind_param(str_repeat('s', count($args)), ...$args);
        $q->execute();
        $have = array();
        $bare = array();      // rows with no picture address to read a code from
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $k = storedKey($row['images']);
            if ($k !== null) {
                $have[$k] = $row;
            } else {
                $bare[lotKey($row['lot_no']) . '|' . strtoupper(trim($row['make'])) . '|' . strtoupper(trim($row['model']))][] = $row;
            }
        }
        $q->close();

        foreach ($lots as $c) {
            $st['keys'][$c['key']] = true;
            $old = $have[$c['key']] ?? null;
            if (!$old) {
                // A row jpauc stored without a picture carries no house code. The
                // same lot, make and model on the same day is the same car - but
                // only when exactly one row fits; two could be two houses' lots.
                $bk = lotKey($c['lot']) . '|' . strtoupper($c['make']) . '|' . strtoupper($c['model']);
                if (isset($bare[$bk]) && count($bare[$bk]) === 1) {
                    $old = $bare[$bk][0];
                    unset($bare[$bk]);
                }
            }
            if ($old) {
                $kind = (strpos($old['car_id'], 'pb-') === 0) ? 'updated' : 'adopted';
                $st[$kind]++;
                if ($dry) {
                    $st['priceSame'] = ($st['priceSame'] ?? 0) + ((float) $old['price'] == $c['price'] ? 1 : 0);
                    $st['kmSame'] = ($st['kmSame'] ?? 0) + ((int) $old['mileage'] == $c['km'] ? 1 : 0);
                    $st['modelSame'] = ($st['modelSame'] ?? 0)
                        + (strtoupper(trim($old['make'] . ' ' . $old['model'])) === strtoupper($c['make'] . ' ' . $c['model']) ? 1 : 0);
                    if ($kind === 'adopted' && count($st['samples']) < 4) {
                        $st['samples'][] = sprintf("  SAME CAR  ours: %-12s %s %s %s | %s km | %s yen | %s\n            PB  : %-12s %s %s %s | %s km | %s yen | %s",
                            $old['auction'], $old['make'], $old['model'], $old['year'], $old['mileage'], (float) $old['price'], $old['status'],
                            $c['hall'], $c['make'], $c['model'], $c['year'], $c['km'], $c['price'], $c['status']);
                    }
                    continue;
                }
                if ($upd === null) {
                    $upd = $conn->prepare("UPDATE cars SET lot_no=?, make=?, model=?, year=?,
                          mileage=IF(? > 0, ?, mileage), price=IF(? > 0, ?, price),
                          sold_price=IF(? > 0, ?, sold_price), auction=?, auction_date=?,
                          auction_time=COALESCE(?, auction_time),
                          chassis=COALESCE(NULLIF(?, ''), chassis), transmission=COALESCE(NULLIF(?, ''), transmission),
                          grade=COALESCE(NULLIF(?, ''), grade), rating=COALESCE(NULLIF(?, ''), rating),
                          engine_cc=IF(? > 0, ?, engine_cc), color=COALESCE(NULLIF(?, ''), color),
                          equipment=COALESCE(NULLIF(?, ''), equipment), status=?,
                          images=IF(CHAR_LENGTH(images) > 2, images, ?), source_url=?,
                          source_section='japan', last_updated=NOW()
                          WHERE id=?");
                }
                $id = (int) $old['id'];
                $upd->bind_param('sssi' . 'ii' . 'dd' . 'dd' . 'ss' . 's' . 'ssss' . 'ii' . 'ss' . 's' . 'ss' . 'i',
                    $c['lot'], $c['make'], $c['model'], $c['year'],
                    $c['km'], $c['km'], $c['price'], $c['price'],
                    $c['sold'], $c['sold'], $c['hall'], $c['date'],
                    $c['time'],
                    $c['chassis'], $c['trans'], $c['grade'], $c['rating'],
                    $c['cc'], $c['cc'], $c['color'], $c['equip'],
                    $c['status'],
                    $c['images'], $c['src'], $id);
                $upd->execute();
                continue;
            }
            $st['new']++;
            if ($dry) {
                continue;
            }
            if ($ins === null) {
                $ins = $conn->prepare("INSERT INTO cars
                      (car_id, lot_no, make, model, year, mileage, price, currency, sold_price,
                       auction, auction_date, auction_time, chassis, transmission, grade, rating,
                       engine_cc, color, equipment, status, images, source_url, source_section,
                       last_updated, created_at)
                     VALUES (?,?,?,?,?,?,?,'yen',?,?,?,?,?,?,?,?,?,?,?,?,?,?,'japan',NOW(),NOW())
                     ON DUPLICATE KEY UPDATE lot_no=VALUES(lot_no), make=VALUES(make), model=VALUES(model),
                       year=VALUES(year), mileage=VALUES(mileage), price=VALUES(price),
                       sold_price=VALUES(sold_price), auction=VALUES(auction), auction_date=VALUES(auction_date),
                       auction_time=COALESCE(VALUES(auction_time), auction_time), chassis=VALUES(chassis),
                       transmission=VALUES(transmission), grade=VALUES(grade), rating=VALUES(rating),
                       engine_cc=VALUES(engine_cc), color=VALUES(color), equipment=VALUES(equipment),
                       status=VALUES(status), images=VALUES(images), source_url=VALUES(source_url),
                       source_section='japan', last_updated=NOW()");
            }
            $ins->bind_param('ssss' . 'ii' . 'dd' . 'sss' . 'ssss' . 'i' . 'sssss',
                $c['car_id'], $c['lot'], $c['make'], $c['model'],
                $c['year'], $c['km'],
                $c['price'], $c['sold'],
                $c['hall'], $c['date'], $c['time'],
                $c['chassis'], $c['trans'], $c['grade'], $c['rating'],
                $c['cc'],
                $c['color'], $c['equip'], $c['status'], $c['images'], $c['src']);
            $ins->execute();
        }
    }
}

/**
 * A house read to its last page: whatever we still offer for that day and
 * house code that the read did not touch has left the source's listing.
 * Only offered rows are retired (sold and unsold are off the list already),
 * never more than the cap, and never after a read whose count fell while it
 * was being taken - a shrinking listing can slide a lot past the reader.
 *
 * Which rows are this house's: those carrying its code AND either its name or
 * no Pacific Boeki mark at all. A row another house already owns (same code is
 * possible - the "Kyouyuu" shared-stock houses may carry the code of the hall
 * a lot came from) is left for that house's own read to judge.
 */
function retireUnseen($conn, array $cur, $dry, array $seenKeys = array()) {
    $n = 0;
    $cap = RETIRE_CAP + (int) ($cur['cnt0'] / 10);
    foreach ((array) $cur['codes'] as $code) {
        $like = '%&auct=' . (int) $code . '&bid=%';
        // The WHERE alone - an UPDATE takes no FROM, and putting "FROM cars" in
        // here once crashed every run at the end of its first whole house.
        $where = "source_section = 'japan' AND auction_on = ? AND images LIKE ?
                  AND (auction = ? OR source_url IS NULL OR source_url NOT LIKE 'https://pacificboeki.jp%')
                  AND last_updated < ? AND (status IS NULL OR status LIKE 'available%')";
        if ($dry) {
            // Nothing was written, so "not touched" is worked out from the keys read.
            $q = $conn->prepare("SELECT images FROM cars WHERE $where");
            $q->bind_param('ssss', $cur['d'], $like, $cur['h'], $cur['since']);
            $q->execute();
            foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $k = storedKey($row['images']);
                if ($k !== null && !isset($seenKeys[$k])) { $n++; }
            }
            $q->close();
            continue;
        }
        $q = $conn->prepare("UPDATE cars SET status = 'removed', last_updated = NOW() WHERE $where LIMIT " . max(0, $cap - $n));
        $q->bind_param('ssss', $cur['d'], $like, $cur['h'], $cur['since']);
        $q->execute();
        $n += max(0, $q->affected_rows);
        $q->close();
        if ($n >= $cap) {
            pbLog("retire cap hit: {$cur['h']} {$cur['d']} ($cap)");
            break;
        }
    }
    return $n;
}

/**
 * How many DIFFERENT rows of this day and house the read has written.
 *
 * The rows a read returned is not that number. Pacific Boeki rebuilds its lots
 * from the auction feed while we page through them - a lot deleted and made again
 * gets a new id and jumps to an earlier page, one we have already passed - so a
 * read can return 2,001 rows and still have missed lots, having seen some twice.
 * Found on the first pass: ten houses 72 lots short, every one of them "whole".
 */
function touchedRows($conn, array $c) {
    // Every row this read wrote, whatever its status: Pacific Boeki reports
    // result "removed" for a withdrawn lot (455 of them on 12 September) and we
    // store that word. Leaving those out made a house look SHORT for ever - it
    // read again every half hour and was read twice for nothing, and it was
    // read as "the source lists lots twice", which it does not.
    $q = $conn->prepare("SELECT COUNT(*) FROM cars WHERE auction_on = ? AND auction = ?
                          AND last_updated >= ?");
    $q->bind_param('sss', $c['d'], $c['h'], $c['since']);
    $q->execute();
    $n = (int) $q->get_result()->fetch_row()[0];
    $q->close();
    return $n;
}

/** Has every house Pacific Boeki lists on this day been read whole? */
function dayReadWhole(array $s, $d) {
    $any = false;
    foreach ($s['parts'] as $p) {
        if ($p['d'] !== $d) {
            continue;
        }
        $any = true;
        // A house read short is not read: sweeping on it would take off the very
        // lots the read slid past.
        if (empty($p['readAt']) || !empty($p['short'])) {
            return false;
        }
    }
    return $any;
}

/**
 * The owner's rule, 12 September: a jpauc car that Pacific Boeki also has stays;
 * one it does NOT have leaves. retireUnseen() does that house by house - but only
 * for houses Pacific Boeki lists on that day. A jpauc row whose house it does not
 * list at all (or that has no picture to read a code from) was never in any read,
 * so nothing ever judged it. Once every house of the day has been read whole,
 * whatever jpauc left on that day that Pacific Boeki took up nowhere goes too.
 *
 * Once per day - jpauc is paused, so no new jpauc rows arrive to sweep again.
 * Refused when it would take more than half of the day: that means the reading
 * went wrong, not that half the auction vanished.
 */
function sweepDay($conn, array &$s, $d) {
    if (!empty($s['swept'][$d]) || !dayReadWhole($s, $d)) {
        return 0;
    }
    $sellable = "source_section = 'japan' AND auction_on = ? AND (status IS NULL OR status LIKE 'available%')";
    $left = "$sellable AND car_id NOT LIKE 'pb-%'
             AND (source_url IS NULL OR source_url NOT LIKE 'https://pacificboeki.jp%')";
    $count = function ($where) use ($conn, $d) {
        $q = $conn->prepare("SELECT COUNT(*) FROM cars WHERE $where");
        $q->bind_param('s', $d);
        $q->execute();
        $n = (int) $q->get_result()->fetch_row()[0];
        $q->close();
        return $n;
    };
    $n = $count($left);
    $all = $count($sellable);
    if ($n > 0 && $n * 2 > $all) {
        pbLog("day sweep REFUSED $d: $n of $all offered rows untouched by Pacific Boeki - looks like a bad read");
        return 0;
    }
    $q = $conn->prepare("UPDATE cars SET status = 'removed', last_updated = NOW() WHERE $left");
    $q->bind_param('s', $d);
    $q->execute();
    $gone = max(0, $q->affected_rows);
    $q->close();
    $s['swept'][$d] = time();
    pbLog("day sweep $d: retired $gone jpauc rows Pacific Boeki does not list");
    return $gone;
}

/**
 * Finished sale days and long-retired rows out of the table - the upkeep the
 * jpauc harvester did every run, which stopped when it was paused. Same rule:
 * a vehicle somebody has touched (a bid, an order, an enquiry) is never deleted.
 */
function purgeDead($conn, $limit = 5000, $seconds = 8.0) {
    static $keep = null;
    if ($keep === null) {
        $keep = array();
        $r = $conn->query(
            "SELECT c.TABLE_NAME t FROM INFORMATION_SCHEMA.COLUMNS c
               JOIN INFORMATION_SCHEMA.TABLES tb
                 ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'car_id'
                AND c.TABLE_NAME <> 'cars' AND tb.TABLE_TYPE = 'BASE TABLE'");
        $tables = array();
        while ($r && $w = $r->fetch_row()) { $tables[] = $w[0]; }
        foreach ($tables as $t) {
            $q = $conn->query("SELECT DISTINCT car_id FROM `$t` WHERE car_id IS NOT NULL AND car_id <> ''");
            while ($q && $w = $q->fetch_row()) { $keep[$w[0]] = true; }
        }
    }
    $dead = "((source_section = 'japan' AND auction_on < CURDATE())
              OR ((status = 'removed' OR status LIKE 'removed%')
                  AND last_updated < NOW() - INTERVAL 1 HOUR))";
    $guard = '';
    if ($keep) {
        $esc = array();
        foreach (array_keys($keep) as $id) {
            $esc[] = "'" . $conn->real_escape_string($id) . "'";
        }
        $guard = ' AND car_id NOT IN (' . implode(',', $esc) . ')';
    }
    $done = 0;
    $until = microtime(true) + $seconds;
    do {
        $conn->query("DELETE FROM cars WHERE $dead$guard LIMIT " . (int) $limit);
        $n = max(0, $conn->affected_rows);
        $done += $n;
    } while ($n > 0 && microtime(true) < $until);
    return $done;
}

/* -------------------------------------------------------------------- run */

global $conn;
$s = stateLoad();

if (isset($opts['reset'])) {
    $s['halted'] = '';
    $s['haltedAt'] = '';
    stateSave($s);
    echo "halt hataya\n";
    exit;
}

/* The comparison, with nothing written: one house, a few pages, and what the
   real run WOULD do with them - how many are the same car as one we hold,
   how many are new, and how many of ours that house no longer lists. */
if ($dry) {
    $d    = (string) ($opts['date'] ?? jstToday());
    $hall = (string) ($opts['hall'] ?? '');
    $pages = max(1, min(30, (int) ($opts['pages'] ?? 2)));
    $why = sessionOk($s);
    printf("session: %s (uid %d)\n", $why === '' ? 'THEEK' : $why, $s['uid']);
    if ($why !== '') { exit; }
    if ($hall === '') {
        list($i, $why) = pbCall('/api/v1/auction/filter/initial', new stdClass(), $s);
        $data = $i['data'] ?? $i;
        echo "auction_dates as sent: " . substr(json_encode($data['auction_dates'] ?? null), 0, 600) . "\n";
        list($h, $why) = pbCall('/api/v1/auction/filter/halls', array('dates' => array($d)), $s);
        echo "houses on $d:\n";
        foreach ((array) ($h['data'] ?? array()) as $row) { printf("  %-22s %6d\n", $row['name'], $row['count']); }
        exit;
    }
    $st = array('new' => 0, 'adopted' => 0, 'updated' => 0, 'bad' => 0, 'samples' => array(), 'keys' => array());
    $since = dbNow($conn);
    $codes = array();
    $read = 0;
    $cnt = 0;
    for ($p = 1; $p <= $pages; $p++) {
        list($r, $why) = pbCall('/api/v1/auction/search', array('dates' => array($d), 'auction_hall_names' => array($hall),
                                'sort_by' => 'year', 'sort_order' => 'desc', 'page' => $p, 'limit' => PER_PAGE), $s);
        if ($r === null) { echo "read fail: $why\n"; break; }
        $rows = (array) ($r['data'] ?? array());
        $cnt = (int) ($r['meta']['count'] ?? 0);
        foreach ($rows as $row) { $codes[(int) $row['auct_ref']] = true; }
        $read += count($rows);
        storeLots($conn, $rows, true, $st);
        printf("page %d/%d: %d lots\n", $p, (int) ($r['meta']['total_pages'] ?? 0), count($rows));
        if (count($rows) < PER_PAGE) { break; }
    }
    printf("\n%s %s - PB lists %d, read %d, house code(s) %s\n", $d, $hall, $cnt, $read, implode(',', array_keys($codes)));
    printf("  already ours (same car, would be UPDATED, not added): %d\n", $st['adopted'] + $st['updated']);
    printf("     of those, same make+model %d, same start price %d, same km %d\n",
           $st['modelSame'] ?? 0, $st['priceSame'] ?? 0, $st['kmSame'] ?? 0);
    printf("  new (would be ADDED): %d\n", $st['new']);
    $q = $conn->prepare("SELECT COUNT(*) FROM cars WHERE source_section='japan' AND auction_on = ? AND images LIKE ?
                          AND (status IS NULL OR status LIKE 'available%')");
    foreach (array_keys($codes) as $code) {
        $like = '%&auct=' . $code . '&bid=%';
        $q->bind_param('ss', $d, $like);
        $q->execute();
        printf("  ours on sale today for code %d: %d\n", $code, (int) $q->get_result()->fetch_row()[0]);
    }
    if ($read >= $cnt && $cnt > 0) {
        $cur = array('d' => $d, 'h' => $hall, 'codes' => array_keys($codes), 'since' => $since, 'cnt0' => $cnt);
        printf("  ours that PB no longer lists (would be RETIRED): %d\n", retireUnseen($conn, $cur, true, $st['keys']));
    } else {
        echo "  (house not read to its end - retiring is only judged on a whole read)\n";
    }
    echo "\n" . implode("\n", $st['samples']) . "\n";
    printf("\nrequests used: %d\n", $s['used']);
    exit;
}

if ($s['halted'] !== '') {
    echo "HALTED: {$s['halted']} (since {$s['haltedAt']})\n";
    exit;
}
if ($s['used'] >= DAILY_BUDGET) {
    echo "budget khatam: {$s['used']} / " . DAILY_BUDGET . "\n";
    exit;
}

$s['runs']++;
$runStart = microtime(true);
$st = array('new' => 0, 'adopted' => 0, 'updated' => 0, 'bad' => 0, 'samples' => array(), 'keys' => array());
$retired = 0;
$pages = 0;
$halt = '';
$stop = '';

$purged = 0;
try {
    $purged = purgeDead($conn);
} catch (Throwable $e) {
    pbLog('purge failed: ' . $e->getMessage());
}
$s['purged'] += $purged;

if (time() - (int) $s['sessionAt'] > SESSION_EVERY) {
    $why = sessionOk($s);
    if ($why !== '') {
        (strpos($why, 'HALT ') === 0) ? ($halt = substr($why, 5)) : ($stop = $why);
    }
}
if ($halt === '' && $stop === '' && (time() - (int) $s['surveyAt'] > SURVEY_EVERY || !$s['parts'])) {
    $why = survey($s);
    if ($why !== '') {
        (strpos($why, 'HALT ') === 0) ? ($halt = substr($why, 5)) : ($stop = $why);
    }
}

while ($halt === '' && $stop === '' && $s['used'] < DAILY_BUDGET && $pages < $maxRequests
       && microtime(true) - $runStart < RUN_LIMIT) {
    if (!$s['cur']) {
        $key = pickPart($s);
        if ($key === null) {
            break;                                    // everything is fresh enough
        }
        $p = $s['parts'][$key];
        // A house read short is read again the OTHER way round, counting from
        // when the first attempt began: a lot that jumped past the reader one way
        // is met coming the other, and the two reads are judged together.
        $again = !empty($p['short']) && !empty($p['since0']);
        $s['cur'] = array('key' => $key, 'd' => $p['d'], 'h' => $p['h'], 'page' => 1, 'pages' => 0,
                          'since' => $again ? $p['since0'] : dbNow($conn),
                          'order' => ((int) ($p['tries'] ?? 0) % 2 === 1) ? 'asc' : 'desc',
                          'rows' => 0, 'cnt0' => 0, 'cnt' => 0, 'codes' => array(), 'bad' => 0, 'avail' => 0, 'res' => 0);
    }
    $c = &$s['cur'];
    list($r, $why) = pbCall('/api/v1/auction/search', array('dates' => array($c['d']), 'auction_hall_names' => array($c['h']),
                            'sort_by' => 'year', 'sort_order' => ($c['order'] ?? 'desc'), 'page' => $c['page'],
                            'limit' => PER_PAGE), $s);
    $pages++;
    if ($r === null) {
        (strpos($why, 'HALT ') === 0) ? ($halt = substr($why, 5)) : ($stop = $why);
        unset($c);
        break;
    }
    $rows = (array) ($r['data'] ?? array());
    $cnt  = (int) ($r['meta']['count'] ?? 0);
    if ($c['page'] === 1) {
        $c['cnt0'] = $cnt;
    }
    $c['cnt'] = $cnt;
    $c['pages'] = (int) ($r['meta']['total_pages'] ?? 0);
    foreach ($rows as $row) {
        if (isset($row['auct_ref']) && !in_array((int) $row['auct_ref'], $c['codes'], true)) {
            $c['codes'][] = (int) $row['auct_ref'];
        }
        // A lot with no day, house code or lot number cannot be stored - counted,
        // so a house that is short because of them says so in the log.
        if (empty($row['date']) || !isset($row['auct_ref']) || trim((string) ($row['bid'] ?? '')) === '') {
            $c['bad'] = (int) ($c['bad'] ?? 0) + 1;
        }
        // Lots still waiting for their result - a house with none left is done
        // selling for the day and need not be read every half hour.
        $res = strtolower(trim((string) ($row['result_en'] ?? '')));
        if ($res === '' || $res === 'n/a' || $res === 'available') {
            $c['avail'] = (int) ($c['avail'] ?? 0) + 1;
        } elseif (!in_array($res, array('removed', 'withdrawn', 'cancel'), true)) {
            // A lot that went through the hall - sold, unsold, negotiate sold. A
            // house with none of these is not selling at auction: see partInterval().
            $c['res'] = (int) ($c['res'] ?? 0) + 1;
        }
    }
    try {
        storeLots($conn, $rows, false, $st);
    } catch (Throwable $e) {
        $stop = 'db: ' . $e->getMessage();
        unset($c);
        break;
    }
    $c['rows'] += count($rows);

    if (count($rows) < PER_PAGE || $c['page'] >= $c['pages']) {
        // The house is read to its end. It is WHOLE only when as many different
        // rows were written as Pacific Boeki says it holds, and the listing did
        // not shrink under it. Short, it is read again in five minutes and nothing
        // is retired - a lot the read slid past must not be taken off the list.
        // The same shortfall twice running is Pacific Boeki listing one lot
        // twice over, not us missing it, and is accepted.
        $key = $c['key'];
        $touched = 0;
        try {
            $touched = touchedRows($conn, $c);
        } catch (Throwable $e) {
            pbLog('count failed: ' . $e->getMessage());
        }
        $short = max(0, $c['cnt'] - $touched);
        // After a second read the other way round, a sliver still missing is
        // Pacific Boeki listing some lots twice - ARAI Oyama VT came up 8 short of
        // 6,105 on two reads running. Up to 0.2% (never under 5) is accepted then;
        // anything left genuinely unread is met by the next read on schedule, and
        // a lot retired by mistake is put back by the read that sees it.
        $tol = max(5, (int) floor($c['cnt'] * 0.002));
        $wasShort = !empty($s['parts'][$key]['short']);
        // WHOLE decides the schedule: a house a sliver short after being read both
        // ways is let back onto its own timetable rather than read every five
        // minutes for ever. EXACT decides RETIRING, and nothing else does: a lot
        // the read slid past must never be taken off the list because of it.
        // (An earlier note here said the source lists some lots twice - walking a
        // whole house through it proved otherwise: 3,183 rows, 3,183 different
        // lots. The shortfalls were withdrawn lots this count used to skip.)
        $exact = ($short === 0 && $c['cnt'] >= $c['cnt0']);
        $whole = ($c['cnt'] >= $c['cnt0']) && ($short === 0 || ($wasShort && $short <= $tol));
        $gone = 0;
        if ($exact) {
            // A failure here must not leave the house "in hand" - every later
            // run would re-read its last page and fail at the same spot.
            try {
                $gone = retireUnseen($conn, $c, false);
            } catch (Throwable $e) {
                pbLog('retire failed: ' . $c['h'] . ' ' . $c['d'] . ' - ' . $e->getMessage());
            }
        }
        $retired += $gone;
        $note = '';
        if (isset($s['parts'][$key])) {
            $pp = &$s['parts'][$key];
            $pp['codes'] = $c['codes'];
            $pp['nRead'] = $c['cnt'];            // what the survey compares against
            $pp['avail'] = (int) ($c['avail'] ?? 0);
            $pp['res'] = (int) ($c['res'] ?? 0);
            $pp['dirty'] = 0;
            if ($whole) {
                $pp['readAt'] = time();
                $pp['short'] = 0;
                $pp['tries'] = 0;
                $pp['since0'] = '';
                if ($short > 0) { $note = " - $short short after reading both ways: taken as listed twice at the source"; }
            } else {
                // Short: again in five minutes, the other way round - three times
                // at most, then back on its schedule still marked short, so the day
                // is never swept on it.
                $pp['short'] = $short;
                $pp['tries'] = (int) ($pp['tries'] ?? 0) + 1;
                $pp['since0'] = $c['since'];
                if ($pp['tries'] >= 3) {
                    $pp['readAt'] = time();
                    $pp['tries'] = 0;
                    $pp['since0'] = '';
                    $note = ' - still SHORT after 3 reads, back on its schedule';
                } else {
                    $pp['readAt'] = time() - partInterval($c['d'], $pp, (int) $s['used']) + 300;
                    $note = ' - SHORT, reading again in 5 min the other way round';
                }
            }
            if (!empty($c['bad'])) { $note .= ' (' . $c['bad'] . ' lots without day/code/lot number)'; }
            /* The cap stopped this read short of taking off everything the house no
               longer lists - Kyouyuu Stock on 14 September dropped 118 and the cap took
               112, leaving 6 cars on the portal that the source had withdrawn, until the
               house's next turn four hours later. Read it again in ten minutes for the
               rest. The cap itself is unchanged: each read still takes off no more
               than it allows, and only an exact read takes off anything. */
            if ($gone > 0 && $gone >= RETIRE_CAP + (int) ($c['cnt0'] / 10)) {
                $pp['readAt'] = time() - partInterval($c['d'], $pp, (int) $s['used']) + 600;
                $note .= ' - retire cap hit, reading again in 10 min for the rest';
            }
            unset($pp);
        }
        $s['reads']++;
        pbLog(sprintf('read %s %s: %d lots, %d different of %d%s, retired %d', $c['d'], $c['h'], $c['rows'],
                      $touched, $c['cnt'], $note, $gone));
        $day = $c['d'];
        unset($c);
        $s['cur'] = null;
        try {
            $retired += sweepDay($conn, $s, $day);
        } catch (Throwable $e) {
            pbLog('day sweep failed: ' . $day . ' - ' . $e->getMessage());
        }
    } else {
        $c['page']++;
        unset($c);
    }
    stateSave($s);
}

if ($halt !== '') {
    $s['halted'] = $halt;
    $s['haltedAt'] = gmdate('Y-m-d H:i');
}
$s['new'] += $st['new'];
$s['adopted'] += $st['adopted'];
$s['updated'] += $st['updated'];
$s['retired'] += $retired;
stateSave($s);

$line = sprintf('run %d: req %d, pages %d | new %d, same-car %d, updated %d, retired %d, purged %d | %.0fs%s%s',
    $s['runs'], $s['used'], $pages, $st['new'], $st['adopted'], $st['updated'], $retired, $purged,
    microtime(true) - $runStart, $halt !== '' ? ' | HALT ' . $halt : '', $stop !== '' ? ' | stop ' . $stop : '');
pbLog($line);
echo $line . "\n";
if ($s['cur']) {
    printf("in hand: %s %s page %d/%d\n", $s['cur']['d'], $s['cur']['h'], $s['cur']['page'], $s['cur']['pages']);
}
