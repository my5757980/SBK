<?php
/**
 * One past sale, on its own page - the detail of the Statistics section.
 *
 * The owner, 24 September 2026: "in the auction statistics section the detail
 * page isn't showing up - add the detail page", and, again, "don't touch the
 * auction module at all". So this page reads ONLY the statistics table
 * (car_stats) through the Statistics library, and nothing of the auction is
 * changed or loaded for it. It borrows the auction detail's LOOK - the shared
 * stylesheet's existing classes (pb-lot-bar, pb-specs, pb-shots, pb-thumbs) -
 * so the two detail pages read as one site, and site.js's thumbnail and zoom
 * handling, which already run on every page, work here unchanged.
 *
 *   statistics-detail.php?id=<stat_id>&back=<the list's query>
 *
 * What it shows: the sale's photographs; when, where and under which lot it was
 * sold; the start and final price and what the result really was (see
 * stOutcome() - the source answers in English and in Spanish); every detail the
 * source gives; and the same model code's other sales, with what it has fetched
 * over the source's window (about three months) - which is what a buyer opens a past sale to learn.
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
   along in `back`, and only the list's own keys are accepted from it. */
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
$backQs   = preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($backQ));   // houses[]=, as the form sends
$backUrl  = 'statistics.php' . ($backQ ? '?' . $backQs : '');
$backPass = $backQ ? '&back=' . rawurlencode($backQs) : '';

/* The same model code's other sales, and what it has been fetching. The code is
   what the source prints beside every lot, so it is what joins one sale to the
   others worth comparing it with; `chassis` is indexed. */
