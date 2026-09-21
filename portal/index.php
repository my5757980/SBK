<?php
/**
 * SBK Auction — the auction list.
 *
 * Signed-in only. Reached from the model picker, and shows lots in column
 * format with facet filters that narrow as the buyer chooses.
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

requireLogin();

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

$filters = array(
    'make'         => trim($_GET['make'] ?? ''),
    'model'        => trim($_GET['model'] ?? ''),
    'lot'           => trim($_GET['lot'] ?? ''),
    'chassis_model' => trim($_GET['chassis_model'] ?? ''),
    'chassis_no'    => trim($_GET['chassis_no'] ?? ''),
    'year_min'     => $_GET['year_min'] ?? '',
    'year_max'     => $_GET['year_max'] ?? '',
    // mileage_min was missing here while the filter builder knew about it, so
    // the low end of the mileage range was read off an undefined key
    'mileage_min'  => $_GET['mileage_min'] ?? '',
    'mileage_max'  => $_GET['mileage_max'] ?? '',
    'cc_min'       => $_GET['cc_min'] ?? '',
    'cc_max'       => $_GET['cc_max'] ?? '',
    'price_max'    => $_GET['price_max'] ?? '',
    'auction_on'   => (array) ($_GET['auction_on'] ?? array()),
    'auction'      => (array) ($_GET['auction'] ?? array()),
    'chassis'      => (array) ($_GET['chassis'] ?? array()),
    'rating'       => (array) ($_GET['rating'] ?? array()),
    'color'        => (array) ($_GET['color'] ?? array()),
    'status'       => (array) ($_GET['status'] ?? array()),
    'transmission' => (array) ($_GET['transmission'] ?? array()),
    'sort'         => $_GET['sort'] ?? 'newest',
);

$per_page    = isset($_GET['per_page']) ? min(100, max(20, intval($_GET['per_page']))) : 20;
$result      = getActiveCars($page, $per_page, $filters);
$cars        = $result['cars'];
$total_pages = (int) $result['total_pages'];
$total_cars  = (int) $result['total'];

$facets = getFacets($filters);

// The sliders' ends are measured with the sliders' own filters taken off.
// Measured with them on, the track collapses onto whatever was last chosen:
// ask for 1,500-2,000cc and the highest engine in the result set is 2,000, so
// the track ends at 2,000, so the handle sits at the end, so it reads as "no
// limit" and the box goes blank while the filter is still applied. The track
// should say what the stock offers, not repeat what has already been asked for.
$boundsFilters = $filters;
foreach (array('year_min', 'year_max', 'mileage_min', 'mileage_max', 'cc_min', 'cc_max') as $k) {
    $boundsFilters[$k] = '';
}
$bounds = getRangeBounds($boundsFilters);
$makes  = getMakes();
$models = $filters['make'] !== '' ? getModelsByLetter($filters['make']) : array();

function assetV($path) {
    $full = __DIR__ . '/' . ltrim($path, '/');
    return $path . '?v=' . (is_file($full) ? filemtime($full) : time());
}

/** Rebuild the current query string with some parts changed. */
function qs(array $changes = array()) {
    $q = $_GET;
    foreach ($changes as $k => $v) {
        if ($v === null) { unset($q[$k]); } else { $q[$k] = $v; }
    }
    unset($q['page']);
    if (isset($changes['page'])) { $q['page'] = $changes['page']; }
    return '?' . http_build_query($q);
}

/** Link for a sortable column: toggles between the two directions. */
function sortLink($asc, $desc, $label) {
    $cur = $_GET['sort'] ?? 'newest';
    $next = ($cur === $desc) ? $asc : $desc;
    $mark = ($cur === $asc) ? ' ▲' : (($cur === $desc) ? ' ▼' : '');
    $on = ($cur === $asc || $cur === $desc) ? ' is-sorted' : '';
    return '<a class="col-sort' . $on . '" href="' . sanitize(qs(array('sort' => $next)))
         . '">' . $label . '<span class="arr">' . $mark . '</span></a>';
}

$labels = array(
    'chassis'      => 'Chassis ID',
    'rating'       => 'Condition',
    'color'        => 'Colours',
    'status'       => 'Status',
    'transmission' => 'Gearbox',
);

