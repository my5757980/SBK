<?php
/**
 * SBK Auction Portal - Client Registration
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

// already signed in — straight to the auctions
if (isLoggedIn()) {
    header("Location: welcome.php");
    exit;
}

$errors = array();
$form_data = array(
    'name' => $_POST['name'] ?? '',
    'email' => $_POST['email'] ?? '',
    'phone' => $_POST['phone'] ?? '',
    'password' => $_POST['password'] ?? '',
    'password_confirm' => $_POST['password_confirm'] ?? '',
);

$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = registerClient($form_data);

    if ($result['success']) {
        $success = true;
        // Redirect after 2 seconds
        header("Refresh: 2; url=welcome.php");
    } else {
        $errors = $result['errors'];
    }
}

$page_title = 'Register — ' . SITE_NAME;
require_once 'includes/header.php';
?>

<main class="container">
  <div class="slim">
    <div class="panel">
      <h1>Create your account</h1>
      <p class="sub">Register to bid, order and track vehicles from Japanese auctions.</p>

      <?php if ($success): ?>
        <div class="alert alert-success">Account created. Taking you to the auctions…</div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
          <?php foreach ($errors as $e): ?>
            <div><?php echo sanitize($e); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-row">
          <label for="name">Full name</label>
          <input type="text" id="name" name="name" class="input"
                 value="<?php echo sanitize($form_data['name']); ?>" required>
        </div>
        <div class="form-row">
          <label for="email">Email address</label>
          <input type="email" id="email" name="email" class="input"
                 value="<?php echo sanitize($form_data['email']); ?>" required>
        </div>
        <div class="form-row">
          <label for="phone">Phone</label>
          <input type="text" id="phone" name="phone" class="input"
                 value="<?php echo sanitize($form_data['phone']); ?>" required>
        </div>
        <div class="form-row">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" class="input" required
                 minlength="6" placeholder="At least 6 characters">
        </div>
        <div class="form-row">
          <label for="password_confirm">Confirm password</label>
          <input type="password" id="password_confirm" name="password_confirm" class="input" required>
        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-full">Create account</button>
      </form>

      <p class="hint" style="margin-top:16px;text-align:center">
        Already registered? <a href="login.php">Log in</a>
      </p>
    </div>
  </div>
</main>

<?php require_once 'includes/footer.php'; ?>
