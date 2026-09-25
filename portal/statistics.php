<?php
/**
 * SBK Auction — past auction prices.
 *
 * What the auction list cannot tell a buyer: what these cars actually go for.
 * jpauc publishes nothing about concluded sales, so this reads a second source
 * (bid.aaajapan.com) into its own table and never touches the auction list.
 * See specs/008-auction-statistics/spec.md.
 *
 * A lot that did not sell is shown as such, and its final figure is presented
 * as the highest bid rather than a price — the difference matters to somebody
 * deciding what to offer.
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/statistics-lib.php';

requireLogin();

global $conn;

/* The table is made by the harvester. Until that has run once there is nothing
   to query, and a missing table must read as "nothing yet" rather than a crash
   in front of a customer. */
$haveTable = false;
if ($r = @$conn->query("SHOW TABLES LIKE 'car_stats'")) {
    $haveTable = $r->num_rows > 0;
}

$page     = max(1, (int) ($_GET['page'] ?? 1));
/* 30 by default; 50 or 100 on request, as the source offers. */
$per_page = (int) ($_GET['per'] ?? 30);
if (!in_array($per_page, array(30, 50, 100), true)) { $per_page = 30; }

$maker  = trim($_GET['maker'] ?? '');
$model  = trim($_GET['model'] ?? '');
$chas   = trim($_GET['chassis'] ?? '');
$hall   = trim($_GET['auction'] ?? '');
$y1     = trim($_GET['y1'] ?? '');
$y2     = trim($_GET['y2'] ?? '');
$result = trim($_GET['result'] ?? '');
/* How far back to look, in months. The owner asked for one, two and three.
   Anything else is treated as "all", so a hand-typed months=99 widens nothing
   and narrows nothing - it simply is not a filter. */
$months = trim($_GET['months'] ?? '');
if (!in_array($months, array('1', '2', '3'), true)) { $months = ''; }

/* The source's ADVANCED SEARCH, filter for filter (the client, 24 September 2026:
   "the source has filters we don't"). See includes/statistics-lib.php. Every value
   is bound as a parameter; nothing typed reaches the SQL as text. */
$lot    = trim($_GET['lot'] ?? '');
$houses = stList($_GET['houses'] ?? array());
if ($hall !== '' && !in_array($hall, $houses, true)) { $houses[] = $hall; }   // old ?auction= links
$hall   = '';
$grades = array_values(array_intersect(STAT_GRADES, stList($_GET['grades'] ?? array())));
$trans  = trim($_GET['trans'] ?? '');
$equip  = trim($_GET['equip'] ?? '');
$colour = trim($_GET['colour'] ?? '');
$ranges = array();
foreach (array('km' => 'mileage', 'cc' => 'engine_cc', 'sp' => 'start_price', 'fp' => 'final_price') as $rk => $col) {
    $ranges[$rk] = array($col,
        preg_replace('/\D+/', '', (string) ($_GET[$rk . '1'] ?? '')),
        preg_replace('/\D+/', '', (string) ($_GET[$rk . '2'] ?? '')));
}
$advOn = ($houses || $grades || $trans !== '' || $equip !== '' || $colour !== ''
          || array_filter($ranges, function ($r) { return $r[1] !== '' || $r[2] !== ''; }));

/* Every column sorts, both ways, as on the source. The key is looked up here, so
   only these expressions ever reach ORDER BY. */
$SORTS = array(
    'date'  => array('sold_on', 'DESC'),       'hall'  => array('auction', 'ASC'),
    'lot'   => array('CAST(lot_no AS UNSIGNED)', 'ASC'),
    'model' => array('maker %s, model', 'ASC'), 'year'  => array('year', 'DESC'),
    'chassis' => array('chassis', 'ASC'),      'cc'    => array('engine_cc', 'DESC'),
    'km'    => array('mileage', 'ASC'),        'grade' => array('rating', 'ASC'),
    'start' => array('start_price', 'DESC'),   'final' => array('final_price', 'DESC'),
);
$sort = (string) ($_GET['sort'] ?? 'date');
if (!isset($SORTS[$sort])) { $sort = 'date'; }
$dir = strtolower((string) ($_GET['dir'] ?? ''));
$dir = ($dir === 'asc' || $dir === 'desc') ? strtoupper($dir) : $SORTS[$sort][1];
$orderBy = sprintf(strpos($SORTS[$sort][0], '%s') !== false ? $SORTS[$sort][0] : $SORTS[$sort][0] . ' %s', $dir)
         . ($sort === 'model' ? ' ' . $dir : '') . ', sold_on DESC, auction ASC, lot_no ASC';
/* A blank or zero value says nothing, so it goes LAST whichever way a column is
   sorted - "mileage, lowest first" opened on thirty rows of dashes before this.
   (The date keeps its plain order, which an index serves.) */
$blank = array('km' => "(mileage IS NULL OR mileage = 0)", 'cc' => "(engine_cc IS NULL OR engine_cc = 0)",
               'year' => "(year IS NULL OR year = 0)", 'start' => "(start_price IS NULL OR start_price = 0)",
               'final' => "(final_price IS NULL OR final_price = 0)", 'grade' => "(rating IS NULL OR rating = '')",
               'chassis' => "(chassis IS NULL OR chassis = '')", 'hall' => "(auction = '')");
if (isset($blank[$sort])) {
    $orderBy = $blank[$sort] . ' ASC, ' . $orderBy;
}

$where  = array(stWindowSql());      // only what the source shows - see STAT_WINDOW_DAYS
$params = array();
$types  = '';

