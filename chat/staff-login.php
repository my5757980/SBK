<?php
/**
 * The desk signs in — on the website, not here.
 *
 * There is no password on this page and there is no list of chat users. The
 * owner's requirement is that an account is made on sbkautotrading.com and that
 * being signed in THERE is what puts somebody at their desk here. So this page
 * only points at the website's own sign-in and comes back holding a signed line
 * saying who arrived (see enter.php).
 *
 * That leaves one account and one password per person, and taking somebody's
 * access away is one thing done in one place — which is the only version of
 * "one set of accounts" that survives contact with a real staff list.
 *
 * There is nothing to see here for anybody who arrived by accident, so a person
 * already carrying a session is sent straight on.
 */

require_once __DIR__ . '/includes/lib.php';

if (staff() || guest()) {
    header('Location: chat.php');
    exit;
}

/* Straight through unless something wants to explain itself first. The website
   will send them back to enter.php, which is what actually signs them in. */
$go = WP_GO . '&login=1';
if (empty($_GET['stay'])) {
    header('Location: ' . $go);
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Chat desk — <?php echo e(CHAT_NAME); ?></title>
<link rel="icon" href="assets/img/favicon.png">
<link rel="stylesheet" href="assets/css/chat.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/chat.css'); ?>">
<script>
/* Am I inside the website's own panel? Answered here, in the head, before
   anything is painted - a bar that appears and then vanishes is worse than one
   that was never drawn. See "inside the website's own panel" in chat.css. */
if (window.self !== window.top) { document.documentElement.className += ' framed'; }
</script>
</head>
<body>

<div class="gate">
  <div class="gate-card">
    <div class="gate-head">
      <img src="assets/img/sbk-logo.png" alt="<?php echo e(CHAT_NAME); ?>">
      <h1>Chat desk</h1>
      <p>Sign in on the SBK website and you are signed in here.<br>
         One account, one password, nothing else to remember.</p>
    </div>

    <div class="gate-body">
      <a class="btn btn-full" href="<?php echo e($go); ?>">
        Sign in on the website
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M5 12h13M13 6l6 6-6 6"/>
        </svg>
      </a>
      <p class="gate-note">
        Not one of our team? <a href="index.php?new=1">Chat with us instead</a>.
      </p>
    </div>
  </div>
</div>

</body>
</html>
