<?php
/**
 * A picture or a voice note.
 *
 * Files are the one resource this server is genuinely short of. The account
 * stands at 79% of its inode limit — 236,573 of 300,000 — and twenty-eight
 * domains share that number, the auction portal among them. Run out and every
 * site on the account stops, not just this one. So three rules hold here:
 *
 *   1. sizes are capped, and a photograph from a phone is re-encoded down
 *      before it is written, which turns a 4 MB camera file into ~200 KB;
 *   2. files are spread over two levels of directory, so no folder ever holds
 *      more than a few hundred entries;
 *   3. old media is swept away on a schedule — see sweep.php.
 *
 * Nothing is written under the document root either. A conversation between a
 * customer and the desk is not public, and a guessable /uploads/ path would
 * make it so; media.php hands a file over only after asking who wants it.
 */

require_once __DIR__ . '/../includes/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(array('ok' => false, 'error' => 'POST only'), 405);
}

$g = guest();
$s = staff();
if (!$g && !$s) {
    jsonOut(array('ok' => false, 'signedout' => true), 401);
}
refuseUntilDetails($g, $s);
$viewerIsGuest = ($g && !$s);

$kind = ($_POST['kind'] ?? '') === 'voice' ? 'voice' : 'image';
$f    = $_FILES['file'] ?? null;

if (!$f || !is_uploaded_file($f['tmp_name'] ?? '') || (int) $f['error'] !== UPLOAD_ERR_OK) {
    $why = array(
        UPLOAD_ERR_INI_SIZE  => 'That file is larger than the server accepts.',
        UPLOAD_ERR_FORM_SIZE => 'That file is too large.',
        UPLOAD_ERR_PARTIAL   => 'The upload was cut short. Try again.',
        UPLOAD_ERR_NO_FILE   => 'No file arrived.',
    );
    jsonOut(array('ok' => false,
                  'error' => $why[(int) ($f['error'] ?? -1)] ?? 'The upload failed.'), 400);
}

$max = ($kind === 'voice') ? MAX_VOICE_BYTES : MAX_IMAGE_BYTES;
if ((int) $f['size'] > $max) {
    jsonOut(array('ok' => false, 'error' => 'That is too large. The limit is '
                  . round($max / 1048576) . ' MB.'), 400);
}

/* What it says it is counts for nothing; what it IS decides. */
$fi   = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $fi->file($f['tmp_name']);

$okImage = array('image/jpeg' => 'jpg', 'image/png' => 'png',
                 'image/gif'  => 'gif', 'image/webp' => 'webp');
/* An M4A recording is an MPEG-4 container, but not every finfo build calls it
   `audio/mp4` - one that reads the ftyp box's own "M4A " brand says
   `audio/x-m4a` instead, and the app's own recordings (expo-audio's
   HIGH_QUALITY preset, `.m4a`) land in exactly that box. Found live on
   2026-09-18: every voice note sent from the app came back "That is not a
   sound file" because only `audio/mp4` was listed. Both names now map to the
   same extension. */
$okVoice = array('audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/ogg' => 'ogg',
                 'audio/mpeg' => 'mp3',  'audio/mp4'  => 'm4a', 'audio/x-m4a' => 'm4a',
                 /* An Android recorder writes an MPEG-4 container, and which of
                    these three names finfo gives it depends on the brand in its
                    own header - the phone's own voice note was refused as "not a
                    sound file" for exactly this reason. All three are the same
                    recording. 3gpp/amr are what older phones fall back to. */
                 'video/mp4' => 'm4a', 'video/3gpp' => '3gp', 'audio/3gpp' => '3gp',
                 'audio/amr' => 'amr',
                 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/aac'  => 'aac');

$ext = ($kind === 'voice') ? ($okVoice[$mime] ?? null) : ($okImage[$mime] ?? null);
if ($ext === null) {
    /* Say WHICH type was refused, in the same place the phone's own reports go.
       A refusal that does not name the type is a day of guessing - which is
       what it cost on 18 September 2026. */
    @file_put_contents('/home/thelyfas/app-crash.log',
        '[' . gmdate('Y-m-d H:i:s') . ' UTC] upload refused: kind=' . $kind
        . ' mime=' . $mime . ' bytes=' . (int) $f['size'] . PHP_EOL . '----' . PHP_EOL,
        FILE_APPEND | LOCK_EX);
    jsonOut(array('ok' => false,
                  'error' => ($kind === 'voice' ? 'That is not a sound file.'
                                                : 'That is not a picture.')), 400);
}

/* ---- where it goes: storage/ab/cd/<random>.<ext> */
$name = bin2hex(random_bytes(16)) . '.' . $ext;
$rel  = substr($name, 0, 2) . '/' . substr($name, 2, 2) . '/' . $name;
$abs  = MEDIA_DIR . '/' . $rel;
if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true)) {
    jsonOut(array('ok' => false, 'error' => 'Could not store that just now.'), 500);
}

