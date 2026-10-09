<?php
/**
 * Auction ingest - the receiving half of the SECOND auction feed (spec 009): aaajapan's live
 * auction, beside Pacific Boeki's (pb-harvest.php). The client's order, 29 September 2026:
 * the same auction, one list, one detail page; a lot PB already has does not come twice, a
 * lot PB does not have comes in; PB loses nothing.
 *
 * WHO FETCHES. aaajapan refuses this server's IP, so the Statistics fetcher on GitHub reads
 * the auction too (same run, same sign-in, same 1.5 s clock) and POSTs here. This file never
 * contacts the source. It knows the row shape (the source's single letters, the same as the
 * Statistics') and decides everything that needs our database: is this car already PB's, is
 * it for sale, which hall name does PB use for it.
 *
 *   GET  ?t=TOKEN&have=1      -> {now, today, halls:{hall:{n,at}}, ours:{hall:{today,later}}, map}
 *   POST ?t=TOKEN&survey=1    {onsale, stat_total, stat_date, halls:[[day,weekday,hall,count],...]}
 *   POST ?t=TOKEN&rows=1      {hall, makers:[...], rows:[{a,b,c,...}]} -> {new, updated, pb, past, results...}
 *   POST ?t=TOKEN&done=1      {hall, count, since}   whole, stable read -> retire ours it did not see
 *   GET  ?t=TOKEN&dedupe=1    a lot PB now lists too: our row merged into PB's (references moved)
 *   php aaa-auction-ingest.php --dedupe   the same fold from the server's own cron, every 10 min
 *
 * OUR ROWS: car_id `aj-<day>-<hall>-<lot>`, source_section 'japan' (so every page shows them
 * as it shows PB's), images = the source's three pictures (two photographs and the inspection
 * sheet), source_url = the source's search page tagged with the hall - never shown anywhere,
 * it is how the count is split (B) and how a hall's rows are found again. When PB lists the
 * same lot, pb-harvest.php adopts the row (same day + lot + make + model), writes its own link
 * and picture on it, and from then on it is PB's (A).
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/source-health.php';       // sourceDataDir()

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* The fold, from the server's own cron (the owner's yes, 9 Oct 2026). The fetcher folds our lots PB
   now lists at the end of every pass - but its passes stop for the day once its share of requests is
   spent (07:40 UTC that day), and from then on every lot PB added showed twice: 382 by 09:45 UTC.
   Folding asks the source nothing, so it no longer waits for the share. Command line only: a web
   request is never PHP_SAPI 'cli', so the door below still wants the token. */
$cliDedupe = PHP_SAPI === 'cli' && in_array('--dedupe', (array) ($_SERVER['argv'] ?? array()), true);
if ($cliDedupe) {
    $_GET = array('dedupe' => '1');
}

define('AAA_INGEST_TOKEN', env_get('AAA_INGEST_TOKEN', ''));
if (!$cliDedupe && (AAA_INGEST_TOKEN === '' || !hash_equals(AAA_INGEST_TOKEN, (string) ($_GET['t'] ?? '')))) {
    http_response_code(404);
    exit;
}

/** The feed's notes: ~/sbk-data/auction/aaa-fetch since 9 Oct 2026 (spec 011), ~/aaa-fetch before. */
function aucDir() { return sourceDataDir('aaa-fetch'); }

const AUC_SRC  = 'https://bid.aaajapan.com/aj_neo?h=';      // + rawurlencode(hall) + '#' + the source's row id
const AUC_IMG  = 'https://8.ajes.com/imgs/';
const AUC_CAP  = 30;                                        // retire at most this + a tenth of the hall per read
const AUC_PACE_HEAD = 6;                                    // the share's pace: (UTC hour + this) / 24 of it by then

/**
 * One request at a time over the hall state, from its read to its save. On 30 Sep a test's clean-up
 * rewrote the file in place while a pass was reading it: the pass read nothing, started from an empty
 * state and saved that - 39 halls read and 23 learnt hall names gone. Every request takes the lock
 * (released when the script ends); the fetcher asks one thing at a time, so nobody waits long.
 */
function aucLock() {
    static $h = null;
    if ($h === null) {
        $d = aucDir();
        if (!is_dir($d)) { @mkdir($d, 0750, true); }
        $h = @fopen($d . '/auction-halls.lock', 'c');
        if ($h) { flock($h, LOCK_EX); }
    }
}
/** The hall state. A file that is there but unreadable is NEVER taken for an empty state - that is
    how it was wiped; the request is refused instead (the pass notes a fault and tries next time). */
function aucState() {
    $f = aucDir() . '/auction-halls.json';
    $empty = array('halls' => array(), 'votes' => array(), 'survey' => array());
    for ($i = 0; $i < 3; $i++) {
        clearstatcache(true, $f);
        if (!is_file($f)) { return $empty; }
        $s = json_decode((string) @file_get_contents($f), true);
        if (is_array($s)) { return $s + $empty; }
        usleep(200000);
    }
    http_response_code(503);
    echo json_encode(array('ok' => false, 'error' => 'the hall state could not be read - try again'));
    exit;
}
/**
 * Save the hall state: the whole file swapped in only when the copy beside it is complete. This
 * host has twice (30 Sep, 07:38 and 09:03 UTC) answered a write with "No such file or directory"
 * and left an EMPTY file behind - once in place (the state wiped), once as the copy then renamed
 * over the state. So: encode, write the copy, read its size back, and rename only a whole copy;
 * otherwise the old state stays and the failure is logged.
 */
