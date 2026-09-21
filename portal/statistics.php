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
$per_page = 30;

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

$where  = array('1=1');
$params = array();
$types  = '';

if ($maker !== '') { $where[] = 'maker = ?';   $params[] = $maker; $types .= 's'; }
if ($hall  !== '') { $where[] = 'auction = ?'; $params[] = $hall;  $types .= 's'; }
if ($model !== '') {
    $where[]  = 'model LIKE ?';
    $params[] = '%' . $model . '%';
    $types   .= 's';
}
if ($chas !== '') {
    // A chassis is given either whole (ZN8-0012345) or as the code alone (ZN8),
    // and a buyer types whichever they are holding. Both have to find the car.
    $where[]  = '(chassis LIKE ? OR ? LIKE CONCAT(chassis, \'%\'))';
    $params[] = '%' . $chas . '%';
    $params[] = $chas;
    $types   .= 'ss';
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
    $where[] = "(result IS NULL OR result NOT LIKE '%not%')";
} elseif ($result === 'unsold') {
    $where[] = "result LIKE '%not%'";
}
$where_sql = implode(' AND ', $where);

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
        $st = $conn->prepare("SELECT COUNT(*) n FROM car_stats WHERE $where_sql");
        if ($st) {
            if ($params) { $st->bind_param($types, ...$params); }
            $st->execute();
            $t = (int) $st->get_result()->fetch_assoc()['n'];
            $st->close();
        }
        $st = $conn->prepare(
            "SELECT COUNT(*) n, AVG(final_price) a FROM car_stats
              WHERE $where_sql AND final_price > 0
                AND (result IS NULL OR result NOT LIKE '%not%')");
        if ($st) {
            if ($params) { $st->bind_param($types, ...$params); }
            $st->execute();
            $g = $st->get_result()->fetch_assoc();
            $sn = (int) $g['n'];
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
    $st = $conn->prepare("SELECT COUNT(*) n FROM car_stats WHERE $where_sql");
    if ($st) {
        if ($params) { $st->bind_param($types, ...$params); }
        $st->execute();
        $total = (int) $st->get_result()->fetch_assoc()['n'];
        $st->close();
    }

    /* The average is of what actually sold, and of nothing else. Folding the
       unsold in would drag it toward the last bid nobody accepted, which is the
       one number a buyer must not mistake for a price. */
    $st = $conn->prepare(
        "SELECT COUNT(*) n, AVG(final_price) a FROM car_stats
          WHERE $where_sql AND final_price > 0
            AND (result IS NULL OR result NOT LIKE '%not%')");
    if ($st) {
        if ($params) { $st->bind_param($types, ...$params); }
        $st->execute();
        $g = $st->get_result()->fetch_assoc();
        $soldN = (int) $g['n'];
        $avg   = $g['a'] !== null ? (float) $g['a'] : null;
        $st->close();
    }

    $pages  = max(1, (int) ceil($total / $per_page));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $per_page;

    $st = $conn->prepare(
        "SELECT stat_id, maker, model, lot_no, auction, sold_on, sold_time, year,
                engine_cc, mileage, chassis, grade, model_grade, transmission,
                rating, engine_hp, drive, colour, start_price, final_price, result, photos
           FROM car_stats
          WHERE $where_sql
          ORDER BY sold_on DESC, auction ASC, lot_no ASC
          LIMIT ? OFFSET ?");
    if ($st) {
        $p2 = $params; $p2[] = $per_page; $p2[] = $offset;
        $st->bind_param($types . 'ii', ...$p2);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }

    if ($res = @$conn->query(
        "SELECT maker, COUNT(*) n FROM car_stats WHERE maker <> ''
          GROUP BY maker ORDER BY maker ASC")) {
        while ($w = $res->fetch_assoc()) { $makers[] = $w; }
    }
    if ($res = @$conn->query(
        "SELECT auction, COUNT(*) n FROM car_stats WHERE auction <> ''
          GROUP BY auction ORDER BY auction ASC")) {
        while ($w = $res->fetch_assoc()) { $halls[] = $w; }
    }
} else {
    $pages = 1;
}

function stQs($over = array()) {
    $keys = array('maker', 'model', 'chassis', 'auction', 'months', 'y1', 'y2', 'result', 'page');
    $a = array_merge(array_intersect_key($_GET, array_flip($keys)), $over);
    $a = array_filter($a, 'strlen');
    return 'statistics.php' . ($a ? '?' . http_build_query($a) : '');
}

/**
 * The photographs of a past sale: array('thumb' => url, 'full' => url) or null.
 *
 * The source stores three picture tokens per lot and serves them from its own
 * image host. It refuses DATACENTRE addresses - this server, GitHub, any VPS -
 * which is why the photographs cannot be fetched or copied here; but it serves
 * an ordinary home or mobile connection with **no login, no referer and no
 * cookie at all** (measured 15 September 2026, including tokens stored the day
 * before and sales from July). The customer's browser is on exactly such a
 * connection, so the page hands it the address and it collects the picture
 * itself - the same arrangement the auction list already has with its own
 * picture host.
 *
 * Only `&h=50` is honoured for a smaller copy (66x50); every other height comes
 * back as 32 bytes of nothing. So: the thumbnail is h=50 and the full picture is
 * the bare address, 640x480.
 *
 * ALL of a lot's pictures are returned, and the cell puts them in a `.lot-shots`
 * strip - the same wrapper the auction list uses - because that is what tells the
 * lightbox which pictures belong together. Showing only the first, with the strip
 * missing, made the set fall back to the whole `tbody`: clicking one car opened
 * "1 / 30" and the arrows walked through every other row on the page.
 */
function statPhotos($row) {
    $t = $row['photos'] ?? '';
    if (is_string($t)) {
        $t = json_decode($t, true) ?: array();
    }
    /* THE SAME PICTURE IS NOT TWO PICTURES.
       For some halls the source gives the same token twice - on 17 September
       2026 ten of the thirty lots on the first page did, all from Aux Mobility.
       Three tiles were drawn, two of them identical, and the viewer counted
       "1 / 3" and then showed the same photograph again when the reader stepped
       forward. The owner read that as the arrows being broken; the arrows were
       fine, there was simply nothing new to show.
       Dropped here as well as at ingest, because this puts the nine hundred
       thousand rows already stored right immediately, without waiting for each
       one to be read again. */
    $out  = array();
    $seen = array();
    foreach ((is_array($t) ? $t : array()) as $tok) {
        $tok = trim((string) $tok);
        if ($tok === '' || !preg_match('/^[A-Za-z0-9_-]{8,}$/', $tok)) {
            continue;
        }
        if (isset($seen[$tok])) {
            continue;
        }
        $seen[$tok] = true;
        $base = 'https://8.ajes.com/imgs/' . rawurlencode($tok);
        $out[] = array('thumb' => $base . '&h=50', 'full' => $base);
    }
    return $out;
}

/** A result the source calls "not sold" is a bid that was refused. */
function stSold($row) {
    $r = strtolower(trim((string) ($row['result'] ?? '')));
    return $r === '' || strpos($r, 'not') === false;
}

$page_title = 'Statistics — ' . SITE_NAME;
require_once 'includes/header.php';
?>

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
    <input type="text" name="model" class="input" placeholder="model"
           value="<?php echo sanitize($model); ?>" style="min-width:150px">
    <input type="text" name="chassis" class="input" placeholder="chassis"
           value="<?php echo sanitize($chas); ?>" style="min-width:130px">
    <select name="auction" class="select">
      <option value="">All halls</option>
      <?php foreach ($halls as $hh): ?>
        <option value="<?php echo sanitize($hh['auction']); ?>"
          <?php echo $hall === $hh['auction'] ? 'selected' : ''; ?>>
          <?php echo sanitize($hh['auction']); ?>
        </option>
      <?php endforeach; ?>
    </select>
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
    <?php if ($maker || $model || $chas || $hall || $months || $y1 || $y2 || $result): ?>
      <a href="statistics.php" class="btn btn-ghost">Reset</a>
    <?php endif; ?>
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
            $stFiltered = ($maker || $model || $chas || $hall || $months || $y1 || $y2 || $result); ?>
      <span><strong id="stCount"<?php echo $stFiltered ? '' : ' data-live="statistics"'; ?>><?php
        echo number_format($total); ?></strong> in Statistics</span>
      <?php // The badge the auction list carries, for the same reason: the page
            // should say out loud that it keeps itself current. See live.js. ?>
      <span id="stLive" class="live-badge" title="Statistics update automatically">Live</span>
      <span id="stSoldWrap"<?php echo $soldN > 0 ? '' : ' hidden'; ?>><strong id="stSold"><?php echo number_format($soldN); ?></strong> of them sold</span>
      <span id="stAvgWrap"<?php echo ($soldN > 0 && $avg !== null) ? '' : ' hidden'; ?>>average sale price
        <strong id="stAvg">&yen;<?php echo $avg !== null ? number_format(round($avg)) : '—'; ?></strong></span>
    </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="empty">
      <h2><?php echo $haveTable && ($maker || $model || $chas)
            ? 'Nothing matched that' : 'Statistics are still being brought across'; ?></h2>
      <p>
        <?php if ($haveTable && ($maker || $model || $chas)): ?>
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
            <th>Sold on<span class="sub">Hall</span></th>
            <th>Lot No.</th>
            <th>Model Name<span class="sub">Year</span></th>
            <th>Chassis No.<span class="sub">Model Grade</span></th>
            <th>Engine (CC)</th>
            <?php // No `c-km` on the heading: that class paints the mileage
                  // FIGURES grey - a deliberate softening in the body - and on a
                  // heading it made "Mileage (KM)" the one grey word in a row of
                  // white ones. The auction list puts the class on the cell only,
                  // and this now matches it. The width comes from nth-child. ?>
            <th>Mileage (KM)</th>
            <th>Trans.<span class="sub">Color</span></th>
            <th class="c-grade">Cond.<br>Grade</th>
            <th class="c-price">Start</th>
            <th class="c-price">Final</th>
            <th class="c-result">Result</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $c): $sold = stSold($c); $pics = statPhotos($c); ?>
            <tr>
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
                <b><?php echo sanitize(trim($c['maker'] . ' ' . $c['model'])); ?></b>
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
              </td>
              <td class="c-km">
                <?php echo $c['mileage'] ? number_format($c['mileage']) : '—'; ?>
              </td>
              <td>
                <?php echo sanitize($c['transmission'] ?: '—'); ?><?php
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
                  <?php if (!$sold): ?><span class="sub">highest bid</span><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="c-result">
                <span class="<?php echo $sold ? 'pill pill-sold' : 'pill pill-gone'; ?>">
                  <?php echo $sold ? 'Sold' : 'Not sold'; ?>
                </span>
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

<?php require_once 'includes/footer.php'; ?>
