<?php
/**
 * Statistics ingest - the receiving half of the aaajapan statistics feed.
 *
 * WHY THIS EXISTS. aaajapan (bid.aaajapan.com) blocks this hosting server's IP
 * outright (403 "Your IP address: 66.29.146.11"), so the harvester that lives
 * here - aaa-stats-harvest.php - cannot reach it. A machine that is NOT blocked
 * (the owner's PC, or a small always-on cloud box) does the fetching instead and
 * POSTs the rows here; this file writes them into `car_stats` and touches
 * nothing else. It fetches nothing itself, so it is safe to run on the blocked
 * server. See specs/008-auction-statistics/spec.md and the memory
 * aaajapan-statistics.
 *
 * The mapping (which single letter is which column, how identity is derived) is
 * exactly the harvester's, kept in step with it deliberately: the fetcher stays
 * dumb - login, page, forward - and the knowledge of the row shape stays here.
 *
 *   POST aaa-stats-ingest.php?t=<TOKEN>
 *   body: JSON { "maker": "TOYOTA", "rows": [ {a:..,b:..,c:..}, ... ] }
 *   -> { "ok": true, "written": N, "new": K, "new_days": {"2026-09-18": K}, "in_db": TOTAL }
 *
 * `written` is every row stored, refreshed ones included; `new` is only the rows
 * the table did not have before. They are not the same thing, and for five days
 * the fetcher was steering by `written` - see the note at `$new` below.
 */

require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* The token is not written here any more (21 September 2026): it lives in the
   server's .env, the one file that already knows the database password and is
   never committed. An empty token refuses everything rather than letting an
   empty one in - hash_equals('', '') is true, and that would be an open door. */
define('AAA_INGEST_TOKEN', env_get('AAA_INGEST_TOKEN', ''));

if (AAA_INGEST_TOKEN === '' || !hash_equals(AAA_INGEST_TOKEN, (string) ($_GET['t'] ?? ''))) {
    http_response_code(404);
    exit;
}

/* ------------------------------------------------------- "how is the ID?"
 * The fetcher says, at the end of EVERY run, how its sign-in to aaajapan went -
 * whether it got in, was refused, was turned away at the door, or has stopped
 * after a refusal - so the Statistics page can show staff a green or a red
 * signal (the owner's request of 24 September 2026; see source-health.php).
 * Kept in the account's home, outside the web root, as the last word only.
 *
 *   POST ?t=TOKEN&health=1   {"login":"ok","halted":"","door":0,"spent":false,...}
 */
if (isset($_GET['health'])) {
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'expected a JSON object'));
        exit;
    }
    $keep = array(
        'at'        => time(),
        'login'     => substr((string) ($in['login'] ?? ''), 0, 20),
        'why'       => substr((string) ($in['why'] ?? ''), 0, 300),
        'halted'    => substr((string) ($in['halted'] ?? ''), 0, 300),
        'halted_at' => (int) ($in['halted_at'] ?? 0),
        'door'      => (int) ($in['door'] ?? 0),
        'spent'     => !empty($in['spent']),
        'used'      => (int) ($in['used'] ?? 0),
        'budget'    => (int) ($in['budget'] ?? 0),
        'pages'     => (int) ($in['pages'] ?? 0),
        'new'       => (int) ($in['new'] ?? 0),
        'run'       => substr((string) ($in['run'] ?? ''), 0, 40),
    );
    $dir = dirname(__DIR__) . '/aaa-fetch';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    /* Most runs on a quiet day find nothing new and never sign in at all, so
       "the ID worked" has to be remembered from the last run that DID sign in -
       otherwise the signal could only ever say what this run skipped. */
    $prev = json_decode((string) @file_get_contents($dir . '/health.json'), true);
    $keep['last_ok'] = ($keep['login'] === 'ok') ? $keep['at']
                     : (int) (is_array($prev) ? ($prev['last_ok'] ?? 0) : 0);
    $ok = @file_put_contents($dir . '/health.json.tmp', json_encode($keep)) !== false
       && @rename($dir . '/health.json.tmp', $dir . '/health.json');
    echo json_encode(array('ok' => (bool) $ok));
    exit;
}

/* ----------------------------------------------------- "what do you have?"
 * The fetcher asks this BEFORE it asks the source for anything, so it never
 * spends a request on a slice whose rows are already here. It costs the source
 * nothing - it only counts our own table (0.7s over ten days, on k_sold_on).
 *
 * Found 19 September 2026, when the owner said the statistics felt far too slow:
 * of a day's 10,000 requests about four in five re-read rows we already held,
 * while the newest sale days sat almost empty (NISSAN 18 Sep: 0 of 2,420).
 *
 *   GET ?t=TOKEN&have=days&from=2026-09-10&to=2026-09-19  -> {"have": {"TOYOTA|2026-09-18": 37, ...}}
 *   GET ?t=TOKEN&have=months&from=2026-01-01              -> {"have": {"TOYOTA|2026-07": 75362, ...}}
 */