function aucSave(array $s) {
    $d = aucDir();
    if (!is_dir($d)) { @mkdir($d, 0750, true); }
    $f = $d . '/auction-halls.json';
    $tmp = $f . '.' . getmypid() . '.tmp';
    $json = json_encode($s, JSON_INVALID_UTF8_SUBSTITUTE);
    $w = ($json === false) ? false : @file_put_contents($tmp, $json);
    clearstatcache(true, $tmp);
    if ($json === false || $w !== strlen($json) || !is_file($tmp) || filesize($tmp) !== strlen($json)) {
        @unlink($tmp);
        error_log('aaa-auction-ingest: the hall state was NOT saved (' . ($json === false ? json_last_error_msg()
                  : 'wrote ' . var_export($w, true) . ' of ' . strlen($json) . ' bytes') . ') - the old one is kept');
        return false;
    }
    return @rename($tmp, $f);
}
function aucOut(array $a) { echo json_encode($a + array('ok' => true)); exit; }
function aucIn() {
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'expected a JSON object'));
        exit;
    }
    return $in;
}

/** Japan's today - the sale days are Japan's days. */
function aucToday() { return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d'); }
/** `28.09.2026` -> `2026-09-28`, or null. */
function aucDay($s) {
    return preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim((string) $s), $m) ? $m[3] . '-' . $m[2] . '-' . $m[1] : null;
}
function aucNum($s, $max = 2000000000) {
    $n = preg_replace('/\D+/', '', (string) $s);
    if ($n === '') { return null; }
    $v = (int) $n;
    return ($v < 0 || $v > $max) ? null : $v;
}
function aucText($s) {
    $t = html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/&#\d*$/', '', $t));
}
/** The source's result in PB's words: '' for sale; 'removed' is OUR word (retired), never theirs. */
function aucStatus($v) {
    $w = strtolower(aucText($v));
    if ($w === '' || $w === 'n/a' || $w === '-') { return 'available'; }
    if (strpos($w, 'nego') !== false) { return 'negotiate sold'; }
    if (strpos($w, 'not') !== false || strpos($w, 'unsold') !== false || strpos($w, 'no sale') !== false) { return 'unsold'; }
    if ($w === 'sold') { return 'sold'; }
    if (strpos($w, 'remov') !== false || strpos($w, 'withdr') !== false) { return 'withdrawn'; }
    if (strpos($w, 'cancel') !== false) { return 'cancel'; }
    return substr($w, 0, 40);
}
/** "MERCEDES BENZ E CLASS" -> [MERCEDES BENZ, E CLASS]: the longest maker the source lists. */
function aucSplit($b, array $makers) {
    $b = aucText($b);
    $best = '';
    foreach ($makers as $m) {
        $m = trim((string) $m);
        if ($m !== '' && strlen($m) > strlen($best) && stripos($b . ' ', $m . ' ') === 0) { $best = $m; }
    }
    if ($best === '') {
        $sp = strpos($b, ' ');
        $best = $sp === false ? $b : substr($b, 0, $sp);
    }
    return array(strtoupper($best), trim(substr($b, strlen($best))));
}
function aucKey($s) { return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $s)); }
function aucLot($lot) {
    $lot = trim((string) $lot);
    return ctype_digit($lot) ? (ltrim($lot, '0') === '' ? '0' : ltrim($lot, '0')) : $lot;
}
/** Same model, as two feeds spell it: equal once punctuation is gone, or one leads the other. */
function aucSameModel($a, $b) {
    $a = aucKey($a); $b = aucKey($b);
    if ($a === '' || $b === '') { return false; }
    return $a === $b || strpos($a, $b) === 0 || strpos($b, $a) === 0;
}
/**
 * PB's name for one of the source's halls: what matched lots taught us (votes), else the
 * spellings known to differ, else the source's own name. The hall filter then shows one hall.
 */
function aucHall($hall, array $st) {
    $v = $st['votes'][$hall] ?? array();
    if ($v) { arsort($v); return (string) key($v); }
    static $known = array('CAA Tohoku' => 'CAA Touhoku', 'TAA Tohoku' => 'TAA Touhoku', 'TAA Kanto' => 'TAA Kantou',
        'ZERO Shonan' => 'ZERO Syonan', 'TAA Minami Kyushu' => 'TAA Minamikyu', 'KCAA Minami Kyushu' => 'KCAA M Kyushu',
        'ISUZU Tokyo' => 'Isuzu Tokyo', 'ISUZU Kyushu' => 'Isuzu Kyushu', 'ISUZU Kobe' => 'Isuzu Kobe',
        'AEP Nyusatsu' => 'AEP Gifu', 'LUM Hokkaido Nyusatsu' => 'LUM Sapporo');
    if (isset($known[$hall])) { return $known[$hall]; }
    return preg_replace('/\s+Nyusatsu$/i', '', $hall);
}
function aucSrcPrefix($hall) { return AUC_SRC . rawurlencode($hall) . '#'; }

