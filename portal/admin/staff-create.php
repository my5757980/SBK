<?php
/**
 * SBK Auction — create a staff login
 *
 * A username, a password and a role. The password is stored as a bcrypt hash,
 * so nobody - the admin included - can ever read it back; it is handed over
 * once, here, and set again from the edit screen if it is lost.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requirePermission('staff.create');

global $conn;

$message    = '';
$message_ok = true;
$created    = '';

// What was typed, kept across a failed submit so nothing has to be retyped.
$in = array('username' => '', 'name' => '', 'email' => '', 'role_id' => 0);

$roles = array();
$res = $conn->query("SELECT id, name, label FROM roles ORDER BY is_system DESC, label ASC");
while ($r = $res->fetch_assoc()) { $roles[(int) $r['id']] = $r; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in['username'] = trim($_POST['username'] ?? '');
    $in['name']     = trim($_POST['name'] ?? '');
    $in['email']    = trim($_POST['email'] ?? '');
    $in['role_id']  = intval($_POST['role_id'] ?? 0);
    $password       = (string) ($_POST['password'] ?? '');

    if ($in['username'] === '' || $in['name'] === '' || $password === '') {
        $message = 'Username, name and password are all required'; $message_ok = false;
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $in['username'])) {
        $message = 'Username must be 3-60 characters: letters, numbers, dot, dash or underscore';
        $message_ok = false;
    } elseif (strlen($password) < 8) {
        $message = 'Give them a password of at least 8 characters'; $message_ok = false;
    } elseif (!isset($roles[$in['role_id']])) {
        $message = 'Pick a role'; $message_ok = false;
    } elseif ($in['email'] !== '' && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
        $message = 'That email address is not valid'; $message_ok = false;
    } elseif ($in['email'] !== '' && staffEmailTaken($conn, $in['email'])) {
        // An address can be signed in with, so it has to name one account only.
        $message = 'Another login already uses that email address';
        $message_ok = false;
    } else {
        $exists = $conn->prepare("SELECT id FROM admins WHERE username = ? LIMIT 1");
        $exists->bind_param('s', $in['username']);
        $exists->execute();
        $taken = $exists->get_result()->num_rows > 0;
        $exists->close();

        if ($taken) {
            $message = 'That username is already taken'; $message_ok = false;
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("
                INSERT INTO admins (username, role_id, name, email, password_hash, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->bind_param('sisss', $in['username'], $in['role_id'], $in['name'],
                              $in['email'], $hash);
            if ($stmt->execute()) {
                logStaffAction('staff.create', $in['username'], $conn->insert_id);
                $created = $in['username'];
                $message = 'Created ' . $in['username'] . ' as ' . $roles[$in['role_id']]['label']
                         . '. Hand over the password you just set — it cannot be read back.';
                // A blank form, ready for the next person.
                $in = array('username' => '', 'name' => '', 'email' => '', 'role_id' => 0);
            } else {
                $message = 'Could not create that login'; $message_ok = false;
            }
            $stmt->close();
        }
    }
}

$page_title = 'Create Staff';
$active = 'staff';
require_once '_header.php';
?>

<p class="crumbs"><a href="staff-list.php">Staff</a> &rsaquo; Create Staff</p>
<h1>Create Staff</h1>
<p class="lede">A new in-house login. Their role decides what they will see —
   set those up on the <a href="roles.php">Roles</a> screen.</p>

<?php if ($message): ?>
  <div class="alert <?php echo $message_ok ? 'alert-success' : 'alert-error'; ?>">
    <?php echo sanitize($message); ?>
    <?php if ($created !== ''): ?>
      <a href="staff-list.php" class="alert-link">View The Staff List</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="admin-card form-card">
  <div class="admin-card-head">
    <h2>New Login</h2>
    <span class="spacer"></span>
    <span class="page-info">All fields marked <span class="req">*</span> are required</span>
  </div>

  <form method="POST" class="staff-form stack">
    <div class="admin-card-body">
      <?php /* Two to a row, and every field says underneath what it wants.
               Five fields in one column read as a long form; five fields with
               nothing under them read as a quiz. The pairs are the ones that
               belong together - a username beside the name it stands for, the
               role beside the address you would use to reach the person. */ ?>
      <div class="form-grid">

        <div class="field">
          <label for="s-user">Username <span class="req">*</span></label>
          <input type="text" id="s-user" name="username" class="input" required
                 autocomplete="off" placeholder="sales.ahmed"
                 pattern="[A-Za-z0-9._-]{3,60}"
                 value="<?php echo sanitize($in['username']); ?>">
          <p class="fhint">3–60 characters. Letters, numbers, dot, dash or underscore.
             This is what they type to sign in.</p>
        </div>

        <div class="field">
          <label for="s-name">Full Name <span class="req">*</span></label>
          <input type="text" id="s-name" name="name" class="input" required
                 placeholder="Ahmed Khan" value="<?php echo sanitize($in['name']); ?>">
          <p class="fhint">Shown in the panel and against anything they do.</p>
        </div>

        <div class="field">
          <label for="s-email">Email <span class="bound">Optional</span></label>
          <input type="email" id="s-email" name="email" class="input"
                 placeholder="ahmed@example.com" value="<?php echo sanitize($in['email']); ?>">
          <p class="fhint">Only for reaching them. It is not used to sign in.</p>
        </div>

        <div class="field">
          <label for="s-role">Role <span class="req">*</span></label>
          <select id="s-role" name="role_id" class="select" required>
            <?php foreach ($roles as $r): ?>
              <option value="<?php echo (int) $r['id']; ?>"
                <?php
                  $pick = $in['role_id'] ? ((int) $r['id'] === (int) $in['role_id'])
                                         : ($r['name'] === 'agent');
                  echo $pick ? 'selected' : '';
                ?>>
                <?php echo sanitize($r['label']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="fhint">Decides every screen they can open.
             <a href="roles.php">Manage roles</a>.</p>
        </div>

        <div class="field field-full">
          <label for="s-pass">Password <span class="req">*</span></label>
          <div class="pw-wrap pw-narrow">
            <input type="password" id="s-pass" name="password" class="input" required
                   autocomplete="new-password" minlength="8"
                   placeholder="At least 8 characters">
            <button type="button" class="pw-eye" data-for="s-pass"
                    aria-label="Show password">&#128065;</button>
          </div>
          <?php /* The warning belongs on the line under the field, not in a
                   tinted box beside it. The box was louder than anything else
                   on the screen and sat against a single input with nothing to
                   balance it - and it said in four lines what one says. */ ?>
          <p class="fhint">At least 8 characters. Stored as a hash, so it can
             never be read back — <strong>write it down before you save</strong>.
             If it is lost, set a new one from
             <a href="staff-list.php">List Staff</a>.</p>
        </div>

      </div>
    </div>

    <div class="form-foot">
      <a href="staff-list.php" class="btn btn-secondary">Cancel</a>
      <button type="submit" class="btn btn-primary">Create Login</button>
    </div>
  </form>
</div>

<?php require_once '_footer.php'; ?>
