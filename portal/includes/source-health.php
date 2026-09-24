<?php
/**
 * Is the ACCOUNT each feed signs in with still working? Green or red, for staff.
 *
 * The owner, 24 September 2026: in Statistics and in the Auction there has to be
 * a signal - red when the ID we fetch with has a problem (it stopped, it was
 * signed out, the source blocked it), green when it is fine. Only the ID and
 * its fetching; the website's own health is a different question.
 *
 * The client, later the same day: no source names. The signal says "Auction" or
 * "Statistics" and then "working" or "not working" - nothing else - and no name
 * of a source may reach a screen, the admin panel included. So neither the
 * words shown, nor the hover text, nor the JSON behind them name a source, and
 * the source's own words (a halt reason, a refusal message) are never passed
 * on: they can carry its name. They stay in the feed's state file on the server.
 *
 * Two feeds, two IDs, and two very different places to look:
 *
 *   AUCTION     Pacific Boeki, read by pb-harvest.php on THIS server every five
 *               minutes with a member's session. Its state file says whether it
 *               halted (a lapsed login, a refusal, a check page instead of data)
 *               and when the session was last confirmed; its log says when it
 *               last ran at all.
 *
 *   STATISTICS  aaajapan, read by a job on GitHub (the source refuses this
 *               server), which reports how its sign-in went at the end of every
 *               run - see aaa-stats-ingest.php ?health=1. Silence is a signal
 *               too: it reports every run, so a long silence means GitHub is no
 *               longer running it.
 *
 * Deliberately NOT red: a run that ends early because the day's allowance is
 * spent, one slow answer, one machine turned away at the door. None of those is
 * the ID, and a signal that cries wolf is soon ignored.
 */

/** Where both feeds keep their notes - the account's home, outside every web root. */
function sourceHome() {
    return dirname(__DIR__, 2);
}

function sourceAgo($seconds) {
    $s = max(0, (int) $seconds);
    if ($s < 90) {
        return 'just now';
    }
    if ($s < 5400) {
        return round($s / 60) . ' min ago';
    }
    if ($s < 172800) {
        return round($s / 3600) . ' h ago';
    }
    return round($s / 86400) . ' days ago';
}

/**
 * One feed's answer. `text` is all a person reads: "working" or "not working".
 * `state` says which case it was, for the tests and for whoever has to fix it;
 * `detail` is the hover text - in our own words, never the source's.
 */
function sourceState($ok, $state, $detail, $at = 0) {
    return array('ok' => $ok, 'text' => $ok ? 'working' : 'not working',
                 'state' => $state, 'detail' => $detail, 'at' => (int) $at);
}

/** The auction's ID: the member session pb-harvest.php reads with. */
function sourceHealthAuction($dir = null) {
    $dir = $dir ?: sourceHome() . '/pb-harvest';     // a test hands in a folder of its own
    $st  = json_decode((string) @file_get_contents($dir . '/state.json'), true);
    if (!is_array($st)) {
        return sourceState(false, 'nostate',
            'The auction feed has left no state on the server, so nothing says its ID is fetching.');
    }
    $now    = time();
    $halt   = trim((string) ($st['halted'] ?? ''));
    $since  = trim((string) ($st['haltedAt'] ?? ''));
    $since  = $since !== '' ? ' Stopped since ' . $since . ' UTC.' : '';
    if ($halt !== '') {
        $h = strtolower($halt);
        if (strpos($h, 'login') !== false) {
            return sourceState(false, 'signedout',
                'The auction ID is no longer signed in, so nothing is being fetched. A person has to sign in '
                . 'to the source again (it asks for the "I\'m not a robot" tick) and hand the session over.' . $since);
        }
        if (strpos($h, 'refused') !== false) {
            return sourceState(false, 'refused',
                'The source refused the auction ID. Fetching has stopped and will not knock again by '
                . 'itself - a person has to look first.' . $since);
        }
        if (strpos($h, 'not json') !== false) {
            return sourceState(false, 'checkpage',
                'The source answered with a check page instead of auction data - usually a sign-in or robot '
                . 'check on the ID. Fetching has stopped.' . $since);
        }
        return sourceState(false, 'stopped',
            'The auction feed stopped itself; the reason is in its state file on the server.' . $since);
    }
    $ranAt  = (int) @filemtime($dir . '/harvest.log');
    $sessAt = (int) ($st['sessionAt'] ?? 0);
    // It runs every five minutes; three missed runs in a row is not a hiccup.
    if (!$ranAt || $now - $ranAt > 20 * 60) {
        return sourceState(false, 'notrunning',
            'The auction feed has not run ' . ($ranAt ? 'since ' . sourceAgo($now - $ranAt) : 'at all')
            . '. It should run every five minutes.', $ranAt);
    }
    // The session is confirmed every thirty minutes of work; fifty means the
    // source has not recognised the ID for a whole round and more.
    if (!$sessAt || $now - $sessAt > 50 * 60) {
        return sourceState(false, 'unconfirmed',
            'The source has not confirmed the auction ID as signed in '
            . ($sessAt ? 'since ' . sourceAgo($now - $sessAt) : 'yet') . ' - it may be down or refusing it.', $sessAt);
    }
    return sourceState(true, 'ok',
        'The auction ID is signed in and fetching. Last run ' . sourceAgo($now - $ranAt)
        . '; the ID was last confirmed ' . sourceAgo($now - $sessAt) . '.', $ranAt);
}

