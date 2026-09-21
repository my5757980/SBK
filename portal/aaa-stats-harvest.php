<?php
/**
 * Past auction prices, from bid.aaajapan.com.
 *
 * jpauc.com - the source the auction list is built from - publishes nothing
 * about what a lot actually sold for. aaajapan does: 1,212,520 results up to
 * 5 September 2026, and the owner's account may read them.
 *
 * This fills `car_stats` and touches nothing else. The auction list is jpauc's
 * and stays jpauc's: the two sites carry near enough the same stock, and mixing
 * a second source into the list is how a portal ends up offering vehicles that
 * are not for sale. See specs/008-auction-statistics/spec.md.
 *
 * Run it the way the jpauc harvester is run - a cron line every five minutes:
 *
 *   /usr/local/bin/php /path/aaa-stats-harvest.php --max=270
 *
 * or over HTTP with the token below. Each run is bounded by the clock and
 * leaves its place in a state file, so a complete first pull - about 60,000
 * requests at one a second, some seventeen hours - happens by itself across as
 * many runs as it takes, with nobody's computer left on.
 */

require_once __DIR__ . '/includes/config.php';

/* ------------------------------------------------------------------ setup */

/* The token is not written here any more (21 September 2026): it lives in the
   server's .env, the one file that already knows the database password and is
   never committed. An empty token refuses everything rather than letting an
   empty one in - hash_equals('', '') is true, and that would be an open door. */
define('AAA_TOKEN', env_get('AAA_TOKEN', ''));
const AAA_STATE  = __DIR__ . '/aaa-stats-state.json';
const AAA_LOCK   = __DIR__ . '/aaa-stats.lock';
const AAA_COOKIE = __DIR__ . '/aaa-stats.cookie';

const AAA_BASE   = 'https://bid.aaajapan.com';
const AAA_USER   = 'Abdul Aziz 111';
const AAA_PASS   = '112233';

/** Seconds between requests. The owner's rule, and it is not negotiable
 *  without him: a previous supplier blocked this server's address when the
 *  rate went up. */
const AAA_GAP = 1.0;

/** Seconds a run may take. The cron fires every five minutes; this leaves a
 *  margin so two runs never overlap. */
const AAA_RUN = 270;

/** Requests a day. The jpauc harvester has its own budget; this is on top of
 *  it, and together they stay under one a second averaged over the day. */
const AAA_BUDGET = 20000;

/** Rows the source returns per page. It accepts list_size=50 and ignores it. */
const AAA_PER_PAGE = 20;

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (AAA_TOKEN === '' || !hash_equals(AAA_TOKEN, (string) ($_GET['t'] ?? ''))) {
        http_response_code(403);
        echo "nahi\n";
        exit;
    }
}
@set_time_limit(0);
$opts   = $isCli ? getopt('', array('max::', 'reset::', 'maker::')) : $_GET;
$runFor = max(30, min(AAA_RUN, (int) ($opts['max'] ?? AAA_RUN)));

/* -------------------------------------------------------------- the table */

/**
 * The table, made if it is not there.
 *
 * Doing it here rather than in a migration file is deliberate: this harvester
 * is deployed by copying one file onto the server, and a schema that needs a
 * second manual step is a schema that will one day be missing.
 */
