<?php
/**
 * What the two Statistics pages share - the list (statistics.php) and a single
 * sale's own page (statistics-detail.php): a sale's photographs, and what its
 * result really was.
 *
 * Nothing here belongs to the auction; the auction's pages never load it.
 */

/**
 * The photographs of a past sale: array('thumb' => url, 'full' => url) or null.
 *
 * The source stores three picture tokens per lot and serves them from its own
 * image host. It refuses DATACENTRE addresses - this server, GitHub, any VPS -
 * which is why the photographs cannot be fetched or copied here; but it serves
 * an ordinary home or mobile connection with **no login, no referer and no
 * cookie at all** (measured 15 September 2026, including tokens stored the day
 * before and sales from July). The customer's browser is on exactly such a
 * connection, so the page hands it the address and it collects the picture
 * itself - the same arrangement the auction list already has with its own
 * picture host.
 *
 * Only `&h=50` is honoured for a smaller copy (66x50); every other height comes
 * back as 32 bytes of nothing. So: the thumbnail is h=50 and the full picture is
 * the bare address, 640x480.
 *
 * ALL of a lot's pictures are returned, and the cell puts them in a `.lot-shots`
 * strip - the same wrapper the auction list uses - because that is what tells the
 * lightbox which pictures belong together. Showing only the first, with the strip
 * missing, made the set fall back to the whole `tbody`: clicking one car opened
 * "1 / 30" and the arrows walked through every other row on the page.
 */
function statPhotos($row) {
    $t = $row['photos'] ?? '';
    if (is_string($t)) {
        $t = json_decode($t, true) ?: array();
    }
    /* THE SAME PICTURE IS NOT TWO PICTURES.
       For some halls the source gives the same token twice - on 17 September
       2026 ten of the thirty lots on the first page did, all from Aux Mobility.
       Three tiles were drawn, two of them identical, and the viewer counted
       "1 / 3" and then showed the same photograph again when the reader stepped
       forward. The owner read that as the arrows being broken; the arrows were
       fine, there was simply nothing new to show.
       Dropped here as well as at ingest, because this puts the nine hundred
       thousand rows already stored right immediately, without waiting for each
       one to be read again. */
    $out  = array();
    $seen = array();
    foreach ((is_array($t) ? $t : array()) as $tok) {
        $tok = trim((string) $tok);
        if ($tok === '' || !preg_match('/^[A-Za-z0-9_-]{8,}$/', $tok)) {
            continue;
        }
        if (isset($seen[$tok])) {
            continue;
        }
        $seen[$tok] = true;
        $base = 'https://8.ajes.com/imgs/' . rawurlencode($tok);
        $out[] = array('thumb' => $base . '&h=50', 'full' => $base);
    }
    return $out;
}

/**
 * What the result of a past sale really was.
 *
 * The source writes it in English OR Spanish - "sold", "not sold", "sold by
 * nego", but also "vendido", "no se vendió", "vendido por medio de
 * negociación", "cancelado" - and sometimes not at all. Until 24 September 2026
 * a result was "sold" unless it contained the word "not", which counted every
 * Spanish refusal, every cancelled and withdrawn lot and every lot with no
 * result (about 38,700 of them) as a sale.
 *
 * Returns array('key' => sold|nego|unsold|cancelled|withdrawn|none|other,
 *               'label' => what a person reads, 'short' => the list's pill,
 *               'sold' => bool).
 */
function stOutcome($row) {
    $r = strtolower(trim((string) ($row['result'] ?? '')));
    $starts = function ($word) use ($r) { return strpos($r, $word) === 0; };
    if ($r === '') {
        return array('key' => 'none', 'label' => 'No result', 'short' => 'No result', 'sold' => false);
    }
    if ($starts('not sold') || $starts('no se vend')) {
        return array('key' => 'unsold', 'label' => 'Not sold', 'short' => 'Not sold', 'sold' => false);
    }
    if ($starts('sold') || $starts('vendido')) {
        return strpos($r, 'nego') !== false
            ? array('key' => 'nego', 'label' => 'Sold by negotiation', 'short' => 'Sold (nego)', 'sold' => true)
            : array('key' => 'sold', 'label' => 'Sold', 'short' => 'Sold', 'sold' => true);
    }
    if ($starts('cancel')) {
        return array('key' => 'cancelled', 'label' => 'Cancelled', 'short' => 'Cancelled', 'sold' => false);
    }
    if ($starts('remov')) {
        return array('key' => 'withdrawn', 'label' => 'Withdrawn', 'short' => 'Withdrawn', 'sold' => false);
    }
    return array('key' => 'other', 'label' => ucfirst($r), 'short' => ucfirst($r), 'sold' => false);
}

