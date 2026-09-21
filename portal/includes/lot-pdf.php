<?php
/**
 * One vehicle as a PDF spec sheet.
 *
 * Kept apart from the page that serves it because two things want it: the PDF
 * link on the lot page, and the zip download, which packs the same sheet in
 * beside the photographs. Building it twice would mean two sheets drifting
 * apart.
 *
 * Laid out rather than screenshotted. A rendered copy of the web page carries
 * its navigation, its filters and its buttons into a document meant to be read
 * on paper; what a buyer wants on paper is the car.
 */

if (!defined('FPDF_FONTPATH')) {
    define('FPDF_FONTPATH', dirname(__DIR__) . '/lib/font/');
}
require_once dirname(__DIR__) . '/lib/fpdf.php';

/**
 * A picture, fetched and kept on disk long enough for FPDF to place it.
 *
 * FPDF reads images from a path, not from memory, and the host will not always
 * answer - a vehicle whose photographs are missing still has a spec sheet worth
 * printing, so a failure here returns null and the layout closes up.
 */
function lotPdfImage($url, &$tmpFiles) {
    if (!$url) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_FOLLOWLOCATION => 1,
    ));
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body || strlen($body) < 200) {
        return null;
    }
    $size = @getimagesizefromstring($body);
    // the host answers for a missing picture with a 128x96 placeholder tile
    // rather than a 404, and that tile is not worth a page
    if (!$size || $size[0] < 200) {
        return null;
    }
    $ext = ($size[2] === IMAGETYPE_PNG) ? '.png' : '.jpg';
    $path = tempnam(sys_get_temp_dir(), 'sbkpdf') . $ext;
    file_put_contents($path, $body);
    $tmpFiles[] = $path;
    return array($path, $size[0], $size[1]);
}

class LotSheet extends FPDF {
    function Header() {
        // Black band, as asked for.
        $this->SetFillColor(20, 24, 28);
        $this->Rect(0, 0, 210, 20, 'F');

        $logo = dirname(__DIR__) . '/assets/img/sbk-logo.png';
        if (is_file($logo)) {
            // Straight onto the black band, with no card behind it. The lockup
            // carries "GLOBAL AUTO TRADING" in white, so it was drawn for a
            // dark ground - a white card was hiding those words.
            //
            // 2134x747, so 33mm across is 11.55mm tall - width is given and the
            // height follows, because a lockup stretched to fit is worse than
            // one that is slightly small.
            $this->Image($logo, 12, 4.2, 33);
        } else {
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Helvetica', 'B', 15);
            $this->SetXY(12, 5);
            $this->Cell(120, 9, 'SBK GLOBAL AUTO TRADING', 0, 0, 'L');
        }

        $this->SetY(28);
        $this->SetTextColor(0, 0, 0);
    }

    /* No footer. The strip along the bottom carried the site address, a
       timestamp and a page number in small grey type - three things nobody
       reads on a sheet handed to a customer, and the first thing the eye
       catches at the foot of the page. */
}

/**
 * The file name for one lot's sheet, without an extension.
 */
function lotPdfName($car) {
    $n = preg_replace('/[^A-Za-z0-9]+/', '-',
        trim($car['make'] . ' ' . $car['model']) . '-lot-' . $car['lot_no']);
    return trim($n, '-') ?: 'vehicle';
}

/**
 * Build the sheet and hand back the PDF as a string.
 *
 * @param array $car  a row from `cars`, with `images` already decoded
 * @return string
 */
