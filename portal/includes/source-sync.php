<?php
/**
 * How far each feed is behind its source - the number beside every signal.
 *
 * The owner, 21 October 2026: next to "Auction / Statistics is working" show the
 * real difference ("asal farq"), and let it change by itself as it moves - 0 as
 * "fully synced", otherwise how many are still to come.
 *
 * Asks no source anything. Every figure comes from what our own feeds left on
 * this server, with the same arithmetic as the desk's own checks:
 *
 *   STATISTICS  the source's total for its window (the fetcher reads it off the
 *               source's page and hands it in with every auction survey:
 *               stat_total for stat_date) against our rows in that same window -
 *               93 sale days ending on stat_date (on 19 September its oldest day
 *               was 18 June). Anchored on the source's date, NOT on today's: the
 *               source moves its window when it publishes a new day (~21:00 UTC),
 *               and a window that moved at Japan's midnight would show a whole
 *               day's sales as "pending" for six hours every day.
 *               (sbk-tools/statsgap.py)
 *
 *   AUCTION A   Pacific Boeki's own count, from the harvester's survey
 *               (state.json), against our rows for those sale days: the lots on
 *               offer plus the lots with a result that PB still lists - sold,
 *               unsold, negotiated, withdrawn, cancelled. PB shows those for a
 *               while; we take them off the list and keep them as records. What
 *               PB lists and we do not hold is pending - normally 0.
 *               (sbk-tools/pbdiff.py, "HISAB ... +0")
 *
 *   AUCTION B   the second feed's survey (auction-survey.json): each hall once,
 *               its count against what PB already holds there (B never brings
 *               those in again) and what B holds; the rest is still to come.
 *               (sbk-tools/bgap.py, "still to come")
 *
 * The sums run at most every five minutes and are kept in one small file. A
 * page only ever reads that file; the signals' own API refreshes it.
 */

const SOURCE_SYNC_TTL = 300;

/** A lot with a result (pbdiff.py's RESULT): off the customers' list, kept as a record. */
function sourceSyncResults() {
    return array('sold', 'unsold', 'negotiate sold', 'withdrawn', 'cancel');
}

function sourceSyncFile() {
    $d = sourceHome() . '/sbk-data/auction';
    return (is_dir($d) ? $d : sourceHome()) . '/sync-gaps.json';
}

function sourceSyncDb() {
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        return $GLOBALS['conn'];
    }
    return function_exists('getDatabaseConnection') ? getDatabaseConnection() : null;
}

