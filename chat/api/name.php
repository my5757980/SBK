<?php
/**
 * "What should we call you?"
 *
 * The owner wants the bold line at the top of every conversation to be a NAME,
 * and for most of this shop's customers there isn't one: WooCommerce builds
 * their display name out of their login, and 36 of the 59 have no first name,
 * last name or billing name anywhere either. The desk was reading `jd3885325`.
 *
 * So a signed-in customer with no name on file is asked once, in the chat, and
 * what they type is kept here — in the chat's own row, NOT written back into
 * the website's user table. The chat reads the website; it does not edit
 * people's shop accounts behind their back.
 *
 * lib.php's guestForWpUser() then refuses to overwrite it with the login on the
 * next heartbeat, which is the other half of making this stick.
 */

require_once __DIR__ . '/../includes/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(array('ok' => false, 'error' => 'POST only'), 405);
}

$g = guest();
if (!$g || staff()) {
    // Only a customer names themselves. The desk's names come from the website.
    jsonOut(array('ok' => false, 'signedout' => true), 401);
}

$name = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
if (mb_strlen($name) < 2) {
    jsonOut(array('ok' => false, 'error' => 'Please type your name.'), 400);
}
if (strpos($name, '@') !== false) {
    jsonOut(array('ok' => false, 'error' => 'That looks like an e-mail address — just your name, please.'), 400);
}
$name = mb_substr($name, 0, 80);

$st = db()->prepare("UPDATE chat_guests SET name = ? WHERE id = ?");
$st->bind_param('si', $name, $g['id']);
$st->execute();
$st->close();

jsonOut(array('ok' => true, 'name' => $name));