function statsTable($conn) {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS car_stats (
           stat_id      VARCHAR(32)  NOT NULL,
           maker        VARCHAR(64)  NOT NULL DEFAULT '',
           model        VARCHAR(128) NOT NULL DEFAULT '',
           lot_no       VARCHAR(32)  NOT NULL DEFAULT '',
           auction      VARCHAR(96)  NOT NULL DEFAULT '',
           sold_on      DATE         NULL,
           sold_time    VARCHAR(16)  NULL,
           year         SMALLINT     NULL,
           engine_cc    INT          NULL,
           mileage      INT          NULL,
           chassis      VARCHAR(64)  NULL,
           grade        VARCHAR(32)  NULL,
           model_grade  VARCHAR(96)  NULL,
           transmission VARCHAR(32)  NULL,
           rating       VARCHAR(16)  NULL,
           colour       VARCHAR(48)  NULL,
           start_price  BIGINT       NULL,
           final_price  BIGINT       NULL,
           result       VARCHAR(32)  NULL,
           photos       TEXT         NULL,
           source       VARCHAR(24)  NOT NULL DEFAULT 'aaajapan',
           last_seen    DATETIME     NOT NULL,
           created_at   DATETIME     NOT NULL,
           PRIMARY KEY (stat_id),
           KEY k_maker_model (maker, model),
           KEY k_sold_on (sold_on),
           KEY k_chassis (chassis),
           KEY k_lot (lot_no),
           KEY k_auction (auction)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ------------------------------------------------------------------ state */

function stLoad() {
    $fresh = array(
        'day' => date('Y-m-d'), 'used' => 0, 'runs' => 0,
        'stored' => 0, 'halted' => '',
        'makers' => array(),      // id => name
        'mIdx'   => 0,            // which maker we are on
        'page'   => 1,            // which page of that maker
        'totals' => array(),      // maker id => rows the source declares
        'done'   => array(),      // maker ids finished this sweep
        'sweeps' => 0,
    );
    if (!is_file(AAA_STATE)) {
        return $fresh;
    }
    $s = json_decode((string) file_get_contents(AAA_STATE), true);
    if (!is_array($s)) {
        return $fresh;
    }
    $s = $s + $fresh;
    if (($s['day'] ?? '') !== date('Y-m-d')) {
        $s['day'] = date('Y-m-d');
        $s['used'] = 0;
        $s['runs'] = 0;
    }
    return $s;
}

function stSave($s) {
    @file_put_contents(AAA_STATE, json_encode($s), LOCK_EX);
}

/* --------------------------------------------------------------- the wire */

/**
 * One request, on the session cookie.
 *
 * @return array(body, err) - err is 'refused' when the source turns us away,
 *         which stops everything rather than being retried.
 */
function aaaFetch($url, $post = null, $referer = '/st?classic') {
    $ch = curl_init($url);
    $opt = array(
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_ENCODING => '',
        CURLOPT_COOKIEFILE => AAA_COOKIE, CURLOPT_COOKIEJAR => AAA_COOKIE,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => array('Referer: ' . AAA_BASE . $referer),
    );
    if ($post !== null) {
        $opt[CURLOPT_POST] = 1;
        $opt[CURLOPT_POSTFIELDS] = $post;
    }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 403 || $code === 429) {
        return array(null, 'refused');
    }
    if ($code !== 200 || !$body) {
        return array(null, 'http ' . $code);
    }
    return array((string) $body, '');
}

/**
 * Sign in and come back with the search form as the site would hand it to a
 * browser, plus the maker list.
 *
 * The login is a plain form post - username, password, is_login, ref - and the
 * session lands in a cookie. Three requests, and only when the session has gone
 * stale.
 *
 * @return array(ok, fields, makers, err)
 */
function aaaSignIn(&$used) {
    @unlink(AAA_COOKIE);
    list($b, $e) = aaaFetch(AAA_BASE . '/aj_3', null, '/aj_3');
    $used++;
    if ($e === 'refused') { return array(false, array(), array(), 'refused'); }

    usleep((int) (AAA_GAP * 1000000));
    list($b, $e) = aaaFetch(AAA_BASE . '/aj_3', http_build_query(array(
        'username' => AAA_USER, 'password' => AAA_PASS,
        'is_login' => '1', 'ref' => 'aj_3')), '/aj_3');
    $used++;
    if ($e === 'refused') { return array(false, array(), array(), 'refused'); }
    if ($b === null)      { return array(false, array(), array(), $e); }

    usleep((int) (AAA_GAP * 1000000));
    list($h, $e) = aaaFetch(AAA_BASE . '/st?classic');
    $used++;
    if ($e === 'refused') { return array(false, array(), array(), 'refused'); }
    if ($h === null)      { return array(false, array(), array(), $e); }
    if (strpos($h, 'logout') === false) {
        return array(false, array(), array(), 'login nahi hua');
    }
    $fields = aaaForm($h);
    if (!$fields) {
        return array(false, array(), array(), 'search form nahi mila');
    }
    return array(true, $fields, aaaMakers($h), '');
}

/** The search form's fields, as the page carries them. */
function aaaForm($html) {
    $i = strpos($html, '<form id=poisk class=poisk');
    if ($i === false) { return array(); }
    $j = strpos($html, '</form>', $i);
    $seg = substr($html, $i, $j - $i + 7);
    $out = array();
    if (preg_match_all('/<input[^>]*>/i', $seg, $m)) {
        foreach ($m[0] as $tag) {
            if (!preg_match('/name=[\'"]?([\w\[\]]+)/i', $tag, $n)) { continue; }
            $v = preg_match('/value=(["\'])(.*?)\1/is', $tag, $vv) ? $vv[2] : '';
            $out[$n[1]] = $v;
        }
    }
    // The site's own anti-scraping decoration; it is not a search field.
    foreach (array_keys($out) as $k) {
        if (stripos($k, 'lose_time_here') === 0) { unset($out[$k]); }
    }
    return $out;
}

/** `0:Any;1:TOYOTA;2:NISSAN;...` from the page. */
function aaaMakers($html) {
    if (!preg_match('/id=manuf_str[^>]*>([^<]*)</i', $html, $m)) { return array(); }
    $out = array();
    foreach (explode(';', $m[1]) as $bit) {
        $p = explode(':', $bit, 2);
        if (count($p) === 2 && trim($p[0]) !== '' && strcasecmp(trim($p[1]), 'Any') !== 0) {
            $out[(string) trim($p[0])] = trim($p[1]);
        }
    }
    return $out;
}

/* -------------------------------------------------------------- the query */

/**
 * One page of one maker's results.
 *
 * `model` must be sent EMPTY. Sending "Any" - which is what the site shows in
 * the box, and the obvious thing to send - returns an empty body with no error,
 * and looks exactly like an account with no entitlement. That distinction cost
 * two hours; it is written down here so it costs nobody else any.
 */
function aaaPage(array $fields, $vendorId, $page) {
    $f = $fields;
    $f['vendor']    = (string) $vendorId;
    $f['model']     = '';
    $f['page']      = (string) max(1, (int) $page);
    $f['list_size'] = (string) AAA_PER_PAGE;
    $f['tpl']       = '';
    $f['is_stat']   = '0';
    $url = AAA_BASE . '/st?file=loader&ajx=' . (int) (microtime(true) * 1000) . '0-form';
    return aaaFetch($url, http_build_query($f));
}

/**
 * The rows and the header out of one answer.
 *
 * The body arrives as a JavaScript call carrying a JS object literal inside a
 * JS string - quotes escaped twice over. It is unwrapped rather than parsed:
 * a real parser for a language we are not running is more to go wrong than the
 * shape of these rows warrants.
 *
 * @return array(rows, navi)
 */
function aaaParse($body) {
    if (!preg_match("/'tpl_poisk':\s*'var data\s*=\s*(\{.*?\});'/s", $body, $m)) {
        return array(array(), array());
    }
    $raw = str_replace(array('\\"', "\\'", '\\/'), array('"', "'", '/'), $m[1]);

    $navi = array();
    if (preg_match('/navi:\{(.*?)\},\s*body:/s', $raw, $nm)) {
        if (preg_match_all('/(\w+):"([^"]*)"/', $nm[1], $kv, PREG_SET_ORDER)) {
            foreach ($kv as $p) { $navi[$p[1]] = $p[2]; }
        }
    }

    $rows = array();
    if (preg_match('/body:\[(.*)\]\s*\}\s*;?\s*$/s', $raw, $bm)) {
        if (preg_match_all('/\{a:"(?:[^"\\\\]|\\\\.)*".*?\}(?=,\{a:"|$)/s', $bm[1], $rm)) {
            foreach ($rm[0] as $one) {
                $r = array();
                if (preg_match_all('/(\w+):"((?:[^"\\\\]|\\\\.)*)"/', $one, $kv, PREG_SET_ORDER)) {
                    foreach ($kv as $p) { $r[$p[1]] = $p[2]; }
                }
                if ($r) { $rows[] = $r; }
            }
        }
    }
    return array($rows, $navi);
}

/**
 * What identifies a past auction result.
 *
 * The source's own key changes between sessions, so it cannot be the identity.
 * A lot number is unique inside one hall on one day, which is - as with the
 * auction list - the only thing about a row that cannot change.
 */
function statId(array $r) {
    $hall = trim($r['d'] ?? '');
    $day  = trim($r['e'] ?? '');
    $lot  = trim($r['c'] ?? '');
    if ($hall === '' || $day === '' || $lot === '') { return null; }
    return substr(sha1($hall . '|' . $day . '|' . $lot), 0, 32);
}

/** `09.06.2026` -> `2026-06-09`, or null. */
function statDay($s) {
    $s = trim((string) $s);
    if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) { return null; }
    return $m[3] . '-' . $m[2] . '-' . $m[1];
}

