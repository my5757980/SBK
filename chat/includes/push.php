<?php
/**
 * Push notifications - the phone hears about a message while the app is shut.
 *
 * The owner's order, 19 September 2026: notifications that work "exactly the
 * same way as they do in a real/production application". A browser tab can
 * only chime while it is open; a phone is expected to ring with the screen off
 * and the app swiped away. That takes Google's own delivery service, Firebase
 * Cloud Messaging - what every production Android app uses - on the Firebase
 * project "sbk-chat", made in the owner's own Google account the same day.
 *
 * WHAT GOES OUT, AND TO WHOM
 *   a message in a conversation   the other person in it
 *   a message in a group          everybody in the group except the writer
 *   a call ringing                the person being rung, on the "calls" channel
 *   a call nobody picked up       the same notification, replaced by "Missed call"
 *   a call answered or put down   a silent push that takes the ringing away
 *
 * Only phones whose app has said "send here" (api/push.php) get anything, and
 * the recipients are the people the conversation already belongs to - nothing
 * about who may read what is decided in this file.
 *
 * NEVER AT THE WRITER'S EXPENSE. Everything is queued while the request runs
 * and sent after the answer has already gone back to whoever wrote
 * (register_shutdown_function, then litespeed_finish_request), so a message is
 * never kept waiting on Google, and a Google outage can never stop a message.
 *
 * THE KEY lives outside every web root, in /home/thelyfas/sbk-private/, and is
 * never printed anywhere. The hour-long access token made from it is kept in
 * the same folder, so Google is asked for one at most once an hour.
 *
 * The phone side is sbk-app/src/push.js; the payload keys (title, message,
 * subtitle, channelId, categoryId, tag, body) are the ones expo-notifications
 * reads from a data message (NotificationData.kt), and `tag` becomes the
 * notification's id - so a conversation shows one notification that keeps
 * itself current, and a missed call replaces the ringing one.
 */

define('PUSH_DIR',      '/home/thelyfas/sbk-private');
define('PUSH_KEY_FILE', PUSH_DIR . '/fcm-service-account.json');
define('PUSH_CACHE',    PUSH_DIR . '/fcm-access-token.json');
define('PUSH_LOG',      PUSH_DIR . '/push.log');
define('PUSH_PROJECT',  'sbk-chat');
define('PUSH_COLOUR',   '#18335E');