/** Statistics: the source's total for its 93 days against ours for the same days. */
function sourceSyncStatistics(mysqli $conn) {
    $st = json_decode((string) @file_get_contents(sourceDataDir('aaa-fetch') . '/auction-halls.json'), true);
    $sv = (is_array($st) && isset($st['survey']) && is_array($st['survey'])) ? $st['survey'] : array();
    $total = (int) ($sv['stat_total'] ?? 0);
    $date  = DateTime::createFromFormat('!d.m.Y', trim((string) ($sv['stat_date'] ?? '')));
    // Nothing handed in yet, or a figure too old to stand for today (the survey runs every few minutes).
    if ($total <= 0 || !$date || time() - (int) ($sv['at'] ?? 0) > 36 * 3600) {
        return null;
    }
    $to = $date->format('Y-m-d');
    $q = $conn->prepare('SELECT COUNT(*) FROM car_stats
                          WHERE sold_on >= DATE_SUB(?, INTERVAL 92 DAY) AND sold_on < DATE_ADD(?, INTERVAL 1 DAY)');
    $q->bind_param('ss', $to, $to);
    $q->execute();
    $ours = (int) $q->get_result()->fetch_row()[0];
    $q->close();
    return array('pending' => max(0, $total - $ours), 'total' => $total, 'ours' => $ours, 'date' => $to);
}

/** Auction A: PB's own count against the lots on offer plus the results PB still lists. */
function sourceSyncA(mysqli $conn) {
    $st = json_decode((string) @file_get_contents(sourceDataDir('pb-harvest') . '/state.json'), true);
    if (!is_array($st) || empty($st['parts']) || !is_array($st['parts'])) {
        return null;
    }
    $today = (string) $conn->query('SELECT CURDATE()')->fetch_row()[0];
    $notB  = "NOT (car_id LIKE 'aj-%' AND source_url LIKE 'https://bid.aaajapan.com%')";
    $pb  = 0;
    $cut = array();     // day|house -> the house's last read, less 15 minutes: rows older were not on PB's list then
    foreach ($st['parts'] as $p) {
        if (!is_array($p) || (string) ($p['d'] ?? '') < $today) {
            continue;
        }
        $pb += (int) ($p['n'] ?? 0);
        $readAt = (int) ($p['readAt'] ?? 0);
        if ($readAt > 0) {
            $cut[$p['d'] . '|' . strtolower(trim((string) ($p['h'] ?? '')))] = $readAt - 900;
        }
    }
    $results = sourceSyncResults();
    $offer = 0;
    $res   = 0;
    $q = $conn->query("SELECT COALESCE(NULLIF(status,''),'available') s, COUNT(*) n FROM cars
                        WHERE source_section = 'japan' AND auction_on >= CURDATE() AND $notB GROUP BY s");
    while ($q && ($w = $q->fetch_assoc())) {
        if (strncmp($w['s'], 'available', 9) === 0) {
            $offer += (int) $w['n'];
        } elseif (in_array($w['s'], $results, true)) {
            $res += (int) $w['n'];
        }
    }
    // Results PB has already dropped from its list: ours until the day ends, not in PB's count.
    $dropped = 0;
    $q = $conn->query("SELECT auction_on d, auction h, UNIX_TIMESTAMP(last_updated) u FROM cars
                        WHERE source_section = 'japan' AND auction_on >= CURDATE() AND $notB
                          AND status IN ('sold','unsold','negotiate sold','withdrawn','cancel')");
    while ($q && ($w = $q->fetch_assoc())) {
        $k = $w['d'] . '|' . strtolower(trim((string) $w['h']));
        if (isset($cut[$k]) && (int) $w['u'] < $cut[$k]) {
            $dropped++;
        }
    }
    $listed = $res - $dropped;
    return array('pending' => max(0, $pb - ($offer + $listed)), 'pb' => $pb, 'offer' => $offer, 'concluded' => $listed);
}

/** Auction B: each hall of the second feed's survey once - its count, less PB's and ours there. */
function sourceSyncB(mysqli $conn) {
    $dir = sourceDataDir('aaa-fetch');
    $sv  = json_decode((string) @file_get_contents($dir . '/auction-survey.json'), true);
    $st  = json_decode((string) @file_get_contents($dir . '/auction-halls.json'), true);
    if (!is_array($sv) || empty($sv['halls']) || !is_array($sv['halls'])) {
        return null;
    }
    $tz    = new DateTimeZone('Asia/Tokyo');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    // The survey names a sale day by its day of the month: the nearest such date from yesterday on.
    $dateOf = function ($dom) use ($today, $tz) {
        $t = new DateTime($today . ' 12:00', $tz);
        for ($i = -1; $i <= 8; $i++) {
            $x = (clone $t)->modify(($i >= 0 ? '+' : '') . $i . ' day');
            if ((int) $x->format('j') === (int) $dom) {
                return $x->format('Y-m-d');
            }
        }
        return $today;
    };
    // The second feed's hall name -> PB's: learnt from matched lots (votes), else the known pairs.
    $known = array('CAA Tohoku' => 'CAA Touhoku', 'TAA Tohoku' => 'TAA Touhoku', 'TAA Kanto' => 'TAA Kantou',
        'ZERO Shonan' => 'ZERO Syonan', 'TAA Minami Kyushu' => 'TAA Minamikyu', 'KCAA Minami Kyushu' => 'KCAA M Kyushu',
        'ISUZU Tokyo' => 'Isuzu Tokyo', 'ISUZU Kyushu' => 'Isuzu Kyushu', 'ISUZU Kobe' => 'Isuzu Kobe',
        'AEP Nyusatsu' => 'AEP Gifu', 'LUM Hokkaido Nyusatsu' => 'LUM Sapporo');
    $pbName = function ($h) use ($st, $known) {
        $v = (is_array($st) && isset($st['votes'][$h]) && is_array($st['votes'][$h])) ? $st['votes'][$h] : array();
        if ($v) {
            arsort($v);
            return (string) key($v);
        }
        return $known[$h] ?? preg_replace('/\s+Nyusatsu$/i', '', $h);
    };
    $pb = array();
    $q = $conn->query("SELECT auction_on d, LOWER(TRIM(auction)) h, COUNT(*) n FROM cars
                        WHERE auction_on >= '$today' AND source_url LIKE 'https://pacificboeki.jp%' GROUP BY d, h");
    while ($q && ($w = $q->fetch_assoc())) {
        $pb[$w['d'] . '|' . $w['h']] = (int) $w['n'];
    }
    $ours = array();
    $q = $conn->query("SELECT auction_on d, SUBSTRING_INDEX(SUBSTRING_INDEX(source_url, '?h=', -1), '#', 1) h, COUNT(*) n
                         FROM cars WHERE auction_on >= '$today' AND source_url LIKE 'https://bid.aaajapan.com%' GROUP BY d, h");
    while ($q && ($w = $q->fetch_assoc())) {
        $k = $w['d'] . '|' . rawurldecode($w['h']);
        $ours[$k] = ($ours[$k] ?? 0) + (int) $w['n'];
    }
    // A hall the survey lists under several days lists the same lots under each (Aux Mobility; the
    // tenders, which PB files on one day): judge each hall once, over all its days together.
    $byHall = array();
    foreach ($sv['halls'] as $h) {
        if (!is_array($h) || count($h) < 4 || (int) $h[3] <= 0) {
            continue;
        }
        $date = $dateOf((int) $h[0]);
        $name = trim((string) $h[2]);
        $byHall[$name]['days'][$date] = ($byHall[$name]['days'][$date] ?? 0) + (int) $h[3];
    }
    $pending = 0;
    $onPb = 0;
    foreach ($byHall as $name => $x) {
        $aaa = max($x['days']);
        $p = 0;
        $o = 0;
        foreach (array_keys($x['days']) as $date) {
            $p += $pb[$date . '|' . strtolower(trim($pbName($name)))] ?? 0;
            $o += $ours[$date . '|' . $name] ?? 0;
        }
        $onPb += min($aaa, $p);
        $pending += max(0, max(0, $aaa - $p) - $o);
    }
    return array('pending' => $pending, 'onsale' => (int) ($sv['onsale'] ?? 0), 'on_pb' => $onPb);
}

/**
 * The three numbers - from the file, refreshed when $refresh asks for it and it
 * is more than five minutes old. One refresh at a time (the others keep the
 * last answer); a sum that fails is left out rather than guessed.
 */
function sourceSync($refresh = false) {
    $f = sourceSyncFile();
    $c = json_decode((string) @file_get_contents($f), true);
    $c = is_array($c) ? $c : null;
    if (!$refresh || ($c && time() - (int) ($c['at'] ?? 0) < SOURCE_SYNC_TTL)) {
        return $c;
    }
    $lock = @fopen($f . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        return $c;
    }
    try {
        $conn = sourceSyncDb();
        $out = array('at' => time());
        foreach (array('statistics' => 'sourceSyncStatistics', 'a' => 'sourceSyncA', 'b' => 'sourceSyncB') as $k => $fn) {
            try {
                $out[$k] = $conn ? $fn($conn) : null;
            } catch (Throwable $e) {
                $out[$k] = null;
            }
        }
        if (@file_put_contents($f . '.tmp', json_encode($out)) !== false) {
            @rename($f . '.tmp', $f);
        }
        return $out;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
