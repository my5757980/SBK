<?php
/**
 * SBK Auction — admin clients
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requirePermission('clients.view');

global $conn;

$clients = $conn->query("
    SELECT c.id, c.name, c.email, c.phone, c.is_active, c.created_at,
           (SELECT COUNT(*) FROM orders o WHERE o.client_id = c.id) AS order_count,
           (SELECT COALESCE(SUM(o.amount),0) FROM orders o WHERE o.client_id = c.id) AS order_value,
           (SELECT COUNT(*) FROM inquiries i WHERE i.client_id = c.id) AS inquiry_count
    FROM clients c
    ORDER BY c.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$page_title = 'Clients';
$active = 'clients';
require_once '_header.php';
?>

<h1>Clients</h1>
<p class="lede">Registered buyers and their activity.</p>

<div class="kpi-row">
  <div class="kpi">
    <div class="k">Registered</div>
    <div class="v"><?php echo number_format(count($clients)); ?></div>
  </div>
  <div class="kpi">
    <div class="k">With orders</div>
    <div class="v"><?php echo number_format(count(array_filter($clients, function ($c) { return $c['order_count'] > 0; }))); ?></div>
  </div>
  <div class="kpi accent">
    <div class="k">Total order value</div>
    <div class="v"><?php echo formatPrice(array_sum(array_column($clients, 'order_value')), 'yen'); ?></div>
  </div>
</div>

<div class="admin-card">
  <?php if (empty($clients)): ?>
    <div class="admin-card-body"><p class="hint">No clients have registered yet.</p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="dashboard-table">
        <thead>
          <tr><th>Client</th><th>Phone</th><th>Orders</th><th>Order value</th><th>Inquiries</th><th>Joined</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($clients as $c): ?>
            <tr>
              <td>
                <strong><?php echo sanitize($c['name']); ?></strong><br>
                <span style="color:var(--tx-3);font-size:12px"><?php echo sanitize($c['email']); ?></span>
              </td>
              <td><?php echo sanitize($c['phone'] ?: '—'); ?></td>
              <td><?php echo number_format($c['order_count']); ?></td>
              <td><?php echo $c['order_value'] > 0 ? formatPrice($c['order_value'], 'yen') : '—'; ?></td>
              <td><?php echo number_format($c['inquiry_count']); ?></td>
              <td><?php echo formatDate($c['created_at']); ?></td>
              <td>
                <span class="status-badge <?php echo $c['is_active'] ? 'status-confirmed' : 'status-cancelled'; ?>">
                  <?php echo $c['is_active'] ? 'Active' : 'Disabled'; ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once '_footer.php'; ?>