/** The statistics' ID: the account the job on GitHub signs in with. */
function sourceHealthStatistics($file = null) {
    $file = $file ?: sourceHome() . '/aaa-fetch/health.json';   // a test hands in a file of its own
    $h = json_decode((string) @file_get_contents($file), true);
    if (!is_array($h) || empty($h['at'])) {
        // It has reported since the day it was set up, so no report now is a fault.
        return sourceState(false, 'noreport',
            'The statistics fetcher has not reported. It does so at the end of every run, about every twenty minutes.');
    }
    $now = time();
    $age = $now - (int) $h['at'];
    $halt = trim((string) ($h['halted'] ?? ''));
    if ($halt !== '') {
        $blocked = (stripos($halt, 'block') !== false || stripos($halt, 'refus') !== false);
        return sourceState(false, $blocked ? 'blocked' : 'stopped',
            ($blocked ? 'The source refused the statistics ID. '
                      : 'The statistics fetcher stopped itself; the reason is in its report on the server. ')
            . 'It leaves the source alone for 24 hours after a stop like this, or until a person restarts it.',
            (int) $h['at']);
    }
    $login = (string) ($h['login'] ?? '');
    if ($login === 'refused') {
        return sourceState(false, 'rejected',
            'The source rejected the statistics ID\'s username or password. '
            . 'Nothing new can arrive until the details are corrected.', (int) $h['at']);
    }
    if ($login === 'nocreds') {
        return sourceState(false, 'nocreds',
            'The fetcher has no username or password to sign in with - they are set as secrets '
            . 'on its GitHub repository.', (int) $h['at']);
    }
    if ($login === 'noaccess') {
        return sourceState(false, 'noaccess',
            'The statistics ID signs in, but the statistics search is not on its page - the account may have lost '
            . 'access to statistics, or the site has changed.', (int) $h['at']);
    }
    if ((int) ($h['door'] ?? 0) >= 4) {
        return sourceState(false, 'door',
            'The source has turned the fetcher away ' . (int) $h['door'] . ' runs in a row before it could even sign in.',
            (int) $h['at']);
    }
    // It reports every run and runs about every twenty minutes, with a schedule
    // behind it every half hour; an hour and a half of silence is not a hiccup.
    if ($age > 90 * 60) {
        return sourceState(false, 'silent',
            'The statistics fetcher last reported ' . sourceAgo($age) . '. It runs on GitHub about every twenty '
            . 'minutes - it has probably been stopped there, or its secrets have changed.', (int) $h['at']);
    }
    // Said as it is: a quiet run that found nothing new never signs in, so the
    // last sign-in that WORKED is named rather than claimed for this run.
    $lastOk = (int) ($h['last_ok'] ?? 0);
    $signed = $lastOk ? ' Last successful sign-in ' . sourceAgo($now - $lastOk) . '.' : '';
    if (!empty($h['spent'])) {
        return sourceState(true, 'spent',
            'The statistics ID is fine. Today\'s allowance of ' . number_format((int) ($h['budget'] ?? 0))
            . ' requests is used, so it rests until 00:00 UTC (05:00 in Pakistan). Last report ' . sourceAgo($age) . '.'
            . $signed, (int) $h['at']);
    }
    if ($login === 'ok') {
        return sourceState(true, 'ok',
            'The statistics ID signed in and fetched on its last run (' . (int) ($h['pages'] ?? 0) . ' pages, '
            . (int) ($h['new'] ?? 0) . ' new sales). Last report ' . sourceAgo($age) . '.', (int) $h['at']);
    }
    return sourceState(true, 'quiet',
        'No problem reported with the statistics ID. Its last run found nothing new to ask the source for, so it '
        . 'did not need to sign in. Last report ' . sourceAgo($age) . '.' . $signed, (int) $h['at']);
}

function sourceHealthAll() {
    return array('auction' => sourceHealthAuction(), 'statistics' => sourceHealthStatistics());
}

/**
 * The signal itself - for staff only. A customer never sees whether one of our
 * accounts is in trouble; it is the desk's to know and to fix.
 */
function sourceSignal($which, $extraClass = '') {
    if (!function_exists('isAdmin') || !isAdmin()) {
        return '';
    }
    $h = ($which === 'statistics') ? sourceHealthStatistics() : sourceHealthAuction();
    $name  = ($which === 'statistics') ? 'Statistics' : 'Auction';
    $class = $h['ok'] ? 'is-ok' : 'is-bad';
    return '<span class="src-sig ' . $class . ($extraClass !== '' ? ' ' . $extraClass : '') . '" data-src="'
         . htmlspecialchars($which) . '" role="status" title="' . htmlspecialchars($h['detail']) . '">'
         . '<i class="src-dot" aria-hidden="true"></i>'
         . '<span class="src-name">' . htmlspecialchars($name) . '</span>'
         . '<span class="src-text">' . htmlspecialchars($h['text']) . '</span></span>';
}
