<?php
/**
 * SBK Auction — admin orders
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requirePermission('orders.view');

global $conn;

$message = '';
$message_ok = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !staffCan('orders.status')) {
    // The screen used to take any POST from anyone who could open it, so a role
    // granted orders.view alone could move an order to "paid". The control is
    // hidden from such a role below, but hiding a control is not a check.
    $message = 'Your role can see orders but not change their status'; $message_ok = false;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    $order_id = intval($_POST['order_id']);
    $status   = trim($_POST['status']);
    $allowed  = array('pending', 'confirmed', 'paid', 'shipped', 'delivered', 'completed', 'cancelled');

    if (!in_array($status, $allowed, true)) {
        $message = 'Unknown status'; $message_ok = false;
    } elseif (updateOrderStatus($order_id, $status)) {
        logStaffAction('order.status', $status, $order_id);
        $message = 'Order status updated';
    } else {
        $message = 'Could not update that order'; $message_ok = false;
    }
}

$orders = getAllOrders();

$filter = $_GET['status'] ?? '';
if ($filter !== '') {
    $orders = array_values(array_filter($orders, function ($o) use ($filter) {
        return strtolower($o['status']) === $filter;
    }));
}

$counts = array();
foreach (getAllOrders() as $o) {
    $s = strtolower($o['status']);
    $counts[$s] = ($counts[$s] ?? 0) + 1;
}

$page_title = 'Orders';
$active = 'orders';
require_once '_header.php';
?>

<h1>Orders</h1>
<p class="lede">Review and move orders through fulfilment.</p>

<?php if ($message): ?>
  <div class="alert <?php echo $message_ok ? 'alert-success' : 'alert-error'; ?>"><?php echo sanitize($message); ?></div>
<?php endif; ?>

<div class="admin-toolbar">
  <a href="orders.php" class="btn <?php echo $filter === '' ? 'btn-dark' : 'btn-secondary'; ?>">
    All (<?php echo array_sum($counts); ?>)
  </a>
  <?php foreach (array('pending','confirmed','paid','shipped','delivered','completed','cancelled') as $s): ?>
    <?php if (!empty($counts[$s])): ?>
      <a href="orders.php?status=<?php echo $s; ?>"
         class="btn <?php echo $filter === $s ? 'btn-dark' : 'btn-secondary'; ?>">
        <?php echo ucfirst($s); ?> (<?php echo $counts[$s]; ?>)
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="admin-card">
  <?php if (empty($orders)): ?>
    <div class="admin-card-body"><p class="hint">No orders here.</p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="dashboard-table">
        <thead>
          <tr>
            <th>Order</th><th>Client</th><th>Vehicle</th><th>Amount</th>
            <th>Placed</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td class="mono"><strong><?php echo sanitize($o['order_number']); ?></strong></td>
              <td>
                <?php echo sanitize($o['name']); ?><br>
                <span style="color:var(--tx-3);font-size:12px"><?php echo sanitize($o['email']); ?></span>
              </td>
              <td>
                <?php echo sanitize(trim($o['make'] . ' ' . $o['model'])); ?>
                <?php if (!empty($o['year'])): ?>
                  <span style="color:var(--tx-3)">· <?php echo intval($o['year']); ?></span>
                <?php endif; ?>
              </td>
              <td><?php echo formatPrice($o['amount'], $o['currency']); ?></td>
              <td><?php echo formatDate($o['order_date']); ?></td>
              <td>
                <?php if (!staffCan('orders.status')): ?>
                  <span class="ro-value"><?php echo ucfirst(sanitize($o['status'])); ?></span>
                <?php else: ?>
                <form method="POST" style="display:flex;gap:6px;align-items:center">
                  <input type="hidden" name="order_id" value="<?php echo intval($o['id']); ?>">
                  <select name="status" class="select" style="width:140px;height:32px;font-size:13px">
                    <?php foreach (array('pending','confirmed','paid','shipped','delivered','completed','cancelled') as $s): ?>
                      <option value="<?php echo $s; ?>" <?php echo strtolower($o['status']) === $s ? 'selected' : ''; ?>>
                        <?php echo ucfirst($s); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-secondary" style="height:32px;padding:0 11px;font-size:12.5px">Save</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once '_footer.php'; ?>