function statNum($s) {
    $n = preg_replace('/\D+/', '', (string) $s);
    return $n === '' ? null : (int) $n;
}

/**
 * Write a page of results.
 *
 * @return int rows written
 */
function statStore($conn, array $rows) {
    if (!$rows) { return 0; }
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $conn->prepare(
            "INSERT INTO car_stats
              (stat_id, maker, model, lot_no, auction, sold_on, sold_time, year,
               engine_cc, mileage, chassis, grade, model_grade, transmission,
               rating, colour, start_price, final_price, result, photos,
               source, last_seen, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'aaajapan',NOW(),NOW())
             ON DUPLICATE KEY UPDATE
               maker=VALUES(maker), model=VALUES(model), auction=VALUES(auction),
               sold_on=VALUES(sold_on), sold_time=VALUES(sold_time),
               year=VALUES(year), engine_cc=VALUES(engine_cc),
               mileage=IF(VALUES(mileage) > 0, VALUES(mileage), mileage),
               chassis=VALUES(chassis), grade=VALUES(grade),
               model_grade=VALUES(model_grade), transmission=VALUES(transmission),
               rating=VALUES(rating), colour=VALUES(colour),
               start_price=VALUES(start_price), final_price=VALUES(final_price),
               result=VALUES(result),
               photos=IF(CHAR_LENGTH(VALUES(photos)) > 2, VALUES(photos), photos),
               last_seen=NOW()");
        if (!$stmt) { return 0; }
    }
    $n = 0;
    foreach ($rows as $r) {
        $id = statId($r);
        if ($id === null) { continue; }

        // `b` is "MAKER MODEL" with the maker first and the model whatever is
        // left. Splitting on the first space is right for TOYOTA 86 and for
        // ALFAROMEO GIULIA alike; a maker with a space in its name would take
        // the second word into the maker, which is why the maker column is
        // filled from the maker we asked for, not from here.
        $b     = trim($r['b'] ?? '');
        $sp    = strpos($b, ' ');
        $maker = $sp === false ? $b : substr($b, 0, $sp);
        $model = $sp === false ? '' : trim(substr($b, $sp + 1));

        $lot   = trim($r['c'] ?? '');
        $hall  = trim($r['d'] ?? '');
        $day   = statDay($r['e'] ?? '');
        $time  = trim((string) ($r['f'] ?? ''), "[] \t");
        $year  = statNum($r['g'] ?? '');
        $cc    = statNum($r['h'] ?? '');
        $km    = statNum($r['i'] ?? '');
        $chas  = trim($r['j'] ?? '');
        $grade = trim($r['k'] ?? '');
        $mgr   = trim($r['l'] ?? '');
        $kpp   = trim($r['m'] ?? '');
        $rate  = trim($r['r'] ?? '');
        $col   = trim($r['w'] ?? '');
        $start = statNum($r['s'] ?? '');
        $final = statNum($r['t'] ?? '');
        $res   = trim(strip_tags((string) ($r['v'] ?? '')));
        $pics  = json_encode(array_values(array_filter(array(
            trim($r['x'] ?? ''), trim($r['y'] ?? ''), trim($r['z'] ?? '')), 'strlen')));

        // The columns run ... rating, COLOUR, start_price, final_price, result,
        // photos - colour is BEFORE the two prices. The variables and the type
        // string must follow that exact order, or the price lands in colour and
        // "white" lands in a price. (Kept in step with aaa-stats-ingest.php.)
        $stmt->bind_param(
            'sssss' . 'ss' . 'iii' . 'sssss' . 's' . 'ii' . 'ss',
            $id, $maker, $model, $lot, $hall,
            $day, $time,
            $year, $cc, $km,
            $chas, $grade, $mgr, $kpp, $rate,
            $col,
            $start, $final,
            $res, $pics
        );
        if ($stmt->execute()) { $n++; }
    }
    return $n;
}