$has_filters = ($filters['make'] || $filters['model'] || $filters['lot']
    || $filters['year_min'] || $filters['year_max'] || $filters['mileage_max']
    || $filters['cc_min'] || $filters['cc_max'] || $filters['price_max']
    || $filters['chassis'] || $filters['rating'] || $filters['color']
    || $filters['status'] || $filters['transmission']);

$page_title = trim(($filters['make'] . ' ' . $filters['model'])) ?: 'Inventory';
$page_title .= ' — ' . SITE_NAME;
require_once 'includes/header.php';
?>

<main class="container">

  <h1 class="pb-title">JAPAN AUTO AUCTION</h1>

  <!-- ---------------------------------------------------------------- filters -->
  <form method="GET" class="pb-search" id="pbSearch">
    <input type="hidden" name="sort" value="<?php echo sanitize($filters['sort']); ?>">
    <input type="hidden" name="per_page" value="<?php echo $per_page; ?>">
    <?php // The make is chosen by following a link, not by ticking a box, so it
          // is not a field this form would otherwise carry - and submitting
          // dropped it. Picking TOYOTA and then a model searched every make for
          // that model name, and the Make column came back with nothing chosen. ?>
    <input type="hidden" name="make" value="<?php echo sanitize($filters['make']); ?>">

    <?php /* Three ways in, named for what they are.

             There was one box doing all three jobs and labelled after only one
             of them - "SEARCH BY LOT NUMBER", hinted "or Chassis Model" - so a
             buyer holding a chassis number had no reason to think it would be
             taken, and a buyer holding a chassis code had to read the small
             print. Each now has its own box and its own label. */ ?>
    <!-- lot number strip -->
    <div class="pb-lotbar">
      <div class="pb-lotbar-in">
        <div class="pb-find">
          <label for="f-lot">Search By Lot Number</label>
          <input type="text" name="lot" id="f-lot" placeholder="4501, 4359, .."
                 value="<?php echo sanitize($filters['lot']); ?>">
        </div>
        <div class="pb-find">
          <label for="f-cmodel">Search By Chassis Model</label>
          <input type="text" name="chassis_model" id="f-cmodel" placeholder="NHP10"
                 value="<?php echo sanitize($filters['chassis_model']); ?>">
        </div>
        <div class="pb-find">
          <label for="f-cno">Search By Chassis Number</label>
          <input type="text" name="chassis_no" id="f-cno" placeholder="NHP10-2054321"
                 value="<?php echo sanitize($filters['chassis_no']); ?>">
        </div>
        <select name="auction[]" id="f-hall">
          <option value="">-- Select Auction --</option>
          <?php foreach (($facets['auction'] ?? array()) as $r): ?>
            <option value="<?php echo sanitize($r['v']); ?>"
              <?php echo in_array((string) $r['v'], (array) $filters['auction'], true) ? 'selected' : ''; ?>>
              <?php echo sanitize($r['v']) . ' (' . number_format($r['n']) . ')'; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="pb-btn">Search</button>
      </div>
      <p class="pb-hint">
        Comma separate to search multiple lot no. For eg. 4501, 4359, ..
        <br>
        <?php /* Said here rather than found out by getting nothing back. The
                 feed carries the model code and not the number - jpauc's own
                 page has the field and leaves it empty - so a whole number is
                 taken for the model it begins with. Narrow it with the auction
                 hall and the sale day to reach one vehicle. */ ?>
        <span class="pb-hint-2">A chassis number finds every vehicle of that
        model — the auction feed does not carry the number itself. Add the
        auction hall to narrow it down.</span>
      </p>
    </div>

    <!-- the six columns the reference portal leads with -->
    <div class="pb-cols" id="pbCols">

      <div class="pb-col pb-col-date">
        <div class="pb-col-h">Auc. Date</div>
        <div class="pb-col-b pb-scroll">
          <?php foreach (($facets['auction_on'] ?? array()) as $r): ?>
            <?php if (!$r['v']) continue; ?>
            <label class="pb-ck">
              <input type="checkbox" name="auction_on[]" value="<?php echo sanitize($r['v']); ?>"
                <?php echo in_array((string) $r['v'], (array) $filters['auction_on'], true) ? 'checked' : ''; ?>>
              <span><?php echo sanitize(date('m/d D', strtotime($r['v']))); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pb-col pb-col-make">
        <div class="pb-col-h">Make</div>
        <div class="pb-col-b pb-scroll">
          <?php foreach ($makes as $m): ?>
            <a class="pb-mk<?php echo $filters['make'] === $m['make'] ? ' is-on' : ''; ?>"
               href="<?php echo sanitize(qs(array('make' => $m['make'], 'model' => null))); ?>">
              <?php echo sanitize($m['make']); ?><i><?php echo number_format($m['n']); ?></i>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pb-col pb-col-model">
        <div class="pb-col-h">Model</div>
        <div class="pb-col-b pb-scroll">
          <?php if ($models): ?>
            <?php foreach ($models as $rows): ?>
              <?php foreach ($rows as $r): ?>
                <label class="pb-ck">
                  <input type="radio" name="model" value="<?php echo sanitize($r['model']); ?>"
                    <?php echo $filters['model'] === $r['model'] ? 'checked' : ''; ?>>
                  <span><?php echo sanitize($r['model']); ?> [<?php echo number_format($r['n']); ?>]</span>
                </label>
              <?php endforeach; ?>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="pb-none">Pick a make first.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="pb-col pb-col-more">
        <div class="pb-col-h">More Filters</div>
        <div class="pb-col-b">
          <?php
            // Six boxes to type numbers into became three sliders you drag.
            //
            // The ends come from the stock itself, not from round guesses: a
            // year slider running to 2030 wastes half its track when the newest
            // car is a 2026, and one running from 1900 wastes the other half.
            // Narrow to a make and the ends narrow with it.
            //
            // A handle resting against the end of its track means "no limit",
            // and posts nothing at all. It cannot post the end value instead:
            // the track ends where the clamp puts it, not where the stock does,
            // so a mileage slider ending at 300,000 would quietly hide every
            // lorry above it - and hide it by default, before the buyer had
            // touched anything.
            $slide = function ($key, $label, $lo, $hi, $step, $fmt) use ($filters) {
                $from = $filters[$key . '_min'] !== '' ? (int) $filters[$key . '_min'] : $lo;
                $to   = $filters[$key . '_max'] !== '' ? (int) $filters[$key . '_max'] : $hi;
                $from = max($lo, min($hi, $from));
                $to   = max($from, min($hi, $to));
                ?>
                <div class="pb-range" data-key="<?php echo $key; ?>" data-fmt="<?php echo $fmt; ?>">
                  <?php // The heading is the switch: click it and the slider gives
                        // way to the two boxes, click again and it comes back.
                        // Dragging suits "roughly 2015 onwards"; typing suits
                        // "exactly 1,500 to 2,000cc", and neither one answers
                        // for both. ?>
                  <button type="button" class="pb-range-h" aria-expanded="false">
                    <span class="pb-range-l"><?php echo sanitize($label); ?></span>
                    <span class="pb-range-v"></span>
                  </button>
                  <div class="pb-range-t">
                    <span class="pb-range-fill"></span>
                    <input type="range" class="pb-range-a" min="<?php echo $lo; ?>" max="<?php echo $hi; ?>"
                           step="<?php echo $step; ?>" value="<?php echo $from; ?>"
                           aria-label="<?php echo sanitize($label); ?> from">
                    <input type="range" class="pb-range-b" min="<?php echo $lo; ?>" max="<?php echo $hi; ?>"
                           step="<?php echo $step; ?>" value="<?php echo $to; ?>"
                           aria-label="<?php echo sanitize($label); ?> to">
                  </div>
                  <?php // These are the fields the form actually posts, in both
                        // modes - the slider writes into them. Two sets of
                        // inputs carrying the same name would post twice. ?>
                  <div class="pb-range-x">
                    <input type="number" name="<?php echo $key; ?>_min" placeholder="From"
                           min="<?php echo $lo; ?>" max="<?php echo $hi; ?>" step="<?php echo $step; ?>"
                           value="<?php echo $from > $lo ? $from : ''; ?>">
                    <input type="number" name="<?php echo $key; ?>_max" placeholder="To"
                           min="<?php echo $lo; ?>" max="<?php echo $hi; ?>" step="<?php echo $step; ?>"
                           value="<?php echo $to < $hi ? $to : ''; ?>">
                  </div>
                </div>
                <?php
            };

            // Clamped, because the ends come from MIN and MAX and a single bad
            // row sets them. One "year 9999" and the year slider spans eight
            // thousand years; one engine size that overflowed its column and
            // the engine slider runs to 2.1 billion cc and every real car sits
            // in the first pixel. A boundary has to survive the worst row in
            // the table, not describe it.
            $clamp = function ($v, $lo, $hi, $fallback) {
                $v = (int) $v;
                return ($v >= $lo && $v <= $hi) ? $v : $fallback;
            };
            $thisYear = (int) date('Y');

            $yLo = $clamp($bounds['year_min'], 1950, $thisYear + 1, 1970);
            $yHi = max($yLo, $clamp($bounds['year_max'], 1950, $thisYear + 1, $thisYear));

            $mHi = $clamp($bounds['mileage_max'], 1, 500000, 300000);
            $mHi = (int) (ceil($mHi / 10000) * 10000);

            $cHi = $clamp($bounds['cc_max'], 1, 10000, 6000);
            $cHi = (int) (ceil($cHi / 500) * 500);

            $slide('year',    'Year Range',      $yLo, $yHi, 1,     'plain');
            $slide('mileage', 'Mileage Range',   0,    $mHi, 1000,  'comma');
            $slide('cc',      'Engine CC Range', 0,    $cHi, 100,   'comma');
          ?>

          <div class="pb-pair">
            <select name="transmission[]">
              <option value="">Transmission</option>
              <?php foreach (($facets['transmission'] ?? array()) as $r): ?>
                <option value="<?php echo sanitize($r['v']); ?>"
                  <?php echo in_array((string) $r['v'], (array) $filters['transmission'], true) ? 'selected' : ''; ?>>
                  <?php echo sanitize($r['v']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select name="color[]">
              <option value="">Colors</option>
              <?php foreach (($facets['color'] ?? array()) as $r): ?>
                <option value="<?php echo sanitize($r['v']); ?>"
                  <?php echo in_array((string) $r['v'], (array) $filters['color'], true) ? 'selected' : ''; ?>>
                  <?php echo sanitize($r['v']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="pb-grades">
            <span class="pb-grades-l">Auction<br>Grade</span>
            <div class="pb-grades-b">
              <?php foreach (($facets['rating'] ?? array()) as $r): ?>
                <label class="pb-ck pb-ck-in">
                  <input type="checkbox" name="rating[]" value="<?php echo sanitize($r['v']); ?>"
                    <?php echo in_array((string) $r['v'], (array) $filters['rating'], true) ? 'checked' : ''; ?>>
                  <span><?php echo sanitize($r['v']); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="pb-col pb-col-chassis">
        <div class="pb-col-h">Chassis Model</div>
        <div class="pb-col-b pb-scroll">
          <?php foreach (($facets['chassis'] ?? array()) as $r): ?>
            <label class="pb-ck">
              <input type="checkbox" name="chassis[]" value="<?php echo sanitize($r['v']); ?>"
                <?php echo in_array((string) $r['v'], (array) $filters['chassis'], true) ? 'checked' : ''; ?>>
              <span><?php echo sanitize($r['v']); ?> [<?php echo number_format($r['n']); ?>]</span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pb-col pb-col-auction">
        <div class="pb-col-h">Auction</div>
        <div class="pb-col-b pb-scroll">
          <?php foreach (($facets['auction'] ?? array()) as $r): ?>
            <label class="pb-ck">
              <input type="checkbox" name="auction[]" value="<?php echo sanitize($r['v']); ?>"
                <?php echo in_array((string) $r['v'], (array) $filters['auction'], true) ? 'checked' : ''; ?>>
              <span><?php echo sanitize($r['v']); ?> [<?php echo number_format($r['n']); ?>]</span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

    </div>

    <div class="pb-acts">
      <button type="submit" class="pb-btn">Search</button>
      <a href="index.php" class="pb-btn pb-btn-2">Reset</a>
    </div>
    <div class="pb-hide">
      <button type="button" class="pb-btn" id="pbToggle" data-shown="1">Hide All Search &minus;</button>
    </div>
  </form>

  <!-- ------------------------------------------------------------ result bar -->
  <div class="pb-resultbar">
    <div class="pb-total">
      Total Records : <strong id="resultsCount"><?php echo number_format($total_cars); ?></strong>
      <span id="liveBadge" class="live-badge" title="Inventory updates automatically">Live</span>
    </div>
    <label class="pb-perpage">
      Per Page
      <select onchange="location.href=this.value">
        <?php foreach (array(20, 50, 100) as $n): ?>
          <option value="<?php echo sanitize(qs(array('per_page' => $n))); ?>"
            <?php echo $per_page === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <?php if (!$cars): ?>
    <div class="empty">
      <?php if ($filters['lot'] !== ''): ?>
        <?php // Say what was looked for. An empty grid after a search reads as a
              // broken search; the same grid with the words "nothing here
              // matches NHP10" reads as an answer. And say what the box holds,
              // because the commonest way to get nothing out of it is to have
              // typed a whole chassis number - which the auction feed does not
              // publish and we therefore do not have. ?>
        <h2>Nothing matches &ldquo;<?php echo sanitize($filters['lot']); ?>&rdquo;</h2>
        <p>This box takes a <strong>lot number</strong> or a <strong>chassis model
           code</strong> — the short code the auction prints beside the engine
           size, like NHP10 or ZVW30. Whole chassis numbers are not published by
           the auction, so they cannot be searched for.</p>
      <?php else: ?>
        <h2>No lots match those filters</h2>
        <p>Try widening the year, mileage or engine range.</p>
      <?php endif; ?>
      <a href="index.php" class="btn btn-primary">Reset filters</a>
    </div>
  <?php else: ?>

  <!-- ----------------------------------------------------------------- list -->
  <div class="lot-table-wrap">
    <table class="lot-table" id="lotTable" data-per-page="<?php echo $per_page; ?>">
      <thead>
        <tr>
          <th class="c-photo">Photo</th>
          <th><?php echo sortLink('lot_asc', 'lot_desc', 'Lot Number'); ?></th>
          <th><?php echo sortLink('date_asc', 'date_desc', 'Auction Date'); ?><span class="sub">Auction Hall</span></th>
          <th>Model Name<span class="sub"><?php echo sortLink('year_old', 'year_new', 'Year'); ?></span></th>
          <th>Chassis No.<span class="sub">Model Grade</span></th>
          <th><?php echo sortLink('cc_low', 'cc_high', 'Engine (CC)'); ?></th>
          <th><?php echo sortLink('mileage', 'mileage_high', 'Mileage (KM)'); ?></th>
          <th>Trans.<span class="sub">Color</span></th>
          <th><?php echo sortLink('cond_low', 'cond_high', 'Auction<br>Grade'); ?></th>
          <th class="c-price"><?php echo sortLink('price_low', 'price_high', 'Start<br>Price'); ?></th>
          <th class="c-result">Result</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cars as $car): ?>
          <?php
            $photos = getCarImages($car, 320);
            $thumbs = getCarImages($car, 100);
            $title  = trim($car['make'] . ' ' . $car['model']);
            $href   = 'car-details.php?id=' . (int) $car['id'];
          ?>
          <tr data-id="<?php echo (int) $car['id']; ?>">
            <td class="c-photo">
              <?php
                // Two photographs and the auction sheet. The listing links only
                // the first picture, but the rest answer to the same address
                // with a different number - and the sheet to number=0 - so all
                // three are worked out here rather than fetched.
                $set = jpaucImageSet($car, 2);
                if ($set) {
                    $shots = array_map(function ($u) {
                        return str_replace('&h=640', '&h=320', $u);
                    }, $set);
                    $sheetUrl = jpaucSheetUrl($car);
                    if ($sheetUrl) { $shots[] = str_replace('&h=640', '&h=320', $sheetUrl); }
                } else {
                    $shots = $thumbs;
                }
              ?>
              <?php if ($shots): ?>
                <div class="lot-shots">
                  <?php foreach ($shots as $t): ?>
                    <?php // zoom opens the original, not the thumbnail blown up ?>
                    <img src="<?php echo sanitize($t); ?>"
                         data-zoom="<?php echo sanitize(fullSizeImage($t)); ?>"
                         alt="<?php echo sanitize($title); ?>" loading="lazy" class="lot-thumb">
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="no-thumb">—</span>
              <?php endif; ?>
            </td>
            <td class="c-lot">
              <a class="lot-no" href="<?php echo $href; ?>"><?php echo sanitize($car['lot_no'] ?: '—'); ?></a>
              <a class="wish" href="<?php echo $href; ?>">Add To Wishlist</a>
              <span class="abcd">
                <?php // one letter per photo the feed gave us; greyed out when absent ?>
                <?php foreach (array('A', 'B', 'C', 'D') as $i => $L): ?>
                  <?php if (isset($photos[$i])): ?>
                    <a class="ltr" href="<?php echo sanitize($photos[$i]); ?>"
                       data-full="<?php echo sanitize($photos[$i]); ?>"><?php echo $L; ?></a>
                  <?php else: ?>
                    <span class="ltr is-off"><?php echo $L; ?></span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </span>
            </td>
            <td>
              <span class="ad"><?php echo sanitize(shortAuctionDate($car['auction_date'])); ?><?php
                if (!empty($car['auction_time'])): ?><i class="jst">(<?php
                  echo sanitize(trim($car['auction_time'], '[]')); ?> JST)</i><?php
                endif; ?></span>
              <?php $ends = auctionEndsAt($car); ?>
              <?php if ($ends !== null): ?>
                <span class="left" data-ends="<?php echo (int) $ends; ?>"></span>
              <?php endif; ?>
              <span class="hall"><?php echo sanitize($car['auction'] ?: '—'); ?></span>
            </td>
            <td>
              <span class="mdl"><?php echo sanitize($car['model'] ?: $title); ?></span>
              <span class="yr"><?php echo $car['year'] ? (int) $car['year'] : '—'; ?></span>
            </td>
            <td>
              <span class="chs"><?php echo sanitize($car['chassis'] ?: '—'); ?></span>
              <span class="gr"><?php echo sanitize($car['grade'] ?: ''); ?></span>
            </td>
            <td class="c-cc"><?php echo $car['engine_cc'] ? number_format($car['engine_cc']) : '—'; ?></td>
            <td class="c-km"><?php echo $car['mileage'] ? number_format($car['mileage']) : '—'; ?></td>
            <td>
              <span class="trn"><?php echo sanitize($car['transmission'] ?: '—'); ?></span>
              <span class="col"><?php echo sanitize($car['color'] ?: '—'); ?></span>
            </td>
            <td class="c-grade"><?php echo sanitize($car['rating'] ?: '—'); ?></td>
            <td class="c-price"><?php echo $car['price'] > 0 ? number_format($car['price']) : '---'; ?></td>
            <td class="c-result <?php echo lotStatusClass($car); ?>">
              <?php echo sanitize(lotStatusLabel($car)); ?>
              <?php if ($car['sold_price'] > 0): ?>
                <span class="sold-at">¥<?php echo number_format($car['sold_price']); ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
    <?php $start = max(1, $page - 3); $end = min($total_pages, $page + 3); ?>
    <nav class="pagination">
      <?php if ($page > 1): ?>
        <a href="<?php echo sanitize(qs(array('page' => $page - 1))); ?>" class="btn btn-secondary">Previous</a>
      <?php endif; ?>
      <?php if ($start > 1): ?>
        <a href="<?php echo sanitize(qs(array('page' => 1))); ?>" class="btn btn-secondary">1</a>
        <?php if ($start > 2): ?><span class="page-gap">…</span><?php endif; ?>
      <?php endif; ?>
      <?php for ($i = $start; $i <= $end; $i++): ?>
        <a href="<?php echo sanitize(qs(array('page' => $i))); ?>"
           class="btn <?php echo $i === $page ? 'btn-active' : 'btn-secondary'; ?>"><?php echo $i; ?></a>
      <?php endfor; ?>
      <?php if ($end < $total_pages): ?>
        <?php if ($end < $total_pages - 1): ?><span class="page-gap">…</span><?php endif; ?>
        <a href="<?php echo sanitize(qs(array('page' => $total_pages))); ?>" class="btn btn-secondary"><?php echo number_format($total_pages); ?></a>
      <?php endif; ?>
      <?php if ($page < $total_pages): ?>
        <a href="<?php echo sanitize(qs(array('page' => $page + 1))); ?>" class="btn btn-secondary">Next</a>
      <?php endif; ?>
      <span class="page-info">Page <?php echo number_format($page); ?> of <?php echo number_format($total_pages); ?></span>
    </nav>
  <?php endif; ?>

  <?php endif; ?>
</main>

<?php // the photo viewer lives in site.js, once, for every page ?>
<script src="<?php echo assetV('assets/js/lots.js'); ?>"></script>
<?php require_once 'includes/footer.php'; ?>
