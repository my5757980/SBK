<?php
/**
 * SBK Chat — the way in.
 *
 * This screen is for STRANGERS ONLY — somebody with no account on
 * sbkautotrading.com at all. They give three things, name, e-mail and
 * telephone, and are in the conversation the moment they submit.
 *
 * Anybody who HAS an account never sees it. They arrive through enter.php
 * carrying a signed line from the website, and the website already knows their
 * name and e-mail; asking a customer to type what the shop has on file would be
 * the sort of small rudeness that makes software feel unfinished.
 *
 * A browser that has been here before skips it too: the cookie from last time
 * is the whole of their identity, and starting a second conversation for the
 * same person is a mess the desk would have to unpick by hand.
 */

require_once __DIR__ . '/includes/lib.php';

/* A customer signed in on the website who has not given all three yet sees this
   same card (see guestNeedsDetails()): the e-mail is the one on their account, and
   so is the name when the account has a real one; the telephone they type. The
   owner's rule is that nobody reaches the desk without name, e-mail and phone. */
$g         = guest();
$details   = ($g && !staff() && guestNeedsDetails($g));
$acct      = $details ? wpUser((int) $g['wp_user_id']) : null;
$fixedName = ($acct && !empty($acct['has_name'])) ? trim((string) $acct['name']) : '';

if ($g && !$details && empty($_GET['new'])) {
    header('Location: chat.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = $fixedName !== '' ? $fixedName : trim((string) ($_POST['name'] ?? ''));
    $email = $details ? (string) $g['email'] : trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));

    /* Checked here and not only in the browser. A form can be posted without a
       browser at all, and a conversation that begins with an empty name is one
       the desk cannot answer. */
    if (mb_strlen($name) < 2 || ($details && strpos($name, '@') !== false)) {
        $err = 'Please tell us your name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'That e-mail address does not look right.';
    } elseif (strlen(preg_replace('/\D+/', '', $phone)) < 7) {
        $err = 'Please give a telephone number we can reach you on.';
    } else {
        if ($details) {
            guestSaveDetails((int) $g['id'], $name, $phone);
        } else {
            guestSignIn($name, $email, $phone);
        }
        header('Location: chat.php');
        exit;
    }
}

if ($details) {
    // The name their account gives, or what they typed; never a login standing in for one.
    $login = $acct ? (string) $acct['username'] : '';
    $name  = $fixedName !== '' ? $fixedName
           : (string) ($_POST['name'] ?? (looksLikeLogin($g['name'], $login, $g['email']) ? '' : $g['name']));
    $email = (string) $g['email'];
    $phone = (string) ($_POST['phone'] ?? $g['phone']);
} else {
    $name  = (string) ($_POST['name'] ?? '');
    $email = (string) ($_POST['email'] ?? '');
    $phone = (string) ($_POST['phone'] ?? '');
}
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Chat with us — <?php echo e(CHAT_NAME); ?></title>
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
      <h1>Chat with our team</h1>
      <p>Tell us who you are and we will put you straight through.</p>
    </div>

    <div class="gate-body">
      <?php if ($err): ?>
        <div class="alert"><?php echo e($err); ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="on" novalidate>
        <div class="field">
          <label for="f-name">Your name</label>
          <input type="text" id="f-name" name="name" autocomplete="name"
                 placeholder="e.g. Muhammad Yaseen" maxlength="120"
                 value="<?php echo e($name); ?>" required
                 <?php echo $fixedName !== '' ? 'readonly' : ($details && $name !== '' ? '' : 'autofocus'); ?>>
        </div>
        <div class="field">
          <label for="f-email">E-mail</label>
          <input type="email" id="f-email" name="email" autocomplete="email"
                 placeholder="you@example.com" maxlength="160"
                 value="<?php echo e($email); ?>" required<?php echo $details ? ' readonly' : ''; ?>>
        </div>
        <div class="field">
          <label for="f-phone">Telephone</label>
          <input type="tel" id="f-phone" name="phone" autocomplete="tel"
                 placeholder="+92 300 0000000" maxlength="40"
                 value="<?php echo e($phone); ?>" required<?php echo ($details && $name !== '') ? ' autofocus' : ''; ?>>
        </div>

        <button type="submit" class="btn btn-full">
          Start chatting
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 12h13M13 6l6 6-6 6"/>
          </svg>
        </button>
      </form>

      <p class="gate-note">
        We use these only to reply to you.<br>
        <?php if ($details): ?>
          Signed in on the website as <?php echo e($email); ?>.
        <?php else: ?>
        <?php /* One line for BOTH kinds of person who already have an account:
                 a customer of the shop and a member of the desk sign in at the
                 same place, because on the website they are the same login.
                 Whichever they are, the website hands them back here knowing
                 it - so nobody has to work out which door is theirs. */ ?>
        <?php /* `login=1` matters: without it somebody who is NOT signed in to
                 the website is sent politely back to this very form, which is
                 the one place a link called "sign in" must never lead. */ ?>
        Already have an SBK account?
        <a href="<?php echo e(WP_GO . '&login=1'); ?>">Sign in on the website</a>.
        <?php endif; ?>
      </p>
    </div>

  </div>
</div>

</body>
</html>