/** Tables that point at a car by car_id (bids, orders, inquiries, auction_results ...). */
function aucRefTables($conn) {
    static $t = null;
    if ($t !== null) { return $t; }
    $t = array();
    $r = $conn->query("SELECT c.TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS c
                         JOIN INFORMATION_SCHEMA.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
                        WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'car_id'
                          AND c.TABLE_NAME <> 'cars' AND tb.TABLE_TYPE = 'BASE TABLE'");
    while ($r && $w = $r->fetch_row()) { $t[] = $w[0]; }
    return $t;
}
/** Our row folds into PB's: whatever pointed at ours points at PB's, then ours goes. */
function aucMerge($conn, $ourCarId, $pbCarId) {
    foreach (aucRefTables($conn) as $t) {
        $q = $conn->prepare("UPDATE `$t` SET car_id = ? WHERE car_id = ?");
        $q->bind_param('ss', $pbCarId, $ourCarId);
        $q->execute();
        $q->close();
    }
    $q = $conn->prepare("DELETE FROM cars WHERE car_id = ? AND source_url LIKE 'https://bid.aaajapan.com%'");
    $q->bind_param('s', $ourCarId);
    $q->execute();
    $n = $conn->affected_rows;
    $q->close();
    return $n;
}
/** Rows of that day and lot that are not ours: PB's (or ones PB adopted). */
function aucOthers($conn, $day, $lot) {
    static $q = null;
    if ($q === null) {
        $q = $conn->prepare("SELECT car_id, auction, make, model, year, mileage, chassis FROM cars
                              WHERE auction_on = ? AND lot_no = ?
                                AND (source_url IS NULL OR source_url NOT LIKE 'https://bid.aaajapan.com%')");
    }
    $q->bind_param('ss', $day, $lot);
    $q->execute();
    return $q->get_result()->fetch_all(MYSQLI_ASSOC);
}
/** Both mileages known and within 1,000 km or 2% (PB rounds to thousands). */
function aucSameKm($k1, $k2) {
    $k1 = (int) $k1; $k2 = (int) $k2;
    return $k1 > 0 && $k2 > 0 && abs($k1 - $k2) <= max(1000, (int) (0.02 * max($k1, $k2)));
}
/** Do the two sheets describe one car - the year, the mileage? At least one known on both
    sides, and none of the known ones disagreeing. A year exactly 30 apart counts as the same:
    PB's model_year_en reads some Reiwa years as Heisei (a 2020 car as 1990 - seen 30 Sep). */
function aucSameCar(array $o, $year, $km) {
    $y1 = (int) ($o['year'] ?? 0);    $y2 = (int) $year;
    $k1 = (int) ($o['mileage'] ?? 0); $k2 = (int) $km;
    $hasY = $y1 > 0 && $y2 > 0;
    $hasK = $k1 > 0 && $k2 > 0;
    if (!$hasY && !$hasK) { return false; }
    if ($hasY && $y1 !== $y2 && abs($y1 - $y2) !== 30) { return false; }
    if ($hasK && !aucSameKm($k1, $k2)) { return false; }
    return true;
}
/**
 * PB's model_year_en gives some Reiwa cars a Heisei year - a 2020 car as 1990, exactly 30 out (seen
 * 30 Sep; the owner: correct it). When the source holds the same lot with the right year (2019 on,
 * the Reiwa era), PB's row takes it; pb-harvest.php keeps a year so corrected. Returns rows changed.
 */
function aucFixYear($conn, array $pb, $year) {
    $y = (int) ($pb['year'] ?? 0);
    $year = (int) $year;
    if ($year < 2019 || $y <= 0 || $year - $y !== 30) { return 0; }
    $q = $conn->prepare("UPDATE cars SET year = ? WHERE car_id = ? AND year = ? AND source_url LIKE 'https://pacificboeki.jp%'");
    $q->bind_param('isi', $year, $pb['car_id'], $y);
    $q->execute();
    $n = max(0, $q->affected_rows);
    $q->close();
    return $n;
}
/** A make that names nothing: PB files machinery, boats and the like under OTHER, the source OTHERS. */
function aucGenericMake($m) {
    $k = aucKey($m);
    return $k === '' || $k === 'OTHER' || $k === 'OTHERS';
}
/**
 * Same hall, day and lot number: ONE lot - unless the two rows plainly describe different cars:
 * two real makes that differ, and neither the chassis code nor the mileage saying they are one.
 * The feeds spell makes and models their own ways ("OTHERS" / "OTHER" for a forklift, "HITACHI"
 * / "OTHER", "NISSAN" / "NISSAN DIESEL"); that is not a second car. A wrong hall name (29 Sep:
 * Aux Mobility read as MIRIVE Saitama) shows as exactly this kind of plain difference.
 */
function aucSameLot(array $o, $make, $km, $chassis) {
    if (aucKey($o['make']) === aucKey($make) || aucSameKm($o['mileage'] ?? 0, $km)) { return true; }
    $c1 = aucKey($o['chassis'] ?? '');
    if ($c1 !== '' && $c1 === aucKey($chassis)) { return true; }
    return aucGenericMake($o['make']) || aucGenericMake($make);
}
/**
 * Is one of those the same car? PB's row, or null.
 *
 * In the same hall (PB's name for it) a day's lot number is one lot (aucSameLot). The model is not
 * asked there - the feeds spell it differently ("UD SERIES" / "UD", "CARAVAN VAN" / "NV350
 * CARAVAN"): on 30 Sep asking for it left 108 lots listed twice, and asking for the make left 181
 * machinery lots. In ANOTHER hall a lot number means nothing: make, model, year and mileage must
 * all agree (29 Sep: make + model alone taught "Aux Mobility = MIRIVE Saitama" from a few kei cars
 * and dropped 390 lots).
 */
function aucSame(array $others, $pbHall, $make, $model, $year = 0, $km = 0, $chassis = '') {
    $fit = array();
    foreach ($others as $o) {
        if (strcasecmp(trim($o['auction']), $pbHall) === 0) {
            if (aucSameLot($o, $make, $km, $chassis)) { return $o; }
            continue;                    // the same number, plainly another car: not this one
        }
        if (aucKey($o['make']) === aucKey($make) && aucSameModel($o['model'], $model) && aucSameCar($o, $year, $km)) {
            $fit[] = $o;
        }
    }
    return count($fit) === 1 ? $fit[0] : null;
}

aucLock();
$st = aucState();

/* ---------------------------------------------------------------- what do you have? */
if (isset($_GET['have'])) {
    $today = aucToday();
    $ours = array();
    $r = $conn->query("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(source_url, '?h=', -1), '#', 1) h,
                              SUM(auction_on = '" . $conn->real_escape_string($today) . "') td, SUM(auction_on > '"
                              . $conn->real_escape_string($today) . "') lt
                         FROM cars
                        WHERE source_url LIKE 'https://bid.aaajapan.com%' AND auction_on >= '" . $conn->real_escape_string($today) . "'
                          AND (status IS NULL OR status LIKE 'available%')
                        GROUP BY h");
    while ($r && $w = $r->fetch_assoc()) {
        $ours[rawurldecode($w['h'])] = array('today' => (int) $w['td'], 'later' => (int) $w['lt']);
    }
    $map = array();
    foreach (array_keys($st['votes']) as $h) { $map[$h] = aucHall($h, $st); }
    aucOut(array('now' => time(), 'today' => $today, 'halls' => (object) $st['halls'], 'ours' => (object) $ours,
                 'map' => (object) $map));
}

/* ------------------------------------------------------- what the source says it has
 * The fetcher's one survey request (the search page lists every hall with its count,
 * grouped by weekday) comes here, and the answer is WHICH HALLS ARE WORTH A READ - the
 * decision needs our table, so it is made here, not on GitHub:
 *   - a hall with our lots selling today, not read in 45 min (08-19 JST): results;
 *   - otherwise nothing while its count is what our last whole read saw (a day at most);
 *   - nothing while PB (and we) already hold as many lots for that day and hall;
 *   - a future day's hall at most every 3 hours - its list grows all day before a sale;
 *   - the rest by how many lots we are missing, most first.
 */
function aucDateOf($dom, $today) {
    $t = new DateTime($today . ' 12:00', new DateTimeZone('Asia/Tokyo'));
    for ($i = -1; $i <= 8; $i++) {
        $d = (clone $t)->modify(($i >= 0 ? '+' : '') . $i . ' day');
        if ((int) $d->format('j') === (int) $dom) { return $d->format('Y-m-d'); }
    }
    return $today;
}
/** Per hall, the last page whose lots were passed over and why, with samples - never silent again.
    Written under the request's lock, a whole copy only; the 80 most recent halls. */
function aucKeepSkips($hall, array $out, array $skip) {
    $f = aucDir() . '/auction-skips.json';
    $all = json_decode((string) @file_get_contents($f), true);
    if (!is_array($all)) { $all = array(); }
    $all[$hall] = array('at' => time(), 'counts' => array_intersect_key($out, array_flip(array('new', 'pb', 'past', 'result_skip', 'bad'))),
                        'samples' => $skip);
    if (count($all) > 80) {
        uasort($all, function ($a, $b) { return $b['at'] - $a['at']; });
        $all = array_slice($all, 0, 80, true);
    }
    $json = json_encode($all, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    if ($json === false) { return; }
    $tmp = $f . '.' . getmypid() . '.tmp';
    $w = @file_put_contents($tmp, $json);
    clearstatcache(true, $tmp);
    if ($w === strlen($json) && is_file($tmp) && filesize($tmp) === strlen($json)) { @rename($tmp, $f); } else { @unlink($tmp); }
}
/** The last survey as the source gave it - every hall's count under each weekday - kept for the
    owner's questions ("of aaajapan's lots on sale, how many are not on PB?"). A whole copy only. */
function aucKeepSurvey(array $in) {
    $f = aucDir() . '/auction-survey.json';
    $json = json_encode(array('at' => time(), 'onsale' => (int) ($in['onsale'] ?? 0), 'halls' => (array) ($in['halls'] ?? array())),
                        JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) { return; }
    $tmp = $f . '.' . getmypid() . '.tmp';
    $w = @file_put_contents($tmp, $json);
    clearstatcache(true, $tmp);
    if ($w === strlen($json) && is_file($tmp) && filesize($tmp) === strlen($json)) { @rename($tmp, $f); } else { @unlink($tmp); }
}
if (isset($_GET['survey'])) {
    $in = aucIn();
    aucKeepSurvey($in);
    $today = aucToday();
    $now = time();
    $halls = array();                       // hall -> count and day (a hall under several days: its largest)
    foreach ((array) ($in['halls'] ?? array()) as $h) {
        if (!is_array($h) || count($h) < 4) { continue; }
        $name = trim((string) $h[2]);
        $n = (int) $h[3];
        if ($name === '' || $n <= 0) { continue; }
        if (!isset($halls[$name]) || $n > $halls[$name]['n']) {
            $halls[$name] = array('n' => $n, 'date' => aucDateOf((int) $h[0], $today));
        }
    }
    $pb = array();
    $q = $conn->prepare("SELECT auction_on d, LOWER(TRIM(auction)) h, COUNT(*) n FROM cars
                          WHERE auction_on >= ? - INTERVAL 1 DAY AND source_url LIKE 'https://pacificboeki.jp%'
                          GROUP BY d, h");
    $q->bind_param('s', $today);
    $q->execute();
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $w) { $pb[$w['d'] . '|' . $w['h']] = (int) $w['n']; }
    $q->close();
    $ours = array();
    $q = $conn->prepare("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(source_url, '?h=', -1), '#', 1) h,
                                SUM(auction_on = ? AND (status IS NULL OR status LIKE 'available%')) td, COUNT(*) al
                           FROM cars WHERE source_url LIKE 'https://bid.aaajapan.com%' AND auction_on >= ?
                          GROUP BY h");
    $q->bind_param('ss', $today, $today);
    $q->execute();
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $w) {
        $ours[rawurldecode($w['h'])] = array('today' => (int) $w['td'], 'all' => (int) $w['al']);
    }
    $q->close();
    /* The rhythm of PB's own harvester (the owner, 30 Sep: "B ko bhi waisa hi karo jaise A"): the
       fetcher surveys every ten minutes, and a hall is read by what it is -
         - our lots selling there today: every 15 min 08-20 JST (results, sold cars leaving), 3 h at night;
         - never read, with lots nobody holds: now;
         - its survey count moved since our last read (lots added or withdrawn): now, but at most
           every 30 min - a page here is 20 lots, PB's is 100;
         - otherwise tomorrow's every 2 h, later days' every 4 h;
         - PB (and we) already hold as many for that day and hall, and none of it is ours: never.
       The "moved" test compares the SURVEY's count with the survey's count at our last read - never
       with a read's own total: a hall under three weekdays (Aux Mobility: 698 a day, 863 read) never
       matched, and was read at every pass all night. At 80% of the day's share every wait doubles,
       as PB's harvester slows at 80% of its own, so the day never runs dry. */
    $hour = (int) (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('G');
    $day = $hour >= 8 && $hour < 20;
    $slow = ((int) ($in['budget'] ?? 0) > 0 && (int) ($in['used'] ?? 0) > 0.8 * (int) $in['budget']) ? 2 : 1;
    $tomorrow = (new DateTime($today . ' 12:00'))->modify('+1 day')->format('Y-m-d');
    $due = array();
    foreach ($halls as $name => $h) {
        $last   = $st['halls'][$name] ?? array();
        $lastAt = (int) ($last['at'] ?? 0);
        $lastSn = isset($last['sn']) ? (int) $last['sn'] : -1;       // the survey's count when we last read it
        $age    = $now - $lastAt;
        $o      = $ours[$name] ?? array('today' => 0, 'all' => 0);
        $pbN    = $pb[$h['date'] . '|' . strtolower(trim(aucHall($name, $st)))] ?? 0;
        $missing = $h['n'] - $pbN - $o['all'];
        $first  = ($pbN === 0 ? 100000 : 0) + max(0, $missing);     // halls PB lacks altogether first
        /* Results cost a hall's EVERY page, however few of its lots are ours: JU Aichi is 170 pages for
           ~255 of ours, and on 1 Oct it was read 8 times a morning (0 new each time) and the day's share
           was gone by noon PK. So by size: up to 30 pages every 15 min, up to 80 every 30, beyond hourly
           (PB's harvester likewise reads its big stock houses less often). */
        $pages = (int) ceil($h['n'] / 20);
        $resEvery = $pages <= 30 ? 15 * 60 : ($pages <= 80 ? 30 * 60 : 3600);
        if ($o['today'] > 0 && $age >= ($day ? $resEvery : 3 * 3600) * $slow) {
            $due[] = array('hall' => $name, 'count' => $h['n'], 'pb' => $pbN, 'why' => 'results', 'p' => 4000000 + $first);
            continue;
        }
        if ($missing <= 0 && $o['all'] === 0) { continue; }         // PB has all of it, none of it is ours
        if (!$lastAt) {
            if ($missing > 0) {
                $due[] = array('hall' => $name, 'count' => $h['n'], 'pb' => $pbN, 'why' => 'new, ' . $missing, 'p' => 3000000 + $first);
            }
            continue;
        }
        if ($lastSn >= 0 && $lastSn !== $h['n'] && $age >= 30 * 60 * $slow) {
            $due[] = array('hall' => $name, 'count' => $h['n'], 'pb' => $pbN, 'why' => 'moved ' . $lastSn . '->' . $h['n'],
                           'p' => 2000000 + $first);
            continue;
        }
        if ($pbN > 0 && $o['all'] === 0 && $h['date'] > $tomorrow) {
            /* A hall PB lists too, short for now: PB fills its list over the days before a sale, so
               a big shared hall is read for the difference only from the day before the sale on. */
            continue;
        }
        // (a sale day's hall with lots of ours is the "results" case above; without, hourly by day)
        $every = ($h['date'] <= $today ? ($day ? 3600 : 3 * 3600) : ($h['date'] === $tomorrow ? 2 * 3600 : 4 * 3600)) * $slow;
        if ($age >= $every) {
            $due[] = array('hall' => $name, 'count' => $h['n'], 'pb' => $pbN, 'why' => 'due, missing ' . $missing,
                           'p' => 1000000 + $first);
        }
    }
    usort($due, function ($a, $b) { return $b['p'] - $a['p']; });
    /* The day's share, spread over the day (the owner, 9 Oct 2026: the same 8,000, "baant do" - not
       more). On 9 Oct it was all spent by 07:40 UTC (16:40 JST, 12:40 PK) and nothing was read until
       00:00 UTC: lots listed that evening came in the next morning, some after their sale had begun,
       and lots withdrawn stayed on our list. Now by hour h of the UTC day (the share's own day) the
       reads may have used at most (h + AUC_PACE_HEAD) / 24 of it - a quarter at once, all of it by
       18:00 UTC. Ahead of that pace only results (a sold car must leave the list) and halls never read
       (lots nobody holds) are handed out; the rest waits and is read later in the day, when the next
       days' lists are fuller anyway. The 80% rule above stays as it was. */
    $budget = (int) ($in['budget'] ?? 0);
    $used   = (int) ($in['used'] ?? 0);
    $pace   = $budget > 0 ? (int) ($budget * min(1, (($now % 86400) / 3600 + AUC_PACE_HEAD) / 24)) : 0;
    $held   = 0;
    if ($budget > 0 && $used >= $pace) {
        $keep = array();
        foreach ($due as $d) {
            if ($d['why'] === 'results' || strncmp($d['why'], 'new', 3) === 0) { $keep[] = $d; } else { $held++; }
        }
        $due = $keep;
    }
    $st['survey'] = array('at' => $now, 'onsale' => (int) ($in['onsale'] ?? 0),
                          'stat_total' => (int) ($in['stat_total'] ?? 0), 'stat_date' => (string) ($in['stat_date'] ?? ''),
                          'halls' => count($halls), 'due' => count($due),
                          'used' => $used, 'pace' => $pace, 'held' => $held);
    if ($budget > 0) {
        // a line a survey (~70 a day) to measure the spread by; kept under 300 KB
        $pl = aucDir() . '/pace.log';
        @file_put_contents($pl, gmdate('Y-m-d H:i') . " used $used pace $pace of $budget | due " . count($due)
                                . ($held ? " held $held" : '') . "\n", FILE_APPEND | LOCK_EX);
        clearstatcache(true, $pl);
        if (@filesize($pl) > 300000) {
            $all = (string) @file_get_contents($pl);
            @file_put_contents($pl, substr($all, (int) (strlen($all) / 2)), LOCK_EX);
        }
    }
    aucSave($st);
    aucOut(array('now' => $now, 'halls' => count($halls), 'due' => array_slice($due, 0, 40)));
}

/* -------------------------------------------- a lot PB lists too: fold ours into PB's */
if (isset($_GET['dedupe'])) {
    /* Per sale day: our rows, then PB's rows of that day with those lot numbers (the auction_on
       index, lot_no IN (...)), and each of ours judged by the rule a new row meets (aucSame).
       The self-join this replaces read every PB row of the day for each of ours - past 90 s, so
       every pass from 29 Sep 18:20 UTC timed out on it: no copy was folded (108 listed twice by
       30 Sep) and the signal went red. This: ~0.2 s for 1,600 of ours. */
    $merged = 0;
    $would = array();
    $ours = $conn->query("SELECT car_id, auction_on, lot_no, auction, make, model, year, mileage, chassis, source_url FROM cars
                           WHERE car_id LIKE 'aj-%' AND source_url LIKE 'https://bid.aaajapan.com%'
                             AND auction_on >= CURDATE() - INTERVAL 1 DAY");
    $byDay = array();
    while ($ours && $o = $ours->fetch_assoc()) { $byDay[$o['auction_on']][$o['lot_no']][] = $o; }
    foreach ($byDay as $day => $lots) {
        $in = implode(',', array_map(function ($l) use ($conn) { return "'" . $conn->real_escape_string((string) $l) . "'"; },
                                     array_keys($lots)));
        $q = $conn->query("SELECT car_id, lot_no, auction, make, model, year, mileage, chassis FROM cars
                            WHERE auction_on = '" . $conn->real_escape_string($day) . "' AND lot_no IN ($in)
                              AND source_url LIKE 'https://pacificboeki.jp%'");
        $pb = array();
        while ($q && $p = $q->fetch_assoc()) { $pb[$p['lot_no']][] = $p; }
        foreach ($pb as $lot => $others) {
            foreach ($lots[$lot] ?? array() as $a) {
                // its hall as written (PB's name when it was known), else PB's name for it now
                $same = aucSame($others, trim($a['auction']), $a['make'], $a['model'], (int) $a['year'], (int) $a['mileage'],
                                (string) $a['chassis']);
                if (!$same) {
                    parse_str((string) parse_url($a['source_url'], PHP_URL_QUERY), $qs);    // h = the source's hall
                    $now = aucHall((string) ($qs['h'] ?? ''), $st);
                    if ($now !== '' && strcasecmp($now, trim($a['auction'])) !== 0) {
                        $same = aucSame($others, $now, $a['make'], $a['model'], (int) $a['year'], (int) $a['mileage'],
                                        (string) $a['chassis']);
                    }
                }
                if ($same && isset($_GET['dry'])) {
                    // dedupe=1&dry=1: what WOULD fold, touching nothing - see a new rule before it acts
                    $merged++;
                    if (count($would) < 60) {
                        $would[] = array($a['car_id'], $same['car_id'], $a['auction'] . ' / ' . $same['auction'],
                                         $a['make'] . ' ' . $a['model'] . ' / ' . $same['make'] . ' ' . $same['model'],
                                         $a['chassis'] . ' / ' . $same['chassis'], $a['mileage'] . ' / ' . $same['mileage']);
                    }
                } elseif ($same) {
                    aucFixYear($conn, $same, (int) $a['year']);           // ours knew the right year
                    $merged += aucMerge($conn, $a['car_id'], $same['car_id']);
                }
            }
        }
    }
    if ($cliDedupe) {
        // the cron's own trace: when it last ran (one small file, rewritten), and a line only when it folded
        @file_put_contents(aucDir() . '/dedupe-last.json', json_encode(array('at' => time(), 'merged' => $merged)), LOCK_EX);
        if ($merged > 0) {
            @file_put_contents(aucDir() . '/dedupe.log', gmdate('Y-m-d H:i') . " UTC folded $merged\n", FILE_APPEND | LOCK_EX);
        }
    }
    aucOut(isset($_GET['dry']) ? array('would' => $merged, 'samples' => $would) : array('merged' => $merged));
}

/* ------------------------------------------------ a hall read whole: retire the unseen */
if (isset($_GET['done'])) {
    $in = aucIn();
    $hall  = trim((string) ($in['hall'] ?? ''));
    $count = (int) ($in['count'] ?? -1);
    $since = (int) ($in['since'] ?? 0);
    if ($hall === '' || $count < 0 || $since <= 0) {
        http_response_code(400);
        aucOut(array('ok' => false, 'error' => 'hall, count and since are needed'));
    }
    $today = aucToday();
    $pre = aucSrcPrefix($hall);
    $cap = AUC_CAP + (int) ($count / 10);
    $retired = 0;
    if (!empty($in['whole'])) {
        // Only a read that saw every page, with the hall's count the same at its end.
        $q = $conn->prepare("UPDATE cars SET status = 'removed', last_updated = NOW()
                              WHERE LEFT(source_url, CHAR_LENGTH(?)) = ? AND auction_on >= ?
                                AND (status IS NULL OR status LIKE 'available%')
                                AND last_updated < FROM_UNIXTIME(?) LIMIT " . (int) $cap);
        $q->bind_param('sssi', $pre, $pre, $today, $since);
        $q->execute();
        $retired = max(0, $q->affected_rows);
        $q->close();
        $st['halls'][$hall] = array('n' => $count, 'at' => time(), 'sn' => (int) ($in['sn'] ?? -1));
    } else {
        // a read cut short: remember when (no re-read for a while), not what it saw
        $st['halls'][$hall] = array('n' => (int) ($st['halls'][$hall]['n'] ?? -1), 'at' => time(), 'sn' => (int) ($in['sn'] ?? -1));
    }
    // sn: the SURVEY's count for the hall when it was read - what the next survey is compared with
    aucSave($st);
    aucOut(array('retired' => $retired, 'capped' => $retired >= $cap));
}

/* ------------------------------------------------------------------ one page of lots */
if (isset($_GET['rows'])) {
    $in = aucIn();
    $hall   = trim((string) ($in['hall'] ?? ''));
    $makers = array_values(array_filter(array_map('strval', (array) ($in['makers'] ?? array()))));
    $rows   = (array) ($in['rows'] ?? array());
    if ($hall === '') {
        http_response_code(400);
        aucOut(array('ok' => false, 'error' => 'hall is needed'));
    }
    $today  = aucToday();
    $pbHall = aucHall($hall, $st);
    $out = array('new' => 0, 'updated' => 0, 'pb' => 0, 'merged' => 0, 'past' => 0, 'result_skip' => 0, 'bad' => 0);
    /* `test=1` (auctioningestcheck.py): the same path, but into section 'ztest', which no
       page lists - a test car must never be offered to a customer, not even for a second. */
    $section = isset($_GET['test']) ? 'ztest' : 'japan';
    $ins = $conn->prepare("INSERT INTO cars
          (car_id, lot_no, make, model, year, mileage, price, currency, sold_price,
           auction, auction_date, auction_time, chassis, transmission, grade, rating,
           engine_cc, engine_hp, color, equipment, status, images, source_url, source_section,
           last_updated, created_at)
         VALUES (?,?,?,?,?,?,?,'yen',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'" . $section . "',NOW(),NOW())
         ON DUPLICATE KEY UPDATE
           make=VALUES(make), model=VALUES(model), year=VALUES(year),
           mileage=IF(VALUES(mileage) > 0, VALUES(mileage), mileage), price=VALUES(price),
           sold_price=IF(VALUES(sold_price) > 0, VALUES(sold_price), sold_price),
           auction=VALUES(auction), auction_date=VALUES(auction_date),
           auction_time=COALESCE(VALUES(auction_time), auction_time), chassis=VALUES(chassis),
           transmission=VALUES(transmission), grade=VALUES(grade), rating=VALUES(rating),
           engine_cc=VALUES(engine_cc), engine_hp=VALUES(engine_hp), color=VALUES(color),
           equipment=VALUES(equipment), status=VALUES(status),
           images=IF(CHAR_LENGTH(VALUES(images)) > 2, VALUES(images), images),
           source_url=VALUES(source_url), last_updated=NOW()");
    $mine = $conn->prepare("SELECT car_id, source_url FROM cars WHERE car_id = ?");
    /* Why lots of this page were NOT taken, with a sample each (aaa-fetch/auction-skips.json). 30 Sep:
       MOTA (142) and LUM Tokyo Nyusatsu (278), halls PB lacks entirely, were read whole and not one
       lot was taken - and nothing said why. */
    $skip = array();
    $note = function ($why, $r) use (&$skip) {
        if (count($skip[$why] ?? array()) < 3) {
            $skip[$why][] = array_intersect_key((array) $r, array_flip(array('b', 'c', 'd', 'e', 'f', 'v')));
        }
    };
    foreach ($rows as $r) {
        if (!is_array($r)) { $out['bad']++; continue; }
        $day = aucDay($r['e'] ?? '');
        $lot = aucLot(aucText($r['c'] ?? ''));
        if ($day === null || $lot === '') { $out['bad']++; $note('bad', $r); continue; }
        if ($day < $today) { $out['past']++; $note('past', $r); continue; }   // a result of a past day: Statistics' job
        list($make, $model) = aucSplit($r['b'] ?? '', $makers);
        $status = aucStatus($r['v'] ?? '');
        $carId  = substr('aj-' . $day . '-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $hall), 0, 16) . '-'
                         . preg_replace('/[^0-9A-Za-z]/', '', $lot), 0, 50);

        // Ours already? Still ours (PB has not adopted it)?
        $mine->bind_param('s', $carId);
        $mine->execute();
        $own = $mine->get_result()->fetch_assoc();
        if ($own && strpos((string) $own['source_url'], 'https://bid.aaajapan.com') !== 0) {
            $out['pb']++;                                  // PB took it over: PB's from here on
            continue;
        }

        // Does PB hold this car? Then it is PB's - and a copy of ours folds into it.
        $year  = aucNum($r['g'] ?? '', 2100);
        $km    = aucNum($r['q'] ?? '', 3000000) ?? 0;
        $same = aucSame(aucOthers($conn, $day, $lot), $pbHall, $make, $model, (int) $year, (int) $km, aucText($r['j'] ?? ''));
        if ($same) {
            $out['pb']++;
            $out['year_fixed'] = ($out['year_fixed'] ?? 0) + aucFixYear($conn, $same, (int) $year);
            // A vote: the source's `$hall` is PB's `$v` - how the hall filter keeps one name.
            $v = trim((string) $same['auction']);
            if ($v !== '' && (strcasecmp($v, $pbHall) === 0 || aucKey($same['make']) === aucKey($make))) {
                $st['votes'][$hall][$v] = ($st['votes'][$hall][$v] ?? 0) + 1;
            }
            if ($own) { $out['merged'] += aucMerge($conn, $carId, $same['car_id']); }
            continue;
        }
        if (!$own && $status !== 'available') { $out['result_skip']++; $note('result', $r); continue; }   // never offered, already over

        $time  = trim(aucText($r['f'] ?? ''), "[] \t");
        $time  = preg_match('/^\d{1,2}:\d{2}$/', $time) ? sprintf('%05s', $time) . ':00' : null;
        $cc    = aucNum($r['h'] ?? '', 30000);
        $hp    = aucText($r['i'] ?? '');
        $price = (float) (aucNum($r['s'] ?? '', 9000000000) ?? 0);
        $sold  = (float) (aucNum($r['t'] ?? '', 9000000000) ?? 0);
        $pics  = array();
        foreach (array('x', 'y', 'z') as $k) {
            $tok = trim((string) ($r[$k] ?? ''));
            if ($tok !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $tok)) { $pics[] = AUC_IMG . $tok; }
        }
        $pics  = json_encode(array_values(array_unique($pics)));
        $date  = substr($day, 8, 2) . '.' . substr($day, 5, 2) . '.' . substr($day, 0, 4);
        $src   = aucSrcPrefix($hall) . preg_replace('/[^A-Za-z0-9]/', '', (string) ($r['a'] ?? ''));
        $chas  = aucText($r['j'] ?? '');
        $trans = aucText($r['k'] ?? '');
        $grade = substr(aucText($r['l'] ?? ''), 0, 150);
        $rate  = substr(aucText($r['r'] ?? ''), 0, 20);
        $color = substr(aucText($r['w'] ?? ''), 0, 60);
        $equip = substr(aucText($r['m'] ?? ''), 0, 120);
        // 22 values, in the column order above
        $ins->bind_param('ssss' . 'ii' . 'dd' . 'sssssss' . 'i' . 'ssssss',
            $carId, $lot, $make, $model,
            $year, $km,
            $price, $sold,
            $pbHall, $date, $time, $chas, $trans, $grade, $rate,
            $cc,
            $hp, $color, $equip, $status, $pics, $src);
        if ($ins->execute()) {
            $out[$ins->affected_rows === 1 ? 'new' : 'updated']++;
        } else {
            $out['bad']++;
        }
    }
    $mine->close();
    $ins->close();
    aucSave($st);
    if ($skip) { aucKeepSkips($hall, $out, $skip); }
    aucOut($out + array('now' => time(), 'pb_hall' => $pbHall));
}

http_response_code(400);
echo json_encode(array('ok' => false, 'error' => 'say have, survey, rows, done or dedupe'));