/** One line in the push log - enough to read a failure from, never a key or a token. */
function pushLog($line) {
    if (!is_dir(PUSH_DIR)) {
        return;
    }
    if ((int) @filesize(PUSH_LOG) > 200000) {
        @rename(PUSH_LOG, PUSH_LOG . '.1');
    }
    @file_put_contents(PUSH_LOG, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* ---------------------------------------------------------------- the phones */

/** Every phone this person has the app on. */
function pushTokensFor($who, $whoId) {
    $out = array();
    $st = db()->prepare("SELECT token FROM chat_push WHERE who = ? AND who_id = ?");
    if (!$st) {
        return $out;             // the table is not there yet: nobody to send to
    }
    $st->bind_param('si', $who, $whoId);
    $st->execute();
    $r = $st->get_result();
    while ($r && $row = $r->fetch_row()) {
        $out[] = (string) $row[0];
    }
    $st->close();
    return $out;
}

/**
 * This phone belongs to this person now. A phone that was somebody else's a
 * minute ago (they signed out, somebody else signed in) is simply moved: one
 * phone, one person, so a customer never receives the desk's notifications.
 */
function pushSaveToken($who, $whoId, $token) {
    $st = db()->prepare("INSERT INTO chat_push (who, who_id, token) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE who = VALUES(who), who_id = VALUES(who_id), seen_at = NOW()");
    if (!$st) {
        return false;
    }
    $st->bind_param('sis', $who, $whoId, $token);
    $ok = $st->execute();
    $st->close();
    return $ok;
}

function pushForgetToken($token) {
    $st = db()->prepare("DELETE FROM chat_push WHERE token = ?");
    if (!$st) {
        return;
    }
    $st->bind_param('s', $token);
    $st->execute();
    $st->close();
}

/* ---------------------------------------------------------------- Google */

function pushB64($s) {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/**
 * An OAuth access token for FCM, from the service account - a JWT signed with
 * its key, exchanged at Google's token endpoint. Kept until a couple of minutes
 * before it runs out.
 */
function pushAccessToken($fresh = false) {
    static $mem = null;
    if ($mem !== null && !$fresh) {
        return $mem;
    }
    $c = @json_decode((string) @file_get_contents(PUSH_CACHE), true);
    if (!$fresh && is_array($c) && !empty($c['token']) && (int) ($c['exp'] ?? 0) > time() + 120) {
        return $mem = (string) $c['token'];
    }
    $key = @json_decode((string) @file_get_contents(PUSH_KEY_FILE), true);
    if (!is_array($key) || empty($key['private_key']) || empty($key['client_email'])) {
        pushLog('no service-account key at ' . PUSH_KEY_FILE);
        return $mem = '';
    }
    $aud  = (string) ($key['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    $now  = time();
    $head = pushB64(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
    $body = pushB64(json_encode(array(
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => $aud,
        'iat'   => $now,
        'exp'   => $now + 3600,
    )));
    $sig = '';
    if (!openssl_sign($head . '.' . $body, $sig, (string) $key['private_key'], OPENSSL_ALGO_SHA256)) {
        pushLog('the key would not sign');
        return $mem = '';
    }
    $ch = curl_init($aud);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => http_build_query(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $head . '.' . $body . '.' . pushB64($sig),
        )),
    ));
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = @json_decode((string) $raw, true);
    if ($code !== 200 || empty($j['access_token'])) {
        pushLog('Google refused the key: HTTP ' . $code . ' ' . substr((string) $raw, 0, 200));
        return $mem = '';
    }
    @file_put_contents(PUSH_CACHE, json_encode(array(
        'token' => $j['access_token'],
        'exp'   => $now + (int) ($j['expires_in'] ?? 3600),
    )), LOCK_EX);
    @chmod(PUSH_CACHE, 0600);
    return $mem = (string) $j['access_token'];
}

/**
 * One push to one phone. A phone that has uninstalled the app, or a token
 * Google no longer knows, is forgotten on the spot so it is never tried again.
 *
 * @return string sent | dropped | failed | no-auth
 */
function pushSendOne($token, array $data, $ttl, $validateOnly = false) {
    $access = pushAccessToken();
    if ($access === '') {
        return 'no-auth';
    }
    $strings = array();
    foreach ($data as $k => $v) {
        if ($v !== null && $v !== '') {
            $strings[$k] = is_string($v) ? $v : (string) $v;
        }
    }
    $msg = array('message' => array(
        'token'   => $token,
        'data'    => $strings,
        'android' => array('priority' => 'HIGH', 'ttl' => max(0, (int) $ttl) . 's'),
    ));
    if ($validateOnly) {
        $msg['validate_only'] = true;
    }
    for ($try = 0; $try < 2; $try++) {
        $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . PUSH_PROJECT . '/messages:send');
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $access,
                                            'Content-Type: application/json'),
            CURLOPT_POSTFIELDS     => json_encode($msg),
        ));
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            return 'sent';
        }
        if ($code === 401 && $try === 0) {
            // The cached access token went stale early; make a new one and try once more.
            $access = pushAccessToken(true);
            if ($access === '') {
                return 'no-auth';
            }
            continue;
        }
        break;
    }
    $text = (string) $raw;
    if ($code === 404 || strpos($text, 'UNREGISTERED') !== false
        || ($code === 400 && stripos($text, 'registration token') !== false)) {
        pushForgetToken($token);
        pushLog('a phone was forgotten (HTTP ' . $code . ')');
        return 'dropped';
    }
    pushLog('send failed: HTTP ' . $code . ' ' . substr($text, 0, 300));
    return 'failed';
}

/* ---------------------------------------------------------------- the queue */

/**
 * Remember a push for after the answer has gone. The phones are looked up now,
 * while the database is certainly there; only the talking to Google waits.
 */
