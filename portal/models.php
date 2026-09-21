<?php
/**
 * SBK Auction — models of one manufacturer, A to Z.
 * Picking a model opens the auction list filtered to it.
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

requireLogin();

$make = trim($_GET['make'] ?? '');
if ($make === '') {
    header('Location: welcome.php');
    exit;
}

$byLetter = getModelsByLetter($make);
$total = 0;
foreach ($byLetter as $rows) {
    foreach ($rows as $r) {
        $total += (int) $r['n'];
    }
}

if (!$byLetter) {
    // unknown or empty manufacturer — send them back rather than show an empty page
    header('Location: welcome.php');
    exit;
}

$page_title = sanitize($make) . ' models — ' . SITE_NAME;
require_once 'includes/header.php';
?>

<main class="container">

  <nav class="crumbs">
    <a href="welcome.php">Manufacturers</a> &nbsp;/&nbsp;
    <b><?php echo sanitize($make); ?></b>
  </nav>

  <div class="pick-step">
    <span class="step-num">2</span>
    <h2><?php echo sanitize($make); ?> — choose a model</h2>
    <span class="rule"></span>
    <span class="page-info"><?php echo number_format($total); ?> vehicles</span>
  </div>

  <div class="model-cols">
    <?php foreach ($byLetter as $letter => $rows): ?>
      <div class="model-block">
        <div class="model-letter"><?php echo sanitize($letter); ?></div>
        <?php foreach ($rows as $r): ?>
          <a class="model-link"
             href="index.php?make=<?php echo urlencode($make); ?>&amp;model=<?php echo urlencode($r['model']); ?>">
            <?php echo sanitize($r['model']); ?>
            <span class="ct">(<?php echo number_format($r['n']); ?>)</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="hint" style="margin:18px 0 44px">
    <a href="index.php?make=<?php echo urlencode($make); ?>">Show every <?php echo sanitize($make); ?></a>
    &nbsp;·&nbsp;
    <a href="welcome.php">Pick another manufacturer</a>
  </p>

</main>

<?php require_once 'includes/footer.php'; ?>