/* -------------------------------------------------------------------- run */

/* ---- a look at what the server itself gets.
   Run with ?t=...&probe=1. The site answered a laptop in Pakistan and refused
   this host on the first request, and the difference between "our request is
   wrong" and "our address is not welcome" is the whole question. */
if (isset($opts['probe'])) {
    $ch = curl_init(AAA_BASE . '/aj_3');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => 1, CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_HEADER => 1, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ));
    $b = curl_exec($ch);
    echo "http    : " . (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
    echo "curl err: " . curl_error($ch) . "\n";
    echo "bytes   : " . strlen((string) $b) . "\n";
    curl_close($ch);
    echo "----\n" . substr((string) $b, 0, 700) . "\n";

    // and what address the world sees us as
    $ch = curl_init('https://api.ipify.org');
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
                                 CURLOPT_SSL_VERIFYPEER => 0));
    echo "----\nserver ka IP: " . (string) curl_exec($ch) . "\n";
    curl_close($ch);
    exit;
}

$lock = @fopen(AAA_LOCK, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "pehle se chal raha hai - chhor diya\n";
    exit;
}

statsTable($conn);
$s = stLoad();
if (isset($opts['reset'])) {
    @unlink(AAA_STATE);
    @unlink(AAA_COOKIE);
    echo "state saaf kar di\n";
    $s = stLoad();
}
if ($s['halted'] !== '') {
    echo "RUKA: {$s['halted']}\n";
    echo "(--reset= se dobara shuru hoga)\n";
    exit;
}
if ($s['used'] >= AAA_BUDGET) {
    echo "aaj ka budget khatam ({$s['used']}/" . AAA_BUDGET . ")\n";
    exit;
}

