<?php
/**
 * The handful of numbers the portal shows in headings, as JSON.
 *
 * Every page that prints a count was printing the count at the moment it was
 * built, so the only way to see the catalogue move was to reload - and the
 * catalogue moves all day. This is what the pages poll instead: counts only, no
 * rows, so it costs one cheap query set and can be asked for every twenty
 * seconds without anybody noticing.
 *
 * Signed in only. The counts are not secret, but an open endpoint on a shared
 * host is a thing to be hammered, and everything that reads this is behind a
 * login anyway.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$isClient = !empty($_SESSION['client_id']);
$isStaff  = !empty($_SESSION['admin_id']);
if (!$isClient && !$isStaff) {
    http_response_code(401);
    echo json_encode(array('error' => 'not signed in'));
    exit;
}

/* Nothing below writes to the session, and PHP holds an exclusive lock on it for
   the length of a request. A page that asks for this at the same moment as
   anything else of its own would have had the two queue up, one behind the
   other - which is how the same figure came to be a second or two older here
   than on the screen beside it. */
if (function_exists('session_write_close')) {
    session_write_close();
}

global $conn;

/** One number, or -1 if the query would not run. */
function n($sql) {
    global $conn;
    $r = $conn->query($sql);
    return $r ? (int) $r->fetch_row()[0] : -1;
}

$out = array(
    'auction'  => n("SELECT COUNT(*) FROM cars c WHERE " . currentLotsSql('c')),
    'at'       => date('c'),
);
$out['stock'] = $out['auction'];      // one section since fixed price was removed

// Past sales, for the Statistics tile on both dashboards. Cached a minute -
// see statsCount(), which the dashboards use to print the first figure.
$out['statistics'] = statsCount();

// The desk's numbers, for whoever is allowed to see them. A client polling this
// gets the two stock counts and nothing else.
if ($isStaff) {
    if (staffCan('clients.view')) {
        $out['clients'] = n("SELECT COUNT(*) FROM clients");
    }
    if (staffCan('bids.view')) {
        $out['bids']     = n("SELECT COUNT(*) FROM bids");
        $out['bids_new'] = n("SELECT COUNT(*) FROM bids WHERE status = 'placed'");
    }
    if (staffCan('enquiries.view')) {
        $out['enquiries']     = n("SELECT COUNT(*) FROM inquiries");
        $out['enquiries_new'] = n("SELECT COUNT(*) FROM inquiries WHERE status = 'new'");
    }
    if (staffCan('orders.view')) {
        $out['orders'] = n("SELECT COUNT(*) FROM orders");
    }
}

echo json_encode($out);
