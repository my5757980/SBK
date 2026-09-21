<?php
/**
 * Accepted bids, as the spreadsheet the desk already works from.
 *
 * The client sent two photographs of the sheet they keep by hand, and it is
 * nine columns wide:
 *
 *   Lot No. | Auction | Date/Time | Make | Year | Grade | Push Price | Max Price | Notes
 *      7054 | KCAA Kyoto | 9-Sep-26 | Honda Shuttle | 2015 | | 205,000 | 220,000 | For Jamaica (Inspection Required)
 *
 * Those nine are laid out first and in that order, under the same heading row,
 * so the file opens looking like the one it replaces. What the owner asked for
 * on top of them - the chassis code, the rest of the vehicle, and the customer's
 * full details - follows to the right rather than being mixed in, so neither
 * request costs the other anything.
 *
 * Push Price is what the customer bid; Max Price is the top of the window the
 * bid was checked against. Grade is the hall's own inspection grade, which is
 * the only inspection the feed carries - "Inspection Required" in the Notes is
 * the desk's own wording and comes from the note left on the bid.
 *
 * ACCEPTED ONLY, and that is the owner's instruction rather than a convenience:
 * a bid reaches this file when the desk has accepted it and at no other time.
 *
 * ---------------------------------------------------------------------------
 * A REAL .xlsx, written here, with no library.
 *
 * The first version of this was an HTML table named .xls. Excel opens those,
 * but it opens them complaining - "the file format and the extension don't
 * match, the file could be corrupted or unsafe" - and a warning like that in
 * front of a file full of customers' addresses is not something to leave in
 * place. The client reported the download as the site hanging, and a modal
 * nobody expected is exactly what that looks like from the other end.
 *
 * An .xlsx is a zip of five small XML parts, and this host has ZipArchive, so
 * the real thing is written directly: no warning, a frozen heading row, filter
 * arrows, real column widths, and money that Excel will actually add up.
 * ---------------------------------------------------------------------------
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requirePermission('bids.export');

/* The same narrowing as the screen it was clicked from: a spreadsheet is not
   a way around a permission. */

/* WHAT GOES IN, settled by the owner with his client on 18 September 2026:
   "only the accepted ones will come through... whatever date filter you apply
   will show on the current page, and when you import, only the accepted ones
   will go into Excel, not everything."

   So the date filter chooses the DAY and nothing chooses the status: this file
   is accepted-only, always. A bid belongs in the sheet the desk works from once
   the desk has accepted it and at no other time, and the status chips on the
   screen are for reading, not for deciding what the desk is handed.

   The day is re-read and re-narrowed here rather than trusted from the link, so
   a hand-typed address cannot reach a day the screen would not have shown. */
$xDay     = bidDay($_GET['day'] ?? '');
$xStatus  = 'accepted';
$xDayLong = date('j M Y', strtotime($xDay));
$xWhat    = 'accepted bids on ' . $xDayLong;

$rows = bidsFull(bidScopeStaffId(), $xDay, $xStatus);

/* Grouped by customer, the way the hand-kept sheet groups them - one buyer's
   cars together. Within a buyer, the earliest sale day first, because that is
   the order the desk has to act in. */
usort($rows, function ($a, $b) {
    $c = strcasecmp((string) $a['client_name'], (string) $b['client_name']);
    if ($c !== 0) { return $c; }
    $c = strcmp((string) $a['auction_on'], (string) $b['auction_on']);
    if ($c !== 0) { return $c; }
    return strnatcmp((string) $a['car_lot'], (string) $b['car_lot']);
});

/* ------------------------------------------------------------------ the sheet
   Each column says what it is called, how wide it should open, and how its
   values are written: L left, C centred, T text-that-must-stay-text (a lot
   number of 07054 keeps its nought), N a number Excel can add up. */
$COLS = array(
    // the nine from the photograph
    array('Lot No.',        11, 'T'),
    array('Auction',        22, 'L'),
    array('Date/Time',      12, 'C'),
    array('Make',           26, 'L'),
    array('Year',            7, 'C'),
    array('Grade',           7, 'C'),
    array('Push Price',     13, 'N'),
    array('Max Price',      13, 'N'),
    array('Notes',          34, 'L'),
    // and everything else the owner asked for
    array('Chassis Model',  15, 'T'),
    array('Model',          20, 'L'),
    array('Mileage (km)',   12, 'N'),
    array('Engine (cc)',    11, 'N'),
    array('Transmission',   13, 'C'),
    array('Colour',         14, 'L'),
    array('Model Grade',    20, 'L'),
    array('Auction Time',   12, 'C'),
    array('Start Price',    13, 'N'),
    array('Bid No.',        18, 'T'),
    array('Bid Date',       16, 'C'),
    array('Status',         11, 'C'),
    array('Vehicle Page',   46, 'T'),
    array('Client Name',    22, 'L'),
    array('Client Email',   28, 'T'),
    array('Client Phone',   18, 'T'),
    array('Address',        30, 'L'),
    array('City',           16, 'L'),
    array('State',          16, 'L'),
    array('Postal Code',    12, 'T'),
    array('Country',        16, 'L'),
);
$MAIN_N = 9;                 // how many belong to the photographed sheet
$NCOL   = count($COLS);

