<?php
/**
 * SBK Auction — admin inventory browser
 *
 * Inventory is owned by the auction feed (auto_sync keeps it current), so this
 * screen is read-only: search, inspect, jump to the public listing.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requirePermission('vehicles.view');

global $conn;

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$page   = max(1, intval($_GET['page'] ?? 1));
$per    = 30;
$offset = ($page - 1) * $per;

$where  = array('1=1');
$params = array();
$types  = '';

if ($search !== '') {
    $or       = "make LIKE ? OR model LIKE ? OR lot_no LIKE ? OR chassis LIKE ? OR car_id = ?";
    $like     = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $search;
    $types   .= 'sssss';
    /* A whole chassis number - HA36S-1234567, or the same without its dash -
       finds the vehicles of the model it begins with, the way the customer
       list does. Neither source carries the number itself (jpauc did not, and
       Pacific Boeki's own lot detail holds only the model code), so matching the
       column against the whole number found nothing. Only a term shaped like a
       chassis number is read this way, so a car id or a make is left alone. */
    if (preg_match('/^(?=[A-Z0-9]*[A-Z])[A-Z0-9]{2,12}-?[0-9]{4,8}$/i', $search)) {
        list($sql, $binds) = chassisNumberSql('chassis');
        $or .= ' OR ' . $sql;
        for ($i = 0; $i < $binds; $i++) {
            $params[] = $search;
            $types   .= 's';
        }
    }
    $where[] = "($or)";
}
if ($status !== '') {
    $where[]  = "status = ?";
    $params[] = $status;
    $types   .= 's';
}
// Auction stock only. Fixed-price stock shared this table and had a filter and a
// column here; the section is gone from the portal on the owner's order of
// 13 September 2026, so neither is offered and no such row is ever listed.
$where[] = "source_section = 'japan'";
/* What the customer can actually buy, or everything the feed has ever sent.
 *
 * Everything was the default, and it read as the size of the business: 393,798
 * vehicles on a screen headed Inventory, against 179,759 on the portal. The
 * difference is four weeks of finished auction days and rows already retired -
 * real history, worth being able to open, but not the answer to "how much stock
 * do we have". The live view is the same test the two portal pages use, so the
 * number here and the number a customer sees are now the same number. */
$show = ($_GET['show'] ?? 'live') === 'all' ? 'all' : 'live';
if ($show === 'live') {
    $where[] = '(' . currentLotsSql('cars') . ')';
}
$where_sql = implode(' AND ', $where);