function pushQueue($who, $whoId, array $data, $ttl = 86400) {
    static $registered = false;
    $tokens = pushTokensFor($who, (int) $whoId);
    if (!$tokens) {
        return;
    }
    $GLOBALS['sbk_push_jobs'][] = array($tokens, $data, (int) $ttl);
    if (!$registered) {
        $registered = true;
        register_shutdown_function('pushFlush');
    }
}

function pushFlush() {
    $jobs = $GLOBALS['sbk_push_jobs'] ?? array();
    $GLOBALS['sbk_push_jobs'] = array();
    if (!$jobs) {
        return;
    }
    // The person who wrote has their answer first; Google is spoken to after.
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    if (function_exists('litespeed_finish_request')) {
        @litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    @ignore_user_abort(true);
    @set_time_limit(90);
    $n = 0;
    foreach ($jobs as $job) {
        list($tokens, $data, $ttl) = $job;
        foreach ($tokens as $token) {
            try {
                pushSendOne($token, $data, $ttl);
            } catch (\Throwable $e) {
                pushLog('push threw: ' . $e->getMessage());
            }
            if (++$n >= 80) {
                pushLog('push ceiling reached - the rest were not sent');
                return;
            }
        }
    }
}

/* ---------------------------------------------------------------- the words */

function pushClock($secs) {
    $secs = max(0, (int) $secs);
    return floor($secs / 60) . ':' . str_pad((string) ($secs % 60), 2, '0', STR_PAD_LEFT);
}

/** What the notification says the message was. */
function pushWhat($kind, $body, $secs) {
    if ($kind === 'image') { return '📷 Photo'; }
    if ($kind === 'voice') { return '🎤 Voice message' . ($secs ? ' (' . pushClock($secs) . ')' : ''); }
    if ($kind === 'file')  { return '📎 File'; }
    return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $body)), 0, 300);
}

/**
 * The thread with both people's names, and who of the two is on the receiving
 * end of something the other one did. `$fromGuest` is who ACTED.
 */
function pushThreadParties($threadId, $fromGuest) {
    $st = db()->prepare("SELECT t.id, t.guest_id, t.staff_id, t.staff_unread, t.guest_unread,
                                g.name AS guest_name, g.email AS guest_email
                           FROM chat_threads t JOIN chat_guests g ON g.id = t.guest_id
                          WHERE t.id = ? LIMIT 1");
    $st->bind_param('i', $threadId);
    $st->execute();
    $t = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$t) {
        return null;
    }
    $staffId = (int) $t['staff_id'];
    if ($fromGuest) {
        // The desk receives. It opens a conversation by its thread.
        $name = (string) $t['guest_name'];
        return array(
            'who'    => 'staff',
            'whoId'  => $staffId,
            'unread' => (int) $t['staff_unread'],
            'name'   => $name,
            'open'   => array('kind' => 'thread', 'thread' => (int) $t['id'], 'name' => $name,
                              'ini' => initials($name), 'hue' => avatarHue((string) $t['guest_email'])),
        );
    }
    // The customer receives. They open a conversation by the person they write to.
    $names = staffNames(array($staffId));
    $name  = (string) ($names[$staffId] ?? CHAT_NAME);
    return array(
        'who'    => 'guest',
        'whoId'  => (int) $t['guest_id'],
        'unread' => (int) $t['guest_unread'],
        'name'   => $name,
        'open'   => array('kind' => 'person', 'to' => $staffId, 'thread' => (int) $t['id'], 'name' => $name,
                          'ini' => initials($name), 'hue' => avatarHue($staffId . $name)),
    );
}

/* ---------------------------------------------------------------- the pushes */

/** A message in a one-to-one conversation, to whichever of the two did not write it. */
function pushThreadMessage($threadId, $fromGuest, $kind, $body, $messageId, $secs) {
    if ($kind === 'call') {
        return;                  // a call's own pushes are pushCallRinging / pushCallOver
    }
    $p = pushThreadParties((int) $threadId, $fromGuest);
    if (!$p) {
        return;
    }
    $open = $p['open'] + array('type' => 'message', 'mid' => (int) $messageId);
    pushQueue($p['who'], $p['whoId'], array(
        'title'      => $p['name'],
        'message'    => pushWhat($kind, $body, $secs),
        'subtitle'   => $p['unread'] > 1 ? $p['unread'] . ' new messages' : null,
        'channelId'  => 'messages',
        'categoryId' => 'message',
        'tag'        => 't' . (int) $threadId,
        'color'      => PUSH_COLOUR,
        'body'       => json_encode($open),
    ));
}

