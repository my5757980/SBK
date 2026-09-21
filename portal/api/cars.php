<?php
/**
 * SBK Auction — live lots API.
 * Returns the same rows as index.php as JSON, plus a cheap "version" so the
 * page can tell whether anything changed before asking for the full payload.
 *
 * Signed-in clients only — the inventory is not public.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// API must emit clean JSON only — never HTML warnings/notices
error_reporting(0);
ini_set('display_errors', '0');
if (ob_get_length()) { @ob_clean(); }

if (!isLoggedIn() && !isAdmin()) {
    http_response_code(401);
    echo json_encode(array('error' => 'auth_required', 'login' => 'login.php'));
    exit;
}

$page     = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? min(100, max(1, intval($_GET['per_page']))) : 20;

/* The same filters index.php reads - every one of them. The chassis model and
   chassis number boxes, the auction hall and the sale day were missing here, so
   the list's 15-second refresh asked for the WHOLE catalogue while the page
   showed a search: its cars were not in the answer, and the page reloaded
   itself every poll (three times in 50 seconds on a chassis search). */
$filters = array(
    'make'         => $_GET['make'] ?? '',
    'model'        => $_GET['model'] ?? '',
    'lot'          => $_GET['lot'] ?? '',
    'chassis_model' => trim((string) ($_GET['chassis_model'] ?? '')),
    'chassis_no'    => trim((string) ($_GET['chassis_no'] ?? '')),
    'auction_on'   => (array) ($_GET['auction_on'] ?? array()),
    'auction'      => (array) ($_GET['auction'] ?? array()),
    'year_min'     => $_GET['year_min'] ?? '',
    'year_max'     => $_GET['year_max'] ?? '',
    'mileage_min'  => $_GET['mileage_min'] ?? '',
    'mileage_max'  => $_GET['mileage_max'] ?? '',
    'cc_min'       => $_GET['cc_min'] ?? '',
    'cc_max'       => $_GET['cc_max'] ?? '',
    'price_min'    => $_GET['price_min'] ?? '',
    'price_max'    => $_GET['price_max'] ?? '',
    'chassis'      => (array) ($_GET['chassis'] ?? array()),
    'rating'       => (array) ($_GET['rating'] ?? array()),
    'color'        => (array) ($_GET['color'] ?? array()),
    'status'       => (array) ($_GET['status'] ?? array()),
    'transmission' => (array) ($_GET['transmission'] ?? array()),
    'sort'         => $_GET['sort'] ?? 'newest',
);

// version-only mode: cheap poll to detect if anything changed
$version_only = isset($_GET['version']) && $_GET['version'] == '1';

global $conn;
$vres = $conn->query("SELECT COUNT(*) AS n, COALESCE(MAX(last_updated),'') AS mx FROM cars WHERE status NOT IN ('removed','cancelled')");
$vrow = $vres->fetch_assoc();
$version = md5($vrow['n'] . '|' . $vrow['mx']);

if ($version_only) {
    echo json_encode(array(
        'version'     => $version,
        'total'       => intval($vrow['n']),
        'last_change' => $vrow['mx'],
        'server_time' => date('c'),
    ));
    exit;
}

$result = getActiveCars($page, $per_page, $filters);

// shape rows to match the columns rendered in index.php
$cars = array();
foreach ($result['cars'] as $c) {
    $cars[] = array(
        'id'           => intval($c['id']),
        'car_id'       => $c['car_id'],
        'lot_no'       => $c['lot_no'],
        'make'         => $c['make'],
        'model'        => $c['model'],
        'title'        => trim($c['make'] . ' ' . $c['model']),
        'grade'        => $c['grade'],
        'year'         => $c['year'] ? intval($c['year']) : null,
        'mileage'      => intval($c['mileage']),
        'mileage_fmt'  => $c['mileage'] > 0 ? number_format($c['mileage']) . ' km' : '—',
        'engine_cc'    => $c['engine_cc'] ? intval($c['engine_cc']) : null,
        'cc_fmt'       => $c['engine_cc'] > 0 ? number_format($c['engine_cc']) . ' cc' : '—',
        'engine_hp'    => $c['engine_hp'],
        'transmission' => $c['transmission'],
        'drive'        => $c['load_capacity'],
        'equipment'    => $c['equipment'],
        'chassis'      => $c['chassis'],
        'color'        => $c['color'],
        'rating'       => $c['rating'],
        'auction'      => $c['auction'],
        'auction_date' => $c['auction_date'],
        'auction_time' => $c['auction_time'],
        'price'        => floatval($c['price']),
        'sold_price'   => floatval($c['sold_price']),
        'avg_price'    => floatval($c['avg_price']),
        'start_fmt'    => $c['price'] > 0 ? '¥' . number_format($c['price']) : '—',
        'sold_fmt'     => $c['sold_price'] > 0 ? '¥' . number_format($c['sold_price']) : '—',
        'currency'     => $c['currency'] ?: 'yen',
        'status'       => $c['status'],
        'status_label' => lotStatusLabel($c),
        'status_class' => statusClass($c['status']),
        'photos'       => getCarImages($c, 100),
        'photos_full'  => getCarImages($c, 320),
        'updated'      => $c['last_updated'],
    );
}

echo json_encode(array(
    'version'     => $version,
    'total'       => intval($result['total']),
    'page'        => $result['page'],
    'total_pages' => intval($result['total_pages']),
    'server_time' => date('c'),
    'cars'        => $cars,
), JSON_UNESCAPED_UNICODE);