if ($maker !== '') { $where[] = 'maker = ?';   $params[] = $maker; $types .= 's'; }
if ($hall  !== '') { $where[] = 'auction = ?'; $params[] = $hall;  $types .= 's'; }
if ($model !== '') {
    // The model's NAME - "prius", "Prius Alpha" - and a buyer who types the maker
    // with it ("toyota prius") is still understood.
    $where[]  = "CONCAT(maker, ' ', model) LIKE ?";
    $params[] = '%' . preg_replace('/\s+/', ' ', $model) . '%';
    $types   .= 's';
}
if ($chas !== '') {
    /* A chassis the way a buyer holds it: the model code alone (S321V), the whole
       number (S321V-0123456, with or without the dash, any case), or with the
       type-approval prefix in front (EBD-S321V-0123456). The source stores the CODE
       - bare for most lots, prefixed (EBD-S321V) for some - so the typed text is
       read with the portal's own chassisParts() and matched three ways.

       Until 24 September 2026 this was "code LIKE typed OR typed LIKE code%", and
       an EMPTY stored code satisfies the second half for anything typed: every
       chassis-number search also returned the 6,576 lots with no chassis at all,
       and a prefixed code (EBD-S321V) was never found from its number. */
    $typed = strtoupper(preg_replace('/\s+/', '', $chas));
    list($code, $serial) = chassisParts($chas);
    $or = array('chassis LIKE ?');                               // a code, or part of one
    $params[] = '%' . $typed . '%';
    $types   .= 's';
    if ($code !== '' && strlen($code) >= 2) {                    // the code read out of it, prefix or not
        $or[] = '(chassis = ? OR chassis LIKE ?)';
        $params[] = $code;
        $params[] = '%-' . $code;
        $types   .= 'ss';
    }
    /* A whole number typed WITHOUT a dash (S321V0123456, NHP101234567) cannot be
       split for certain - a code can end in digits too - so every split that leaves
       a serial of 4 to 8 digits is tried as the code: S321V | 0123456, NHP10 |
       1234567 (and NHP1 | 01234567, which no car has). "The code anywhere inside
       it" (the portal's chassisNumberSql) was tried first and pulled in KH-012 from
       the serial, HP10 from NHP10 and DA1 from DA17V; "starts with" still took a
       lot stored as "---". With a dash, the exact code above is already the answer. */
    if ($serial === '' && preg_match('/^(.*[A-Z])(\d{4,})$/', $code, $m)) {
        $cands = array();
        for ($k = 0; $k <= strlen($m[2]); $k++) {
            $rest = strlen($m[2]) - $k;
            if ($rest >= 4 && $rest <= 8) { $cands[] = $m[1] . substr($m[2], 0, $k); }
        }
        if ($cands) {
            $in = implode(',', array_fill(0, count($cands), '?'));
            $or[] = "(REPLACE(chassis, '-', '') IN ($in) OR SUBSTRING_INDEX(chassis, '-', -1) IN ($in))";
            foreach (array_merge($cands, $cands) as $cd) { $params[] = $cd; $types .= 's'; }
        }
    }
    $where[] = '(' . implode(' OR ', $or) . ')';
}
/* The auction DATE, which is not the car's year just below - `sold_on` is
   indexed, so this stays a range scan rather than a walk over the lot. The
   number is one of exactly three we allowed above, so it is written into the
   query directly: INTERVAL takes a literal, and a bound parameter here is a
   driver quirk waiting to happen. */
if ($months !== '') {
    $where[] = 'sold_on >= DATE_SUB(CURDATE(), INTERVAL ' . (int) $months . ' MONTH)';
}
if (ctype_digit($y1)) { $where[] = 'year >= ?'; $params[] = (int) $y1; $types .= 'i'; }
if (ctype_digit($y2)) { $where[] = 'year <= ?'; $params[] = (int) $y2; $types .= 'i'; }
if ($result === 'sold') {
    $where[] = STAT_SOLD_SQL;
} elseif ($result === 'unsold') {
    $where[] = STAT_UNSOLD_SQL;
}
if ($lot !== '') {
    // one lot number or several, as the source's lot box takes them
    $lots = array_slice(array_values(array_filter(preg_split('/[\s,;]+/', $lot), 'strlen')), 0, 20);
    if ($lots) {
        $where[] = 'lot_no IN (' . implode(',', array_fill(0, count($lots), '?')) . ')';
        foreach ($lots as $l) { $params[] = $l; $types .= 's'; }
    }
}
if ($houses) {
    $where[] = 'auction IN (' . implode(',', array_fill(0, count($houses), '?')) . ')';
    foreach ($houses as $h) { $params[] = $h; $types .= 's'; }
}
if ($grades) {
    $where[] = 'rating IN (' . implode(',', array_fill(0, count($grades), '?')) . ')';
    foreach ($grades as $g) { $params[] = $g; $types .= 's'; }
}
if ($trans !== '')  { $where[] = STAT_COL_TRANS . ' = ?'; $params[] = $trans;  $types .= 's'; }
if ($equip !== '')  { $where[] = STAT_COL_EQUIP . ' = ?'; $params[] = $equip;  $types .= 's'; }
if ($colour !== '') { $where[] = 'colour = ?';            $params[] = $colour; $types .= 's'; }
foreach ($ranges as $r) {
    if ($r[1] !== '') { $where[] = $r[0] . ' >= ?'; $params[] = (int) $r[1]; $types .= 'i'; }
    if ($r[2] !== '') { $where[] = $r[0] . ' <= ?'; $params[] = (int) $r[2]; $types .= 'i'; }
}
$where_sql = implode(' AND ', $where);

