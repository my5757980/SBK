<?php
/**
 * SBK Auction — the staff list
 *
 * Who exists, whether their login works, and when they last used it. Editing
 * happens on its own screen rather than in the row: a table of live selects and
 * checkboxes reads as a spreadsheet, and the thing being changed here is
 * somebody's access.
 *
 * Two things stay in the row, because both are answers to somebody standing in
 * front of you. Setting a password - a person locked out wants a new one and a
 * sentence back, not a detour through a form offering four other changes. And
 * deleting, which has nothing to fill in at all.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requirePermission('staff.view');

global $conn;

$message    = '';
$message_ok = true;
$me = currentStaff();

/* Setting a password from the row itself.
   It lives here as well as on the edit screen because it is the one thing
   somebody comes to this page already meaning to do: a person rings up locked
   out, and the answer is a new password and a sentence back - not a detour
   through an edit form where four other fields invite a change nobody asked
   for. Everything else about a login is edited there; only this is here. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    $id      = intval($_POST['staff_id'] ?? 0);
    $newpass = (string) ($_POST['new_password'] ?? '');

    if (!staffCan('staff.password')) {
        $message = 'Your role cannot set passwords for other staff'; $message_ok = false;
    } elseif ($newpass === '') {
        $message = 'Type the new password first'; $message_ok = false;
    } elseif (strlen($newpass) < 8) {
        $message = 'A new password needs at least 8 characters'; $message_ok = false;
    } else {
        $who = null;
        $st = $conn->prepare("SELECT username FROM admins WHERE id = ? LIMIT 1");
        $st->bind_param('i', $id);
        $st->execute();
        $who = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$who) {
            $message = 'That login no longer exists'; $message_ok = false;
        } else {
            $hash = password_hash($newpass, PASSWORD_BCRYPT);
            $up = $conn->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
            $up->bind_param('si', $hash, $id);
            if ($up->execute()) {
                logStaffAction('staff.password', $who['username'], $id);
                $message = 'New Password Set For ' . $who['username']
                         . ' — hand it over now, it cannot be read back.';
            } else {
                $message = 'Could not set that password'; $message_ok = false;
            }
            $up->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = intval($_POST['staff_id'] ?? 0);

    if (!staffCan('staff.delete')) {
        $message = 'Your role cannot remove staff logins'; $message_ok = false;
    } elseif ($id === (int) $me['id']) {
        // The one deletion nobody recovers from on their own.
        $message = 'You cannot delete your own login'; $message_ok = false;
    } else {
        $victim = null;
        $st = $conn->prepare("
            SELECT a.username, r.name AS role
              FROM admins a LEFT JOIN roles r ON r.id = a.role_id
             WHERE a.id = ? LIMIT 1");
        $st->bind_param('i', $id);
        $st->execute();
        $victim = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$victim) {
            $message = 'That login no longer exists'; $message_ok = false;
        } else {
            /* The last administrator cannot be removed. Roles are what grant
               the right to manage staff, so deleting the only account holding
               the administrator role locks everybody out of the screen that
               would put it back - including this one. */
            $lastAdmin = false;
            if (($victim['role'] ?? '') === 'admin') {
                $c = $conn->query("
                    SELECT COUNT(*) c FROM admins a
                      JOIN roles r ON r.id = a.role_id
                     WHERE r.name = 'admin'");
                $lastAdmin = $c && (int) $c->fetch_assoc()['c'] <= 1;
            }

            if ($lastAdmin) {
                $message = 'This is the only administrator — make somebody else '
                         . 'an administrator first'; $message_ok = false;
            } else {
                $del = $conn->prepare("DELETE FROM admins WHERE id = ?");
                $del->bind_param('i', $id);
                if ($del->execute() && $del->affected_rows > 0) {
                    logStaffAction('staff.delete', $victim['username'], $id);
                    $message = 'Deleted ' . $victim['username'];
                } else {
                    $message = 'Could not delete that login'; $message_ok = false;
                }
                $del->close();
            }
        }
    }
}

// A word from the edit screen, which redirects here after saving.
if ($message === '' && isset($_GET['saved'])) {
    $message = 'Saved' . ($_GET['saved'] === 'password' ? ' — New Password Set' : '');
}
if ($message === '' && isset($_GET['created'])) {
    $message = 'Created ' . $_GET['created'];
}

