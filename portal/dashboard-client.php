<?php
/**
 * SBK Auction — client dashboard
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

requireClient();

$client    = getClient();
$orders    = getClientOrders($client['id']);
$inquiries = getClientInquiries($client['id']);
$bids      = getClientBids($client['id']);

// headline numbers
$order_total = 0;
$open_orders = 0;
foreach ($orders as $o) {
    $order_total += floatval($o['amount']);
    if (in_array(strtolower($o['status']), array('pending', 'confirmed'), true)) {
        $open_orders++;
    }
}
$live_bids = 0;
foreach ($bids as $b) {
    if (in_array(strtolower($b['status']), array('placed', 'under review', 'accepted'), true)) {
        $live_bids++;
    }
}
$open_inquiries = 0;
foreach ($inquiries as $q) {
    if (strtolower($q['status']) === 'open') {
        $open_inquiries++;
    }
}

$initials = strtoupper(mb_substr($client['name'], 0, 1));
$parts = preg_split('/\s+/', trim($client['name']));
if (count($parts) > 1) {
    $initials .= strtoupper(mb_substr(end($parts), 0, 1));
}

$page_title = 'Dashboard — ' . SITE_NAME;
require_once 'includes/header.php';
?>

<section class="acct-head">
  <div class="container">
    <div class="acct-id">
      <div class="avatar"><?php echo sanitize($initials); ?></div>
      <div>
        <h1><?php echo sanitize($client['name']); ?></h1>
        <p><?php echo sanitize($client['email']); ?> &nbsp;·&nbsp; Member since <?php echo formatDate($client['created_at']); ?></p>
      </div>
    </div>
    <div class="acct-actions">
      <a href="welcome.php" class="btn btn-primary">Browse auctions</a>
      <a href="includes/logout.php" class="btn btn-ghost" style="color:#c9d1d9">Log out</a>
    </div>
  </div>
</section>

<main class="container">

  <div class="stat-row">
    <?php if ($orders): ?>
      <div class="stat-tile">
        <div class="k">Orders placed</div>
        <div class="v"><?php echo number_format(count($orders)); ?></div>
        <div class="s"><?php echo $open_orders; ?> in progress</div>
      </div>
    <?php endif; ?>
    <div class="stat-tile">
      <div class="k">Bids placed</div>
      <div class="v"><?php echo number_format(count($bids)); ?></div>
      <div class="s"><?php echo $live_bids; ?> still live</div>
    </div>
    <div class="stat-tile">
      <div class="k">Enquiries</div>
      <div class="v"><?php echo number_format(count($inquiries)); ?></div>
      <div class="s"><?php echo $open_inquiries; ?> awaiting reply</div>
    </div>
    <?php // These two say "live", so they have to BE live: data-live keeps them
          // up to date from api/counts.php, the way the desk's overview already
          // was. Until 15 September 2026 this tile carried the words without the
          // attribute, so it promised a figure that in fact never moved until the
          // reader pressed refresh. ?>
    <div class="stat-tile accent">
      <div class="k">Inventory available</div>
      <div class="v" data-live="auction"><?php $st = getInventoryStats(); echo number_format($st['available']); ?></div>
      <div class="s">Updated live from Japan</div>
    </div>
    <div class="stat-tile">
      <div class="k">Statistics</div>
      <div class="v" data-live="statistics"><?php echo number_format(statsCount()); ?></div>
      <div class="s"><a href="statistics.php">past auction results</a></div>
    </div>
  </div>

  <div class="section-head">
    <h2>My bids</h2><span class="rule"></span>
    <?php if ($bids): ?><span class="page-info"><?php echo count($bids); ?> total</span><?php endif; ?>
  </div>

  <?php if (empty($bids)): ?>
    <div class="empty">
      <h2>No bids yet</h2>
      <p>Open any lot and place a bid inside its allowed range.</p>
      <a href="welcome.php" class="btn btn-primary">Browse auctions</a>
    </div>
  <?php else: ?>
    <div class="panel" style="padding:0;overflow:hidden">
      <div class="table-responsive">
        <table class="dashboard-table">
          <thead>
            <tr>
              <th>Bid</th><th>Vehicle</th><th>Lot</th><th>Your bid</th>
              <th>Status</th><th>Placed</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bids as $b): ?>
              <tr>
                <td class="mono"><strong><?php echo sanitize($b['bid_number']); ?></strong></td>
                <td>
                  <?php if (!empty($b['car_row_id'])): ?>
                    <a href="car-details.php?id=<?php echo intval($b['car_row_id']); ?>">
                      <?php echo sanitize(trim($b['make'] . ' ' . $b['model'])); ?>
                    </a>
                  <?php else: ?>
                    <?php echo sanitize(trim($b['make'] . ' ' . $b['model'])); ?>
                  <?php endif; ?>
                  <?php if (!empty($b['year'])): ?>
                    <span style="color:var(--dead)">· <?php echo intval($b['year']); ?></span>
                  <?php endif; ?>
                </td>
                <td class="mono"><?php echo sanitize($b['lot_no']); ?></td>
                <td><strong>¥<?php echo number_format($b['amount']); ?></strong></td>
                <td><span class="status-badge status-<?php echo sanitize(str_replace(' ', '-', strtolower($b['status']))); ?>"><?php echo sanitize(ucfirst($b['status'])); ?></span></td>
                <td><?php echo formatDate($b['placed_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($orders): ?>
  <div class="section-head" style="margin-top:26px">
    <h2>My orders</h2><span class="rule"></span>
    <span class="page-info"><?php echo count($orders); ?> total</span>
  </div>
    <div class="panel" style="padding:0;overflow:hidden">
      <div class="table-responsive">
        <table class="dashboard-table">
          <thead>
            <tr>
              <th>Order</th><th>Vehicle</th><th>Amount</th><th>Status</th><th>Placed</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o): ?>
              <tr>
                <td class="mono"><strong><?php echo sanitize($o['order_number']); ?></strong></td>
                <td>
                  <?php echo sanitize(trim($o['make'] . ' ' . $o['model'])); ?>
                  <?php if (!empty($o['year'])): ?>
                    <span style="color:var(--dead)">· <?php echo intval($o['year']); ?></span>
                  <?php endif; ?>
                </td>
                <td><?php echo $o['amount'] > 0 ? '¥' . number_format($o['amount']) : '—'; ?></td>
                <td><span class="status-badge status-<?php echo sanitize(strtolower($o['status'])); ?>"><?php echo sanitize(ucfirst($o['status'])); ?></span></td>
                <td><?php echo formatDate($o['order_date']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="section-head" style="margin-top:26px">
    <h2>My enquiries</h2><span class="rule"></span>
    <?php if ($inquiries): ?><span class="page-info"><?php echo count($inquiries); ?> total</span><?php endif; ?>
  </div>

  <?php if (empty($inquiries)): ?>
    <div class="empty" style="margin-bottom:44px">
      <h2>No enquiries yet</h2>
      <p>Ask us about condition, shipping or landed cost on any lot.</p>
      <a href="welcome.php" class="btn btn-primary">Find a vehicle</a>
    </div>
  <?php else: ?>
    <div class="thread-list">
      <?php foreach ($inquiries as $q): ?>
        <div class="thread">
          <div class="thread-top">
            <h3><?php echo sanitize(trim($q['make'] . ' ' . $q['model'])); ?></h3>
            <span class="status-badge status-<?php echo sanitize(strtolower($q['status'])); ?>"><?php echo sanitize(ucfirst($q['status'])); ?></span>
          </div>
          <p class="thread-msg"><?php echo nl2br(sanitize($q['message'])); ?></p>
          <?php if (!empty($q['response'])): ?>
            <div class="thread-reply">
              <span class="who">SBK reply</span>
              <p><?php echo nl2br(sanitize($q['response'])); ?></p>
            </div>
          <?php endif; ?>
          <div class="thread-meta">
            Ref <?php echo sanitize($q['inquiry_number']); ?> · asked <?php echo formatDate($q['created_at']); ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section-head" style="margin-top:26px">
    <h2>Account</h2><span class="rule"></span>
  </div>
  <div class="panel" style="margin-bottom:44px">
    <table class="spec-table">
      <tr><th>Name</th><td><?php echo sanitize($client['name']); ?></td></tr>
      <tr><th>Email</th><td><?php echo sanitize($client['email']); ?></td></tr>
      <tr><th>Phone</th><td><?php echo sanitize($client['phone']); ?></td></tr>
      <tr><th>Member since</th><td><?php echo formatDate($client['created_at']); ?></td></tr>
    </table>
  </div>

</main>

<?php require_once 'includes/footer.php'; ?>
