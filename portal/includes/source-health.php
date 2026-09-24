<?php
/**
 * Is the ACCOUNT each feed signs in with still working? Green or red, for staff.
 *
 * The owner, 24 September 2026: in Statistics and in the Auction there has to be
 * a signal - red when the ID we fetch with has a problem (it stopped, it was
 * signed out, the source blocked it), green when it is fine. Only the ID and
 * its fetching; the website's own health is a different question.
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

function sourceState($ok, $text, $detail, $at = 0) {
    return array('ok' => $ok, 'text' => $text, 'detail' => $detail, 'at' => (int) $at);
}

/** The auction's ID: the Pacific Boeki member session pb-harvest.php reads with. */
function sourceHealthAuction($dir = null) {
    $dir = $dir ?: sourceHome() . '/pb-harvest';     // a test hands in a folder of its own
    $st  = json_decode((string) @file_get_contents($dir . '/state.json'), true);
    if (!is_array($st)) {
        return sourceState(false, 'No word from the feed',
            'The Pacific Boeki harvester has left no state on the server, so nothing says the ID is fetching.');
    }
    $now    = time();
    $halt   = trim((string) ($st['halted'] ?? ''));
    $since  = trim((string) ($st['haltedAt'] ?? ''));
    $since  = $since !== '' ? ' Stopped since ' . $since . ' UTC.' : '';
    if ($halt !== '') {
        $h = strtolower($halt);
        if (strpos($h, 'login') !== false) {
            return sourceState(false, 'ID signed out',
                'The Pacific Boeki ID is no longer signed in, so nothing is being fetched. A person has to sign in '
                . 'to Pacific Boeki again (it asks for the "I\'m not a robot" tick) and hand the session over.' . $since);
        }
        if (strpos($h, 'refused') !== false) {
            return sourceState(false, 'ID refused',
                'Pacific Boeki refused the ID (' . $halt . '). Fetching has stopped and will not knock again by '
                . 'itself - a person has to look first.' . $since);
        }
        if (strpos($h, 'not json') !== false) {
            return sourceState(false, 'Check page',
                'Pacific Boeki answered with a check page instead of auction data - usually a sign-in or robot '
                . 'check on the ID. Fetching has stopped.' . $since);
        }
        return sourceState(false, 'Stopped', 'The auction feed stopped itself: ' . $halt . '.' . $since);
    }
    $ranAt  = (int) @filemtime($dir . '/harvest.log');
    $sessAt = (int) ($st['sessionAt'] ?? 0);
    // It runs every five minutes; three missed runs in a row is not a hiccup.
    if (!$ranAt || $now - $ranAt > 20 * 60) {
        return sourceState(false, 'Not running',
            'The auction feed has not run ' . ($ranAt ? 'since ' . sourceAgo($now - $ranAt) : 'at all')
            . '. It should run every five minutes.', $ranAt);
    }
    // The session is confirmed every thirty minutes of work; fifty means the
    // source has not recognised the ID for a whole round and more.
    if (!$sessAt || $now - $sessAt > 50 * 60) {
        return sourceState(false, 'ID not confirmed',
            'Pacific Boeki has not confirmed the ID as signed in '
            . ($sessAt ? 'since ' . sourceAgo($now - $sessAt) : 'yet') . ' - the source may be down or refusing it.', $sessAt);
    }
    return sourceState(true, 'ID working',
        'The Pacific Boeki ID is signed in and fetching. Last run ' . sourceAgo($now - $ranAt)
        . '; the ID was last confirmed ' . sourceAgo($now - $sessAt) . '.', $ranAt);
}

/** The statistics' ID: the aaajapan account the job on GitHub signs in with. */
function sourceHealthStatistics($file = null) {
    $file = $file ?: sourceHome() . '/aaa-fetch/health.json';   // a test hands in a file of its own
    $h = json_decode((string) @file_get_contents($file), true);
    if (!is_array($h) || empty($h['at'])) {
        return sourceState(null, 'Waiting',
            'The statistics fetcher has not reported yet. It does so at the end of every run, about every twenty minutes.');
    }
    $now = time();
    $age = $now - (int) $h['at'];
    $halt = trim((string) ($h['halted'] ?? ''));
    if ($halt !== '') {
        $blocked = (stripos($halt, 'block') !== false || stripos($halt, 'refus') !== false);
        return sourceState(false, $blocked ? 'ID refused' : 'Stopped',
            ($blocked ? 'aaajapan refused the ID (' . $halt . '). '
                      : 'The statistics fetcher stopped itself: ' . $halt . '. ')
            . 'It leaves the source alone for 24 hours after a stop like this, or until a person restarts it.',
            (int) $h['at']);
    }
    $login = (string) ($h['login'] ?? '');
    if ($login === 'refused') {
        return sourceState(false, 'ID rejected',
            'aaajapan rejected the ID\'s username or password: ' . trim((string) ($h['why'] ?? '')) . '. '
            . 'Nothing new can arrive until the details are corrected.', (int) $h['at']);
    }
    if ($login === 'nocreds') {
        return sourceState(false, 'ID details missing',
            'The fetcher has no aaajapan username or password to sign in with - they are set as secrets '
            . 'on its GitHub repository (AAA_USER, AAA_PASS).', (int) $h['at']);
    }
    if ($login === 'noaccess') {
        return sourceState(false, 'No statistics access',
            'The aaajapan ID signs in, but the statistics search is not on its page - the account may have lost '
            . 'access to statistics, or the site has changed.', (int) $h['at']);
    }
    if ((int) ($h['door'] ?? 0) >= 4) {
        return sourceState(false, 'Turned away',
            'aaajapan has turned the fetcher away ' . (int) $h['door'] . ' runs in a row before it could even sign in.',
            (int) $h['at']);
    }
    // It reports every run and runs about every twenty minutes, with a schedule
    // behind it every half hour; an hour and a half of silence is not a hiccup.
    if ($age > 90 * 60) {
        return sourceState(false, 'Not running',
            'The statistics fetcher last reported ' . sourceAgo($age) . '. It runs on GitHub about every twenty '
            . 'minutes - it has probably been stopped there, or its secrets have changed.', (int) $h['at']);
    }
    if (!empty($h['spent'])) {
        return sourceState(true, 'ID working',
            'The aaajapan ID is fine. Today\'s allowance of ' . number_format((int) ($h['budget'] ?? 0))
            . ' requests is used, so it rests until 00:00 UTC (05:00 in Pakistan). Last report ' . sourceAgo($age) . '.',
            (int) $h['at']);
    }
    return sourceState(true, 'ID working',
        'The aaajapan ID is signed in and fetching. Last report ' . sourceAgo($age) . '.', (int) $h['at']);
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
    $name  = ($which === 'statistics') ? 'aaajapan ID' : 'Pacific Boeki ID';
    $class = $h['ok'] === true ? 'is-ok' : ($h['ok'] === false ? 'is-bad' : 'is-wait');
    return '<span class="src-sig ' . $class . ($extraClass !== '' ? ' ' . $extraClass : '') . '" data-src="'
         . htmlspecialchars($which) . '" role="status" title="' . htmlspecialchars($h['detail']) . '">'
         . '<i class="src-dot" aria-hidden="true"></i>'
         . '<span class="src-name">' . htmlspecialchars($name) . '</span>'
         . '<span class="src-text">' . htmlspecialchars($h['text']) . '</span></span>';
}
