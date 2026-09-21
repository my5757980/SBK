<?php
/**
 * A USS lot's real photographs, fetched from Pacific Boeki the first time a
 * signed-in person opens that car, and kept.
 *
 *   GET api/pb-photos.php?id=<cars.id>
 *   -> {"status":"ok","sheet":url,"photos":[urls]}
 *    | {"status":"processing","retry":10}   Pacific Boeki is fetching them; ask again
 *    | {"status":"none"}                    not a USS lot, or nothing to be had
 *
 * Why: for USS halls the picture host shows everyone only a 100x75 preview, and
 * Pacific Boeki's member API hands over the full set - see ussPhotoSet() in
 * includes/functions.php.
 *
 * Safe for the source, by the same rules as the harvester: one question at a
 * time and never two within PB_GAP seconds, at most PB_HOURLY an hour, nothing
 * at all while the harvester is halted (a lapsed login, a refusal), and every
 * answer kept for a week so a car is asked about once.
 */

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const PB_GAP = 2.0;
const PB_HOURLY = 300;
define('PB_HOME', dirname(__DIR__, 2) . '/pb-harvest');

function pbOut(array $a, $code = 200) {
    http_response_code($code);
    echo json_encode($a);
    exit;
}

if (!getClient() && !currentStaff()) {
    pbOut(array('status' => 'signin'), 401);
}
$car = getCarById(isset($_GET['id']) ? intval($_GET['id']) : 0);
if (!$car) {
    pbOut(array('status' => 'none'), 404);
}
if (!isUssLot($car) || (!viewerSeesPastLots() && !lotIsCurrent($car))) {
    pbOut(array('status' => 'none'));
}
if ($kept = ussPhotoSet($car)) {
    pbOut(array('status' => 'ok', 'sheet' => $kept['sheet'], 'photos' => $kept['photos']));
}

// Nothing is asked of the source while the harvester is halted - the same
// person who has to sign in again for it would be signing in for this.
$st = @json_decode((string) @file_get_contents(PB_HOME . '/state.json'), true);
if (!is_array($st) || (string) ($st['halted'] ?? '') !== '') {
    pbOut(array('status' => 'none'));
}
$cookie = trim((string) @file_get_contents(PB_HOME . '/session.txt'));
if ($cookie === '') {
    pbOut(array('status' => 'none'));
}

// One at a time, PB_GAP apart, PB_HOURLY an hour - across every visitor.
$dir = ussPhotoDir();
if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
}
$gate = fopen($dir . '/_gate', 'c+');
if (!$gate || !flock($gate, LOCK_EX)) {
    pbOut(array('status' => 'processing', 'retry' => 5));
}
$g = json_decode((string) stream_get_contents($gate), true) ?: array();
$hour = gmdate('YmdH');
if (($g['hour'] ?? '') !== $hour) {
    $g = array('hour' => $hour, 'n' => 0, 'last' => 0);
}
if ($g['n'] >= PB_HOURLY) {
    flock($gate, LOCK_UN);
    pbOut(array('status' => 'none'));
}
$wait = PB_GAP - (microtime(true) - (float) $g['last']);
if ($wait > 0) {
    usleep((int) ($wait * 1000000));
}

$ch = curl_init('https://pacificboeki.jp/api/v1/auction/lot/images');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => 1, CURLOPT_HEADER => 1, CURLOPT_POST => 1,
    CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '',
    CURLOPT_POSTFIELDS => json_encode(array('jsonrpc' => '2.0', 'method' => 'call',
                                            'params' => array('lot_id' => pbLotId($car)), 'id' => mt_rand())),
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json, text/plain, */*',
                                'Origin: https://pacificboeki.jp', 'Referer: https://pacificboeki.jp/pb-auction/',
                                'Cookie: ' . $cookie),
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                       . '(KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
));
$raw  = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$g['n']++;
$g['last'] = microtime(true);
ftruncate($gate, 0);
rewind($gate);
fwrite($gate, json_encode($g));
fflush($gate);
flock($gate, LOCK_UN);

if ($raw === false || $code !== 200) {
    pbOut(array('status' => 'none'));
}
// The session renews itself on answers; keep the renewed one, as the harvester does.
if (preg_match('/^set-cookie:\s*session_id=([^;\s]+)/im', substr($raw, 0, $hs), $m)) {
    $now = trim((string) @file_get_contents(PB_HOME . '/session.txt'));
    if ($now !== '' && strpos($now, 'session_id=' . $m[1]) === false) {
        @file_put_contents(PB_HOME . '/session.txt',
                           preg_replace('/session_id=[^;\s]*/', 'session_id=' . $m[1], $now), LOCK_EX);
    }
}
$j = json_decode(substr($raw, $hs), true);
$d = $j['result']['data'] ?? null;
if (!is_array($d)) {
    pbOut(array('status' => 'none'));
}
if (($d['image_status'] ?? '') === 'processing') {
    pbOut(array('status' => 'processing', 'retry' => max(3, min(30, (int) ($d['retry_after_seconds'] ?? 10)))));
}
// Only the picture host's own addresses are ever passed on to a page.
$urls = array_values(array_filter((array) ($d['image_urls'] ?? array()), function ($u) {
    return is_string($u) && strpos($u, 'https://p3.aleado.com/') === 0 && strlen($u) < 400;
}));
if (($d['image_status'] ?? '') !== 'success' || !$urls) {
    pbOut(array('status' => 'none'));
}
// The set comes sheet first: 1000x1000 square, then the 1024x768 photographs.
$set = array('sheet' => count($urls) > 1 ? $urls[0] : null,
             'photos' => count($urls) > 1 ? array_slice($urls, 1) : $urls,
             'lot' => pbLotId($car), 'at' => time());
@file_put_contents($dir . '/' . pbLotId($car) . '.json', json_encode($set), LOCK_EX);
pbOut(array('status' => 'ok', 'sheet' => $set['sheet'], 'photos' => $set['photos']));