if (($_GET['have'] ?? '') !== '') {
    $months = ($_GET['have'] === 'months');
    $pick = function ($key, $fallback) {
        $v = (string) ($_GET[$key] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $fallback;
    };
    $from = $pick('from', gmdate('Y-m-d', time() - 45 * 86400));
    $to   = $pick('to', gmdate('Y-m-d', time() + 86400));
    $fmt  = $months ? '%Y-%m' : '%Y-%m-%d';
    $st = $conn->prepare("SELECT maker, DATE_FORMAT(sold_on, '$fmt') k, COUNT(*) c
                            FROM car_stats WHERE sold_on BETWEEN ? AND ? GROUP BY maker, k");
    $st->bind_param('ss', $from, $to);
    $st->execute();
    $res = $st->get_result();
    $have = array();
    while ($res && $row = $res->fetch_assoc()) {
        $have[strtoupper((string) $row['maker']) . '|' . $row['k']] = (int) $row['c'];
    }
    $st->close();
    echo json_encode(array('ok' => true, 'kind' => $months ? 'months' : 'days',
                           'from' => $from, 'to' => $to, 'have' => $have));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'POST only'));
    exit;
}

$raw = file_get_contents('php://input');
$in  = json_decode((string) $raw, true);
if (!is_array($in) || !isset($in['rows']) || !is_array($in['rows'])) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'expected { maker, rows: [...] }'));
    exit;
}
$askedMaker = trim((string) ($in['maker'] ?? ''));
$rows = $in['rows'];
if (count($rows) > 500) {
    http_response_code(413);
    echo json_encode(array('ok' => false, 'error' => 'at most 500 rows per batch'));
    exit;
}

/* The table, made if it is not there - same shape the harvester creates, so
   either half can be the first to run. */
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
       engine_hp    INT          NULL,
       drive        VARCHAR(24)  NULL,
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
/* Added 15 September 2026, after the row map was read out of the source's own
   template instead of guessed. A table made before that date has neither. */
@$conn->query("ALTER TABLE car_stats ADD COLUMN IF NOT EXISTS engine_hp INT NULL AFTER rating");
@$conn->query("ALTER TABLE car_stats ADD COLUMN IF NOT EXISTS drive VARCHAR(24) NULL AFTER engine_hp");

/** sha1(hall|date|lot) - a lot is unique inside one hall on one day; the
 *  source's own key changes between sessions, so it cannot be the identity. */
function ingStatId(array $r) {
    $hall = trim($r['d'] ?? '');
    $day  = trim($r['e'] ?? '');
    $lot  = trim($r['c'] ?? '');
    if ($hall === '' || $day === '' || $lot === '') { return null; }
    return substr(sha1($hall . '|' . $day . '|' . $lot), 0, 32);
}
/** `09.06.2026` -> `2026-06-09`, or null. */
function ingDay($s) {
    $s = trim((string) $s);
    if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) { return null; }
    return $m[3] . '-' . $m[2] . '-' . $m[1];
}
/**
 * A number, or null - and never a number the column cannot hold.
 *
 * Without the ceiling an odd field saturated at 2,147,483,647, the largest value
 * an INT takes, and that figure then sat in the table looking like a reading.
 */
function ingNum($s, $max = 2000000000) {
    $n = preg_replace('/\D+/', '', (string) $s);
    if ($n === '') { return null; }
    $v = (int) $n;
    return ($v < 0 || $v > $max) ? null : $v;
}
/**
 * A text field as it should be read, not as it was transported.
 *
 * The source sends some fields HTML-encoded - `&#65413;&#65404;` for a Japanese
 * transmission code, and `<b>sold</b>` for a result - and 124 rows had reached
 * the table with the entities still in them, which is exactly what a customer
 * would have seen on the page.
 */
function ingText($s) {
    $t = html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // The source cuts long texts at a fixed length, sometimes through a character
    // code ("...ｱｯﾌﾟﾁﾙ&#654", 25 September 2026): half a code is dropped, not kept.
    return trim(preg_replace('/&#\d*$/', '', $t));
}

$stmt = $conn->prepare(
    "INSERT INTO car_stats
      (stat_id, maker, model, lot_no, auction, sold_on, sold_time, year,
       engine_cc, mileage, chassis, grade, model_grade, transmission,
       rating, engine_hp, drive, colour, start_price, final_price, result, photos,
       source, last_seen, created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'aaajapan',NOW(),NOW())
     ON DUPLICATE KEY UPDATE
       maker=VALUES(maker), model=VALUES(model), auction=VALUES(auction),
       sold_on=VALUES(sold_on), sold_time=VALUES(sold_time),
       year=VALUES(year), engine_cc=VALUES(engine_cc),
       mileage=VALUES(mileage), engine_hp=VALUES(engine_hp), drive=VALUES(drive),
       chassis=VALUES(chassis), grade=VALUES(grade),
       model_grade=VALUES(model_grade), transmission=VALUES(transmission),
       rating=VALUES(rating), colour=VALUES(colour),
       start_price=VALUES(start_price), final_price=VALUES(final_price),
       result=VALUES(result),
       photos=IF(CHAR_LENGTH(VALUES(photos)) > 2, VALUES(photos), photos),
       last_seen=NOW()");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'prepare failed'));
    exit;
}

