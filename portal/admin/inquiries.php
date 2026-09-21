<?php
/**
 * SBK Auction — enquiries
 *
 * The list: who asked, about which vehicle, when, and where it stands.
 *
 * The whole conversation used to be here too - message, reply, and the form to
 * answer with, for every enquiry on the page at once. Fifty enquiries meant
 * fifty open letters stacked down one screen, and finding the one you had been
 * asked about meant scrolling past everybody else's. Reading and answering
 * happen on the enquiry's own page now; this one is for finding it.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requirePermission('enquiries.view');

$statuses = array('new', 'open', 'answered', 'closed');

$all    = getAllInquiries();
$filter = $_GET['status'] ?? '';
$rows   = $filter === ''
    ? $all
    : array_values(array_filter($all, function ($i) use ($filter) {
          return strtolower($i['status']) === $filter;
      }));

$counts = array();
foreach ($all as $i) {
    $s = strtolower($i['status']);
    $counts[$s] = ($counts[$s] ?? 0) + 1;
}

$page_title = 'Enquiries';
$active = 'inquiries';
require_once '_header.php';
?>

<h1>Enquiries</h1>
<p class="lede">Questions clients sent from a lot page. Open one to read it and
   reply — the reply appears on their dashboard.</p>

<?php if (!empty($_GET['saved'])): ?>
  <div class="alert alert-success">Enquiry updated.</div>
<?php endif; ?>

<div class="admin-toolbar">
  <a href="inquiries.php" class="btn <?php echo $filter === '' ? 'btn-dark' : 'btn-secondary'; ?>">
    All (<?php echo count($all); ?>)
  </a>
  <?php foreach ($statuses as $s): ?>
    <?php if (!empty($counts[$s])): ?>
      <a href="inquiries.php?status=<?php echo urlencode($s); ?>"
         class="btn <?php echo $filter === $s ? 'btn-dark' : 'btn-secondary'; ?>">
        <?php echo ucfirst($s); ?> (<?php echo $counts[$s]; ?>)
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<?php if (empty($rows)): ?>
  <div class="admin-card"><div class="admin-card-body"><p class="hint">No enquiries here yet.</p></div></div>
<?php else: ?>
<div class="admin-card">
  <div class="admin-card-body">
    <div class="table-responsive">
      <table class="dashboard-table enq-table">
        <thead>
          <tr>
            <th>Enquiry</th>
            <th>Client</th>
            <th>Vehicle</th>
            <th>Lot</th>
            <th>Asked</th>
            <th>Received</th>
            <th>Status</th>
            <th>&nbsp;</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i): ?>
            <tr>
              <td class="mono no-break"><?php echo sanitize($i['inquiry_number']); ?></td>
              <td>
                <b><?php echo sanitize($i['client_name']); ?></b>
                <?php if (!empty($i['client_email'])): ?>
                  <span class="enq-em"><?php echo sanitize($i['client_email']); ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php $v = trim($i['make'] . ' ' . $i['model']); ?>
                <?php if (!empty($i['car_row_id'])): ?>
                  <a href="../car-details.php?id=<?php echo intval($i['car_row_id']); ?>"
                     target="_blank" rel="noopener"><?php echo sanitize($v !== '' ? $v : 'the vehicle'); ?></a>
                <?php else: ?>
                  <?php echo sanitize($v !== '' ? $v : '—'); ?>
                <?php endif; ?>
              </td>
              <td class="mono no-break"><?php echo $i['lot_no'] ? sanitize($i['lot_no']) : '—'; ?></td>
              <td class="enq-snip">
                <?php
                  // Enough to recognise it by, never the whole letter - that is
                  // what the detail page is for.
                  $m = trim(preg_replace('/\s+/', ' ', (string) $i['message']));
                  echo sanitize(mb_strimwidth($m, 0, 70, '…'));
                ?>
              </td>
              <td class="no-break"><?php echo formatDate($i['created_at']); ?></td>
              <td>
                <span class="enq-status s-<?php echo sanitize(strtolower($i['status'])); ?>">
                  <?php echo ucfirst(sanitize($i['status'])); ?>
                </span>
              </td>
              <td>
                <a class="btn btn-secondary btn-xs"
                   href="inquiry.php?id=<?php echo intval($i['id']); ?>">
                  <?php echo staffCan('enquiries.reply') ? 'Open and reply' : 'Open'; ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once '_footer.php'; ?>
