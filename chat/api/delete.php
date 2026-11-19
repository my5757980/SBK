<?php
/**
 * Delete one of YOUR OWN messages ("delete for everyone", like WhatsApp).
 *
 * The sender - a customer for their own line, a member of staff for theirs -
 * removes a message they wrote. The text and any picture/voice are wiped and
 * the media file is deleted; the other side turns the bubble into a "deleted"
 * placeholder on its next poll. Ownership is checked in the database, not in the
 * markup that drew the little menu. There is no time limit.
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

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    jsonOut(array('ok' => false, 'error' => 'No message named.'), 400);
}

$meId = $viewerIsGuest ? 0 : (int) $s['id'];
$done = deleteMessage($id, $viewerIsGuest, $meId);
if (!$done) {
    jsonOut(array('ok' => false, 'error' => 'You can only delete your own message.'), 403);
}

jsonOut(array('ok' => true, 'id' => (int) $done));
