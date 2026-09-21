<?php
/**
 * Hand over a picture or a voice note — to the two people entitled to it.
 *
 * The files sit outside the document root, so this is the only way to reach one
 * and every request goes past mayReadThread() first. A photograph a customer
 * sent to the desk is theirs and the desk's; a URL that anybody could guess
 * would make it everybody's.
 *
 * Answered with an ETag so a browser fetches each picture once. The bytes never
 * change — the name is random and a file is never rewritten — so the strongest
 * caching is also the correct caching.
 */

require_once __DIR__ . '/includes/lib.php';

$id = (int) ($_GET['m'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

$st = db()->prepare("SELECT id, thread_id, group_id, media_path, media_mime, kind
                       FROM chat_messages WHERE id = ? LIMIT 1");
$st->bind_param('i', $id);
$st->execute();
$m = $st->get_result()->fetch_assoc();
$st->close();

/* Two doors, one key each. A picture in a conversation belongs to the customer
   and the member of staff it is with; a picture in a group belongs to whoever
   is in that group. A message is one or the other and never both, so the
   question asked is the one that fits. */
$allowed = false;
if ($m && !empty($m['media_path'])) {
    $allowed = $m['group_id']
        ? (bool) mayReadGroup((int) $m['group_id'])
        : (bool) mayReadThread((int) $m['thread_id']);
}
if (!$allowed) {
    http_response_code(404);
    exit;
}

/* The path came out of our own table, but it is still checked against the
   folder it must live in. A traversal in a column is as good as one in a query
   string if nobody looks. */
$abs  = MEDIA_DIR . '/' . $m['media_path'];
$real = realpath($abs);
$root = realpath(MEDIA_DIR);
if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit;
}

/* The name a file goes out under - which is not always the name finfo gave it.
   An Android voice note is MPEG-4 audio, and finfo calls it `video/mp4` or
   `audio/x-m4a` depending on the brand in its header; both are the same AAC
   recording, and every browser and player knows that as `audio/mp4`. A browser's
   voice note is WebM that finfo calls `video/webm`, and it is still only sound.
   These names were added to upload.php on 18 September 2026 and never here, so
   every voice note from the app went out as application/octet-stream under
   nosniff (found 19 September 2026). Chrome and Edge played it regardless -
   checked in both - but Safari on an iPhone does not, and it should not have to
   guess. Anything not on this list still gets no type at all. */
$served = array(
    'image/jpeg' => 'image/jpeg', 'image/png'  => 'image/png',
    'image/gif'  => 'image/gif',  'image/webp' => 'image/webp',
    'audio/webm' => 'audio/webm', 'video/webm' => 'audio/webm',
    'audio/ogg'  => 'audio/ogg',  'audio/mpeg' => 'audio/mpeg',
    'audio/mp4'  => 'audio/mp4',  'audio/x-m4a' => 'audio/mp4', 'video/mp4' => 'audio/mp4',
    'audio/aac'  => 'audio/aac',  'audio/wav'  => 'audio/wav', 'audio/x-wav' => 'audio/wav',
    'video/3gpp' => 'audio/3gpp', 'audio/3gpp' => 'audio/3gpp', 'audio/amr' => 'audio/amr',
);
$mime = $served[(string) $m['media_mime']] ?? 'application/octet-stream';

$size = filesize($real);
$etag = '"' . md5($m['media_path'] . '|' . $size) . '"';

/* Who is asking has been settled. Let go of the session now: a picture or a
   recording on a slow telephone line can take seconds, and a PHP session is
   locked for as long as a request holds it - every poll from the same phone
   would stand in line behind the download and the conversation would look
   frozen while it lasted. */
session_write_close();

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

/* A piece of the file, when a piece is asked for. This said `Accept-Ranges:
   bytes` and then sent the whole file to every request regardless - and a
   player that asks for the middle of a voice note to scrub through it, which is
   what Safari does before it will play anything at all, was told one thing and
   given another. One range is honoured; several at once get the whole file,
   which the standard allows. */
$from = 0;
$to   = $size - 1;
$part = false;
$want = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
if ($want !== '' && $size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', $want, $r) && ($r[1] !== '' || $r[2] !== '')) {
    if ($r[1] === '') {
        $from = max(0, $size - (int) $r[2]);             // "the last N bytes"
    } else {
        $from = (int) $r[1];
        if ($r[2] !== '') { $to = min((int) $r[2], $size - 1); }
    }
    if ($from > $to || $from >= $size) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    $part = true;
}

if ($part) {
    http_response_code(206);
    header('Content-Range: bytes ' . $from . '-' . $to . '/' . $size);
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . ($to - $from + 1));
header('ETag: ' . $etag);
header('Cache-Control: private, max-age=604800');
// Never rendered as a page, whatever it claims to be.
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="sbk-' . (int) $m['id']
       . '.' . pathinfo($real, PATHINFO_EXTENSION) . '"');
// Voice notes are scrubbed through, and a player needs to be able to ask for
// the middle of a file to do that.
header('Accept-Ranges: bytes');

if (!$part) {
    readfile($real);
    exit;
}
$fh = fopen($real, 'rb');
fseek($fh, $from);
$left = $to - $from + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(65536, $left));
    if ($chunk === false || $chunk === '') { break; }
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fh);