$others  = array();
$summary = null;
$code    = $sale ? trim((string) $sale['chassis']) : '';
if ($sale && $conn && $code !== '') {
    $st = $conn->prepare(
        "SELECT stat_id, sold_on, auction, lot_no, year, mileage, rating, colour,
                start_price, final_price, result
           FROM car_stats
          WHERE chassis = ? AND stat_id <> ? AND " . stWindowSql() . "
          ORDER BY sold_on DESC, auction ASC, lot_no ASC
          LIMIT 12");
    if ($st) {
        $st->bind_param('ss', $code, $sale['stat_id']);
        $st->execute();
        $others = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }
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
    $st = $conn->prepare("SELECT COUNT(*) n FROM car_stats WHERE chassis = ? AND " . stWindowSql());
    if ($st) {
        $st->bind_param('s', $code);
        $st->execute();
        $allOfCode = (int) $st->get_result()->fetch_assoc()['n'];
        $st->close();
    }
}

if (!$sale) {
    http_response_code(404);
}

function sdYen($n) {
    return $n ? '&yen;' . number_format((float) $n) : '&mdash;';
}

$title      = $sale ? trim($sale['maker'] . ' ' . $sale['model']) : 'Sale not found';
$page_title = ($sale ? $title . ' — Lot ' . $sale['lot_no'] . ' — Statistics' : 'Statistics') . ' | ' . SITE_NAME;
require_once 'includes/header.php';
?>
<?php // The Statistics pages' own styles - the auction never loads this file. ?>
<link rel="stylesheet" href="<?php echo assetV('assets/css/stats.css'); ?>">

<main class="container sd-page">

  <nav class="crumbs">
    <a href="welcome.php">Auctions</a> &nbsp;/&nbsp;
    <a href="<?php echo sanitize($backUrl); ?>">Statistics</a> &nbsp;/&nbsp;
    <b><?php echo sanitize($sale ? $title . ' — Lot ' . $sale['lot_no'] : 'Not found'); ?></b>
  </nav>

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
    $when = $sale['sold_on'] ? date('d.m.Y', strtotime($sale['sold_on'])) : '—';
    if (!empty($sale['sold_time'])) {
        $when .= ' · ' . $sale['sold_time'];
    }
    $engine = $sale['engine_cc'] ? number_format($sale['engine_cc']) . ' cc' : '—';
    if (!empty($sale['engine_hp'])) {
        $engine .= ' · ' . (int) $sale['engine_hp'] . ' hp';
    }
    // [label, value, extra class] - the auction detail's spec strip, same markup
    $specs = array(
        array('Sold on', $when, 'is-day'),
        array('Auction house', $sale['auction'] ?: '—', ''),
        array('Lot No.', $sale['lot_no'] ?: '—', ''),
        array('Result', $out['label'], $out['sold'] ? 'is-final' : 'is-status'),
        array('Start price', $start ? '¥' . number_format($start) : '—', ''),
        array($out['sold'] ? 'Final price' : 'Highest bid', $final ? '¥' . number_format($final) : '—',
              $out['sold'] ? 'is-final' : 'is-status'),
        array('Year', $sale['year'] ? (int) $sale['year'] : '—', ''),
        array('Chassis (model code)', $code !== '' ? $code : '—', ''),
        array('Model grade', $sale['model_grade'] ?: '—', ''),
        // The gearbox and the equipment are stored under each other's names - see
        // STAT_COL_TRANS / STAT_COL_EQUIP in statistics-lib.php.
        array('Engine', $engine, ''),
        array('Transmission', trim(($sale[STAT_COL_TRANS] ?: '—') . (!empty($sale['drive']) ? ' · ' . $sale['drive'] : '')), ''),
        array('Equipment', $sale[STAT_COL_EQUIP] ?: '—', ''),
        array('Mileage', $sale['mileage'] ? number_format($sale['mileage']) . ' km' : '—', ''),
        array('Colour', $sale['colour'] ?: '—', ''),
        array('Condition grade', $sale['rating'] ?: '—', 'is-grade'),
    );
?>

  <div class="pb-lot-bar">
    <div class="pb-lot-name">
      <h1><?php echo sanitize($title); ?><?php
        if ($sale['year']): ?> <i><?php echo (int) $sale['year']; ?></i><?php endif; ?></h1>
      <span class="pb-lot-grade">
        <?php echo sanitize(trim(($sale['model_grade'] ?: '') . ($code !== '' ? '  ·  ' . $code : ''), ' ·')); ?>
      </span>
    </div>
    <div class="pb-lot-mid">
      <span class="pill <?php echo $pillClass; ?> sd-pill"><?php echo sanitize($out['label']); ?></span>
    </div>
    <div class="pb-lot-price">
      <?php echo $out['sold'] ? 'Final price' : 'Highest bid'; ?> :
      <b><?php echo $final ? number_format($final) : '---'; ?></b>
      <span class="pb-cur">JPY</span>
    </div>
  </div>

  <div class="sd-actions">
    <a href="<?php echo sanitize($backUrl); ?>" class="btn btn-secondary">&larr; Back to Statistics</a>
    <?php if ($code !== ''): ?>
      <a href="statistics.php?chassis=<?php echo rawurlencode($code); ?>" class="btn btn-ghost">
        Every sale of <?php echo sanitize($code); ?>
      </a>
    <?php endif; ?>
    <?php if ($out['sold'] && $final && $start): $up = $final - $start; ?>
      <span class="sd-rise <?php echo $up >= 0 ? 'is-up' : 'is-down'; ?>">
        <?php echo $up >= 0 ? '+' : '&minus;'; ?>&yen;<?php echo number_format(abs($up)); ?>
        <?php echo $up >= 0 ? 'over' : 'under'; ?> the start price
        (<?php echo ($up >= 0 ? '+' : '&minus;') . number_format(abs($up) / $start * 100, 1); ?>%)
      </span>
    <?php endif; ?>
  </div>

  <div class="pb-specs">
    <?php foreach ($specs as $s): ?>
      <div class="pb-spec <?php echo $s[2]; ?>">
        <span class="k"><?php echo sanitize($s[0]); ?></span>
        <span class="v"><?php echo sanitize((string) $s[1]); ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php // The photographs: the same stage and strip as the auction's detail, so
        // site.js swaps the stage when a thumbnail is chosen and zooms on a
        // click. The host answers h=50 for a small copy and the bare address for
        // the 640x480 original - see statPhotos(). No referrer, as in the list. ?>
  <div class="pb-body is-solo">
    <div class="pb-shots">
      <div class="pb-stage">
        <?php if ($pics): ?>
          <img id="stageImg" src="<?php echo sanitize($pics[0]['full']); ?>"
               data-zoom="<?php echo sanitize($pics[0]['full']); ?>"
               alt="<?php echo sanitize($title); ?>" referrerpolicy="no-referrer">
        <?php else: ?>
          <div class="noimg">No photograph for this sale</div>
        <?php endif; ?>
      </div>
      <?php if (count($pics) > 1): ?>
        <div class="pb-thumbs">
          <?php foreach ($pics as $i => $p): ?>
            <img src="<?php echo sanitize($p['thumb']); ?>"
                 data-full="<?php echo sanitize($p['full']); ?>"
                 data-zoom="<?php echo sanitize($p['full']); ?>"
                 class="thumb<?php echo $i === 0 ? ' active' : ''; ?>"
                 alt="View <?php echo $i + 1; ?>" referrerpolicy="no-referrer"
                 onerror="this.style.display='none';">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($summary): ?>
    <div class="sd-summary">
      <span class="sd-summary-k"><?php echo sanitize($code); ?>, last three months</span>
      <span><b><?php echo number_format((int) $summary['n']); ?></b> sold</span>
      <span>average <b>&yen;<?php echo number_format(round($summary['a'])); ?></b></span>
      <span>from &yen;<?php echo number_format($summary['lo']); ?> to &yen;<?php echo number_format($summary['hi']); ?></span>
    </div>
  <?php endif; ?>

  <?php if ($others): ?>
    <section class="sd-others">
      <h2>Other sales of <?php echo sanitize($code); ?>
        <?php if (!empty($allOfCode) && $allOfCode > 1): ?>
          <span class="sd-count"><?php echo number_format($allOfCode - 1); ?> more in Statistics</span>
        <?php endif; ?>
      </h2>
      <div class="lot-table-wrap">
        <table class="lot-table sd-table">
          <thead>
            <tr>
              <th>Sold on<span class="sub">Hall</span></th>
              <th>Lot No.</th>
              <th>Year</th>
              <th>Mileage (KM)</th>
              <th>Cond.<br>Grade</th>
              <th>Colour</th>
              <th class="c-price">Start</th>
              <th class="c-price">Final</th>
              <th class="c-result">Result</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($others as $o):
                $oo = stOutcome($o);
                $oh = 'statistics-detail.php?id=' . rawurlencode($o['stat_id']) . $backPass; ?>
              <tr class="st-row" data-href="<?php echo sanitize($oh); ?>">
                <td>
                  <a class="st-open" href="<?php echo sanitize($oh); ?>"><?php
                    echo $o['sold_on'] ? date('d.m.Y', strtotime($o['sold_on'])) : '—'; ?></a>
                  <span class="sub"><?php echo sanitize($o['auction']); ?></span>
                </td>
                <td><?php echo sanitize($o['lot_no']); ?></td>
                <td><?php echo $o['year'] ? (int) $o['year'] : '—'; ?></td>
                <td class="c-km"><?php echo $o['mileage'] ? number_format($o['mileage']) : '—'; ?></td>
                <td class="c-grade"><?php echo sanitize($o['rating'] ?: '—'); ?></td>
                <td><?php echo sanitize($o['colour'] ?: '—'); ?></td>
                <td class="c-price"><?php echo sdYen($o['start_price']); ?></td>
                <td class="c-price">
                  <?php if ($o['final_price']): ?>
                    <b class="<?php echo $oo['sold'] ? 'st-sold' : 'st-unsold'; ?>"><?php echo sdYen($o['final_price']); ?></b>
                  <?php else: ?>&mdash;<?php endif; ?>
                </td>
                <td class="c-result">
                  <span class="pill <?php echo $oo['sold'] ? 'pill-sold' : ($oo['key'] === 'unsold' ? 'pill-gone' : 'pill-other'); ?>"><?php
                    echo sanitize($oo['short']); ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

<?php endif; ?>

</main>

<?php // A row of the "other sales" table opens that sale, as the list's rows do. ?>
<script>
document.addEventListener('click', function (ev) {
  var t = ev.target;
  if (!t.closest || t.closest('a, button, img, input, select')) { return; }
  var row = t.closest('tr.st-row[data-href]');
  if (!row) { return; }
  if (ev.ctrlKey || ev.metaKey) { window.open(row.getAttribute('data-href'), '_blank'); return; }
  window.location.href = row.getAttribute('data-href');
});
</script>

<?php require_once 'includes/footer.php'; ?>
