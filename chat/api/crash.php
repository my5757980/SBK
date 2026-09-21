<?php
/**
 * A JavaScript error the app could not do anything else with.
 *
 * The first calls APK closed itself the instant it opened - no message, no
 * screenshot, nothing to look at. This is the fix for THAT: every uncaught
 * JS error (index.js's global handler) and every screen that fails to draw
 * (App.js's Guard) is sent here, so the next one is read from the account's
 * own error_log instead of guessed at from "it just closes".
 *
 * No sign-in is required - a crash can happen before anyone is signed in, and
 * a report that needed a valid pass would be the one report that never
 * arrives for a broken pass. What is written is capped and never trusted:
 * this is a stack trace from a phone, not a command.
 */

require_once __DIR__ . '/../includes/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$body = (string) file_get_contents('php://input');
// 32 KB: an Android crash report is a stack PLUS every "Caused by", and the
// root cause is the last of them - an 8 KB cut would keep the noise and drop it.
$body = substr($body, 0, 32000);
if ($body === '') {
    http_response_code(204);
    exit;
}

$ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 120);
$line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ua=' . $ua . "\n" . $body . "\n----\n";

// error_log() was tried first and never landed anywhere findable - its ini
// value here is the RELATIVE path "error_log", which PHP resolves against
// whatever the process's working directory happens to be, and that turned
// out not to be this script's own folder either. A fixed, absolute path next
// to MEDIA_DIR (outside the web root, same as the pictures) is the one this
// account is proven to write to reliably.
$log = dirname(MEDIA_DIR) . '/app-crash.log';
$fh = @fopen($log, 'a');
if ($fh) {
    flock($fh, LOCK_EX);
    fwrite($fh, $line);
    flock($fh, LOCK_UN);
    fclose($fh);
    // A log nobody trims becomes the next disk-space problem. Once it passes
    // 2 MB, keep only the newer half rather than let it grow forever.
    if (filesize($log) > 2 * 1024 * 1024) {
        $all = (string) @file_get_contents($log);
        @file_put_contents($log, substr($all, (int) (strlen($all) / 2)), LOCK_EX);
    }
}

http_response_code(204);
