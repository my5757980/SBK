<?php
/**
 * SBK Auction — edit a staff login
 *
 * Opens holding what the person already is, so a change is a change to what is
 * on the screen rather than a form filled in again from memory. Saving writes
 * only what the admin is allowed to write: role and active state need
 * staff.edit, a new password needs staff.password, and the password field is
 * left alone unless something was actually typed into it.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requirePermission('staff.view');

global $conn;

$me  = currentStaff();
$id  = intval($_GET['id'] ?? $_POST['staff_id'] ?? 0);
$mayEdit = staffCan('staff.edit');
$mayPw   = staffCan('staff.password');

if (!$mayEdit && !$mayPw) {
    header('Location: staff-list.php');
    exit;
}

$roles = array();
$res = $conn->query("SELECT id, name, label FROM roles ORDER BY is_system DESC, label ASC");
while ($r = $res->fetch_assoc()) { $roles[(int) $r['id']] = $r; }

function loadStaff($conn, $id) {
    $st = $conn->prepare("
        SELECT a.id, a.username, a.name, a.email, a.is_active, a.role_id,
               a.last_login, a.created_at, r.label AS role_label
          FROM admins a LEFT JOIN roles r ON r.id = a.role_id
         WHERE a.id = ? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

$staff = loadStaff($conn, $id);
if (!$staff) {
    header('Location: staff-list.php');
    exit;
}

$isMe       = (int) $staff['id'] === (int) $me['id'];
$message    = '';
$message_ok = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $role_id = $mayEdit ? intval($_POST['role_id'] ?? 0) : (int) $staff['role_id'];
    $act     = $mayEdit ? (isset($_POST['is_active']) ? 1 : 0) : (int) $staff['is_active'];
    $newpass = (string) ($_POST['new_password'] ?? '');

    // Their own role and their own switch stay out of their hands: a permission
    // system whose holder can rewrite their own grants is not one. Another
    // administrator makes those two changes.
    if ($isMe) {
        $role_id = (int) $staff['role_id'];
        $act     = 1;
    }

    if ($newpass !== '' && !$mayPw) {
        $message = 'Your role cannot set passwords for other staff'; $message_ok = false;
    } elseif ($name === '') {
        $message = 'A name is required'; $message_ok = false;
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'That email address is not valid'; $message_ok = false;
    } elseif ($email !== '' && staffEmailTaken($conn, $email, $id)) {
        // An address can be signed in with, so it has to name one account only.
        $message = 'Another login already uses that email address';
        $message_ok = false;
    } elseif (!isset($roles[$role_id])) {
        $message = 'Pick a role'; $message_ok = false;
    } elseif ($newpass !== '' && strlen($newpass) < 8) {
        $message = 'A new password needs at least 8 characters'; $message_ok = false;
    } else {
        if ($newpass !== '') {
            $hash = password_hash($newpass, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE admins
                                       SET name = ?, email = ?, role_id = ?, is_active = ?,
                                           password_hash = ?
                                     WHERE id = ?");
            $stmt->bind_param('ssiisi', $name, $email, $role_id, $act, $hash, $id);
        } else {
            $stmt = $conn->prepare("UPDATE admins
                                       SET name = ?, email = ?, role_id = ?, is_active = ?
                                     WHERE id = ?");
            $stmt->bind_param('ssiii', $name, $email, $role_id, $act, $id);
        }
        if ($stmt->execute()) {
            $stmt->close();
            logStaffAction($newpass !== '' ? 'staff.password' : 'staff.edit',
                           $staff['username'], $id);
            header('Location: staff-list.php?saved=' . ($newpass !== '' ? 'password' : '1'));
            exit;
        }
        $stmt->close();
        $message = 'Could not save that login'; $message_ok = false;
        // Show what they typed rather than what is stored, so nothing is lost.
        $staff['name']      = $name;
        $staff['email']     = $email;
        $staff['role_id']   = $role_id;
        $staff['is_active'] = $act;
    }
}

$page_title = 'Edit Staff';
$active = 'staff';
require_once '_header.php';
?>

<p class="crumbs"><a href="staff-list.php">Staff</a> &rsaquo; Edit</p>
<h1>Edit Staff</h1>
<p class="lede">Editing <strong><?php echo sanitize($staff['username']); ?></strong>
   — created <?php echo $staff['created_at'] ? formatDate($staff['created_at']) : '—'; ?>,
   last signed in <?php echo $staff['last_login'] ? formatDate($staff['last_login']) : 'never'; ?>.</p>

<?php if ($message): ?>
  <div class="alert <?php echo $message_ok ? 'alert-success' : 'alert-error'; ?>"><?php echo sanitize($message); ?></div>
<?php endif; ?>

<div class="admin-card form-card">
  <div class="admin-card-head"><h2>Login Details</h2></div>
  <form method="POST" class="staff-form stack">
    <div class="admin-card-body">
      <input type="hidden" name="staff_id" value="<?php echo (int) $staff['id']; ?>">

      <?php /* Two to a row, the same as the create screen - the two forms hold
               the same facts about the same person and should not read as two
               different kinds of screen. */ ?>
      <div class="form-grid">
      <div class="field">
        <label>Username</label>
        <input type="text" class="input" value="<?php echo sanitize($staff['username']); ?>" disabled>
        <p class="hint">A username is how the login is known and does not change.</p>
      </div>

      <div class="field">
        <label for="e-name">Full Name</label>
        <input type="text" id="e-name" name="name" class="input" required
               value="<?php echo sanitize($staff['name']); ?>">
      </div>

      <div class="field">
        <label for="e-email">Email <span class="bound">Optional</span></label>
        <input type="email" id="e-email" name="email" class="input"
               value="<?php echo sanitize($staff['email']); ?>">
      </div>

      <div class="field">
        <label for="e-role">Role</label>
        <select id="e-role" name="role_id" class="select"
                <?php echo (!$mayEdit || $isMe) ? 'disabled' : ''; ?>>
          <?php foreach ($roles as $r): ?>
            <option value="<?php echo (int) $r['id']; ?>"
              <?php echo (int) $r['id'] === (int) $staff['role_id'] ? 'selected' : ''; ?>>
              <?php echo sanitize($r['label']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($isMe): ?>
          <p class="hint">You cannot change your own role — ask another administrator.</p>
        <?php elseif (!$mayEdit): ?>
          <p class="hint">Your role cannot change roles.</p>
        <?php endif; ?>
      </div>

      <div class="field">
        <label>Status</label>
        <label class="facet-item" style="white-space:nowrap">
          <input type="checkbox" name="is_active" value="1"
                 <?php echo $staff['is_active'] ? 'checked' : ''; ?>
                 <?php echo (!$mayEdit || $isMe) ? 'disabled' : ''; ?>>
          <span class="fv">Active</span>
        </label>
        <?php if ($isMe): ?>
          <p class="hint">You cannot switch off your own login.</p>
        <?php else: ?>
          <p class="hint">A login that is not active cannot sign in at all.</p>
        <?php endif; ?>
      </div>

      <?php if ($mayPw): ?>
      <div class="field field-full">
        <label for="e-pass">New Password</label>
        <div class="pw-wrap pw-narrow">
          <input type="password" id="e-pass" name="new_password" class="input"
                 autocomplete="new-password" minlength="8"
                 placeholder="Leave blank to keep the current password">
          <button type="button" class="pw-eye" data-for="e-pass" aria-label="Show password">&#128065;</button>
        </div>
        <p class="hint">Only filled in when it is being changed. The old password
           cannot be read back, so if it has been forgotten, set a new one here
           and hand it over.</p>
      </div>
      <?php endif; ?>

      </div><!-- /form-grid -->
    </div>

    <div class="form-foot">
      <a href="staff-list.php" class="btn btn-secondary">Cancel</a>
      <button type="submit" class="btn btn-primary">Save Changes</button>
    </div>
  </form>
</div>

<?php require_once '_footer.php'; ?>
