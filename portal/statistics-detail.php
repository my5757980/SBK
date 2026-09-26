<?php
/**
 * One past sale, on its own page - the detail of the Statistics section.
 *
 * The owner, 24 September 2026: "in the auction statistics section the detail
 * page isn't showing up - add the detail page", and, again, "don't touch the
 * auction module at all". So this page reads ONLY the statistics table
 * (car_stats) through the Statistics library; nothing of the auction is changed
 * for it. It reuses, unchanged, one of the auction page's tools that site.js
 * already runs on any page carrying its markup: the month-of-production lookup.
 *
 * THE SOURCE'S OWN LAYOUT (the owner, 25 September 2026: "make the detail page
 * exactly like the statistics site's - its data, its UI/UX"). Read off one of
 * its sale pages the same day (sbk-tools/aaadetail.py, _aaa/6-detail.png):
 *   - Home / Close / Prev / Next, and Tokyo's date and time;
 *   - the sale as ONE row under the list's own headings - lot number (copy
 *     info), auction date and hall, model and year, chassis, engine and
 *     equipment, mileage and condition, start and sold-for, average price;
 *   - of the source's four buttons, only "details of vehicle" was kept, and
 *     then shown on the page itself instead of behind a click (the owner,
 *     26 September 2026);
 *   - the two photographs large on the left; on the right the month of
 *     production, the auction sheet large, the sheet's codes spelled out and
 *     a description.
 * On a phone, as there: the row in two parts, the sheet first, photos last.
 *
 *   statistics-detail.php?id=<stat_id>&back=<the list's query>
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/statistics-lib.php';

requireLogin();

$id   = strtolower(trim((string) ($_GET['id'] ?? '')));
$conn = getDatabaseConnection();
$sale = null;
if ($conn && preg_match('/^[a-f0-9]{32}$/', $id)) {
    // A sale the source has already dropped is not shown either - the same window as the list.
    $st = $conn->prepare("SELECT * FROM car_stats WHERE stat_id = ? AND " . stWindowSql());
    if ($st) {
        $st->bind_param('s', $id);
        $st->execute();
        $sale = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }
}

/* Back to the very rows the reader came from: the list's filters and page ride
   along in `back`, and only the list's own keys are accepted from it. The same
   `back` lets Prev / Next step through that list (statistics.php?ids=1). */
$backIn = array();
parse_str((string) ($_GET['back'] ?? ''), $backIn);
$backQ = array();
foreach (STAT_KEYS as $k) {
    if (!isset($backIn[$k])) {
        continue;
    }
    if (is_array($backIn[$k])) {                    // houses[] and grades[]
        $list = stList($backIn[$k]);
        if ($list) { $backQ[$k] = $list; }
    } elseif (is_string($backIn[$k]) && $backIn[$k] !== '') {
        $backQ[$k] = $backIn[$k];
    }
}
$backQs  = preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($backQ));   // houses[]=, as the form sends
$backUrl = 'statistics.php' . ($backQ ? '?' . $backQs : '');

/* What the same model code has been selling for inside the source's window -
   the source's "average price" column, and the description's last line. */