/** Did this lot actually sell? See stOutcome(). */
function stSold($row) {
    $o = stOutcome($row);
    return $o['sold'];
}

/** The same two questions asked of the table, in SQL (both languages). */
const STAT_SOLD_SQL   = "(result LIKE 'sold%' OR result LIKE 'vendido%')";
const STAT_UNSOLD_SQL = "(result LIKE 'not sold%' OR result LIKE 'no se vend%')";

/* WHAT THE SOURCE SHOWS, AND ONLY THAT (the owner, 25 September 2026: "the way
   the website's statistics work - exactly that, everywhere in the portal").
   The source keeps a rolling window: about three months of sale days, 93 days
   back from today in Japan (on 19 September its oldest day was 18 June), and it
   drops its oldest day every day. The portal kept every row it had ever read,
   back to 13 June, so its count went past the source's (1,214,932 against
   1,212,906) while it was still about 1.1 lakh short INSIDE the window. Every
   figure and list a person sees now comes from the same window; older rows stay
   in the table, out of sight. The fetcher's counts (aaa-stats-ingest.php ?have=)
   are not windowed - it always names its own dates. */
const STAT_WINDOW_DAYS = 93;

/** The window as a condition: the sale day, by Japan's calendar. sold_on is indexed. */
function stWindowSql($col = 'sold_on') {
    return $col . ' >= DATE_SUB(DATE(UTC_TIMESTAMP() + INTERVAL 9 HOUR), INTERVAL ' . (int) STAT_WINDOW_DAYS . ' DAY)';
}

/* ------------------------------------------------ the source's ADVANCED SEARCH
 * The client, 24 September 2026: "the source our Statistics come from has
 * filters we don't - make ours the same". aaajapan's statistics search offers,
 * besides maker/model/chassis/year: auction houses grouped by the weekday they
 * sell on (several at once, each with its count), mileage, engine size, start
 * price and final price as from-to ranges, the condition grades as a set of
 * boxes, transmission, equipment and colour, a lot number, a sortable column for
 * everything and an average-price column. Everything below serves that.
 */

/* WHICH COLUMN IS WHICH - read from the source's own row template (tpl_poisk,
   24 September 2026), not guessed. It prints b.k in the "Engine CC" cell as the
   gearbox (AT, IAT, FAT, CVT...), b.l under the chassis as the model's grade, and
   b.m with the class `aj_equip` - the equipment (AAC, AC...). The ingest has
   always stored k in `grade` and m in `transmission`, so the stored names are
   the wrong way round. The data is right; only the names lie, so the pages read
   the gearbox from `grade` and the equipment from `transmission` through these
   two names, and every label says what the value really is. */
const STAT_COL_TRANS = 'grade';
const STAT_COL_EQUIP = 'transmission';

/** The condition grades exactly as the source offers them, in its order. */
const STAT_GRADES = array('99', '9', '6', '5.', '5', '4.5', '4.3', '4', '3.8', '3.5', '3.3', '3.', '3', '2',
                          '1KR', '1', '0', 'XX', 'X', 'WR', 'W', 'S', 'RC', 'RB', 'RA1', 'RA', 'R1', 'R',
                          'N', 'G', 'B', '-', '***', '*');

