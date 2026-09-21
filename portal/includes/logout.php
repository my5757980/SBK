<?php
/**
 * SBK Auction — end the client session.
 *
 * Clears only the client keys (an admin signed in on the same browser keeps
 * their session), then rotates the session id.
 */

require_once 'config.php';

unset(
    $_SESSION['client_id'],
    $_SESSION['client_name'],
    $_SESSION['client_email'],
    $_SESSION['redirect_after_login']
);

session_regenerate_id(true);

header("Location: " . SITE_URL);
exit;
