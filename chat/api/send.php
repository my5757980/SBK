<?php
/**
 * Send a message.
 *
 * A visitor names the member of staff they are writing to and the thread is
 * made if it is their first word. A member of staff answers inside a thread
 * that already exists, and may only answer in one that is theirs — checked
 * here, in the database, and not in the markup that drew the reply box.
 */

require_once __DIR__ . '/../includes/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(array('ok' => false, 'error' => 'POST only'), 405);
}

$g = guest();
$s = staff();
if (!$g && !$s) {
    jsonOut(array('ok' => false, 'signedout' => true), 401);
}
refuseUntilDetails($g, $s);
$viewerIsGuest = ($g && !$s);

$body = trim((string) ($_POST['body'] ?? ''));
if ($body === '') {
    jsonOut(array('ok' => false, 'error' => 'Nothing to send.'), 400);
}
// Long enough for anything anybody types, short enough that nobody can post a
// novel into a column meant for a sentence.
$body = mb_substr($body, 0, 4000);

if ($viewerIsGuest) {
    $staffId = (int) ($_POST['to'] ?? 0);

    /* The person written to must be a member of the desk on the WEBSITE right
       now. A made-up id, ANOTHER CUSTOMER'S id, or somebody whose role was taken
       away this morning is refused here - which keeps the owner's rule
       ("customers should not appear in the chat sidebar") true even against a
       request that never came from the sidebar at all. */
    $who = wpUser($staffId);
    if (!$who || !$who['is_listed']) {
        jsonOut(array('ok' => false, 'error' => 'That person is not available.'), 400);
    }

    $t  = threadFor((int) $g['id'], $staffId);
    $id = addMessage((int) $t['id'], true, null, 'text', $body);
} else {
    /* A room the desk made for itself. Answered and returned here rather than
       below, because a group message carries who wrote it — in a room of six a
       bubble with no name on it is a bubble you cannot answer — and because it
       belongs to no thread. */
    $groupId = (int) ($_POST['group'] ?? 0);
    if ($groupId > 0) {
        if (!mayReadGroup($groupId)) {
            jsonOut(array('ok' => false, 'error' => 'That group is not yours.'), 403);
        }
        $id = addGroupMessage($groupId, (int) $s['id'], 'text', $body);
        $st = db()->prepare("SELECT * FROM chat_messages WHERE id = ? LIMIT 1");
        $st->bind_param('i', $id);
        $st->execute();
        $m = $st->get_result()->fetch_assoc();
        $st->close();
        jsonOut(array('ok' => true, 'group' => $groupId,
                      'message' => groupMessageOut($m, (int) $s['id'],
                                                   staffNames(array((int) $s['id'])))));
    }

    $threadId = (int) ($_POST['thread'] ?? 0);
    $t = mayReadThread($threadId);
    if (!$t) {
        jsonOut(array('ok' => false, 'error' => 'That conversation is not yours.'), 403);
    }
    $id = addMessage((int) $t['id'], false, (int) $s['id'], 'text', $body);
}

/* The sender gets their own message straight back, so the bubble appears the
   instant they press send rather than on the next poll. */
$st = db()->prepare("SELECT * FROM chat_messages WHERE id = ? LIMIT 1");
$st->bind_param('i', $id);
$st->execute();
$m = $st->get_result()->fetch_assoc();
$st->close();

jsonOut(array('ok' => true, 'thread' => (int) $t['id'],
              'message' => messageOut($m, $viewerIsGuest)));
