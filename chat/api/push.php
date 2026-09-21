<?php
/**
 * "Send my notifications to this phone" - and, on signing out, "stop".
 *
 * The app calls this after it signs in (and whenever Google gives the phone a
 * new token), with the Firebase token that identifies this installation. It is
 * filed against whoever the request signs in as - a customer or a member of
 * the desk - so the pushes in includes/push.php go to the right people and to
 * nobody else. A phone that changes hands is moved, never shared.
 *
 *   POST token=...            this phone is mine now
 *   POST do=forget&token=...  I have signed out on this phone
 */

require_once __DIR__ . '/../includes/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(array('ok' => false, 'error' => 'POST only'), 405);
}

$token = trim((string) ($_POST['token'] ?? ''));
if (strlen($token) < 20 || strlen($token) > 4096 || !preg_match('/^[A-Za-z0-9:_\-\.]+$/', $token)) {
    jsonOut(array('ok' => false, 'error' => 'That is not a notification token.'), 400);
}

/* Forgetting needs no sign-in: the token itself is the proof, and a phone
   whose pass has already expired must still be able to say "stop". */
if (($_POST['do'] ?? '') === 'forget') {
    pushForgetToken($token);
    jsonOut(array('ok' => true, 'forgotten' => true));
}

$g = guest();
$s = staff();
if (!$g && !$s) {
    jsonOut(array('ok' => false, 'signedout' => true), 401);
}

$who   = $s ? 'staff' : 'guest';
$whoId = $s ? (int) $s['id'] : (int) $g['id'];
if (!pushSaveToken($who, $whoId, $token)) {
    jsonOut(array('ok' => false, 'error' => 'Notifications could not be set up just now.'), 500);
}
jsonOut(array('ok' => true, 'who' => $who));
