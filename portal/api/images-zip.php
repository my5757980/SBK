<?php
/**
 * One lot as a single zip: every photograph, the inspection sheet, and the
 * spec sheet as a PDF.
 *
 * A buyer forwarding a car to a colleague should not save a dozen files by
 * hand, and the pictures on their own do not say which car they are - the PDF
 * that goes in with them carries the lot number, the grade, the mileage and
 * the price.
 *
 * The pictures live on the feed's CDN, so this fetches them and packs them;
 * the alternative, a list of links, is what we are trying to save them from.
 *
 * Signed-in only, and only for lots the viewer is allowed to see: the id is the
 * only input, so without that check it would hand out the archive to anybody
 * who guessed a number.
 */

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/lot-pdf.php';

requireLogin();

@set_time_limit(180);

$car = getCarById(isset($_GET['id']) ? intval($_GET['id']) : 0);
if (!$car) {
    http_response_code(404);
    exit('Not found');
}

if ((($car['source_section'] ?? 'japan') !== 'japan') || (!viewerSeesPastLots() && !lotIsCurrent($car))) {
    http_response_code(403);
    exit('Not available');
}

if (!class_exists('ZipArchive')) {
    http_response_code(501);
    exit('Zip support is not enabled on this server.');
}

/**
 * Every picture the lot has, at full size.
 *
 * The feed sends one address; the rest answer to it with a different number,
 * and the inspection sheet to number=0. This used to pack what the listing
 * showed at 320 high - thumbnails, in an archive whose whole purpose is to
 * hand somebody the pictures.
 */
$shots = jpaucImageSet($car, 12);
$uss = ussPhotoSet($car);
if ($uss) {
    // USS: the host's own addresses hold only a preview; the kept Pacific Boeki
    // set is the real one (see ussPhotoSet()).
    $shots = $uss['photos'];
    $sheet = $uss['sheet'];
} elseif ($shots) {
    $shots = array_map('fullSizeImage', $shots);
    $sheet = jpaucSheetUrl($car);
} else {
    $shots = array_map('fullSizeImage', getCarImages($car, 640));
    $sheet = null;
}
if (!$shots) {
    http_response_code(404);
    exit('This lot has no pictures.');
}

$name = preg_replace('/[^A-Za-z0-9]+/', '-',
    trim($car['make'] . ' ' . $car['model'] . ' lot ' . $car['lot_no']));
$name = trim($name, '-') ?: 'lot';

$tmp = tempnam(sys_get_temp_dir(), 'sbk');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not build the archive.');
}

/**
 * Fetch one picture, or null if there is nothing worth packing at that address.
 */
function zipFetch($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_FOLLOWLOCATION => 1,
    ));
    $bytes = curl_exec($ch);
    curl_close($ch);
    if ($bytes === false || strlen($bytes) < 512) {
        return null;
    }
    // The CDN answers for a picture that was never taken with a 128x96
    // placeholder tile rather than a 404. Asking for twelve photographs when
    // the lot has four would otherwise pack eight grey tiles.
    $size = @getimagesizefromstring($bytes);
    if (!$size || $size[0] < 200) {
        return null;
    }
    return $bytes;
}

$added = 0;
foreach ($shots as $i => $url) {
    $bytes = zipFetch($url);
    if ($bytes === null) {
        continue;
    }
    $zip->addFromString(sprintf('%s-photo-%02d.jpg', $name, $i + 1), $bytes);
    $added++;
}

if ($sheet) {
    $bytes = zipFetch($sheet);
    if ($bytes !== null) {
        $zip->addFromString($name . '-inspection-sheet.jpg', $bytes);
        $added++;
    }
}

// The spec sheet goes in with them. Pictures alone do not say which car they
// are, and a colleague opening the archive should not have to be told.
$pdf = lotPdfBytes($car);
if ($pdf !== '') {
    $zip->addFromString(lotPdfName($car) . '.pdf', $pdf);
    $added++;
}

$zip->close();

if (!$added) {
    @unlink($tmp);
    http_response_code(502);
    exit('The picture service did not answer. Please try again shortly.');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
