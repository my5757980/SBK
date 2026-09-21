<?php
/**
 * SBK Auction — place order
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

requireClient();

$car_id = isset($_GET['car_id']) ? intval($_GET['car_id']) : 0;
$car = getCarById($car_id);

if (!$car) {
    header("Location: index.php");
    exit;
}

$client = getClient();
$error = '';
$success = false;
$order_number = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = createOrder($client['id'], $car['id']);
    if ($result['success']) {
        $success = true;
        $order_number = $result['order_number'];
    } else {
        $error = $result['message'];
    }
}

$title = trim($car['make'] . ' ' . $car['model']);
$photo = getCarImage($car, 320);

$page_title = ($success ? 'Order placed' : 'Confirm order') . ' — ' . SITE_NAME;
require_once 'includes/header.php';
?>

<main class="container">

<?php if ($success): ?>

  <div class="slim" style="max-width:560px">
    <div class="panel" style="text-align:center;padding:34px">
      <div class="ok-mark">✓</div>
      <h1 style="font-size:23px;margin-bottom:6px">Order placed</h1>
      <p class="sub" style="margin-bottom:18px">
        We've received your order and it's now pending review.
        Our team will contact you with shipping and total landed cost.
      </p>
      <div class="order-ref">
        <span>Order reference</span>
        <strong><?php echo sanitize($order_number); ?></strong>
      </div>
      <div class="stack" style="margin-top:20px">
        <a href="dashboard-client.php" class="btn btn-primary btn-lg btn-full">View in dashboard</a>
        <a href="index.php" class="btn btn-secondary btn-full">Keep browsing</a>
      </div>
    </div>
  </div>

<?php else: ?>

  <nav class="crumbs">
    <a href="index.php">Inventory</a> &nbsp;/&nbsp;
    <a href="car-details.php?id=<?php echo $car['id']; ?>"><?php echo sanitize($title); ?></a> &nbsp;/&nbsp;
    <b>Confirm order</b>
  </nav>

  <?php if (!empty($error)): ?>
    <div class="alert alert-error" style="max-width:900px"><?php echo sanitize($error); ?></div>
  <?php endif; ?>

  <div class="detail" style="grid-template-columns:1.1fr 1fr">

    <div class="panel">
      <h3>Vehicle</h3>
      <div class="order-car">
        <div class="order-car-photo">
          <?php if ($photo): ?>
            <img src="<?php echo sanitize($photo); ?>" alt="<?php echo sanitize($title); ?>">
          <?php else: ?><div class="noimg">No photo</div><?php endif; ?>
        </div>
        <div>
          <h2 style="font-size:18px"><?php echo sanitize($title); ?></h2>
          <div class="car-grade" style="min-height:0"><?php echo sanitize($car['grade'] ?: '—'); ?></div>
          <div class="car-meta" style="margin-top:8px">
            <?php if ($car['year']): ?><span class="tag"><?php echo intval($car['year']); ?></span><?php endif; ?>
            <?php if ($car['mileage']): ?><span class="tag"><?php echo number_format($car['mileage']); ?> km</span><?php endif; ?>
            <?php if ($car['engine_cc']): ?><span class="tag"><?php echo number_format($car['engine_cc']); ?> cc</span><?php endif; ?>
          </div>
        </div>
      </div>

      <table class="spec-table" style="margin-top:16px">
        <tr><th>Auction house</th><td><?php echo sanitize($car['auction'] ?: '—'); ?></td></tr>
        <tr><th>Auction date</th><td><?php echo sanitize($car['auction_date'] ?: '—'); ?></td></tr>
        <tr><th>Lot number</th><td class="mono"><?php echo sanitize($car['lot_no']); ?></td></tr>
        <tr><th>Inspection grade</th><td><?php echo sanitize($car['rating'] ?: '—'); ?></td></tr>
        <tr><th>Chassis ID</th><td class="mono"><?php echo sanitize($car['chassis'] ?: '—'); ?></td></tr>
      </table>
    </div>

    <div class="detail-side">

      <div class="panel">
        <h3>Buyer</h3>
        <table class="spec-table">
          <tr><th>Name</th><td><?php echo sanitize($client['name']); ?></td></tr>
          <tr><th>Email</th><td><?php echo sanitize($client['email']); ?></td></tr>
          <tr><th>Phone</th><td><?php echo sanitize($client['phone']); ?></td></tr>
        </table>
      </div>

      <div class="panel">
        <h3>Order summary</h3>
        <table class="spec-table">
          <tr>
            <th>Vehicle price</th>
            <td><?php echo $car['price'] > 0 ? '¥' . number_format($car['price']) : 'On request'; ?></td>
          </tr>
          <tr><th>Auction &amp; service fees</th><td>Quoted on confirmation</td></tr>
          <tr><th>Shipping</th><td>Quoted on confirmation</td></tr>
        </table>
        <div class="order-total">
          <span>Payable now</span>
          <strong>¥0</strong>
        </div>
        <p class="hint" style="margin-top:8px">
          Nothing is charged today. We'll send a full landed-cost quote before anything is due.
        </p>

        <form method="POST" class="stack" style="margin-top:16px">
          <label class="agree">
            <input type="checkbox" name="terms" required>
            <span>I understand this is a request to purchase and fees will be quoted before payment.</span>
          </label>
          <button type="submit" class="btn btn-primary btn-lg btn-full">Confirm order</button>
          <a href="car-details.php?id=<?php echo $car['id']; ?>" class="btn btn-ghost btn-full">Cancel</a>
        </form>
      </div>

    </div>
  </div>

<?php endif; ?>

</main>

<?php require_once 'includes/footer.php'; ?>
