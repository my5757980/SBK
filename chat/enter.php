<?php
/**
 * The door from the website.
 *
 * Somebody clicks "Chat with a person online" on sbkautotrading.com. If they are
 * signed in there, WordPress signs one short line saying who they are and sends
 * them here holding it. This file checks the signature and lets them straight in
 * — as the desk if their role is staff, as themselves if they are a customer.
 *
 * That is the owner's requirement in his own words: a customer who already has
 * an account and is signed in should "go directly to the staff side", and Talal
 * should be able to sign in on the website, come to the chat, and be green.
 *
 * NOBODY TYPES A PASSWORD HERE, and this app never sees one. The website is the
 * only place anyone signs in, which means there is one account, one password and
 * one place to take somebody's access away.
 *
 * A line is good for five minutes (LINK_SECONDS). If it has gone stale — a
 * bookmarked link, a page left open over lunch — the person is sent back to the
 * website to be handed a fresh one, which costs them one redirect and no typing.
 */

require_once __DIR__ . '/includes/lib.php';

$token = (string) ($_GET['t'] ?? '');
$wpId  = handoverUser($token);

if ($wpId <= 0) {
    /* Not a good line. Ask the website to make a new one; if the person is not
       signed in there either, the website will ask them to, and send them back
       here afterwards. `back=1` stops the two sites bouncing a broken handover
       between them for ever. */
    if (empty($_GET['back'])) {
        header('Location: ' . WP_GO);
        exit;
    }
    header('Location: index.php');
    exit;
}

$u = wpUser($wpId);
if (!$u) {
    header('Location: index.php');
    exit;
}

/* One person at a time in this browser. Somebody who was the desk and comes
   back as a customer must not still be holding the desk, and the other way
   round — otherwise two identities are live at once and every "is this mine?"
   check downstream has to guess which one to believe. */
unset($_SESSION['chat_wp_staff'], $_SESSION['chat_wp_guest']);
session_regenerate_id(true);

if ($u['is_staff']) {
    $_SESSION['chat_wp_staff'] = (int) $u['id'];
    touchPresence('staff', (int) $u['id'], 'chat');
} else {
    /* A customer of the website. Their name and e-mail come from their account,
       so the three boxes are never shown to somebody who has already told the
       website who they are. */
    $g = guestForWpUser($u);
    touchPresence('guest', (int) $g['id'], 'chat');
}

$to = (int) ($_GET['to'] ?? 0);
header('Location: chat.php' . ($to > 0 ? '?to=' . $to : ''));
exit;