/** A message in a group, to everybody in it except whoever wrote it. */
function pushGroupMessage($groupId, $staffId, $kind, $body, $messageId, $secs) {
    $st = db()->prepare("SELECT id, name FROM chat_groups WHERE id = ? LIMIT 1");
    $st->bind_param('i', $groupId);
    $st->execute();
    $g = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$g) {
        return;
    }
    $names = staffNames(array((int) $staffId));
    $from  = (string) ($names[(int) $staffId] ?? 'Someone');
    $open  = array('type' => 'message', 'kind' => 'group', 'group' => (int) $g['id'],
                   'name' => (string) $g['name'], 'ini' => initials((string) $g['name']),
                   'hue' => avatarHue('g' . $g['id'] . $g['name']), 'mid' => (int) $messageId);
    $st = db()->prepare("SELECT staff_id, unread FROM chat_group_members WHERE group_id = ? AND staff_id <> ?");
    $st->bind_param('ii', $groupId, $staffId);
    $st->execute();
    $r = $st->get_result();
    while ($r && $m = $r->fetch_assoc()) {
        pushQueue('staff', (int) $m['staff_id'], array(
            'title'      => (string) $g['name'],
            'message'    => $from . ': ' . pushWhat($kind, $body, $secs),
            'subtitle'   => (int) $m['unread'] > 1 ? (int) $m['unread'] . ' new messages' : null,
            'channelId'  => 'groups',
            'categoryId' => 'message',
            'tag'        => 'g' . (int) $g['id'],
            'color'      => PUSH_COLOUR,
            'body'       => json_encode($open),
        ));
    }
    $st->close();
}

/** A call has started ringing: the person being rung hears it with the app shut. */
function pushCallRinging(array $call) {
    $fromGuest = ((int) $call['from_guest'] === 1);
    $p = pushThreadParties((int) $call['thread_id'], $fromGuest);
    if (!$p) {
        return;
    }
    $video = ($call['kind'] === 'video');
    $open  = $p['open'] + array('type' => 'call', 'call' => (int) $call['id'], 'video' => $video);
    // Lives no longer than the ringing does: a late delivery must not ring for a call that is over.
    pushQueue($p['who'], $p['whoId'], array(
        'title'      => $p['name'],
        'message'    => $video ? '📹 Incoming video call' : '📞 Incoming voice call',
        'channelId'  => 'calls',
        'categoryId' => 'call',
        'tag'        => 'call' . (int) $call['id'],
        'sticky'     => 'true',
        'color'      => PUSH_COLOUR,
        'body'       => json_encode($open),
    ), RING_SECONDS);
}

/**
 * A call is over for the person who was rung. Missed (or the caller gave up):
 * the ringing notification becomes "Missed call", as on any phone. Answered,
 * declined or ended: the ringing notification is simply taken away - a silent
 * push the app's background task acts on (src/push.js).
 */
function pushCallOver(array $call, $state) {
    $fromGuest = ((int) $call['from_guest'] === 1);
    $p = pushThreadParties((int) $call['thread_id'], $fromGuest);
    if (!$p) {
        return;
    }
    $tag = 'call' . (int) $call['id'];
    if ($state === 'missed' || $state === 'cancelled') {
        $video = ($call['kind'] === 'video');
        pushQueue($p['who'], $p['whoId'], array(
            'title'      => $p['name'],
            'message'    => $video ? '📹 Missed video call' : '📞 Missed voice call',
            'channelId'  => 'messages',
            'categoryId' => 'message',
            'tag'        => $tag,
            'color'      => PUSH_COLOUR,
            'body'       => json_encode($p['open'] + array('type' => 'message')),
        ));
        return;
    }
    pushQueue($p['who'], $p['whoId'], array(
        'body' => json_encode(array('type' => 'dismiss', 'tag' => $tag)),
    ), 120);
}