/** A1-style column name: 1 -> A, 27 -> AA. */
function colName($n) {
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

/** XML rejects most control characters outright, so they go before escaping. */
function xs($v) {
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v);
    return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * One cell.
 *
 * Everything is written as an inline string except the money and the counts,
 * which go in as numbers so a column can be totalled. `$s` is the style index
 * defined in styles.xml below.
 */
function cell($ref, $s, $v, $numeric = false) {
    if ($numeric) {
        return '<c r="' . $ref . '" s="' . $s . '"><v>' . (int) round((float) $v) . '</v></c>';
    }
    $v = trim((string) $v);
    if ($v === '') {
        return '<c r="' . $ref . '" s="' . $s . '"/>';
    }
    return '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">'
         . xs($v) . '</t></is></c>';
}

/* Style indexes, in the order they are declared in styles.xml:
   0 plain · 1 title · 2 subtitle · 3 group blue · 4 group grey
   5 column heading · 6 left · 7 centred · 8 number */
$S_TITLE = 1; $S_SUB = 2; $S_G1 = 3; $S_G2 = 4;
$S_HEAD  = 5; $S_L = 6; $S_C = 7; $S_N = 8;

// ------------------------------------------------------------------ the rows
$xml = '';
$r = 1;

$xml .= '<row r="1" ht="21" customHeight="1">'
      . cell('A1', $S_TITLE, 'SBK Auto Trading — Accepted Bids, ' . $xDayLong) . '</row>';
$xml .= '<row r="2">'
      . cell('A2', $S_SUB, count($rows) . ' bid' . (count($rows) === 1 ? '' : 's')
             . '  ·  taken ' . date('j M Y, H:i') . '  ·  ' . $xWhat) . '</row>';
$xml .= '<row r="3"/>';

// row 4: which block a column belongs to
$xml .= '<row r="4">';
for ($i = 1; $i <= $NCOL; $i++) {
    $st = ($i <= $MAIN_N) ? $S_G1 : $S_G2;
    $v  = ($i === 1) ? 'BID SHEET' : (($i === $MAIN_N + 1) ? 'VEHICLE & CUSTOMER DETAIL' : '');
    $xml .= cell(colName($i) . '4', $st, $v);
}
$xml .= '</row>';

// row 5: the headings themselves
$xml .= '<row r="5" ht="18" customHeight="1">';
foreach ($COLS as $i => $c) {
    $xml .= cell(colName($i + 1) . '5', $S_HEAD, $c[0]);
}
$xml .= '</row>';

$r = 5;
foreach ($rows as $b) {
    // The vehicle's own row is the better source; the bid keeps a copy of the
    // basics from when it was placed, which stands in if the lot has since been
    // dropped from the catalogue.
    $make  = trim((string) ($b['car_make']  ?: $b['bid_make']));
    $model = trim((string) ($b['car_model'] ?: $b['bid_model']));
    $year  = (int) ($b['car_year'] ?: $b['bid_year']);
    $lot   = trim((string) ($b['car_lot'] ?: $b['bid_lot']));

    // "9-Sep-26", which is how the sheet the client keeps writes a sale day.
    $day = '';
    if (!empty($b['auction_on']) && $b['auction_on'] !== '0000-00-00') {
        $day = date('j-M-y', strtotime($b['auction_on']));
    }
    $time = trim((string) $b['auction_time'], "[] \t\n\r");
    if (strpos($time, '00:00:00') === 0) { $time = ''; }

    $page = $b['car_row_id']
        ? SITE_URL . 'car-details.php?id=' . (int) $b['car_row_id']
        : (string) $b['source_url'];

    $vals = array(
        array($lot,                    'T'),
        array($b['auction_hall'],      'L'),
        array($day,                    'C'),
        array(trim($make . ' ' . $model), 'L'),
        array($year > 1950 ? $year : '', 'C'),
        array($b['auction_grade'],     'C'),
        array($b['amount'],            'N'),
        array($b['max_allowed'],       'N'),
        array($b['admin_note'],        'L'),

        array($b['chassis'],           'T'),
        array($model,                  'L'),
        array($b['mileage'],           'N'),
        array($b['engine_cc'],         'N'),
        array($b['transmission'],      'C'),
        array($b['color'],             'L'),
        array($b['model_grade'],       'L'),
        array(substr($time, 0, 5),     'C'),
        array($b['start_price'],       'N'),
        array($b['bid_number'],        'T'),
        array($b['placed_at'] ? date('j-M-y H:i', strtotime($b['placed_at'])) : '', 'C'),
        array(ucfirst((string) $b['status']), 'C'),
        array($page,                   'T'),
        array($b['client_name'],       'L'),
        array($b['client_email'],      'T'),
        array($b['client_phone'],      'T'),
        array($b['address'],           'L'),
        array($b['city'],              'L'),
        array($b['state'],             'L'),
        array($b['postal_code'],       'T'),
        array($b['country'],           'L'),
    );

    $r++;
    $xml .= '<row r="' . $r . '">';
    foreach ($vals as $i => $v) {
        $ref = colName($i + 1) . $r;
        if ($v[1] === 'N') {
            $n = (float) $v[0];
            $xml .= ($n > 0) ? cell($ref, $S_N, $n, true) : cell($ref, $S_N, '');
        } elseif ($v[1] === 'C') {
            $xml .= cell($ref, $S_C, $v[0]);
        } elseif ($v[1] === 'T') {
            $xml .= cell($ref, $S_L, $v[0]);
        } else {
            $xml .= cell($ref, $S_L, $v[0]);
        }
    }
    $xml .= '</row>';
}

if (!$rows) {
    $r = 6;
    $xml .= '<row r="6">' . cell('A6', $S_L,
        'There are no ' . $xWhat . '. Change the date on the Bids screen and export again.')
        . '</row>';
}

$lastCol = colName($NCOL);

$cols = '<cols>';
foreach ($COLS as $i => $c) {
    $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $c[1] . '" customWidth="1"/>';
}
$cols .= '</cols>';

/* The element order inside a worksheet is fixed by the schema - cols before
   sheetData, autoFilter before mergeCells - and Excel refuses to open a file
   that gets it wrong rather than repairing it quietly. */
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
  . '<dimension ref="A1:' . $lastCol . max($r, 6) . '"/>'
  . '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
  . '<pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/>'
  . '</sheetView></sheetViews>'
  . '<sheetFormatPr defaultRowHeight="15"/>'
  . $cols
  . '<sheetData>' . $xml . '</sheetData>'
  . '<autoFilter ref="A5:' . $lastCol . max($r, 5) . '"/>'
  . '<mergeCells count="2">'
  . '<mergeCell ref="A4:' . colName($MAIN_N) . '4"/>'
  . '<mergeCell ref="' . colName($MAIN_N + 1) . '4:' . $lastCol . '4"/>'
  . '</mergeCells>'
  . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
  . '</worksheet>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
  . '<fonts count="5">'
  . '<font><sz val="11"/><name val="Calibri"/></font>'
  . '<font><b/><sz val="15"/><name val="Calibri"/></font>'
  . '<font><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font>'
  . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
  . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
  . '</fonts>'
  . '<fills count="4">'
  . '<fill><patternFill patternType="none"/></fill>'
  . '<fill><patternFill patternType="gray125"/></fill>'
  . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill>'
  . '<fill><patternFill patternType="solid"><fgColor rgb="FF7F7F7F"/><bgColor indexed="64"/></patternFill></fill>'
  . '</fills>'
  . '<borders count="2">'
  . '<border><left/><right/><top/><bottom/><diagonal/></border>'
  . '<border><left style="thin"><color rgb="FF000000"/></left>'
  . '<right style="thin"><color rgb="FF000000"/></right>'
  . '<top style="thin"><color rgb="FF000000"/></top>'
  . '<bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border>'
  . '</borders>'
  . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
  . '<cellXfs count="9">'
  . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
  . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
  . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
  . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
  . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
  . '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
  . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="top"/></xf>'
  . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
  . '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
  . '</cellXfs>'
  . '</styleSheet>';

$parts = array(
  '[Content_Types].xml' =>
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>',
  '_rels/.rels' =>
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>',
  'xl/workbook.xml' =>
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Accepted Bids" sheetId="1" r:id="rId1"/></sheets>'
    . '</workbook>',
  'xl/_rels/workbook.xml.rels' =>
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>',
  'xl/styles.xml'             => $styles,
  'xl/worksheets/sheet1.xml'  => $sheet,
);

$tmp = tempnam(sys_get_temp_dir(), 'sbkxlsx');
$zip = new ZipArchive();
if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    // Nothing half-written ever reaches the browser: say so in plain words and
    // leave the desk on a page it can read.
    @unlink($tmp);
    header('Location: bids.php?export=failed');
    exit;
}
foreach ($parts as $name => $body) {
    $zip->addFromString($name, $body);
}
$zip->close();

logStaffAction('bids.export', count($rows) . ' · ' . $xWhat, 0);

/* The whole file is on disk before a single byte of it is sent, so the length
   is known and the browser knows when the download has finished rather than
   sitting on an open connection waiting to find out. */
$size = filesize($tmp);
while (ob_get_level()) { ob_end_clean(); }

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="SBK-accepted-bids-' . $xDay . '.xlsx"');
header('Content-Length: ' . $size);
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
readfile($tmp);
@unlink($tmp);
