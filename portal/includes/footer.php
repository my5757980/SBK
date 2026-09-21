<footer class="footer">
  <div class="container">
    <div class="fbrand">
      <img src="<?php echo assetV('assets/img/sbk-logo.png'); ?>" alt="SBK Global Auto Trading">
    </div>
    <?php // The copyright line and nothing after it. "Inventory synced live from
          // Japanese auction houses" was a claim about how the site is built,
          // repeated on every page to buyers who are here for the cars. ?>
    <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>.</p>
    <a href="<?php echo (defined('SITE_URL') ? SITE_URL : '/'); ?>admin/login.php" class="staff-link">Staff sign-in</a>
  </div>
</footer>

<?php /* The chat used to open from here. It now belongs to the WEBSITE
         (sbkautotrading.com), where a WordPress plugin puts the same button on
         every page - the owner's instruction on 2026-09-10, so that a customer
         who is already signed in to the shop walks straight into a conversation
         instead of being asked who they are all over again on a second site. */ ?>
</body>
</html>
