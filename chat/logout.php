<?php
/**
 * Leave the chat.
 *
 * Only the chat. Signing out HERE does not sign anybody out of the website —
 * the website is where a person signs in, and pulling their shop session out
 * from under them because they closed a conversation would be rude and
 * surprising. What goes is this app's own idea of who they are.
 *
 * Presence is cleared in the same breath rather than left to time out, so the
 * green dot goes off the moment they leave instead of forty-five seconds later.
 */

require_once __DIR__ . '/includes/lib.php';

$s = staff();
$g = guest();

if ($s) {
    $st = db()->prepare("DELETE FROM chat_presence WHERE who = 'staff' AND who_id = ?");
    $st->bind_param('i', $s['id']);
    $st->execute();
    $st->close();
}

if ($g) {
    $st = db()->prepare("DELETE FROM chat_presence WHERE who = 'guest' AND who_id = ?");
    $st->bind_param('i', $g['id']);
    $st->execute();
    $st->close();

    /* The conversation is kept: the desk still has it, and the same person
       coming back with the same account or the same e-mail picks it up where it
       stopped.

       The cookie must be cleared on the domain it was SET on, or the browser
       keeps the old one and signing out does nothing at all - and also on the
       subdomain alone, which an older build used and which is a different
       cookie carrying the same name. Without that second line a dead token
       stayed behind and beat every new one, and the person could never get back
       in. See forgetHostOnlyGuestCookie(). */
    setcookie('sbk_guest', '', array(
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    forgetHostOnlyGuestCookie();
}

/* Both, because one browser can have been both in its time and a half-cleared
   session is worse than either. */
unset($_SESSION['chat_wp_staff'], $_SESSION['chat_wp_guest']);
session_regenerate_id(true);

/* Back to the website, which is where the chat lives now - unless this is the
   application's own domain, where signing out leaves you at the application's
   own front door rather than somewhere else entirely. */
header('Location: ' . (ON_APP_HOST ? 'index.php' : WP_URL));
