<?php
/**
 * SBK Auction — admin sign-in
 * Credentials live in the `admins` table as bcrypt hashes; nothing is
 * hardcoded in this file.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

if (isAdmin()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Username or email, and password, are required";
    } else {
        global $conn;

        /* The username, or the email address the account was created with.

           Only the username was accepted, and the screen that creates an
           account asks for both - so a login was handed over as a Gmail address
           and a password, which is what the person then typed here, and the
           answer was "Invalid username or password". The account was fine; the
           form simply never looked at the email column.

           The username is tried first, because it is the account's real name
           and cannot be shared. The email is a fallback and is only accepted
           when exactly one active account carries it: the field is optional and
           was never made unique, so two accounts sharing one address must not
           silently sign somebody in as whichever came first. (New and edited
           accounts now refuse a duplicate, so this can only be old data.) */
        $stmt = $conn->prepare("
            SELECT id, name, password_hash
            FROM admins
            WHERE username = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$admin && strpos($username, '@') !== false) {
            $stmt = $conn->prepare("
                SELECT id, name, password_hash
                FROM admins
                WHERE email = ? AND email <> '' AND is_active = 1
                LIMIT 2
            ");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (count($rows) === 1) {
                $admin = $rows[0];
            }
        }

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];

            $stmt = $conn->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
            $stmt->bind_param('i', $admin['id']);
            $stmt->execute();
            $stmt->close();

            header("Location: dashboard.php");
            exit;
        }
        // same message either way - don't reveal which part was wrong
        $error = "Invalid username, email or password";
    }
}

function assetV($path) {
    $full = dirname(__DIR__) . '/' . ltrim($path, '/');
    return '../' . $path . '?v=' . (is_file($full) ? filemtime($full) : time());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin sign-in — <?php echo SITE_NAME; ?></title>
<link rel="icon" type="image/png" href="<?php echo assetV('assets/img/favicon.png'); ?>">
<link rel="stylesheet" href="<?php echo assetV('assets/css/style.css'); ?>">
</head>
<body>

<header class="topbar">
  <div class="container">
    <a href="../index.php" class="brand">
      <img src="<?php echo assetV('assets/img/sbk-logo.png'); ?>" alt="SBK Global Auto Trading">
      <span class="brand-sub">Administration</span>
    </a>
    <nav class="topnav">
      <a href="../index.php">Back to portal</a>
    </nav>
  </div>
</header>

<main class="container">
  <div class="slim">
    <div class="panel">
      <h1>Admin sign-in</h1>
      <p class="sub">Restricted area — staff only.</p>

      <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-row">
          <label for="username">Username or Email</label>
          <input type="text" id="username" name="username" class="input"
                 value="<?php echo sanitize($username); ?>" required autofocus autocomplete="username">
        </div>
        <div class="form-row">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" class="input" required
                 autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-dark btn-lg btn-full">Sign in</button>
      </form>
    </div>
  </div>
</main>

<footer class="footer">
  <div class="container">
    <div class="fbrand">
      <img src="<?php echo assetV('assets/img/sbk-logo.png'); ?>" alt="SBK Global Auto Trading">
    </div>
    <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>.</p>
  </div>
</footer>
</body>
</html>
