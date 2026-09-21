<?php
/**
 * SBK Auction — end the admin session.
 *
 * Only the admin keys are cleared: a client may be signed in in the same
 * browser session and should stay signed in.
 */

require_once '../includes/config.php';

unset($_SESSION['admin_id'], $_SESSION['admin_name']);

// new session id so the old one can't be replayed
session_regenerate_id(true);

header("Location: login.php");
exit;