/** A list from the query string - houses[]=A&houses[]=B or "A,B" - trimmed, capped. */
function stList($v, $max = 80) {
    if (!is_array($v)) {
        $v = ($v === null || $v === '') ? array() : explode(',', (string) $v);
    }
    $out = array();
    foreach ($v as $x) {
        $x = trim((string) $x);
        if ($x !== '' && strlen($x) <= 96) {
            $out[$x] = true;
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return array_keys($out);
}

/**
 * A slow answer kept for a while in the server's temp folder. The lists below are
 * GROUP BYs over a million rows (about a second each) and change slowly, so a page
 * view must not pay for them every time.
 */
function stCached($key, $ttl, $make) {
    $f = sys_get_temp_dir() . '/sbk-stats-' . preg_replace('/[^a-z0-9_-]/i', '', $key) . '.json';
    if (is_file($f) && (time() - filemtime($f)) < $ttl) {
        $v = json_decode((string) @file_get_contents($f), true);
        if (is_array($v)) {
            return $v;
        }
    }
    $v = $make();
    if (is_array($v) && $v) {
        @file_put_contents($f . '.tmp', json_encode($v));
        @rename($f . '.tmp', $f);
    }
    return is_array($v) ? $v : array();
}

/** Transmission, equipment and colour, most common first, with their counts. */
function stFacets($conn) {
    return stCached('facets-w', 3600, function () use ($conn) {
        $out = array('trans' => array(), 'equip' => array(), 'colour' => array());
        $want = array('trans' => array(STAT_COL_TRANS, 30), 'equip' => array(STAT_COL_EQUIP, 16),
                      'colour' => array('colour', 30));
        foreach ($want as $k => $w) {
            $r = @$conn->query("SELECT {$w[0]} v, COUNT(*) n FROM car_stats
                                 WHERE {$w[0]} IS NOT NULL AND {$w[0]} <> '' AND " . stWindowSql() . "
                                 GROUP BY {$w[0]} ORDER BY n DESC LIMIT " . (int) $w[1]);
            while ($r && $x = $r->fetch_assoc()) {
                $out[$k][] = array((string) $x['v'], (int) $x['n']);
            }
        }
        return $out;
    });
}

/**
 * The auction houses the way the source lays them out: under the weekday each
 * one sells on, with how many sales it had in the source's window. A house that
 * sells on two days goes under the busier one.
 */
function stHousesByDay($conn) {
    return stCached('houses-by-day-w', 1800, function () use ($conn) {
        $best = array();
        $r = @$conn->query("SELECT auction, DAYOFWEEK(sold_on) d, COUNT(*) n FROM car_stats
                             WHERE " . stWindowSql() . " AND auction <> ''
                             GROUP BY auction, DAYOFWEEK(sold_on)");
        while ($r && $x = $r->fetch_assoc()) {
            $h = (string) $x['auction'];
            $n = (int) $x['n'];
            if (!isset($best[$h])) {
                $best[$h] = array('d' => (int) $x['d'], 'top' => $n, 'n' => 0);
            }
            $best[$h]['n'] += $n;
            if ($n > $best[$h]['top']) {
                $best[$h]['d'] = (int) $x['d'];
                $best[$h]['top'] = $n;
            }
        }
        $days = array(2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday',
                      6 => 'Friday', 7 => 'Saturday', 1 => 'Sunday');
        $out = array();
        foreach ($days as $d => $name) {
            $list = array();
            foreach ($best as $h => $b) {
                if ($b['d'] === $d) {
                    $list[] = array($h, $b['n']);
                }
            }
            usort($list, function ($a, $b) { return strcasecmp($a[0], $b[0]); });
            if ($list) {
                $out[] = array('day' => $name, 'houses' => $list);
            }
        }
        return $out;
    });
}

/** Every key the Statistics list reads from its address - the pager, the sort
    headings, "Back" from a sale's own page and the download all carry these. */
const STAT_KEYS = array('maker', 'model', 'chassis', 'auction', 'months', 'y1', 'y2', 'result', 'page',
                        'lot', 'houses', 'grades', 'trans', 'equip', 'colour', 'km1', 'km2', 'cc1', 'cc2',
                        'sp1', 'sp2', 'fp1', 'fp2', 'sort', 'dir', 'per');
