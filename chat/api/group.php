<?php
/**
 * Groups — making one, naming it, and who is in it.
 *
 * The owner asked for groups "like WhatsApp": a room the desk can make for
 * itself and talk in. Writing INTO one goes through send.php and upload.php
 * like every other message; this file is only the room itself.
 *
 * Two rules run through all of it:
 *
 *   1. **Only staff.** Every id offered as a member is asked of the WEBSITE
 *      before it is written down — the same question send.php asks before a
 *      customer may write to somebody. A customer's id, a made-up id, or
 *      somebody whose role was taken away this morning is refused, so the
 *      owner's rule ("customers should not appear in the chat sidebar") stays
 *      true even against a request that never came from the sidebar.
 *   2. **Only members.** Reading a group, and writing in it, is membership and
 *      nothing else. An administrator sees every CONVERSATION because the desk's
 *      work is his to see — but a group is a room people were put in, and being
 *      an administrator is not an invitation. Changing a group is the owner's.
 */

require_once __DIR__ . '/../includes/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(array('ok' => false, 'error' => 'POST only'), 405);
}

$s = staff();
if (!$s) {
    /* A customer has no groups and never will. They are not told "no" in a way
       that admits a group exists — as far as their side of the chat is
       concerned, this door is not there. */
    jsonOut(array('ok' => false, 'signedout' => true), 401);
}
$me = (int) $s['id'];

$do  = (string) ($_POST['do'] ?? '');
$gid = (int) ($_POST['group'] ?? 0);

/** The people this person may put in a group: the desk, minus themselves. */
function pickable($me) {
    $out = array();
    foreach (staffDirectory() as $p) {
        if ((int) $p['id'] === (int) $me) {
            continue;
        }
        $out[] = array(
            'id'     => (int) $p['id'],
            'name'   => $p['name'],
            'role'   => (string) $p['role_label'],
            'online' => $p['online'],
            'ini'    => initials($p['name']),
            'hue'    => avatarHue($p['id'] . $p['name']),
        );
    }
    return $out;
}

/** A group, with everybody in it, shaped for the browser. */
function groupOut($g, $me) {
    $ids   = groupMemberIds((int) $g['id']);
    $names = staffNames($ids);
    $on    = array();
    foreach (staffDirectory() as $p) {
        if ($p['online']) {
            $on[(int) $p['id']] = true;
        }
    }
    $members = array();
    foreach ($ids as $id) {
        $name = (string) ($names[$id] ?? 'Someone');
        $members[] = array(
            'id'     => $id,
            'name'   => $name,
            'ini'    => initials($name),
            'hue'    => avatarHue($id . $name),
            'online' => isset($on[$id]),
            'owner'  => ownsGroup((int) $g['id'], $id),
            'me'     => ($id === (int) $me),
        );
    }
    return array(
        'id'      => (int) $g['id'],
        'name'    => (string) $g['name'],
        'members' => $members,
        'count'   => count($members),
        'owner'   => ownsGroup((int) $g['id'], $me),
        /* The same face poll.php gives the row in the list, so a group that has
           just been made does not change colour on the next beat. */
        'ini'     => initials((string) $g['name']),
        'hue'     => avatarHue('g' . $g['id'] . $g['name']),
    );
}

/* ------------------------------------------------------------------ make one */
if ($do === 'create') {
    $name = (string) ($_POST['name'] ?? '');
    $ids  = $_POST['members'] ?? array();
    if (!is_array($ids)) {
        $ids = array();
    }
    if (count($ids) > 200) {
        jsonOut(array('ok' => false, 'error' => 'That is too many people for one group.'), 400);
    }
    if (trim($name) === '') {
        jsonOut(array('ok' => false, 'error' => 'Give the group a name.'), 400);
    }
    $made = createGroup($name, $me, $ids);
    $g = groupRow($made['id']);
    jsonOut(array('ok' => true, 'group' => groupOut($g, $me), 'skipped' => $made['skipped']));
}

/* ---------------------------------------------------- who there is to pick from */
if ($do === 'people') {
    jsonOut(array('ok' => true, 'people' => pickable($me)));
}

/* Everything past here is about a group that must already exist, and that this
   person must already be in. */
$g = mayReadGroup($gid);
if (!$g) {
    jsonOut(array('ok' => false, 'error' => 'That group is not yours.'), 403);
}

if ($do === 'info') {
    jsonOut(array('ok' => true, 'group' => groupOut($g, $me), 'people' => pickable($me)));
}

/* Walking out is the one change anybody may make, because it is about
   themselves and not about the room. */
if ($do === 'leave') {
    $what = removeGroupMember($gid, $me);
    jsonOut(array('ok' => true, 'left' => true, 'gone' => ($what === 'gone')));
}

if (!ownsGroup($gid, $me)) {
    jsonOut(array('ok' => false, 'error' => 'Only whoever made the group can change it.'), 403);
}

if ($do === 'rename') {
    if (!renameGroup($gid, (string) ($_POST['name'] ?? ''))) {
        jsonOut(array('ok' => false, 'error' => 'Give the group a name.'), 400);
    }
    jsonOut(array('ok' => true, 'group' => groupOut(groupRow($gid), $me)));
}

if ($do === 'add') {
    $ids = $_POST['members'] ?? array();
    if (!is_array($ids)) {
        $ids = array();
    }
    $n = addGroupMembers($gid, $ids);
    jsonOut(array('ok' => true, 'added' => $n, 'group' => groupOut(groupRow($gid), $me)));
}

if ($do === 'remove') {
    $who = (int) ($_POST['staff'] ?? 0);
    if ($who === $me) {
        /* Taking yourself out is leaving, and leaving hands the group on rather
           than leaving it ownerless. Sending it the other way would be a quiet
           difference in behaviour depending on which button was pressed. */
        jsonOut(array('ok' => false, 'error' => 'Use Leave group.'), 400);
    }
    if (!inGroup($gid, $who)) {
        jsonOut(array('ok' => false, 'error' => 'They are not in this group.'), 400);
    }
    removeGroupMember($gid, $who);
    jsonOut(array('ok' => true, 'group' => groupOut(groupRow($gid), $me)));
}

jsonOut(array('ok' => false, 'error' => 'Unknown request.'), 400);
