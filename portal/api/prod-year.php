<?php
/**
 * Production year for a chassis number.
 *
 * jpauc publishes this at /vin - its "Prod. Year" page - and the reference
 * portal offers the same thing on a lot's page. The browser cannot ask jpauc
 * directly (its answer carries no cross-origin header), so the question goes
 * through here.
 *
 * What it is NOT: a way to find a vehicle by chassis number. The auction feed
 * carries a model code beside the engine size - NHP10, ZVW30, JF1 - and never
 * the whole number, so no search of ours can match one. This answers the other
 * question, the one a buyer holding a document actually asks: what year was
 * this chassis built.
 *
 * ---------------------------------------------------------------------------
 * What jpauc's page ACTUALLY sends, which is not what its form is named.
 *
 * The form has two boxes, `short_model_type` and `number_chassis`, and this
 * asked with those two names. jpauc answered
 *
 *     {"error":"Chassis No \/ Maker Not Available"}
 *
 * every single time, so the panel could only ever say "no record" - which is
 * what the client found when they compared it against the page that works. The
 * names belong to the boxes; they are not what is posted. jpauc's own script
 * joins the two with a hyphen and sends ONE field:
 *
 *     $.post("/vin/get_data_chassis_no", {chassis_no: cn, maker_name: ...})
 *
 * and the reply is one flat record, not a list:
 *
 *     {"year":"2016","month":"01","modelname":"AQUA","modelcode":"NHP10-AHXXB",
 *      "gradecode":"XRBN","engineno":"1NZFXE","code":["CVT","5D"], ...}
 *
 * The four columns their table shows are built from it below exactly as their
 * script builds them, so our answer and theirs are the same answer.
 * ---------------------------------------------------------------------------
 *
 * One request per click, from a signed-in customer or a member of staff. It is
 * not a feed and nothing polls it.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!isLoggedIn() && !isAdmin()) {
    http_response_code(403);
    echo json_encode(array('error' => 'Please sign in.'));
    exit;
}

/** The nine makers jpauc's own page offers. Anything else it cannot answer. */
function prodYearMakers() {
    return array('DAIHATSU', 'HONDA', 'ISUZU', 'MAZDA', 'MITSUBISHI',
                 'NISSAN', 'SUBARU', 'SUZUKI', 'TOYOTA');
}

/**
 * Everything we ourselves hold of one chassis model.
 *
 * This is the half jpauc has no way to answer. Their page tells a buyer what
 * their document says; it cannot tell them what is in the sale on Saturday. The
 * feed carries the model code beside the engine size - L375S, NHP10, ZVW30 -
 * and that code is the only field a buyer's document and our catalogue have in
 * common, so it is the join.
 *
 * Read in ONE pass. `chassis LIKE '%CODE%'` has its wildcard on the left and so
 * can use no index: every separate COUNT and GROUP BY would be another walk of
 * the table, six walks for one click. The rows are fetched once, capped, and
 * counted here instead.
 *
 * @param string $type      the chassis model code
 * @param string $matchYear the year jpauc gave, so its own lots can be marked
 * @return array
 */
