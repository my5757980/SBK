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
