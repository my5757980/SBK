<?php
/**
 * The live channel.
 *
 * One request answers everything a screen needs to redraw itself: the new
 * messages in the thread that is open, the side list with who is online, and
 * the total waiting for the bell. One request rather than four, because this is
 * the call that repeats.
 *
 * WHY IT POLLS, AND WHY THAT IS THE SAFE CHOICE HERE.
 * The obvious way to do "instant" in PHP is to hold the request open — long
 * polling, or an event stream. On this server that would take the whole account
 * down. cPanel caps *concurrent* requests (Entry Processes) at 30, and this one
 * account carries 28 domains including the auction portal. Thirty people with a
 * chat window open would be thirty held processes and nothing left for anybody
 * else — exactly the hanging the owner asked us to avoid.
 *
 * A poll is the opposite shape: it opens, answers in about twenty milliseconds,
 * and closes. Fifty people polling every second and a half occupy, on average,
 * well under one of those thirty. The delay a person actually feels is under a
 * second, and nothing is ever held.
 *
 * (The account does have Node.js, so a real WebSocket can be dropped in later
 * for true zero-delay push. This endpoint is deliberately the only thing that
 * would have to change.)
 */

require_once __DIR__ . '/../includes/lib.php';

$g = guest();
$s = staff();
if (!$g && !$s) {
    jsonOut(array('ok' => false, 'signedout' => true), 401);
}
refuseUntilDetails($g, $s);

$viewerIsGuest = ($g && !$s);
touchPresence($viewerIsGuest ? 'guest' : 'staff', $viewerIsGuest ? $g['id'] : $s['id'], 'chat');

$out = array('ok' => true, 'now' => gmdate('c'));

/* ---- a call, if this person is in one or being rung
   On the poll that already repeats, so a ring reaches the screen within a
   second and a half and costs no extra request. Stale calls are tidied first:
   a missed call must read as missed, not ring for ever. */
sweepCalls();
$live = liveCallFor($viewerIsGuest, (int) ($viewerIsGuest ? $g['id'] : $s['id']));
if ($live) {
    touchCall($live, $viewerIsGuest);
    $out['call'] = callOut($live, $viewerIsGuest);
}

$after = (int) ($_GET['after'] ?? 0);

/* ---- the group that is open, if one is
   A group and a conversation are never open together — the screen has one pane
   — so whichever the browser names is the one answered, and a group is asked
   first because naming one is the more specific request. Reading a group is
   membership and nothing else: an administrator sees every conversation, but a
   group is a room people were put in, and being an administrator is not an
   invitation. */
$groupId = (int) ($_GET['group'] ?? 0);
if ($groupId > 0 && !$viewerIsGuest) {
    if (!mayReadGroup($groupId)) {
        jsonOut(array('ok' => false, 'error' => 'not yours'), 403);
    }
    $out['messages'] = groupMessagesSince($groupId, $after, (int) $s['id']);

    /* Looking at it is reading it — and this is set BEFORE the receipts are
       read, so our own arrival counts towards everybody else's blue ticks on
       this same poll rather than the next one. */
    if (!empty($_GET['open'])) {
        markGroupRead($groupId, (int) $s['id']);
    }
    $out['receipts'] = groupReceipts($groupId, (int) $s['id']);
}

/* ---- the thread that is open, if one is */
$threadId = (int) ($_GET['thread'] ?? 0);
if ($threadId > 0 && $groupId <= 0) {
    $t = mayReadThread($threadId);
    if (!$t) {
        jsonOut(array('ok' => false, 'error' => 'not yours'), 403);
    }
    $out['messages'] = messagesSince($threadId, $after, $viewerIsGuest);

    /* Somebody looking at a thread is reading it. The blue ticks on the other
       side are set from here rather than from a separate call, so a message
       cannot be shown and left unread by a lost request. */
    if (!empty($_GET['open'])) {
        markRead($threadId, $viewerIsGuest);
    }

    /* And the ticks on OUR OWN messages, which the block above cannot carry.
       `messages` only returns what is newer than `after`, so a message already
       on the screen is never sent again - and its tick would therefore never
       move past the one it was drawn with. The receipt of a message is not the
       message; it changes after the message has been delivered, which is the
       whole point of it. So the last fifty of ours come back every time as
       three small fields, and the screen repaints the ticks from them.

       Fifty because a person watches the tail of a conversation, not its
       beginning, and (thread_id, id) is indexed so the cost is a seek. */
    $mine = $viewerIsGuest ? 1 : 0;
    $rc = array();
    $st = db()->prepare("SELECT id, delivered_at, read_at
                           FROM chat_messages
                          WHERE thread_id = ? AND from_guest = ?
                       ORDER BY id DESC LIMIT 50");
    $st->bind_param('ii', $threadId, $mine);
    $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) {
        $rc[] = array('id' => (int) $row['id'],
                      'd'  => !empty($row['delivered_at']),
                      'r'  => !empty($row['read_at']));
    }
    $st->close();
    $out['receipts'] = $rc;
}