$secs = null;

if ($kind === 'image') {
    /* Re-encoded rather than copied. A modern phone sends four megabytes of
       pixels nobody will look at; 1600 across is more than a chat bubble can
       show and a fraction of the bytes. It also strips whatever the camera
       wrote into the file — location among it — which has no business travelling
       to the desk with a photograph of a bumper. */
    if (!shrinkImage($f['tmp_name'], $abs, $mime, 1600, 82)) {
        if (!@move_uploaded_file($f['tmp_name'], $abs)) {
            jsonOut(array('ok' => false, 'error' => 'Could not store that picture.'), 500);
        }
    }
} else {
    if (!@move_uploaded_file($f['tmp_name'], $abs)) {
        jsonOut(array('ok' => false, 'error' => 'Could not store that recording.'), 500);
    }
    $secs = (int) ($_POST['secs'] ?? 0);
    if ($secs < 0 || $secs > 36000) { $secs = null; }
}
@chmod($abs, 0644);
$bytes = (int) @filesize($abs);

/* ---- and the message that carries it */
if ($viewerIsGuest) {
    $staffId = (int) ($_POST['to'] ?? 0);

    /* The same question send.php asks, and for the same reason: the person
       being written to must be a member of the desk on the WEBSITE right now.
       The file is deleted again if they are not, because a picture with no
       message to carry it is a file nobody will ever find and never delete. */
    $who = wpUser($staffId);
    if (!$who || !$who['is_listed']) {
        @unlink($abs);
        jsonOut(array('ok' => false, 'error' => 'That person is not available.'), 400);
    }
    $t  = threadFor((int) $g['id'], $staffId);
    $id = addMessage((int) $t['id'], true, null, $kind, '', $rel, $mime, $bytes, $secs);
} else {
    /* Into a group. The file is written first, like every other upload, and
       taken away again if the room turns out not to be this person's — a file
       with no message to carry it is one nobody will ever find and never
       delete. */
    $groupId = (int) ($_POST['group'] ?? 0);
    if ($groupId > 0) {
        if (!mayReadGroup($groupId)) {
            @unlink($abs);
            jsonOut(array('ok' => false, 'error' => 'That group is not yours.'), 403);
        }
        $id = addGroupMessage($groupId, (int) $s['id'], $kind, '', $rel, $mime, $bytes, $secs);
        $st = db()->prepare("SELECT * FROM chat_messages WHERE id = ? LIMIT 1");
        $st->bind_param('i', $id);
        $st->execute();
        $m = $st->get_result()->fetch_assoc();
        $st->close();
        jsonOut(array('ok' => true, 'group' => $groupId,
                      'message' => groupMessageOut($m, (int) $s['id'],
                                                   staffNames(array((int) $s['id'])))));
    }

    $t = mayReadThread((int) ($_POST['thread'] ?? 0));
    if (!$t) {
        @unlink($abs);
        jsonOut(array('ok' => false, 'error' => 'That conversation is not yours.'), 403);
    }
    $id = addMessage((int) $t['id'], false, (int) $s['id'], $kind, '', $rel, $mime, $bytes, $secs);
}

$st = db()->prepare("SELECT * FROM chat_messages WHERE id = ? LIMIT 1");
$st->bind_param('i', $id);
$st->execute();
$m = $st->get_result()->fetch_assoc();
$st->close();

jsonOut(array('ok' => true, 'thread' => (int) $t['id'],
              'message' => messageOut($m, $viewerIsGuest)));


/**
 * Write a smaller copy of a picture.
 *
 * @return bool false when GD cannot read it, and the caller keeps the original
 */
function shrinkImage($src, $dst, $mime, $maxSide, $quality) {
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    $img = null;
    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $img = @imagecreatefromjpeg($src);
    } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        $img = @imagecreatefrompng($src);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $img = @imagecreatefromwebp($src);
    } elseif ($mime === 'image/gif') {
        // Left whole: shrinking a GIF would take the animation out of it.
        return false;
    }
    if (!$img) {
        return false;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1.0, $maxSide / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $out = imagecreatetruecolor($nw, $nh);
    // A white ground, so a transparent PNG does not arrive as a black rectangle.
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $ok = false;
    if (substr($dst, -4) === '.png' && function_exists('imagepng')) {
        $ok = @imagepng($out, $dst, 6);
    } elseif (substr($dst, -5) === '.webp' && function_exists('imagewebp')) {
        $ok = @imagewebp($out, $dst, $quality);
    } elseif (function_exists('imagejpeg')) {
        $ok = @imagejpeg($out, $dst, $quality);
    }
    imagedestroy($img);
    imagedestroy($out);
    return (bool) $ok;
}