$stmt = $conn->prepare("SELECT COUNT(*) c FROM cars WHERE $where_sql");
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare("
    SELECT id, car_id, lot_no, make, model, year, mileage, price, currency,
           status, auction, auction_date, auction_time, sold_price,
           rating, images, source_section
    FROM cars
    WHERE $where_sql
    ORDER BY last_updated DESC
    LIMIT ? OFFSET ?
");
$p2 = $params; $p2[] = $per; $p2[] = $offset;
$stmt->bind_param($types . 'ii', ...$p2);
$stmt->execute();
$cars = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pages = max(1, (int) ceil($total / $per));

$page_title = 'Vehicles';
$active = 'cars';
require_once '_header.php';
?>

<h1>Vehicles</h1>
<p class="lede">
  Inventory is synced automatically from the auction feed — this view is read-only.
</p>

<form method="GET" class="admin-toolbar">
  <input type="text" name="search" class="input" placeholder="Make, model, lot no., chassis model or number, ID"
         value="<?php echo sanitize($search); ?>" style="min-width:320px">
  <select name="status" class="select">
    <option value="">Any status</option>
    <?php foreach (array('available','sold','unsold','negotiate sold','cancel','withdrawn','removed') as $s): ?>
      <option value="<?php echo $s; ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
    <?php endforeach; ?>
  </select>
  <?php /* The "Everything ever received" choice is no longer offered.
             It sat beside a number and changed it - 46,652 against 43,811 for
             the same screen - and the difference is 2,839 rows nobody can buy:
             finished auction days and lots already withdrawn. Every time it was
             read as stock it was read wrong, and the owner asked for it to go.

             Nothing is lost with it. Bids, enquiries and orders each name their
             vehicle and link straight to its page, and that page opens whether
             the lot is still listed or not - checked on a bid from August whose
             auction is long over. The rows stay in the table for the archive
             screen that is still to be built.

             ?show=all still answers, for support work; it is simply not put in
             front of anybody. */ ?>
  <?php if ($show !== 'live'): ?>
    <input type="hidden" name="show" value="all">
  <?php endif; ?>

  <button type="submit" class="btn btn-dark">Search</button>
  <?php if ($search !== '' || $status !== '' || $show !== 'live'): ?>
    <a href="cars.php" class="btn btn-ghost">Clear</a>
  <?php endif; ?>
  <span class="page-info"><strong<?php echo $show === 'live' ? ' data-live="auction"' : ''; ?>><?php
            echo number_format($total); ?></strong> matching<?php
            echo $show !== 'live' ? ' - including lots no longer on the portal' : '';
          ?></span>
</form>

<div class="admin-card">
  <?php if (empty($cars)): ?>
    <div class="admin-card-body"><p class="hint">Nothing matches that search.</p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="dashboard-table">
        <thead>
          <tr>
            <th></th><th>Vehicle</th><th>Year</th><th>Mileage</th>
            <th>Auction</th><th>Lot</th><th>Grade</th><th>Price</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cars as $c): ?>
            <?php $thumb = getCarImage($c, 100); ?>
            <tr>
              <td style="width:74px">
                <?php // The desk gets the same click the customer gets. It was a
                      // picture you could not open - staff checking a photograph
                      // against a complaint had to open the portal page to see it
                      // at any size. site.js is already loaded here; the attribute
                      // is all it was waiting for. ?>
                <?php if ($thumb): ?>
                  <img src="<?php echo sanitize($thumb); ?>" alt=""
                       data-zoom="<?php echo sanitize(fullSizeImage($thumb)); ?>"
                       style="width:62px;height:46px;object-fit:cover;border-radius:4px;border:1px solid var(--line)">
                <?php endif; ?>
              </td>
              <td>
                <strong><?php echo sanitize(trim($c['make'] . ' ' . $c['model'])); ?></strong><br>
                <span class="mono" style="color:var(--tx-3);font-size:11.5px"><?php echo sanitize($c['car_id']); ?></span>
              </td>
              <td><?php echo $c['year'] ? intval($c['year']) : '—'; ?></td>
              <td><?php echo $c['mileage'] ? number_format($c['mileage']) . ' km' : '—'; ?></td>
              <td><?php echo sanitize($c['auction'] ?: '—'); ?><br>
                  <span style="color:var(--tx-3);font-size:11.5px"><?php echo sanitize($c['auction_date']); ?></span></td>
              <td class="mono"><?php echo sanitize($c['lot_no']); ?></td>
              <td><?php echo sanitize($c['rating'] ?: '—'); ?></td>
              <?php // Start price above, and what it actually made below it -
                    // the desk is asked "what did that one go for" more often
                    // than "what did it open at", and the answer was only on the
                    // customer's page. ?>
              <td><?php echo $c['price'] > 0 ? formatPrice($c['price'], $c['currency']) : '—'; ?>
                  <?php if ($c['sold_price'] > 0): ?>
                    <br><span style="color:#1a7a3c;font-size:11.5px;font-weight:650">&yen;<?php
                      echo number_format($c['sold_price']); ?></span>
                  <?php endif; ?>
              </td>
              <?php // 'Available' on a lot whose hammer fell hours ago is not a
                    // state, it is missing news - see lotStatusLabel(). ?>
              <td><span class="status-badge status-<?php echo sanitize(str_replace(' ', '-', strtolower(lotStatusLabel($c)))); ?>"><?php echo sanitize(lotStatusLabel($c)); ?></span></td>
              <td><a href="../car-details.php?id=<?php echo intval($c['id']); ?>" target="_blank" class="btn btn-secondary" style="height:30px;padding:0 10px;font-size:12.5px">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
  <?php
  // No section any more (fixed price is gone, 13 September 2026): naming it here
  // left "Undefined variable $section" in the log on every page of results.
  $qs = function ($p) use ($search, $status, $show) {
      $a = array('page' => $p);
      if ($search !== '') { $a['search'] = $search; }
      if ($status !== '') { $a['status'] = $status; }
      if ($show !== 'live') { $a['show'] = $show; }
      return '?' . http_build_query($a);
  };
  $start = max(1, $page - 2);
  $end   = min($pages, $page + 2);
  ?>
  <nav class="pagination">
    <?php if ($page > 1): ?><a href="<?php echo sanitize($qs($page - 1)); ?>" class="btn btn-secondary">Previous</a><?php endif; ?>
    <?php for ($i = $start; $i <= $end; $i++): ?>
      <a href="<?php echo sanitize($qs($i)); ?>" class="btn <?php echo $i === $page ? 'btn-active' : 'btn-secondary'; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
    <?php if ($page < $pages): ?><a href="<?php echo sanitize($qs($page + 1)); ?>" class="btn btn-secondary">Next</a><?php endif; ?>
    <span class="page-info">Page <?php echo number_format($page); ?> of <?php echo number_format($pages); ?></span>
  </nav>
<?php endif; ?>

<?php require_once '_footer.php'; ?>