function lotPdfBytes($car) {
    $title = trim($car['make'] . ' ' . $car['model']);
    $tmpFiles = array();

    $pdf = new LotSheet();
    $pdf->SetTitle($title . ' - Lot ' . $car['lot_no'], true);
    $pdf->SetAuthor('SBK Global Auto Trading', true);
    $pdf->SetCreator('SBK Auction Portal', true);
    // 15mm, not 20: the bottom strip that used to sit there is gone, so the
    // page keeps the room it was reserving for it.
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    /* --------------------------------------------------------------- title */
    $pdf->SetFont('Helvetica', 'B', 19);
    $pdf->Cell(120, 9, $title, 0, 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->SetTextColor(198, 32, 38);
    // Nothing where there is no price. "Price on request" is an invitation the
    // sheet is not making - the desk quotes.
    $pdf->Cell(70, 9, $car['price'] > 0 ? number_format($car['price']) . ' JPY' : '', 0, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);

    $sub = array();
    if ($car['year'])    { $sub[] = (int) $car['year']; }
    if ($car['grade'])   { $sub[] = $car['grade']; }
    if ($car['lot_no'])  { $sub[] = 'Lot ' . $car['lot_no']; }
    if ($car['auction']) { $sub[] = $car['auction']; }
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(90, 96, 104);
    $pdf->Cell(0, 6, implode('   |   ', $sub), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);

    /* ------------------------------------------------------------ pictures */
    $uss = ussPhotoSet($car);
    $shots = jpaucImageSet($car, 3);
    if (!$shots) {
        $shots = getCarImages($car, 640);
    }
    // 640 high, not the original: three 1024x768 photographs make a spec sheet
    // close to a megabyte, and at 61mm across the page the extra pixels are not
    // visible. The inspection sheet below is fetched full size, because that one
    // has to be readable.
    $shots = array_map(function ($u) {
        $u = fullSizeImage($u);
        return $u . (strpos($u, '?') === false ? '?' : '&') . 'h=640';
    }, array_slice($shots, 0, 3));
    if ($uss) {
        // USS: the kept Pacific Boeki photographs, as they are - their addresses
        // take no size parameter (see ussPhotoSet()).
        $shots = array_slice($uss['photos'], 0, 3);
    }

    $x = 12;
    $placed = 0;
    foreach ($shots as $u) {
        $im = lotPdfImage($u, $tmpFiles);
        if (!$im) {
            continue;
        }
        list($path, $w, $h) = $im;
        $boxW = 61;
        $boxH = $boxW * $h / max(1, $w);
        if ($boxH > 48) {                    // keep the strip an even height
            $boxH = 48;
            $boxW = $boxH * $w / max(1, $h);
        }
        $pdf->Image($path, $x, $pdf->GetY(), $boxW, $boxH);
        $x += 63;
        $placed++;
        if ($placed >= 3) {
            break;
        }
    }
    if ($placed) {
        $pdf->Ln(50);
    } else {
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->SetTextColor(140, 140, 140);
        $pdf->Cell(0, 8, 'No photographs published for this lot.', 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);
    }

    /* --------------------------------------------------------------- specs */
    $specs = array(
        array('Lot Number',    $car['lot_no']),
        array('Auction Hall',  $car['auction']),
        array('Auction Date',  $car['auction_date']),
        array('Make',          $car['make']),
        array('Model',         $car['model']),
        array('Model Grade',   $car['grade']),
        array('Year',          $car['year'] ? (int) $car['year'] : ''),
        array('Chassis Model', $car['chassis']),
        array('Transmission',  $car['transmission']),
        array('Engine',        $car['engine_cc'] ? number_format($car['engine_cc']) . ' cc' : ''),
        array('Mileage',       $car['mileage'] ? number_format($car['mileage']) . ' km' : ''),
        array('Color',         $car['color']),
        array('Auction Grade', $car['rating']),
        array('Start Price',   $car['price'] > 0 ? number_format($car['price']) . ' JPY' : ''),
        array('Result',        $car['sold_price'] > 0 ? number_format($car['sold_price']) . ' JPY' : ''),
        array('Status',        lotStatusLabel($car)),
    );
    // Drop what the source did not send. A sheet two-thirds full of dashes
    // reads as a broken document rather than a complete one.
    $specs = array_values(array_filter($specs, function ($s) {
        $v = trim((string) $s[1]);
        return $v !== '' && $v !== '—' && $v !== '0';
    }));

    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'Specification', 0, 1);
    $pdf->SetDrawColor(214, 218, 222);

    // two columns, so the sheet stays on one page
    $rows = (int) ceil(count($specs) / 2);
    $top = $pdf->GetY();
    for ($col = 0; $col < 2; $col++) {
        $pdf->SetY($top);
        $left = 12 + $col * 93;
        for ($i = 0; $i < $rows; $i++) {
            $n = $col * $rows + $i;
            if (!isset($specs[$n])) {
                break;
            }
            $pdf->SetX($left);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(105, 112, 120);
            $pdf->Cell(38, 7, $specs[$n][0], 'B', 0, 'L');
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->SetTextColor(20, 24, 28);
            $pdf->Cell(50, 7, (string) $specs[$n][1], 'B', 1, 'L');
        }
    }
    $pdf->SetY(max($pdf->GetY(), $top + $rows * 7));
    $pdf->Ln(6);

    /* ---------------------------------------------------- inspection sheet */
    $sheetUrl = ($uss && !empty($uss['sheet'])) ? $uss['sheet'] : jpaucSheetUrl($car);
    if ($sheetUrl) {
        $im = lotPdfImage(fullSizeImage($sheetUrl), $tmpFiles);
        if ($im) {
            list($path, $w, $h) = $im;
            if ($pdf->GetY() > 150) {
                $pdf->AddPage();
            }
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->Cell(0, 8, 'Inspection sheet', 0, 1);
            $boxW = 120;
            $boxH = $boxW * $h / max(1, $w);
            $pdf->Image($path, 12, $pdf->GetY() + 1, $boxW, $boxH);
            $pdf->Ln($boxH + 4);
        }
    }

    $bytes = $pdf->Output('S');

    foreach ($tmpFiles as $f) {
        @unlink($f);
    }
    return $bytes;
}
