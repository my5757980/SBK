<?php
/**
 * SBK Auction — admin overview
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requireAdmin();

global $conn;

$stats = array();
// Stock we hold, not rows we hold. Counting the table read 303,974, of which
// 91,400 were vehicles the feed has dropped and 7,900 were already sold - a
// number three times the truth, on the tile a person looks at first.
// "Available" has to mean the same thing here as everywhere else: lots still to
// be auctioned. Counting every row marked available swept in ~25,000 whose
// auction day had already gone, so this screen read 83,911 while the portal
// showed 58,442 — the gap the owner spotted against the source.
$stats['available_cars'] = $conn->query(
    "SELECT COUNT(*) c FROM cars WHERE " . currentLotsSql('cars'))->fetch_assoc()['c'];

// Counts of sold vehicles, and of auctions we never got a result for, used to
// sit here. The portal has two sections; a vehicle in neither of them is not
// stock, and putting its number on the overview only ever raised the question
// of where it could be seen. The rows are still in the table.
$stats['total_clients']  = $conn->query("SELECT COUNT(*) c FROM clients WHERE is_active = 1")->fetch_assoc()['c'];
$stats['total_orders']   = $conn->query("SELECT COUNT(*) c FROM orders")->fetch_assoc()['c'];
$stats['pending_orders'] = $conn->query("SELECT COUNT(*) c FROM orders WHERE status = 'pending'")->fetch_assoc()['c'];
$stats['total_bids']     = $conn->query("SELECT COUNT(*) c FROM bids")->fetch_assoc()['c'];
$stats['new_bids']       = $conn->query("SELECT COUNT(*) c FROM bids WHERE status = 'placed'")->fetch_assoc()['c'];
$stats['bid_value']      = $conn->query("SELECT SUM(amount) t FROM bids WHERE status IN ('placed','under review','accepted','won')")->fetch_assoc()['t'] ?? 0;

$rev = $conn->query("
    SELECT SUM(amount) t FROM orders
    WHERE status IN ('confirmed','paid','shipped','delivered','completed')
")->fetch_assoc()['t'];
$stats['revenue'] = $rev ?? 0;

$pipeline = $conn->query("SELECT SUM(amount) t FROM orders WHERE status = 'pending'")->fetch_assoc()['t'];
$stats['pipeline'] = $pipeline ?? 0;

$last_sync = $conn->query("SELECT MAX(last_updated) t FROM cars")->fetch_assoc()['t'];

$recent_orders = array_slice(getAllOrders(), 0, 8);
$recent_bids   = array_slice(getAllBids(), 0, 8);

/* ===================================================================== flow
   How far customers get.

   Counted as people, not events: a customer who sent nine enquiries is one
   customer who enquired. Counting events would make one busy afternoon look
   like a crowd, which is the question this chart exists to answer honestly.

   Each stage counts everyone who has reached it, so the bars only ever get
   shorter left to right - that is what makes the drop between two of them mean
   something. */
$funnel = array();
$funnel['registered'] = (int) $conn->query(
    "SELECT COUNT(*) c FROM clients")->fetch_assoc()['c'];
$funnel['enquired']   = (int) $conn->query(
    "SELECT COUNT(DISTINCT client_id) c FROM inquiries")->fetch_assoc()['c'];
$funnel['bid']        = (int) $conn->query(
    "SELECT COUNT(DISTINCT client_id) c FROM bids")->fetch_assoc()['c'];
$funnel['ordered']    = (int) $conn->query(
    "SELECT COUNT(DISTINCT client_id) c FROM orders")->fetch_assoc()['c'];

/* ================================================================= activity
   Thirty days, three things people do. Days with nothing on them stay in rather
   than being dropped, so a quiet week looks quiet instead of looking like a
   shorter week. */