$s['runs']++;
$began  = microtime(true);
$did    = 0;
$wrote  = 0;
$fields = array();
$makers = (array) $s['makers'];

list($ok, $fields, $found, $err) = aaaSignIn($s['used']);
$did += 3;
if (!$ok) {
    if ($err === 'refused') {
        $s['halted'] = 'source ne mana kar diya - ' . date('Y-m-d H:i');
        stSave($s);
        echo "RUKA: source refused.\n";
        exit;
    }
    stSave($s);
    echo "login nahi hua: {$err}\n";
    exit;
}
if ($found) {
    $makers = $found;
    $s['makers'] = $makers;
}
if (!$makers) {
    stSave($s);
    echo "maker ki list nahi mili\n";
    exit;
}

$ids = array_keys($makers);
if (isset($opts['maker']) && $opts['maker'] !== '') {
    $ids = array_values(array_filter($ids, function ($i) use ($opts) {
        return (string) $i === (string) $opts['maker'];
    }));
    $s['mIdx'] = 0;
}
if ($s['mIdx'] >= count($ids)) {
    $s['mIdx'] = 0;
    $s['page'] = 1;
    $s['sweeps']++;
    $s['done'] = array();
}

while (microtime(true) - $began < $runFor && $s['used'] < AAA_BUDGET) {
    if ($s['mIdx'] >= count($ids)) {
        $s['mIdx'] = 0;
        $s['page'] = 1;
        $s['sweeps']++;
        $s['done'] = array();
        echo "poora chakkar mukammal - phir se pehle maker se\n";
        break;
    }
    $vid  = (string) $ids[$s['mIdx']];
    $name = $makers[$vid];

    list($body, $e) = aaaPage($fields, $vid, $s['page']);
    $did++;
    $s['used']++;

    if ($e === 'refused') {
        $s['halted'] = 'source ne mana kar diya - ' . date('Y-m-d H:i');
        stSave($s);
        echo "RUKA: source refused. Sab band.\n";
        exit;
    }
    if ($body === null) {
        // A failed read is not an empty page. Leave the cursor and try again.
        stSave($s);
        echo "read fail ({$e}) {$name} page {$s['page']}\n";
        break;
    }

    list($rows, $navi) = aaaParse($body);

    if (isset($navi['is_user']) && (string) $navi['is_user'] === '0') {
        $s['halted'] = 'account ko statistics ki ijazat nahi rahi - ' . date('Y-m-d H:i');
        stSave($s);
        echo "RUKA: is_user=0. Account se statistics ki ijazat chali gayi.\n";
        exit;
    }

    if (isset($navi['rows'])) {
        $s['totals'][$vid] = (int) $navi['rows'];
    }
    $total = (int) ($s['totals'][$vid] ?? 0);
    $last  = $total > 0 ? (int) ceil($total / AAA_PER_PAGE) : 0;

    if ($rows) {
        $n = statStore($conn, $rows);
        $wrote += $n;
        $s['stored'] += $n;
    }

    // A maker is finished at its declared last page, or when a page that ought
    // to hold rows holds none. An empty first page is a maker with no results,
    // not a broken read - the source answers the same way for both, and moving
    // on costs one page where stopping would cost the whole sweep.
    $endOfMaker = ($last > 0 && $s['page'] >= $last) || (!$rows && $s['page'] >= 1);
    if ($endOfMaker) {
        echo sprintf("%-14s p%-5d %6d rows kul, %d likhi\n",
                     $name, $s['page'], $total, $wrote);
        $s['done'][] = $vid;
        $s['mIdx']++;
        $s['page'] = 1;
    } else {
        $s['page']++;
    }

    stSave($s);
    usleep((int) (AAA_GAP * 1000000));
}

stSave($s);

$held = 0;
if ($r = $conn->query("SELECT COUNT(*) FROM car_stats")) {
    $held = (int) $r->fetch_row()[0];
}
$doneN = count((array) $s['done']);
echo "\n";
echo "requests   {$did}  |  likhi {$wrote}\n";
echo "maker      " . ($doneN) . " / " . count($ids) . " is chakkar mein\n";
echo "DB mein    " . number_format($held) . " purani auction\n";
echo "aaj        {$s['used']} / " . AAA_BUDGET . " requests, chakkar {$s['sweeps']}\n";
