<?php
/**
 * SBK Auction - fixed-price stock: REMOVED.
 *
 * The owner's order of 13 September 2026: "fixed price nahi chahiye, har jagah se
 * hata do" - the page, the menu links, the desk's tiles and filter, and the rows.
 * This address is kept only so an old bookmark or a link in somebody's message
 * lands on the auctions instead of an error. A temporary redirect (302), not a
 * permanent one, so browsers do not remember it for ever.
 */
header('Location: welcome.php', true, 302);
exit;