$days = array();
for ($i = 29; $i >= 0; $i--) {
    $days[date('Y-m-d', strtotime("-$i day"))] = array(0, 0, 0);
}
$since = date('Y-m-d', strtotime('-29 day'));
$feeds = array(
    0 => "SELECT DATE(created_at) d, COUNT(*) n FROM clients   WHERE created_at >= ? GROUP BY d",
    1 => "SELECT DATE(created_at) d, COUNT(*) n FROM inquiries WHERE created_at >= ? GROUP BY d",
    2 => "SELECT DATE(placed_at)  d, COUNT(*) n FROM bids      WHERE placed_at  >= ? GROUP BY d",
);
foreach ($feeds as $slot => $sql) {
    $st = $conn->prepare($sql);
    if (!$st) { continue; }
    $st->bind_param('s', $since);
    $st->execute();
    $r = $st->get_result();
    while ($r && $w = $r->fetch_assoc()) {
        if (isset($days[$w['d']])) { $days[$w['d']][$slot] = (int) $w['n']; }
    }
    $st->close();
}

/* ============================================================== bid states */
$bid_states = array();
foreach (array('placed', 'under review', 'accepted', 'won', 'rejected', 'lost') as $s) {
    $st = $conn->prepare("SELECT COUNT(*) c FROM bids WHERE status = ?");
    $st->bind_param('s', $s);
    $st->execute();
    $bid_states[$s] = (int) $st->get_result()->fetch_assoc()['c'];
    $st->close();
}

/* ================================================================ timeline
   One list of everything customers have done lately, in the order it happened.
   Four tables, one story: with a handful of customers this says more than any
   of the charts, and it keeps saying it when there are a thousand. */