$summary = null;
$code    = $sale ? trim((string) $sale['chassis']) : '';
if ($sale && $conn && $code !== '') {
    $st = $conn->prepare(
        "SELECT COUNT(*) n, AVG(final_price) a, MIN(final_price) lo, MAX(final_price) hi
           FROM car_stats
          WHERE chassis = ? AND final_price > 0 AND " . STAT_SOLD_SQL . "
            AND " . stWindowSql());
    if ($st) {
        $st->bind_param('s', $code);
        $st->execute();
        $g = $st->get_result()->fetch_assoc();
        $st->close();
        if ($g && (int) $g['n'] > 0) {
            $summary = $g;
        }
    }
}

if (!$sale) {
    http_response_code(404);
}

$title      = $sale ? trim($sale['maker'] . ' ' . $sale['model']) : 'Sale not found';
$page_title = ($sale ? $title . ' №' . $sale['lot_no'] . ' ' . $sale['auction'] : 'Statistics') . ' | ' . SITE_NAME;
require_once 'includes/header.php';
?>
<?php // The Statistics pages' own styles - the auction never loads this file. ?>
<link rel="stylesheet" href="<?php echo assetV('assets/css/stats.css'); ?>">

<main class="container sd-page sd2">

<?php if (!$sale): ?>

  <div class="sd-missing">
    <b>This sale is not in the statistics.</b>
    <span>Statistics cover the last three months of sales, as the source does - an older sale has left them.
      The link may also be incomplete.</span>
    <a href="<?php echo sanitize($backUrl); ?>" class="btn btn-primary">Back to Statistics</a>
  </div>

<?php else:
    $out   = stOutcome($sale);
    $pics  = statPhotos($sale);
    $final = (float) ($sale['final_price'] ?? 0);
    $start = (float) ($sale['start_price'] ?? 0);
    $pillClass = $out['sold'] ? 'pill-sold' : ($out['key'] === 'unsold' ? 'pill-gone' : 'pill-other');
    $day   = $sale['sold_on'] ? date('d.m.Y', strtotime($sale['sold_on'])) : '—';
    $time  = trim((string) ($sale['sold_time'] ?? ''), " []");
    $grade = stText($sale['model_grade'] ?? '');
    $gear  = trim((string) ($sale[STAT_COL_TRANS] ?? ''));
    $equip = trim((string) ($sale[STAT_COL_EQUIP] ?? ''));
    $hp    = stHp($sale);
    // The source's three pictures: two photographs, then the auction sheet.
    $photos = array_slice($pics, 0, 2);
    $sheet  = count($pics) >= 3 ? $pics[2] : null;
    $tokyo  = gmdate('d.m.Y', time() + 9 * 3600) . ' <b>' . gmdate('H:i', time() + 9 * 3600) . '</b>';
    // "copy info", as the source offers: the sale in one line for a message.
    $copy = implode(' | ', array_filter(array(
        $title . ($sale['year'] ? ' ' . (int) $sale['year'] : ''),
        'Lot ' . $sale['lot_no'], $day . ' ' . $sale['auction'],
        trim($code . ' ' . $grade), $sale['mileage'] ? number_format($sale['mileage']) . ' km' : '',
        $sale['rating'] ? 'grade ' . $sale['rating'] : '',
        $start ? 'start ¥' . number_format($start) : '',
        $final ? ($out['sold'] ? 'sold ¥' : 'highest bid ¥') . number_format($final) : $out['label'],
    )));
    // Every detail, for "details of vehicle" - the auction page's spec strip markup.
    $engine = $sale['engine_cc'] ? number_format($sale['engine_cc']) . ' cc' : '—';
    if ($hp) {                            // not the kei cars' four-digit figure - see stHp()
        $engine .= ' · ' . $hp . ' hp';
    }
    $specs = array(
        array('Sold on', $day . ($time !== '' ? ' · ' . $time : ''), 'is-day'),
        array('Auction house', $sale['auction'] ?: '—', ''),
        array('Lot No.', $sale['lot_no'] ?: '—', ''),
        array('Result', $out['label'], $out['sold'] ? 'is-final' : 'is-status'),
        array('Start price', $start ? '¥' . number_format($start) : '—', ''),
        array($out['sold'] ? 'Final price' : 'Highest bid', $final ? '¥' . number_format($final) : '—',
              $out['sold'] ? 'is-final' : 'is-status'),
        array('Year', $sale['year'] ? (int) $sale['year'] : '—', ''),
        array('Chassis (model code)', $code !== '' ? $code : '—', ''),
        array('Model grade', $grade !== '' ? $grade : '—', ''),
        // The gearbox and the equipment are stored under each other's names - see
        // STAT_COL_TRANS / STAT_COL_EQUIP in statistics-lib.php.
        array('Engine', $engine, ''),
        array('Transmission', trim(($gear !== '' ? $gear : '—') . (!empty($sale['drive']) ? ' · ' . $sale['drive'] : '')), ''),
        array('Equipment', $equip !== '' ? $equip : '—', ''),
        array('Mileage', $sale['mileage'] ? number_format($sale['mileage']) . ' km' : '—', ''),
        array('Colour', $sale['colour'] ?: '—', ''),
        array('Condition grade', $sale['rating'] ?: '—', 'is-grade'),
    );
    // Month of production: the same lookup the auction's car page has (site.js).
    $vinMakers = array('DAIHATSU', 'HONDA', 'ISUZU', 'MAZDA', 'MITSUBISHI', 'NISSAN', 'SUBARU', 'SUZUKI', 'TOYOTA');
    $vinMake   = in_array(strtoupper((string) $sale['maker']), $vinMakers, true) ? strtoupper((string) $sale['maker']) : '';
?>

  <?php // ---------------------------------------------- Home / Close / Prev / Next ?>
  <div class="sd2-nav" id="sdNav" data-id="<?php echo sanitize($sale['stat_id']); ?>" data-back="<?php echo sanitize($backQs); ?>">
    <a class="sd2-btn" href="statistics.php">Home</a>
    <a class="sd2-btn is-close" id="sdClose" href="<?php echo sanitize($backUrl); ?>" title="Back to Statistics">Close</a>
    <a class="sd2-btn is-off" id="sdPrev" aria-disabled="true">Prev</a>
    <a class="sd2-btn is-off" id="sdNext" aria-disabled="true">Next</a>
    <span class="sd2-tokyo">TOKYO <?php echo $tokyo; ?></span>
  </div>

  <?php // ---------------------------------------------- the sale, as one row ?>
  <div class="sd2-row" aria-label="<?php echo sanitize($title); ?>">
    <div class="sd2-c is-lot">
      <div class="sd2-h">Lot number<br><a href="#" class="sd2-copy" id="sdCopy" data-copy="<?php echo sanitize($copy); ?>">copy info</a></div>
      <div class="sd2-v"><b class="sd2-lot"><?php echo sanitize($sale['lot_no']); ?></b></div>
    </div>
    <div class="sd2-c">
      <div class="sd2-h">Auction date<br>Auction</div>
      <div class="sd2-v"><?php echo sanitize($day); ?><?php if ($time !== ''): ?><br><i class="sd2-time">[<?php echo sanitize($time); ?>]</i><?php endif; ?>
        <br><?php echo sanitize($sale['auction']); ?></div>
    </div>
    <div class="sd2-c">
      <div class="sd2-h">Model<br>Registration year</div>
      <div class="sd2-v"><h1 class="sd2-model"><?php echo sanitize($title); ?></h1>
        <span class="sd2-year"><?php echo $sale['year'] ? (int) $sale['year'] : '—'; ?></span>
        <?php echo sanitize($sale['colour'] ?: ''); ?></div>
    </div>
    <div class="sd2-c">
      <div class="sd2-h">Chassis ID</div>
      <div class="sd2-v"><?php echo sanitize($code !== '' ? $code : '—'); ?><br><span class="sd2-grade"><?php echo sanitize($grade); ?></span></div>
    </div>
    <div class="sd2-c">
      <div class="sd2-h">Engine CC<br>Equipment</div>
      <div class="sd2-v"><span class="sd2-gear"><?php echo sanitize($gear); ?></span>
        <?php echo $sale['engine_cc'] ? number_format($sale['engine_cc']) . ' cc' : '—'; ?>
        <?php if ($hp): ?><span class="sd2-hp"><?php echo $hp; ?> hp</span><?php endif; ?>
        <br><?php echo sanitize($equip); ?></div>
    </div>
    <div class="sd2-c">
      <div class="sd2-h">Mileage<br>Condition</div>
      <div class="sd2-v"><?php echo $sale['mileage'] ? number_format($sale['mileage']) : '0'; ?><br>
        <b class="sd2-rate"><?php echo sanitize($sale['rating'] ?: '—'); ?></b></div>
    </div>
    <div class="sd2-c is-price">
      <div class="sd2-h">Start<br><?php echo $out['sold'] ? 'Sold for' : 'Highest bid'; ?></div>
      <div class="sd2-v"><?php echo $start ? number_format($start) . ' ¥' : '—'; ?><br>
        <b class="<?php echo $out['sold'] ? 'sd2-sold' : 'sd2-bid'; ?>"><?php echo $final ? number_format($final) . ' ¥' : '—'; ?></b>
        <br><span class="pill <?php echo $pillClass; ?> sd-pill"><?php echo sanitize($out['label']); ?></span>
        <span class="sd2-price-k"><?php echo $out['sold'] ? 'Final price' : 'Highest bid'; ?></span></div>
    </div>
    <div class="sd2-c">
      <div class="sd2-h">Average<br>price</div>
      <div class="sd2-v"><?php if ($summary): ?>
        <b class="sd2-avg"><?php echo number_format(round($summary['a'])); ?> ¥</b><br>
        <span class="sd2-avg-n"><?php echo number_format((int) $summary['n']); ?> sold, 3 months</span>
      <?php else: ?>&mdash;<?php endif; ?></div>
    </div>
  </div>

  <?php // ------------------------------------ "Details of vehicle"
        // Every field the source gives, the auction page's strip - always on the
        // page. The source has four buttons here; the owner, 26 September 2026,
        // took three off (sales statistics, cars catalogue, cars calculator) and
        // then had this one's panel shown without a click, as the Statistics
        // list's advanced search was taken out of its dropdown. ?>
  <div class="sd2-panel is-open" id="sdDetails">
    <div class="sd2-panel-h">Details of vehicle</div>
    <div class="pb-specs">
      <?php foreach ($specs as $s): ?>
        <div class="pb-spec <?php echo $s[2]; ?>">
          <span class="k"><?php echo sanitize($s[0]); ?></span>
          <span class="v"><?php echo sanitize((string) $s[1]); ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>


  <?php // ---------------------------------------------- photographs | sheet
        // `lot-shots` is what tells site.js's viewer which pictures are one car's:
        // the two photographs and the sheet, "1 / 3", as in the list. ?>
  <div class="sd2-body lot-shots">
    <div class="sd2-photos">
      <?php if ($photos): foreach ($photos as $i => $p): ?>
        <img src="<?php echo sanitize($p['full']); ?>" data-zoom="<?php echo sanitize($p['full']); ?>"
             class="sd2-photo" alt="<?php echo sanitize($title); ?> - photograph <?php echo $i + 1; ?>"
             referrerpolicy="no-referrer" onerror="this.style.display='none';">
      <?php endforeach; else: ?>
        <div class="noimg">No photograph for this sale</div>
      <?php endif; ?>
    </div>

    <div class="sd2-side">
      <div class="pb-vin sd2-vin" id="pbVin">
        <div class="sd2-vin-h">Month of production</div>
        <div class="pb-vin-row">
          <input type="text" id="vinType" autocomplete="off" placeholder="Code"
                 value="<?php echo sanitize(chassisParts($code)[0]); ?>" aria-label="Chassis code">
          <span class="pb-vin-dash">&ndash;</span>
          <input type="text" id="vinNo" autocomplete="off" placeholder="Number" aria-label="Chassis number">
          <select id="vinMaker" class="sd2-hide" aria-label="Maker">
            <option value="">— maker —</option>
            <?php foreach ($vinMakers as $mk): ?>
              <option value="<?php echo $mk; ?>" <?php echo $vinMake === $mk ? 'selected' : ''; ?>><?php echo $mk; ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="sd2-find" id="vinGo">Find</button>
          <button type="button" class="sd2-hide" id="vinStockGo" tabindex="-1">Our stock</button>
        </div>
        <p class="pb-vin-note" id="vinNote" hidden></p>
        <table class="pb-vin-tbl" id="vinTbl" hidden>
          <thead><tr><th>Year / Month</th><th>Model Name</th><th>Grade Code</th><th>Seat</th></tr></thead>
          <tbody></tbody>
        </table>
        <div class="pb-vin-more" id="vinMore" hidden></div>
        <div class="pb-vin-stock" id="vinStock" hidden></div>
      </div>

      <?php if ($sheet): ?>
        <img src="<?php echo sanitize($sheet['full']); ?>" data-zoom="<?php echo sanitize($sheet['full']); ?>"
             class="sd2-sheet" alt="Auction inspection sheet" referrerpolicy="no-referrer" onerror="this.style.display='none';">
      <?php endif; ?>

      <div class="sd2-legend">
        <a class="sd2-legend-h">Understanding the Japanese Auction Sheet</a>
        <ul>
          <li>A1 Small Scratch</li><li>A2 Scratch</li><li>A3 Big Scratch</li><li>E1 Few Dimples</li>
          <li>E2 Several Dimples</li><li>E3 Many Dimples</li><li>U1 Small Dent</li><li>U2 Dent</li>
          <li>U3 Big Dent</li><li>W1 Repair Mark/Wave (hardly detectable)</li><li>W2 Repair Mark/Wave</li>
          <li>W3 Obvious Repair Mark/Wave (needs to be repainted)</li><li>S1 Rust</li><li>S2 Heavy Rust</li>
          <li>C1 Corrosion</li><li>C2 Heavy Corrosion</li><li>P Paint marked</li><li>H Paint faded</li>
        </ul>
        <ul>
          <li>X Need to be replaced</li><li>XX Replaced</li><li>B1 Small dent with scratch (size like a thumb)</li>
          <li>B2 Dent with scratch (size like flat of the hand)</li><li>B3 Big Dent with scratch (size like elbow)</li>
          <li>Y1 Small Hole or Crack</li><li>Y2 Hole or Crack</li><li>Y3 Big Hole or Crack</li>
          <li>X1 Small Crack on Windshield (approximately 1cm)</li><li>R Repaired Crack on Windshield</li>
          <li>RX Repaired Crack on Windshield (needs to be replaced)</li><li>X Crack on Windshield (needs to be replaced)</li>
          <li>G Stone chip in glass</li>
        </ul>
      </div>

      <div class="sd2-desc">
        <div class="sd2-desc-h">Description</div>
        <p><?php
          $bits = array();
          if ($grade !== '')          { $bits[] = 'Grade: ' . $grade; }
          if ($gear !== '')           { $bits[] = 'Transmission: ' . $gear . (!empty($sale['drive']) ? ' ' . $sale['drive'] : ''); }
          if ($equip !== '')          { $bits[] = 'Equipment: ' . $equip; }
          if ($hp)                    { $bits[] = 'Power: ' . $hp . ' hp'; }
          if (!empty($sale['colour'])) { $bits[] = 'Colour: ' . $sale['colour']; }
          $bits[] = 'Result: ' . $out['label'] . ($final ? ($out['sold'] ? ' for ' : ', highest bid ') . '¥' . number_format($final) : '');
          echo sanitize(implode(', ', $bits));
        ?></p>
        <?php if ($summary): ?>
          <p class="sd-summary"><span class="sd-summary-k"><?php echo sanitize($code); ?>, last three months</span>:
            <?php echo number_format((int) $summary['n']); ?> sold, average ¥<?php echo number_format(round($summary['a'])); ?>,
            from ¥<?php echo number_format($summary['lo']); ?> to ¥<?php echo number_format($summary['hi']); ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php endif; ?>

</main>

<script>
/* copy info, and Prev / Next through the list the reader came from */
(function () {
  var c = document.getElementById('sdCopy');
  if (c) {
    c.addEventListener('click', function (ev) {
      ev.preventDefault();
      var t = c.getAttribute('data-copy') || '';
      var done = function () { c.textContent = 'copied'; setTimeout(function () { c.textContent = 'copy info'; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done, done); }
      else { var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select();
             try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(a); done(); }
    });
  }
  var nav = document.getElementById('sdNav');
  if (!nav || !window.fetch || !window.URLSearchParams) { return; }
  var id = nav.getAttribute('data-id'), back = nav.getAttribute('data-back') || '';
  var page = parseInt(new URLSearchParams(back).get('page') || '1', 10) || 1;
  function ids(p) {
    var q = new URLSearchParams(back); q.set('page', p); q.set('ids', '1');
    return fetch('statistics.php?' + q.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { ids: [] }; })
      .then(function (j) { return j.ids || []; }, function () { return []; });
  }
  function link(a, sid, p) {
    var q = new URLSearchParams(back); q.set('page', p);
    a.href = 'statistics-detail.php?id=' + encodeURIComponent(sid) + '&back=' + encodeURIComponent(q.toString());
    a.classList.remove('is-off'); a.removeAttribute('aria-disabled');
  }
  ids(page).then(function (list) {
    var i = list.indexOf(id);
    if (i < 0) { return; }
    var prev = document.getElementById('sdPrev'), next = document.getElementById('sdNext');
    if (i > 0) { link(prev, list[i - 1], page); }
    else if (page > 1) { ids(page - 1).then(function (l) { if (l.length) { link(prev, l[l.length - 1], page - 1); } }); }
    if (i < list.length - 1) { link(next, list[i + 1], page); }
    else { ids(page + 1).then(function (l) { if (l.length) { link(next, l[0], page + 1); } }); }
  });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
