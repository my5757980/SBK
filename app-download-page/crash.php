<?php
/**
 * Where the phone app reports what went wrong — on the app's own domain.
 *
 * The same door the app has had since 2026-09-18, moved here with the rest of
 * the application: the chat's copy belongs to the chat, this one belongs to
 * the app. It stands alone on purpose — no config, no database, nothing of the
 * chat's — because a crash report must be able to arrive when everything else
 * is broken.
 *
 * No sign-in is asked for: a crash can happen before anyone is signed in, and
 * a report that needed a valid pass would be the one report that never
 * arrives for a broken one. What is written is capped and never trusted —
 * this is a stack trace from a phone, not a command.
 *
 * It is read from /home/thelyfas/app-crash.log, which sits outside every
 * website's folder, so nothing here is reachable from the web.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$body = (string) file_get_contents('php://input');
// 32 KB: an Android report is a stack PLUS every "Caused by", and the root
// cause is the last of them - a smaller cut would keep the noise and drop it.
$body = substr($body, 0, 32000);
if ($body === '') {
    http_response_code(204);
    exit;
}

$ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 120);
$line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ua=' . $ua . "\n" . $body . "\n----\n";

$log = '/home/thelyfas/app-crash.log';
$fh = @fopen($log, 'a');
if ($fh) {
    flock($fh, LOCK_EX);
    fwrite($fh, $line);
    flock($fh, LOCK_UN);
    fclose($fh);
    // A log nobody trims becomes the next disk-space problem.
    if (filesize($log) > 2 * 1024 * 1024) {
        $all = (string) @file_get_contents($log);
        @file_put_contents($log, substr($all, (int) (strlen($all) / 2)), LOCK_EX);
    }
}

http_response_code(204);