$timeline = array();
$q = $conn->query("
    SELECT 'registered' k, c.name who, '' what, c.created_at at, c.id ref
      FROM clients c ORDER BY c.created_at DESC LIMIT 12");
while ($q && $w = $q->fetch_assoc()) { $timeline[] = $w; }

$q = $conn->query("
    SELECT 'enquired' k, cl.name who,
           CONCAT_WS(' ', i.make, i.model) what, i.created_at at, i.id ref
      FROM inquiries i JOIN clients cl ON cl.id = i.client_id
     ORDER BY i.created_at DESC LIMIT 12");
while ($q && $w = $q->fetch_assoc()) { $timeline[] = $w; }

$q = $conn->query("
    SELECT 'bid' k, cl.name who,
           CONCAT_WS(' ', c.make, c.model, CONCAT('· ¥', FORMAT(b.amount, 0))) what,
           b.placed_at at, b.id ref
      FROM bids b JOIN clients cl ON cl.id = b.client_id
      LEFT JOIN cars c ON c.car_id = b.car_id
     ORDER BY b.placed_at DESC LIMIT 12");
while ($q && $w = $q->fetch_assoc()) { $timeline[] = $w; }

$q = $conn->query("
    SELECT 'ordered' k, cl.name who,
           CONCAT('¥', FORMAT(o.amount, 0)) what, o.order_date at, o.id ref
      FROM orders o JOIN clients cl ON cl.id = o.client_id
     ORDER BY o.order_date DESC LIMIT 12");
while ($q && $w = $q->fetch_assoc()) { $timeline[] = $w; }

usort($timeline, function ($a, $b) { return strcmp($b['at'], $a['at']); });
$timeline = array_slice($timeline, 0, 14);

/* =============================================================== customers
   Who bids most, and what it is worth. The reference dashboard the client
   showed calls this "Bid Activity" and gives each bidder a success rate; ours
   shows the same shape from what we actually record - a success rate needs
   won-and-lost outcomes, and the feed has not given us one yet. */
$top_clients = array();
$q = $conn->query("
    SELECT cl.id, cl.name, cl.email,
           COUNT(b.id) bids, COALESCE(SUM(b.amount), 0) total,
           SUM(b.status = 'won') won
      FROM clients cl JOIN bids b ON b.client_id = cl.id
     GROUP BY cl.id ORDER BY bids DESC, total DESC LIMIT 6");
while ($q && $w = $q->fetch_assoc()) { $top_clients[] = $w; }

/* =================================================================== staff
   Who is on the desk, and what they have been doing.

   Nothing recorded who acted until now - staff_activity is new, so this fills
   from today forward rather than showing a history that was never kept. Saying
   so on the screen is better than an empty table that looks broken. */
$staff_rows = array();
$q = $conn->query("
    SELECT a.id, a.username, a.name, a.is_active, a.last_login,
           r.label role_label,
           (SELECT COUNT(*) FROM staff_activity s
             WHERE s.staff_id = a.id AND s.at >= NOW() - INTERVAL 30 DAY) acts
      FROM admins a LEFT JOIN roles r ON r.id = a.role_id
     ORDER BY acts DESC, a.id ASC");
while ($q && $w = $q->fetch_assoc()) { $staff_rows[] = $w; }

$staff_counts = array(
    'members' => (int) $conn->query("SELECT COUNT(*) c FROM admins WHERE is_active = 1")->fetch_assoc()['c'],
    'roles'   => (int) $conn->query("SELECT COUNT(*) c FROM roles")->fetch_assoc()['c'],
    'perms'   => count(allPermissionKeys()),
    'acts'    => (int) ($conn->query(
        "SELECT COUNT(*) c FROM staff_activity WHERE at >= NOW() - INTERVAL 30 DAY")
        ->fetch_assoc()['c'] ?? 0),
);

// What the desk has been doing, by kind of action.
$staff_kinds = array();
$q = $conn->query("
    SELECT action, COUNT(*) n FROM staff_activity
     WHERE at >= NOW() - INTERVAL 30 DAY GROUP BY action ORDER BY n DESC");
while ($q && $w = $q->fetch_assoc()) { $staff_kinds[] = array($w['action'], (int) $w['n']); }

/* ============================================== enquiries, as a whole */
$enq_states = array();
foreach (array('open', 'answered', 'closed') as $st) {
    $q = $conn->prepare("SELECT COUNT(*) c FROM inquiries WHERE status = ?");
    $q->bind_param('s', $st);
    $q->execute();
    $enq_states[] = array(ucfirst($st), (int) $q->get_result()->fetch_assoc()['c']);
    $q->close();
}

/* ====================================================== every customer
   One row a customer, the whole of what they have done. The charts above say
   what the crowd does; this says what each person did, which is the question
   actually asked at a desk - "what has this one been up to". */
$journeys = array();
$q = $conn->query("
    SELECT cl.id, cl.name, cl.email, cl.created_at joined,
           (SELECT COUNT(*) FROM inquiries i WHERE i.client_id = cl.id) enquiries,
           (SELECT COUNT(*) FROM bids b     WHERE b.client_id = cl.id) bids,
           (SELECT COALESCE(SUM(b.amount),0) FROM bids b WHERE b.client_id = cl.id) offered,
           (SELECT COUNT(*) FROM orders o   WHERE o.client_id = cl.id) orders,
           GREATEST(
             COALESCE((SELECT MAX(i.created_at) FROM inquiries i WHERE i.client_id = cl.id), cl.created_at),
             COALESCE((SELECT MAX(b.placed_at)  FROM bids b      WHERE b.client_id = cl.id), cl.created_at)
           ) last_seen
      FROM clients cl
     ORDER BY last_seen DESC LIMIT 10");
while ($q && $w = $q->fetch_assoc()) { $journeys[] = $w; }

/* =========================================== how much each role may do */
// Counted the way staffCan() counts, which is the way the Roles screen shows
// it: a role holding a retired key holds everything that key stood for. The
// raw row count said 8 where Roles said 11, and two screens disagreeing about
// one number is worse than either of them being slightly wrong.
require_once '_roles.php';
$role_reach = array();
foreach (allRoles($conn) as $r) {
    $role_reach[] = array($r['label'], count($r['perms']));
}

/* ============================================ every customer's bids
   Not just how many each has placed but how theirs have gone - the two
   questions are asked together at a desk and answering them in one row saves
   reading two charts against each other. */
$bid_flow_states = array('placed', 'under review', 'accepted', 'won', 'rejected', 'lost');
$bid_flow = array();
$q = $conn->query("
    SELECT cl.id, cl.name, b.status, COUNT(*) n, SUM(b.amount) amt
      FROM bids b JOIN clients cl ON cl.id = b.client_id
     GROUP BY cl.id, b.status");
$acc = array();
while ($q && $w = $q->fetch_assoc()) {
    $id = (int) $w['id'];
    if (!isset($acc[$id])) {
        $acc[$id] = array('name' => $w['name'], 'v' => array_fill(0, 6, 0), 'amt' => 0);
    }
    $k = array_search(strtolower($w['status']), $bid_flow_states, true);
    if ($k !== false) { $acc[$id]['v'][$k] = (int) $w['n']; }
    $acc[$id]['amt'] += (float) $w['amt'];
}
foreach ($acc as $a) {
    $bid_flow[] = array($a['name'], $a['v'], '· ¥' . number_format($a['amt']));
}
usort($bid_flow, function ($x, $y) { return array_sum($y[1]) - array_sum($x[1]); });
$bid_flow = array_slice($bid_flow, 0, 12);

require_once '_charts.php';

$page_title = 'Overview';
$active = 'dashboard';
require_once '_header.php';
?>

<h1>Overview</h1>
<p class="lede">
  Inventory syncs automatically from the Japanese auction feed.
  <?php if ($last_sync): ?>Last update <?php echo date('M j, Y H:i', strtotime($last_sync)); ?>.<?php endif; ?>
</p>
<?php // The IDs both feeds sign in with: green while they fetch, red when one is
      // signed out, refused or silent. Hover a signal for what it means. ?>
<div class="src-sig-row">
  <?php echo sourceSignal('auction'); ?>
  <?php echo sourceSignal('statistics'); ?>
</div>

<div class="kpi-row">
  <?php // One section now. The Fixed price tile and the "Both together" tile
        // beside it went on the owner's order of 13 September 2026 - with one
        // section the two tiles only repeated the auction's number. ?>
  <div class="kpi accent">
    <div class="k">Auction</div>
    <div class="v" data-live="auction"><?php echo number_format($stats['available_cars']); ?></div>
    <div class="s">still to be auctioned</div>
  </div>
  <?php // Sold and awaiting-result tiles removed: a vehicle no longer offered is
        // not stock, and counting it on the overview invited the question
        // "where is that, then?" every time. ?>
  <?php // Past sales, beside the lots still to be auctioned: the two halves of
        // what the portal knows about a car - what it is being offered at, and
        // what cars like it have been going for. The figure keeps itself up to
        // date the same way the others do, through api/counts.php. ?>
  <div class="kpi">
    <div class="k">Statistics</div>
    <div class="v" data-live="statistics"><?php echo number_format(statsCount()); ?></div>
    <div class="s">past auction results</div>
  </div>
  <div class="kpi">
    <div class="k">Clients</div>
    <div class="v" data-live="clients"><?php echo number_format($stats['total_clients']); ?></div>
  </div>
</div>

<div class="kpi-row">
  <div class="kpi">
    <div class="k">Bids</div>
    <div class="v" data-live="bids"><?php echo number_format($stats['total_bids']); ?></div>
  </div>
  <div class="kpi <?php echo $stats['new_bids'] > 0 ? 'accent' : ''; ?>">
    <div class="k">Awaiting review</div>
    <div class="v" data-live="bids_new"><?php echo number_format($stats['new_bids']); ?></div>
  </div>
  <div class="kpi">
    <div class="k">Bid value</div>
    <div class="v"><?php echo formatPrice($stats['bid_value'], 'yen'); ?></div>
  </div>
</div>

<h2 class="sec-h">Customers</h2>

<div class="viz-row viz-row-3">
  <div class="admin-card viz-card">
    <div class="admin-card-head"><h2>Every bid we hold</h2></div>
    <div class="admin-card-body">
      <?php
        $ring = array();
        foreach ($bid_states as $k => $v) { $ring[] = array(ucfirst($k), $v); }
        echo vizDonut($ring, 'bids');
        echo vizTable(array('Status', 'Bids'),
            array_map(function ($r) { return array($r[0], number_format($r[1])); }, $ring));
      ?>
    </div>
  </div>

  <div class="admin-card viz-card">
    <div class="admin-card-head"><h2>Every enquiry we hold</h2></div>
    <div class="admin-card-body">
      <?php
        echo vizDonut($enq_states, 'enquiries');
        echo vizTable(array('Status', 'Enquiries'),
            array_map(function ($r) { return array($r[0], number_format($r[1])); }, $enq_states));
      ?>
    </div>
  </div>

  <?php // A stock ring sat here: two slices, auction against fixed price. Two
        // slices is not a ring, it is a pair of numbers wearing one - and those
        // two numbers are already the first two tiles at the top of this page.
        // Removed rather than kept for the sake of a third circle. ?>
</div>

<div class="viz-row">
  <div class="admin-card viz-card">
    <div class="admin-card-head">
      <h2>How far customers get</h2>
      <span class="spacer"></span>
      <span class="page-info">people, not events</span>
    </div>
    <div class="admin-card-body">
      <?php
        $reg = max(1, $funnel['registered']);
        $pc = function ($n) use ($reg) { return '· ' . round($n * 100 / $reg) . '%'; };
        echo vizFunnel(array(
            array('Registered', $funnel['registered'], ''),
            array('Enquired',   $funnel['enquired'],   $pc($funnel['enquired'])),
            array('Bid',        $funnel['bid'],        $pc($funnel['bid'])),
            array('Ordered',    $funnel['ordered'],    $pc($funnel['ordered'])),
        ));
        echo vizTable(array('Stage', 'Customers', 'Of registered'), array(
            array('Registered', number_format($funnel['registered']), '100%'),
            array('Enquired',   number_format($funnel['enquired']),   $pc($funnel['enquired'])),
            array('Bid',        number_format($funnel['bid']),        $pc($funnel['bid'])),
            array('Ordered',    number_format($funnel['ordered']),    $pc($funnel['ordered'])),
        ));
      ?>
    </div>
  </div>

  <div class="admin-card viz-card">
    <div class="admin-card-head">
      <h2>Who bids most</h2>
      <span class="spacer"></span>
      <a href="clients.php" class="btn btn-secondary btn-xs">All clients</a>
    </div>
    <div class="admin-card-body">
      <?php if (!$top_clients): ?>
        <p class="hint">No bids yet. Customers appear here as they start bidding.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="dashboard-table mini-table">
            <thead><tr><th>Customer</th><th>Bids</th><th>Won</th><th>Total offered</th></tr></thead>
            <tbody>
              <?php foreach ($top_clients as $c): ?>
                <tr>
                  <td>
                    <b><?php echo sanitize($c['name']); ?></b>
                    <span class="enq-em"><?php echo sanitize($c['email']); ?></span>
                  </td>
                  <td class="no-break"><?php echo number_format($c['bids']); ?></td>
                  <td class="no-break"><?php echo number_format($c['won']); ?></td>
                  <td class="no-break"><b>&yen;<?php echo number_format($c['total']); ?></b></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card-head">
    <h2>Activity, last 30 days</h2>
    <span class="spacer"></span>
    <?php echo vizLegend(array('Registrations', 'Enquiries', 'Bids')); ?>
  </div>
  <div class="admin-card-body">
    <?php
      echo vizWave($days, array('Registrations', 'Enquiries', 'Bids'));
      $trows = array();
      foreach (array_reverse($days, true) as $d => $v) {
          if (!array_sum($v)) { continue; }
          $trows[] = array(date('j M Y', strtotime($d)), $v[0], $v[1], $v[2]);
      }
      if (!$trows) { $trows[] = array('—', 0, 0, 0); }
      echo vizTable(array('Day', 'Registrations', 'Enquiries', 'Bids'), $trows);
    ?>
  </div>
</div>

<!-- ========================================================== customers -->

<!-- =========================================================== timeline -->
<div class="admin-card">
  <div class="admin-card-head">
    <h2>What customers have been doing</h2>
    <span class="spacer"></span>
    <a href="clients.php" class="btn btn-secondary btn-xs">All clients</a>
  </div>
  <div class="admin-card-body">
    <?php if (!$timeline): ?>
      <p class="hint">Nothing yet. Registrations, enquiries, bids and orders all appear here.</p>
    <?php else: ?>
      <ol class="tl">
        <?php foreach ($timeline as $t): ?>
          <?php
            $label = array(
                'registered' => 'registered',
                'enquired'   => 'asked about',
                'bid'        => 'bid on',
                'ordered'    => 'ordered',
            )[$t['k']] ?? $t['k'];
            $href = array(
                'registered' => 'clients.php',
                'enquired'   => 'inquiry.php?id=' . (int) $t['ref'],
                'bid'        => 'bids.php',
                'ordered'    => 'orders.php',
            )[$t['k']] ?? '#';
          ?>
          <li class="tl-item tl-<?php echo sanitize($t['k']); ?>">
            <span class="tl-dot" aria-hidden="true"></span>
            <span class="tl-body">
              <b><?php echo sanitize($t['who']); ?></b>
              <span class="tl-verb"><?php echo sanitize($label); ?></span>
              <?php if (trim((string) $t['what']) !== ''): ?>
                <span class="tl-what"><?php echo sanitize(trim($t['what'])); ?></span>
              <?php endif; ?>
            </span>
            <span class="tl-when"><?php echo $t['at'] ? formatDate($t['at']) : ''; ?></span>
            <a class="tl-go" href="<?php echo sanitize($href); ?>">Open</a>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card-head">
    <h2>Every customer, start to finish</h2>
    <span class="spacer"></span>
    <a href="clients.php" class="btn btn-secondary btn-xs">All clients</a>
  </div>
  <div class="admin-card-body">
    <?php if (!$journeys): ?>
      <p class="hint">No customers yet.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="dashboard-table mini-table journey-table">
          <thead>
            <tr>
              <th>Customer</th><th>Joined</th><th>Enquiries</th><th>Bids</th>
              <th>Offered</th><th>Orders</th><th>How far</th><th>Last seen</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($journeys as $j): ?>
              <?php
                // Four steps, filled in as far as this person has come. The
                // dots say at a glance what four numbers say on inspection.
                $reached = 1
                    + ($j['enquiries'] > 0 ? 1 : 0)
                    + ($j['bids'] > 0 ? 1 : 0)
                    + ($j['orders'] > 0 ? 1 : 0);
              ?>
              <tr>
                <td>
                  <b><?php echo sanitize($j['name']); ?></b>
                  <span class="enq-em"><?php echo sanitize($j['email']); ?></span>
                </td>
                <td class="no-break"><?php echo formatDate($j['joined']); ?></td>
                <td class="no-break"><?php echo number_format($j['enquiries']); ?></td>
                <td class="no-break"><?php echo number_format($j['bids']); ?></td>
                <td class="no-break"><b>&yen;<?php echo number_format($j['offered']); ?></b></td>
                <td class="no-break"><?php echo number_format($j['orders']); ?></td>
                <td class="no-break">
                  <span class="steps" title="<?php echo $reached; ?> of 4: registered, enquired, bid, ordered">
                    <?php for ($k = 1; $k <= 4; $k++): ?>
                      <i class="<?php echo $k <= $reached ? 'on' : ''; ?>"></i>
                    <?php endfor; ?>
                  </span>
                </td>
                <td class="no-break"><?php echo formatDate($j['last_seen']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================== staff -->
<h2 class="sec-h">The desk</h2>

<div class="kpi-row">
  <div class="kpi">
    <div class="k">Staff</div>
    <div class="v"><?php echo number_format($staff_counts['members']); ?></div>
    <div class="s">logins that can sign in</div>
  </div>
  <div class="kpi">
    <div class="k">Roles</div>
    <div class="v"><?php echo number_format($staff_counts['roles']); ?></div>
    <div class="s"><a href="roles.php">manage</a></div>
  </div>
  <div class="kpi">
    <div class="k">Permissions</div>
    <div class="v"><?php echo number_format($staff_counts['perms']); ?></div>
    <div class="s"><a href="permissions.php">what each role may do</a></div>
  </div>
  <div class="kpi <?php echo $staff_counts['acts'] > 0 ? 'accent' : ''; ?>">
    <div class="k">Actions</div>
    <div class="v"><?php echo number_format($staff_counts['acts']); ?></div>
    <div class="s">by the desk, last 30 days</div>
  </div>
</div>

<div class="viz-row">
  <div class="admin-card viz-card">
    <div class="admin-card-head">
      <h2>Who is on the desk</h2>
      <span class="spacer"></span>
      <a href="staff.php" class="btn btn-secondary btn-xs">Manage staff</a>
    </div>
    <div class="admin-card-body">
      <div class="table-responsive">
        <table class="dashboard-table mini-table">
          <thead><tr><th>Person</th><th>Role</th><th>Last signed in</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($staff_rows as $p): ?>
              <tr>
                <td>
                  <b><?php echo sanitize($p['name'] ?: $p['username']); ?></b>
                  <span class="enq-em mono"><?php echo sanitize($p['username']); ?></span>
                </td>
                <td class="no-break">
                  <span class="ro-value"><?php echo sanitize($p['role_label'] ?: '—'); ?></span>
                </td>
                <td class="no-break">
                  <?php echo $p['last_login'] ? formatDate($p['last_login']) : 'never'; ?>
                </td>
                <td class="no-break">
                  <?php if (!$p['is_active']): ?>
                    <span class="ro-note">switched off</span>
                  <?php else: ?>
                    <?php echo number_format($p['acts']); ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="admin-card viz-card">
    <div class="admin-card-head">
      <h2>What the desk has been doing</h2>
      <span class="spacer"></span>
      <span class="page-info">last 30 days</span>
    </div>
    <div class="admin-card-body">
      <?php if (!$staff_kinds): ?>
        <p class="hint">Nothing recorded yet. Who does what has only been written
           down since today — replies, bid decisions, order moves and role changes
           will appear here as they happen.</p>
        <div class="reach">
          <h3>How far each role reaches</h3>
          <?php echo vizMeters($role_reach, count(allPermissionKeys())); ?>
        </div>
      <?php else: ?>
        <?php
          $names = array(
              'bid.status'       => 'Bid status changed',
              'bid.note'         => 'Note left on a bid',
              'enquiry.open'     => 'Enquiry opened',
              'enquiry.reply'    => 'Enquiry answered',
              'enquiry.status'   => 'Enquiry status changed',
              'order.status'     => 'Order status changed',
              'staff.create'     => 'Staff login created',
              'staff.edit'       => 'Staff login changed',
              'staff.password'   => 'Password set',
              'role.create'      => 'Role added',
              'role.rename'      => 'Role renamed',
              'role.delete'      => 'Role removed',
              'role.permissions' => 'Permissions changed',
          );
          $rows = array();
          foreach ($staff_kinds as $k) { $rows[] = array($names[$k[0]] ?? $k[0], $k[1]); }
          echo vizBars($rows);
          echo vizTable(array('Action', 'Times'),
              array_map(function ($r) { return array($r[0], number_format($r[1])); }, $rows));
        ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<h2 class="sec-h">Bids</h2>

<div class="admin-card">
  <div class="admin-card-head">
    <h2>Every customer's bids, and how they went</h2>
    <span class="spacer"></span>
    <?php echo vizLegend(array('Placed', 'Under review', 'Accepted', 'Won', 'Rejected', 'Lost')); ?>
  </div>
  <div class="admin-card-body">
    <?php
      echo vizStackRows($bid_flow, $bid_flow_states);
      $trows = array();
      foreach ($bid_flow as $r) {
          $trows[] = array_merge(array($r[0], array_sum($r[1])), $r[1], array(ltrim($r[2], '· ')));
      }
      if (!$trows) { $trows[] = array('—', 0, 0, 0, 0, 0, 0, 0, '¥0'); }
      echo vizTable(
          array_merge(array('Customer', 'Bids'), array_map('ucfirst', $bid_flow_states), array('Offered')),
          $trows);
    ?>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card-head">
    <h2>Recent bids</h2>
    <span class="spacer"></span>
    <?php if ($stats['new_bids'] > 0): ?>
      <span class="status-badge status-open"><?php echo $stats['new_bids']; ?> awaiting review</span>
    <?php endif; ?>
    <a href="bids.php" class="btn btn-secondary">All bids</a>
  </div>

  <?php if (empty($recent_bids)): ?>
    <div class="admin-card-body">
      <p class="hint">No bids yet. They'll appear here as clients bid on lots.</p>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="dashboard-table">
        <thead>
          <tr><th>Bid</th><th>Client</th><th>Vehicle</th><th>Lot</th><th>Amount</th><th>Status</th><th>Placed</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recent_bids as $b): ?>
            <tr>
              <td class="mono"><strong><?php echo sanitize($b['bid_number']); ?></strong></td>
              <td><?php echo sanitize($b['client_name']); ?></td>
              <td><?php echo sanitize(trim($b['make'] . ' ' . $b['model'])); ?></td>
              <td class="mono"><?php echo sanitize($b['lot_no']); ?></td>
              <td><strong>¥<?php echo number_format($b['amount']); ?></strong></td>
              <td><span class="status-badge status-<?php echo sanitize(str_replace(' ', '-', strtolower($b['status']))); ?>"><?php echo sanitize(ucfirst($b['status'])); ?></span></td>
              <td><?php echo formatDate($b['placed_at']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="kpi-row" style="margin-top:18px">
  <div class="kpi">
    <div class="k">Orders</div>
    <div class="v"><?php echo number_format($stats['total_orders']); ?></div>
  </div>
  <div class="kpi">
    <div class="k">Pending orders</div>
    <div class="v"><?php echo number_format($stats['pending_orders']); ?></div>
  </div>
  <div class="kpi">
    <div class="k">Pipeline value</div>
    <div class="v"><?php echo formatPrice($stats['pipeline'], 'yen'); ?></div>
  </div>
  <div class="kpi">
    <div class="k">Confirmed revenue</div>
    <div class="v"><?php echo formatPrice($stats['revenue'], 'yen'); ?></div>
  </div>
</div>

<h2 class="sec-h">Orders</h2>

<div class="admin-card">
  <div class="admin-card-head">
    <h2>Recent orders</h2>
    <span class="spacer"></span>
    <a href="orders.php" class="btn btn-secondary">All orders</a>
  </div>

  <?php if (empty($recent_orders)): ?>
    <div class="admin-card-body">
      <p class="hint">No orders yet. They'll appear here as clients place them.</p>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="dashboard-table">
        <thead>
          <tr><th>Order</th><th>Client</th><th>Vehicle</th><th>Amount</th><th>Status</th><th>Placed</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recent_orders as $o): ?>
            <tr>
              <td class="mono"><strong><?php echo sanitize($o['order_number']); ?></strong></td>
              <td>
                <?php echo sanitize($o['name']); ?><br>
                <span style="color:var(--dead);font-size:12px"><?php echo sanitize($o['email']); ?></span>
              </td>
              <td>
                <?php echo sanitize(trim($o['make'] . ' ' . $o['model'])); ?>
                <?php if (!empty($o['year'])): ?>
                  <span style="color:var(--dead)">· <?php echo intval($o['year']); ?></span>
                <?php endif; ?>
              </td>
              <td><?php echo formatPrice($o['amount'], $o['currency']); ?></td>
              <td><span class="status-badge status-<?php echo sanitize(strtolower($o['status'])); ?>"><?php echo sanitize(ucfirst($o['status'])); ?></span></td>
              <td><?php echo formatDate($o['order_date']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once '_footer.php'; ?>