/* ---- the side list */
if ($viewerIsGuest) {
    $people = array();
    foreach (staffDirectory() as $p) {
        $people[] = array(
            'id'     => (int) $p['id'],
            'name'   => $p['name'],
            'role'   => (string) $p['role_label'],
            'online' => $p['online'],
            'ini'    => initials($p['name']),
            'hue'    => avatarHue($p['id'] . $p['name']),
        );
    }
    $out['people'] = $people;
    $out['unread'] = unreadFor('guest', $g['id']);

    /* Which of those conversations have something waiting, so the list can
       carry its own little green number. */
    $per = array();
    $st = db()->prepare("SELECT staff_id, guest_unread, id FROM chat_threads WHERE guest_id = ?");
    $st->bind_param('i', $g['id']);
    $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) {
        $per[(int) $row['staff_id']] = array('thread' => (int) $row['id'],
                                             'unread' => (int) $row['guest_unread']);
    }
    $st->close();
    $out['threads'] = $per;

    /* "What's your name?" - asked by the app exactly when chat.php asks it: when
       the only name this customer has is their login or half their e-mail. The
       app asks once, when its list opens (`me=1`), not on every beat, because
       the answer needs the website's own row and it almost never changes. */
    if (!empty($_GET['me'])) {
        $login = '';
        if (!empty($g['wp_user_id'])) {
            $wu = wpUser((int) $g['wp_user_id']);
            $login = $wu ? (string) $wu['username'] : '';
        }
        $out['me'] = array('name'       => (string) $g['name'],
                           'needs_name' => looksLikeLogin($g['name'], $login, $g['email']));
    }
} else {
    $all  = staffSeesEverything();
    $list = array();
    foreach (staffThreads($s['id'], $all) as $t) {
        $list[] = array(
            'id'      => (int) $t['id'],
            'name'    => $t['guest_name'],
            'email'   => $t['guest_email'],
            'phone'   => $t['guest_phone'],
            'staff'   => $all ? (string) $t['staff_name'] : '',
            /* The agent's id, not only their name: an administrator's list is
               grouped by agent, and two people may be called the same thing. */
            'sid'     => (int) $t['staff_id'],
            'mine'    => ((int) $t['staff_id'] === (int) $s['id']),
            'preview' => (string) $t['last_preview'],
            'at'      => $t['last_message_at'],
            'unread'  => (int) $t['staff_unread'],
            'online'  => $t['guest_online'],
            'ini'     => initials($t['guest_name']),
            'hue'     => avatarHue($t['guest_email']),
        );
    }
    $out['list'] = $list;
    $out['all']  = $all;

    /* ---- and the rooms the desk made for itself
       Groups sit in the same list as the conversations, the way they do in
       WhatsApp, so the browser is handed them in the same answer. They are
       never sent to a customer. */
    $groups = array();
    foreach (groupsFor((int) $s['id']) as $gr) {
        $groups[] = array(
            'id'      => (int) $gr['id'],
            'name'    => (string) $gr['name'],
            'preview' => (string) $gr['last_preview'],
            'at'      => $gr['last_message_at'],
            'unread'  => (int) $gr['unread'],
            'members' => (int) $gr['members'],
            'owner'   => ((int) $gr['is_owner'] === 1),
            'ini'     => initials((string) $gr['name']),
            'hue'     => avatarHue('g' . $gr['id'] . $gr['name']),
        );
    }
    $out['groups'] = $groups;

    /* The bell counts both — somebody waiting is somebody waiting, whether they
       wrote in a conversation or in a room. */
    $out['unread'] = unreadFor('staff', $s['id']) + groupUnreadFor((int) $s['id']);
}

jsonOut($out);