$staff = array();
$res = $conn->query("
    SELECT a.id, a.username, a.name, a.email, a.is_active, a.last_login, a.created_at,
           a.role_id, r.label AS role_label, r.name AS role_name
    FROM admins a
    LEFT JOIN roles r ON r.id = a.role_id
    ORDER BY a.id ASC
");
while ($r = $res->fetch_assoc()) { $staff[] = $r; }

$mayEdit   = staffCan('staff.edit') || staffCan('staff.password');
$mayDelete = staffCan('staff.delete');
$mayPw     = staffCan('staff.password');

$page_title = 'List Staff';
$active = 'staff';
require_once '_header.php';
?>

<p class="crumbs">Staff &rsaquo; List Staff</p>
<h1>List Staff</h1>
<p class="lede">Every in-house login. What each person can see comes from their
   role — set those on the <a href="roles.php">Roles</a> screen.</p>

<?php if ($message): ?>
  <div class="alert <?php echo $message_ok ? 'alert-success' : 'alert-error'; ?>"><?php echo sanitize($message); ?></div>
<?php endif; ?>

<div class="admin-card">
  <div class="admin-card-head">
    <h2>Existing Logins</h2>
    <span class="spacer"></span>
    <?php if (staffCan('staff.create')): ?>
      <a href="staff-create.php" class="btn btn-primary btn-sm">Create Staff</a>
    <?php endif; ?>
    <span class="page-info"><?php echo count($staff); ?> Total</span>
  </div>
  <div class="table-responsive">
    <table class="dashboard-table staff-table">
      <thead>
        <tr>
          <th>Login</th>
          <th>Name</th>
          <th>Email</th>
          <th>Last Signed In</th>
          <th>Role</th>
          <th>Status</th>
          <?php if ($mayPw): ?><th>New Password</th><?php endif; ?>
          <?php if ($mayEdit || $mayDelete): ?><th class="ta-r">Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($staff as $s): $isMe = (int) $s['id'] === (int) $me['id']; ?>
          <tr>
            <td class="mono"><strong><?php echo sanitize($s['username']); ?></strong>
              <?php if ($isMe): ?><span class="sub2">That's You</span><?php endif; ?>
            </td>
            <td><?php echo sanitize($s['name']); ?></td>
            <td><?php echo $s['email'] ? sanitize($s['email']) : '—'; ?></td>
            <td><?php echo $s['last_login'] ? formatDate($s['last_login']) : 'Never'; ?></td>
            <td><?php echo sanitize($s['role_label'] ?? '—'); ?></td>
            <td>
              <span class="pill <?php echo $s['is_active'] ? 'pill-ok' : 'pill-off'; ?>">
                <?php echo $s['is_active'] ? 'Active' : 'Disabled'; ?>
              </span>
            </td>
            <?php if ($mayPw): ?>
            <td>
              <form method="POST" class="pw-row">
                <input type="hidden" name="action" value="password">
                <input type="hidden" name="staff_id" value="<?php echo (int) $s['id']; ?>">
                <span class="pw-wrap">
                  <input type="password" name="new_password" class="input"
                         autocomplete="new-password" minlength="8"
                         id="pw<?php echo (int) $s['id']; ?>"
                         placeholder="New password">
                  <button type="button" class="pw-eye" data-for="pw<?php echo (int) $s['id']; ?>"
                          aria-label="Show password">&#128065;</button>
                </span>
                <button type="submit" class="btn btn-secondary btn-sm">Set</button>
              </form>
            </td>
            <?php endif; ?>
            <?php if ($mayEdit || $mayDelete): ?>
            <td class="ta-r">
              <div class="row-actions">
                <?php if ($mayEdit): ?>
                  <a href="staff-edit.php?id=<?php echo (int) $s['id']; ?>"
                     class="btn btn-secondary btn-sm">Edit</a>
                <?php endif; ?>
                <?php if ($mayDelete && !$isMe): ?>
                  <form method="POST" class="inline-form"
                        onsubmit="return confirm('Delete the login &quot;<?php
                            echo sanitize($s['username']); ?>&quot;? This cannot be undone.');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="staff_id" value="<?php echo (int) $s['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                  </form>
                <?php elseif ($mayDelete && $isMe): ?>
                  <span class="ro-note">—</span>
                <?php endif; ?>
              </div>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$staff): ?>
          <tr><td colspan="8" class="empty">No staff logins yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once '_footer.php'; ?>
