<?php
/**
 * SBK Auction — roles
 *
 * Which roles exist. Add one, rename one, remove one nobody holds.
 *
 * What each role may *do* is the Permissions screen next door. The two were one
 * page, and every role added pushed the list of roles further down under forty
 * tickboxes belonging to the role above it. A role is a short thing; its
 * permissions are a long one. They read better apart.
 *
 * The Administrator role is shown but never editable — it is the role that
 * hands permissions out, so it can neither sign away its own access nor be
 * deleted.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '_roles.php';

requirePermission('roles.view');

global $conn;

$message = '';
$message_ok = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ------------------------------------------------------------ add a role */
    if ($action === 'add_role' && !staffCan('roles.create')) {
        $message = 'Your role cannot add roles'; $message_ok = false;
    } elseif ($action === 'add_role') {
        $label = trim($_POST['label'] ?? '');
        $name  = roleKey($label);
        if ($label === '' || $name === '') {
            $message = 'Give the role a name'; $message_ok = false;
        } else {
            $stmt = $conn->prepare("INSERT INTO roles (name, label, is_system) VALUES (?, ?, 0)");
            $stmt->bind_param('ss', $name, $label);
            if ($stmt->execute()) {
                $new = $conn->insert_id;
                $stmt->close();
                logStaffAction('role.create', $label, $new);
                // Straight on to the tickboxes: a role that exists and permits
                // nothing is the state nobody meant to leave behind.
                header('Location: permissions.php?role=' . (int) $new . '&added=1');
                exit;
            }
            $stmt->close();
            $message = 'A role by that name already exists'; $message_ok = false;
        }
    }

    /* --------------------------------------------------------- rename a role */
    if ($action === 'rename_role' && !staffCan('roles.rename')) {
        $message = 'Your role cannot rename roles'; $message_ok = false;
    } elseif ($action === 'rename_role') {
        $id    = intval($_POST['role_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $row   = roleById($conn, $id);

        if (!$row) {
            $message = 'Unknown role'; $message_ok = false;
        } elseif ($label === '') {
            $message = 'A role needs a name'; $message_ok = false;
        } elseif ((int) $row['is_system'] === 1) {
            // The key is what the code checks against, and renaming a built-in
            // role would leave every requirePermission() looking for something
            // that no longer answers.
            $message = 'Built-in roles cannot be renamed'; $message_ok = false;
        } else {
            // Only the label changes. Permissions and staff hang off the role's
            // id, so the key could safely change too - but the key is what a
            // person writes down elsewhere, and moving it under them is worse
            // than a key that no longer matches its label.
            $stmt = $conn->prepare("UPDATE roles SET label = ? WHERE id = ?");
            $stmt->bind_param('si', $label, $id);
            $stmt->execute();
            $stmt->close();
            logStaffAction('role.rename', $label, $id);
            $message = 'Renamed to ' . $label;
        }
    }

    /* --------------------------------------------------------- delete a role */
    if ($action === 'delete_role' && !staffCan('roles.delete')) {
        $message = 'Your role cannot remove roles'; $message_ok = false;
    } elseif ($action === 'delete_role') {
        $id  = intval($_POST['role_id'] ?? 0);
        $row = roleById($conn, $id);
        $n   = $row ? roleStaffCount($conn, $id) : 0;

        if (!$row) {
            $message = 'Unknown role'; $message_ok = false;
        } elseif ((int) $row['is_system'] === 1) {
            $message = 'Built-in roles cannot be removed'; $message_ok = false;
        } elseif ($n > 0) {
            // Deleting it would leave those logins holding a role that no longer
            // exists: able to sign in, permitted nothing, with no screen saying
            // why. They get moved first.
            $message = $row['label'] . ' is still held by ' . $n . ' '
                     . ($n === 1 ? 'person' : 'people')
                     . '. Move them to another role first.';
            $message_ok = false;
        } else {
            $conn->begin_transaction();
            $d1 = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $d1->bind_param('i', $id); $d1->execute(); $d1->close();
            $d2 = $conn->prepare("DELETE FROM roles WHERE id = ?");
            $d2->bind_param('i', $id); $d2->execute(); $d2->close();
            $conn->commit();
            logStaffAction('role.delete', $row['label'], $id);
            $message = 'Removed the ' . $row['label'] . ' role';
        }
    }
}

$roles = allRoles($conn);
$total = count(allPermissionKeys());

$page_title = 'Roles';
$active = 'roles';
require_once '_header.php';
?>

<h1>Roles</h1>
<p class="lede">A role is a job. Name it here; set what that job may see and do
   on the <a href="permissions.php">Permissions</a> screen.</p>

<?php if ($message): ?>
  <div class="alert <?php echo $message_ok ? 'alert-success' : 'alert-error'; ?>"><?php echo sanitize($message); ?></div>
<?php endif; ?>

<div class="admin-card">
  <div class="admin-card-head">
    <h2>Roles</h2>
    <span class="spacer"></span>
    <span class="page-info"><?php echo count($roles); ?> in total</span>
  </div>

  <div class="admin-card-body">
    <?php if (staffCan('roles.create')): ?>
    <form method="POST" class="role-add">
      <input type="text" name="label" class="input" required maxlength="60"
             placeholder="Name a new role — e.g. Shipping desk">
      <input type="hidden" name="action" value="add_role">
      <button type="submit" class="btn btn-primary">Add role</button>
    </form>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="dashboard-table roles-table">
        <thead>
          <tr>
            <th>Role</th>
            <th>Key</th>
            <th>Held by</th>
            <th>Permissions</th>
            <th>&nbsp;</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($roles as $r): ?>
            <?php
              $locked = $r['locked'];
              $got    = count($r['perms']);
              $pct    = $total ? round($got * 100 / $total) : 0;
              $n      = $r['people'];
            ?>
            <tr>
              <td>
                <?php if ($locked || !staffCan('roles.rename')): ?>
                  <b><?php echo sanitize($r['label']); ?></b>
                  <?php if ($locked): ?>
                    <span class="role-lock" title="Built in — cannot be renamed or removed">built in</span>
                  <?php endif; ?>
                <?php else: ?>
                  <form method="POST" class="role-rename">
                    <input type="hidden" name="action" value="rename_role">
                    <input type="hidden" name="role_id" value="<?php echo (int) $r['id']; ?>">
                    <input type="text" name="label" class="input" required maxlength="60"
                           value="<?php echo sanitize($r['label']); ?>">
                    <button type="submit" class="btn btn-secondary btn-xs">Rename</button>
                  </form>
                <?php endif; ?>
              </td>
              <td><code class="role-key"><?php echo sanitize($r['name']); ?></code></td>
              <td><?php echo $n; ?> <?php echo $n === 1 ? 'person' : 'people'; ?></td>
              <td class="role-perm-cell">
                <span class="role-meter"><span style="width:<?php echo $pct; ?>%"></span></span>
                <span class="role-meter-n"><?php echo $got; ?> / <?php echo $total; ?></span>
              </td>
              <td class="role-acts">
                <a class="btn btn-secondary btn-xs" href="permissions.php?role=<?php echo (int) $r['id']; ?>">
                  <?php echo ($locked || !staffCan('roles.permissions')) ? 'View' : 'Edit'; ?> permissions
                </a>
                <?php if (!$locked && staffCan('roles.delete')): ?>
                  <form method="POST" class="role-del"
                        onsubmit="return confirm('Remove the <?php echo sanitize($r['label']); ?> role?')">
                    <input type="hidden" name="action" value="delete_role">
                    <input type="hidden" name="role_id" value="<?php echo (int) $r['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-xs"
                            <?php echo $n > 0 ? 'disabled title="Move its people to another role first"' : ''; ?>>
                      Delete
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once '_footer.php'; ?>
