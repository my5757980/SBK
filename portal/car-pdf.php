<?php
/**
 * Serve one vehicle's spec sheet as a PDF download.
 *
 * The sheet itself is built in includes/lot-pdf.php, because the zip download
 * packs the same document in beside the photographs and one of them having a
 * different layout would be worse than either.
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/lot-pdf.php';

requireLogin();

$car = getCarById(isset($_GET['id']) ? (int) $_GET['id'] : 0);
if (!$car) {
    http_response_code(404);
    exit('That vehicle is no longer listed.');
}

$bytes = lotPdfBytes($car);
$name  = lotPdfName($car);

// `attachment`, deliberately: served inline the browser opens its own PDF
// viewer, which is the print preview this page exists to get away from.
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $name . '.pdf"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $bytes;
