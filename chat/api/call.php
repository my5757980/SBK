<?php
/**
 * A call's handshake — start, answer, decline, cancel, end, and "where is it".
 *
 * The call itself is WebRTC: sound and picture travel browser to browser and
 * never come here. What comes here is the paperwork two browsers need to find
 * each other — the caller's offer and the answer to it — and the state of the
 * call, which both sides poll for exactly as they poll for messages.
 *
 * WHY THE WHOLE OFFER IN ONE GO. Browsers normally trickle their connection
 * details across in dozens of little messages as they discover them. Over a
 * chat that polls every second and a half that would take the best part of a
 * minute. Instead each side waits a moment for its details to be complete and
 * sends them all at once: two messages, one each way, and the call connects in
 * a few seconds.
 *
 * Every action checks, here and in the database, that the person asking is one
 * of the two people the call is between. An administrator who may READ every
 * conversation may not ring into or pick up somebody else's call.
 */

require_once __DIR__ . '/../includes/lib.php';

$g = guest();
$s = staff();
if (!$g && !$s) {
    jsonOut(array('ok' => false, 'signedout' => true), 401);
}
refuseUntilDetails($g, $s);
$viewerIsGuest = ($g && !$s);
$me = $viewerIsGuest ? $g : $s;

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
sweepCalls();

/* ------------------------------------------------------------------ start */
if ($action === 'start') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(array('ok' => false, 'error' => 'POST only'), 405);
    }
    $kind  = ((string) ($_POST['kind'] ?? '') === 'video') ? 'video' : 'voice';
    $offer = (string) ($_POST['offer'] ?? '');
    if (strlen($offer) < 50 || strlen($offer) > 200000 || strpos($offer, 'v=0') === false) {
        jsonOut(array('ok' => false, 'error' => 'That call could not be set up.'), 400);
    }

    if ($viewerIsGuest) {
        $to = wpUser((int) ($_POST['to'] ?? 0));
        if (!$to || !$to['is_listed']) {
            jsonOut(array('ok' => false, 'error' => 'That person is not available.'), 400);
        }
        $t = threadFor((int) $g['id'], (int) $to['id']);
    } else {
        $t = mayReadThread((int) ($_POST['thread'] ?? 0));
        /* Only the member of staff the conversation belongs to rings the
           customer. An administrator reading someone else's conversation does
           not get to phone that someone else's customer from it. */
        if (!$t || (int) $t['staff_id'] !== (int) $s['id']) {
            jsonOut(array('ok' => false, 'error' => 'That conversation is not yours.'), 403);
        }
    }

    // One call at a time, for both people.
    if (liveCallFor($viewerIsGuest, (int) $me['id'])) {
        jsonOut(array('ok' => false, 'error' => 'You are already in a call.'), 409);
    }
    $row = db()->query("SELECT guest_id, staff_id FROM chat_threads WHERE id = " . (int) $t['id'])->fetch_assoc();
    $calleeBusy = $viewerIsGuest ? liveCallFor(false, (int) $row['staff_id'])
                                 : liveCallFor(true,  (int) $row['guest_id']);
    if ($calleeBusy) {
        jsonOut(array('ok' => false, 'busy' => true, 'error' => 'They are on another call. Try again in a minute.'), 409);
    }

    $fg = $viewerIsGuest ? 1 : 0;
    $st = db()->prepare("INSERT INTO chat_calls (thread_id, from_guest, kind, offer, guest_seen, staff_seen)
                         VALUES (?,?,?,?,NOW(),NOW())");
    $tid = (int) $t['id'];
    $st->bind_param('iiss', $tid, $fg, $kind, $offer);
    $st->execute();
    $id = $st->insert_id;
    $st->close();

    $call = callRow($id);
    // The person being rung hears it even with the app shut (includes/push.php).
    try {
        pushCallRinging($call);
    } catch (\Throwable $e) {
        pushLog('ringing push not queued: ' . $e->getMessage());
    }
    jsonOut(array('ok' => true, 'call' => callOut($call, $viewerIsGuest)));
}

/* ---- everything below is about one call that already exists */
$call = callRow((int) ($_POST['call'] ?? $_GET['call'] ?? 0));
if (!$call || !isCallParty($call, $viewerIsGuest, (int) $me['id'])) {
    jsonOut(array('ok' => false, 'error' => 'That call is not yours.'), 403);
}
$incoming = callIsIncoming($call, $viewerIsGuest);

/* ------------------------------------------------------------------ state */
if ($action === 'state') {
    touchCall($call, $viewerIsGuest);
    $out = callOut($call, $viewerIsGuest);
    // Each side is handed only the half of the handshake it needs.
    if ($incoming && $call['state'] === 'ringing') {
        $out['offer'] = (string) $call['offer'];
    }
    if (!$incoming && $call['state'] === 'accepted') {
        $out['answer'] = (string) $call['answer'];
    }
    jsonOut(array('ok' => true, 'call' => $out));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(array('ok' => false, 'error' => 'POST only'), 405);
}

/* ----------------------------------------------------------------- answer */
if ($action === 'answer') {
    if (!$incoming || $call['state'] !== 'ringing') {
        jsonOut(array('ok' => false, 'error' => 'That call is no longer ringing.'), 409);
    }
    $answer = (string) ($_POST['answer'] ?? '');
    if (strlen($answer) < 50 || strlen($answer) > 200000 || strpos($answer, 'v=0') === false) {
        jsonOut(array('ok' => false, 'error' => 'That call could not be answered.'), 400);
    }
    $st = db()->prepare("UPDATE chat_calls SET state = 'accepted', answer = ?, answered_at = NOW(),
                                guest_seen = NOW(), staff_seen = NOW()
                          WHERE id = ? AND state = 'ringing'");
    $st->bind_param('si', $answer, $call['id']);
    $st->execute();
    $ok = $st->affected_rows > 0;
    $st->close();
    if (!$ok) {
        jsonOut(array('ok' => false, 'error' => 'That call is no longer ringing.'), 409);
    }
    try {
        pushCallOver($call, 'answered');
    } catch (\Throwable $e) {
        pushLog('answered push not queued: ' . $e->getMessage());
    }
    jsonOut(array('ok' => true, 'call' => callOut(callRow((int) $call['id']), $viewerIsGuest)));
}

/* ---------------------------------------------- decline / cancel / end */
if ($action === 'decline' || $action === 'cancel' || $action === 'end') {
    if ($call['state'] === 'ringing') {
        // Not yet answered: the one ringing declines, the one calling cancels.
        finishCall($call, $incoming ? 'declined' : 'cancelled');
    } elseif ($call['state'] === 'accepted') {
        finishCall($call, !empty($_POST['failed']) ? 'failed' : 'ended');
    }
    jsonOut(array('ok' => true, 'call' => callOut(callRow((int) $call['id']), $viewerIsGuest)));
}

jsonOut(array('ok' => false, 'error' => 'Unknown action.'), 400);