$written = 0;
/* Rows this page ADDED, per sale day. MySQL answers an INSERT ... ON DUPLICATE
   KEY UPDATE with 1 for a new row and 2 for an existing one (last_seen always
   changes, so an existing row is never 0). `written` counts both, and the
   fetcher used it to decide whether a page had told it anything new - so every
   page looked useful, and on 19 September 2026 a whole run of 396 requests
   re-read rows it already had without once noticing (in_db did not move). */
$new = 0;
$newDays = array();
foreach ($rows as $r) {
    if (!is_array($r)) { continue; }
    $id = ingStatId($r);
    if ($id === null) { continue; }

    /* `b` is "MAKER MODEL". The maker column is filled from the maker the fetcher
       asked for (reliable even when a maker's name has a space); the model is
       what is left after that prefix, falling back to a split on the first space
       when the asked maker is not given or does not lead the string. */
    $b = trim($r['b'] ?? '');
    if ($askedMaker !== '' && stripos($b, $askedMaker) === 0) {
        $maker = $askedMaker;
        $model = trim(substr($b, strlen($askedMaker)));
    } else {
        $sp    = strpos($b, ' ');
        $maker = $sp === false ? $b : substr($b, 0, $sp);
        $model = $sp === false ? '' : trim(substr($b, $sp + 1));
    }

    $lot   = ingText($r['c'] ?? '');
    $hall  = ingText($r['d'] ?? '');
    $day   = ingDay($r['e'] ?? '');
    $time  = trim(ingText($r['f'] ?? ''), "[] \t");
    $year  = ingNum($r['g'] ?? '', 2100);
    $cc    = ingNum($r['h'] ?? '', 30000);
    /* WHICH LETTER IS WHICH, read out of the source's own row template rather
       than guessed (15 September 2026):
         q  mileage, ALREADY in kilometres - the template prints `${b.q} km`
         i  engine power, in hp   n  drive (FF, 4WD, ...)
       The first harvester assumed `i` was the mileage, and this file inherited
       it, so 412,000 rows carried an engine's horsepower under "Mileage (KM)" -
       a 2.0 Accord reading "145 km". Proven on rows carrying both: q = 17000,
       18000, 19000 while i = 145 for the 2.0 and 206 for the 2.4. */
    $km    = ingNum($r['q'] ?? '', 3000000);
    $hp    = ingNum($r['i'] ?? '', 5000);
    $drive = ingText($r['n'] ?? '');
    $chas  = ingText($r['j'] ?? '');
    $grade = ingText($r['k'] ?? '');
    $mgr   = ingText($r['l'] ?? '');
    $kpp   = ingText($r['m'] ?? '');
    $rate  = ingText($r['r'] ?? '');
    $col   = ingText($r['w'] ?? '');
    $start = ingNum($r['s'] ?? '', 9000000000);
    $final = ingNum($r['t'] ?? '', 9000000000);
    // The source writes the result as "<b>sold</b>" / "not sold"; keep the words.
    $res   = ingText($r['v'] ?? '');
    /* array_unique as well as array_filter: some halls send the same token in
       two of the three slots, which stored one photograph twice and made the
       viewer offer a picture it had already shown. See statPhotos() in
       statistics.php, which drops them on the way out too, for the rows that
       were stored before this. */
    $pics  = json_encode(array_values(array_unique(array_filter(array(
        trim($r['x'] ?? ''), trim($r['y'] ?? ''), trim($r['z'] ?? '')), 'strlen'))));

    /* The columns run ... rating, engine_hp, drive, COLOUR, start_price,
       final_price, result, photos - colour sits BEFORE the two prices, not
       after. The variables and the type string must follow that exact order, or
       the price lands in the colour column and "white" lands in a price (which
       is how the first test stored "start 2875000, colour 2200000, final 0"). */
    $stmt->bind_param(
        'sssss' . 'ss' . 'iii' . 'sssss' . 'is' . 's' . 'ii' . 'ss',
        $id, $maker, $model, $lot, $hall,
        $day, $time,
        $year, $cc, $km,
        $chas, $grade, $mgr, $kpp, $rate,
        $hp, $drive,
        $col,
        $start, $final,
        $res, $pics
    );
    if ($stmt->execute()) {
        $written++;
        if ($stmt->affected_rows === 1) {
            $new++;
            $newDays[(string) $day] = ($newDays[(string) $day] ?? 0) + 1;
        }
    }
}
$stmt->close();

$in_db = 0;
if ($q = $conn->query("SELECT COUNT(*) FROM car_stats")) {
    $in_db = (int) $q->fetch_row()[0];
}
echo json_encode(array('ok' => true, 'written' => $written, 'new' => $new,
                       'new_days' => (object) $newDays, 'in_db' => $in_db));
