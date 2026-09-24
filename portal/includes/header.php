<?php
/**
 * Shared page chrome. Set $page_title before including.
 * Expects config.php + functions.php to already be loaded.
 */
if (!function_exists('assetV')) {
    function assetV($path) {
        $full = dirname(__DIR__) . '/' . ltrim($path, '/');
        return $path . '?v=' . (is_file($full) ? filemtime($full) : time());
    }
}
$page_title = isset($page_title) ? $page_title : SITE_NAME;

// The green / red signal for the IDs the feeds sign in with (staff only).
require_once __DIR__ . '/source-health.php';

// Resolve the signed-in client once. If the session points at a client that no
// longer exists (deleted or deactivated while logged in), drop the stale session
// so the header shows the logged-out state instead of crashing on null['name'].
$navClient = isLoggedIn() ? getClient() : null;
if (isLoggedIn() && !$navClient) {
    unset($_SESSION['client_id']);
}
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo sanitize($page_title); ?></title>
<link rel="icon" type="image/png" href="<?php echo assetV('assets/img/favicon.png'); ?>">
<link rel="stylesheet" href="<?php echo assetV('assets/css/style.css'); ?>">
<?php // pictures that enlarge, the auction countdowns, back to top - the two
      // screens the client asked about need these; nothing else changes. ?>
<script src="<?php echo assetV('assets/js/site.js'); ?>" defer></script>
<?php // a photograph the source does not have is taken out of the page ?>
<script src="<?php echo assetV('assets/js/photos.js'); ?>" defer></script>
<?php // the translator, loaded hidden and driven by the header's own select ?>
<script src="<?php echo assetV('assets/js/translate.js'); ?>" defer></script>
<?php // Any figure marked data-live keeps itself up to date from api/counts.php.
      // It was loaded on the desk's pages only, so the customer's dashboard tile
      // said "Updated live from Japan" beside a number that never moved. The
      // script does nothing at all on a page with no such figure on it. ?>
<script src="<?php echo assetV('assets/js/livecount.js'); ?>" defer></script>
<?php if (isAdmin()): // the ID signals repaint themselves once a minute - staff only ?>
<script src="<?php echo assetV('assets/js/source-signal.js'); ?>" defer></script>
<?php endif; ?>
</head>
<body>

<header class="topbar">
  <div class="container">
    <a href="<?php echo $navClient ? 'welcome.php' : 'login.php'; ?>" class="brand">
      <img src="<?php echo assetV('assets/img/sbk-logo.png'); ?>" alt="SBK Global Auto Trading">
    </a>
    <?php
      // Japan's clock and the language picker.
      //
      // Every auction on this portal runs on Tokyo time, and a buyer working
      // out whether a lot closes tonight or tomorrow morning should not have to
      // do the arithmetic in their head.
      //
      // The picker drives Google's translate widget, which is loaded hidden.
      // Its own control is a grey bar across the top of the page carrying
      // Google's name and a banner that shoves the whole site down 40px; this
      // one is a plain select that matches the rest of the header. Note that
      // Google's terms ask for their attribution to stay visible, so this is
      // the owner's call, taken knowingly.
      $jstNow = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
    ?>
    <div class="topbar-tools">
      <span class="jst-clock" id="jstClock" title="Japan Standard Time">
        <b class="notranslate" translate="no">JST</b>
        <span class="jst-t"><?php echo $jstNow->format('D d M · H:i:s'); ?></span>
      </span>
      <?php // Left untranslated on purpose. A reader looking for their own
            // language wants to find it written in that language - turning
            // "English" into "Английский" for a Russian reader hides the way
            // back, and the list is the one thing on the page that must stay
            // legible whatever the page has been translated into. ?>
      <select class="lang-pick notranslate" translate="no" id="langPick" aria-label="Language">
        <option value="en">English</option>
        <option value="ja">日本語</option>
        <option value="zh-CN">中文</option>
        <option value="ur">اردو</option>
        <option value="ar">العربية</option>
        <option value="ru">Русский</option>
        <option value="es">Español</option>
        <option value="fr">Français</option>
      </select>
    </div>
    <div id="google_translate_element" aria-hidden="true"></div>

    <nav class="topnav">
      <?php if ($navClient): ?>
        <a href="welcome.php">Auctions</a>
        <?php // What the auction list cannot say: what these cars go for. It sits
              // next to the list because it is read alongside it - a buyer looks
              // at a lot, then at what the model has been making. ?>
        <a href="statistics.php">Statistics</a>
        <a href="dashboard-client.php">Dashboard</a>
        <span class="nav-user"><?php echo sanitize($navClient['name']); ?></span>
        <a href="includes/logout.php">Log out</a>
      <?php elseif (isAdmin()): ?>
        <?php // Staff walking the portal see what a customer sees, section for
              // section. Only the dashboard is missing, and only because there
              // is no customer account behind a staff session to fill it.
              //
              // Fixed price is gone from every bar, on the owner's order of
              // 13 September 2026 - see fixed-price.php. ?>
        <a href="welcome.php">Auctions</a>
        <a href="statistics.php">Statistics</a>
        <span class="nav-user">Staff · <?php echo sanitize($_SESSION['admin_name'] ?? 'Admin'); ?></span>
        <a href="admin/dashboard.php" class="nav-cta">Admin panel</a>
        <a href="admin/logout.php">Sign out</a>
      <?php else: ?>
        <a href="login.php">Log in</a>
        <a href="register.php" class="nav-cta">Register</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