function vinStock($conn, $type, $matchYear = '') {
    $out = array('model' => $type, 'auction' => 0, 'fixed' => 0, 'capped' => false,
                 'price' => null, 'halls' => array(), 'years' => array(),
                 'days' => array(), 'lots' => array(),
                 // so the year jpauc named can be picked out of the year chips
                 'matchYear' => (string) $matchYear);
    if (!$conn || strlen($type) < 3) {
        return $out;
    }
    $live = currentLotsSql('c');
    $cap  = 4000;
    $st = $conn->prepare(
        "SELECT c.id, c.car_id, c.lot_no, c.auction, c.auction_on, c.auction_time,
                c.make, c.model, c.year, c.mileage, c.price, c.chassis,
                c.grade, c.rating, c.color, c.transmission, c.engine_cc,
                0 AS isfx
           FROM cars c
          WHERE ($live) AND c.chassis LIKE ?
          ORDER BY isfx ASC, c.auction_on ASC, c.auction ASC,
                   CAST(c.lot_no AS UNSIGNED) ASC
          LIMIT " . $cap);
    if (!$st) {
        return $out;
    }
    $like = '%' . $type . '%';
    $st->bind_param('s', $like);
    $st->execute();
    $r = $st->get_result();

    /* Two lists, not one. When jpauc has named a year, the vehicles built in
       that year are the ones the buyer came for, so they lead - the rest fill
       what is left of the dozen. Kept as two short lists rather than sorting
       four thousand rows afterwards. */
    $halls = array(); $years = array(); $days = array();
    $prices = array(); $n = 0; $hit = array(); $any = array();
    $thisYear = (int) date('Y');
    while ($w = $r->fetch_assoc()) {
        $n++;
        $fx = (int) $w['isfx'] === 1;
        if ($fx) {
            $out['fixed']++;
        } else {
            $out['auction']++;
            $h = trim((string) $w['auction']);
            $d = trim((string) $w['auction_on']);
            if ($h !== '') { $halls[$h] = ($halls[$h] ?? 0) + 1; }
            if ($d !== '' && $d !== '0000-00-00') { $days[$d] = ($days[$d] ?? 0) + 1; }
        }
        /* A year of 9999 arrives from the feed and is not a year. Nor is one
           two years in the future - the catalogue is used cars. */
        $y = (int) $w['year'];
        $yOk = ($y > 1950 && $y <= $thisYear + 2);
        if ($yOk) { $years[$y] = ($years[$y] ?? 0) + 1; }

        $p = (float) $w['price'];
        if ($p > 0) { $prices[] = $p; }

        $want = ($matchYear !== '' && $yOk && (string) $y === (string) $matchYear);
        if (($want && count($hit) < 12) || (!$want && count($any) < 12)) {
            // "[10:13:00]" on some rows, bare on others, and 00:00 means the
            // hall does not publish a time - see lotStatusLabel().
            $t = trim((string) $w['auction_time'], "[] \t\n\r");
            if ($t === '' || strpos($t, '00:00:00') === 0) { $t = ''; }
            $row = array(
                /* The row id, which is what car-details.php opens. This used to
                   send car_id - "jp-9048bd…" or "pb-2026-09-15-25-10406" - and
                   the page reads its id as a number, so every lot in this table
                   sent the buyer to the front page instead of to the car. */
                'id'      => (string) $w['id'],
                'lot'     => (string) $w['lot_no'],
                'hall'    => (string) $w['auction'],
                'day'     => (string) $w['auction_on'],
                'time'    => substr($t, 0, 5),
                'name'    => trim($w['make'] . ' ' . $w['model']),
                'year'    => $yOk ? (string) $y : '',
                'km'      => $w['mileage'] > 0 ? number_format((int) $w['mileage']) : '',
                'cc'      => $w['engine_cc'] > 0 ? number_format((int) $w['engine_cc']) : '',
                'chassis' => (string) $w['chassis'],
                'grade'   => trim((string) $w['grade']),
                'rating'  => trim((string) $w['rating']),
                'color'   => trim((string) $w['color']),
                'shift'   => trim((string) $w['transmission']),
                'price'   => ($p > 0 && $p < 9000000) ? number_format($p) : '',
                'fixed'   => $fx,
                'match'   => $want,
            );
            if ($want) { $hit[] = $row; } else { $any[] = $row; }
        }
    }
    $st->close();
    $out['capped'] = ($n >= $cap);
    $out['lots']   = array_slice(array_merge($hit, $any), 0, 12);

    /* What a vehicle of this model actually starts at.

       The straight lowest and highest read "\u00a5 1,000 to \u00a5 77,777,000", which
       tells a buyer nothing: 99,999,000 and 77,777,000 are the feed's way of
       writing "no start price" and there is no list of them to exclude. So the
       band is the middle eight tenths - the tenth cheapest to the tenth dearest -
       and the middle price with it. Sentinels sit outside it by construction,
       and so does the one strange car that would otherwise set the ceiling. */
    if ($prices) {
        sort($prices);
        $c = count($prices);
        $at = function ($f) use ($prices, $c) {
            return $prices[max(0, min($c - 1, (int) round($f * ($c - 1))))];
        };
        $out['price'] = array(
            'min' => number_format($at(0.10)),
            'max' => number_format($at(0.90)),
            'avg' => number_format($at(0.50)),
            'n'   => $c,
        );
    }
    arsort($halls);
    foreach (array_slice($halls, 0, 10, true) as $k => $v) {
        $out['halls'][] = array('v' => (string) $k, 'n' => $v);
    }
    krsort($years);
    foreach (array_slice($years, 0, 14, true) as $k => $v) {
        $out['years'][] = array('v' => (string) $k, 'n' => $v);
    }
    ksort($days);
    foreach ($days as $k => $v) {
        $out['days'][] = array('v' => (string) $k, 'n' => $v);
    }
    return $out;
}

/** One GET or POST to jpauc, dressed as its own page's request. */
function vinCall($url, $post = null) {
    $ch = curl_init($url);
    $opt = array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => array(
            'Referer: https://jpauc.com/vin',
            'X-Requested-With: XMLHttpRequest',
        ),
    );
    if ($post !== null) {
        $opt[CURLOPT_POST]       = 1;
        $opt[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) {
        return null;
    }
    $j = json_decode((string) $body, true);
    if (is_array($j)) {
        return $j;
    }
    /* Some makers answer the bare word `false` - HONDA does for a code it does
       not carry. That is a reply, not a failure, and telling the reader to try
       again shortly would send them back for the same nothing. */
    if ($j === false || $j === null) {
        return array('error' => 'not found');
    }
    return null;
}

/**
 * The maker a chassis code belongs to.
 *
 * jpauc's page fills its own maker box as you type - `/vin/maker_frame_no/NHP10`
 * answers `{"maker":"TOYOTA"}` - so a buyer holding a document types only what
 * is printed on it. Ours does the same rather than making them guess from a
 * list of nine.
 */
$frame = strtoupper(trim((string) ($_POST['frame'] ?? $_GET['frame'] ?? '')));
if ($frame !== '') {
    $frame = chassisParts($frame)[0];
    $j = ($frame === '') ? null
       : vinCall('https://jpauc.com/vin/maker_frame_no/' . rawurlencode($frame));
    $mk = strtoupper(trim((string) ($j['maker'] ?? '')));
    echo json_encode(array(
        'maker' => in_array($mk, prodYearMakers(), true) ? $mk : '',
    ));
    exit;
}

/* Our stock on its own, with no chassis number at all.

   A buyer reading a lot's page does not always have a document in hand; often
   the question is simply "what else like this is in the sale". The model code is
   already printed on the page, so the panel offers that on its own - and this
   asks jpauc nothing whatsoever. */
if (!empty($_POST['stock']) || !empty($_GET['stock'])) {
    // The model code out of whatever the box holds - NHP10-2054321 gives NHP10, and
    // Pacific Boeki's prefixed QDF-KDY221 gives KDY221, not the prefix.
    $t = chassisParts((string) ($_POST['type'] ?? $_GET['type'] ?? ''))[0];
    if (strlen($t) < 3) {
        echo json_encode(array('error' => 'Enter the chassis model, e.g. NHP10.'));
        exit;
    }
    echo json_encode(array('stock' => vinStock(getDatabaseConnection(), $t)));
    exit;
}

$maker = strtoupper(trim((string) ($_POST['maker'] ?? $_GET['maker'] ?? '')));
$type  = strtoupper(trim((string) ($_POST['type']  ?? $_GET['type']  ?? '')));
$num   = strtoupper(trim((string) ($_POST['number'] ?? $_GET['number'] ?? '')));

// A buyer pastes the whole thing into the first box as often as not - and a
// prefixed code (QDF-KDY221) is the model KDY221, whichever box it sits in.
if ($num === '') {
    list($type, $num) = chassisParts($type);
} else {
    $type = chassisParts($type)[0];
}
$type = preg_replace('/[^A-Z0-9]/', '', $type);
$num  = preg_replace('/[^A-Z0-9]/', '', $num);

if ($type === '' || $num === '') {
    echo json_encode(array('error' => 'Enter the chassis code and the number after it.'));
    exit;
}

// Nothing chosen in the maker box: ask which maker the code belongs to, the way
// jpauc's own page does, instead of refusing to look.
if (!in_array($maker, prodYearMakers(), true)) {
    $j  = vinCall('https://jpauc.com/vin/maker_frame_no/' . rawurlencode($type));
    $mk = strtoupper(trim((string) ($j['maker'] ?? '')));
    if (in_array($mk, prodYearMakers(), true)) {
        $maker = $mk;
    } else {
        echo json_encode(array('error' => 'Choose one of the nine makers this lookup covers.'));
        exit;
    }
}

$chassis = $type . '-' . $num;
$j = vinCall('https://jpauc.com/vin/get_data_chassis_no',
             array('chassis_no' => $chassis, 'maker_name' => $maker));

if ($j === null) {
    echo json_encode(array('error' => 'The lookup did not answer. Try again shortly.'));
    exit;
}
if (isset($j['error'])) {
    // jpauc's own page says "Car Catalog not found"; say what that means here.
    echo json_encode(array('chassis' => $chassis, 'maker' => $maker,
                           'error' => 'No record for that chassis number and maker.'));
    exit;
}

/* The four columns, built the way jpauc's own script builds them.
   Seats come out of the `code` list - 07S, 08S and their kind mean seven and
   eight - and anything else in that list picks between the variants of a model
   name that arrives as "ONE/OTHER": the variant carrying every character of the
   code is the one meant. Grade Code is `modelcode`, not `gradecode`; that reads
   like a slip on their side, but it is what their page prints, and the point of
   this panel is to print what their page prints. */
$year  = trim((string) ($j['year'] ?? ''));
$month = trim((string) ($j['month'] ?? ''));
$model = isset($j['modelname']) ? trim((string) $j['modelname']) : 'n/a';
$grade = isset($j['modelcode'])  ? trim((string) $j['modelcode'])  : 'n/a';
$seat  = 'n/a';

$codes = array();
if (isset($j['code'])) {
    $codes = is_array($j['code']) ? $j['code'] : array($j['code']);
}
$seatCodes = array('02S', '04S', '05S', '06S', '07S', '08S');
foreach ($codes as $v) {
    if (!is_string($v) && !is_numeric($v)) {
        continue;
    }
    $v = trim((string) $v);
    if (in_array($v, $seatCodes, true)) {
        $seat = (string) (int) substr($v, 0, 2);
        continue;
    }
    if ($v === '' || strpos($model, '/') === false) {
        continue;
    }
    $chars = preg_split('//', preg_replace('/\W+/', ' ', $v), -1, PREG_SPLIT_NO_EMPTY);
    $pat = '';
    foreach ((array) $chars as $ch) {
        $pat .= '(?=.*' . preg_quote($ch, '/') . ')';
    }
    if ($pat === '') {
        continue;
    }
    foreach (explode('/', $model) as $part) {
        if (preg_match('/' . $pat . '/', $part)) {
            $model = $part;
        }
    }
}

/* The rest of what the answer carries.

   jpauc's own table prints four columns and throws the rest away, and this did
   the same. But the reply also holds the engine number, the grade, the paint and
   trim codes, the body and drive, the catalogue number and the option codes -
   asked for in the same request, already paid for, and exactly what a buyer
   holding a document wants to check against it. There is no reason to discard
   them, so they are listed under the table, each one only if it came. */
$more = array();
function vinAdd(&$more, $label, $v) {
    if (is_array($v)) {
        $v = implode(', ', array_filter(array_map('strval', $v), 'strlen'));
    }
    $v = trim((string) $v);
    if ($v !== '' && $v !== 'n/a') {
        $more[] = array('k' => $label, 'v' => $v);
    }
}
vinAdd($more, 'Engine Number',  $j['engineno'] ?? '');
vinAdd($more, 'Grade',          $j['gradecode'] ?? '');
vinAdd($more, 'Transmission',   $j['transcode'] ?? '');
vinAdd($more, 'Body',           $j['bodycode'] ?? '');
vinAdd($more, 'Drive',          $j['doorstyle'] ?? '');
vinAdd($more, 'Colour Code',    $j['colorcode'] ?? '');
vinAdd($more, 'Trim Code',      $j['trimcode'] ?? '');
vinAdd($more, 'Catalogue No.',  $j['catalogno'] ?? '');
vinAdd($more, 'Option Codes',   $j['code'] ?? '');

/* And the lots we hold of the same model, with the vehicles built in the year
   jpauc just named marked out of the rest. */
echo json_encode(array(
    'chassis' => $chassis,
    'maker'   => $maker,
    'rows'    => array(array(
        'ym'    => trim($year . ' / ' . $month, " /"),
        'model' => $model,
        'grade' => $grade,
        'seat'  => $seat,
    )),
    'more'  => $more,
    'model' => $type,
    'stock' => vinStock(getDatabaseConnection(), $type, $year),
));
