<?php
/**
 * SBK Auction — admin bids.
 * Every bid a client places on the portal arrives here for the auction desk.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requirePermission('bids.view');

$statuses = bidStatuses();

/* Whose bids this person may see - null for everybody's. Worked out once and
   handed to every query on the page, so the list, the counts, the status form,
   the delete and the spreadsheet all narrow the same way. */
$scope = bidScopeStaffId();

$message = '';
$message_ok = true;

/* Removing a bid, which is final.

   Its own permission rather than bids.status: changing a status is a decision
   that can be changed back, and this is not. The screen asks before it posts,
   and what was removed is written to the staff log with the status it held, so
   there is a record of a row that no longer exists. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_bid'])) {
    $bid_id = intval($_POST['delete_bid']);
    if (!staffCan('bids.delete')) {
        $message = 'Your role can see bids but not delete them';
        $message_ok = false;
    } else {
        $was = bidRow($bid_id);
        if (deleteBid($bid_id, $scope)) {
            logStaffAction('bid.delete', $was ? (string) $was['status'] : '', $bid_id);
            $message = 'Bid deleted';
        } elseif ($scope !== null) {
            // deleteBid() carries the scope into the statement, so a row that
            // belongs to someone else simply does not match.
            $message = 'That bid is not yours to delete.';
            $message_ok = false;
        } else {
            $message = 'That bid was already gone';
            $message_ok = false;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bid_id'], $_POST['status'])) {
    $bid_id = intval($_POST['bid_id']);
    $status = trim($_POST['status']);
    $note   = trim($_POST['admin_note'] ?? '');

    // Status and note are two separate permissions, so what matters is not that
    // a form was submitted but which of the two values the submission actually
    // moves. A role allowed to annotate a bid may re-save it untouched status
    // and all; it may not quietly mark the bid won.
    $before  = bidRow($bid_id);

    /* A screen that shows only this person's own bids still receives whatever
       id is posted to it, and the check that matters is here rather than in the
       markup that drew the row. Refused in its own words: setting the status to
       nothing and letting it fail as "Unknown status" would be true but would
       read as a broken form rather than as a refusal. */
    $notMine = ($scope !== null
                && (!$before || (int) $before['staff_id'] !== (int) $scope));

    $touches = array();
    if (!$before || $status !== $before['status'])                { $touches[] = 'bids.status'; }
    if (!$before || $note   !== (string) $before['admin_note'])   { $touches[] = 'bids.note'; }

    $lacks = array_values(array_filter($touches, function ($p) { return !staffCan($p); }));

    if ($notMine) {
        $message = 'That bid was placed by somebody else. Your role only carries '
                 . 'your own bids — an administrator can add bids.view_all to it.';
        $message_ok = false;
    } elseif ($lacks) {
        $message = in_array('bids.status', $lacks, true)
            ? 'Your role can see bids but not change their status'
            : 'Your role cannot leave notes on bids';
        $message_ok = false;
    } elseif (!$touches) {
        $message = 'Nothing changed';
    } elseif (!in_array($status, $statuses, true)) {
        $message = 'Unknown status'; $message_ok = false;
    } elseif (updateBidStatus($bid_id, $status, $note)) {
        logStaffAction(in_array('bids.status', $touches, true) ? 'bid.status' : 'bid.note',
                       $status, $bid_id);
        $message = 'Bid updated';
    } else {
        $message = 'Could not update that bid'; $message_ok = false;
    }
}

/* THE DAY ON THE SCREEN - the owner's rule, 17 September 2026. The desk opens
   on today's bids; an older day is reached by changing the date box, one day at
   a time. Tomorrow "today" moves on by itself, because it is read from the
   clock and not stored anywhere. */
$day     = bidDay($_GET['day'] ?? '');
$isToday = ($day === date('Y-m-d'));
$dayLong = date('j M Y', strtotime($day));

$all    = getAllBids('', $scope, $day);
$filter = $_GET['status'] ?? '';
if ($filter !== '' && !in_array($filter, $statuses, true)) {
    $filter = '';
}

/* Every link on this screen keeps the day and the status, so stepping through
   the status chips never quietly throws you back to today. */
$bidQs = function ($over = array()) use ($day, $filter) {
    $a = array_merge(array('day' => $day, 'status' => $filter), $over);
    $a = array_filter($a, 'strlen');
    return '?' . http_build_query($a);
};
$bids   = $filter === ''
    ? $all
    : array_values(array_filter($all, function ($b) use ($filter) {
          return strtolower($b['status']) === $filter;
      }));

