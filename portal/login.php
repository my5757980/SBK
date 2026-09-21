<?php
/**
 * SBK Auction Portal - Client Login
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

// already signed in — straight to the auctions
if (isLoggedIn()) {
    header("Location: welcome.php");
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Email and password are required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } else {
        if (loginClient($email, $password)) {
            // Signing in always lands on the welcome page, by the owner's
            // instruction. Drop any URL requireLogin() stashed on the way in,
            // otherwise a stale one would send the visitor somewhere else.
            unset($_SESSION['redirect_after_login']);
            header("Location: welcome.php");
            exit;
        } else {
            $error = "Invalid email or password";
        }
    }
}

$page_title = 'Log in — ' . SITE_NAME;
require_once 'includes/header.php';
?>

<main class="container">
  <div class="slim">
    <div class="panel">
      <h1>Welcome back</h1>
      <p class="sub">Sign in to browse the auctions, place bids and track your enquiries.</p>

      <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-row">
          <label for="email">Email address</label>
          <input type="email" id="email" name="email" class="input"
                 value="<?php echo sanitize($email); ?>" required autofocus>
        </div>
        <div class="form-row">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" class="input" required>
        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-full">Log in</button>
      </form>

      <p class="hint" style="margin-top:16px;text-align:center">
        No account yet? <a href="register.php">Create one</a>
      </p>
    </div>
  </div>
</main>

<?php require_once 'includes/footer.php'; ?>
