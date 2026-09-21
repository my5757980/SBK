<?php
/**
 * Shared ground between the Roles screen and the Permissions screen.
 *
 * The two were one page. A role is a short thing - a name and how many people
 * hold it - while its permissions are forty tickboxes, so putting both together
 * meant the list of roles was pushed further down the page with every role
 * added. They are separate screens now, and this is what they both need.
 */

/** A role by id, or null. */
function roleById($conn, $id) {
    $st = $conn->prepare("SELECT id, name, label, is_system FROM roles WHERE id = ? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** How many staff hold this role. */
function roleStaffCount($conn, $id) {
    $st = $conn->prepare("SELECT COUNT(*) c FROM admins WHERE role_id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $n = (int) $st->get_result()->fetch_assoc()['c'];
    $st->close();
    return $n;
}

/** The stored key for a label: lowercase, underscores, nothing else. */
function roleKey($label) {
    $k = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label));
    return trim($k, '_');
}

/**
 * Every role, each carrying the permissions it holds and how many people hold it.
 *
 * The administrator is reported as holding everything rather than whatever its
 * rows happen to say - that is what staffCan() does for it, and a screen that
 * showed anything else would be describing a rule the code does not follow.
 *
 * @return array
 */
function allRoles($conn) {
    $valid = allPermissionKeys();

    $counts = array();
    $res = $conn->query("SELECT role_id, COUNT(*) c FROM admins WHERE role_id IS NOT NULL GROUP BY role_id");
    while ($r = $res->fetch_assoc()) { $counts[(int) $r['role_id']] = (int) $r['c']; }

    $out = array();
    $res = $conn->query("SELECT id, name, label, is_system FROM roles ORDER BY is_system DESC, label ASC");
    while ($r = $res->fetch_assoc()) {
        $r['locked'] = ($r['name'] === 'admin');
        $r['perms']  = $r['locked'] ? $valid : expandPermissions(rolePermissions((int) $r['id']));
        $r['people'] = $counts[(int) $r['id']] ?? 0;
        $out[] = $r;
    }
    return $out;
}
