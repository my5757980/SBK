<?php
/**
 * "I am still here."
 *
 * Called every fifteen seconds by whoever has a window open, and — this is the
 * part the owner asked for — by every page of the WEBSITE as well. A member of
 * staff is green because they are signed in to sbkautotrading.com, not because
 * they happen to have the chat tab in front of them. Talal reading the blog is
 * as available as Talal staring at the desk.
 *
 * It answers with the number waiting, because the website needs that for the
 * bell it shows in its own corner. Two jobs, one call, on the request that
 * repeats.
 *
 * The website is a different origin, so it says who is calling and is answered
 * with the matching header — and only those origins. A heartbeat is a small
 * thing to leak but there is no reason to leak it to anybody.
 */

require_once __DIR__ . '/../includes/lib.php';

$origin  = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$allowed = array_map('trim', explode(',', SITE_ORIGINS));
if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

/**
 * Is this person being rung right now? For the WEBSITE's corner card.
 *
 * The chat's own page learns of a call from its poll. But a member of staff
 * reading the website — or a customer who closed the panel — has no chat page
 * open at all, and a call that rings where nobody can hear it is a missed call
 * before it starts. So the heartbeat every page of the website already sends
 * carries it too, and the website shows a card that says who is calling.
 */
function ringingFor($viewerIsGuest, $meId) {
    sweepCalls();
    $c = liveCallFor($viewerIsGuest, $meId);
    if (!$c || $c['state'] !== 'ringing' || !callIsIncoming($c, $viewerIsGuest)) {
        return null;
    }
    return array('id' => (int) $c['id'], 'from' => callPeerName($c, $viewerIsGuest),
                 'kind' => $c['kind'], 'thread' => (int) $c['thread_id']);
}

$where = (string) ($_GET['from'] ?? 'chat');
$where = in_array($where, array('chat', 'site'), true) ? $where : 'chat';

/**
 * A person carrying the WEBSITE's session rather than the chat's.
 *
 * The website and the chat are separate origins with separate cookies, so a
 * page on sbkautotrading.com cannot hand over its own login. It hands over a
 * SIGNED line instead — the same one enter.php takes — which is checked here
 * against the secret before anything at all is stamped. A bare id in the query
 * string would let anybody mark anybody green.
 */
$fromSite = handoverUser((string) ($_GET['wp'] ?? ''));
if ($fromSite > 0) {
    $u = wpUser($fromSite);

    /* A fresh line goes back with every beat.
       A handover is deliberately short-lived, but a member of staff who leaves
       the website open all afternoon must stay green all afternoon. Renewing it
       on each beat does both: the window that is really there keeps a valid
       line, and a line that leaked somewhere else is worthless five minutes
       after it left the browser that is rotating it. */
    $fresh = $u ? handoverFor((int) $u['id']) : '';

    if ($u && $u['is_staff']) {
        touchPresence('staff', (int) $u['id'], $where);
        jsonOut(array('ok' => true, 'who' => 'staff', 'staff' => true,
                      'name' => $u['name'], 'token' => $fresh,
                      'unread' => unreadFor('staff', (int) $u['id']),
                      'last'   => latestWaiting('staff', (int) $u['id']),
                      'ringing' => ringingFor(false, (int) $u['id'])));
    }
    if ($u) {
        $g = guestForWpUser($u);
        touchPresence('guest', (int) $g['id'], $where);
        jsonOut(array('ok' => true, 'who' => 'guest', 'staff' => false,
                      'name' => $u['name'], 'token' => $fresh,
                      'unread' => unreadFor('guest', (int) $g['id']),
                      'last'   => latestWaiting('guest', (int) $g['id']),
                      'ringing' => ringingFor(true, (int) $g['id'])));
    }
}

/* And the ordinary case: somebody with this app's own session, on this app's
   own page. */
$s = staff();
if ($s) {
    touchPresence('staff', (int) $s['id'], $where);
    jsonOut(array('ok' => true, 'who' => 'staff', 'staff' => true,
                  'name' => $s['name'],
                  'unread' => unreadFor('staff', (int) $s['id']),
                  'last'   => latestWaiting('staff', (int) $s['id']),
                  'ringing' => ringingFor(false, (int) $s['id'])));
}

$g = guest();
if ($g) {
    touchPresence('guest', (int) $g['id'], $where);
    jsonOut(array('ok' => true, 'who' => 'guest', 'staff' => false,
                  'name' => $g['name'],
                  'unread' => unreadFor('guest', (int) $g['id']),
                  'last'   => latestWaiting('guest', (int) $g['id']),
                      'ringing' => ringingFor(true, (int) $g['id'])));
}

jsonOut(array('ok' => false, 'signedout' => true), 401);
