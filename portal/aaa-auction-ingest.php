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
 *
 * OUR ROWS: car_id `aj-<day>-<hall>-<lot>`, source_section 'japan' (so every page shows them
 * as it shows PB's), images = the source's three pictures (two photographs and the inspection
 * sheet), source_url = the source's search page tagged with the hall - never shown anywhere,
 * it is how the count is split (B) and how a hall's rows are found again. When PB lists the
 * same lot, pb-harvest.php adopts the row (same day + lot + make + model), writes its own link
 * and picture on it, and from then on it is PB's (A).
 */

require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

define('AAA_INGEST_TOKEN', env_get('AAA_INGEST_TOKEN', ''));
if (AAA_INGEST_TOKEN === '' || !hash_equals(AAA_INGEST_TOKEN, (string) ($_GET['t'] ?? ''))) {
    http_response_code(404);
    exit;
}

const AUC_SRC  = 'https://bid.aaajapan.com/aj_neo?h=';      // + rawurlencode(hall) + '#' + the source's row id
const AUC_IMG  = 'https://8.ajes.com/imgs/';
const AUC_CAP  = 30;                                        // retire at most this + a tenth of the hall per read

function aucState() {
    $f = dirname(__DIR__) . '/aaa-fetch/auction-halls.json';
    $s = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($s) ? $s + array('halls' => array(), 'votes' => array(), 'survey' => array())
                        : array('halls' => array(), 'votes' => array(), 'survey' => array());
}
function aucSave(array $s) {
    $d = dirname(__DIR__) . '/aaa-fetch';
    if (!is_dir($d)) { @mkdir($d, 0750, true); }
    $f = $d . '/auction-halls.json';
    @file_put_contents($f . '.' . getmypid() . '.tmp', json_encode($s));
    @rename($f . '.' . getmypid() . '.tmp', $f);
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
        $q = $conn->prepare("SELECT car_id, auction, make, model, year, mileage FROM cars
                              WHERE auction_on = ? AND lot_no = ?
                                AND (source_url IS NULL OR source_url NOT LIKE 'https://bid.aaajapan.com%')");
    }
    $q->bind_param('ss', $day, $lot);
    $q->execute();
    return $q->get_result()->fetch_all(MYSQLI_ASSOC);
}
/** Do the two sheets describe one car - the year, the mileage? At least one known on both
    sides, and none of the known ones disagreeing (mileage within 1,000 km or 2%). */
function aucSameCar(array $o, $year, $km) {
    $y1 = (int) ($o['year'] ?? 0);    $y2 = (int) $year;
    $k1 = (int) ($o['mileage'] ?? 0); $k2 = (int) $km;
    $hasY = $y1 > 0 && $y2 > 0;
    $hasK = $k1 > 0 && $k2 > 0;
    if (!$hasY && !$hasK) { return false; }
    if ($hasY && $y1 !== $y2) { return false; }
    if ($hasK && abs($k1 - $k2) > max(1000, (int) (0.02 * max($k1, $k2)))) { return false; }
    return true;
}
/**
 * Is one of those the same car? PB's row, or null.
 *
 * The first live read (29 Sep) matched on the hall alone once a hall's PB name was learnt, and
 * the name was learnt from make + model alone at ANY hall: a few common kei cars sharing a lot
 * number taught "Aux Mobility = MIRIVE Saitama", and from then on every Aux Mobility lot whose
 * number PB's MIRIVE Saitama also had (it has ~3,000 a day) counted as PB's - 390 lots dropped,
 * 289 shown under the wrong hall. Now the car itself must agree too: in the same hall its make
 * and model (or make, year and mileage); in another hall make, model, year and mileage.
 */
function aucSame(array $others, $pbHall, $make, $model, $year = 0, $km = 0) {
    $fit = array();
    foreach ($others as $o) {
        $mk  = aucKey($o['make']) === aucKey($make);
        $md  = $mk && aucSameModel($o['model'], $model);
        $car = aucSameCar($o, $year, $km);
        if (strcasecmp(trim($o['auction']), $pbHall) === 0) {
            if ($md || ($mk && $car)) { return $o; }
            continue;                    // the same number, another car: not this one
        }
        if ($md && $car) { $fit[] = $o; }
    }
    return count($fit) === 1 ? $fit[0] : null;
}

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
if (isset($_GET['survey'])) {
    $in = aucIn();
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
    $hour = (int) (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('G');
    $due = array();
    foreach ($halls as $name => $h) {
        $last   = $st['halls'][$name] ?? array();
        $lastAt = (int) ($last['at'] ?? 0);
        $lastN  = isset($last['n']) ? (int) $last['n'] : -1;
        $o      = $ours[$name] ?? array('today' => 0, 'all' => 0);
        if ($o['today'] > 0 && $hour >= 8 && $hour < 19 && $now - $lastAt >= 60 * 60) {
            $due[] = array('hall' => $name, 'count' => $h['n'], 'why' => 'results', 'p' => 1000000);
            continue;
        }
        if ($lastN === $h['n'] && $now - $lastAt < 86400) { continue; }
        $pbN = $pb[$h['date'] . '|' . strtolower(trim(aucHall($name, $st)))] ?? 0;
        $missing = $h['n'] - $pbN - $o['all'];
        if ($missing <= 0) { continue; }
        if ($h['date'] > $today && $now - $lastAt < 3 * 3600) { continue; }
        if ($pbN > 0) {
            /* A hall PB lists too, short for now: PB fills its list over the days before a
               sale, so reading a 4,000-lot hall for the difference is worth it only from the
               day before the sale on - by then what is still missing is missing. */
            $tomorrow = (new DateTime($today . ' 12:00'))->modify('+1 day')->format('Y-m-d');
            if ($h['date'] > $tomorrow) { continue; }
            $due[] = array('hall' => $name, 'count' => $h['n'], 'pb' => $pbN, 'why' => 'missing ' . $missing, 'p' => $missing);
        } else {
            // a hall PB does not list at all: every lot of it is new to the portal - first
            $due[] = array('hall' => $name, 'count' => $h['n'], 'pb' => 0, 'why' => 'not on PB, ' . $missing, 'p' => 100000 + $missing);
        }
    }
    usort($due, function ($a, $b) { return $b['p'] - $a['p']; });
    $st['survey'] = array('at' => $now, 'onsale' => (int) ($in['onsale'] ?? 0),
                          'stat_total' => (int) ($in['stat_total'] ?? 0), 'stat_date' => (string) ($in['stat_date'] ?? ''),
                          'halls' => count($halls), 'due' => count($due));
    aucSave($st);
    aucOut(array('now' => $now, 'halls' => count($halls), 'due' => array_slice($due, 0, 40)));
}

/* -------------------------------------------- a lot PB lists too: fold ours into PB's */
if (isset($_GET['dedupe'])) {
    $merged = 0;
    $r = $conn->query("SELECT a.car_id ours, a.make am, a.model amo, a.auction ah, a.year ay, a.mileage akm,
                              p.car_id pb, p.make pm, p.model pmo, p.auction ph, p.year, p.mileage
                         FROM cars a
                         JOIN cars p ON p.auction_on = a.auction_on AND p.lot_no = a.lot_no AND p.id <> a.id
                        WHERE a.source_url LIKE 'https://bid.aaajapan.com%' AND a.auction_on >= CURDATE() - INTERVAL 1 DAY
                          AND p.source_url LIKE 'https://pacificboeki.jp%'");
    $pairs = $r ? $r->fetch_all(MYSQLI_ASSOC) : array();
    $done = array();
    foreach ($pairs as $p) {
        if (isset($done[$p['ours']])) { continue; }
        // the rule a new row meets (aucSame): the car must agree, never the lot number alone
        $mk   = aucKey($p['am']) === aucKey($p['pm']);
        $md   = $mk && aucSameModel($p['amo'], $p['pmo']);
        $car  = aucSameCar($p, $p['ay'], $p['akm']);
        $same = strcasecmp(trim($p['ah']), trim($p['ph'])) === 0 ? ($md || ($mk && $car)) : ($md && $car);
        if ($same) {
            $merged += aucMerge($conn, $p['ours'], $p['pb']);
            $done[$p['ours']] = true;
        }
    }
    aucOut(array('merged' => $merged));
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
        $st['halls'][$hall] = array('n' => $count, 'at' => time());
    } else {
        // a read cut short: remember when (no re-read for a while), not what it saw
        $st['halls'][$hall] = array('n' => (int) ($st['halls'][$hall]['n'] ?? -1), 'at' => time());
    }
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
    foreach ($rows as $r) {
        if (!is_array($r)) { $out['bad']++; continue; }
        $day = aucDay($r['e'] ?? '');
        $lot = aucLot(aucText($r['c'] ?? ''));
        if ($day === null || $lot === '') { $out['bad']++; continue; }
        if ($day < $today) { $out['past']++; continue; }                 // a result of a past day: Statistics' job
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
        $same = aucSame(aucOthers($conn, $day, $lot), $pbHall, $make, $model, (int) $year, (int) $km);
        if ($same) {
            $out['pb']++;
            // A vote: the source's `$hall` is PB's `$v` - how the hall filter keeps one name.
            $v = trim((string) $same['auction']);
            if ($v !== '' && (strcasecmp($v, $pbHall) === 0 || aucKey($same['make']) === aucKey($make))) {
                $st['votes'][$hall][$v] = ($st['votes'][$hall][$v] ?? 0) + 1;
            }
            if ($own) { $out['merged'] += aucMerge($conn, $carId, $same['car_id']); }
            continue;
        }
        if (!$own && $status !== 'available') { $out['result_skip']++; continue; }   // never offered, already over

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
    aucOut($out + array('now' => time(), 'pb_hall' => $pbHall));
}

http_response_code(400);
echo json_encode(array('ok' => false, 'error' => 'say have, survey, rows, done or dedupe'));
