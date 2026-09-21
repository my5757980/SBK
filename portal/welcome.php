<?php
/**
 * SBK Auction — the page every signed-in client lands on.
 * Pick a manufacturer, then a model, then the auction list.
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

requireLogin();

$groups = getMakesGrouped();
$stats  = getInventoryStats();

$page_title = 'Welcome — ' . SITE_NAME;
require_once 'includes/header.php';
?>

<section class="welcome-head">
  <div class="container">
    <h1>Welcome to <em>SBK Auction</em></h1>
    <p>Choose a manufacturer to begin. <?php echo number_format($stats['available']); ?>
       vehicles are available across <?php echo number_format($stats['auctions']); ?> Japanese auction houses.</p>
  </div>
</section>

<main class="container">

  <div class="pick-step">
    <span class="step-num">1</span>
    <h2>Manufacturer</h2>
    <span class="rule"></span>
    <span class="page-info"><?php echo number_format($groups['total']); ?> vehicles</span>
  </div>

  <?php if ($groups['japanese']): ?>
    <div class="make-group">
      <div class="make-group-label">Japanese</div>
      <div class="make-grid is-primary">
        <?php foreach ($groups['japanese'] as $m): ?>
          <a class="make-tile" href="models.php?make=<?php echo urlencode($m['make']); ?>">
            <span class="mk"><?php echo sanitize($m['make']); ?></span>
            <span class="ct"><?php echo number_format($m['n']); ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($groups['other']): ?>
    <div class="make-group">
      <div class="make-group-label">Other manufacturers</div>
      <div class="make-grid">
        <?php foreach ($groups['other'] as $m): ?>
          <a class="make-tile" href="models.php?make=<?php echo urlencode($m['make']); ?>">
            <span class="mk"><?php echo sanitize($m['make']); ?></span>
            <span class="ct"><?php echo number_format($m['n']); ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <p class="hint" style="margin:8px 0 44px">
    Looking for something specific? <a href="index.php">Browse the full inventory</a> instead.
  </p>

</main>

<?php require_once 'includes/footer.php'; ?>
