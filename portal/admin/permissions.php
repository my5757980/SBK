<?php
/**
 * SBK Auction — permissions
 *
 * One role at a time. Pick it from the strip along the top, tick what that job
 * may do, save.
 *
 * This used to sit under the roles list, every role's forty tickboxes stacked
 * one after another, so the page grew by a screenful with each role added and
 * the thing you had come to change was never the thing in front of you. Showing
 * one role means the page is the same size whether the company has three roles
 * or thirty.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '_roles.php';

requirePermission('roles.view');

// Reading what a role may do is useful to anyone who can open this screen;
// changing it is a separate grant. Without that grant the boxes are a report.
$mayEdit = staffCan('roles.permissions');

global $conn;

$message = '';
$message_ok = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$mayEdit) {
        $message = 'Your role cannot change what other roles may do'; $message_ok = false;
    } else {
        $id  = intval($_POST['role_id'] ?? 0);
        $row = roleById($conn, $id);

        if (!$row) {
            $message = 'Unknown role'; $message_ok = false;
        } elseif ($row['name'] === 'admin') {
            $message = 'The Administrator role always holds every permission'; $message_ok = false;
        } else {
            // Only keys the catalogue knows. A tick posted for something that no
            // longer exists would sit in the table for ever, granting nothing
            // and explaining nothing.
            $valid = allPermissionKeys();
            $want  = array_values(array_intersect((array) ($_POST['perm'] ?? array()), $valid));

            $conn->begin_transaction();
            $d = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $d->bind_param('i', $id); $d->execute(); $d->close();

            if ($want) {
                $i = $conn->prepare("INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)");
                foreach ($want as $p) {
                    $i->bind_param('is', $id, $p);
                    $i->execute();
                }
                $i->close();
            }
            $conn->commit();

            logStaffAction('role.permissions', $row['label'], $id);
            $message = 'Saved — ' . $row['label'] . ' now holds '
                     . count($want) . ' of ' . count($valid) . ' permissions';
        }
    }
}

$roles = allRoles($conn);
if (!$roles) {
    $message = 'No roles exist yet'; $message_ok = false;
}

// Which role are we looking at? The one asked for, else the first that is not
// the administrator - opening on a role that cannot be edited would make the
// screen look broken to somebody who has just arrived.
$want = intval($_GET['role'] ?? 0);
$role = null;
foreach ($roles as $r) {
    if ($r['id'] == $want) { $role = $r; break; }
}
if (!$role) {
    foreach ($roles as $r) {
        if (!$r['locked']) { $role = $r; break; }
    }
}
if (!$role && $roles) { $role = $roles[0]; }

$catalogue = permissionCatalogue();
$valid     = allPermissionKeys();

$page_title = 'Permissions';
$active = 'permissions';
require_once '_header.php';
?>

<h1>Permissions</h1>
<p class="lede">What each job is allowed to do. Pick a role, tick what it may
   see and do, and save — the change applies the next time that person loads a
   page, so nobody has to sign out. Roles themselves are named on the
   <a href="roles.php">Roles</a> screen.</p>

<?php if (!empty($_GET['added'])): ?>
  <div class="alert alert-success">Role created. Now tick what it may do — until
     you do, it can sign in and reach nothing.</div>
<?php endif; ?>

<?php if ($message): ?>
  <div class="alert <?php echo $message_ok ? 'alert-success' : 'alert-error'; ?>"><?php echo sanitize($message); ?></div>
<?php endif; ?>

<?php if (!$role): ?>
  <div class="admin-card"><div class="admin-card-body">
    <p class="hint">There are no roles to set permissions on yet.
       Add one on the <a href="roles.php">Roles</a> screen.</p>
  </div></div>
<?php else: ?>

<!-- Every role along the top, so moving between them is one click and the
     page never grows past one role's worth of boxes. -->
<nav class="role-strip">
  <?php foreach ($roles as $r): ?>
    <a class="role-chip<?php echo $r['id'] == $role['id'] ? ' is-on' : ''; ?>"
       href="permissions.php?role=<?php echo (int) $r['id']; ?>">
      <?php echo sanitize($r['label']); ?>
      <span class="role-chip-n"><?php echo count($r['perms']); ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php
  $locked = $role['locked'];
  $got    = count($role['perms']);
  $n      = $role['people'];
?>
<div class="admin-card perm-card">
  <div class="admin-card-head">
    <h2><?php echo sanitize($role['label']); ?></h2>
    <span class="status-badge status-<?php echo $locked ? 'accepted' : 'open'; ?>">
      <?php echo $got; ?> of <?php echo count($valid); ?>
    </span>
    <span class="spacer"></span>
    <span class="page-info"><?php echo $n; ?> <?php echo $n === 1 ? 'person holds' : 'people hold'; ?> this role</span>
  </div>

  <div class="admin-card-body">
    <?php if ($locked): ?>
      <p class="hint">The Administrator role always holds every permission — it is
         the role that grants them, so it cannot sign away its own access.</p>
    <?php elseif (!$mayEdit): ?>
      <p class="hint">You can read this but not change it.</p>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="role_id" value="<?php echo (int) $role['id']; ?>">

      <div class="perm-groups">
        <?php foreach ($catalogue as $group => $perms): ?>
          <?php
            $inGroup = array_keys($perms);
            $on = count(array_intersect($inGroup, $role['perms']));
          ?>
          <section class="perm-group">
            <header class="perm-group-h">
              <?php // one tick for the whole group: setting eleven boxes one at
                    // a time is how a role ends up half-granted ?>
              <label class="perm-all">
                <input type="checkbox" class="perm-group-all"
                       <?php echo $on === count($inGroup) ? 'checked' : ''; ?>
                       <?php echo ($locked || !$mayEdit) ? 'disabled' : ''; ?>>
                <b><?php echo sanitize($group); ?></b>
              </label>
              <span class="perm-count"><?php echo $on; ?>/<?php echo count($inGroup); ?></span>
            </header>
            <div class="perm-list">
              <?php foreach ($perms as $key => $what): ?>
                <label class="perm-item">
                  <input type="checkbox" name="perm[]" value="<?php echo sanitize($key); ?>"
                         <?php echo in_array($key, $role['perms'], true) ? 'checked' : ''; ?>
                         <?php echo ($locked || !$mayEdit) ? 'disabled' : ''; ?>>
                  <span class="perm-text">
                    <span class="perm-what"><?php echo sanitize($what); ?></span>
                    <code class="perm-key"><?php echo sanitize($key); ?></code>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>

      <?php if (!$locked && $mayEdit): ?>
        <div class="perm-save">
          <button type="submit" class="btn btn-primary">Save <?php echo sanitize($role['label']); ?></button>
          <span class="hint">Applies the next time that person loads a page.</span>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php endif; ?>

<?php require_once '_footer.php'; ?>