/* The list as a spreadsheet - the source's download button. For the desk only:
   the statistics are the business's paid data, and a customer copying ten
   thousand rows at a click is not something to hand out by default. */
if (isset($_GET['csv']) && isAdmin() && $haveTable) {
    if (function_exists('session_write_close')) { session_write_close(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sbk-statistics-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                         // so Excel reads the yen sign and the dashes
    fputcsv($out, array('Sold on', 'Time', 'Auction', 'Lot', 'Maker', 'Model', 'Year', 'Chassis', 'Model grade',
                        'Engine cc', 'HP', 'Transmission', 'Equipment', 'Drive', 'Mileage km', 'Colour',
                        'Condition', 'Start JPY', 'Final JPY', 'Result'));
    $st = $conn->prepare("SELECT * FROM car_stats WHERE $where_sql ORDER BY $orderBy LIMIT 10000");
    if ($st) {
        if ($params) { $st->bind_param($types, ...$params); }
        $st->execute();
        $res = $st->get_result();
        while ($c = $res->fetch_assoc()) {
            $o = stOutcome($c);
            fputcsv($out, array($c['sold_on'], $c['sold_time'], $c['auction'], $c['lot_no'], $c['maker'], $c['model'],
                $c['year'], $c['chassis'], $c['model_grade'], $c['engine_cc'], $c['engine_hp'],
                $c[STAT_COL_TRANS], $c[STAT_COL_EQUIP], $c['drive'], $c['mileage'], $c['colour'], $c['rating'],
                $c['start_price'], $c['final_price'], $o['label']));
        }
        $st->close();
    }
    fclose($out);
    exit;
}

/* The count-only answer, and it has to come BEFORE the page's own queries.
 *
 * It used to sit further down, after the rows, the maker list and the hall list
 * had all been fetched - two GROUP BYs over six hundred thousand rows for an
 * answer that needs none of them. Worse, PHP holds an exclusive lock on the
 * session for the length of a request, so this one blocked the `api/counts.php`
 * the same page asks for at the same moment; that count then ran a second or two
 * late and came back a few dozen rows higher than the identical figure on the
 * dashboards. The session is closed for writing here too - nothing below is
 * written to it - so the two requests never queue behind each other again.
 */
if (isset($_GET['count'])) {
    if (function_exists('session_write_close')) { session_write_close(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $t = 0; $sn = 0; $av = null;
    if ($haveTable) {
        $st = $conn->prepare(
            "SELECT COUNT(*) n,
                    SUM(final_price > 0 AND " . STAT_SOLD_SQL . ") sn,
                    AVG(CASE WHEN final_price > 0 AND " . STAT_SOLD_SQL . " THEN final_price END) a
               FROM car_stats WHERE $where_sql");
        if ($st) {
            if ($params) { $st->bind_param($types, ...$params); }
            $st->execute();
            $g = $st->get_result()->fetch_assoc();
            $t  = (int) $g['n'];
            $sn = (int) $g['sn'];
            $av = $g['a'] !== null ? (int) round((float) $g['a']) : null;
            $st->close();
        }
    }
    echo json_encode(array('total' => $t, 'sold' => $sn, 'avg' => $av));
    exit;
}

$total = 0;
$rows  = array();
$avg   = null;
$soldN = 0;
$makers = array();
$halls  = array();

if ($haveTable) {
    /* Unfiltered, these three figures are a count and an average over the whole
       million-row table - about a second - and they move by a few rows a minute.
       Kept sixty seconds; a filtered list always counts afresh. */
    $plain = ($where_sql === stWindowSql());
    $cachedTotals = $plain ? stCached('totals-w', 60, function () use ($conn) {
        $n = (int) $conn->query("SELECT COUNT(*) FROM car_stats WHERE " . stWindowSql())->fetch_row()[0];
        $g = $conn->query("SELECT COUNT(*) n, AVG(final_price) a FROM car_stats
                            WHERE final_price > 0 AND " . STAT_SOLD_SQL . " AND " . stWindowSql())->fetch_assoc();
        return array('total' => $n, 'sold' => (int) $g['n'], 'avg' => $g['a'] !== null ? (float) $g['a'] : null);
    }) : null;
    /* Filtered: the count, how many of them sold, and their average - ONE pass
       over the matching rows rather than two. */
    $st = $cachedTotals ? null : $conn->prepare(
        "SELECT COUNT(*) n,
                SUM(final_price > 0 AND " . STAT_SOLD_SQL . ") sn,
                AVG(CASE WHEN final_price > 0 AND " . STAT_SOLD_SQL . " THEN final_price END) a
           FROM car_stats WHERE $where_sql");
    if ($cachedTotals) {
        $total = (int) $cachedTotals['total'];
        $soldN = (int) $cachedTotals['sold'];
        $avg   = $cachedTotals['avg'];
    }
    if ($st) {
        if ($params) { $st->bind_param($types, ...$params); }
        $st->execute();
        $g = $st->get_result()->fetch_assoc();
        $total = (int) $g['n'];
        $soldN = (int) $g['sn'];
        $avg   = $g['a'] !== null ? (float) $g['a'] : null;
        $st->close();
    }

    /* The average above is of what actually sold, and of nothing else. Folding
       the unsold in would drag it toward the last bid nobody accepted, which is
       the one number a buyer must not mistake for a price. */

    $pages  = max(1, (int) ceil($total / $per_page));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $per_page;

    $st = $conn->prepare(
        "SELECT stat_id, maker, model, lot_no, auction, sold_on, sold_time, year,
                engine_cc, mileage, chassis, grade, model_grade, transmission,
                rating, engine_hp, drive, colour, start_price, final_price, result, photos
           FROM car_stats
          WHERE $where_sql
          ORDER BY $orderBy
          LIMIT ? OFFSET ?");
    if ($st) {
        $p2 = $params; $p2[] = $per_page; $p2[] = $offset;
        $st->bind_param($types . 'ii', ...$p2);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }

    // A GROUP BY over a million rows for a list that changes slowly: kept ten minutes.
    $makers = stCached('makers-w', 600, function () use ($conn) {
        $out = array();
        if ($res = @$conn->query(
            "SELECT maker, COUNT(*) n FROM car_stats WHERE maker <> '' AND " . stWindowSql() . "
              GROUP BY maker ORDER BY maker ASC")) {
            while ($w = $res->fetch_assoc()) { $out[] = $w; }
        }
        return $out;
    });
    $housesByDay = stHousesByDay($conn);
    $facets      = stFacets($conn);

    /* THE AVERAGE-PRICE COLUMN, as the source has it: for each row, what the same
       model code sold for over the last three months - the average, how many, and
       the last ten prices as small bars. One query for every code on the page. */
    $avgBy = array();
    $codes = array_values(array_unique(array_filter(array_map(function ($c) {
        return trim((string) $c['chassis']); }, $rows), 'strlen')));
    if ($codes) {
        // One pass: the window gives each code's count and average beside its
        // ten newest prices. k_chassis_day makes the three-month range direct.
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $st = $conn->prepare(
            "SELECT chassis, final_price, rn, cnt, av FROM (
                SELECT chassis, final_price,
                       ROW_NUMBER() OVER (PARTITION BY chassis ORDER BY sold_on DESC, stat_id) rn,
                       COUNT(*) OVER (PARTITION BY chassis) cnt,
                       AVG(final_price) OVER (PARTITION BY chassis) av
                  FROM car_stats
                 WHERE chassis IN ($ph) AND final_price > 0 AND " . STAT_SOLD_SQL . "
                   AND " . stWindowSql() . ") x
             WHERE rn <= 10");
        if ($st) {
            $st->bind_param(str_repeat('s', count($codes)), ...$codes);
            $st->execute();
            $res = $st->get_result();
            while ($x = $res->fetch_assoc()) {
                $k = $x['chassis'];
                if (!isset($avgBy[$k])) {
                    $avgBy[$k] = array('avg' => (float) $x['av'], 'n' => (int) $x['cnt'], 'hist' => array());
                }
                $avgBy[$k]['hist'][(int) $x['rn']] = (int) $x['final_price'];
            }
            $st->close();
        }
        foreach ($avgBy as $k => $v) {            // oldest on the left, as the source draws it
            krsort($v['hist']);
            $avgBy[$k]['hist'] = array_values($v['hist']);
        }
    }
} else {
    $pages = 1;
}

function stQs($over = array()) {
    $a = array_merge(array_intersect_key($_GET, array_flip(STAT_KEYS)), $over);
    $a = array_filter($a, function ($v) { return is_array($v) ? (bool) $v : strlen((string) $v) > 0; });
    // houses[]=A&houses[]=B, as the form itself sends them - not houses[0]=A
    return 'statistics.php' . ($a ? '?' . preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($a)) : '');
}

/** A column heading that sorts: first click the column's natural way, then flips. */
function stSortLink($key, $label) {
    global $sort, $dir, $SORTS;
    $on   = ($sort === $key);
    $next = $on ? ($dir === 'ASC' ? 'desc' : 'asc') : strtolower($SORTS[$key][1]);
    $mark = $on ? ($dir === 'ASC' ? ' &#9650;' : ' &#9660;') : '';
    return '<a class="st-sort' . ($on ? ' is-on' : '') . '" href="'
         . sanitize(stQs(array('sort' => $key, 'dir' => $next, 'page' => 1))) . '">' . $label . $mark . '</a>';
}

// statPhotos(), stOutcome() and stSold() live in includes/statistics-lib.php,
// which statistics-detail.php shares.

/** A sale's own page. It carries the list's filters and page, so "Back" on the
    detail returns the reader to exactly the rows they came from. */
function stDetailHref($c) {
    $back = substr(stQs(), strlen('statistics.php'));
    return 'statistics-detail.php?id=' . rawurlencode($c['stat_id'])
         . ($back !== '' ? '&back=' . rawurlencode(ltrim($back, '?')) : '');
}

$page_title = 'Statistics — ' . SITE_NAME;
require_once 'includes/header.php';
?>
<?php // The Statistics pages' own styles - a separate file, so nothing the auction
      // loads is touched by them. ?>
<link rel="stylesheet" href="<?php echo assetV('assets/css/stats.css'); ?>">

<div class="container">
  <nav class="crumbs">
    <a href="welcome.php">Auctions</a> &nbsp;/&nbsp; <b>Statistics</b>
  </nav>

  <div class="lot-actionbar">
    <div class="lab-title">
      <h1>Statistics</h1>
      <span class="lab-meta">
        What these cars actually went for at the halls — start price, final
        price, and whether the lot sold at all.
      </span>
    </div>
    <div class="lab-actions">
      <?php // Staff only: is the aaajapan ID these figures come from still
            // working? Green or red - the owner's request of 24 September 2026. ?>
      <?php echo sourceSignal('statistics'); ?>
      <a href="welcome.php" class="btn btn-secondary">Go to auctions</a>
    </div>
  </div>

  <form method="GET" class="admin-toolbar pb-stats-form">
    <select name="maker" class="select">
      <option value="">All makers</option>
      <?php foreach ($makers as $m): ?>
        <option value="<?php echo sanitize($m['maker']); ?>"
          <?php echo $maker === $m['maker'] ? 'selected' : ''; ?>>
          <?php echo sanitize($m['maker']) . ' (' . number_format($m['n']) . ')'; ?>
        </option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="model" class="input" placeholder="model name (e.g. Prius)"
           value="<?php echo sanitize($model); ?>" style="min-width:150px">
    <input type="text" name="chassis" class="input" placeholder="chassis no. / model code"
           value="<?php echo sanitize($chas); ?>" style="min-width:130px">
    <?php // The halls moved into the advanced search, grouped by weekday and
          // several at once, as the source lays them out. Its place here is the
          // source's lot-number box - one lot, or several with commas. ?>
    <input type="text" name="lot" class="input" placeholder="lot no. (1234, 5678)"
           value="<?php echo sanitize($lot); ?>" style="min-width:130px">
    <input type="text" name="y1" class="input" placeholder="year from"
           value="<?php echo sanitize($y1); ?>" style="width:92px">
    <input type="text" name="y2" class="input" placeholder="to"
           value="<?php echo sanitize($y2); ?>" style="width:70px">
    <?php /* The auction date, not the car's year. Kept next to sold/unsold
             because both answer "which of them", while the year boxes above
             answer "which car". */ ?>
    <select name="months" class="select">
      <option value="">All dates</option>
      <option value="1" <?php echo $months === '1' ? 'selected' : ''; ?>>Last 1 month</option>
      <option value="2" <?php echo $months === '2' ? 'selected' : ''; ?>>Last 2 months</option>
      <option value="3" <?php echo $months === '3' ? 'selected' : ''; ?>>Last 3 months</option>
    </select>
    <select name="result" class="select">
      <option value="">Sold and unsold</option>
      <option value="sold"   <?php echo $result === 'sold'   ? 'selected' : ''; ?>>Sold only</option>
      <option value="unsold" <?php echo $result === 'unsold' ? 'selected' : ''; ?>>Not sold only</option>
    </select>
    <button type="submit" class="btn btn-dark">Search</button>
    <?php if ($maker || $model || $chas || $months || $y1 || $y2 || $result || $lot !== '' || $advOn): ?>
      <a href="statistics.php" class="btn btn-ghost">Reset</a>
    <?php endif; ?>
    <?php if (isAdmin()): ?>
      <a href="<?php echo sanitize(stQs(array('csv' => 1, 'page' => ''))); ?>" class="btn btn-ghost"
         title="This list as a spreadsheet - up to 10,000 rows. The desk only.">Download CSV</a>
    <?php endif; ?>
    <?php // A new search keeps the chosen order and page size. ?>
    <?php if ($sort !== 'date' || $dir !== 'DESC'): ?>
      <input type="hidden" name="sort" value="<?php echo sanitize($sort); ?>">
      <input type="hidden" name="dir" value="<?php echo strtolower($dir); ?>">
    <?php endif; ?>
    <?php if ($per_page !== 30): ?>
      <input type="hidden" name="per" value="<?php echo (int) $per_page; ?>">
    <?php endif; ?>

    <?php /* THE SOURCE'S ADVANCED SEARCH (the client, 24 September 2026). The
             halls under the weekday each one sells on, with its count over the
             last three months; the four from-to ranges; transmission, equipment
             and colour; and the condition grades as the source's own row of
             boxes. A native <details>, so it opens without any script, and it
             opens by itself whenever one of its filters is in use. */ ?>
    <details class="st-adv"<?php echo $advOn ? ' open' : ''; ?>>
      <summary>Advanced search<?php if ($advOn): ?> <span class="st-adv-on">in use</span><?php endif; ?></summary>
      <div class="st-adv-body">
        <?php if (!empty($housesByDay)): ?>
          <div class="st-adv-houses">
            <?php foreach ($housesByDay as $grp): ?>
              <div class="st-day">
                <b><?php echo sanitize($grp['day']); ?></b>
                <?php foreach ($grp['houses'] as $h): ?>
                  <label class="st-chk">
                    <input type="checkbox" name="houses[]" value="<?php echo sanitize($h[0]); ?>"<?php
                      echo in_array($h[0], $houses, true) ? ' checked' : ''; ?>>
                    <?php echo sanitize($h[0]); ?> <i>(<?php echo number_format($h[1]); ?>)</i>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="st-adv-grid">
          <div class="st-ranges">
            <?php foreach (array('km' => 'Mileage (km)', 'cc' => 'Engine (cc)', 'sp' => 'Start price (&yen;)',
                                 'fp' => 'Final price (&yen;)') as $rk => $rl): ?>
              <div class="st-range">
                <span><?php echo $rl; ?></span>
                <input type="text" inputmode="numeric" name="<?php echo $rk; ?>1" class="input" placeholder="from"
                       value="<?php echo sanitize($ranges[$rk][1]); ?>">
                <input type="text" inputmode="numeric" name="<?php echo $rk; ?>2" class="input" placeholder="to"
                       value="<?php echo sanitize($ranges[$rk][2]); ?>">
              </div>
            <?php endforeach; ?>
            <?php foreach (array('trans' => array('Transmission', $trans), 'equip' => array('Equipment', $equip),
                                 'colour' => array('Colour', $colour)) as $fk => $fl): ?>
              <div class="st-range">
                <span><?php echo $fl[0]; ?></span>
                <select name="<?php echo $fk; ?>" class="select">
                  <option value="">Any</option>
                  <?php foreach (($facets[$fk] ?? array()) as $fv): ?>
                    <option value="<?php echo sanitize($fv[0]); ?>"<?php echo $fl[1] === $fv[0] ? ' selected' : ''; ?>>
                      <?php echo sanitize($fv[0]) . ' (' . number_format($fv[1]) . ')'; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="st-grades">
            <b>Condition</b>
            <div class="st-grade-set">
              <?php foreach (STAT_GRADES as $g): ?>
                <label class="st-chk st-g">
                  <input type="checkbox" name="grades[]" value="<?php echo sanitize($g); ?>"<?php
                    echo in_array($g, $grades, true) ? ' checked' : ''; ?>> <?php echo sanitize($g); ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="st-adv-do">
          <button type="submit" class="btn btn-dark">Search</button>
          <a href="statistics.php" class="btn btn-ghost">Clear everything</a>
        </div>
      </div>
    </details>
  </form>

  <?php // The count stays visible even at zero so the live poll has something to
        // fill while the history is still loading. ?>
  <?php if ($haveTable): ?>
    <div class="pb-stats-sum" id="stSum"<?php echo $total > 0 ? '' : ' style="opacity:.7"'; ?>>
      <?php /* Unfiltered, this figure IS the one on the dashboards' Statistics
               tiles, so it is marked data-live and livecount.js drives it - one
               script, one fetch of api/counts.php, one number. It used to run on
               this page's own timer against its own query, which is how the page
               and the tiles came to show figures minutes apart. Filtered, it
               counts something no tile shows, so the attribute comes off and the
               page's own poll below owns it again. */
            $stFiltered = ($maker || $model || $chas || $months || $y1 || $y2 || $result || $lot !== '' || $advOn); ?>
      <span><strong id="stCount"<?php echo $stFiltered ? '' : ' data-live="statistics"'; ?>><?php
        echo number_format($total); ?></strong> in Statistics</span>
      <?php // The badge the auction list carries, for the same reason: the page
            // should say out loud that it keeps itself current. See live.js. ?>
      <span id="stLive" class="live-badge" title="Statistics update automatically">Live</span>
      <span id="stSoldWrap"<?php echo $soldN > 0 ? '' : ' hidden'; ?>><strong id="stSold"><?php echo number_format($soldN); ?></strong> of them sold</span>
      <span id="stAvgWrap"<?php echo ($soldN > 0 && $avg !== null) ? '' : ' hidden'; ?>>average sale price
        <strong id="stAvg">&yen;<?php echo $avg !== null ? number_format(round($avg)) : '—'; ?></strong></span>
      <span class="st-per">Show
        <?php foreach (array(30, 50, 100) as $pp): ?>
          <?php if ($pp === $per_page): ?><b><?php echo $pp; ?></b><?php else: ?><a href="<?php
            echo sanitize(stQs(array('per' => $pp === 30 ? '' : $pp, 'page' => ''))); ?>"><?php echo $pp; ?></a><?php endif; ?>
        <?php endforeach; ?>
      </span>
    </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="empty">
      <h2><?php echo $haveTable && ($maker || $model || $chas || $lot !== '' || $advOn || $months || $y1 || $y2 || $result)
            ? 'Nothing matched that' : 'Statistics are still being brought across'; ?></h2>
      <p>
        <?php if ($haveTable && ($maker || $model || $chas || $lot !== '' || $advOn || $months || $y1 || $y2 || $result)): ?>
          Try a wider search — a maker on its own, or a shorter chassis code.
        <?php else: ?>
          The Statistics are being loaded. It is more than a million sales, so it
          arrives over some hours rather than at once.
        <?php endif; ?>
      </p>
      <a href="welcome.php" class="btn btn-primary">Browse the auctions</a>
    </div>
  <?php else: ?>
    <div class="lot-table-wrap">
      <table class="lot-table lot-table-stats">
        <thead>
          <tr>
            <th class="c-shot">Photo</th>
            <th><?php echo stSortLink('date', 'Sold on'); ?><span class="sub"><?php echo stSortLink('hall', 'Hall'); ?></span></th>
            <th><?php echo stSortLink('lot', 'Lot No.'); ?></th>
            <th><?php echo stSortLink('model', 'Model Name'); ?><span class="sub"><?php echo stSortLink('year', 'Year'); ?></span></th>
            <th><?php echo stSortLink('chassis', 'Chassis No.'); ?><span class="sub">Model Grade</span></th>
            <th><?php echo stSortLink('cc', 'Engine (CC)'); ?><span class="sub">Equipment</span></th>
            <?php // No `c-km` on the heading: that class paints the mileage
                  // FIGURES grey - a deliberate softening in the body - and on a
                  // heading it made "Mileage (KM)" the one grey word in a row of
                  // white ones. The auction list puts the class on the cell only,
                  // and this now matches it. The width comes from nth-child. ?>
            <th><?php echo stSortLink('km', 'Mileage (KM)'); ?></th>
            <th>Trans.<span class="sub">Color</span></th>
            <th class="c-grade"><?php echo stSortLink('grade', 'Cond.<br>Grade'); ?></th>
            <th class="c-price"><?php echo stSortLink('start', 'Start'); ?></th>
            <th class="c-price"><?php echo stSortLink('final', 'Final'); ?></th>
            <th class="c-result">Result</th>
            <?php // Last, so the widths style.css gives columns 1-12 by position stay put. ?>
            <th class="c-avg">Average<span class="sub">same code, 3 months</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $c): $out = stOutcome($c); $sold = $out['sold']; $pics = statPhotos($c); $href = stDetailHref($c); ?>
            <?php // The whole row opens the sale's own page (the owner, 24 September
                  // 2026: "the detail page isn't showing up"); the photographs keep
                  // their own click, which enlarges them. See stats.css / below. ?>
            <tr class="st-row" data-href="<?php echo sanitize($href); ?>">
              <td class="c-shot">
                <?php if ($pics): ?>
                  <?php // The strip is what groups them: the lightbox opens the
                        // set it belongs to, and `.lot-shots` means "this car's
                        // pictures" - see hostFor() in site.js. data-zoom carries
                        // the full-size address; the browser fetches both sizes
                        // straight from the source's picture host. ?>
                  <div class="lot-shots">
                    <?php foreach ($pics as $p): ?>
                      <img src="<?php echo sanitize($p['thumb']); ?>"
                           data-zoom="<?php echo sanitize($p['full']); ?>"
                           alt="<?php echo sanitize(trim($c['maker'] . ' ' . $c['model'])); ?>"
                           loading="lazy" referrerpolicy="no-referrer" class="lot-thumb"
                           onerror="this.setAttribute('data-broken','1');this.style.display='none';">
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>&mdash;<?php endif; ?>
              </td>
              <td>
                <?php echo $c['sold_on'] ? date('d.m.Y', strtotime($c['sold_on'])) : '—'; ?>
                <span class="sub"><?php echo sanitize($c['auction']); ?></span>
              </td>
              <td><?php echo sanitize($c['lot_no']); ?></td>
              <td>
                <a class="st-open" href="<?php echo sanitize($href); ?>"><b><?php echo sanitize(trim($c['maker'] . ' ' . $c['model'])); ?></b></a>
                <span class="sub"><?php echo $c['year'] ? (int) $c['year'] : '—'; ?></span>
              </td>
              <td>
                <?php echo sanitize($c['chassis'] ?: '—'); ?>
                <span class="sub"><?php echo sanitize($c['model_grade'] ?: ''); ?></span>
              </td>
              <?php // The source carries the engine's power beside its size, and
                    // the drive beside the gearbox. Both were being thrown away
                    // until 15 September 2026, when one of them - the power -
                    // turned out to be what had been filling the mileage column. ?>
              <td>
                <?php echo $c['engine_cc'] ? number_format($c['engine_cc']) : '—'; ?>
                <?php if (!empty($c['engine_hp'])): ?>
                  <span class="sub"><?php echo (int) $c['engine_hp']; ?> hp</span>
                <?php endif; ?>
                <?php if (!empty($c[STAT_COL_EQUIP])): ?>
                  <span class="sub st-equip"><?php echo sanitize($c[STAT_COL_EQUIP]); ?></span>
                <?php endif; ?>
              </td>
              <td class="c-km">
                <?php echo $c['mileage'] ? number_format($c['mileage']) : '—'; ?>
              </td>
              <td>
                <?php // the gearbox - stored in `grade`, see STAT_COL_TRANS ?>
                <?php echo sanitize($c[STAT_COL_TRANS] ?: '—'); ?><?php
                  if (!empty($c['drive'])): ?> <?php echo sanitize($c['drive']); ?><?php endif; ?>
                <span class="sub"><?php echo sanitize($c['colour'] ?: ''); ?></span>
              </td>
              <td class="c-grade"><?php echo sanitize($c['rating'] ?: '—'); ?></td>
              <td class="c-price">
                <?php echo $c['start_price'] ? '&yen;' . number_format($c['start_price']) : '—'; ?>
              </td>
              <td class="c-price">
                <?php if ($c['final_price']): ?>
                  <b class="<?php echo $sold ? 'st-sold' : 'st-unsold'; ?>">&yen;<?php
                    echo number_format($c['final_price']); ?></b>
                  <?php if (!$sold): ?><span class="sub"><?php echo $out['key'] === 'unsold' ? 'highest bid' : sanitize(strtolower($out['label'])); ?></span><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="c-result">
                <span class="pill <?php echo $sold ? 'pill-sold' : ($out['key'] === 'unsold' ? 'pill-gone' : 'pill-other'); ?>">
                  <?php echo sanitize($out['short']); ?>
                </span>
              </td>
              <td class="c-avg">
                <?php $av = $avgBy[trim((string) $c['chassis'])] ?? null; ?>
                <?php if ($av): $mx = max($av['hist'] ?: array(1)); ?>
                  <b>&yen;<?php echo number_format(round($av['avg'])); ?></b>
                  <span class="sub"><?php echo number_format($av['n']); ?> sold</span>
                  <?php if (count($av['hist']) > 1): ?>
                    <span class="st-spark" title="The last <?php echo count($av['hist']); ?> sale prices of <?php
                      echo sanitize($c['chassis']); ?>, oldest first"><?php foreach ($av['hist'] as $hp): ?><i style="height:<?php
                        echo max(10, (int) round($hp / $mx * 100)); ?>%"></i><?php endforeach; ?></span>
                  <?php endif; ?>
                <?php else: ?>&mdash;<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <?php $from = max(1, $page - 2); $to = min($pages, $page + 2); ?>
      <nav class="pagination">
        <?php if ($page > 1): ?>
          <a href="<?php echo sanitize(stQs(array('page' => $page - 1))); ?>" class="btn btn-secondary">Previous</a>
        <?php endif; ?>
        <?php for ($i = $from; $i <= $to; $i++): ?>
          <a href="<?php echo sanitize(stQs(array('page' => $i))); ?>"
             class="btn <?php echo $i === $page ? 'btn-active' : 'btn-secondary'; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $pages): ?>
          <a href="<?php echo sanitize(stQs(array('page' => $page + 1))); ?>" class="btn btn-secondary">Next</a>
        <?php endif; ?>
        <span class="page-info">Page <?php echo number_format($page); ?> of <?php echo number_format($pages); ?></span>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php // The number updates on its own, like the auction list. Polls the same page
      // in count-only mode with the current filters, every 20 s, and eases the
      // figures in when they change. Reloads once if new rows would change the
      // table itself, only while the reader is at the top. ?>
<script>
(function () {
  var elCount = document.getElementById('stCount');
  if (!elCount) { return; }
  var elSum = document.getElementById('stSum'),
      elSold = document.getElementById('stSold'), elSoldW = document.getElementById('stSoldWrap'),
      elAvg = document.getElementById('stAvg'), elAvgW = document.getElementById('stAvgWrap');
  var elLive = document.getElementById('stLive');
  var params = new URLSearchParams(window.location.search);
  params.set('count', '1');
  var url = 'statistics.php?' + params.toString();
  var last = parseInt((elCount.textContent || '0').replace(/[^0-9]/g, ''), 10) || 0;
  var fmt = function (n) { return Number(n).toLocaleString('en-US'); };
  function flash(el) { if (!el) { return; } el.classList.remove('just-changed'); void el.offsetWidth; el.classList.add('just-changed'); }
  function pulse() {
    if (!elLive) { return; }
    elLive.classList.add('live-pulse');
    setTimeout(function () { elLive.classList.remove('live-pulse'); }, 1200);
  }

  /* The rows themselves, the way the auction list does it (live.js): only while
     the reader is at the top, and never on a page they have paged into - pulling
     the table out from under somebody mid-read is worse than a stale table. */
  var TOP_ZONE = 150;
  var refreshing = false;
  var tableAt = 0;
  var TABLE_EVERY = 120000;   // at most once every two minutes
  var TABLE_AFTER = 3000;     // and never at the same moment as the count
  function refreshTable() {
    var onFirstPage = !(new URLSearchParams(window.location.search).get('page') > 1);
    if (refreshing || !onFirstPage || window.scrollY > TOP_ZONE) { return; }
    /* This fetches the whole page - a megabyte and ninety pictures - to lift the
       table out of it. Doing that on every count tick put it in the way of the
       count's own request, which then reached the server a second or two late and
       came back a few dozen rows higher than the same figure on the dashboards:
       the very mismatch this was all meant to remove. The rows themselves barely
       move (the backfill adds OLD sales, which sort below the newest), so twice a
       minute is generous, and it waits a moment so the two never compete. */
    if (Date.now() - tableAt < TABLE_EVERY) { return; }
    tableAt = Date.now();
    setTimeout(doRefreshTable, TABLE_AFTER);
  }
  function doRefreshTable() {
    if (refreshing || window.scrollY > TOP_ZONE) { return; }
    refreshing = true;
    fetch(window.location.href, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) {
        if (!html) { return; }
        var fresh = new DOMParser().parseFromString(html, 'text/html');
        var a = fresh.querySelector('.lot-table-wrap'), b = document.querySelector('.lot-table-wrap');
        if (a && b) { b.replaceWith(a); }
      })
      .catch(function () {})
      .then(function () { refreshing = false; });
  }
  function tick() {
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || typeof j.total !== 'number') { return; }
        if (j.total !== last) {
          // Unfiltered, livecount.js owns this number so that it and the
          // dashboard tiles always read the same value; touching it here as well
          // would put two scripts on one figure.
          if (!elCount.hasAttribute('data-live')) {
            elCount.textContent = fmt(j.total);
            flash(elCount);
          }
          pulse();
          refreshTable();
          if (elSum) { elSum.style.opacity = j.total > 0 ? '1' : '.7'; }
          if (elSold && elSoldW) {
            if (j.sold > 0) { elSold.textContent = fmt(j.sold); elSoldW.hidden = false; } else { elSoldW.hidden = true; }
          }
          if (elAvg && elAvgW) {
            if (j.sold > 0 && j.avg) { elAvg.innerHTML = '&yen;' + fmt(j.avg); elAvgW.hidden = false; } else { elAvgW.hidden = true; }
          }
          last = j.total;
        }
      })
      .catch(function () {});
  }
  // On the same wall-clock beat as livecount.js - :00, :20, :40 - so this page's
  // "of them sold" and average move with the figure beside them and with the
  // dashboards, instead of on a clock that started whenever the page loaded.
  var EVERY = 20000;
  setTimeout(function () {
    tick();
    setInterval(function () { if (!document.hidden) { tick(); } }, EVERY);
  }, EVERY - (Date.now() % EVERY));
})();
</script>

<?php // A click anywhere on a row opens that sale - except on a photograph, a
      // link or a button, which keep their own job. Delegated from the document
      // because the live refresh replaces the whole table. ?>
<script>
document.addEventListener('click', function (ev) {
  var t = ev.target;
  if (!t.closest || t.closest('a, button, img, input, select, .lot-shots')) { return; }
  var row = t.closest('tr.st-row[data-href]');
  if (!row) { return; }
  if (ev.ctrlKey || ev.metaKey) { window.open(row.getAttribute('data-href'), '_blank'); return; }
  window.location.href = row.getAttribute('data-href');
});
</script>

<?php require_once 'includes/footer.php'; ?>
