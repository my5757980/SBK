  </main>
</div>

<footer class="footer">
  <div class="container">
    <div class="fbrand">
      <img src="<?php echo assetV('assets/img/sbk-logo.png'); ?>" alt="SBK Global Auto Trading">
    </div>
    <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?> — administration</p>
  </div>
</footer>
<?php // the panel needs the show/hide eye on its password fields ?>
<script src="../assets/js/site.js"></script>
<?php // headings that print a count keep it current without a reload ?>
<script src="../assets/js/livecount.js"></script>
<script src="../assets/js/photos.js"></script>
<?php /* The chat's heartbeat used to be sent from here, so that a member of
         staff showed as online because the panel was open. Presence is now the
         WEBSITE's job: the plugin on sbkautotrading.com sends the beat from
         every page, which is what the owner asked for - somebody is available
         because they are signed in to the website, and the auction portal is
         not the website. */ ?>
</body>
</html>
