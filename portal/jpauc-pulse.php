<?php
/**
 * Watch the source's own counters and write down when they move.
 *
 * The question this answers is when jpauc publishes - once a day, hourly, or
 * continuously - and nobody here knows. Guessing produced a sweep timed to
 * nothing in particular; a week of readings will say it plainly.
 *
 * Both section landing pages print their stock per auction house:
 *
 *     Kyouyuu (34736)   JU Kyouyuu (22375)   JAA HAA (17226) ...
 *
 * One request each, so a reading every ten minutes costs 288 a day against a
 * budget of five thousand. Only changes are recorded, so the log stays short
 * and reads as a list of publication times.
 *
 * Once the pattern is known the sweep can follow it: a full pass just after
 * the source publishes, and nothing but this pulse in between.
 *
 *     php jpauc-pulse.php
 *     jpauc-pulse.php?t=TOKEN          reading now
 *     jpauc-pulse.php?t=TOKEN&log=1    what has been seen so far
 */

require_once __DIR__ . '/includes/config.php';

/* The token lives in the server's .env, never in the code (21 September 2026).
   Empty refuses everything: hash_equals('', '') is true, which would be a door
   standing open. */
define('PULSE_TOKEN', env_get('HARVEST_TOKEN', ''));
const PULSE_STATE = __DIR__ . '/jpauc-pulse-state.json';
const PULSE_LOG   = __DIR__ . '/jpauc-pulse.log';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (PULSE_TOKEN === '' || !hash_equals(PULSE_TOKEN, (string) ($_GET['t'] ?? ''))) {
        http_response_code(404);
        exit;
    }
}

if (!$isCli && isset($_GET['log'])) {
    echo is_file(PULSE_LOG) ? file_get_contents(PULSE_LOG) : "abhi kuch nahi\n";
    exit;
}

function pulseFetch($url) {
    // The site hands out a session cookie and redirects to itself expecting it
    // back. Without somewhere to keep it the redirect never resolves - the
    // oneprice landing page bounced twenty times and gave up, which read here
    // as "cannot reach it" for hours.
    $jar = __DIR__ . '/jpauc-pulse-cookies.txt';

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1,
        // the oneprice landing lists a hundred and sixty thousand vehicles'
        // worth of counters and is slow to build; 25s was not enough for it
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_ENCODING       => '',
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ));
    $b = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $b) ? $b : null;
}

/**
 * The per-house counts a landing page prints, as name => number.
 */
/** Country headings roll up the houses listed beneath them. Counting both
 *  doubles the total, which made oneprice read as 327,684 instead of 163,846. */
function pulseIsCountry($name) {
    return in_array(strtolower($name), array('japan', 'hong kong', 'korea', 'china'), true);
}

function pulseCounts($html) {
    // A tag becomes a space, not nothing. Dropping it outright glued the
    // heading to the label after it - "August 2026" and "17 Monday" arrived as
    // "August 202617 Monday", which made the log unreadable and stopped the
    // country headings being recognised so they could be left out of the sum.
    $text = preg_replace('/<[^>]+>/', ' ', $html);
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    $out = array();
    if (preg_match_all('/([A-Za-z][A-Za-z0-9 ._-]{1,28}?)\s*\(\s*([0-9]{2,7})\s*\)/', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            // The match reaches back as far as it is allowed, so it drags in
            // whatever heading sits before the label - "Select All Location"
            // ahead of a country, "August 2026" ahead of a sale day. Trim both
            // off, or the same auction house is filed under two names on two
            // different days and every reading looks like a change.
            $name = trim($x[1]);
            $name = preg_replace('/^.*?\ball\b\s*(location|auction)?\s*/i', '', $name);
            $name = trim(preg_replace('/^[A-Za-z]+\s+\d{4}\s*/', '', $name));

            // the page also prints years and other bracketed numbers
            if ($name === '' || is_numeric($name) || pulseIsCountry($name)) {
                continue;
            }
            $out[$name] = (int) $x[2];
        }
    }
    return $out;
}

$sections = array(
    'auction'  => 'https://jpauc.com/auction',
    'oneprice' => 'https://jpauc.com/oneprice',
);

$prev = is_file(PULSE_STATE)
    ? (json_decode((string) file_get_contents(PULSE_STATE), true) ?: array())
    : array();

$now = date('Y-m-d H:i');
$jst = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('H:i');
$state = array();
$lines = array();

foreach ($sections as $key => $url) {
    $html = pulseFetch($url);
    if ($html === null) {
        echo "{$key}: pohnch nahi saka\n";
        $state[$key] = $prev[$key] ?? array();
        continue;
    }
    $counts = pulseCounts($html);
    $state[$key] = $counts;
    $was = $prev[$key] ?? array();

    $total = array_sum($counts);
    $wasTotal = array_sum($was);

    if (!$was) {
        $lines[] = sprintf('%s  JST %s  %-9s pehli reading  total %d', $now, $jst, $key, $total);
        continue;
    }
    if ($total === $wasTotal) {
        continue;                       // nothing moved; not worth a line
    }

    $moved = array();
    foreach ($counts as $house => $n) {
        $before = $was[$house] ?? 0;
        if ($n !== $before) {
            $moved[] = $house . ' ' . ($n - $before > 0 ? '+' : '') . ($n - $before);
        }
    }
    foreach ($was as $house => $n) {
        if (!isset($counts[$house])) {
            $moved[] = $house . ' gayab';
        }
    }
    $lines[] = sprintf('%s  JST %s  %-9s total %d (%+d)  |  %s',
        $now, $jst, $key, $total, $total - $wasTotal, implode(', ', $moved));
}

file_put_contents(PULSE_STATE, json_encode($state), LOCK_EX);
if ($lines) {
    file_put_contents(PULSE_LOG, implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
}

foreach ($sections as $key => $url) {
    printf("%-9s total %d\n", $key, array_sum($state[$key] ?? array()));
    if (!$isCli && isset($_GET['detail'])) {
        foreach (($state[$key] ?? array()) as $house => $n) {
            printf("    %-22s %7d\n", $house, $n);
        }
    }
}
echo $lines ? "\nbadla:\n" . implode("\n", $lines) . "\n" : "\nkoi tabdeeli nahi\n";
