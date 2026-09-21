<?php
/**
 * Take a batch of JPAuc vehicles and store them.
 *
 * The harvest runs in a browser rather than on this server, because the source's
 * operator refuses this server's address and the owner's browser reaches it
 * through a VPN. The browser can read JPAuc but cannot reach MySQL, so it posts
 * what it parsed here.
 *
 * Two consequences of that arrangement are handled deliberately:
 *
 *  - The request arrives from https://jpauc.com, a different origin, so it needs
 *    CORS. Only that one origin is allowed; a wildcard would let any page a
 *    browser visits post inventory at us.
 *  - The endpoint is reachable by anyone who finds the URL, so it carries a
 *    shared token. Without one it is an open write into the catalogue.
 *
 * POST JSON: { "token": "...", "section": "japan", "cars": [ {...}, ... ] }
 * Replies  : { "ok": true, "written": N, "skipped": N, "errors": [...] }
 */

require_once dirname(__DIR__) . '/includes/config.php';

/* The token is not written here any more (21 September 2026): it lives in the
   server's .env, the one file that already knows the database password and is
   never committed. An empty token refuses everything rather than letting an
   empty one in - hash_equals('', '') is true, and that would be an open door. */
define('JPAUC_INGEST_TOKEN', env_get('JPAUC_INGEST_TOKEN', ''));
const JPAUC_ALLOWED_ORIGIN = 'https://jpauc.com';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === JPAUC_ALLOWED_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . JPAUC_ALLOWED_ORIGIN);
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(array('ok' => false, 'error' => $msg));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('POST only', 405);
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
    fail('body is not JSON');
}
if (JPAUC_INGEST_TOKEN === '' || !hash_equals(JPAUC_INGEST_TOKEN, (string) ($in['token'] ?? ''))) {
    fail('bad token', 403);
}

$section = (string) ($in['section'] ?? 'japan');
// Auctions only: fixed price was removed from the portal on the owner's order
// of 13 September 2026, so its rows are refused rather than stored again.
if ($section !== 'japan') {
    fail('unknown section');
}
$cars = $in['cars'] ?? null;
if (!is_array($cars) || !$cars) {
    fail('no cars');
}
if (count($cars) > 500) {
    fail('batch too large');   // keeps one bad call from holding the table
}

global $conn;

$sql = "INSERT INTO cars
  (car_id, lot_no, make, model, year, mileage, price, currency, sold_price,
   auction, auction_date, auction_time, chassis, transmission, grade, rating,
   engine_cc, color, status, images, source_url, source_section,
   last_updated, created_at)
 VALUES (?,?,?,?,?,?,?,'yen',?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
 ON DUPLICATE KEY UPDATE
   lot_no=VALUES(lot_no), make=VALUES(make), model=VALUES(model), year=VALUES(year),
   -- a zero never overwrites a figure we already hold: a page read without an
   -- account can come back with the vehicle but no numbers, and taking that at
   -- face value would wipe good prices and take the bid window with them
   mileage=IF(VALUES(mileage) > 0, VALUES(mileage), mileage),
   price=IF(VALUES(price) > 0, VALUES(price), price),
   sold_price=IF(VALUES(sold_price) > 0, VALUES(sold_price), sold_price),
   auction=VALUES(auction), auction_date=VALUES(auction_date),
   auction_time=VALUES(auction_time), chassis=VALUES(chassis),
   transmission=VALUES(transmission), grade=VALUES(grade), rating=VALUES(rating),
   engine_cc=VALUES(engine_cc), color=VALUES(color), status=VALUES(status),
   images=IF(CHAR_LENGTH(VALUES(images)) > 2, VALUES(images), images),
   source_url=VALUES(source_url), last_updated=NOW()";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    fail('prepare failed: ' . $conn->error, 500);
}

$written = 0;
$skipped = 0;
$errors = array();

foreach ($cars as $c) {
    // Identity is sale day + hall + lot number, never the lot number alone: the
    // same number is used at every hall on the same day, so lot 1 at Touhoku and
    // lot 1 at Kinki are two different cars.
    $day  = trim((string) ($c['day'] ?? ''));
    $hall = trim((string) ($c['hall'] ?? ''));
    $lot  = trim((string) ($c['lot'] ?? ''));
    if ($day === '' || $hall === '' || $lot === '') {
        $skipped++;
        continue;
    }
    $carId = 'jp-' . substr(sha1($day . '|' . $hall . '|' . $lot), 0, 24);

    $d = DateTime::createFromFormat('Y-m-d', $day);
    $auctionDate = $d ? $d->format('d.m.Y') : null;   // the format the portal reads
    if (!$auctionDate) {
        $skipped++;
        continue;
    }

    $year    = (int) ($c['year'] ?? 0) ?: null;
    $mileage = (int) preg_replace('/\D+/', '', (string) ($c['km'] ?? '')) ?: 0;
    $price   = (float) preg_replace('/[^\d.]/', '', (string) ($c['price'] ?? '')) ?: 0;
    $sold    = (float) preg_replace('/[^\d.]/', '', (string) ($c['endPrice'] ?? '')) ?: 0;
    $cc      = (int) preg_replace('/\D+/', '', (string) ($c['cc'] ?? '')) ?: null;
    $status  = strtolower(trim((string) ($c['status'] ?? 'available'))) ?: 'available';
    $images  = json_encode(array_values(array_filter(
        (array) ($c['images'] ?? array()),
        function ($u) { return is_string($u) && strlen($u) > 10; }
    )));
    $src = 'https://jpauc.com/auction/search?d%5B%5D=' . rawurlencode($day)
         . '&lots=' . rawurlencode($lot);

    $args = array(
        $carId, $lot,
        trim((string) ($c['maker'] ?? '')), trim((string) ($c['model'] ?? '')),
        $year, $mileage, $price, $sold,
        $hall, $auctionDate, trim((string) ($c['time'] ?? '')) ?: null,
        trim((string) ($c['chassis'] ?? '')) ?: null,
        trim((string) ($c['shift'] ?? '')) ?: null,
        trim((string) ($c['modelGrade'] ?? '')) ?: null,
        trim((string) ($c['grade'] ?? '')) ?: null,
        $cc,
        trim((string) ($c['color'] ?? '')) ?: null,
        $status, $images, $src, $section,
    );
    // The type string has to line up with $args one for one. It did not: the
    // first was declared an integer, so every car_id - "jp-" and a hash - was
    // cast to 0, and sixteen thousand vehicles upserted onto a single row
    // while the endpoint cheerfully reported each one written.
    //
    //        carId lot make model | year mileage | price sold | hall date time
    //        chassis shift grade rating | cc | color status images src section
    $stmt->bind_param('ssss' . 'ii' . 'dd' . 'sss' . 'ssss' . 'i' . 'sssss', ...$args);

    if ($stmt->execute()) {
        $written++;
    } else {
        $skipped++;
        if (count($errors) < 3) {
            $errors[] = substr($stmt->error, 0, 120);
        }
    }
}
$stmt->close();

echo json_encode(array(
    'ok'      => true,
    'written' => $written,
    'skipped' => $skipped,
    'errors'  => $errors,
));