$counts = array();
foreach ($all as $b) {
    $s = strtolower($b['status']);
    $counts[$s] = ($counts[$s] ?? 0) + 1;
}

/* The one way the spreadsheet can fail is the server refusing it a scratch
   file to build the zip in. It says so here rather than sending half a file. */
if (($_GET['export'] ?? '') === 'failed') {
    $message = 'The spreadsheet could not be built just now. Try again in a moment.';
    $message_ok = false;
}

$page_title = 'Bids';
$active = 'bids';
require_once '_header.php';
?>

<h1>Bids</h1>
<p class="lede">
  <?php if ($scope === null): ?>
    Every bid placed from the auction list, by customers and by staff. Each one
    records the window it was checked against, so you can see the room you have.
  <?php else: ?>
    The bids you have placed yourself. Each one records the window it was checked
    against, so you can see the room you have. Your role does not carry
    <strong>bids.view_all</strong>, so other people's bids are not shown here.
  <?php endif; ?>
</p>

<?php if ($message): ?>
  <div class="alert <?php echo $message_ok ? 'alert-success' : 'alert-error'; ?>"><?php echo sanitize($message); ?></div>
<?php endif; ?>

<?php /* The day comes first, because it decides what the chips beside it are
           even counting. Changing the box submits on its own - a date picker
           with a Go button beside it is a button nobody should have to find. */ ?>
<div class="admin-toolbar">
  <form method="GET" class="bid-day">
    <label for="bidDay">Day</label>
    <input type="date" id="bidDay" name="day" class="input" value="<?php echo sanitize($day); ?>"
           max="<?php echo date('Y-m-d'); ?>" onchange="this.form.submit()">
    <?php if ($filter !== ''): ?>
      <input type="hidden" name="status" value="<?php echo sanitize($filter); ?>">
    <?php endif; ?>
    <noscript><button type="submit" class="btn btn-secondary">Show</button></noscript>
  </form>
  <?php if (!$isToday): ?>
    <a href="bids.php" class="btn btn-secondary">Today</a>
  <?php endif; ?>

  <a href="bids.php<?php echo sanitize($bidQs(array('status' => ''))); ?>"
     class="btn <?php echo $filter === '' ? 'btn-dark' : 'btn-secondary'; ?>">
    All (<?php echo count($all); ?>)
  </a>
  <?php foreach ($statuses as $s): ?>
    <?php if (!empty($counts[$s])): ?>
      <a href="bids.php<?php echo sanitize($bidQs(array('status' => $s))); ?>"
         class="btn <?php echo $filter === $s ? 'btn-dark' : 'btn-secondary'; ?>">
        <?php echo ucfirst($s); ?> (<?php echo $counts[$s]; ?>)
      </a>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php /* The spreadsheet, and only the accepted ones in it.

           That is the owner's instruction and not a default: a bid belongs in
           the file the desk works from once the desk has accepted it, and at no
           other time. The count is on the button so nobody has to open the file
           to find out it is empty. */ ?>
  <?php /* In a tab of its own, so the screen it was clicked from never goes
             anywhere. The file is a download and the browser closes the tab as
             soon as it starts, but the page behind it does not blink - the
             client read a plain link's navigation as the site hanging, and
             there is no reason to make them read anything at all. */ ?>
  <?php if (staffCan('bids.export')): ?>
    <?php /* ACCEPTED ONLY, for the day on the screen - the owner's rule, and
             the reason the status chip is NOT in this link. Whichever chip is
             pressed, the desk is handed the accepted bids of that date; the
             chips are for reading the screen.
             The day and the count are written on the button so nobody has to
             open the file to find out what came out of it. */ ?>
    <a href="bids-export.php<?php echo sanitize($bidQs(array('status' => ''))); ?>"
       class="btn btn-primary" style="margin-left:auto"
       target="_blank" rel="noopener" download>
      &#128202; Export accepted &mdash;
      <?php echo $isToday ? 'today' : sanitize($dayLong); ?>
      (<?php echo (int) ($counts['accepted'] ?? 0); ?>)
    </a>
  <?php endif; ?>
</div>

<?php /* The owner's own words for the day with nothing in it. It sits above
           the table, not inside it, because it is about the DAY and not about
           the rows a status chip happened to leave. */ ?>
<?php if (empty($all)): ?>
  <div class="day-note">
    <?php echo $isToday ? 'No bids on current date'
                        : 'No bids on ' . sanitize($dayLong); ?>
  </div>
<?php endif; ?>

<div class="admin-card">
  <?php if (empty($bids)): ?>
    <div class="admin-card-body"><p class="hint">
      <?php if (empty($all)): ?>
        Nothing was bid on this day. Pick another date above to look back.
      <?php else: ?>
        No <?php echo sanitize($filter); ?> bids on this day.
      <?php endif; ?>
    </p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="dashboard-table bids-table">
        <thead>
          <tr>
            <th>Bid</th><th>Client</th><th>Vehicle</th><th>Lot</th>
            <th>Amount</th><th>Placed</th><th>Status</th>
            <?php if (staffCan('bids.delete')): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bids as $b): ?>
            <tr>
              <td class="mono"><strong><?php echo sanitize($b['bid_number']); ?></strong></td>
              <td>
                <?php echo sanitize($b['client_name']); ?>
                <?php if (!empty($b['staff_id'])): ?>
                  <span class="chip-staff">staff</span>
                <?php endif; ?>
                <br>
                <span style="color:var(--dead);font-size:12px"><?php echo sanitize($b['client_email']); ?></span>
              </td>
              <td>
                <?php if (!empty($b['car_row_id'])): ?>
                  <a href="../car-details.php?id=<?php echo intval($b['car_row_id']); ?>" target="_blank" rel="noopener">
                    <?php echo sanitize(trim($b['make'] . ' ' . $b['model'])); ?>
                  </a>
                <?php else: ?>
                  <?php echo sanitize(trim($b['make'] . ' ' . $b['model'])); ?>
                <?php endif; ?>
                <?php if (!empty($b['year'])): ?>
                  <span style="color:var(--dead)">· <?php echo intval($b['year']); ?></span>
                <?php endif; ?>
              </td>
              <td class="mono no-break"><?php echo sanitize($b['lot_no']); ?></td>
              <td class="no-break"><strong>¥<?php echo number_format($b['amount']); ?></strong></td>
              <td class="no-break"><?php echo formatDate($b['placed_at']); ?></td>
              <td>
                <?php $mayStatus = staffCan('bids.status'); $mayNote = staffCan('bids.note'); ?>
                <?php if (!$mayStatus && !$mayNote): ?>
                  <span class="ro-value"><?php echo ucfirst(sanitize($b['status'])); ?></span>
                  <?php if (!empty($b['admin_note'])): ?>
                    <span class="ro-note"><?php echo sanitize($b['admin_note']); ?></span>
                  <?php endif; ?>
                <?php else: ?>
                <form method="POST" class="bid-admin-form">
                  <input type="hidden" name="bid_id" value="<?php echo intval($b['id']); ?>">
                  <?php if ($mayStatus): ?>
                    <select name="status" class="select">
                      <?php foreach ($statuses as $s): ?>
                        <option value="<?php echo sanitize($s); ?>" <?php echo strtolower($b['status']) === $s ? 'selected' : ''; ?>>
                          <?php echo ucfirst($s); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <span class="ro-value"><?php echo ucfirst(sanitize($b['status'])); ?></span>
                    <input type="hidden" name="status" value="<?php echo sanitize($b['status']); ?>">
                  <?php endif; ?>
                  <?php if ($mayNote): ?>
                    <input type="text" name="admin_note" class="input" placeholder="note"
                           value="<?php echo sanitize($b['admin_note'] ?? ''); ?>">
                  <?php else: ?>
                    <?php if (!empty($b['admin_note'])): ?>
                      <span class="ro-note"><?php echo sanitize($b['admin_note']); ?></span>
                    <?php endif; ?>
                    <input type="hidden" name="admin_note" value="<?php echo sanitize($b['admin_note'] ?? ''); ?>">
                  <?php endif; ?>
                  <button type="submit" class="btn btn-secondary">Save</button>
                </form>
                <?php endif; ?>
              </td>
              <?php if (staffCan('bids.delete')): ?>
                <td>
                  <?php /* Its own form, so a delete can never ride along with a
                           status the reader was in the middle of changing. */ ?>
                  <form method="POST" class="bid-del-form" onsubmit="return confirm(
                        'Delete bid <?php echo sanitize($b['bid_number']); ?>?\n\n<?php
                        echo sanitize(trim($b['client_name'] . ' \u00b7 ' . trim($b['make'] . ' ' . $b['model']))); ?>\n\nThis cannot be undone.');">
                    <input type="hidden" name="delete_bid" value="<?php echo intval($b['id']); ?>">
                    <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once '_footer.php'; ?>
